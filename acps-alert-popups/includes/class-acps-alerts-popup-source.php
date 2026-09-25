<?php
/**
 * Lifts Beaver Builder's own Popup module off the status page.
 *
 * The alert is not something this plugin draws. You put Beaver Builder's Popup
 * module on the status page and build it there like any other popup, and this
 * finds that module and renders it on every other page while the alert is on.
 * The plugin supplies only what Beaver Builder has no opinion about: whether
 * the alert is on, who sees it, where, when it comes down, and how often it
 * comes back.
 *
 * Two things make that harder than it sounds, and both are handled here:
 *
 * - The node lives in another post's layout, so Beaver Builder has to be told
 *   which post it is reading before it can be asked for the node.
 * - That post's generated CSS and JS are not on the page being viewed, so they
 *   have to be enqueued or the popup arrives unstyled.
 *
 * Every Beaver Builder entry point used here is checked before it is called and
 * has a fallback behind it, because these are another plugin's internals and
 * they move between versions.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds and renders the Beaver Builder popup that is the alert.
 */
class ACPS_Alerts_Popup_Source {

	/**
	 * Where the detected node id is remembered.
	 */
	const NODE_OPTION = 'acps_alerts_popup_node';

	/**
	 * Handle for the status page's stylesheet when we load it ourselves.
	 */
	const STYLE_HANDLE = 'acps-alerts-popup-layout';

	/**
	 * The element every rule from the status page's stylesheet is confined to.
	 *
	 * The lifted popup is rendered inside the alert dialog (`.acps-alert`), and
	 * the status page's compiled CSS is scoped under this selector before it is
	 * printed, so none of its rules — least of all Beaver Builder's own
	 * `.fl-builder-content .fl-col` / `.fl-row` rules, which target a wrapper
	 * class present on every builder page — can reach the page the popup is
	 * shown on. Without this, those rules leaked onto the host page's own
	 * columns and, at mobile widths, overrode their float and centring.
	 */
	const CSS_SCOPE = '.acps-alert';

	/**
	 * Pages whose assets have already been asked for this request.
	 *
	 * A property rather than a static local so that forgetting the popup also
	 * forgets this: "the popup moved, look again" has to mean the styles too.
	 *
	 * @var array
	 */
	protected static $assets_done = array();

	/**
	 * Watches for anything that could move the popup.
	 *
	 * @return void
	 */
	public static function init() {
		ACPS_Alerts_Failsafe::action( 'save_post', array( __CLASS__, 'on_save' ), 'popup-source/save', 10, 1 );
		ACPS_Alerts_Failsafe::action( 'wp_head', array( __CLASS__, 'hide_on_source_page' ), 'popup-source/hide', 99 );

		// Beaver Builder saves a layout without going through save_post in some
		// versions, so its own completion hook is watched too. Both ending up
		// firing costs one wasted delete_option.
		ACPS_Alerts_Failsafe::action( 'fl_builder_after_save_layout', array( __CLASS__, 'forget' ), 'popup-source/bb-save' );
	}

	/**
	 * Drops the remembered node when the status page is saved.
	 *
	 * @param int $post_id Post being saved.
	 * @return void
	 */
	public static function on_save( $post_id ) {
		if ( (int) $post_id === self::page_id() ) {
			self::forget();
		}
	}

	/**
	 * Keeps the popup from opening on the page it is built on.
	 *
	 * Beaver Builder renders the Popup module into whichever page carries it,
	 * and its own script opens it there. That page is the status page, which is
	 * the one place the alert must not pop up — somebody who went there to read
	 * the status should not have it covered by a box saying the same thing. The
	 * banner says it instead.
	 *
	 * Left alone inside the builder, where it has to be visible to be edited.
	 *
	 * @return void
	 */
	public static function hide_on_source_page() {
		if ( ! is_singular() || get_queried_object_id() !== self::page_id() ) {
			return;
		}

		if ( class_exists( 'FLBuilderModel' ) && method_exists( 'FLBuilderModel', 'is_builder_active' ) && FLBuilderModel::is_builder_active() ) {
			return;
		}

		$node_id = self::node_id();

		if ( '' === $node_id ) {
			return;
		}

		$node_id = preg_replace( '/[^A-Za-z0-9_\-]/', '', $node_id );

		if ( '' === $node_id ) {
			return;
		}

		printf(
			'<style id="acps-alerts-hide-source">.fl-node-%1$s{display:none !important;}</style>' . "\n",
			esc_attr( $node_id )
		);
	}

