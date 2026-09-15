/**
 * WPCode snippet #2 — "Search: front-end filter & restyle"
 *
 * Code Type:     JavaScript Snippet
 * Location:      Site Wide Footer
 * Priority:      10
 *
 * Reads window.ACPS_SEARCH (printed by snippet #1) and rewrites the search
 * results page in the browser: drops hidden items, applies your own keyword
 * rules, relabels titles, fixes the result count, and re-runs itself when a
 * live/AJAX search swaps the markup out.
 */
( function () {
	'use strict';

	/* =================================================================
	 * CONFIGURE ME
	 *
	 * Selectors have to live here — they depend on your theme's markup.
	 * Everything else is a FALLBACK: whatever you set under
	 * Settings → Search Filters (snippet #4) is merged in on top, so you
	 * shouldn't need to touch this file again to add or change a rule.
	 * ================================================================= */
	var CONFIG = {
		// Wrapper around the whole result list. First match wins.
		// Open your search page, inspect a result, and put the real
		// selector at the front of this list.
		containerSelectors: [
			'.acps-search-results',
			'#search-results',
			'.search-results',
			'.wp-search-results',
			'main',
			'#content'
		],

		// A single result row. Again: first match wins.
		itemSelectors: [
			'.acps-result',
			'article.post',
			'.search-result',
			'.wp-search-result',
			'li.result',
			'article'
		],

		// Where the result title/link lives inside an item.
		titleSelectors: [ '.entry-title a', 'h2 a', 'h3 a', 'a' ],

		// Element showing "About 42 results". Left alone if not found.
		countSelectors: [ '.search-count', '.results-count', '.acps-count' ],

		// What to do with a filtered result:
		//   'remove' — pull it out of the DOM entirely
		//   'dim'    — leave it visible but faded + flagged (handy while testing)
		hideMode: 'remove',

		// Admins see everything, with a red "HIDDEN" badge instead of removal.
		// Set false to make admins see exactly what visitors see.
		adminSeesHidden: true,

		// Extra rules, merged with the ones from the settings page.
		// Case-insensitive substring match.
		blockTitleContains: [
			// 'draft',
			// 'internal'
		],
		blockUrlContains: [
			// '/private/',
			// '/staff-only/'
		],

		// Cosmetic relabelling: { match: 'old text', replace: 'new text' }
		titleRewrites: [
			// { match: 'ACPS - ', replace: '' }
		],

		// Shown when every result got filtered away.
		emptyMessage: 'No matching results. Try a different search term.',

		// Safety valve: if the results container never appears, un-hide the
		// page anyway after this many ms so nothing is left blank.
		revealTimeout: 2000,

		debug: false
	};
	/* ================================================================= */

	var DATA = window.ACPS_SEARCH || {
		hiddenIds: [],
		hiddenPaths: [],
		isAdmin: false,
		query: '',
		homePath: ''
	};

	/* -----------------------------------------------------------------
	 * Merge the admin-managed rules over the defaults above.
	 * Lists are combined and de-duplicated; single values overwrite.
	 * ----------------------------------------------------------------- */
	( function mergeServerRules() {
		var rules = DATA.rules || {};

		Object.keys( rules ).forEach( function ( key ) {
			var value = rules[ key ];

			if ( value === null || typeof value === 'undefined' ) {
				return;
			}

			if ( Object.prototype.toString.call( value ) === '[object Array]' ) {
				var combined = ( CONFIG[ key ] || [] ).concat( value );
				var seen = {};
				CONFIG[ key ] = combined.filter( function ( entry ) {
					var fingerprint = ( typeof entry === 'object' ) ? JSON.stringify( entry ) : String( entry );
					if ( seen[ fingerprint ] ) {
						return false;
					}
					seen[ fingerprint ] = true;
					return true;
				} );
				return;
			}

			if ( value !== '' ) {
				CONFIG[ key ] = value;
			}
		} );
	} )();

	var hiddenIds = {};
	( DATA.hiddenIds || [] ).forEach( function ( id ) {
		hiddenIds[ String( id ) ] = true;
	} );

	var hiddenPaths = {};
	( DATA.hiddenPaths || [] ).forEach( function ( p ) {
		hiddenPaths[ normalizePath( p ) ] = true;
	} );

	function log() {
		if ( CONFIG.debug && window.console ) {
			console.log.apply( console, [ '[acps-search]' ].concat( [].slice.call( arguments ) ) );
		}
	}

	function normalizePath( path ) {
		if ( ! path ) {
			return '';
		}
		path = String( path ).split( '?' )[ 0 ].split( '#' )[ 0 ];
		if ( path.length > 1 && path.charAt( path.length - 1 ) === '/' ) {
			path = path.slice( 0, -1 );
		}
		return path.toLowerCase();
	}

	function pick( selectors, root ) {
		root = root || document;
		for ( var i = 0; i < selectors.length; i++ ) {
			var found = root.querySelector( selectors[ i ] );
			if ( found ) {
				return { el: found, selector: selectors[ i ] };
			}
		}
		return null;
	}

	/* --- decide whether one result should go ------------------------- */

	function postIdOf( item ) {
		// post_class() leaves a `post-123` class behind.
		var m = /(?:^|\s)post-(\d+)(?:\s|$)/.exec( item.className || '' );
		if ( m ) {
			return m[ 1 ];
		}
		// Some plugins use data attributes instead.
		return item.getAttribute( 'data-post-id' ) || item.getAttribute( 'data-id' ) || '';
	}

	function linkOf( item ) {
		var hit = pick( CONFIG.titleSelectors, item );
		return hit ? hit.el : null;
	}

	function shouldFilter( item ) {
		if ( item.classList.contains( 'acps-hidden-result' ) ) {
			return 'flagged hidden by plugin';
		}

		var id = postIdOf( item );
		if ( id && hiddenIds[ id ] ) {
			return 'hidden post id ' + id;
		}

		var link = linkOf( item );
		var href = link ? ( link.getAttribute( 'href' ) || '' ) : '';
		var path = '';
		if ( href ) {
			try {
				path = normalizePath( new URL( href, window.location.origin ).pathname );
			} catch ( e ) {
				path = normalizePath( href );
			}
		}

		if ( path && hiddenPaths[ path ] ) {
			return 'hidden path ' + path;
		}

		var title = ( link ? link.textContent : item.textContent ) || '';
		title = title.toLowerCase();

		for ( var i = 0; i < CONFIG.blockTitleContains.length; i++ ) {
			var needle = String( CONFIG.blockTitleContains[ i ] ).toLowerCase();
			if ( needle && title.indexOf( needle ) !== -1 ) {
				return 'blocked title term "' + needle + '"';
			}
		}

		for ( var j = 0; j < CONFIG.blockUrlContains.length; j++ ) {
			var frag = String( CONFIG.blockUrlContains[ j ] ).toLowerCase();
			if ( frag && path.indexOf( frag ) !== -1 ) {
				return 'blocked url fragment "' + frag + '"';
			}
		}

		return false;
	}

	/* --- cosmetic pass ----------------------------------------------- */

	function restyle( item ) {
		if ( ! CONFIG.titleRewrites.length ) {
			return;
		}
		var link = linkOf( item );
		if ( ! link ) {
			return;
		}
		var text = link.textContent;
		CONFIG.titleRewrites.forEach( function ( rule ) {
			if ( rule && rule.match ) {
				text = text.split( rule.match ).join( rule.replace || '' );
			}
		} );
		if ( text !== link.textContent ) {
			link.textContent = text;
		}
	}

	/* --- main pass ---------------------------------------------------- */

	var running = false;

	function apply() {
		if ( running ) {
			return;
		}
		running = true;

		try {
			var container = pick( CONFIG.containerSelectors );
			if ( ! container ) {
				log( 'no results container found' );
				return;
			}

			var itemHit = pick( CONFIG.itemSelectors, container.el );
			if ( ! itemHit ) {
				log( 'no result items found inside', container.selector );
				return;
			}

			var items = container.el.querySelectorAll( itemHit.selector );
			var kept = 0;
			var removed = 0;

			Array.prototype.forEach.call( items, function ( item ) {
				if ( item.getAttribute( 'data-acps-done' ) === '1' ) {
					if ( ! item.classList.contains( 'acps-filtered-out' ) ) {
						kept++;
					}
					return;
				}
				item.setAttribute( 'data-acps-done', '1' );

				var reason = shouldFilter( item );

				if ( ! reason ) {
					restyle( item );
					kept++;
					return;
				}

				log( 'filtering:', reason, item );
				removed++;

				var adminPreview = CONFIG.adminSeesHidden && DATA.isAdmin;

				if ( adminPreview ) {
					item.classList.add( 'acps-admin-hidden' );
					item.setAttribute( 'data-acps-reason', reason );
					kept++;
				} else if ( CONFIG.hideMode === 'dim' ) {
					item.classList.add( 'acps-filtered-out', 'acps-dimmed' );
					item.setAttribute( 'data-acps-reason', reason );
				} else {
					item.classList.add( 'acps-filtered-out' );
					if ( item.parentNode ) {
						item.parentNode.removeChild( item );
					}
				}
			} );

			updateCount( container.el, kept );
			toggleEmptyMessage( container.el, kept );

			log( 'kept', kept, 'filtered', removed );
		} finally {
			reveal();
			running = false;
		}
	}

	function updateCount( container, kept ) {
		var hit = pick( CONFIG.countSelectors, container ) || pick( CONFIG.countSelectors );
		if ( ! hit ) {
			return;
		}
		// Swap the first number in the label for the real post-filter count.
		hit.el.textContent = hit.el.textContent.replace( /\d[\d,]*/, String( kept ) );
	}

	function toggleEmptyMessage( container, kept ) {
		var existing = container.querySelector( '.acps-empty-message' );

		if ( kept > 0 ) {
			if ( existing ) {
				existing.parentNode.removeChild( existing );
			}
			return;
		}

		if ( existing ) {
			return;
		}

		var p = document.createElement( 'p' );
		p.className = 'acps-empty-message';
		p.textContent = CONFIG.emptyMessage;
		container.appendChild( p );
	}

	/* --- anti-flicker -------------------------------------------------- */

	function reveal() {
		document.documentElement.classList.add( 'acps-search-ready' );
	}

	/* --- boot ----------------------------------------------------------- */

	function boot() {
		apply();

		// Live search / AJAX pagination swaps the markup — re-run on change.
		if ( window.MutationObserver ) {
			var target = ( pick( CONFIG.containerSelectors ) || {} ).el || document.body;
			var pending = null;

			new MutationObserver( function () {
				clearTimeout( pending );
				pending = setTimeout( apply, 50 );
			} ).observe( target, { childList: true, subtree: true } );
		}

		// Never leave the page hidden.
		setTimeout( reveal, CONFIG.revealTimeout );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
