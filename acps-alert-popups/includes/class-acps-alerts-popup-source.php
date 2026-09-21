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
		delete_option( self::NODE_OPTION );
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

		static $done = array();

		if ( isset( $done[ $page_id ] ) ) {
			return;
		}

		$done[ $page_id ] = true;

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

				return;
			}
		}

		ACPS_Alerts_Failsafe::record( 'popup-source/assets', 'no Beaver Builder method to enqueue another layout' );
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

		self::enqueue_assets();

		$html = ACPS_Alerts_Failsafe::capture(
			array( __CLASS__, 'render_node' ),
			array( $page_id, $node_id ),
			'popup-source/render'
		);

		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			// Nothing came back from the direct render, so fall back to
			// rendering the whole layout and keeping only this node. Slower,
			// but it goes through the same path Beaver Builder uses for its own
			// embeds, so it works where the node-level API has moved or gone.
			$html = self::render_by_extraction( $page_id, $node_id );
		}

		return self::inline_popup( $html );
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

		$switched = false;

		// Beaver Builder reads nodes out of whichever post it thinks it is
		// rendering. In the footer of another page that is the wrong post, so
		// point it at the status page first and put it back afterwards — even
		// if the render throws, or every later builder call on this request
		// would read the wrong layout.
		if ( method_exists( 'FLBuilderModel', 'set_post_id' ) && method_exists( 'FLBuilderModel', 'reset_post_id' ) ) {
			FLBuilderModel::set_post_id( $page_id );
			$switched = true;
		}

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
			if ( $switched ) {
				FLBuilderModel::reset_post_id();
			}
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
	protected static function render_by_extraction( $page_id, $node_id ) {
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