	/**
	 * Module slugs that count as "a popup".
	 *
	 * Beaver Builder has changed this name across versions and builds, and a
	 * site may have a third-party popup module instead, so the list is broad
	 * and filterable rather than a single guess.
	 *
	 * @return string[]
	 */
	public static function module_types() {
		$types = array(
			'popup',
			'fl-popup',
			'fl_popup',
			'unified-popup',
			'popup-module',
		);

		/**
		 * Filters which Beaver Builder modules count as the alert popup.
		 *
		 * @param string[] $types Module slugs.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'acps_alerts_popup_module_types', $types ) ) ) );
	}

	/**
	 * The page the popup is built on.
	 *
	 * @return int
	 */
	public static function page_id() {
		$page_id = class_exists( 'ACPS_Alerts_Status' ) ? ACPS_Alerts_Status::board_page() : 0;

		/**
		 * Filters the page the alert popup is taken from.
		 *
		 * @param int $page_id Post ID.
		 */
		return (int) apply_filters( 'acps_alerts_popup_page', (int) $page_id );
	}

	/**
	 * The layout Beaver Builder has stored for a page.
	 *
	 * Read straight from post meta rather than through Beaver Builder, because
	 * its own reader works on whichever post is currently being rendered, and
	 * in the footer of another page that is the wrong post.
	 *
	 * @param int $page_id Post ID.
	 * @return array Node id => node, or an empty array.
	 */
	public static function layout( $page_id ) {
		$page_id = (int) $page_id;

		if ( ! $page_id ) {
			return array();
		}

		$data = get_post_meta( $page_id, '_fl_builder_data', true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * The node id of the popup module on a page.
	 *
	 * @param int $page_id Post ID. Defaults to the status page.
	 * @return string Node id, or an empty string when there is no popup on it.
	 */
	public static function find_node( $page_id = 0 ) {
		$page_id = $page_id ? (int) $page_id : self::page_id();

		if ( ! $page_id ) {
			return '';
		}

		$types = self::module_types();

		foreach ( self::layout( $page_id ) as $node_id => $node ) {
			if ( ! is_object( $node ) && ! is_array( $node ) ) {
				continue;
			}

			$node = (object) $node;

			if ( ! isset( $node->type ) || 'module' !== $node->type ) {
				continue;
			}

			$settings = isset( $node->settings ) ? (object) $node->settings : null;
			$slug     = $settings && isset( $settings->type ) ? (string) $settings->type : '';

			if ( '' !== $slug && in_array( $slug, $types, true ) ) {
				return (string) $node_id;
			}
		}

		return '';
	}

	/**
	 * The popup node id, remembered between requests.
	 *
	 * Scanning a layout is cheap but not free, and this runs in the footer of
	 * every page on the site. The answer only changes when the status page is
	 * edited, so it is cached and re-detected when the cache misses.
	 *
	 * @return string
	 */
	public static function node_id() {
		$page_id = self::page_id();

		if ( ! $page_id ) {
			return '';
		}

		$cached = get_option( self::NODE_OPTION, array() );
		$cached = is_array( $cached ) ? $cached : array();

		// The cache is keyed by page, so moving the popup to a different page
		// cannot leave a node id from the old one behind.
		if ( isset( $cached['page'] ) && (int) $cached['page'] === $page_id && isset( $cached['node'] ) ) {
			$node = (string) $cached['node'];

			// Still trust it only while that node is really in the layout.
			if ( '' !== $node && isset( self::layout( $page_id )[ $node ] ) ) {
				return $node;
			}
		}

		$node = self::find_node( $page_id );

		update_option(
			self::NODE_OPTION,
			array(
				'page' => $page_id,
				'node' => $node,
			),
			false
		);

		return $node;
	}

	/**
	 * Forgets the remembered node, so the next request looks again.
	 *
	 * Called when the status page is saved, since that is the only thing that
	 * can move or remove the popup.
	 *
	 * @return void
	 */
	public static function forget() {
		// The rendered markup is keyed by the page's modified time, so editing
		// the popup already orphans it rather than serving it stale. Deleting
		// it here keeps a saved page from leaving one behind for a day.
		delete_transient( self::cache_key() );
		delete_option( self::NODE_OPTION );

		self::$assets_done = array();
	}

	/**
	 * Whether there is a Beaver Builder popup to show.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'FLBuilder' ) && '' !== self::node_id();
	}

	/* ------------------------------------------------------------------ *
	 * Rendering.
	 * ------------------------------------------------------------------ */

	/**
	 * Brings the status page's generated CSS and JS onto this page.
	 *
	 * Beaver Builder writes one stylesheet per post. The popup is built on the
	 * status page, so on any other page its styles are simply not there, and
	 * without this the popup arrives as unstyled markup.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		$page_id = self::page_id();

		if ( ! $page_id || ! class_exists( 'FLBuilder' ) ) {
			return;
		}

		if ( isset( self::$assets_done[ $page_id ] ) ) {
			return;
		}

		self::$assets_done[ $page_id ] = true;

		// Where Beaver Builder cached this page's compiled stylesheet.
		$info = self::asset_info( $page_id );

		/*
		 * The popup is styled entirely by ONE inline block, with every rule in
		 * it confined under .acps-alert — the dialog the lifted popup sits in.
		 * That block carries both the base layout rules (rows, columns, spacing)
		 * and this page's compiled rules, so the popup has its full look while
		 * NOTHING the plugin adds is left able to touch the page around it.
		 *
		 * This is the whole point of the change: Beaver Builder scopes many of
		 * its rules to .fl-builder-content / .fl-col / .fl-row — classes present
		 * on every builder page — so any of its stylesheets loaded globally
		 * restyle the host page's own columns, most visibly on mobile. So none
		 * of them is loaded globally; they are read, scoped and inlined instead.
		 */
		if ( ! self::inject_scoped_stylesheet( $page_id, $info ) ) {
			ACPS_Alerts_Failsafe::record( 'popup-source/assets', 'no stylesheet for the status page; the popup may render unstyled' );
		}

		/*
		 * Beaver Builder's own global layout assets — webfonts, an icon sheet, a
		 * module's secondary CSS — are OFF by default now, because the call that
		 * loads them also enqueues the compiled stylesheet unscoped and globally,
		 * which is exactly the leak. The scoped inline block above already gives
		 * the popup its layout and design; a site that also wants Beaver
		 * Builder's fonts and icon sheets on every page can opt back in, and even
		 * then the unscoped compiled stylesheet is dropped again so it cannot
		 * reach the page.
		 */
		if ( apply_filters( 'acps_alerts_load_bb_scripts', false ) ) {
			foreach ( array( 'enqueue_layout_styles_scripts_by_id', 'enqueue_layout_styles_scripts' ) as $method ) {
				if ( method_exists( 'FLBuilder', $method ) ) {
					ACPS_Alerts_Failsafe::guard(
						array( 'FLBuilder', $method ),
						array( $page_id ),
						'popup-source/assets'
					);

					break;
				}
			}

			self::dequeue_raw_layout_css( $info );
		}
	}

	/**
	 * Where Beaver Builder cached this page's compiled stylesheet.
	 *
	 * @param int $page_id Post ID.
	 * @return array The asset info array (css/css_url, css_partial/…), or empty.
	 */
	protected static function asset_info( $page_id ) {
		if ( ! class_exists( 'FLBuilderModel' ) || ! method_exists( 'FLBuilderModel', 'get_asset_info' ) ) {
			return array();
		}

		$switched = self::point_at( $page_id );

		try {
			$info = ACPS_Alerts_Failsafe::guard( array( 'FLBuilderModel', 'get_asset_info' ), array(), 'popup-source/asset-info', array() );
		} finally {
			self::point_back( $switched );
		}

		return (array) $info;
	}

	/**
	 * The readable compiled-stylesheet path and its url, out of the asset info.
	 *
	 * A partial refresh writes a different file from a full render, and only one
	 * of the two is on disk, so the path is checked rather than picked.
	 *
	 * @param array $info Asset info.
	 * @return array{0:string,1:string} Path and url, or two empty strings.
	 */
	protected static function stylesheet_path( array $info ) {
		$pairs = array(
			array( 'css_partial', 'css_partial_url' ),
			array( 'css', 'css_url' ),
		);

		foreach ( $pairs as $pair ) {
			list( $path_key, $url_key ) = $pair;

			$path = isset( $info[ $path_key ] ) ? (string) $info[ $path_key ] : '';
			$url  = isset( $info[ $url_key ] ) ? (string) $info[ $url_key ] : '';

			if ( '' !== $path && '' !== $url && is_readable( $path ) ) {
				return array( $path, $url );
			}
		}

		return array( '', '' );
	}

	/**
	 * Drops the raw, unscoped compiled stylesheet Beaver Builder enqueues.
	 *
	 * Matched by the file it points at rather than by handle, so it does not
	 * matter what Beaver Builder calls the handle in a given version. Our own
	 * scoped handle is left alone.
	 *
	 * @param array $info Asset info.
	 * @return void
	 */
	protected static function dequeue_raw_layout_css( array $info ) {
		if ( ! function_exists( 'wp_styles' ) || ! function_exists( 'wp_dequeue_style' ) ) {
			return;
		}

		$targets = array();

		foreach ( array( 'css_url', 'css_partial_url' ) as $key ) {
			if ( ! empty( $info[ $key ] ) ) {
				$targets[] = basename( (string) wp_parse_url( (string) $info[ $key ], PHP_URL_PATH ) );
			}
		}

		if ( empty( $targets ) ) {
			return;
		}

		$styles = wp_styles();

		if ( ! isset( $styles->registered ) || ! is_array( $styles->registered ) ) {
			return;
		}

		foreach ( $styles->registered as $handle => $dep ) {
			if ( self::STYLE_HANDLE === $handle || empty( $dep->src ) ) {
				continue;
			}

			$base = basename( (string) wp_parse_url( (string) $dep->src, PHP_URL_PATH ) );

			if ( in_array( $base, $targets, true ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}

	/**
	 * Builds the popup's whole stylesheet — the base layout rules plus this
	 * page's compiled rules, every one of them confined under .acps-alert — and
	 * prints it inline. Each part is scoped once per edit and cached against its
	 * file's timestamp, so the parse does not repeat on every request.
	 *
	 * @param int   $page_id Post ID.
	 * @param array $info    Asset info.
	 * @return bool Whether any stylesheet was found and injected.
	 */
	protected static function inject_scoped_stylesheet( $page_id, array $info ) {
		if ( ! function_exists( 'wp_register_style' ) || ! function_exists( 'wp_add_inline_style' ) ) {
			return false;
		}

		// Base first (structure), compiled second (this page's specifics), so a
		// node rule wins over the generic one it overrides inside the popup.
		$base = self::scoped_base_css();

		list( $path, ) = self::stylesheet_path( $info );

		$compiled = '';
		$stamp    = '' !== $base ? '1' : '0';

		if ( '' !== $path ) {
			$stamp = (string) filemtime( $path );
			$key   = 'acps_alerts_popup_css_' . (int) $page_id . '_' . $stamp;
			$css   = get_transient( $key );

			if ( false === $css ) {
				$raw = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local cache file, read once per edit.
				$css = self::scope_css( $raw, self::CSS_SCOPE );

				set_transient( $key, $css, DAY_IN_SECONDS );
			}

			$compiled = (string) $css;
		}

		$blob = trim( $base . "\n" . $compiled );

		if ( '' === $blob ) {
			return false;
		}

		// A no-src style that only carries inline CSS; no external dependency,
		// because the base rules are folded into the block itself.
		wp_register_style( self::STYLE_HANDLE, false, array(), $stamp );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, $blob );

		return true;
	}

	/**
	 * Beaver Builder's base layout stylesheet, read off disk and scoped to the
	 * alert dialog. Cached against the file's timestamp.
	 *
	 * Loaded this way rather than enqueued globally: the file carries generic
	 * `.fl-col` / `.fl-row` rules, including the ones that stack columns at
	 * mobile widths, and on its own on a page it restyles that page's columns.
	 * Scoped under .acps-alert it can only ever lay out the popup.
	 *
	 * @return string Scoped CSS, or '' when the file cannot be found.
	 */
	protected static function scoped_base_css() {
		$path = self::base_css_path();

		if ( '' === $path ) {
			return '';
		}

		$stamp = (string) filemtime( $path );
		$key   = 'acps_alerts_popup_basecss_' . md5( $path ) . '_' . $stamp;
		$css   = get_transient( $key );

		if ( false === $css ) {
			$raw = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Beaver Builder's own stylesheet, read once per version.
			$css = self::scope_css( $raw, self::CSS_SCOPE );

			set_transient( $key, $css, DAY_IN_SECONDS );
		}

		return (string) $css;
	}

	/**
	 * The path to Beaver Builder's base layout stylesheet on disk.
	 *
	 * Prefers the FL_BUILDER_DIR constant; falls back to mapping Beaver
	 * Builder's plugin URL back to a path under wp-content. Returns '' when
	 * neither yields a readable file, in which case the popup simply relies on
	 * the base stylesheet the host page loads for its own builder content.
	 *
	 * @return string
	 */
	protected static function base_css_path() {
		if ( defined( 'FL_BUILDER_DIR' ) ) {
			$path = rtrim( (string) FL_BUILDER_DIR, '/\\' ) . '/css/fl-builder-layout.css';

			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		if ( method_exists( 'FLBuilder', 'plugin_url' ) && defined( 'WP_CONTENT_URL' ) && defined( 'WP_CONTENT_DIR' ) ) {
			$url = ACPS_Alerts_Failsafe::guard( array( 'FLBuilder', 'plugin_url' ), array(), 'popup-source/base-url', '' );

			if ( is_string( $url ) && '' !== $url ) {
				$file_url = rtrim( $url, '/' ) . '/css/fl-builder-layout.css';
				$path     = str_replace( WP_CONTENT_URL, WP_CONTENT_DIR, $file_url );

				if ( $path !== $file_url && is_readable( $path ) ) {
					return $path;
				}
			}
		}

		return '';
	}

	/**
	 * Confines every rule in a stylesheet under a scope selector.
	 *
	 * Each rule's selectors are prefixed with the scope, so `.fl-col { … }`
	 * becomes `.acps-alert .fl-col { … }` and can only match inside the alert
	 * dialog. `@media` / `@supports` / `@layer` blocks are recursed into so
	 * their inner rules are scoped too; `@font-face`, `@keyframes` and the like
	 * are left untouched, because scoping their contents would break them. This
	 * is deliberately conservative: a selector it cannot sensibly scope (one
	 * rooted at `html` or `body`) is still prefixed, so it stops matching rather
	 * than leaking — no layout rule of Beaver Builder's needs those.
	 *
	 * @param string $css    Raw CSS.
	 * @param string $prefix Scope selector, e.g. ".acps-alert".
	 * @return string
	 */
	public static function scope_css( $css, $prefix ) {
		$css = (string) $css;

		if ( '' === trim( $css ) ) {
			return '';
		}

		// Strip comments first: a stray "{" or "}" inside one would throw the
		// brace matching off.
		$stripped = preg_replace( '#/\*.*?\*/#s', '', $css );
		$css      = null === $stripped ? $css : $stripped;

		return self::scope_block( $css, $prefix );
	}

	/**
	 * Scopes a run of statements — the top level, or the inside of an @media.
	 *
	 * @param string $css    CSS statements.
	 * @param string $prefix Scope selector.
	 * @return string
	 */
	protected static function scope_block( $css, $prefix ) {
		$out = '';
		$len = strlen( $css );
		$i   = 0;
		$buf = '';

		while ( $i < $len ) {
			$ch = $css[ $i ];

			if ( '@' === $ch ) {
				// Flush any stray text before the at-rule (there should be none).
				$buf = '';

				$j = $i;

				while ( $j < $len && '{' !== $css[ $j ] && ';' !== $css[ $j ] ) {
					$j++;
				}

				if ( $j >= $len ) {
					break; // Malformed tail; drop it rather than emit broken CSS.
				}

				$prelude = trim( substr( $css, $i, $j - $i ) );

				if ( ';' === $css[ $j ] ) {
					// A statement at-rule: @import, @charset, @namespace.
					$out .= $prelude . ';';
					$i    = $j + 1;

					continue;
				}

				$close = self::matching_brace( $css, $j );
				$inner = substr( $css, $j + 1, $close - $j - 1 );

				if ( preg_match( '/^@(-[a-z]+-)?(media|supports|document|layer)\b/i', $prelude ) ) {
					// A grouping at-rule: scope the rules inside it.
					$out .= $prelude . '{' . self::scope_block( $inner, $prefix ) . '}';
				} else {
					// @font-face, @keyframes, @page, @font-feature-values …:
					// their contents are not selectors, so leave them be.
					$out .= $prelude . '{' . $inner . '}';
				}

				$i = $close + 1;

				continue;
			}

			if ( '{' === $ch ) {
				$close = self::matching_brace( $css, $i );
				$decls = trim( substr( $css, $i + 1, $close - $i - 1 ) );
				$sel   = trim( $buf );

				if ( '' !== $sel ) {
					$out .= self::scope_selector_list( $sel, $prefix ) . '{' . $decls . '}';
				}

				$i   = $close + 1;
				$buf = '';

				continue;
			}

			$buf .= $ch;
			$i++;
		}

		return $out;
	}

	/**
	 * The index of the "}" that closes the "{" at $open, honouring nesting and
	 * string literals.
	 *
	 * @param string $css  CSS.
	 * @param int    $open Index of the opening brace.
	 * @return int Index of the matching close brace, or the last character.
	 */
	protected static function matching_brace( $css, $open ) {
		$len   = strlen( $css );
		$depth = 0;

		for ( $i = $open; $i < $len; $i++ ) {
			$c = $css[ $i ];

			if ( '"' === $c || "'" === $c ) {
				$q = $c;
				$i++;

				while ( $i < $len && $css[ $i ] !== $q ) {
					if ( '\\' === $css[ $i ] ) {
						$i++;
					}

					$i++;
				}

				continue;
			}

			if ( '{' === $c ) {
				$depth++;
			} elseif ( '}' === $c ) {
				$depth--;

				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return $len - 1;
	}

	/**
	 * Prefixes each selector in a comma-separated list with the scope.
	 *
	 * @param string $selectors Selector list.
	 * @param string $prefix    Scope selector.
	 * @return string
	 */
	protected static function scope_selector_list( $selectors, $prefix ) {
		$scoped = array();

		foreach ( self::split_selectors( $selectors ) as $selector ) {
			$selector = trim( $selector );

			if ( '' === $selector ) {
				continue;
			}

			/*
			 * A rule rooted at the document — :root, html, body — is where a
			 * layout keeps its CSS custom properties. Prefixed as a descendant
			 * (`.acps-alert :root`) it would never match, and the popup would
			 * lose those variables. Put the scope IN PLACE of that root token
			 * instead, so the properties land on the dialog and inherit inward.
			 */
			$rooted = preg_replace( '/^\s*(?::root|html|body)\b/i', $prefix, $selector, 1, $count );

			$scoped[] = $count ? $rooted : $prefix . ' ' . $selector;
		}

		return implode( ',', $scoped );
	}

	/**
	 * Splits a selector list on its top-level commas — the ones that separate
	 * selectors, not the commas inside :not(), :is() or an [attr] value.
	 *
	 * @param string $selectors Selector list.
	 * @return string[]
	 */
	protected static function split_selectors( $selectors ) {
		$parts = array();
		$buf   = '';
		$depth = 0;
		$len   = strlen( $selectors );

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $selectors[ $i ];

			if ( '(' === $c || '[' === $c ) {
				$depth++;
			} elseif ( ')' === $c || ']' === $c ) {
				$depth = max( 0, $depth - 1 );
			}

			if ( ',' === $c && 0 === $depth ) {
				$parts[] = $buf;
				$buf     = '';

				continue;
			}

			$buf .= $c;
		}

		if ( '' !== trim( $buf ) ) {
			$parts[] = $buf;
		}

		return $parts;
	}

	/**
	 * The popup's markup, rendered out of the status page's layout.
	 *
	 * @return string Markup, or an empty string when it could not be rendered.
	 */
	public static function render() {
		$node_id = self::node_id();
		$page_id = self::page_id();

		if ( '' === $node_id || ! $page_id || ! class_exists( 'FLBuilder' ) ) {
			return '';
		}

		$cached = self::cached();

		if ( null !== $cached ) {
			self::enqueue_assets();

			return $cached;
		}

		self::enqueue_assets();

		/*
		 * Render the whole layout and keep this node, rather than asking
		 * Beaver Builder for the node on its own.
		 *
		 * The popup is a CONTAINER. What is inside it — the heading, the text,
		 * the button — are separate nodes in the layout that name the popup as
		 * their parent, not part of the popup module itself. Rendering just the
		 * module gives back the popup's shell with nothing in it, which reaches
		 * the page as an alert containing only a close button.
		 *
		 * Rendering the layout is the same path Beaver Builder uses for its own
		 * embeds, so the popup comes back built: children, styles and all.
		 */
		$html = '';

		/*
		 * Three ways of getting at it, in the order of how complete the result
		 * is. Each is tried only while the one before it came back with nothing
		 * to read, so a working site pays for the first alone.
		 */
		$strategies = array(
			// The whole layout, with everything but this node dropped. The
			// popup comes back exactly as the status page builds it.
			'popup-source/extract'  => array( array( __CLASS__, 'render_by_extraction' ), array( $page_id, $node_id ), false ),

			// The popup's children on their own. Beaver Builder renders popups
			// outside the layout flow in some versions, which leaves nothing
			// for the step above to find. The alert supplies its own frame, so
			// the popup's shell is no loss.
			'popup-source/children' => array( array( __CLASS__, 'render_children' ), array( $page_id, $node_id ), true ),

			// The popup module by itself. Last because it renders the shell
			// without its children, but a popup built with no children at all
			// still has its own content to show.
			'popup-source/module'   => array( array( __CLASS__, 'render_node' ), array( $page_id, $node_id ), true ),
		);

		foreach ( $strategies as $context => $strategy ) {
			list( $callable, $args, $captures ) = $strategy;

			$result = $captures
				? ACPS_Alerts_Failsafe::capture( $callable, $args, $context )
				: ACPS_Alerts_Failsafe::guard( $callable, $args, $context, '' );

			if ( self::has_content( $result ) ) {
				$html = (string) $result;

				break;
			}
		}

		// An empty shell is worse than nothing: the caller can still fall back
		// to the alert's own heading and text, and a visitor gets a popup with
		// words in it rather than one holding a lone close button.
		if ( ! self::has_content( $html ) ) {
			ACPS_Alerts_Failsafe::record( 'popup-source/render', 'the popup rendered with no content in it' );

			return '';
		}

		// A shortcode typed into the popup — [schoolstatus] for the badge, say —
		// can survive Beaver Builder's render as literal text when it sits in a
		// module that does not expand shortcodes (a heading, for one). Run the
		// processor over the finished popup so it executes wherever it landed.
		// It is harmless on markup that has none left.
		if ( function_exists( 'do_shortcode' ) ) {
			$expanded = ACPS_Alerts_Failsafe::guard( 'do_shortcode', array( $html ), 'popup-source/shortcodes', null );

			if ( is_string( $expanded ) && '' !== trim( $expanded ) ) {
				$html = $expanded;
			}
		}

		$html = self::wrap( self::adopt_close_button( self::inline_popup( $html ) ), $page_id );

		self::cache( $html );

		return $html;
	}

	/**
	 * Whether rendered markup actually has something in it.
	 *
	 * A popup shell with no children is markup, and passes an "is it empty"
	 * check on the string, but it is an alert with nothing to read. Text or an
	 * image both count; tags on their own do not.
	 *
	 * @param string $html Rendered markup.
	 * @return bool
	 */
	public static function has_content( $html ) {
		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			return false;
		}

		if ( '' !== trim( wp_strip_all_tags( $html ) ) ) {
			return true;
		}

		// No text, but a picture or a video is still an alert worth showing.
		return (bool) preg_match( '/<(img|picture|svg|video|iframe)[\s>]/i', $html );
	}

	/* ------------------------------------------------------------------ *
	 * Caching the rendered popup.
	 * ------------------------------------------------------------------ */

	/**
	 * The key the rendered popup is stored under.
	 *
	 * Includes when the status page was last modified, so editing the popup
	 * produces a different key and the old markup is simply never read again.
	 *
	 * @return string
	 */
	protected static function cache_key() {
		$page_id = self::page_id();

		return 'acps_alerts_popup_html_' . md5(
			$page_id . '|' . self::node_id() . '|' . (string) get_post_modified_time( 'U', true, $page_id )
			. '|' . self::status_stamp() . '|' . ACPS_ALERTS_VERSION
		);
	}

	/**
	 * What the status is, as a string the cache key can be built from.
	 *
	 * The popup can contain a [schoolstatus] shortcode, and a shortcode inside
	 * cached markup is frozen at whatever it said when the markup was stored.
	 * Folding the status into the key means the moment the alert changes, the
	 * old markup is simply never read again.
	 *
	 * @return string
	 */
	protected static function status_stamp() {
		if ( ! class_exists( 'ACPS_Alerts_Status' ) ) {
			return '';
		}

		$alert = ACPS_Alerts_Status::board_entry();

		if ( ! $alert ) {
			return 'normal';
		}

		// The revision moves on every edit to the alert, so the wording is
		// covered as well as the level.
		return 'live:' . $alert->get( 'status_level' ) . ':' . $alert->revision();
	}

	/**
	 * The rendered popup from an earlier request, if there is one.
	 *
	 * Rendering a whole page layout in the footer of every page on the site is
	 * the most expensive thing this plugin does, and the answer only changes
	 * when the status page is edited.
	 *
	 * @return string|null Markup, or null when nothing is stored.
	 */
	protected static function cached() {
		$stored = get_transient( self::cache_key() );

		return is_string( $stored ) && '' !== $stored ? $stored : null;
	}

	/**
	 * Remembers the rendered popup.
	 *
	 * @param string $html Markup to store.
	 * @return void
	 */
	protected static function cache( $html ) {
		if ( '' === trim( (string) $html ) ) {
			return;
		}

		set_transient( self::cache_key(), (string) $html, DAY_IN_SECONDS );
	}

	/**
	 * Wires the popup's own close button up to the alert.
	 *
	 * The popup was built with a close button, positioned and styled against
	 * the popup's own corner — which is where a close button belongs and where
	 * it sits on its own page. Ours is positioned against the alert's dialog
	 * instead, and that dialog spans the page so the popup's percentage width
	 * has something to be a percentage of; our button therefore lands in the
	 * corner of the window rather than the corner of the popup.
	 *
	 * So the popup's button is used. It cannot do its own job any more — it
	 * calls hidePopover() on something that is no longer a popover — but adding
	 * the attribute the alert's script listens for makes it close the alert,
	 * which is the same thing from the visitor's side.
	 *
	 * @param string $html The popup's markup.
	 * @return string
	 */
	public static function adopt_close_button( $html ) {
		$html = (string) $html;

		if ( '' === trim( $html ) || false === strpos( $html, 'fl-popup-close' ) ) {
			return $html;
		}

		$pattern = '/<(?:button|a|span|div)\b[^>]*\bclass\s*=\s*(?:"[^"]*\bfl-popup-close\b[^"]*"|\'[^\']*\bfl-popup-close\b[^\']*\')[^>]*>/i';

		$result = preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$tag = $matches[0];

				// Already wired, from a cached render.
				if ( false !== stripos( $tag, 'data-acps-close' ) ) {
					return $tag;
				}

				// Insert before the closing bracket, keeping a self-closing
				// slash where there is one.
				$end = preg_match( '#/>$#', $tag ) ? ' data-acps-close />' : ' data-acps-close>';

				return preg_replace( '#\s*/?>$#', $end, $tag );
			},
			$html
		);

		return null === $result ? $html : $result;
	}

	/**
	 * Whether markup carries a close button the alert can use.
	 *
	 * @param string $html Rendered markup.
	 * @return bool
	 */
	public static function has_close_button( $html ) {
		return false !== strpos( (string) $html, 'data-acps-close' );
	}

	/**
	 * Puts the popup back inside the container its styling expects.
	 *
	 * Beaver Builder writes most of a layout's CSS against an ancestor:
	 *
	 *     .fl-builder-content .fl-node-xxx.fl-button-group .fl-button { ... }
	 *     .fl-builder-content-123 .fl-node-yyy.fl-popup { ... }
	 *
	 * Lifting a node out of its page leaves that ancestor behind, and every one
	 * of those rules stops matching — silently, because the stylesheet is
	 * loaded and the node classes are all still correct. What reaches the page
	 * is a popup with the rules that happen not to need an ancestor (the icon's
	 * colour, the heading's font) and none of the rules that do (the popup's own
	 * background, border, radius and width; every button's fill).
	 *
	 * Both class names are needed: the bare one, and the one carrying the post
	 * id, which is how Beaver Builder scopes a layout to its own page.
	 *
	 * @param string $html    The popup's markup.
	 * @param int    $page_id Post the layout belongs to.
	 * @return string
	 */
	public static function wrap( $html, $page_id ) {
		$html    = (string) $html;
		$page_id = (int) $page_id;

		if ( '' === trim( $html ) || ! $page_id ) {
			return $html;
		}

		// The whole-layout render already brings the wrapper with it; wrapping
		// again would nest one inside the other for no gain.
		if ( false !== strpos( $html, 'fl-builder-content-' . $page_id ) ) {
			return $html;
		}

		return sprintf(
			'<div class="fl-builder-content fl-builder-content-%1$d" data-post-id="%1$d">%2$s</div>',
			$page_id,
			$html
		);
	}

	/**
	 * Turns a popover back into ordinary markup.
	 *
	 * Beaver Builder's popup is a real HTML popover: the element carries
	 * `popover="manual"`, and the browser keeps any such element display:none
	 * until showPopover() is called, then promotes it to the top layer.
	 *
	 * Both of those are wrong here. The popup is not opening itself on this
	 * page — it has been lifted into the alert dialog and IS that dialog's
	 * body — so while the attribute is still on it the element sits in the DOM
	 * greyed out and nothing appears. Even if it were opened, the top layer
	 * would take it straight back out of the dialog it is supposed to be
	 * inside.
	 *
	 * Removing the attribute is what makes it render inline. The stylesheet
	 * undoes the rest of the closed-popup styling.
	 *
	 * @param string $html Rendered popup markup.
	 * @return string
	 */
	public static function inline_popup( $html ) {
		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			return '';
		}

		// Only ever matches inside a tag: [^>]*? cannot cross a closing angle
		// bracket, so the word "popover" in someone's alert text is left alone.
		$stripped = preg_replace(
			'/(<[a-zA-Z][^>]*?)\s+popover(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/i',
			'$1',
			$html
		);

		// preg_replace returns null if it ever fails; the original markup is
		// better than nothing at all.
		return null === $stripped ? $html : $stripped;
	}

	/**
	 * Renders whatever sits inside the popup, without the popup's own shell.
	 *
	 * The popup is a container: its heading, text and buttons are separate
	 * nodes naming it as their parent. When the layout render cannot be used,
	 * these are what the alert actually needs — the dialog around them is the
	 * plugin's own, so the popup's shell is not missed.
	 *
	 * Public because the failsafe captures its output from outside the class.
	 *
	 * @param int    $page_id Post the layout belongs to.
	 * @param string $node_id The popup node.
	 * @return void
	 */
	public static function render_children( $page_id, $node_id ) {
		if ( ! class_exists( 'FLBuilderModel' ) || ! method_exists( 'FLBuilderModel', 'get_nodes' ) ) {
			return;
		}

		$switched = self::point_at( $page_id );

		try {
			$children = FLBuilderModel::get_nodes( null, $node_id );

			foreach ( (array) $children as $child ) {
				$child = (object) $child;
				$type  = isset( $child->type ) ? (string) $child->type : '';

				// Each kind of node has its own renderer, and which of them a
				// given version exposes varies, so each is checked before use.
				$renderer = array(
					'row'    => 'render_row',
					'column' => 'render_column',
					'module' => 'render_module',
				);

				if ( isset( $renderer[ $type ] ) && method_exists( 'FLBuilder', $renderer[ $type ] ) ) {
					call_user_func( array( 'FLBuilder', $renderer[ $type ] ), $child );
				}
			}
		} finally {
			self::point_back( $switched );
		}
	}

	/**
	 * Points Beaver Builder at another post's layout.
	 *
	 * It reads nodes out of whichever post it thinks it is rendering, and in
	 * the footer of another page that is the wrong post.
	 *
	 * @param int $page_id Post to read from.
	 * @return bool Whether it was switched, for point_back().
	 */
	protected static function point_at( $page_id ) {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return false;
		}

		if ( ! method_exists( 'FLBuilderModel', 'set_post_id' ) || ! method_exists( 'FLBuilderModel', 'reset_post_id' ) ) {
			return false;
		}

		FLBuilderModel::set_post_id( (int) $page_id );

		return true;
	}

	/**
	 * Puts Beaver Builder back on the post it was reading.
	 *
	 * Always called from a finally: leaving it pointed elsewhere would have
	 * every later builder call on the request reading the wrong layout.
	 *
	 * @param bool $switched Whether point_at() switched it.
	 * @return void
	 */
	protected static function point_back( $switched ) {
		if ( $switched ) {
			FLBuilderModel::reset_post_id();
		}
	}

	/**
	 * Asks Beaver Builder to render one node of another post's layout.
	 *
	 * Public because the failsafe captures its output from outside the class.
	 *
	 * @param int    $page_id Post the layout belongs to.
	 * @param string $node_id Node to render.
	 * @return void
	 */
	public static function render_node( $page_id, $node_id ) {
		if ( ! class_exists( 'FLBuilderModel' ) || ! method_exists( 'FLBuilderModel', 'get_node' ) ) {
			return;
		}

		$switched = self::point_at( $page_id );

		try {
			$node = FLBuilderModel::get_node( $node_id );

			if ( ! $node || ! isset( $node->settings ) ) {
				return;
			}

			$type = isset( $node->settings->type ) ? (string) $node->settings->type : '';

			if ( '' === $type || ! method_exists( 'FLBuilder', 'render_module_html' ) ) {
				return;
			}

			// Echoes rather than returns in some versions, which is why this
			// whole method is captured rather than having its value taken.
			$html = FLBuilder::render_module_html( $type, $node->settings, $node );

			if ( is_string( $html ) && '' !== $html ) {
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Beaver Builder's own rendered module.
			}
		} finally {
			self::point_back( $switched );
		}
	}

	/**
	 * Renders the whole status page layout and keeps only the popup node.
	 *
	 * The fallback. Beaver Builder wraps every node in an element carrying
	 * `fl-node-<id>`, so the one we want can be lifted back out by that class.
	 *
	 * @param int    $page_id Post the layout belongs to.
	 * @param string $node_id Node to keep.
	 * @return string
	 */
	public static function render_by_extraction( $page_id, $node_id ) {
		if ( ! shortcode_exists( 'fl_builder_insert_layout' ) ) {
			return '';
		}

		$full = ACPS_Alerts_Failsafe::guard(
			'do_shortcode',
			array( '[fl_builder_insert_layout id="' . (int) $page_id . '"]' ),
			'popup-source/extract-render',
			''
		);

		$full = (string) $full;

		if ( '' === trim( $full ) ) {
			return '';
		}

		return self::extract_node( $full, $node_id );
	}

	/**
	 * Pulls one `fl-node-<id>` element out of a rendered layout.
	 *
	 * Public so it can be exercised directly; it is pure string work.
	 *
	 * @param string $html    Rendered layout.
	 * @param string $node_id Node to keep.
	 * @return string The node's markup, or an empty string.
	 */
	public static function extract_node( $html, $node_id ) {
		$node_id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $node_id );

		if ( '' === $node_id || ! class_exists( 'DOMDocument' ) ) {
			return '';
		}

		if ( false === strpos( $html, 'fl-node-' . $node_id ) ) {
			return '';
		}

		$doc = new DOMDocument();

		// A page-builder layout is a fragment, not a document, and is full of
		// HTML5 the old parser complains about. Errors are collected rather
		// than raised so a stray tag cannot fill the log or break the page.
		$previous = libxml_use_internal_errors( true );

		$loaded = $doc->loadHTML(
			'<?xml encoding="utf-8" ?><div id="acps-wrap">' . $html . '</div>',
			defined( 'LIBXML_HTML_NOIMPLIED' ) && defined( 'LIBXML_HTML_NODEFDTD' )
				? LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
				: 0
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return '';
		}

		$xpath = new DOMXPath( $doc );
		$found = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' fl-node-{$node_id} ')]" );

		if ( ! $found || ! $found->length ) {
			return '';
		}

		return (string) $doc->saveHTML( $found->item( 0 ) );
	}

	/* ------------------------------------------------------------------ *
	 * Reading the popup's wording, for the status page banner.
	 * ------------------------------------------------------------------ */

	/**
	 * The first heading and the first block of text inside the popup.
	 *
	 * The status page shows a banner rather than the popup itself, and that
	 * banner needs words. When the Current Alert module has been given its own
	 * heading and text those win; this is what makes a popup built entirely in
	 * Beaver Builder still produce a readable banner.
	 *
	 * @return array { heading, text } Either may be an empty string.
	 */
	public static function wording() {
		$page_id = self::page_id();
		$node_id = self::node_id();
		$out     = array(
			'heading' => '',
			'text'    => '',
		);

		if ( ! $page_id || '' === $node_id ) {
			return $out;
		}

		$layout = self::layout( $page_id );

		foreach ( $layout as $node ) {
			$node = (object) $node;

			if ( ! isset( $node->type ) || 'module' !== $node->type || ! isset( $node->settings ) ) {
				continue;
			}

			// Only look inside the popup, not at the rest of the status page.
			if ( ! self::descends_from( $layout, $node, $node_id ) ) {
				continue;
			}

			$settings = (object) $node->settings;
			$slug     = isset( $settings->type ) ? (string) $settings->type : '';

			if ( '' === $out['heading'] && in_array( $slug, array( 'heading', 'fl-heading' ), true ) ) {
				$out['heading'] = isset( $settings->heading ) ? wp_strip_all_tags( (string) $settings->heading ) : '';
			}

			if ( '' === $out['text'] && in_array( $slug, array( 'rich-text', 'fl-rich-text' ), true ) ) {
				$out['text'] = isset( $settings->text ) ? wp_strip_all_tags( (string) $settings->text ) : '';
			}

			if ( '' !== $out['heading'] && '' !== $out['text'] ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Writes a heading and body into the popup's own modules.
	 *
	 * The exact inverse of wording(): where that reads the first heading module
	 * and the first rich-text module inside the popup, this writes them. That
	 * is what lets a quick admin form change what the popup says without opening
	 * Beaver Builder — the rest of the popup, and any extra modules built into
	 * it, are left exactly as they are.
	 *
	 * The layout is edited straight in post meta, the same place wording() reads
	 * it from, because Beaver Builder's own writers act on whichever post is
	 * being rendered and this runs from an admin form on another screen.
	 *
	 * @param string $heading Plain-text heading. Empty leaves the heading alone.
	 * @param string $text    Body HTML. Empty leaves the body alone.
	 * @param int    $page_id Page to write to. Defaults to the status page.
	 * @return array { heading: bool, text: bool } which pieces were written.
	 */
	public static function write_wording( $heading, $text, $page_id = 0 ) {
		$page_id = $page_id ? (int) $page_id : self::page_id();
		$result  = array( 'heading' => false, 'text' => false );

		if ( ! $page_id ) {
			return $result;
		}

		$node_id = self::find_node( $page_id );

		if ( '' === $node_id ) {
			return $result;
		}

		/*
		 * The published layout, and the builder's draft of it when one exists.
		 *
		 * Writing only the published data would show on the live site but be
		 * silently reverted the next time somebody opened the popup in the
		 * builder and clicked Save, because the builder republishes its draft.
		 * Keeping the two in step means the change sticks.
		 */
		foreach ( array( '_fl_builder_data', '_fl_builder_draft' ) as $meta_key ) {
			$layout = get_post_meta( $page_id, $meta_key, true );

			if ( ! is_array( $layout ) || empty( $layout ) ) {
				continue;
			}

			$done = self::set_wording_in( $layout, $node_id, $heading, $text );

			if ( $done['heading'] || $done['text'] ) {
				update_post_meta( $page_id, $meta_key, $layout );
			}

			// The published layout is the one that decides what actually shows,
			// so that is the answer handed back.
			if ( '_fl_builder_data' === $meta_key ) {
				$result = $done;
			}
		}

		// Our own cached render is keyed on the alert's revision, which the
		// caller bumps, but the node id cache and any stale transient are
		// dropped here so nothing survives the edit.
		self::forget();

		return $result;
	}

	/**
	 * Sets the heading and body on the popup's modules within one layout.
	 *
	 * Mutates $layout in place and reports which pieces it found somewhere to
	 * put. The first heading module and the first rich-text module inside the
	 * popup are the targets, matching what wording() reads back.
	 *
	 * @param array  $layout  Layout map, edited in place.
	 * @param string $node_id The popup node everything must sit inside.
	 * @param string $heading Heading to write, or '' to skip it.
	 * @param string $text    Body to write, or '' to skip it.
	 * @return array { heading: bool, text: bool }
	 */
	protected static function set_wording_in( array &$layout, $node_id, $heading, $text ) {
		$done      = array( 'heading' => false, 'text' => false );
		$want_head = '' !== (string) $heading;
		$want_text = '' !== (string) $text;

		foreach ( $layout as $key => $node ) {
			if ( ! is_object( $node ) && ! is_array( $node ) ) {
				continue;
			}

			$node = (object) $node;

			if ( ! isset( $node->type ) || 'module' !== $node->type || ! isset( $node->settings ) ) {
				continue;
			}

			if ( ! self::descends_from( $layout, $node, $node_id ) ) {
				continue;
			}

			$settings = (object) $node->settings;
			$slug     = isset( $settings->type ) ? (string) $settings->type : '';

			if ( ! $done['heading'] && $want_head && in_array( $slug, array( 'heading', 'fl-heading' ), true ) ) {
				$settings->heading = (string) $heading;
				$done['heading']   = true;
			} elseif ( ! $done['text'] && $want_text && in_array( $slug, array( 'rich-text', 'fl-rich-text' ), true ) ) {
				$settings->text = (string) $text;
				$done['text']   = true;
			} else {
				continue;
			}

			// Beaver Builder stores nodes as objects; keep them that way, and
			// write the possibly-normalised node back into the map.
			$node->settings = $settings;
			$layout[ $key ] = $node;

			$head_left = $want_head && ! $done['heading'];
			$text_left = $want_text && ! $done['text'];

			if ( ! $head_left && ! $text_left ) {
				break;
			}
		}

		return $done;
	}

	/**
	 * Whether a node sits anywhere inside another one.
	 *
	 * Beaver Builder stores a flat map with each node naming its parent, so
	 * "inside the popup" means walking parents up until the popup is reached.
	 *
	 * @param array  $layout    The whole layout.
	 * @param object $node      Node to test.
	 * @param string $ancestor  Node it should be inside.
	 * @return bool
	 */
	protected static function descends_from( array $layout, $node, $ancestor ) {
		$seen   = array();
		$parent = isset( $node->parent ) ? (string) $node->parent : '';

		// A layout with a cycle in it would loop forever, so every node visited
		// is remembered and a repeat ends the walk.
		while ( '' !== $parent && ! isset( $seen[ $parent ] ) ) {
			if ( $parent === $ancestor ) {
				return true;
			}

			$seen[ $parent ] = true;

			if ( ! isset( $layout[ $parent ] ) ) {
				return false;
			}

			$next   = (object) $layout[ $parent ];
			$parent = isset( $next->parent ) ? (string) $next->parent : '';
		}

		return false;
	}
}
