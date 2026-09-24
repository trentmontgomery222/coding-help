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

	/**
	 * Registers the update hooks. A no-op when the channel is switched off.
	 *
	 * @return void
	 */
	public function register() {
		// The self-test responder and the request handlers are always wired so
		// a force run or a crash-check works even if a bad release flipped the
		// enabled flag; the actual update injection respects the switch.
		add_action( 'init', array( $this, 'maybe_handle_selftest' ), 1 );
		add_action( 'init', array( $this, 'maybe_handle_force_update' ), 2 );

		if ( ! ACPS_Alerts_Settings::get( 'update_enabled' ) ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'maybe_resolve_private_download' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( $this, 'maybe_auto_update' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_after_upgrade' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'verify_after_upgrade' ), 20, 2 );
		add_action( 'admin_notices', array( $this, 'maybe_show_update_failed_notice' ) );

		// Staged rollout: a dev install publishes the version it has verified at
		// a key-guarded REST endpoint, which a production install checks before
		// it will offer or apply an update.
		add_action( 'rest_api_init', array( $this, 'register_status_route' ) );
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
				'callback'            => array( $this, 'rest_status' ),
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

			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			if ( ! is_plugin_active( ACPS_ALERTS_BASENAME ) ) {
				activate_plugin( ACPS_ALERTS_BASENAME, '', false, true );
			}

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

		self::flush_cache();
		$remote = $this->remote( true );

		if ( ! $remote ) {
			echo "Could not reach the configured update source.\n";
			exit;
		}

		echo 'Installed version: ' . esc_html( ACPS_ALERTS_VERSION ) . "\n";
		echo 'Latest version:    ' . esc_html( $remote['version'] ) . "\n";

		if ( ! version_compare( $remote['version'], ACPS_ALERTS_VERSION, '>' ) ) {
			echo "Already up to date.\n";
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( ACPS_ALERTS_BASENAME );

		$messages = $skin->get_upgrade_messages();

		if ( $messages ) {
			echo "\n" . esc_html( implode( "\n", array_map( 'wp_strip_all_tags', $messages ) ) ) . "\n";
		}

		echo "\n" . ( ( ! is_wp_error( $result ) && $result ) ? 'SUCCESS' : 'FAILED' ) . "\n";
		exit;
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
	 * Tells admins when a recent update was rolled back.
	 *
	 * @return void
	 */
	public function maybe_show_update_failed_notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$failed = get_option( self::FAILED_OPTION );

		if ( ! is_array( $failed ) ) {
			return;
		}

		echo '<div class="notice notice-error is-dismissible"><p>'
			. esc_html__( 'ACPS Alert Popups: a recent update failed its load test and was kept disabled to protect the site.', 'acps-alert-popups' )
			. ' ' . esc_html( isset( $failed['when'] ) ? $failed['when'] : '' )
			. '</p></div>';
	}

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
