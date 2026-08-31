<?php
/**
 * Self-hosted plugin updater (see /Self-Update-System-Spec.md, PART A).
 *
 * Lets this plugin show "Update now" on the Plugins screen — and optionally
 * install automatically in the background — without living on wordpress.org.
 * It supports two interchangeable sources, chosen by the `update_source`
 * setting:
 *
 *   'url'    A small JSON manifest at a URL the site owner controls (spec §A4).
 *   'github' The GitHub Releases API for a given owner/repo (spec §A5).
 *
 * Both normalize to the same internal shape in remote() (spec §A2) so every
 * other method — the update-transient injection, the "View details" popup,
 * the private-asset download fix, and the secret force-update URL — is
 * source-agnostic.
 *
 * Every public hook callback is wrapped in try/catch (spec §A7): a broken
 * update source must never be able to break the rest of the site. Failures
 * are logged only when WP_DEBUG is on, and are cached briefly so a broken
 * source isn't hammered on every page load.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updater.
 */
class Updater {

	/** Transient the normalized remote() lookup is cached under. */
	const CACHE_KEY = 'acps_st_update_remote';

	/** How long a successful lookup is cached. */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** How long a failed lookup is cached, so a broken source isn't hammered. */
	const CACHE_TTL_FAIL = 15 * MINUTE_IN_SECONDS;

	/** Query var the secret force-update URL is matched on (spec §A6). */
	const QUERY_VAR = 'acps_st_update';

	/**
	 * Register hooks. A no-op if the updater is turned off in Settings — the
	 * master switch disables the force-update URL too, not just the checks.
	 */
	public function register() {
		if ( ! Settings::get( 'update_enabled' ) ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'maybe_resolve_private_download' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( $this, 'maybe_auto_update' ), 10, 2 );
		// Rename the extracted package folder back to our plugin slug, so an
		// update whose zip unpacks to a different folder name (typical of GitHub
		// release zips) installs over the SAME directory instead of a new one —
		// which is what otherwise leaves the plugin "disabled" after an update.
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'init', array( $this, 'maybe_handle_force_update' ) );
		// Early self-test responder used by the post-update crash check.
		add_action( 'init', array( $this, 'maybe_handle_selftest' ), 1 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_after_upgrade' ), 10, 2 );
		// After our plugin updates: crash-test the new code and (re)enable it
		// only if it loads cleanly.
		add_action( 'upgrader_process_complete', array( $this, 'verify_after_upgrade' ), 20, 2 );
		// Surface a rolled-back update to admins (shown by whatever version is
		// active once the plugin runs again).
		add_action( 'admin_notices', array( $this, 'maybe_show_update_failed_notice' ) );

		// Staged rollout: a dev install publishes its verified status here, which
		// a production install checks before it will offer/apply the update.
		add_action( 'rest_api_init', array( $this, 'register_status_route' ) );
	}

