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
	/**
	 * The exact URL the plugin fetches to check for updates.
	 *
	 * Manifest base + this site's URL + the key you set — shown in the hidden
	 * updates panel so it is verifiable, not a black box.
	 */
	public function request_url() {
		$base = trim( (string) $this->settings()['update_manifest'] );

		if ( '' === $base ) {
			return '';
		}

		return add_query_arg(
			array(
				'plugin' => $this->slug(),
				'site'   => rawurlencode( home_url() ),
				'key'    => rawurlencode( (string) $this->settings()['update_key'] ),
			),
			$base
		);
	}

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

		// Fire when WordPress tells us this update is ours, OR — for a manifest
		// zip that carries no such hint — when the unpacked folder is plainly
		// this plugin (it contains our main file with our header). The second
		// path is what catches a zip that unpacks to
		// "wpsearch-quick-results-1.6.2/" and would otherwise install to a new
		// folder and drop the active plugin.
		$is_ours = ( ! empty( $args['plugin'] ) && $args['plugin'] === $this->basename() );

		if ( ! $is_ours ) {
			$candidate = untrailingslashit( $source ) . '/wpsearch-quick-results.php';

			if ( ! is_readable( $candidate ) ) {
				return $source; // not our zip
			}

			$header = file_get_contents( $candidate, false, null, 0, 2048 );

			if ( false === strpos( (string) $header, 'WPSearch Quick Results' ) ) {
				return $source; // a different plugin with a same-named file
			}
		}

		$desired = trailingslashit( $remote_source ) . $this->slug();

		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source; // already the right folder name
		}

		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), $desired ) ) {
			return trailingslashit( $desired );
		}

		// The move failed. Rather than let WordPress install to the wrong
		// folder and deactivate us, keep the source as-is and let the update
		// error out visibly — a failed update the plugin survives is better
		// than a "successful" one that turns it off.
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

		// Record that the plugin should be active, so the post-update check
		// can switch it back on if the update left it off. This covers updates
		// from the Plugins screen too, not just install_now().
		update_option( 'wpsqr_should_be_active', 1, false );

		// Schedule the health check for the next request, once the new code is
		// actually loaded — checking in this one would only test the old code.
		update_option( 'wpsqr_post_update_check', time(), false );
	}

	/**
	 * Check what version the source is offering, without installing.
	 *
	 * @return array { current, available, newer }
	 */
	public function check() {
		$this->flush();
		$remote = $this->remote( true );

		return array(
			'current'   => WPSQR_VERSION,
			'available' => $remote ? (string) $remote['version'] : '',
			'newer'     => $remote ? version_compare( $remote['version'], WPSQR_VERSION, '>' ) : false,
		);
	}

	/**
	 * Install the offered update now, from the front end.
	 *
	 * Reached from the remote status page. Runs the same upgrader the Plugins
	 * screen would, so the folder-rename and after-update checks below still
	 * apply, but without a wp-admin session — the caller has already cleared
	 * the endpoint's own gates.
	 *
	 * @return array { ok, updated, message }
	 */
	public function install_now() {
		if ( empty( $this->settings()['update_enabled'] ) ) {
			return array( 'ok' => false, 'updated' => false, 'message' => 'Self-update is turned off.' );
		}

		$remote = $this->remote( true );

		if ( ! $remote ) {
			return array( 'ok' => false, 'updated' => false, 'message' => 'The update source did not answer, or returned nothing usable.' );
		}

		if ( version_compare( $remote['version'], WPSQR_VERSION, '<=' ) ) {
			return array( 'ok' => true, 'updated' => false, 'message' => 'Already up to date (' . WPSQR_VERSION . ').' );
		}

		// The upgrader lives in wp-admin, which the front end does not load.
		foreach ( array( 'includes/plugin.php', 'includes/class-wp-upgrader.php', 'includes/file.php', 'includes/misc.php' ) as $file ) {
			$path = ABSPATH . 'wp-admin/' . $file;

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}

		if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			return array( 'ok' => false, 'updated' => false, 'message' => 'The WordPress upgrader is not available in this context.' );
		}

		// Put our own entry into the update transient so the upgrader finds
		// the package, without the network sweep wp_update_plugins() would do.
		$transient = get_site_transient( 'update_plugins' );

		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		$transient = $this->inject_update( $transient );
		set_site_transient( 'update_plugins', $transient );

		// Back up the current version first, so if the new one crashes on load
		// the bootstrap can put this one back rather than leave the plugin
		// paused. A backup that cannot be made is not fatal — the update still
		// proceeds, just without an automatic undo.
		if ( class_exists( 'WPSQR_Guard' ) ) {
			WPSQR_Guard::arm_rollback( WPSQR_VERSION );
		}

		try {
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );

			$result = $upgrader->upgrade( $this->basename() );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'updated' => false, 'message' => 'Update failed: ' . $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'updated' => false, 'message' => 'Update failed: ' . $result->get_error_message() );
		}

		if ( false === $result || null === $result ) {
			// Almost always a filesystem-permissions problem — the front-end
			// process cannot write to the plugins directory.
			return array( 'ok' => false, 'updated' => false, 'message' => 'Update could not be written — the web server may not have permission to update files. ' . implode( ' ', (array) $skin->get_upgrade_messages() ) );
		}

		// after_update() has scheduled the post-update check; it runs on the
		// next request, once the new code is loaded, and confirms the plugin
		// and this very channel survived.
		return array(
			'ok'      => true,
			'updated' => true,
			'message' => 'Updated to ' . $remote['version'] . '. It will verify itself on the next page load.',
		);
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

		// The activation helpers are admin-only includes.
		if ( ! function_exists( 'is_plugin_active' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

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

			// This request loaded the new code without a fatal — it is good.
			// Drop the rollback backup so it is not restored by mistake.
			WPSQR_Guard::disarm_rollback();
		}

		// Never leave the plugin disabled after an update. If the update left
		// it deactivated (the upgrader can, on some hosts), switch it back on.
		// This only runs because the new code loaded, i.e. the update itself
		// is sound — a crashing update is handled by rollback in the guard.
		if ( get_option( 'wpsqr_should_be_active' ) && function_exists( 'is_plugin_active' ) && ! is_plugin_active( $this->basename() ) ) {
			activate_plugin( $this->basename() );
			$problems[] = 'plugin had been deactivated by the update; re-enabled';
		}

		delete_option( 'wpsqr_should_be_active' );

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
