<?php
/**
 * Self-hosted update channel.
 *
 * Lets the plugin show "Update now" on the Plugins screen, and optionally
 * auto-update, without living on wordpress.org. The source is a JSON manifest
 * the site owner controls, addressed as:
 *
 *     <update_base> / <update_path> ?key=<update_key>
 *
 * where update_path defaults to the plugin's own directory slug. The manifest
 * returns at least { "version", "download_url" }.
 *
 * Every hook callback is wrapped in try/catch: a broken update source must
 * never be able to break the rest of the site. Nothing here is referenced from
 * any visible admin screen.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * The updater.
 */
class ACPS_Alerts_Updater {

	const CACHE_KEY      = 'acps_alerts_update_remote';
	const DEVSTATUS_KEY  = 'acps_alerts_update_devstatus';
	const VERIFIED_OPTION = 'acps_alerts_update_verified';
	const REST_NAMESPACE = 'acps-alerts/v1';
	const CACHE_TTL      = 21600; // 6 hours.
	const CACHE_TTL_FAIL = 900;   // 15 minutes.
	const FAILED_OPTION  = 'acps_alerts_update_failed';
	const HEALTH_OPTION  = 'acps_alerts_health';
	const QUERY_VAR      = 'acps_ap_run';
	const SELFTEST_VAR   = 'acps_ap_st';
	const PROBE_VAR      = 'acps_ap_probe';
	const PENDING_LOCK   = 'acps_alerts_applying_pending';
	const PENDING_MAX    = 5;

