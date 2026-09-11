<?php
/**
 * GitHub-backed self-updater for Cayden Link Shortener.
 *
 * Lets the plugin update itself from GitHub Releases, exactly like a plugin
 * from the wordpress.org directory:
 *
 *   1. You publish a new GitHub Release whose tag is the new version
 *      (e.g. "v1.14.0") and attach the built zip (acps-link-shortener.zip)
 *      as a release asset.
 *   2. WordPress notices the new version and shows an "Update now" button.
 *      With auto-update enabled it installs silently in the background.
 *   3. Visiting the secret trigger URL forces an immediate check + install
 *      on demand.
 *
 * Works with a PUBLIC repo (no token needed) or a PRIVATE repo (set a GitHub
 * personal access token in Settings). Everything is wrapped so a network
 * hiccup or a bad response can never take the site down.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Self-update engine.
 */
class ACPS_LS_Updater {

	/**
	 * Transient key for the cached remote-release lookup.
	 */
	const CACHE_KEY = 'acps_ls_update_remote';

	/**
	 * How long (seconds) to cache the GitHub lookup.
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Resolved configuration for this request.
	 *
	 * @var array
	 */
	private $cfg;

	/**
	 * Build with resolved config.
	 */
	public function __construct() {
		$this->cfg = self::config();
	}

	/**
	 * Merge stored settings with sensible defaults.
	 *
	 * @return array
	 */
	public static function config() {
		$s = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();

		$source = isset( $s['update_source'] ) && in_array( $s['update_source'], array( 'url', 'github' ), true ) ? $s['update_source'] : 'url';

		$cfg = array(
			'enabled'     => isset( $s['update_enabled'] ) ? (bool) $s['update_enabled'] : true,
			'auto'        => isset( $s['update_auto'] ) ? (bool) $s['update_auto'] : false,
			'source'      => $source,
			// Hosted-manifest source.
			'manifest'    => isset( $s['update_manifest'] ) ? (string) $s['update_manifest'] : '',
			'manifest_key' => isset( $s['update_manifest_key'] ) ? (string) $s['update_manifest_key'] : '',
			// GitHub source.
			'owner'       => isset( $s['gh_owner'] ) && '' !== $s['gh_owner'] ? (string) $s['gh_owner'] : 'trentmontgomery222',
			'repo'        => isset( $s['gh_repo'] ) && '' !== $s['gh_repo'] ? (string) $s['gh_repo'] : 'coding-help',
			'asset'       => isset( $s['gh_asset'] ) && '' !== $s['gh_asset'] ? (string) $s['gh_asset'] : 'acps-link-shortener.zip',
			'token'       => isset( $s['gh_token'] ) ? (string) $s['gh_token'] : '',
			'trigger'     => isset( $s['update_trigger'] ) && '' !== $s['update_trigger'] ? (string) $s['update_trigger'] : 'protcol_U999_update',
			// Optional staged rollout (two-site dev -> production gating).
			'role'         => ( isset( $s['update_role'] ) && 'production' === $s['update_role'] ) ? 'production' : 'standalone',
			'verify_url'   => isset( $s['verify_status_url'] ) ? (string) $s['verify_status_url'] : '',
			'verify_key'   => isset( $s['verify_status_key'] ) ? (string) $s['verify_status_key'] : '',
		);

		/**
		 * Filter the updater configuration (owner/repo/token/etc.).
		 *
		 * @param array $cfg Config.
		 */
		return apply_filters( 'acps_ls_updater_config', $cfg );
	}

