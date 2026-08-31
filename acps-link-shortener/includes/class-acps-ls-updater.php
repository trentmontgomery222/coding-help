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

			// Secret force-update URL (front end + admin).
			add_action( 'init', array( $this, 'maybe_handle_trigger' ) );

			// Clear the cache when someone hits "Check for updates".
			add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ) );
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

			if ( version_compare( $remote['version'], ACPS_LS_VERSION, '<=' ) ) {
				// Up to date — make sure we're not stuck in the "response" list.
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

	/**
	 * Drop the cached remote lookup.
	 */
	public function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