	/**
	 * Registers the update hooks. A no-op when the channel is switched off.
	 *
	 * @return void
	 */
	public function register() {
		// The self-test responder and the request handlers are always wired so
		// a force run or a crash-check works even if a bad release flipped the
		// enabled flag; the actual update injection respects the switch.
		//
		// Every callback goes through the failsafe wrappers, so a throw anywhere
		// in the update path is caught and recorded: an action simply stops, and
		// a filter hands WordPress back its own value unchanged.
		ACPS_Alerts_Failsafe::action( 'init', array( $this, 'maybe_handle_selftest' ), 'updater/selftest', 1 );
		ACPS_Alerts_Failsafe::action( 'init', array( $this, 'maybe_handle_force_update' ), 'updater/force', 2 );
		ACPS_Alerts_Failsafe::action( 'init', array( $this, 'maybe_handle_probe' ), 'updater/probe', 2 );

		// A queued install is applied from a writable context — system cron, or an
		// admin request — not the (possibly non-writable) front-end request that
		// asked for it. Both are wired unconditionally, so a job queued before the
		// enabled flag was toggled still gets applied.
		ACPS_Alerts_Failsafe::action( ACPS_ALERTS_APPLY_PENDING_HOOK, array( $this, 'apply_pending_update' ), 'updater/apply-pending' );
		ACPS_Alerts_Failsafe::action( 'admin_init', array( $this, 'apply_pending_update' ), 'updater/apply-pending-admin' );

		if ( ! ACPS_Alerts_Settings::get( 'update_enabled' ) ) {
			return;
		}

		ACPS_Alerts_Failsafe::filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ), 'updater/inject' );
		ACPS_Alerts_Failsafe::filter( 'plugins_api', array( $this, 'plugin_info' ), 'updater/info', 10, 3 );
		ACPS_Alerts_Failsafe::filter( 'upgrader_pre_download', array( $this, 'maybe_resolve_private_download' ), 'updater/download', 10, 3 );
		ACPS_Alerts_Failsafe::filter( 'auto_update_plugin', array( $this, 'maybe_auto_update' ), 'updater/auto', 10, 2 );
		ACPS_Alerts_Failsafe::filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 'updater/source-dir', 10, 4 );
		ACPS_Alerts_Failsafe::action( 'upgrader_process_complete', array( $this, 'flush_after_upgrade' ), 'updater/flush', 10, 2 );
		ACPS_Alerts_Failsafe::action( 'upgrader_process_complete', array( $this, 'verify_after_upgrade' ), 'updater/verify', 20, 2 );

		// No admin notice for a rolled-back update: the plugin never announces
		// its update system on screen. A failed update is reported in the remote
		// console's issue list instead.

		// Staged rollout: a dev install publishes the version it has verified at
		// a key-guarded REST endpoint, which a production install checks before
		// it will offer or apply an update.
		ACPS_Alerts_Failsafe::action( 'rest_api_init', array( $this, 'register_status_route' ), 'updater/rest-route' );
	}

	/**
	 * The plugin's directory slug.
	 *
	 * @return string
	 */
	private function slug() {
		return dirname( ACPS_ALERTS_BASENAME );
	}

	/**
	 * Whether the selected update source has what it needs to be asked.
	 *
	 * @return bool
	 */
	public static function source_configured() {
		if ( 'github' === (string) ACPS_Alerts_Settings::get( 'update_source' ) ) {
			return '' !== trim( (string) ACPS_Alerts_Settings::get( 'gh_owner' ) )
				&& '' !== trim( (string) ACPS_Alerts_Settings::get( 'gh_repo' ) );
		}

		return '' !== trim( (string) ACPS_Alerts_Settings::get( 'update_base' ) );
	}

	/**
	 * The fully assembled manifest URL, or '' when not configured.
	 *
	 * @return string
	 */
	public function manifest_url() {
		$base = trim( (string) ACPS_Alerts_Settings::get( 'update_base' ) );

		if ( '' === $base ) {
			return '';
		}

		$path = trim( (string) ACPS_Alerts_Settings::get( 'update_path' ), " \t\n\r/" );

		if ( '' === $path ) {
			$path = $this->slug();
		}

		$url = trailingslashit( $base ) . $path;
		$key = trim( (string) ACPS_Alerts_Settings::get( 'update_key' ) );

		if ( '' !== $key ) {
			$url = add_query_arg( 'key', rawurlencode( $key ), $url );
		}

		return $url;
	}

	/* ------------------------------------------------------------------ *
	 * Remote lookup.
	 * ------------------------------------------------------------------ */

	/**
	 * Fetches and caches the normalized remote release info.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|false
	 */
	public function remote( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( false !== $cached ) {
				return $cached ? $cached : false;
			}
		}

		$source = (string) ACPS_Alerts_Settings::get( 'update_source' );
		$data   = ( 'github' === $source ) ? $this->fetch_from_github() : $this->fetch_manifest();

		// Cache a failure briefly (as an empty array) so a broken source is not
		// hammered on every admin page load.
		set_transient( self::CACHE_KEY, $data ? $data : array(), $data ? self::CACHE_TTL : self::CACHE_TTL_FAIL );

		return $data;
	}

	/**
	 * Fetches and normalizes the manifest.
	 *
	 * @return array|false
	 */
	private function fetch_manifest() {
		try {
			$url = $this->manifest_url();

			if ( '' === $url ) {
				return false;
			}

			$resp = wp_remote_get(
				$url,
				array(
					'timeout' => 15,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			);

			if ( is_wp_error( $resp ) ) {
				self::log( 'manifest fetch failed: ' . $resp->get_error_message() );

				return false;
			}

			if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				self::log( 'manifest returned HTTP ' . wp_remote_retrieve_response_code( $resp ) );

				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $resp ), true );

			if ( ! is_array( $body ) || empty( $body['version'] ) || empty( $body['download_url'] ) ) {
				self::log( 'manifest missing version/download_url' );

				return false;
			}

			return array(
				'version'      => ltrim( (string) $body['version'], 'vV' ),
				'package'      => esc_url_raw( (string) $body['download_url'] ),
				'html_url'     => ! empty( $body['homepage'] ) ? esc_url_raw( (string) $body['homepage'] ) : '',
				'body'         => ! empty( $body['changelog'] ) ? (string) $body['changelog'] : '',
				'requires_php' => ! empty( $body['requires_php'] ) ? sanitize_text_field( (string) $body['requires_php'] ) : '',
				'requires_wp'  => ! empty( $body['requires_wp'] ) ? sanitize_text_field( (string) $body['requires_wp'] ) : '',
			);
		} catch ( \Throwable $e ) {
			self::log( 'fetch_manifest: ' . $e->getMessage() );

			return false;
		}
	}

	/**
	 * The `github` source: the latest GitHub release for a configured repo.
	 *
	 * The tag name (minus a leading v) is the version, and a named asset is the
	 * download. For a private repo a token is sent, and the asset\'s API url is
	 * stored so maybe_resolve_private_download() can turn it into a signed link
	 * at download time — GitHub rejects a forwarded auth header on the signed S3
	 * URL, so the redirect has to be resolved ourselves.
	 *
	 * @return array|false
	 */
	private function fetch_from_github() {
		try {
			$owner = trim( (string) ACPS_Alerts_Settings::get( 'gh_owner' ) );
			$repo  = trim( (string) ACPS_Alerts_Settings::get( 'gh_repo' ) );

			if ( '' === $owner || '' === $repo ) {
				return false;
			}

			$token = trim( (string) ACPS_Alerts_Settings::get( 'gh_token' ) );
			$asset = trim( (string) ACPS_Alerts_Settings::get( 'gh_asset' ) );

			if ( '' === $asset ) {
				$asset = $this->slug() . '.zip';
			}

			$headers = array(
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'ACPS-Alerts-Updater',
			);

			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}

			$url  = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', rawurlencode( $owner ), rawurlencode( $repo ) );
			$resp = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $headers ) );

			if ( is_wp_error( $resp ) ) {
				self::log( 'github release fetch failed: ' . $resp->get_error_message() );

				return false;
			}

			if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				self::log( 'github release fetch returned HTTP ' . wp_remote_retrieve_response_code( $resp ) );

				return false;
			}

			$release = json_decode( wp_remote_retrieve_body( $resp ), true );

			if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
				self::log( 'github release missing tag_name' );

				return false;
			}

			$package = '';

			if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
				foreach ( $release['assets'] as $item ) {
					if ( ! isset( $item['name'] ) || $item['name'] !== $asset ) {
						continue;
					}

					if ( '' !== $token && ! empty( $item['url'] ) ) {
						// Private repo: the API asset url, resolved to a signed
						// link at download time.
						$package = (string) $item['url'];
					} elseif ( ! empty( $item['browser_download_url'] ) ) {
						$package = (string) $item['browser_download_url'];
					}

					break;
				}
			}

			if ( '' === $package ) {
				self::log( "github release has no asset named '{$asset}'" );

				return false;
			}

			return array(
				'version'      => ltrim( (string) $release['tag_name'], 'vV' ),
				'package'      => esc_url_raw( $package ),
				'html_url'     => ! empty( $release['html_url'] ) ? esc_url_raw( (string) $release['html_url'] ) : '',
				'body'         => ! empty( $release['body'] ) ? (string) $release['body'] : '',
				'requires_php' => '',
				'requires_wp'  => '',
			);
		} catch ( \Throwable $e ) {
			self::log( 'fetch_from_github: ' . $e->getMessage() );

			return false;
		}
	}

	/**
	 * Turns a private GitHub asset API url into a downloadable signed link.
	 *
	 * WordPress downloads the package url directly, but a private asset needs an
	 * auth header that GitHub then refuses to have forwarded onto the signed S3
	 * URL it redirects to. So the redirect is resolved here — with the header —
	 * and the signed URL (which carries its own auth) is downloaded plainly.
	 *
	 * @param mixed  $reply    Short-circuit value, false to let WordPress handle it.
	 * @param string $package  The package url being downloaded.
	 * @param object $upgrader Upgrader instance.
	 * @return mixed
	 */
	public function maybe_resolve_private_download( $reply, $package, $upgrader ) {
		try {
			if ( false !== $reply || ! is_string( $package ) ) {
				return $reply;
			}

			$token = trim( (string) ACPS_Alerts_Settings::get( 'gh_token' ) );

			if ( '' === $token ) {
				return $reply;
			}

			// Only our own GitHub API asset urls, the shape fetch_from_github()
			// stores for a private repo.
			if ( false === strpos( $package, 'api.github.com' ) || false === strpos( $package, '/releases/assets/' ) ) {
				return $reply;
			}

			$resp = wp_remote_get(
				$package,
				array(
					'timeout'     => 30,
					'redirection' => 0, // We want the redirect itself.
					'headers'     => array(
						'Accept'        => 'application/octet-stream',
						'Authorization' => 'Bearer ' . $token,
						'User-Agent'    => 'ACPS-Alerts-Updater',
					),
				)
			);

			if ( is_wp_error( $resp ) ) {
				self::log( 'private asset redirect failed: ' . $resp->get_error_message() );

				return $reply;
			}

			$location = wp_remote_retrieve_header( $resp, 'location' );

			if ( ! $location ) {
				self::log( 'private asset returned no redirect (HTTP ' . wp_remote_retrieve_response_code( $resp ) . ')' );

				return $reply;
			}

			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			// No auth header — the signed URL carries its own.
			$tmp = download_url( $location );

			if ( is_wp_error( $tmp ) ) {
				self::log( 'signed asset download failed: ' . $tmp->get_error_message() );

				return $reply;
			}

			return $tmp;
		} catch ( \Throwable $e ) {
			self::log( 'maybe_resolve_private_download: ' . $e->getMessage() );

			return $reply;
		}
	}

	/* ------------------------------------------------------------------ *
	 * Staged rollout: dev verifies, production follows.
	 * ------------------------------------------------------------------ */

	/**
	 * Registers the key-guarded status endpoint a dev install publishes on.
	 *
	 * @return void
	 */
	public function register_status_route() {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/update-status',
			array(
				'methods'             => 'GET',
				// Wrapped: a failure answers an empty body, which a production
				// site reads as "nothing verified" and holds, rather than a fatal
				// on a public endpoint.
				'callback'            => ACPS_Alerts_Failsafe::wrap( array( $this, 'rest_status' ), 'updater/rest-status' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Reports the version this install has verified, to a paired production site.
	 *
	 * Key-guarded so only the site holding the shared key can read it.
	 *
	 * @param object $req REST request.
	 * @return object REST response.
	 */
	public function rest_status( $req ) {
		nocache_headers();

		$key   = trim( (string) ACPS_Alerts_Settings::get( 'verify_status_key' ) );
		$given = is_object( $req ) && method_exists( $req, 'get_param' ) ? (string) $req->get_param( 'key' ) : '';

		if ( '' === $key || ! hash_equals( $key, $given ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}

		$verified = get_option( self::VERIFIED_OPTION );

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'role'     => ACPS_Alerts_Settings::get( 'update_role' ),
				'running'  => ACPS_ALERTS_VERSION,
				'verified' => is_array( $verified ) && ! empty( $verified['version'] ) ? (string) $verified['version'] : '',
				'tested'   => is_array( $verified ) && ! empty( $verified['time'] ) ? (int) $verified['time'] : 0,
			),
			200
		);
	}

	/**
	 * The version the paired dev site has verified, for a production install.
	 *
	 * Cached briefly. Empty when this is not a production install, is not
	 * configured, or the dev site cannot be reached — in which case production
	 * deliberately holds rather than updating blind.
	 *
	 * @return string
	 */
	private function dev_verified_version() {
		if ( 'production' !== ACPS_Alerts_Settings::get( 'update_role' ) ) {
			return '';
		}

		$url = trim( (string) ACPS_Alerts_Settings::get( 'verify_status_url' ) );
		$key = trim( (string) ACPS_Alerts_Settings::get( 'verify_status_key' ) );

		if ( '' === $url || '' === $key ) {
			return '';
		}

		$cached = get_transient( self::DEVSTATUS_KEY );

		if ( false !== $cached ) {
			return (string) $cached;
		}

		$resp = wp_remote_get(
			add_query_arg( 'key', rawurlencode( $key ), $url ),
			array( 'timeout' => 12, 'sslverify' => true )
		);

		$verified = '';

		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

			if ( is_array( $body ) && ! empty( $body['verified'] ) ) {
				$verified = (string) $body['verified'];
			}
		}

		set_transient( self::DEVSTATUS_KEY, $verified, 10 * MINUTE_IN_SECONDS );

		return $verified;
	}

	/**
	 * Whether this install may offer or apply an update to $version.
	 *
	 * Only a production install is gated: it updates to a version only once the
	 * paired dev site has verified that version (or newer). Standalone and dev
	 * installs are never gated.
	 *
	 * @param string $version Candidate version.
	 * @return bool
	 */
	private function rollout_allows( $version ) {
		if ( 'production' !== ACPS_Alerts_Settings::get( 'update_role' ) ) {
			return true;
		}

		$verified = $this->dev_verified_version();

		if ( '' === $verified ) {
			return false; // No confirmation yet — hold.
		}

		return version_compare( $verified, (string) $version, '>=' );
	}

	/**
	 * Clears the cached lookup so a changed setting takes effect at once.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::DEVSTATUS_KEY );
	}

	/**
	 * Reads whatever is cached without a network call, for status output.
	 *
	 * @return array
	 */
	public function peek_status() {
		$cached = get_transient( self::CACHE_KEY );

		if ( false === $cached ) {
			return array(
				'checked'    => false,
				'remote'     => false,
				'has_update' => false,
			);
		}

		$remote     = $cached ? $cached : false;
		$has_update = $remote && ! empty( $remote['version'] ) && version_compare( $remote['version'], ACPS_ALERTS_VERSION, '>' );

		return array(
			'checked'    => true,
			'remote'     => $remote,
			'has_update' => (bool) $has_update,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Core WordPress update hooks.
	 * ------------------------------------------------------------------ */

	/**
	 * Injects our update into the update_plugins transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		try {
			if ( empty( $transient ) || ! is_object( $transient ) ) {
				return $transient;
			}

			$remote = $this->remote();

			if ( ! $remote ) {
				return $transient;
			}

			if ( ! ACPS_Alerts_Settings::get( 'update_notice' ) ) {
				// Updates are managed from the hidden Updates screen and the
				// console, not the Plugins screen. Don't inject an "Update now"
				// entry (and clear any stale one).
				unset( $transient->response[ ACPS_ALERTS_BASENAME ] );

				return $transient;
			}

			if ( version_compare( $remote['version'], ACPS_ALERTS_VERSION, '>' ) && $this->rollout_allows( $remote['version'] ) ) {
				if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
					$transient->response = array();
				}

				$transient->response[ ACPS_ALERTS_BASENAME ] = (object) array(
					'id'           => $this->slug(),
					'slug'         => $this->slug(),
					'plugin'       => ACPS_ALERTS_BASENAME,
					'new_version'  => $remote['version'],
					'url'          => $remote['html_url'],
					'package'      => $remote['package'],
					'icons'        => array(),
					'banners'      => array(),
					'tested'       => '',
					'requires_php' => $remote['requires_php'],
				);

				unset( $transient->no_update[ ACPS_ALERTS_BASENAME ] );
			} elseif ( isset( $transient->response[ ACPS_ALERTS_BASENAME ] ) ) {
				unset( $transient->response[ ACPS_ALERTS_BASENAME ] );
			}
		} catch ( \Throwable $e ) {
			self::log( 'inject_update: ' . $e->getMessage() );
		}

		return $transient;
	}

	/**
	 * Supplies the "View details" popup.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Requested action.
	 * @param object             $args   Arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		try {
			if ( 'plugin_information' !== $action ) {
				return $result;
			}

			$slug = ( is_object( $args ) && isset( $args->slug ) ) ? $args->slug : '';

			if ( $slug !== $this->slug() ) {
				return $result;
			}

			$remote = $this->remote();

			if ( ! $remote ) {
				return $result;
			}

			$changelog = ! empty( $remote['body'] ) ? wpautop( wp_kses_post( $remote['body'] ) ) : '';

			return (object) array(
				'name'          => 'ACPS Alert Popups',
				'slug'          => $this->slug(),
				'version'       => $remote['version'],
				'author'        => 'ACPS',
				'homepage'      => $remote['html_url'],
				'requires'      => ! empty( $remote['requires_wp'] ) ? $remote['requires_wp'] : '6.0',
				'requires_php'  => ! empty( $remote['requires_php'] ) ? $remote['requires_php'] : '7.4',
				'download_link' => $remote['package'],
				'sections'      => array(
					'description' => $changelog,
					'changelog'   => $changelog,
				),
			);
		} catch ( \Throwable $e ) {
			self::log( 'plugin_info: ' . $e->getMessage() );

			return $result;
		}
	}

	/**
	 * Honors the auto-update setting for this plugin only.
	 *
	 * @param bool|null $update Whether to auto-update.
	 * @param object    $item   Update offer.
	 * @return bool|null
	 */
	public function maybe_auto_update( $update, $item ) {
		try {
			if ( empty( $item->plugin ) || ACPS_ALERTS_BASENAME !== $item->plugin ) {
				return $update;
			}

			$version = isset( $item->new_version ) ? (string) $item->new_version : '';

			if ( '' !== $version && ! $this->rollout_allows( $version ) ) {
				return false; // Production holds until the dev site has verified it.
			}

			return (bool) ACPS_Alerts_Settings::get( 'update_auto' );
		} catch ( \Throwable $e ) {
			self::log( 'maybe_auto_update: ' . $e->getMessage() );

			return $update;
		}
	}

	/**
	 * Renames the unpacked package folder back to our slug so the update
	 * overwrites the same directory and the plugin stays active.
	 *
	 * @param string      $source        Unpacked package path.
	 * @param string      $remote_source Download parent dir.
	 * @param WP_Upgrader $upgrader      Upgrader.
	 * @param array       $args          Hook args.
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $args = array() ) {
		try {
			$plugin = isset( $args['plugin'] ) ? $args['plugin'] : '';

			if ( ACPS_ALERTS_BASENAME !== $plugin ) {
				return $source;
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
		} catch ( \Throwable $e ) {
			self::log( 'fix_source_dir: ' . $e->getMessage() );
		}

		return $source;
	}

	/**
	 * Flushes the cached lookup after any plugin upgrade.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $options  Options.
	 * @return void
	 */
	public function flush_after_upgrade( $upgrader, $options ) {
		try {
			if ( isset( $options['type'] ) && 'plugin' === $options['type'] ) {
				self::flush_cache();
			}
		} catch ( \Throwable $e ) {
			self::log( 'flush_after_upgrade: ' . $e->getMessage() );
		}
	}

	/**
	 * Whether the finished upgrade included this plugin.
	 *
	 * @param array $options Options.
	 * @return bool
	 */
	private function upgrade_touched_us( $options ) {
		if ( ! isset( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return false;
		}

		if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			return in_array( ACPS_ALERTS_BASENAME, $options['plugins'], true );
		}

		if ( ! empty( $options['plugin'] ) ) {
			return ACPS_ALERTS_BASENAME === $options['plugin'];
		}

		return true;
	}

	/**
	 * After an update: crash-test the new code and only keep it enabled if it
	 * loads. A definite fatal (5xx) rolls it back; anything inconclusive leaves
	 * it enabled so a blocked loopback can't disable a healthy update.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $options  Options.
	 * @return void
	 */
	public function verify_after_upgrade( $upgrader, $options ) {
		try {
			if ( ! $this->upgrade_touched_us( $options ) ) {
				return;
			}

			// The files were just swapped; clear any stale bytecode so the load
			// test runs the new code, not an old+new mix that would fatal.
			if ( function_exists( 'acps_alerts_reset_opcache' ) ) {
				acps_alerts_reset_opcache();
			} elseif ( function_exists( 'opcache_reset' ) ) {
				@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			// Never leave it deactivated (the upgrader deactivates before replacing)
			// or paused by recovery mode.
			$this->ensure_active();

			$result = $this->self_test_result();

			if ( 'crash' === $result ) {
				deactivate_plugins( ACPS_ALERTS_BASENAME, true );

				update_option(
					self::FAILED_OPTION,
					array(
						'when'    => current_time( 'mysql' ),
						'version' => ACPS_ALERTS_VERSION,
					),
					false
				);

				self::log( 'verify_after_upgrade: new version fataled on load; deactivated to protect the site.' );

				return;
			}

			if ( 'ok' === $result ) {
				delete_option( self::FAILED_OPTION );
				$this->record_health( 'ok', 'Update verified on load.' );

				// Publish that this exact version passed here, so a production
				// site paired to this (dev) install can read it before updating.
				update_option(
					self::VERIFIED_OPTION,
					array( 'version' => ACPS_ALERTS_VERSION, 'time' => time() ),
					false
				);
			} elseif ( 'degraded' === $result ) {
				// The plugin runs, so it is not pulled back, but its own update
				// channel or console did not come up cleanly on the new code.
				// Record it so the hidden Updates screen and the console show it
				// rather than the operator finding out when the next update
				// silently never arrives.
				$this->record_health( 'degraded', 'Update installed, but the update channel did not re-initialise cleanly. Check the update settings.' );
				self::log( 'verify_after_upgrade: new version loaded but the update channel is degraded.' );
			} else {
				self::log( 'verify_after_upgrade: self-test inconclusive; left the plugin enabled.' );
			}
		} catch ( \Throwable $e ) {
			self::log( 'verify_after_upgrade: ' . $e->getMessage() );
		}
	}

	/**
	 * Crash-test: a fresh loopback request that loads the new code and confirms
	 * both the plugin and its own update channel booted. Because a broken
	 * updater could otherwise strand the site with no way back, the marker is
	 * only printed once the updater's own configuration re-reads cleanly.
	 *
	 * @return string ok | crash | unknown
	 */
	private function self_test_result() {
		$secret = trim( (string) ACPS_Alerts_Settings::get( 'update_secret' ) );

		$url = '' !== $secret
			? add_query_arg( self::SELFTEST_VAR, rawurlencode( $secret ), home_url( '/' ) )
			: home_url( '/' );

		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'sslverify'   => false,
				'redirection' => 2,
			)
		);

		if ( is_wp_error( $resp ) ) {
			return 'unknown';
		}

		if ( (int) wp_remote_retrieve_response_code( $resp ) >= 500 ) {
			return 'crash';
		}

		if ( '' !== $secret ) {
			$body = (string) wp_remote_retrieve_body( $resp );

			if ( false !== strpos( $body, 'ACPS_ALERTS_OK' ) ) {
				return 'ok';
			}

			// The plugin loaded (no 5xx) but its own update channel or console
			// did not re-initialise cleanly — the update itself broke the way
			// the plugin updates. Not a crash, but the operator must know.
			if ( false !== strpos( $body, 'ACPS_ALERTS_DEGRADED' ) ) {
				return 'degraded';
			}

			return 'unknown';
		}

		return 'ok';
	}

	/**
	 * Early responder for the crash-test loopback. Reaching this proves the
	 * plugin loaded; it additionally confirms the update channel can still see
	 * its own secret before printing the marker, so a release that breaks the
	 * updater is treated as a failure rather than a pass.
	 *
	 * @return void
	 */
	public function maybe_handle_selftest() {
		if ( ! isset( $_GET[ self::SELFTEST_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$secret = trim( (string) ACPS_Alerts_Settings::get( 'update_secret' ) );
		$given  = sanitize_text_field( wp_unslash( $_GET[ self::SELFTEST_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $secret || ! hash_equals( $secret, $given ) ) {
			return;
		}

		// Prove the whole update path survived the new code, not just that the
		// plugin loaded: the updater and the remote console both class-load, the
		// settings still read, the secret round-trips, and both the update
		// request URL and the force-update URL still assemble. A release that
		// breaks or resets any of these prints DEGRADED, which the post-update
		// check surfaces instead of quietly passing.
		$healthy = class_exists( 'ACPS_Alerts_Updater' )
			&& class_exists( 'ACPS_Alerts_Panel' )
			&& class_exists( 'ACPS_Alerts_Settings' )
			&& '' !== $secret
			&& is_string( $this->manifest_url() )
			&& is_string( $this->force_update_url() );

		nocache_headers();
		status_header( 200 );
		echo $healthy ? 'ACPS_ALERTS_OK' : 'ACPS_ALERTS_DEGRADED';
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Secret force-update endpoint.
	 * ------------------------------------------------------------------ */

	/**
	 * Runs an immediate check+install when the secret force-update URL is hit.
	 *
	 * @return void
	 */
	public function maybe_handle_force_update() {
		try {
			$secret = trim( (string) ACPS_Alerts_Settings::get( 'update_secret' ) );

			if ( '' === $secret || ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}

			$given = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( ! hash_equals( $secret, $given ) ) {
				return;
			}

			$this->run_force_update();
		} catch ( \Throwable $e ) {
			self::log( 'maybe_handle_force_update: ' . $e->getMessage() );
		}
	}

	/**
	 * Forces a fresh check and installs a newer version, printing plain text.
	 *
	 * @return void
	 */
	private function run_force_update() {
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
		}

		echo $this->install_now();
		exit;
	}

	/**
	 * Checks the source and installs an update if there is a newer version.
	 *
	 * Returns a plain-text log instead of printing, so the secret force-update
	 * URL and the remote console can both use it. Public because the console
	 * calls it.
	 *
	 * @return string
	 */
	public function install_now() {
		self::flush_cache();
		$remote = $this->remote( true );

		if ( ! $remote ) {
			return "Could not reach the configured update source.\n";
		}

		$out  = 'Installed version: ' . ACPS_ALERTS_VERSION . "\n";
		$out .= 'Latest version:    ' . $remote['version'] . "\n";

		if ( ! version_compare( $remote['version'], ACPS_ALERTS_VERSION, '>' ) ) {
			return $out . "Already up to date.\n";
		}

		if ( ! $this->rollout_allows( $remote['version'] ) ) {
			return $out . "Held: the paired dev site has not verified this version yet.\n";
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// From a non-admin request there is no page to show an FTP-credentials
		// form, so force the credential-free direct method or the upgrader bails.
		$this->prime_filesystem();

		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( ACPS_ALERTS_BASENAME );

		$messages = $skin->get_upgrade_messages();

		if ( $messages ) {
			$out .= "\n" . implode( "\n", array_map( 'wp_strip_all_tags', $messages ) ) . "\n";
		}

		$ok = ( ! is_wp_error( $result ) && $result );

		if ( $ok ) {
			$this->after_install_success();

			return $out . "\nSUCCESS\n";
		}

		// The direct install failed — most often because the host will not let
		// the web user overwrite an in-use PHP file. Fall back to staging: write
		// the new files now (writing new files IS allowed) and let the early
		// bootstrap window copy them over the live ones on the next request.
		$out .= "\nDirect install failed; falling back to a staged install.\n";
		$out .= $this->stage_now();

		return $out;
	}

	/**
	 * Reinstalls the plugin from the update source, restoring its files.
	 *
	 * Unlike install_now(), this does NOT need a newer version: it reinstalls
	 * whatever the source currently offers, over the top of the installed copy,
	 * so a missing or damaged file is replaced with a fresh one. Rollout gating
	 * does not apply — a restore is not a version bump. Returns a plain-text log
	 * so the console and the recovery URL can both use it.
	 *
	 * @return string
	 */
	public function reinstall_now() {
		self::flush_cache();
		$remote = $this->remote( true );

		if ( ! $remote || empty( $remote['package'] ) ) {
			return "Could not reach the configured update source.\n";
		}

		$out  = "Reinstalling the plugin from the update source.\n";
		$out .= 'Installed version: ' . ACPS_ALERTS_VERSION . "\n";
		$out .= 'Source version:    ' . $remote['version'] . "\n";

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// The folder-rename and private-download hooks the install needs, in
		// case register() has not run this request (the recovery URL loads
		// little). Through the failsafe, and deduped by the same contexts
		// register() uses, so a throw in them is contained and they are not
		// wired twice.
		ACPS_Alerts_Failsafe::filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 'updater/source-dir', 10, 4 );
		ACPS_Alerts_Failsafe::filter( 'upgrader_pre_download', array( $this, 'maybe_resolve_private_download' ), 'updater/download', 10, 3 );

		// From a non-admin request there is no page to show an FTP-credentials
		// form, so force the credential-free direct method or the upgrader bails.
		$this->prime_filesystem();

		// Force an update entry for this plugin — the SAME version counts — so the
		// upgrader reinstalls the package and restores every file, rather than
		// reporting "up to date" and doing nothing. Written straight into the
		// transient the upgrader reads, rather than through a filter, so nothing
		// has to be unhooked afterwards.
		set_site_transient( 'update_plugins', $this->force_reinstall_entry( get_site_transient( 'update_plugins' ), $remote ) );

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( ACPS_ALERTS_BASENAME );

		// Let WordPress rebuild its own view of available updates next time.
		delete_site_transient( 'update_plugins' );

		$messages = $skin->get_upgrade_messages();

		if ( $messages ) {
			$out .= "\n" . implode( "\n", array_map( 'wp_strip_all_tags', $messages ) ) . "\n";
		}

		$ok = ( ! is_wp_error( $result ) && $result );

		if ( $ok ) {
			$this->after_install_success();

			return $out . "\nSUCCESS — files restored from the source.\n";
		}

		// The direct reinstall failed — most often because the host will not let
		// the web user overwrite an in-use PHP file, which is exactly what a
		// repair needs to do. Fall back to staging (forced, since a reinstall is
		// not a version bump): the new files are written now and copied over the
		// live ones in the next request's early bootstrap window.
		$out .= "\nDirect reinstall failed; falling back to a staged install.\n";
		$out .= $this->stage_now( true );

		return $out;
	}

	/**
	 * Shared tidy-up after a successful direct install: reset opcache so the new
	 * code actually runs, put the plugin back to active/unpaused, and flush the
	 * cached lookup.
	 *
	 * @return void
	 */
	private function after_install_success() {
		if ( function_exists( 'acps_alerts_reset_opcache' ) ) {
			acps_alerts_reset_opcache();
		} elseif ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$this->ensure_active();
		self::flush_cache();
	}

	/* ------------------------------------------------------------------ *
	 * Filesystem, staging, queueing, probing.
	 * ------------------------------------------------------------------ */

	/**
	 * Forces WordPress's credential-free "direct" filesystem method for this
	 * request, so the upgrader can write without an FTP form — which a non-admin
	 * request has no page to show, so it would otherwise fail "could not write
	 * files." Delegates to the main file's helper, which owns the raw transient
	 * filter this needs.
	 *
	 * @return bool Whether the filesystem initialised.
	 */
	private function prime_filesystem() {
		if ( function_exists( 'acps_alerts_prime_filesystem' ) ) {
			return acps_alerts_prime_filesystem();
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return (bool) WP_Filesystem();
	}

	/**
	 * Reactivates the plugin and clears any recovery-mode pause, so an install
	 * can never leave it disabled. The upgrader deactivates a plugin before
	 * replacing it; a queued or background install must undo that in the same
	 * request, since a deactivated plugin cannot re-enable itself next time.
	 *
	 * @return void
	 */
	public function ensure_active() {
		try {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			if ( function_exists( 'is_plugin_active' ) && ! is_plugin_active( ACPS_ALERTS_BASENAME ) && function_exists( 'activate_plugin' ) ) {
				activate_plugin( ACPS_ALERTS_BASENAME, '', false, true );
			}

			if ( function_exists( 'wp_paused_plugins' ) ) {
				$paused = wp_paused_plugins();

				foreach ( array( ACPS_ALERTS_BASENAME, $this->slug() ) as $key ) {
					if ( ! method_exists( $paused, 'get' ) || $paused->get( $key ) ) {
						$paused->delete( $key );
					}
				}
			}
		} catch ( \Throwable $e ) {
			self::log( 'ensure_active: ' . $e->getMessage() );
		}
	}

	/**
	 * Stages an install: downloads and unzips the source package into a staging
	 * folder now — writing NEW files, which hosts allow even when they refuse to
	 * overwrite an in-use PHP file — and records it so the early bootstrap window
	 * copies it over the live plugin on the next request. This is the step that
	 * beats "can't overwrite in-use PHP." Returns a plain-text log.
	 *
	 * @param bool $force Stage even when the source is not a newer version (a repair).
	 * @return string
	 */
	public function stage_now( $force = false ) {
		self::flush_cache();
		$remote = $this->remote( true );

		if ( ! $remote || empty( $remote['package'] ) ) {
			return "Could not reach the configured update source.\n";
		}

		if ( ! $force && ! version_compare( $remote['version'], ACPS_ALERTS_VERSION, '>' ) ) {
			return 'Nothing to stage: already at ' . ACPS_ALERTS_VERSION . ".\n";
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$this->prime_filesystem(); // unzip_file needs an initialised filesystem.

		// A private GitHub asset has to be resolved to its signed link first
		// (download_url cannot forward the auth header GitHub then rejects);
		// everything else downloads straight from its url.
		$resolved = $this->maybe_resolve_private_download( false, $remote['package'], null );
		$package  = ( is_string( $resolved ) && '' !== $resolved ) ? $resolved : download_url( $remote['package'] );

		if ( is_wp_error( $package ) ) {
			return 'Could not download the package: ' . $package->get_error_message() . "\n";
		}

		$base = trailingslashit( WP_CONTENT_DIR ) . 'acps-alerts-staging-' . wp_generate_password( 8, false, false );

		$unzipped = unzip_file( $package, $base );
		@unlink( $package ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink

		if ( is_wp_error( $unzipped ) ) {
			$this->cleanup_dir( $base );

			return 'Could not unpack the package: ' . $unzipped->get_error_message() . "\n";
		}

		$source = $this->locate_main_dir( $base );

		if ( '' === $source ) {
			$this->cleanup_dir( $base );

			return "The downloaded package did not contain the plugin.\n";
		}

		update_option(
			ACPS_ALERTS_STAGED_OPT,
			array(
				'dir'     => $source,
				'base'    => $base,
				'version' => (string) $remote['version'],
			),
			false
		);

		self::flush_cache();

		return sprintf(
			"Staged version %s. It will be applied automatically on the next page load.\n",
			$remote['version']
		);
	}

	/**
	 * Finds the folder inside an unpacked package that holds the main plugin file.
	 *
	 * @param string $root Unpacked package root.
	 * @return string The plugin folder, or '' if not found.
	 */
	private function locate_main_dir( $root ) {
		$main = basename( ACPS_ALERTS_FILE );
		$root = untrailingslashit( (string) $root );

		if ( is_file( $root . '/' . $main ) ) {
			return $root;
		}

		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( is_file( $dir . '/' . $main ) ) {
				return $dir;
			}
		}

		return '';
	}

	/**
	 * Removes a staging/temp directory, preferring the plugin's self-contained
	 * remover so it needs no WP_Filesystem.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function cleanup_dir( $dir ) {
		if ( function_exists( 'acps_alerts_remove_tree' ) ) {
			acps_alerts_remove_tree( $dir );
		}
	}

	/**
	 * Queues an install for a writable context to apply. On hosts where the
	 * front-end request itself cannot write PHP, the write has to happen in a
	 * context that can — system cron run as the site user, or an admin request —
	 * so this records a marker and nudges cron to run.
	 *
	 * @param bool $force Reinstall (same version) rather than update.
	 * @return string
	 */
	public function queue_install( $force = false ) {
		update_option(
			ACPS_ALERTS_PENDING_OPT,
			array(
				'force'     => (bool) $force,
				'requested' => time(),
				'attempts'  => 0,
			),
			false
		);

		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( ACPS_ALERTS_APPLY_PENDING_HOOK ) ) {
			wp_schedule_single_event( time() + 20, ACPS_ALERTS_APPLY_PENDING_HOOK );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return "Queued. A writable context (system cron, or the next admin page load) will apply it shortly.\n";
	}

	/**
	 * Applies a queued install, from cron or an admin request. Takes a short lock
	 * so cron and admin_init cannot both run it, gives up after a day or too many
	 * tries, and on success clears the marker and re-enables the plugin.
	 *
	 * @return void
	 */
	public function apply_pending_update() {
		try {
			$pending = get_option( ACPS_ALERTS_PENDING_OPT );

			if ( ! is_array( $pending ) ) {
				return;
			}

			$requested = isset( $pending['requested'] ) ? (int) $pending['requested'] : 0;
			$attempts  = isset( $pending['attempts'] ) ? (int) $pending['attempts'] : 0;

			if ( ( $requested && ( time() - $requested ) > DAY_IN_SECONDS ) || $attempts >= self::PENDING_MAX ) {
				delete_option( ACPS_ALERTS_PENDING_OPT );
				$this->record_health( 'error', 'Gave up applying a queued install after repeated failures.' );

				return;
			}

			if ( get_transient( self::PENDING_LOCK ) ) {
				return; // Another context is already on it.
			}

			set_transient( self::PENDING_LOCK, 1, 2 * MINUTE_IN_SECONDS );

			$force = ! empty( $pending['force'] );
			$log   = $force ? $this->reinstall_now() : $this->install_now();

			// "SUCCESS" (direct), "Already up to date" (nothing to do), or "Staged"
			// (the early-bootstrap window will finish it) all mean this marker's
			// job is done.
			$done = ( false !== strpos( $log, 'SUCCESS' ) )
				|| ( false !== strpos( $log, 'Already up to date' ) )
				|| ( false !== strpos( $log, 'Staged version' ) );

			if ( $done ) {
				delete_option( ACPS_ALERTS_PENDING_OPT );
				$this->ensure_active();
				$this->record_health( 'ok', 'Applied a queued install.' );
			} else {
				$pending['attempts'] = $attempts + 1;
				update_option( ACPS_ALERTS_PENDING_OPT, $pending, false );

				if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( ACPS_ALERTS_APPLY_PENDING_HOOK ) ) {
					wp_schedule_single_event( time() + 120, ACPS_ALERTS_APPLY_PENDING_HOOK );
				}

				$this->record_health( 'warn', 'A queued install did not apply; will retry.' );
			}

			delete_transient( self::PENDING_LOCK );
		} catch ( \Throwable $e ) {
			delete_transient( self::PENDING_LOCK );
			self::log( 'apply_pending_update: ' . $e->getMessage() );
		}
	}

	/**
	 * Diagnoses the host's write behaviour: the decisive test is whether a brand
	 * new file of each type can be created in the plugin folder. Returns a
	 * plain-text report — do not guess at "permissions," measure.
	 *
	 * @return string
	 */
	public function write_probe() {
		$dir = untrailingslashit( ACPS_ALERTS_DIR );
		$out = 'Write probe for: ' . $dir . "\n\n";

		$out .= "Live write test (create, then delete, a throwaway file of each type):\n";
		$tag  = substr( md5( uniqid( '', true ) ), 0, 8 );

		foreach ( array( 'md', 'txt', 'js', 'css', 'php' ) as $ext ) {
			$file = $dir . '/acps-writetest-' . $tag . '.' . $ext;
			$ok   = ( false !== @file_put_contents( $file, "test\n" ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			if ( $ok ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			}

			$out .= sprintf( "  new .%-4s : %s\n", $ext, $ok ? 'OK' : 'FAILED' );
		}

		$out .= "\nPer-file writability of the installed plugin files:\n";
		$not_writable = 0;

		foreach ( ACPS_Alerts_Failsafe::required_files() as $rel ) {
			$path     = $dir . '/' . $rel;
			$writable = is_writable( $path ) || ( ! file_exists( $path ) && is_writable( dirname( $path ) ) );

			if ( ! $writable ) {
				++$not_writable;
				$out .= '  NOT writable: ' . $rel . "\n";
			}
		}

		$out .= $not_writable
			? '  ' . $not_writable . " required file(s) not writable.\n"
			: "  all required files writable.\n";

		$out .= "\nReading:\n";
		$out .= "  new .php OK, but a normal update still fails  -> the host blocks overwriting IN-USE PHP; use Stage (it applies in the early bootstrap window).\n";
		$out .= "  new .php FAILED                               -> the host blocks ALL PHP writes by the web user; update via SFTP, or system cron run as the site owner.\n";

		return $out;
	}

	/**
	 * The secret write-probe URL responder: prints the probe and exits.
	 *
	 * @return void
	 */
	public function maybe_handle_probe() {
		if ( ! isset( $_GET[ self::PROBE_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$secret = trim( (string) ACPS_Alerts_Settings::get( 'update_secret' ) );
		$given  = sanitize_text_field( wp_unslash( $_GET[ self::PROBE_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $secret || ! hash_equals( $secret, $given ) ) {
			return;
		}

		if ( ! headers_sent() ) {
			nocache_headers();
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}

		echo $this->write_probe(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text, sent as text/plain.
		exit;
	}

	/**
	 * Injects a forced update entry for this plugin into the update transient,
	 * so the upgrader reinstalls the source package even when the version is not
	 * newer. Public so the reinstall filter (and its test) can reach it.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @param array $remote    Normalized remote info (version, package, …).
	 * @return object
	 */
	public function force_reinstall_entry( $transient, array $remote ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ ACPS_ALERTS_BASENAME ] = (object) array(
			'slug'        => dirname( ACPS_ALERTS_BASENAME ),
			'plugin'      => ACPS_ALERTS_BASENAME,
			'new_version' => (string) $remote['version'],
			'package'     => (string) $remote['package'],
			'url'         => ! empty( $remote['html_url'] ) ? (string) $remote['html_url'] : '',
		);

		return $transient;
	}

	/**
	 * The secret force-update URL, for display in the hidden panel.
	 *
	 * @return string
	 */
	public function force_update_url() {
		$secret = trim( (string) ACPS_Alerts_Settings::get( 'update_secret' ) );

		if ( '' === $secret ) {
			return '';
		}

		return add_query_arg( self::QUERY_VAR, $secret, home_url( '/' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Notices, health, logging.
	 * ------------------------------------------------------------------ */

	/**
	 * Records a health data point, keeping a short rolling history.
	 *
	 * @param string $status ok | warn | error.
	 * @param string $note   Short message.
	 * @return void
	 */
	public function record_health( $status, $note ) {
		$log = get_option( self::HEALTH_OPTION, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'time'   => time(),
			'status' => in_array( $status, array( 'ok', 'warn', 'error', 'degraded' ), true ) ? $status : 'ok',
			'note'   => sanitize_text_field( $note ),
		);

		$log = array_slice( $log, -25 );

		update_option( self::HEALTH_OPTION, $log, false );
	}

	/**
	 * Logs to the PHP error log only when WP_DEBUG is on.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[ACPS Alert Popups] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
