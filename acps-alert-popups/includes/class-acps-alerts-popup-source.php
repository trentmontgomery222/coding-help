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

		// Beaver Builder's base layout stylesheet. On a page with no builder
		// content of its own it is simply not there, and without it the popup
		// has no rows, no columns and no spacing.
		self::enqueue_base_styles();

		// The name of this has moved between versions, so try each one that has
		// existed rather than depending on a single spelling.
		$methods = array(
			'enqueue_layout_styles_scripts_by_id',
			'enqueue_layout_styles_scripts',
		);

		foreach ( $methods as $method ) {
			if ( method_exists( 'FLBuilder', $method ) ) {
				ACPS_Alerts_Failsafe::guard(
					array( 'FLBuilder', $method ),
					array( $page_id ),
					'popup-source/assets'
				);

				break;
			}
		}

		/*
		 * And load the status page's stylesheet ourselves as well, rather than
		 * trusting that the call above did anything.
		 *
		 * There is no reliable way to tell whether it did: Beaver Builder's
		 * handle for a layout has changed shape between versions, so looking
		 * for one by name answers "no" for a version that named it something
		 * else, and the method can also decline quietly for a post that is not
		 * the one being viewed. Getting this wrong means the popup arrives with
		 * its structure and none of its design — the buttons unstyled, the
		 * widths gone.
		 *
		 * Loading the cached file under our own handle always works. If Beaver
		 * Builder did already enqueue it, the same stylesheet is fetched twice,
		 * which costs one cached request and nothing else. That is the better
		 * side to be wrong on.
		 */
		if ( ! self::enqueue_cached_stylesheet( $page_id ) ) {
			ACPS_Alerts_Failsafe::record( 'popup-source/assets', 'no cached stylesheet for the status page; the popup may render unstyled' );
		}
	}

	/**
	 * Loads Beaver Builder's base layout stylesheet.
	 *
	 * @return void
	 */
	protected static function enqueue_base_styles() {
		if ( ! function_exists( 'wp_style_is' ) || ! method_exists( 'FLBuilder', 'plugin_url' ) ) {
			return;
		}

		if ( wp_style_is( 'fl-builder-layout', 'enqueued' ) || wp_style_is( 'fl-builder-layout', 'done' ) ) {
			return;
		}

		$url = ACPS_Alerts_Failsafe::guard( array( 'FLBuilder', 'plugin_url' ), array(), 'popup-source/base-url', '' );

		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}

		wp_enqueue_style(
			'fl-builder-layout',
			rtrim( $url, '/' ) . '/css/fl-builder-layout.css',
			array(),
			defined( 'FL_BUILDER_VERSION' ) ? FL_BUILDER_VERSION : null
		);
	}

	/**
	 * Loads the status page's generated stylesheet straight off disk.
	 *
	 * The fallback for when Beaver Builder's own enqueue did not fire. It
	 * writes one cached stylesheet per post and can say where it is, which is
	 * a far more stable thing to ask for than a particular method name.
	 *
	 * @param int $page_id Post ID.
	 * @return bool Whether a stylesheet was found and enqueued.
	 */
	protected static function enqueue_cached_stylesheet( $page_id ) {
		if ( ! class_exists( 'FLBuilderModel' ) || ! method_exists( 'FLBuilderModel', 'get_asset_info' ) ) {
			return false;
		}

		$switched = self::point_at( $page_id );

		try {
			$info = ACPS_Alerts_Failsafe::guard( array( 'FLBuilderModel', 'get_asset_info' ), array(), 'popup-source/asset-info', array() );
		} finally {
			self::point_back( $switched );
		}

		$info = (array) $info;

		// Partial refresh writes a different file from a full render, and only
		// one of the two is on disk, so the path is checked rather than picked.
		$pairs = array(
			array( 'css_partial', 'css_partial_url' ),
			array( 'css', 'css_url' ),
		);

		foreach ( $pairs as $pair ) {
			list( $path_key, $url_key ) = $pair;

			$path = isset( $info[ $path_key ] ) ? (string) $info[ $path_key ] : '';
			$url  = isset( $info[ $url_key ] ) ? (string) $info[ $url_key ] : '';

			if ( '' === $path || '' === $url || ! is_readable( $path ) ) {
				continue;
			}

			/*
			 * Only name the base stylesheet as a dependency when it is really
			 * registered. WordPress silently declines to print a style whose
			 * dependency it has never heard of — so on a site where Beaver
			 * Builder's base handle is named something else, declaring it
			 * unconditionally would mean this stylesheet never reaches the page
			 * at all, and the popup would arrive with no design whatsoever.
			 */
			$deps = ( function_exists( 'wp_style_is' ) && wp_style_is( 'fl-builder-layout', 'registered' ) )
				? array( 'fl-builder-layout' )
				: array();

			wp_enqueue_style(
				self::STYLE_HANDLE,
				$url,
				$deps,
				(string) filemtime( $path )
			);

			return true;
		}

		return false;
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
			$page_id . '|' . self::node_id() . '|' . (string) get_post_modified_time( 'U', true, $page_id ) . '|' . ACPS_ALERTS_VERSION
		);
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