	/**
	 * REST: GET /update-status — a dev install reports the version it has
	 * verified (installed + passed the load test). Key-guarded so only the
	 * paired production site can read it.
	 */
	public function register_status_route() {
		register_rest_route(
			ACPS_ST_REST_NAMESPACE,
			'/update-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function rest_status( $req ) {
		nocache_headers();
		$key      = trim( (string) Settings::get( 'verify_status_key' ) );
		$given    = (string) $req->get_param( 'key' );
		if ( '' === $key || ! hash_equals( $key, $given ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 403 );
		}
		$verified = get_option( 'acps_st_verified' );
		return new \WP_REST_Response(
			array(
				'ok'       => true,
				'role'     => Settings::get( 'update_role' ),
				'running'  => ACPS_ST_VERSION,
				'verified' => is_array( $verified ) && ! empty( $verified['version'] ) ? $verified['version'] : '',
				'tested'   => is_array( $verified ) && ! empty( $verified['time'] ) ? (int) $verified['time'] : 0,
			),
			200
		);
	}

	/**
	 * For a production install: the version the paired dev site has verified,
	 * fetched from its /update-status endpoint and cached briefly. '' when the
	 * site isn't a production install, isn't configured, or can't be reached
	 * (in which case production deliberately holds rather than updating blind).
	 *
	 * @return string
	 */
	private function dev_verified_version() {
		if ( 'production' !== Settings::get( 'update_role' ) ) {
			return ''; // Not gated.
		}
		$url = trim( (string) Settings::get( 'verify_status_url' ) );
		$key = trim( (string) Settings::get( 'verify_status_key' ) );
		if ( '' === $url || '' === $key ) {
			return '';
		}

		$cache_key = 'acps_st_devstatus';
		$cached    = get_transient( $cache_key );
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
		set_transient( $cache_key, $verified, 10 * MINUTE_IN_SECONDS );
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
		if ( 'production' !== Settings::get( 'update_role' ) ) {
			return true;
		}
		$verified = $this->dev_verified_version();
		if ( '' === $verified ) {
			return false; // No confirmation yet — hold.
		}
		return version_compare( $verified, $version, '>=' );
	}

	/**
	 * Ensure the unpacked update folder is named after our plugin slug so it
	 * overwrites the existing plugin directory (and the plugin stays active),
	 * regardless of what the zip's top-level folder was called.
	 *
	 * @param string      $source        Path to the unpacked package.
	 * @param string      $remote_source Path to the download's parent dir.
	 * @param \WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $args          Hook args (includes 'plugin' during a plugin update).
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $args = array() ) {
		try {
			// Only touch OUR plugin's update.
			$plugin = isset( $args['plugin'] ) ? $args['plugin'] : '';
			if ( ACPS_ST_BASENAME !== $plugin ) {
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
			self::log_error( 'fix_source_dir: ' . $e->getMessage() );
		}
		return $source;
	}

	/* ------------------------------------------------------------------ *
	 * WordPress core hooks (spec §A1).
	 * ------------------------------------------------------------------ */

	/**
	 * Inject our update into the `update_plugins` site transient when a newer
	 * version exists; remove it when we're already current (e.g. after a
	 * manual install that raced ahead of the cache).
	 *
	 * @param object $transient The `update_plugins` transient WordPress is about to store.
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

			if ( version_compare( $remote['version'], ACPS_ST_VERSION, '>' ) && $this->rollout_allows( $remote['version'] ) ) {
				if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
					$transient->response = array();
				}
				$transient->response[ ACPS_ST_BASENAME ] = (object) array(
					'id'           => $this->slug(),
					'slug'         => $this->slug(),
					'plugin'       => ACPS_ST_BASENAME,
					'new_version'  => $remote['version'],
					'url'          => ! empty( $remote['html_url'] ) ? $remote['html_url'] : '',
					'package'      => $remote['package'],
					'icons'        => array(),
					'banners'      => array(),
					'tested'       => '',
					'requires_php' => ! empty( $remote['requires_php'] ) ? $remote['requires_php'] : '',
				);
				if ( isset( $transient->no_update[ ACPS_ST_BASENAME ] ) ) {
					unset( $transient->no_update[ ACPS_ST_BASENAME ] );
				}
			} elseif ( isset( $transient->response[ ACPS_ST_BASENAME ] ) ) {
				unset( $transient->response[ ACPS_ST_BASENAME ] );
			}
		} catch ( \Throwable $e ) {
			self::log_error( 'inject_update: ' . $e->getMessage() );
		}
		return $transient;
	}

	/**
	 * Supply the "View details" popup data (spec §A1). Matched by ->slug, the
	 * same way wordpress.org-hosted plugins are.
	 *
	 * @param false|object|array $result The result object, or false (default).
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments.
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
				'name'          => 'Cayden Form Manager',
				'slug'          => $this->slug(),
				'version'       => $remote['version'],
				'author'        => '<a href="https://acpsmd.org/">ACPS</a>',
				'homepage'      => ! empty( $remote['html_url'] ) ? $remote['html_url'] : '',
				// Fall back to this INSTALLED copy's own requirements if the
				// remote source didn't supply any for the new version.
				'requires'      => ! empty( $remote['requires_wp'] ) ? $remote['requires_wp'] : '6.2',
				'requires_php'  => ! empty( $remote['requires_php'] ) ? $remote['requires_php'] : '7.4',
				'download_link' => $remote['package'],
				'sections'      => array(
					'description' => $changelog,
					'changelog'   => $changelog,
				),
			);
		} catch ( \Throwable $e ) {
			self::log_error( 'plugin_info: ' . $e->getMessage() );
			return $result;
		}
	}

	/**
	 * Private-GitHub-asset download fix (spec §A5). WordPress core normally
	 * downloads `package` itself; a private release asset instead needs an
	 * authenticated request to resolve GitHub's signed (unauthenticated)
	 * redirect, then a plain download of THAT url — GitHub rejects a
	 * forwarded Authorization header on the signed S3 link.
	 *
	 * @param false|string|\WP_Error $reply    Short-circuit value from another filter, or false.
	 * @param string                 $package  The package URL.
	 * @param \WP_Upgrader           $upgrader The upgrader instance (unused).
	 * @return false|string|\WP_Error
	 */
	public function maybe_resolve_private_download( $reply, $package, $upgrader ) {
		try {
			if ( false !== $reply ) {
				return $reply; // Something upstream already short-circuited this.
			}

			$token = trim( (string) Settings::get( 'gh_token' ) );
			if ( '' === $token || ! is_string( $package ) ) {
				return $reply;
			}
			// Only intervene for our own plugin's GitHub *API* asset URLs
			// (…/repos/OWNER/REPO/releases/assets/ID) — the shape used only
			// when a token is configured (see fetch_from_github()).
			if ( false === strpos( $package, 'api.github.com' ) || false === strpos( $package, '/releases/assets/' ) ) {
				return $reply;
			}

			$resp = wp_remote_get(
				$package,
				array(
					'timeout'     => 30,
					'redirection' => 0, // We need the redirect itself, not where it leads.
					'headers'     => array(
						'Accept'        => 'application/octet-stream',
						'Authorization' => 'Bearer ' . $token,
						'User-Agent'    => 'ACPS-Site-Toolkit-Updater',
					),
				)
			);
			if ( is_wp_error( $resp ) ) {
				self::log_error( 'private asset redirect request failed: ' . $resp->get_error_message() );
				return $reply;
			}

			$location = wp_remote_retrieve_header( $resp, 'location' );
			if ( ! $location ) {
				self::log_error( 'private asset request returned no redirect (HTTP ' . wp_remote_retrieve_response_code( $resp ) . ')' );
				return $reply;
			}

			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			// No auth header here on purpose — the signed URL carries its own.
			$tmp = download_url( $location );
			if ( is_wp_error( $tmp ) ) {
				self::log_error( 'signed asset download failed: ' . $tmp->get_error_message() );
				return $reply;
			}
			return $tmp;
		} catch ( \Throwable $e ) {
			self::log_error( 'maybe_resolve_private_download: ' . $e->getMessage() );
			return $reply;
		}
	}

	/**
	 * Whether THIS plugin should be installed automatically by WordPress'
	 * background auto-update cron, honoring the "auto" setting (spec §A1).
	 * Every other plugin's auto-update decision passes through untouched.
	 *
	 * @param bool|null $update Whether to auto-update, or null (undecided).
	 * @param object    $item   The update offer being considered.
	 * @return bool|null
	 */
	public function maybe_auto_update( $update, $item ) {
		try {
			if ( empty( $item->plugin ) || ACPS_ST_BASENAME !== $item->plugin ) {
				return $update;
			}
			// Production only auto-updates a version the dev site has verified.
			$version = ! empty( $item->new_version ) ? (string) $item->new_version : '';
			if ( '' !== $version && ! $this->rollout_allows( $version ) ) {
				return false;
			}
			return (bool) Settings::get( 'update_auto' );
		} catch ( \Throwable $e ) {
			self::log_error( 'maybe_auto_update: ' . $e->getMessage() );
			return $update;
		}
	}

	/**
	 * Flush the cached lookup after ANY plugin upgrade, so a version we just
	 * installed (by whatever means) isn't still offered a moment later from a
	 * stale cache (spec §A1).
	 *
	 * @param \WP_Upgrader $upgrader The upgrader instance (unused).
	 * @param array        $options  Upgrade options, including 'action' and 'type'.
	 */
	public function flush_after_upgrade( $upgrader, $options ) {
		try {
			if ( isset( $options['type'] ) && 'plugin' === $options['type'] ) {
				self::flush_cache();
			}
		} catch ( \Throwable $e ) {
			self::log_error( 'flush_after_upgrade: ' . $e->getMessage() );
		}
	}

	/**
	 * Whether the just-finished upgrade included our plugin.
	 *
	 * @param array $options upgrader_process_complete options.
	 * @return bool
	 */
	private function upgrade_touched_us( $options ) {
		if ( ! isset( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return false;
		}
		if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			return in_array( ACPS_ST_BASENAME, $options['plugins'], true );
		}
		if ( ! empty( $options['plugin'] ) ) {
			return ACPS_ST_BASENAME === $options['plugin'];
		}
		// Single-plugin update without an explicit list: assume it may be us.
		return true;
	}

	/**
	 * After our plugin is updated: make sure it's active, then crash-test the
	 * NEW code with a fresh loopback request. If it loads cleanly, leave it fully
	 * enabled; if it fails to load, deactivate it so a bad release can't take the
	 * site down — and record that so it can be surfaced later.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance (unused).
	 * @param array        $options  upgrader_process_complete options.
	 */
	public function verify_after_upgrade( $upgrader, $options ) {
		try {
			if ( ! $this->upgrade_touched_us( $options ) ) {
				return;
			}
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			// Ensure it's marked active so the loopback loads the new code.
			// Silent activation just sets the option — it does NOT re-include the
			// plugin in this request (which would fatal on "cannot redeclare").
			if ( ! is_plugin_active( ACPS_ST_BASENAME ) ) {
				activate_plugin( ACPS_ST_BASENAME, '', false, true );
			}

			$result = $this->self_test_result();

			if ( 'crash' === $result ) {
				// Definitive failure (a 5xx from the fresh load) — pull it back so
				// a bad release can't take the site down.
				deactivate_plugins( ACPS_ST_BASENAME, true );
				update_option(
					'acps_st_update_failed',
					array( 'when' => current_time( 'mysql' ), 'version' => ACPS_ST_VERSION ),
					false
				);
				self::log_error( 'verify_after_upgrade: new version returned a fatal (5xx) on load; deactivated to protect the site.' );
				return;
			}

			// 'ok' or 'unknown': leave the plugin ENABLED. We only ever disable on
			// a definite crash, so a blocked/slow loopback can't knock out a
			// perfectly healthy update.
			if ( 'ok' === $result ) {
				delete_option( 'acps_st_update_failed' );
				// Publish that this exact version passed here — a production site
				// pointed at this (dev) install reads this before it will update.
				update_option(
					'acps_st_verified',
					array( 'version' => ACPS_ST_VERSION, 'time' => time() ),
					false
				);
			} else {
				self::log_error( 'verify_after_upgrade: load self-test inconclusive (loopback blocked or uncached); left the plugin enabled.' );
			}
		} catch ( \Throwable $e ) {
			self::log_error( 'verify_after_upgrade: ' . $e->getMessage() );
		}
	}

	/**
	 * Crash test: hit the site with a fresh loopback request (which loads the
	 * newly-installed code from scratch) and confirm the plugin booted far enough
	 * to answer with its marker. A fatal during load never reaches the marker, so
	 * its absence — or a 5xx — means "crashed".
	 *
	 * @return string 'ok' | 'crash' | 'unknown'
	 */
	private function self_test_result() {
		$trigger = trim( (string) Settings::get( 'update_trigger' ) );
		$url     = '' !== $trigger
			? add_query_arg( self::QUERY_VAR . '_selftest', rawurlencode( $trigger ), home_url( '/' ) )
			: home_url( '/' );

		$resp = wp_remote_get( $url, array( 'timeout' => 20, 'sslverify' => false, 'redirection' => 2 ) );

		// A network error is inconclusive (loopbacks are blocked on some hosts) —
		// never treat it as a crash.
		if ( is_wp_error( $resp ) ) {
			return 'unknown';
		}
		if ( (int) wp_remote_retrieve_response_code( $resp ) >= 500 ) {
			return 'crash';
		}
		if ( '' !== $trigger ) {
			return ( false !== strpos( (string) wp_remote_retrieve_body( $resp ), 'ACPS_OK' ) ) ? 'ok' : 'unknown';
		}
		// No secret: a non-5xx home page is a reasonable healthy signal.
		return 'ok';
	}

	/**
	 * Early responder for the crash-test loopback. Reaching this line at all
	 * proves the plugin loaded without a fatal, so it prints a marker and exits.
	 * Guarded by the same secret as the force-update URL.
	 */
	public function maybe_handle_selftest() {
		$key = self::QUERY_VAR . '_selftest';
		if ( ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$trigger = trim( (string) Settings::get( 'update_trigger' ) );
		$given   = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $trigger || ! hash_equals( $trigger, $given ) ) {
			return;
		}
		nocache_headers();
		status_header( 200 );
		echo 'ACPS_OK';
		exit;
	}

	/**
	 * Tell admins if a recent update was rolled back because it failed the load
	 * test. Shown by whichever version is active once the plugin runs again.
	 */
	public function maybe_show_update_failed_notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$failed = get_option( 'acps_st_update_failed' );
		if ( ! is_array( $failed ) ) {
			return;
		}
		echo '<div class="notice notice-error is-dismissible"><p>'
			. esc_html__( 'Cayden Form Manager: a recent update failed its load test and was rolled back / kept disabled to protect the site.', 'acps-site-toolkit' )
			. ' ' . esc_html( isset( $failed['when'] ) ? $failed['when'] : '' )
			. '</p></div>';
	}

