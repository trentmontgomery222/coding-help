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
		add_action( 'init', array( $this, 'maybe_handle_force_update' ) );
		add_action( 'upgrader_process_complete', array( $this, 'flush_after_upgrade' ), 10, 2 );
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

			if ( version_compare( $remote['version'], ACPS_ST_VERSION, '>' ) ) {
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
