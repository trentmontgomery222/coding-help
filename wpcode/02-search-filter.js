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
	 *    then: 'hide' | 'dim' | 'rewrite' | 'setDesc' | 'badge' | 'top' | 'bottom' | 'keep'
	 *
	 *    'rewrite' needs `replace`, and takes an optional `target` of 'title'
	 *    (the default), 'desc' or 'both'. 'setDesc' needs `desc` and replaces
	 *    the description outright. 'badge' needs `label`.
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

		// Rewrite the description instead of, or as well as, the title.
		// { when: 'excerpt', op: 'contains', value: '[…]', then: 'rewrite', replace: '…', target: 'desc' },

		// Replace a description outright. Useful where an indexed page's own
		// text makes a poor summary — a directory listing whose excerpt is a
		// run of names, for instance.
		// { when: 'url', op: 'contains', value: '/staff/directory/', then: 'setDesc',
		//   desc: 'Look up any ACPS employee by name, school or department.' },
		// { when: 'title', op: 'contains', value: 'Dept.',   then: 'rewrite', replace: 'Department' },
		// { when: 'url',   op: 'contains', value: '/news/',  then: 'badge', label: 'News' },

		// --- reorder --------------------------------------------------
		// { when: 'url',  op: 'contains', value: '/enrollment/', then: 'top' },

		// Sink the indexed PDFs below everything else without removing them.
		// Does the same job as the SearchWP relevance mod in snippet #0, but
		// in the browser, so it works whether or not the mod takes effect.
		// { when: 'type', op: 'equals', value: 'attachment', then: 'bottom' }

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

		// Rules built on the settings screen arrive in exactly the shape used
		// by the arrays above, so they append rather than being translated.
		( admin.result || [] ).forEach( function ( rule ) {
			if ( rule && rule.value !== undefined ) {
				rule.source = 'settings';
				RULES.push( rule );
			}
		} );

		( admin.query || [] ).forEach( function ( rule ) {
			if ( rule && rule.value !== undefined ) {
				rule.source = 'settings';
				QUERY_RULES.push( rule );
			}
		} );

		// Older payloads used three flat lists. Still understood, so a site
		// mid-upgrade doesn't lose its rules between one save and the next.
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
		if ( typeof admin.updateCount === 'boolean' ) {
			OPTIONS.updateCount = admin.updateCount;
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

	/**
	 * Find the search term.
	 *
	 * This site's form uses ?swps= (snippet #0 redirects /?s=term to
	 * /search/?swp_form[form_id]=5&swps=term), other SearchWP setups use
	 * ?swpquery=, WordPress uses ?s=, and a paged result may carry none of
	 * them. So: whatever the bridge reported, else any known parameter, else
	 * the term out of the "Found 271 results for staff" notice.
	 */
	function getQueryFromUrl() {
		var params = DATA.queryParams || [ 'swps', 'swpquery', 's' ];

		for ( var i = 0; i < params.length; i++ ) {
			var m = new RegExp( '[?&]' + params[ i ] + '=([^&]*)' ).exec( window.location.search );
			if ( m && m[ 1 ] ) {
				return decodeURIComponent( m[ 1 ].replace( /\+/g, ' ' ) );
			}
		}

		return getQueryFromNotice();
	}

	function getQueryFromNotice() {
		var hit = pick( SELECTORS.count );
		if ( ! hit ) {
			return '';
		}

		// "Found 271 results for staff"
		var m = /\bfor\s+(.+)$/i.exec( hit.el.textContent.trim() );
		return m ? m[ 1 ].trim() : '';
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

			// A blocked search shows nothing, whatever the total said.
			originalCount = 0;
			removedTotal = 1;
			updateCount( container );
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

		// A description written for search replaces the template's own before
		// anything is judged, so rules match against the text that will
		// actually be on the page rather than the text it started with.
		var custom = ( DATA.descriptions || {} )[ String( id ) ];
		if ( undefined !== custom && excerptEl && excerptEl.el.textContent !== custom ) {
			excerptEl.el.textContent = custom;
		}

		return {
			el:      item,
			titleEl: titleEl ? titleEl.el : null,
			descEl:  excerptEl ? excerptEl.el : null,
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
		var verdict = {
			hide: false, dim: false, keep: false, top: false, bottom: false,
			badges: [], rewrites: [], setDesc: null, reason: ''
		};

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

			if ( typeof subject === 'undefined' ) {
				rule._badField = true;
				continue;
			}

			if ( ! matches( String( subject ), rule ) ) {
				continue;
			}

			rule._hits = ( rule._hits || 0 ) + 1;

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
					verdict.rewrites.push( {
						match: rule.value,
						replace: rule.replace || '',
						target: rule.target || 'title'
					} );
					break;

				case 'setDesc':
					// Last one wins, so a more specific rule further down can
					// override a broad one above it.
					verdict.setDesc = rule.desc || '';
					break;

				case 'badge':
					if ( rule.label ) {
						verdict.badges.push( rule.label );
					}
					break;

				case 'top':
					verdict.top = true;
					break;

				case 'bottom':
					verdict.bottom = true;
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
				text = replaceInsensitive( text, rule.match, rule.replace );
			} );

			if ( text !== textNode.nodeValue ) {
				textNode.nodeValue = text;
			}
		} );
	}

	/**
	 * Case-insensitive find and replace.
	 *
	 * Rule matching ignores case, so replacement has to as well. A rule that
	 * matched a row on "hub" and then silently failed to replace "Hub" would
	 * look like the rule was broken.
	 */
	function replaceInsensitive( text, needle, replacement ) {
		if ( ! needle ) {
			return text;
		}

		var escaped = String( needle ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

		return text.replace( new RegExp( escaped, 'gi' ), replacement );
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

			removedTotal++;

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

		// The custom description was already applied in fieldsOf, before the
		// rules ran. A setDesc rule overrides it; a rewrite edits whatever is
		// there by then.
		if ( null !== verdict.setDesc && fields.descEl ) {
			fields.descEl.textContent = verdict.setDesc;
		}

		var titleRules = verdict.rewrites.filter( function ( rule ) {
			return 'desc' !== rule.target;
		} );

		var descRules = verdict.rewrites.filter( function ( rule ) {
			return 'desc' === rule.target || 'both' === rule.target;
		} );

		if ( titleRules.length && fields.titleEl ) {
			rewriteText( fields.titleEl, titleRules );
		}

		if ( descRules.length && fields.descEl ) {
			rewriteText( fields.descEl, descRules );
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

		// Reordering is only flagged here. Moving rows mid-iteration would
		// reverse the order of every row after the first one moved, so the
		// actual move happens once, after every row has been judged.
		if ( verdict.top ) {
			item.classList.add( 'acps-pinned' );
			item.setAttribute( 'data-acps-order', 'top' );
		} else if ( verdict.bottom ) {
			item.classList.add( 'acps-sunk' );
			item.setAttribute( 'data-acps-order', 'bottom' );
		}

		return true;
	}

	/**
	 * Apply the 'top' and 'bottom' actions in document order, once.
	 * Rows with neither keep their original relative position.
	 */
	function reorder( container, itemSelector ) {
		var rows = Array.prototype.slice.call( container.querySelectorAll( itemSelector ) );

		var tops = rows.filter( function ( row ) {
			return row.getAttribute( 'data-acps-order' ) === 'top';
		} );

		var bottoms = rows.filter( function ( row ) {
			return row.getAttribute( 'data-acps-order' ) === 'bottom';
		} );

		if ( ! tops.length && ! bottoms.length ) {
			return;
		}

		// Prepend the pinned rows in their own order, ahead of everything else.
		var firstRow = rows[ 0 ];
		tops.forEach( function ( row ) {
			if ( row !== firstRow && row.parentNode ) {
				row.parentNode.insertBefore( row, firstRow );
			}
			firstRow = row.nextSibling;
		} );

		// Append the sunk rows in their own order, after everything else.
		bottoms.forEach( function ( row ) {
			if ( row.parentNode ) {
				row.parentNode.appendChild( row );
			}
		} );
	}

	/* --- page furniture -------------------------------------------------- */

	/**
	 * Correct the "Found 271 results for staff" notice.
	 *
	 * It reports the whole result set, while this page holds only one page of
	 * it. So subtract what was filtered rather than replacing the total with
	 * the number of visible rows — on an unpaginated page those are the same
	 * number, and on a paginated one subtracting is at least honest about
	 * what changed. If nothing was filtered, the notice is left exactly as
	 * the plugin wrote it.
	 */
	function updateCount( container ) {
		if ( ! OPTIONS.updateCount || removedTotal === 0 ) {
			return;
		}

		// The SearchWP notice lives outside the results wrapper, so fall back
		// to a document-wide lookup when it isn't found inside.
		var hit = pick( SELECTORS.count, container ) || pick( SELECTORS.count );
		if ( ! hit ) {
			return;
		}

		var current = hit.el.textContent;
		var found = /\d[\d,]*/.exec( current );
		if ( ! found ) {
			return;
		}

		if ( originalCount === null ) {
			originalCount = parseInt( found[ 0 ].replace( /,/g, '' ), 10 );
		}

		var updated = current.replace( /\d[\d,]*/, String( Math.max( 0, originalCount - removedTotal ) ) );

		// Only write when it differs. If the notice happens to sit inside the
		// element the MutationObserver watches, an unconditional write would
		// retrigger the observer on every pass and spin forever.
		if ( updated !== current ) {
			hit.el.textContent = updated;
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
	var LAST = {};
	var removedTotal = 0;   // rows this snippet has filtered out
	var originalCount = null; // the number the notice showed before we touched it

	function apply() {
		if ( running ) {
			return;
		}
		running = true;

		try {
			var container = pick( SELECTORS.container );
			LAST.container = container ? container.selector : null;

			if ( ! container ) {
				log( 'no results container matched', SELECTORS.container );
				return;
			}

			if ( applyQueryVerdict( container.el ) ) {
				log( 'query rule short-circuited the page' );
				return;
			}

			var itemHit = pick( SELECTORS.item, container.el );
			LAST.item = itemHit ? itemHit.selector : null;

			if ( ! itemHit ) {
				log( 'no result items matched inside', container.selector );
				return;
			}

			var items = container.el.querySelectorAll( itemHit.selector );
			var kept = 0;

			LAST.total = items.length;

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

			reorder( container.el, itemHit.selector );
			updateCount( container.el );

			if ( kept > 0 ) {
				clearEmpty( container.el );
			} else {
				showEmpty( container.el, OPTIONS.emptyMessage );
			}

			LAST.kept = kept;
			log( 'showing', kept, 'of', items.length );
		} finally {
			reveal();
			running = false;
			if ( isDebug() ) {
				renderDiagnostics();
			}
		}
	}

	/* --- diagnostics ---------------------------------------------------------
	 * Add ?acpsdebug=1 to the results URL (or set OPTIONS.debug) to get a
	 * panel showing exactly what this snippet found and which rules fired.
	 * ----------------------------------------------------------------------- */

	function isDebug() {
		return OPTIONS.debug || /[?&]acpsdebug=1/.test( window.location.search );
	}

	function renderDiagnostics() {
		var existing = document.getElementById( 'acps-debug-panel' );
		if ( existing ) {
			existing.parentNode.removeChild( existing );
		}

		var rows = [];

		function row( label, value, ok ) {
			rows.push(
				'<tr><th style="text-align:left;padding:2px 10px 2px 0;font-weight:600;white-space:nowrap">' + label +
				'</th><td style="padding:2px 0;color:' + ( ok === false ? '#b91c1c' : ( ok === true ? '#15803d' : 'inherit' ) ) + '">' +
				value + '</td></tr>'
			);
		}

		var hasBridge = !! window.ACPS_SEARCH;
		row( 'Bridge (snippet #1)',
			hasBridge ? 'present' : 'MISSING — window.ACPS_SEARCH is undefined',
			hasBridge );

		if ( ! hasBridge ) {
			row( '', 'Snippet #1 is inactive, or it does not recognise this page as a results page. ' +
				'Nothing flagged by the hide-plugin can be filtered without it. Rules below still run.' );
		}

		row( 'Results container', LAST.container || 'NOT FOUND — check SELECTORS.container', !! LAST.container );
		row( 'Result rows', LAST.item ? ( LAST.total + ' × ' + LAST.item ) : 'NOT FOUND — check SELECTORS.item', !! LAST.item );

		var term = DATA.query || getQueryFromUrl();
		row( 'Search term', term ? '"' + term + '"' : 'NOT DETECTED — query rules cannot fire', !! term );

		row( 'Flagged hidden', ( DATA.hiddenIds || [] ).length + ' ids, ' + ( DATA.hiddenPaths || [] ).length + ' paths' );
		row( 'Viewing as', DATA.isAdmin ? ( OPTIONS.adminSeesHidden ? 'editor (hidden rows shown, marked)' : 'editor' ) : 'visitor' );

		if ( queryVerdict ) {
			row( 'Query rule fired', queryVerdict.op + ' "' + queryVerdict.value + '" → ' + queryVerdict.then, true );
		}

		row( 'Showing', ( LAST.kept === undefined ? '—' : LAST.kept + ' of ' + LAST.total ) );

		// Snippet #0's own report, printed in the footer.
		var swp = window.ACPS_SWP_DEBUG;
		if ( swp ) {
			var links = swp.links || {};
			row( 'SearchWP plugin', swp.searchwpActive ? 'active' : 'NOT DETECTED', !! swp.searchwpActive );

			var linkSummary = links.calls
				? links.calls + ' permalink calls, ' + links.attachments + ' attachments, ' +
					links.rewritten + ' rewritten to the file'
				: 'the permalink filters never ran — this template does not use them';
			row( 'Media links', linkSummary, links.rewritten > 0 ? true : ( links.calls ? false : false ) );

			if ( links.calls && ! links.attachments ) {
				row( '', 'Permalinks were filtered but none were attachments. The template is ' +
					'producing attachment URLs some other way (a stored/indexed URL), so the ' +
					'PHP filter cannot reach them.' );
			}

			var mods = swp.mods || {};
			row( 'Attachment sink (mod)',
				! mods.filter_ran ? 'the searchwp\\query\\mods filter never ran'
					: ( mods.applied ? 'applied to source "' + mods.source + '"'
						: ( mods.classes ? 'ran, but no attachment source was found' : 'ran before SearchWP loaded' ) ),
				!! mods.applied );

			if ( ! mods.filter_ran ) {
				row( '', 'These results are not coming from a \\SearchWP\\Query, so the relevance ' +
					'mod has nothing to attach to. Use a front-end rule instead: ' +
					'{ when: "type", op: "equals", value: "attachment", then: "bottom" }' );
			}
		} else {
			row( 'Snippet #0', 'no report — inactive, or it did not run on this page', false );
		}

		var ruleRows = RULES.map( function ( rule, i ) {
			var hits = rule._hits || 0;
			var note = rule._badField ? ' <em style="color:#b91c1c">unknown &ldquo;when&rdquo; field</em>' : '';
			return '<li style="color:' + ( hits ? '#15803d' : '#92400e' ) + '">' +
				( i + 1 ) + '. ' + ( rule.when || 'title' ) + ' ' + ( rule.op || 'contains' ) +
				' &ldquo;' + rule.value + '&rdquo; → ' + rule.then +
				' <strong>(' + hits + ' matched)</strong>' +
				( rule.source === 'settings' ? ' <em>from settings page</em>' : '' ) + note + '</li>';
		} );

		var panel = document.createElement( 'div' );
		panel.id = 'acps-debug-panel';
		panel.setAttribute( 'style',
			'position:fixed;inset-block-end:12px;inset-inline-start:12px;z-index:99999;max-width:min(30rem,calc(100vw - 24px));' +
			'max-height:70vh;overflow:auto;background:#fff;color:#111;border:2px solid #2271b1;border-radius:6px;' +
			'padding:12px 14px;font:12px/1.5 system-ui,sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.25)' );

		panel.innerHTML =
			'<div style="display:flex;justify-content:space-between;align-items:center;gap:1em;margin-bottom:8px">' +
			'<strong style="font-size:13px">Search filter diagnostics</strong>' +
			'<button type="button" style="border:0;background:#eee;border-radius:4px;padding:2px 8px;cursor:pointer">close</button></div>' +
			'<table style="border-collapse:collapse;width:100%">' + rows.join( '' ) + '</table>' +
			( ruleRows.length
				? '<div style="margin-top:10px"><strong>Rules</strong><ol style="margin:4px 0 0;padding-inline-start:1.4em">' +
					ruleRows.join( '' ) + '</ol></div>'
				: '<p style="margin:10px 0 0;color:#92400e">No rules defined — RULES is empty.</p>' );

		panel.querySelector( 'button' ).addEventListener( 'click', function () {
			panel.parentNode.removeChild( panel );
		} );

		document.body.appendChild( panel );
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
