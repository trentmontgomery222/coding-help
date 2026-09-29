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

	/**
	 * The WordPress-side "an update is available" hook.
	 *
	 * By default this does nothing: the only ways to update are the ?updates=1
	 * panel and the remote endpoint, so the Plugins screen must not show a
	 * notice (the `hide_update_notice` setting, on by default). The internal
	 * installer does not go through this filter — it calls
	 * inject_update_entry() directly — so hiding the notice never blocks an
	 * actual update.
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( empty( $this->settings()['update_enabled'] ) ) {
			return $transient;
		}

		// Suppress the wp-admin update notice entirely when asked to.
		if ( ! empty( $this->settings()['hide_update_notice'] ) ) {
			return $transient;
		}

		return $this->inject_update_entry( $transient );
	}

	/**
	 * Put this plugin's available-update entry into an update transient.
	 *
	 * Used both by the (optional) wp-admin notice and, always, by the internal
	 * installer so it can update without the Plugins-screen notice being on.
	 * With $force the entry is added even when the remote version is not newer,
	 * so a same-version reinstall can re-download the current release.
	 */
	public function inject_update_entry( $transient, $force = false ) {
		$remote = $this->remote();

		if ( ! $remote ) {
			return $transient;
		}

		if ( ! $force && version_compare( $remote['version'], WPSQR_VERSION, '<=' ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
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
	public function install_now( $force = false ) {
		if ( empty( $this->settings()['update_enabled'] ) ) {
			return array( 'ok' => false, 'updated' => false, 'message' => 'Self-update is turned off.' );
		}

		$remote = $this->remote( true );

		if ( ! $remote ) {
			return array( 'ok' => false, 'updated' => false, 'message' => 'The update source did not answer, or returned nothing usable.' );
		}

		// With $force we reinstall even the same version, so a file edited
		// wrongly on the server can be replaced by a clean copy from the source.
		if ( ! $force && version_compare( $remote['version'], WPSQR_VERSION, '<=' ) ) {
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

		// Initialise the filesystem the way wp-admin does, but without an admin
		// session. From the front end there is no page on which WordPress could
		// show its FTP/SSH credentials form, so left to itself the upgrader asks
		// for credentials, gets none, and bails out with "could not be written"
		// — even when the files are perfectly writable (which a working update
		// from a logged-in browser proves). Forcing the direct method and
		// initialising WP_Filesystem() here makes the remote install write files
		// exactly as a logged-in update does. If the host genuinely needs FTP
		// creds (files not owned by the PHP user), this filter is ignored and
		// the normal failure message still stands.
		$prep = $this->prepare_filesystem();

		if ( true !== $prep ) {
			return array( 'ok' => false, 'updated' => false, 'message' => $prep );
		}

		// Put our own entry into the update transient so the upgrader finds
		// the package, without the network sweep wp_update_plugins() would do.
		$transient = get_site_transient( 'update_plugins' );

		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		// Build the entry directly (not via the notice filter, which may be
		// suppressed); with $force it is added even for the same version.
		$transient = $this->inject_update_entry( $transient, $force );
		set_site_transient( 'update_plugins', $transient );

		// A same-version reinstall needs core to believe the package differs,
		// or Plugin_Upgrader::upgrade() short-circuits. Clearing the checked
		// entry forces it to treat the response entry as installable.
		if ( $force && isset( $transient->checked[ $this->basename() ] ) ) {
			unset( $transient->checked[ $this->basename() ] );
			set_site_transient( 'update_plugins', $transient );
		}

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
			'message' => ( $force ? 'Reinstalled ' : 'Updated to ' ) . $remote['version'] . '. It will verify itself on the next page load.',
		);
	}

	/**
	 * Download, unpack, and test-write every file in the release — reporting
	 * each file's result instead of aborting on the first failure.
	 *
	 * This exists to answer, with facts rather than a guess, "is it every file
	 * or just some?" It does the real download and unpack, then for every file
	 * and folder in the package it checks whether that exact destination could
	 * be written (existing file writable, or its parent dir writable for a new
	 * one), and lists every path that could NOT be, with why (exists but not
	 * writable / parent not writable) and the owner-vs-PHP-user and permissions
	 * where the OS exposes them. It writes nothing to the live plugin, so it is
	 * safe to run repeatedly.
	 *
	 * @return array { ok, lines }
	 */
	public function probe_write() {
		$lines = array();

		foreach ( array( 'includes/plugin.php', 'includes/file.php', 'includes/misc.php' ) as $file ) {
			$path = ABSPATH . 'wp-admin/' . $file;

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}

		if ( ! function_exists( 'download_url' ) || ! function_exists( 'unzip_file' ) ) {
			return array( 'ok' => false, 'lines' => array( 'The WordPress download/unzip API is not available in this context.' ) );
		}

		$prep = $this->prepare_filesystem();

		if ( true !== $prep ) {
			return array( 'ok' => false, 'lines' => array( $prep ) );
		}

		$remote = $this->remote( true );

		if ( ! $remote ) {
			return array( 'ok' => false, 'lines' => array( 'The update source did not answer, or returned nothing usable.' ) );
		}

		$lines[] = 'source version: ' . $remote['version'];
		$lines[] = 'download URL:   ' . $remote['download_url'];

		$package = download_url( $remote['download_url'] );

		if ( is_wp_error( $package ) ) {
			$lines[] = 'DOWNLOAD FAILED: ' . $package->get_error_message();
			return array( 'ok' => false, 'lines' => $lines );
		}

		$lines[] = 'download:       OK (' . size_format( (int) ( @filesize( $package ) ) ) . ')'; // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$tmp    = trailingslashit( get_temp_dir() ) . 'wpsqr-probe-' . wp_generate_password( 8, false, false );
		$unzip  = unzip_file( $package, $tmp );

		@unlink( $package ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( is_wp_error( $unzip ) ) {
			$lines[] = 'UNPACK FAILED:  ' . $unzip->get_error_message();
			$this->rrmdir( $tmp );
			return array( 'ok' => false, 'lines' => $lines );
		}

		$lines[] = 'unpack:         OK (to a temp folder)';

		$source = $this->locate_main_dir( $tmp );

		if ( '' === $source ) {
			$lines[] = 'could not find the plugin main file inside the package.';
			$this->rrmdir( $tmp );
			return array( 'ok' => false, 'lines' => $lines );
		}

		$dest = trailingslashit( WP_PLUGIN_DIR ) . $this->slug();

		$lines[] = 'destination:    ' . $dest;
		$lines[] = '';

		$ok   = 0;
		$fail = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$rel    = ltrim( str_replace( $source, '', $item->getPathname() ), '/\\' );
			$target = trailingslashit( $dest ) . $rel;

			$writable = file_exists( $target )
				? is_writable( $target ) // phpcs:ignore WordPress.WP.AlternativeFunctions
				: is_writable( dirname( $target ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( $writable ) {
				$ok++;
			} else {
				$fail++;
				$lines[] = 'FAIL ' . ( $item->isDir() ? 'dir  ' : 'file ' ) . $rel . '  —  ' . $this->why_unwritable( $target );
			}
		}

		$this->rrmdir( $tmp );

		$lines[] = '';
		$lines[] = "RESULT: {$ok} writable, {$fail} NOT writable.";

		if ( 0 === $fail ) {
			$lines[] = 'Every file is writable — a reinstall should succeed.';
		} else {
			$lines[] = 'The paths marked FAIL are what an install cannot overwrite. Compare their owner to the php-user above.';
		}

		return array( 'ok' => 0 === $fail, 'lines' => $lines );
	}

	/** Find the folder inside $dir that holds the plugin's main file. */
	protected function locate_main_dir( $dir ) {
		if ( file_exists( trailingslashit( $dir ) . 'wpsearch-quick-results.php' ) ) {
			return untrailingslashit( $dir );
		}

		foreach ( (array) glob( trailingslashit( $dir ) . '*', GLOB_ONLYDIR ) as $sub ) {
			if ( file_exists( trailingslashit( $sub ) . 'wpsearch-quick-results.php' ) ) {
				return untrailingslashit( $sub );
			}
		}

		return '';
	}

	/** A human-readable reason a destination path can't be written, with facts. */
	protected function why_unwritable( $target ) {
		$exists = file_exists( $target );
		$check  = $exists ? $target : dirname( $target );
		$parts  = array( $exists ? 'exists but not writable' : 'parent folder not writable' );

		$owner = @fileowner( $check ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false !== $owner ) {
			$owner_name = ( function_exists( 'posix_getpwuid' ) && posix_getpwuid( $owner ) ) ? posix_getpwuid( $owner )['name'] : $owner;

			$php_uid  = function_exists( 'posix_geteuid' ) ? posix_geteuid() : ( function_exists( 'getmyuid' ) ? getmyuid() : -1 );
			$php_name = ( $php_uid >= 0 && function_exists( 'posix_getpwuid' ) && posix_getpwuid( $php_uid ) ) ? posix_getpwuid( $php_uid )['name'] : $php_uid;

			$parts[] = 'owner=' . $owner_name . ' php-user=' . $php_name;
		}

		$perms = file_exists( $check ) ? substr( sprintf( '%o', @fileperms( $check ) ), -4 ) : '----'; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$parts[] = 'perms=' . $perms;

		return implode( ', ', $parts );
	}

	/** Remove a temp tree. Uses WP_Filesystem when it is up, else plain PHP. */
	protected function rrmdir( $dir ) {
		global $wp_filesystem;

		if ( is_object( $wp_filesystem ) ) {
			$wp_filesystem->delete( $dir, true );
			return;
		}

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore
			}
		}

		@rmdir( $dir ); // phpcs:ignore
	}

	/**
	 * Ready WP_Filesystem for a front-end (no admin session) write.
	 *
	 * Prefers the direct method, which needs no credentials, so the upgrader
	 * does not try to render an FTP form to a page that cannot show one. Returns
	 * true on success, or a human-readable reason string on failure.
	 *
	 * @return true|string
	 */
	protected function prepare_filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			return 'The WordPress filesystem API is not available in this context.';
		}

		// Prefer the credential-free direct method for this request only.
		$force_direct = static function () {
			return 'direct';
		};
		add_filter( 'filesystem_method', $force_direct, 99 );

		$ready = WP_Filesystem();

		remove_filter( 'filesystem_method', $force_direct, 99 );

		if ( ! $ready || ! is_object( $wp_filesystem ) ) {
			return 'Could not initialise the filesystem for writing. This host may require SFTP/Git deploys for plugin files rather than in-WordPress updates.';
		}

		return true;
	}

	/**
	 * What the file writer can see, for the status page — so a failed update
	 * reports why rather than just "could not be written".
	 *
	 * @return array<string,string>
	 */
	public function filesystem_diagnostics() {
		$plugins_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : ABSPATH . 'wp-content/plugins' );

		$out = array(
			'plugins dir'         => is_writable( $plugins_dir ) ? 'writable' : 'NOT writable', // phpcs:ignore WordPress.WP.AlternativeFunctions
			'this plugin dir'     => is_writable( dirname( WPSQR_FILE ) ) ? 'writable' : 'NOT writable', // phpcs:ignore WordPress.WP.AlternativeFunctions
			'FS_METHOD constant'  => defined( 'FS_METHOD' ) ? (string) FS_METHOD : 'not set',
			'file mods'           => ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ? 'BLOCKED (DISALLOW_FILE_MODS)' : 'allowed',
		);

		if ( function_exists( 'get_filesystem_method' ) || is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			if ( ! function_exists( 'get_filesystem_method' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			if ( function_exists( 'get_filesystem_method' ) ) {
				$out['detected method'] = (string) get_filesystem_method();
			}
		}

		return $out;
	}

	/**
	 * Force a clean copy of the current release to be downloaded and written
	 * over the installed files, even if the version has not changed.
	 *
	 * The escape hatch for "a file got edited wrong on the server" — pull the
	 * source's copy again and overwrite.
	 */
	public function reinstall_now() {
		return $this->install_now( true );
	}

	/**
	 * Last-resort self-repair used by the remote endpoint if its own code
	 * throws while handling a request: quietly re-download and apply the latest
	 * release from the source, at most once in a while so a genuinely broken
	 * release cannot loop.
	 *
	 * Deliberately defensive and self-limiting — it is a failsafe, not a
	 * background auto-updater. Returns a short human-readable outcome string.
	 */
	public function failsafe_repair() {
		// Rate-limit to one attempt per hour, whatever happens, so a package
		// that keeps failing cannot be fetched and applied in a tight loop.
		$last = (int) get_option( 'wpsqr_failsafe_last', 0 );

		if ( $last && ( time() - $last ) < HOUR_IN_SECONDS ) {
			return 'failsafe recently attempted; skipped';
		}

		update_option( 'wpsqr_failsafe_last', time(), false );

		try {
			$result = $this->install_now( true );

			return $result['ok']
				? ( $result['updated'] ? 'failsafe reinstalled the latest release' : 'failsafe: source offered nothing to install' )
				: 'failsafe could not reinstall: ' . $result['message'];
		} catch ( \Throwable $e ) {
			return 'failsafe errored: ' . $e->getMessage();
		}
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