	/* ------------------------------------------------------------------ *
	 * Secret force-update URL (spec §A6).
	 * ------------------------------------------------------------------ */

	/**
	 * On every front-end/admin request, check whether this is a hit on the
	 * secret force-update URL and, if so, run the install and exit. The
	 * secret is the only guard, so it must be non-trivial — see
	 * Settings::sanitize() / Activator for how it's generated.
	 */
	public function maybe_handle_force_update() {
		try {
			$trigger = trim( (string) Settings::get( 'update_trigger' ) );
			if ( '' === $trigger ) {
				return; // Not configured — nothing to match against.
			}

			$matched = false;

			if ( isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$given = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( hash_equals( $trigger, $given ) ) {
					$matched = true;
				}
			}

			if ( ! $matched && isset( $_SERVER['REQUEST_URI'] ) ) {
				$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$path = trim( $path, '/' );
				if ( '' !== $path && hash_equals( $trigger, $path ) ) {
					$matched = true;
				}
			}

			if ( $matched ) {
				$this->run_force_update();
			}
		} catch ( \Throwable $e ) {
			self::log_error( 'maybe_handle_force_update: ' . $e->getMessage() );
		}
	}

	/**
	 * Force a fresh check and, if newer, install it now. Prints a plain-text
	 * status page and exits — this is meant to be hit by curl/cron/a browser,
	 * not rendered as part of a normal page.
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

		echo 'Installed version: ' . ACPS_ST_VERSION . "\n";
		echo 'Latest version:    ' . $remote['version'] . "\n";

		if ( ! version_compare( $remote['version'], ACPS_ST_VERSION, '>' ) ) {
			echo "Already up to date.\n";
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Make sure WordPress' own transient agrees before we ask it to upgrade.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( ACPS_ST_BASENAME );

		$messages = $skin->get_upgrade_messages();
		if ( $messages ) {
			echo "\n" . implode( "\n", array_map( 'wp_strip_all_tags', $messages ) ) . "\n";
		}

		echo "\n" . ( ( ! is_wp_error( $result ) && $result ) ? 'SUCCESS' : 'FAILED' ) . "\n";
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * The remote lookup (spec §A2, §A4, §A5).
	 * ------------------------------------------------------------------ */

