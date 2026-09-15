/**
 * WPCode snippet #2 — "Search: front-end filter & restyle"
 *
 * Code Type:     JavaScript Snippet
 * Location:      Site Wide Footer
 * Priority:      10
 *
 * Everything you tune lives in the three blocks at the top:
 *
 *   SELECTORS   — where the results are in your theme's markup
 *   QUERY_RULES — rules about what the visitor typed
 *   RULES       — rules about each individual result
 *
 * Below those is the engine. You shouldn't need to touch it.
 */
( function () {
	'use strict';

	/* =================================================================
	 * 1. SELECTORS — the only part that depends on your theme.
	 *
	 * Open a search results page, right-click a result, Inspect, and put
	 * the real selector at the front of each list. First match wins.
	 * ================================================================= */
	var SELECTORS = {
		// SearchWP's Beaver Builder results module. The wrapper's id is
		// generated per render (swp-search-results-6aa940fc80207), so match
		// on the class, never the id.
		container: [ '.swp-search-results', '.acps-search-results', 'main', '#content' ],
		item:      [ '.swp-result-item', '.acps-result', 'article.post', 'article' ],
		title:     [ '.entry-title a', 'h2 a', 'h3 a', 'a' ],
		excerpt:   [ '.swp-result-item--desc', '.entry-summary', 'p' ],
		// "Found 271 results for staff" — this one sits OUTSIDE the results
		// wrapper, so it is looked up document-wide as a fallback.
		count:     [ '.swp-total-results-notice p', '.search-count', '.results-count' ]
	};

	/* =================================================================
	 * 2. QUERY_RULES — act on the search term itself, before looking at
	 *    any result. First matching rule wins; later ones are skipped.
	 *
	 *    op:   'equals' | 'contains' | 'starts' | 'ends' | 'regex' | 'in'
	 *    then: 'noResults' | 'redirect' | 'notice' | 'allow'
	 *
	 *    Matching is case-insensitive and ignores surrounding whitespace.
	 * ================================================================= */
	var QUERY_RULES = [

		// Searching this exact phrase returns nothing at all.
		// { op: 'equals', value: 'staff directory', then: 'noResults',
		//   message: 'That information isn\'t available here.' },

		// Any search containing this word returns nothing.
		// { op: 'contains', value: 'payroll', then: 'noResults' },

		// Several phrases at once.
		// { op: 'in', value: [ 'ssn', 'social security', 'w-2' ], then: 'noResults',
		//   message: 'We don\'t publish that. Please contact the office.' },

		// Pattern matching, for when plain text isn't enough.
		// { op: 'regex', value: '^\\d{3}-\\d{2}-\\d{4}$', then: 'noResults' },

		// Send a common search straight to the right page instead.
		// { op: 'equals', value: 'lunch menu', then: 'redirect', url: '/menus/' },

		// Show a banner above the results but leave them alone.
		// { op: 'contains', value: 'enrollment', then: 'notice',
		//   message: 'Looking to enroll? Start on our admissions page.' },

		// An escape hatch: let one term through untouched even if a
		// broader rule below would have caught it. Put 'allow' rules first.
		// { op: 'equals', value: 'board policy', then: 'allow' }

	];

	/* =================================================================
	 * 3. RULES — act on each result row.
	 *
	 *    when: 'title' | 'url' | 'id' | 'type' | 'excerpt' | 'text'
	 *          ('url' is the path only, lower-case: pages carry a trailing
	 *           slash — '/about/' — and file links keep their extension,
	 *           '/wp-content/uploads/budget.pdf')
	 *    op:   'equals' | 'contains' | 'starts' | 'ends' | 'regex' | 'in'
	 *    then: 'hide' | 'dim' | 'rewrite' | 'badge' | 'top' | 'keep'
	 *
	 *    'rewrite' needs `replace`. 'badge' needs `label`.
	 *    'keep' protects a result from any later rule.
	 *    Rules run top to bottom; 'hide', 'dim' and 'keep' stop the rest.
	 * ================================================================= */
	var RULES = [

		// --- protect things first ------------------------------------
		// { when: 'url', op: 'contains', value: '/important/', then: 'keep' },

		// --- hide things ---------------------------------------------
		// { when: 'url',   op: 'contains', value: '/staff-only/', then: 'hide' },
		// { when: 'title', op: 'contains', value: 'internal',     then: 'hide' },
		// { when: 'title', op: 'starts',   value: 'Draft',        then: 'hide' },
		// { when: 'id',    op: 'in',       value: [ 412, 998 ],   then: 'hide' },

		// Post type, from the result's own `type-…` class. On this site that
		// drops the PDFs and images that flood a broad search:
		// { when: 'type', op: 'equals', value: 'attachment', then: 'hide' },
		// Or just the media files, keeping the PDFs:
		// { when: 'url',  op: 'regex',  value: '\\.(jpe?g|png|gif|svg|webp)$', then: 'hide' },
		// { when: 'url',   op: 'regex',    value: '/20(1[0-9])/', then: 'hide' },

		// --- grey out instead of removing (good while testing) -------
		// { when: 'title', op: 'contains', value: 'archived', then: 'dim' },

		// --- cosmetic ------------------------------------------------
		// { when: 'title', op: 'contains', value: 'ACPS - ', then: 'rewrite', replace: '' },
		// { when: 'title', op: 'contains', value: 'Dept.',   then: 'rewrite', replace: 'Department' },
		// { when: 'url',   op: 'contains', value: '/news/',  then: 'badge', label: 'News' },

		// --- float to the top ----------------------------------------
		// { when: 'url', op: 'contains', value: '/enrollment/', then: 'top' }

	];

	/* =================================================================
	 * 4. OPTIONS
	 * ================================================================= */
	var OPTIONS = {
		// What 'hide' does: 'remove' pulls the row out of the DOM,
		// 'dim' greys it out and labels it.
		hideMode: 'remove',

		// Logged-in editors see filtered results marked "Hidden from
		// visitors" rather than losing them. Set false to see what a
		// visitor sees.
		adminSeesHidden: true,

		// Shown when every result was filtered away, and the default for
		// a 'noResults' query rule that doesn't carry its own message.
		emptyMessage: 'No matching results. Try a different search term.',

		// Rewrite the "Found 271 results" notice to the number actually
		// shown. Turn this OFF if your results are paginated: the notice
		// counts the whole result set, but the page only holds one page of
		// it, so the corrected number would be wrong in the other direction.
		updateCount: true,

		// Un-hide the page after this long even if something went wrong,
		// so a bad selector can never leave the page blank.
		revealTimeout: 2000,

		// Log every decision to the browser console. Turn this on while
		// you're writing rules.
		debug: false
	};

	/* =================================================================
	 * ENGINE — no need to edit below this line.
	 * ================================================================= */

	var DATA = window.ACPS_SEARCH || {};
	var hiddenIds = {};
	var hiddenPaths = {};

	( DATA.hiddenIds || [] ).forEach( function ( id ) {
		hiddenIds[ String( id ) ] = true;
	} );

	( DATA.hiddenPaths || [] ).forEach( function ( path ) {
		hiddenPaths[ normalizePath( path ) ] = true;
	} );

	// Anything managed from Settings → Search Filters (snippet #4) is
	// translated into the same rule shape and appended to RULES, so the
	// two ways of working stay interchangeable.
	importAdminRules();

	function importAdminRules() {
		var admin = DATA.rules || {};

		( admin.blockUrlContains || [] ).forEach( function ( value ) {
			RULES.push( { when: 'url', op: 'contains', value: value, then: 'hide', source: 'settings' } );
		} );

		( admin.blockTitleContains || [] ).forEach( function ( value ) {
			RULES.push( { when: 'title', op: 'contains', value: value, then: 'hide', source: 'settings' } );
		} );

		( admin.titleRewrites || [] ).forEach( function ( rule ) {
			if ( rule && rule.match ) {
				RULES.push( {
					when: 'title', op: 'contains', value: rule.match,
					then: 'rewrite', replace: rule.replace || '', source: 'settings'
				} );
			}
		} );

		if ( admin.hideMode ) {
			OPTIONS.hideMode = admin.hideMode;
		}
		if ( typeof admin.adminSeesHidden === 'boolean' ) {
			OPTIONS.adminSeesHidden = admin.adminSeesHidden;
		}
		if ( admin.emptyMessage ) {
			OPTIONS.emptyMessage = admin.emptyMessage;
		}
	}

	/* --- helpers ------------------------------------------------------ */

	function log() {
		if ( OPTIONS.debug && window.console ) {
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

	/**
	 * Give a path a trailing slash so a rule fragment like '/enrollment/'
	 * matches a top-level page, not just a nested one.
	 *
	 * File URLs are left alone: SearchWP indexes PDFs and images, and their
	 * results link straight at the file. Appending a slash to
	 * '…/budget.pdf' would break any rule anchored on the extension.
	 */
	function withTrailingSlash( path ) {
		if ( ! path ) {
			return '';
		}

		if ( path.charAt( path.length - 1 ) === '/' ) {
			return path;
		}

		var lastSegment = path.slice( path.lastIndexOf( '/' ) + 1 );
		if ( lastSegment.indexOf( '.' ) !== -1 ) {
			return path; // looks like a file, e.g. /uploads/budget.pdf
		}

		return path + '/';
	}

	function pick( list, root ) {
		root = root || document;
		for ( var i = 0; i < list.length; i++ ) {
			var el = root.querySelector( list[ i ] );
			if ( el ) {
				return { el: el, selector: list[ i ] };
			}
		}
		return null;
	}

	/**
	 * The one comparison routine every rule goes through.
	 *
	 * @param {string} subject Already lower-cased.
	 * @param {Object} rule
	 * @return {boolean}
	 */
	function matches( subject, rule ) {
		var op = rule.op || 'contains';
		var value = rule.value;

		if ( 'in' === op ) {
			var list = ( Object.prototype.toString.call( value ) === '[object Array]' ) ? value : [ value ];
			for ( var i = 0; i < list.length; i++ ) {
				if ( matches( subject, { op: 'equals', value: list[ i ] } ) ) {
					return true;
				}
			}
			return false;
		}

		if ( 'regex' === op ) {
			try {
				return new RegExp( value, 'i' ).test( subject );
			} catch ( e ) {
				log( 'bad regex, rule skipped:', value, e );
				return false;
			}
		}

		var needle = String( value == null ? '' : value ).toLowerCase().trim();
		if ( '' === needle ) {
			return false;
		}

		switch ( op ) {
			case 'equals':
				return subject === needle;
			case 'starts':
				return subject.indexOf( needle ) === 0;
			case 'ends':
				return subject.length >= needle.length &&
					subject.lastIndexOf( needle ) === subject.length - needle.length;
			case 'contains':
			default:
				return subject.indexOf( needle ) !== -1;
		}
	}

	/* --- query rules --------------------------------------------------- */

	var queryVerdict = null; // { then, message, url, rule }

	function evaluateQuery() {
		var term = String( DATA.query || getQueryFromUrl() || '' ).toLowerCase().trim();

		if ( ! term ) {
			return null;
		}

		for ( var i = 0; i < QUERY_RULES.length; i++ ) {
			var rule = QUERY_RULES[ i ];
			if ( matches( term, rule ) ) {
				log( 'query rule matched:', rule.then, rule.value );
				return rule;
			}
		}

		return null;
	}

	function getQueryFromUrl() {
		var m = /[?&]s=([^&]*)/.exec( window.location.search );
		return m ? decodeURIComponent( m[ 1 ].replace( /\+/g, ' ' ) ) : '';
	}

	function applyQueryVerdict( container ) {
		if ( ! queryVerdict || 'allow' === queryVerdict.then ) {
			return false;
		}

		if ( 'redirect' === queryVerdict.then && queryVerdict.url ) {
			window.location.replace( queryVerdict.url );
			return true;
		}

		if ( 'notice' === queryVerdict.then ) {
			showNotice( container, queryVerdict.message || '' );
			return false;
		}

		if ( 'noResults' === queryVerdict.then ) {
			// Drop every result, then show the message.
			var itemHit = pick( SELECTORS.item, container );
			if ( itemHit ) {
				var items = container.querySelectorAll( itemHit.selector );
				Array.prototype.forEach.call( items, function ( item ) {
					if ( item.parentNode ) {
						item.parentNode.removeChild( item );
					}
				} );
			}
			updateCount( container, 0 );
			showEmpty( container, queryVerdict.message || OPTIONS.emptyMessage );
			return true;
		}

		return false;
	}

	/* --- result rules --------------------------------------------------- */

	function fieldsOf( item ) {
		var titleEl = pick( SELECTORS.title, item );
		var excerptEl = pick( SELECTORS.excerpt, item );

		var href = titleEl ? ( titleEl.el.getAttribute( 'href' ) || '' ) : '';
		var path = '';
		if ( href ) {
			try {
				path = normalizePath( new URL( href, window.location.origin ).pathname );
			} catch ( e ) {
				path = normalizePath( href );
			}
		}

		var className = item.className || '';

		var idMatch = /(?:^|\s)post-(\d+)(?:\s|$)/.exec( className );
		var id = idMatch
			? idMatch[ 1 ]
			: ( item.getAttribute( 'data-post-id' ) || item.getAttribute( 'data-id' ) || '' );

		// SearchWP/WordPress stamp the post type on each result as
		// `type-page`, `type-post`, `type-attachment` and so on.
		var typeMatch = /(?:^|\s)type-([a-z0-9_-]+)(?:\s|$)/i.exec( className );

		return {
			el:      item,
			titleEl: titleEl ? titleEl.el : null,
			id:      String( id ),
			type:    typeMatch ? typeMatch[ 1 ].toLowerCase() : '',
			title:   ( titleEl ? titleEl.el.textContent : '' ).toLowerCase().trim(),
			// Rules see the path with a trailing slash, so a fragment like
			// '/enrollment/' matches a top-level page as well as a nested
			// one. File URLs keep their real ending so '.pdf$' still works.
			url:     withTrailingSlash( path ),
			// The un-slashed form is what the hidden-path lookup is keyed on.
			urlKey:  path,
			excerpt: ( excerptEl ? excerptEl.el.textContent : '' ).toLowerCase().trim(),
			text:    ( item.textContent || '' ).toLowerCase().trim()
		};
	}

	/**
	 * Run every rule against one result.
	 *
	 * @return {Object} { hide, dim, keep, top, badges[], rewrites[], reason }
	 */
	function evaluate( fields ) {
		var verdict = { hide: false, dim: false, keep: false, top: false, badges: [], rewrites: [], reason: '' };

		// The hide-plugin's flag is rule zero.
		if ( fields.el.classList.contains( 'acps-hidden-result' ) ||
			( fields.id && hiddenIds[ fields.id ] ) ||
			( fields.urlKey && hiddenPaths[ fields.urlKey ] ) ) {
			verdict.hide = true;
			verdict.reason = 'flagged hidden';
			return verdict;
		}

		for ( var i = 0; i < RULES.length; i++ ) {
			var rule = RULES[ i ];
			var subject = fields[ rule.when || 'title' ];

			if ( typeof subject === 'undefined' || ! matches( String( subject ), rule ) ) {
				continue;
			}

			var label = ( rule.when || 'title' ) + ' ' + ( rule.op || 'contains' ) + ' "' + rule.value + '"';

			switch ( rule.then ) {
				case 'keep':
					verdict.keep = true;
					verdict.reason = 'kept by rule: ' + label;
					return verdict;

				case 'hide':
					verdict.hide = true;
					verdict.reason = label;
					return verdict;

				case 'dim':
					verdict.dim = true;
					verdict.reason = label;
					return verdict;

				case 'rewrite':
					verdict.rewrites.push( { match: rule.value, replace: rule.replace || '' } );
					break;

				case 'badge':
					if ( rule.label ) {
						verdict.badges.push( rule.label );
					}
					break;

				case 'top':
					verdict.top = true;
					break;
			}
		}

		return verdict;
	}

	/**
	 * Replace text inside an element without touching its markup.
	 *
	 * SearchWP wraps every matched term in <mark class="searchwp-highlight">,
	 * so setting textContent would strip the highlighting off every title it
	 * rewrote. Walking the text nodes leaves the markup intact.
	 *
	 * The one limit: a phrase split across a highlight boundary lives in two
	 * separate text nodes and won't match. Rewrite rules should target text
	 * that won't contain the search term — a "ACPS - " prefix, not the word
	 * someone just searched for.
	 */
	function rewriteText( root, rules ) {
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, null, false );
		var nodes = [];
		var node;

		while ( ( node = walker.nextNode() ) ) {
			nodes.push( node );
		}

		nodes.forEach( function ( textNode ) {
			var text = textNode.nodeValue;

			rules.forEach( function ( rule ) {
				text = text.split( rule.match ).join( rule.replace );
			} );

			if ( text !== textNode.nodeValue ) {
				textNode.nodeValue = text;
			}
		} );
	}

	function applyVerdict( fields, verdict ) {
		var item = fields.el;

		if ( verdict.hide || verdict.dim ) {
			var adminPreview = OPTIONS.adminSeesHidden && DATA.isAdmin;
			var mode = verdict.dim ? 'dim' : OPTIONS.hideMode;

			item.setAttribute( 'data-acps-reason', verdict.reason );

			if ( adminPreview ) {
				item.classList.add( 'acps-admin-hidden' );
				return true; // still counts as shown, for the editor
			}

			if ( 'dim' === mode ) {
				item.classList.add( 'acps-filtered-out', 'acps-dimmed' );
			} else {
				item.classList.add( 'acps-filtered-out' );
				if ( item.parentNode ) {
					item.parentNode.removeChild( item );
				}
			}

			return false;
		}

		// Cosmetic changes only apply to results that survived.
		if ( verdict.rewrites.length && fields.titleEl ) {
			rewriteText( fields.titleEl, verdict.rewrites );
		}

		verdict.badges.forEach( function ( label ) {
			if ( item.querySelector( '.acps-badge[data-label="' + label + '"]' ) ) {
				return;
			}
			var badge = document.createElement( 'span' );
			badge.className = 'acps-badge';
			badge.setAttribute( 'data-label', label );
			badge.textContent = label;
			( fields.titleEl || item ).appendChild( badge );
		} );

		if ( verdict.top ) {
			item.classList.add( 'acps-pinned' );
			if ( item.parentNode && item.parentNode.firstChild !== item ) {
				item.parentNode.insertBefore( item, item.parentNode.firstChild );
			}
		}

		return true;
	}

	/* --- page furniture -------------------------------------------------- */

	function updateCount( container, kept ) {
		if ( ! OPTIONS.updateCount ) {
			return;
		}

		// The SearchWP notice lives outside the results wrapper, so fall back
		// to a document-wide lookup when it isn't found inside.
		var hit = pick( SELECTORS.count, container ) || pick( SELECTORS.count );
		if ( hit ) {
			hit.el.textContent = hit.el.textContent.replace( /\d[\d,]*/, String( kept ) );
		}
	}

	function showEmpty( container, message ) {
		if ( container.querySelector( '.acps-empty-message' ) ) {
			return;
		}
		var p = document.createElement( 'p' );
		p.className = 'acps-empty-message';
		p.textContent = message;
		container.appendChild( p );
	}

	function clearEmpty( container ) {
		var existing = container.querySelector( '.acps-empty-message' );
		if ( existing && existing.parentNode ) {
			existing.parentNode.removeChild( existing );
		}
	}

	function showNotice( container, message ) {
		if ( ! message || container.querySelector( '.acps-notice' ) ) {
			return;
		}
		var p = document.createElement( 'p' );
		p.className = 'acps-notice';
		p.textContent = message;
		container.insertBefore( p, container.firstChild );
	}

	function reveal() {
		document.documentElement.classList.add( 'acps-search-ready' );
	}

	/* --- main pass --------------------------------------------------------- */

	var running = false;

	function apply() {
		if ( running ) {
			return;
		}
		running = true;

		try {
			var container = pick( SELECTORS.container );
			if ( ! container ) {
				log( 'no results container matched', SELECTORS.container );
				return;
			}

			if ( applyQueryVerdict( container.el ) ) {
				log( 'query rule short-circuited the page' );
				return;
			}

			var itemHit = pick( SELECTORS.item, container.el );
			if ( ! itemHit ) {
				log( 'no result items matched inside', container.selector );
				return;
			}

			var items = container.el.querySelectorAll( itemHit.selector );
			var kept = 0;

			Array.prototype.forEach.call( items, function ( item ) {
				if ( item.getAttribute( 'data-acps-done' ) === '1' ) {
					if ( ! item.classList.contains( 'acps-filtered-out' ) ) {
						kept++;
					}
					return;
				}
				item.setAttribute( 'data-acps-done', '1' );

				var fields = fieldsOf( item );
				var verdict = evaluate( fields );

				if ( verdict.reason ) {
					log( verdict.hide || verdict.dim ? 'filtering' : 'note', fields.title || fields.url, '—', verdict.reason );
				}

				if ( applyVerdict( fields, verdict ) ) {
					kept++;
				}
			} );

			updateCount( container.el, kept );

			if ( kept > 0 ) {
				clearEmpty( container.el );
			} else {
				showEmpty( container.el, OPTIONS.emptyMessage );
			}

			log( 'showing', kept, 'of', items.length );
		} finally {
			reveal();
			running = false;
		}
	}

	/* --- boot --------------------------------------------------------------- */

	function boot() {
		queryVerdict = evaluateQuery();
		apply();

		// Live search and AJAX pagination replace the markup — re-run.
		if ( window.MutationObserver ) {
			var target = ( pick( SELECTORS.container ) || {} ).el || document.body;
			var pending = null;

			new MutationObserver( function () {
				clearTimeout( pending );
				pending = setTimeout( apply, 50 );
			} ).observe( target, { childList: true, subtree: true } );
		}

		setTimeout( reveal, OPTIONS.revealTimeout );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