	/**
	 * Hook everything up. Never throws.
	 */
	public function register() {
		try {
			if ( empty( $this->cfg['enabled'] ) ) {
				return;
			}

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
			add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
			add_filter( 'upgrader_pre_download', array( $this, 'maybe_prefetch_private' ), 10, 3 );
			add_filter( 'auto_update_plugin', array( $this, 'auto_update_flag' ), 10, 2 );

			// Rename the unpacked package folder back to our slug so an update whose
			// zip unpacks to a differently-named folder (e.g. a GitHub source zip)
			// still installs over the SAME directory and the plugin stays active.
			add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );

			// Secret force-update URL (front end + admin).
			add_action( 'init', array( $this, 'maybe_handle_trigger' ) );
			// Early marker used by the post-update crash test.
			add_action( 'init', array( $this, 'maybe_handle_selftest' ), 1 );

			// After any upgrade: clear the cache; after OUR upgrade: crash-test the
			// new code and roll back if it fails to load.
			add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
			add_action( 'upgrader_process_complete', array( $this, 'verify_after_upgrade' ), 20, 2 );

			// Tell admins if a recent update was rolled back.
			add_action( 'admin_notices', array( $this, 'maybe_show_update_failed_notice' ) );

			// Staged rollout: publish this install's verified version for a paired
			// production site to read before it updates.
			add_action( 'rest_api_init', array( $this, 'register_status_route' ) );
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'updater register', $e );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Remote lookup                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Fetch (and cache) the latest release from GitHub.
	 *
	 * @param bool $force Skip the cache.
	 * @return array|false { version, package, is_asset, html_url, body } or false.
	 */
	public function remote( $force = false ) {
		try {
			if ( ! $force ) {
				$cached = get_transient( self::CACHE_KEY );
				if ( is_array( $cached ) ) {
					return empty( $cached ) ? false : $cached;
				}
			}

			$out = ( 'github' === $this->cfg['source'] )
				? $this->remote_github()
				: $this->remote_manifest();

			if ( ! $out ) {
				// Cache a short-lived "nothing" so we don't hammer the source.
				set_transient( self::CACHE_KEY, array(), 15 * MINUTE_IN_SECONDS );
				return false;
			}

			set_transient( self::CACHE_KEY, $out, self::CACHE_TTL );
			return $out;
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'updater remote', $e );
			return false;
		}
	}

	/**
	 * Look up the latest version from a self-hosted JSON manifest.
	 *
	 * Expected JSON: { "version": "1.14.0", "download_url": "https://.../x.zip",
	 * "changelog": "...", "requires_php": "7.4", "homepage": "..." }
	 *
	 * @return array|false
	 */
	private function remote_manifest() {
		$manifest = trim( (string) $this->cfg['manifest'] );
		if ( '' === $manifest ) {
			return false;
		}

		// Optional shared secret, sent as a query arg so it also protects a
		// static host that can't read headers.
		if ( '' !== $this->cfg['manifest_key'] ) {
			$manifest = add_query_arg( 'key', rawurlencode( $this->cfg['manifest_key'] ), $manifest );
		}

		$resp = wp_remote_get(
			$manifest,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Cayden-Link-Shortener/' . ACPS_LS_VERSION,
				),
			)
		);

		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
			return false;
		}

		return array(
			'version'  => ltrim( (string) $data['version'], 'vV' ),
			'package'  => (string) $data['download_url'],
			'is_asset' => false,
			'html_url' => isset( $data['homepage'] ) ? (string) $data['homepage'] : '',
			'body'     => isset( $data['changelog'] ) ? (string) $data['changelog'] : '',
		);
	}

	/**
	 * Look up the latest release from GitHub.
	 *
	 * @return array|false
	 */
	private function remote_github() {
		try {
			$url  = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', rawurlencode( $this->cfg['owner'] ), rawurlencode( $this->cfg['repo'] ) );
			$resp = wp_remote_get( $url, $this->api_args() );

			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return false;
			}

			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
				return false;
			}

			$version = ltrim( (string) $data['tag_name'], 'vV' );

			// Prefer the matching release asset (the real plugin zip).
			$package  = '';
			$is_asset = false;
			if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
				foreach ( $data['assets'] as $asset ) {
					if ( ! empty( $asset['name'] ) && (string) $asset['name'] === $this->cfg['asset'] ) {
						// For a private repo we must go through the API url with a token;
						// for a public repo the browser_download_url is simplest.
						if ( '' !== $this->cfg['token'] && ! empty( $asset['url'] ) ) {
							$package = (string) $asset['url'];
						} elseif ( ! empty( $asset['browser_download_url'] ) ) {
							$package = (string) $asset['browser_download_url'];
						}
						$is_asset = true;
						break;
					}
				}
			}

			// Fall back to the source zipball only if there is no asset. NOTE: for a
			// plugin that lives in a repo subfolder the zipball is NOT directly
			// installable, so an attached asset is strongly recommended.
			if ( '' === $package && ! empty( $data['zipball_url'] ) ) {
				$package = (string) $data['zipball_url'];
			}

			if ( '' === $package ) {
				return false;
			}

			return array(
				'version'  => $version,
				'package'  => $package,
				'is_asset' => $is_asset,
				'html_url' => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
				'body'     => isset( $data['body'] ) ? (string) $data['body'] : '',
			);
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'updater remote_github', $e );
			return false;
		}
	}

	/**
	 * Common request args for GitHub API calls (adds auth when a token is set).
	 *
	 * @param array $extra Extra args to merge.
	 * @return array
	 */
	private function api_args( $extra = array() ) {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'Cayden-Link-Shortener/' . ACPS_LS_VERSION,
		);
		if ( '' !== $this->cfg['token'] ) {
			$headers['Authorization'] = 'Bearer ' . $this->cfg['token'];
		}
		return array_merge(
			array(
				'timeout' => 20,
				'headers' => $headers,
			),
			$extra
		);
	}

	/* --------------------------------------------------------------------- */
	/* Update transient + info popup                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Inject our update into the plugins update transient.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		try {
			if ( ! is_object( $transient ) ) {
				return $transient;
			}

			$remote = $this->remote();
			if ( ! $remote || empty( $remote['version'] ) ) {
				return $transient;
			}

			// Up to date, or a production install whose paired dev site has not yet
			// verified this version — make sure we're not stuck offering it.
			if ( version_compare( $remote['version'], ACPS_LS_VERSION, '<=' ) || ! $this->rollout_allows( $remote['version'] ) ) {
				if ( isset( $transient->response[ ACPS_LS_BASENAME ] ) ) {
					unset( $transient->response[ ACPS_LS_BASENAME ] );
				}
				return $transient;
			}

			$item = array(
				'slug'        => dirname( ACPS_LS_BASENAME ),
				'plugin'      => ACPS_LS_BASENAME,
				'new_version' => $remote['version'],
				'url'         => $remote['html_url'],
				'package'     => $remote['package'],
				'icons'       => array(),
				'banners'     => array(),
				'tested'      => '',
				'requires_php' => '7.4',
			);

			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ ACPS_LS_BASENAME ] = (object) $item;

			return $transient;
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'updater inject', $e );
			return $transient;
		}
	}

	/**
	 * Provide data for the "View details" popup.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action Requested action.
	 * @param object $args   Args (expects ->slug).
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		try {
			if ( 'plugin_information' !== $action ) {
				return $result;
			}
			if ( empty( $args->slug ) || dirname( ACPS_LS_BASENAME ) !== $args->slug ) {
				return $result;
			}

			$remote = $this->remote();
			if ( ! $remote ) {
				return $result;
			}

			$info = array(
				'name'          => 'Cayden Link Shortener',
				'slug'          => dirname( ACPS_LS_BASENAME ),
				'version'       => $remote['version'],
				'author'        => 'Cayden',
				'homepage'      => $remote['html_url'],
				'download_link' => $remote['package'],
				'requires_php'  => '7.4',
				'sections'      => array(
					'changelog' => $remote['body'] ? wpautop( esc_html( $remote['body'] ) ) : esc_html__( 'See the GitHub release notes.', 'acps-link-shortener' ),
				),
			);
			return (object) $info;
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'updater info', $e );
			return $result;
		}
	}

	/**
	 * Should this plugin auto-update? Honors the "auto" setting.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   The update item (expects ->plugin).
	 * @return bool|null
	 */
	public function auto_update_flag( $update, $item ) {
		try {
			if ( isset( $item->plugin ) && ACPS_LS_BASENAME === $item->plugin ) {
				$version = ! empty( $item->new_version ) ? (string) $item->new_version : '';
				if ( '' !== $version && ! $this->rollout_allows( $version ) ) {
					return false;
				}
				return ! empty( $this->cfg['auto'] );
			}
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'updater auto flag', $e );
		}
		return $update;
	}

	/* --------------------------------------------------------------------- */
	/* Private-repo asset download                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * When downloading a PRIVATE release asset, GitHub's asset API returns a
	 * signed redirect that must NOT carry the Authorization header. We resolve
	 * the redirect ourselves (authenticated), then download the signed URL
	 * without auth. For public repos this is skipped entirely.
	 *
	 * @param bool|WP_Error $reply   Short-circuit value.
	 * @param string        $package Package URL being downloaded.
	 * @param WP_Upgrader   $upgrader Upgrader instance.
	 * @return bool|string|WP_Error False to let WP download normally; a file path to short-circuit.
	 */
	public function maybe_prefetch_private( $reply, $package, $upgrader = null ) {
		try {
			// Only intervene for our own private GitHub asset API URLs.
			if ( '' === $this->cfg['token'] || false === strpos( (string) $package, 'api.github.com/repos/' ) || false === strpos( (string) $package, '/releases/assets/' ) ) {
				return $reply;
			}

			// Step 1: ask the API for the asset, do NOT follow the redirect.
			$resp = wp_remote_get(
				$package,
				$this->api_args(
					array(
						'redirection' => 0,
						'headers'     => array(
							'Accept'        => 'application/octet-stream',
							'Authorization' => 'Bearer ' . $this->cfg['token'],
							'User-Agent'    => 'Cayden-Link-Shortener/' . ACPS_LS_VERSION,
						),
					)
				)
			);

			if ( is_wp_error( $resp ) ) {
				return $resp;
			}

			$location = wp_remote_retrieve_header( $resp, 'location' );
			$code     = (int) wp_remote_retrieve_response_code( $resp );

			if ( ! $location ) {
				// Some servers stream the body directly on a 200.
				if ( 200 === $code ) {
					$body = wp_remote_retrieve_body( $resp );
					if ( $body ) {
						$tmp = wp_tempnam( 'acps-ls-update.zip' );
						if ( $tmp && false !== file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
							return $tmp;
						}
					}
				}
				return new WP_Error( 'acps_ls_update_no_redirect', __( 'Could not resolve the update download URL.', 'acps-link-shortener' ) );
			}

			// Step 2: download the signed URL with NO auth header.
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$file = download_url( $location );
			return $file; // File path (success) or WP_Error.
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'updater prefetch', $e );
			return $reply;
		}
	}

	/* --------------------------------------------------------------------- */
	/* Secret force-update URL                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Detect the secret trigger and, when hit, force an update now.
	 *
	 * The trigger fires when the request path OR the ?acps_ls_update= query
	 * value equals the configured secret string. It performs a fresh GitHub
	 * check and installs the update immediately, then prints a small status
	 * page and stops. The secret string is the guard, so keep it private.
	 */
	public function maybe_handle_trigger() {
		try {
			$secret = (string) $this->cfg['trigger'];
			if ( '' === $secret ) {
				return;
			}

			$hit = false;

			// ?acps_ls_update=<secret>
			if ( isset( $_GET['acps_ls_update'] ) && hash_equals( $secret, (string) wp_unslash( $_GET['acps_ls_update'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$hit = true;
			}

			// Pretty path: /<secret> (or /<secret>/)
			if ( ! $hit && isset( $_SERVER['REQUEST_URI'] ) ) {
				$path = trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( '' !== $path && hash_equals( $secret, $path ) ) {
					$hit = true;
				}
			}

			if ( ! $hit ) {
				return;
			}

			$this->run_forced_update();
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'updater trigger', $e );
		}
	}

	/**
	 * Force a fresh check and install of the latest release. Prints a plain
	 * status page and exits.
	 */
	private function run_forced_update() {
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );

		$out = "Cayden Link Shortener — update trigger\n\n";

		try {
			$this->flush_cache();
			$remote = $this->remote( true );

			if ( ! $remote || empty( $remote['version'] ) ) {
				echo esc_html( $out . "Could not reach GitHub or no release found. Check the owner/repo (and token for a private repo) in Settings.\n" );
				exit;
			}

			$out .= 'Installed: ' . ACPS_LS_VERSION . "\n";
			$out .= 'Latest:    ' . $remote['version'] . "\n\n";

			if ( version_compare( $remote['version'], ACPS_LS_VERSION, '<=' ) ) {
				echo esc_html( $out . "Already up to date. Nothing to do.\n" );
				exit;
			}

			// Refresh the update transient so the upgrader sees our package.
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			delete_site_transient( 'update_plugins' );
			wp_update_plugins();

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->upgrade( ACPS_LS_BASENAME );

			$out .= "Installing " . $remote['version'] . "...\n";
			foreach ( (array) $skin->get_upgrade_messages() as $m ) {
				$out .= ' - ' . wp_strip_all_tags( (string) $m ) . "\n";
			}

			if ( is_wp_error( $result ) ) {
				$out .= "\nResult: FAILED — " . $result->get_error_message() . "\n";
			} elseif ( false === $result ) {
				$out .= "\nResult: FAILED — the upgrader could not write the files (filesystem permissions?).\n";
			} else {
				$out .= "\nResult: SUCCESS. Updated to " . $remote['version'] . ".\n";
			}

			echo esc_html( $out );
			exit;
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'updater forced', $e );
			echo esc_html( $out . "\nError: " . $e->getMessage() . "\n" );
			exit;
		}
	}

	/* --------------------------------------------------------------------- */
	/* Keep the plugin active + healthy across an update                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Rename the unpacked update folder to our plugin slug so the update
	 * overwrites the SAME directory (and the plugin stays active), no matter
	 * what the zip's top-level folder was called.
	 *
	 * @param string $source        Path to the unpacked package.
	 * @param string $remote_source Parent dir of the download.
	 * @param object $upgrader      Upgrader instance (unused).
	 * @param array  $args          Hook args (includes 'plugin' during a plugin update).
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader = null, $args = array() ) {
		try {
			$plugin = isset( $args['plugin'] ) ? $args['plugin'] : '';
			if ( ACPS_LS_BASENAME !== $plugin ) {
				return $source; // Not our update.
			}
			$desired = trailingslashit( $remote_source ) . $this->slug();
			$source  = untrailingslashit( $source );
			if ( untrailingslashit( $desired ) === $source ) {
				return trailingslashit( $source );
			}
			global $wp_filesystem;
			if ( $wp_filesystem && $wp_filesystem->move( $source, untrailingslashit( $desired ), true ) ) {
				return trailingslashit( $desired );
			}
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'updater fix_source_dir', $e );
		}
		return $source;
	}

	/**
	 * Early marker for the post-update crash test. Reaching this line proves the
	 * new code loaded without a fatal, so it prints the marker and exits. Guarded
	 * by the same secret as the force-update URL.
	 */
	public function maybe_handle_selftest() {
		if ( ! isset( $_GET['acps_ls_update_selftest'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$secret = (string) $this->cfg['trigger'];
		$given  = sanitize_text_field( wp_unslash( $_GET['acps_ls_update_selftest'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $secret || ! hash_equals( $secret, $given ) ) {
			return;
		}
		nocache_headers();
		status_header( 200 );
		echo 'ACPS_LS_OK';
		exit;
	}

	/**
	 * Whether the just-finished upgrade included this plugin.
	 *
	 * @param array $options upgrader_process_complete options.
	 * @return bool
	 */
	private function upgrade_touched_us( $options ) {
		if ( ! isset( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return false;
		}
		if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			return in_array( ACPS_LS_BASENAME, $options['plugins'], true );
		}
		if ( ! empty( $options['plugin'] ) ) {
			return ACPS_LS_BASENAME === $options['plugin'];
		}
		return true; // Single-plugin update with no explicit list: assume it may be us.
	}

	/**
	 * After our plugin updates: ensure it's active, crash-test the NEW code with
	 * a fresh loopback, and deactivate it only on a definite crash so a bad
	 * release can't take the site down. Records success so a paired production
	 * site can gate on it.
	 *
	 * @param object $upgrader Upgrader instance (unused).
	 * @param array  $options  upgrader_process_complete options.
	 */
	public function verify_after_upgrade( $upgrader, $options ) {
		try {
			if ( ! $this->upgrade_touched_us( (array) $options ) ) {
				return;
			}
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			// Silent activation just flips the option; it does not re-include the
			// plugin in this request (which would fatal on redeclare).
			if ( ! is_plugin_active( ACPS_LS_BASENAME ) ) {
				activate_plugin( ACPS_LS_BASENAME, '', false, true );
			}

			$result = $this->self_test_result();

			if ( 'crash' === $result ) {
				deactivate_plugins( ACPS_LS_BASENAME, true );
				update_option( 'acps_ls_update_failed', array( 'when' => current_time( 'mysql' ), 'version' => ACPS_LS_VERSION ), false );
				acps_ls_log_error( 'updater verify', new Exception( 'new version returned a fatal (5xx) on load; deactivated to protect the site' ) );
				return;
			}

			// 'ok' or 'unknown': leave it enabled (only a definite crash disables).
			if ( 'ok' === $result ) {
				delete_option( 'acps_ls_update_failed' );
				update_option( 'acps_ls_verified', array( 'version' => ACPS_LS_VERSION, 'time' => time() ), false );
			}
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'updater verify', $e );
		}
	}

	/**
	 * Crash test: hit the site with a fresh loopback request (loads the new code
	 * from scratch) and confirm the plugin booted far enough to answer with its
	 * marker. A fatal during load never reaches the marker.
	 *
	 * @return string 'ok' | 'crash' | 'unknown'
	 */
	private function self_test_result() {
		$secret = (string) $this->cfg['trigger'];
		$url    = '' !== $secret
			? add_query_arg( 'acps_ls_update_selftest', rawurlencode( $secret ), home_url( '/' ) )
			: home_url( '/' );

		$resp = wp_remote_get( $url, array( 'timeout' => 20, 'sslverify' => false, 'redirection' => 2 ) );

		if ( is_wp_error( $resp ) ) {
			return 'unknown'; // Loopbacks blocked on some hosts — never a crash.
		}
		if ( (int) wp_remote_retrieve_response_code( $resp ) >= 500 ) {
			return 'crash';
		}
		if ( '' !== $secret ) {
			return ( false !== strpos( (string) wp_remote_retrieve_body( $resp ), 'ACPS_LS_OK' ) ) ? 'ok' : 'unknown';
		}
		return 'ok';
	}

	/**
	 * Warn admins if a recent update was rolled back for failing its load test.
	 */
	public function maybe_show_update_failed_notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$failed = get_option( 'acps_ls_update_failed' );
		if ( ! is_array( $failed ) ) {
			return;
		}
		echo '<div class="notice notice-error is-dismissible"><p>'
			. esc_html__( 'Cayden Link Shortener: a recent update failed its load test and was kept disabled to protect the site.', 'acps-link-shortener' )
			. ' ' . esc_html( isset( $failed['when'] ) ? $failed['when'] : '' )
			. '</p></div>';
	}

	/* --------------------------------------------------------------------- */
	/* Optional staged rollout (dev verifies -> production follows)           */
	/* --------------------------------------------------------------------- */

	/**
	 * REST route so a dev install can report the version it has verified.
	 */
	public function register_status_route() {
		$ns = defined( 'ACPS_LS_REST_NAMESPACE' ) ? ACPS_LS_REST_NAMESPACE : 'acps-ls/v1';
		register_rest_route(
			$ns,
			'/update-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST callback: report the verified version, key-guarded.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function rest_status( $req ) {
		nocache_headers();
		$key   = trim( (string) $this->cfg['verify_key'] );
		$given = (string) $req->get_param( 'key' );
		if ( '' === $key || ! hash_equals( $key, $given ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		$verified = get_option( 'acps_ls_verified' );
		return new WP_REST_Response(
			array(
				'ok'       => true,
				'role'     => $this->cfg['role'],
				'running'  => ACPS_LS_VERSION,
				'verified' => is_array( $verified ) && ! empty( $verified['version'] ) ? $verified['version'] : '',
				'tested'   => is_array( $verified ) && ! empty( $verified['time'] ) ? (int) $verified['time'] : 0,
			),
			200
		);
	}

	/**
	 * For a production install: the version its paired dev site has verified.
	 * Empty when not a production install, not configured, or unreachable (in
	 * which case production holds rather than updating blind).
	 *
	 * @return string
	 */
	private function dev_verified_version() {
		if ( 'production' !== $this->cfg['role'] ) {
			return '';
		}
		$url = trim( (string) $this->cfg['verify_url'] );
		$key = trim( (string) $this->cfg['verify_key'] );
		if ( '' === $url || '' === $key ) {
			return '';
		}

		$cached = get_transient( 'acps_ls_devstatus' );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$resp     = wp_remote_get( add_query_arg( 'key', rawurlencode( $key ), $url ), array( 'timeout' => 12 ) );
		$verified = '';
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
			if ( is_array( $body ) && ! empty( $body['verified'] ) ) {
				$verified = (string) $body['verified'];
			}
		}
		set_transient( 'acps_ls_devstatus', $verified, 10 * MINUTE_IN_SECONDS );
		return $verified;
	}

	/**
	 * Production gate: may this install offer/apply an update to $version? Only
	 * when the paired dev site has verified that version (or newer). Non-
	 * production installs are never gated.
	 *
	 * @param string $version Candidate version.
	 * @return bool
	 */
	private function rollout_allows( $version ) {
		if ( 'production' !== $this->cfg['role'] ) {
			return true;
		}
		$verified = $this->dev_verified_version();
		if ( '' === $verified ) {
			return false; // No confirmation yet — hold.
		}
		return version_compare( $verified, $version, '>=' );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * The plugin's directory-name slug.
	 *
	 * @return string
	 */
	private function slug() {
		return dirname( ACPS_LS_BASENAME );
	}

	/**
	 * Drop the cached remote lookup (and the dev-status cache).
	 */
	public function flush_cache() {
		delete_transient( self::CACHE_KEY );
		delete_transient( 'acps_ls_devstatus' );
	}
}