	/**
	 * Fetch (and cache) the normalized remote release info for whichever
	 * source is configured.
	 *
	 * @param bool $force Bypass the cache and fetch fresh.
	 * @return array|false {
	 *     @type string $version  Version with any leading "v" stripped.
	 *     @type string $package  Downloadable zip URL.
	 *     @type bool   $is_asset True only for a GitHub API asset URL.
	 *     @type string $html_url Optional release/homepage link.
	 *     @type string $body     Optional changelog text.
	 * } or false on failure / not configured.
	 */
	public function remote( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return $cached ? $cached : false;
			}
		}

		$source = Settings::get( 'update_source', 'url' );
		$data   = ( 'github' === $source ) ? $this->fetch_from_github() : $this->fetch_from_url();

		// Cache the failure too (briefly) so a broken source isn't hammered
		// on every admin page load. An empty array is the "cached failure"
		// marker, distinguishable from "not cached at all" (get_transient()
		// returning false).
		set_transient( self::CACHE_KEY, $data ? $data : array(), $data ? self::CACHE_TTL : self::CACHE_TTL_FAIL );

		return $data;
	}

	/**
	 * Clear the cached lookup so a changed source/setting takes effect
	 * immediately (spec §A8).
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
		delete_transient( 'acps_st_devstatus' );
	}

	/**
	 * Peek at whatever is currently cached WITHOUT triggering a network
	 * fetch — used to show "last known" status on the Settings screen
	 * without slowing down that page load.
	 *
	 * @return array{checked:bool,remote:array|false,has_update:bool}
	 */
	public static function peek_status() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false === $cached ) {
			return array(
				'checked'    => false,
				'remote'     => false,
				'has_update' => false,
			);
		}
		$remote     = $cached ? $cached : false;
		$has_update = $remote && ! empty( $remote['version'] ) && version_compare( $remote['version'], ACPS_ST_VERSION, '>' );
		return array(
			'checked'    => true,
			'remote'     => $remote,
			'has_update' => (bool) $has_update,
		);
	}

	/**
	 * The `url` source (spec §A4): a small hosted JSON manifest.
	 *
	 * @return array|false
	 */
	private function fetch_from_url() {
		try {
			$manifest = trim( (string) Settings::get( 'update_manifest' ) );
			if ( '' === $manifest ) {
				return false;
			}

			$key = trim( (string) Settings::get( 'update_manifest_key' ) );
			if ( '' !== $key ) {
				$manifest = add_query_arg( 'key', rawurlencode( $key ), $manifest );
			}

			$resp = wp_remote_get( $manifest, array( 'timeout' => 15 ) );
			if ( is_wp_error( $resp ) ) {
				self::log_error( 'manifest fetch failed: ' . $resp->get_error_message() );
				return false;
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				self::log_error( 'manifest fetch returned HTTP ' . wp_remote_retrieve_response_code( $resp ) );
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $body ) || empty( $body['version'] ) || empty( $body['download_url'] ) ) {
				self::log_error( 'manifest missing required version/download_url fields' );
				return false;
			}

			return array(
				'version'      => ltrim( (string) $body['version'], 'vV' ),
				'package'      => esc_url_raw( (string) $body['download_url'] ),
				'is_asset'     => false,
				'html_url'     => ! empty( $body['homepage'] ) ? esc_url_raw( (string) $body['homepage'] ) : '',
				'body'         => ! empty( $body['changelog'] ) ? (string) $body['changelog'] : '',
				'requires_php' => ! empty( $body['requires_php'] ) ? sanitize_text_field( (string) $body['requires_php'] ) : '',
				'requires_wp'  => ! empty( $body['requires_wp'] ) ? sanitize_text_field( (string) $body['requires_wp'] ) : '',
			);
		} catch ( \Throwable $e ) {
			self::log_error( 'fetch_from_url: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * The `github` source (spec §A5): GitHub Releases API.
	 *
	 * @return array|false
	 */
	private function fetch_from_github() {
		try {
			$owner = trim( (string) Settings::get( 'gh_owner' ) );
			$repo  = trim( (string) Settings::get( 'gh_repo' ) );
			if ( '' === $owner || '' === $repo ) {
				return false;
			}

			$token      = trim( (string) Settings::get( 'gh_token' ) );
			$asset_name = trim( (string) Settings::get( 'gh_asset' ) );
			if ( '' === $asset_name ) {
				$asset_name = 'acps-site-toolkit.zip';
			}

			$headers = array(
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'ACPS-Site-Toolkit-Updater',
			);
			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}

			$url  = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', rawurlencode( $owner ), rawurlencode( $repo ) );
			$resp = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $headers ) );

			if ( is_wp_error( $resp ) ) {
				self::log_error( 'github release fetch failed: ' . $resp->get_error_message() );
				return false;
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				self::log_error( 'github release fetch returned HTTP ' . wp_remote_retrieve_response_code( $resp ) );
				return false;
			}

			$release = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
				return false;
			}

			$package  = '';
			$is_asset = false;

			if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
				foreach ( $release['assets'] as $asset ) {
					if ( ! isset( $asset['name'] ) || $asset['name'] !== $asset_name ) {
						continue;
					}
					if ( '' !== $token && ! empty( $asset['url'] ) ) {
						// Private repo: use the API asset url; download is
						// resolved later in maybe_resolve_private_download().
						$package  = (string) $asset['url'];
						$is_asset = true;
					} elseif ( ! empty( $asset['browser_download_url'] ) ) {
						$package = (string) $asset['browser_download_url'];
					}
					break;
				}
			}

			if ( '' === $package ) {
				self::log_error( "github release found but no asset named '{$asset_name}' on it" );
				return false;
			}

			return array(
				'version'      => ltrim( (string) $release['tag_name'], 'vV' ),
				'package'      => $package,
				'is_asset'     => $is_asset,
				'html_url'     => ! empty( $release['html_url'] ) ? (string) $release['html_url'] : '',
				'body'         => ! empty( $release['body'] ) ? (string) $release['body'] : '',
				'requires_php' => '', // GitHub releases don't carry this; not read from the header either.
				'requires_wp'  => '',
			);
		} catch ( \Throwable $e ) {
			self::log_error( 'fetch_from_github: ' . $e->getMessage() );
			return false;
		}
	}

	/* ------------------------------------------------------------------ *
	 * Small helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * The plugin's directory-name slug, e.g. "acps-site-toolkit" — used to
	 * match `plugins_api` requests and as the update object's ->slug.
	 *
	 * @return string
	 */
	private function slug() {
		return dirname( ACPS_ST_BASENAME );
	}

	/**
	 * Build the secret force-update URL for display in Settings (spec §A6).
	 * Empty until a trigger secret exists.
	 *
	 * @return string
	 */
	public static function force_update_url() {
		$trigger = trim( (string) Settings::get( 'update_trigger' ) );
		if ( '' === $trigger ) {
			return '';
		}
		return add_query_arg( self::QUERY_VAR, $trigger, home_url( '/' ) );
	}

	/**
	 * Log to the PHP error log, only when WP_DEBUG is on (spec §A7) — these
	 * failures never surface to visitors.
	 *
	 * @param string $message Message to log.
	 */
	private static function log_error( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Cayden Form Manager Updater] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
