<?php
/**
 * Self-updating from a manifest you control.
 *
 * The plugin is not on wordpress.org, so WordPress will never offer it an
 * update on its own. This tells core an update exists — making "Update now"
 * appear on the Plugins screen — by pointing it at a JSON manifest at a URL
 * you set.
 *
 * The manifest is fetched from: <manifest base> ? plugin=<slug> & key=<key>,
 * so the source can tell which plugin and which site is asking and refuse
 * anything else. The endpoint needs no login — it is a machine reading a file.
 *
 * The whole point of the safety work elsewhere is that a bad release cannot
 * brick the site, and this class adds the piece specific to updating: after
 * an update it makes sure the plugin still loads AND that the remote endpoint
 * still answers, so an update can never cut off the channel used to push the
 * next one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Updater {

	const CACHE_KEY = 'wpsqr_update_manifest';

	public function hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'after_update' ), 10, 2 );

		// A source-folder rename so a zip that unpacks to a differently named
		// folder still overwrites this plugin rather than installing beside it.
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
	}

	protected function settings() {
		return WPSQR_Plugin::settings();
	}

	public function slug() {
		return dirname( plugin_basename( WPSQR_FILE ) );
	}

	public function basename() {
		return plugin_basename( WPSQR_FILE );
	}

	/* ---- Fetching the manifest ---------------------------------------- */

	/**
	 * @return array|null { version, download_url, ... } or null.
	 */
	public function remote( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached ? $cached : null;
			}
		}

		$settings = $this->settings();
		$base     = trim( (string) $settings['update_manifest'] );

		if ( '' === $base ) {
			return null;
		}

		$url = add_query_arg(
			array(
				'plugin' => $this->slug(),
				'site'   => rawurlencode( home_url() ),
				'key'    => rawurlencode( (string) $settings['update_key'] ),
			),
			$base
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		// Cache a failure briefly so a broken source is not hammered on every
		// admin page load, but not for long, so a fix is picked up soon.
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, array(), 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
			set_transient( self::CACHE_KEY, array(), 15 * MINUTE_IN_SECONDS );
			return null;
		}

		set_transient( self::CACHE_KEY, $data, 6 * HOUR_IN_SECONDS );

		return $data;
	}

	public function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/* ---- Telling WordPress ---------------------------------------------- */

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( empty( $this->settings()['update_enabled'] ) ) {
			return $transient;
		}

		$remote = $this->remote();

		if ( ! $remote || version_compare( $remote['version'], WPSQR_VERSION, '<=' ) ) {
			return $transient;
		}

		$transient->response[ $this->basename() ] = (object) array(
			'slug'        => $this->slug(),
			'plugin'      => $this->basename(),
			'new_version' => $remote['version'],
			'package'     => $remote['download_url'],
			'url'         => isset( $remote['homepage'] ) ? $remote['homepage'] : home_url(),
			'tested'      => isset( $remote['tested'] ) ? $remote['tested'] : '',
		);

		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug() ) {
			return $result;
		}

		$remote = $this->remote();

		if ( ! $remote ) {
			return $result;
		}

		return (object) array(
			'name'          => 'WPSearch Quick Results',
			'slug'          => $this->slug(),
			'version'       => $remote['version'],
			'download_link' => $remote['download_url'],
			'sections'      => array(
				'changelog' => isset( $remote['changelog'] ) ? $remote['changelog'] : '',
			),
		);
	}

	/**
	 * Rename the unpacked folder to this plugin's slug.
	 *
	 * A release zip often unpacks to a folder named for the tag or repo. Left
	 * alone, WordPress would install that as a new plugin and deactivate this
	 * one. Renaming it back means the update overwrites in place and stays on.
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $args = array() ) {
		global $wp_filesystem;

		if ( empty( $args['plugin'] ) || $args['plugin'] !== $this->basename() ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug();

		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), $desired ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/* ---- After an update: prove the channel still works ----------------- */

	public function after_update( $upgrader, $data ) {
		if ( empty( $data['type'] ) || 'plugin' !== $data['type'] ) {
			return;
		}

		if ( empty( $data['plugins'] ) || ! in_array( $this->basename(), (array) $data['plugins'], true ) ) {
			return;
		}

		$this->flush();

		// Schedule the health check for the next request, once the new code is
		// actually loaded — checking in this one would only test the old code.
		update_option( 'wpsqr_post_update_check', time(), false );
	}

	/**
	 * Confirm, on the request after an update, that the update did not break
	 * either the plugin or the endpoint used to push updates.
	 *
	 * This is the piece that stops an update severing its own lifeline: if the
	 * remote key or route is gone after an update, the plugin cannot be
	 * reached to fix it, so that specifically is checked and repaired rather
	 * than trusted.
	 */
	public function run_post_update_check() {
		if ( ! get_option( 'wpsqr_post_update_check' ) ) {
			return;
		}

		delete_option( 'wpsqr_post_update_check' );

		$problems = array();

		// The core classes loaded, or the bootstrap would already be in safe
		// mode and this would not run — so the check is narrower: the remote
		// channel specifically.
		if ( class_exists( 'WPSQR_Remote' ) ) {
			$repaired = WPSQR_Remote::ensure_configured();

			if ( $repaired ) {
				$problems[] = 'remote endpoint key was missing after update; regenerated';
			}
		} else {
			$problems[] = 'remote endpoint class did not load after update';
		}

		if ( class_exists( 'WPSQR_Guard' ) ) {
			$missing = WPSQR_Guard::load_includes();

			if ( $missing ) {
				$problems[] = count( $missing ) . ' file(s) missing after update';
			}
		}

		update_option(
			'wpsqr_last_update_check',
			array(
				'time'     => time(),
				'version'  => WPSQR_VERSION,
				'problems' => $problems,
			),
			false
		);
	}
}
