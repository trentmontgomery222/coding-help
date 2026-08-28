/*
WPCode Snippet type: JavaScript Snippet
Insert location: Footer
Insertion: restrict to just the page(s) with the Facebook feed via
WPCode's "Insertion" tab -> "Page Specific" (or Smart Conditional Logic).

Pure JS on purpose (no <link>/<script> tags) — WPCode's JavaScript
Snippet editor treats the whole box as a JS body and lints out raw HTML
tags. Splide's CSS/JS are pulled in with document.createElement instead
of a literal <script>/<link>, so nothing here needs an HTML Snippet.

Matches Smash Balloon's actual rendered markup:
  #fb-feed-carousel > ... > .cff-posts-wrap > .cff-item (one per post)
(NOT .cff-list-wrapper / .cff-item-wrap — that's a different layout mode.)

If the feed's Layout is set to "Masonry" in Smash Balloon, its own JS
positions each .cff-item with inline position:absolute; left/top and may
re-run that on window resize, fighting the carousel. Switching the feed's
Layout to "Grid" avoids that fight entirely (see README). This script
still defensively strips those inline styles either way, including a
re-strip after resize in case Masonry's JS reapplies them.
*/
(function () {
	var ROOT_ID = 'fb-feed-carousel'; // must match the wrapper div ID around your shortcode
	var root = document.getElementById(ROOT_ID);
	if (!root) return;

	function loadAsset(tag, attrs, onload) {
		var el = document.createElement(tag);
		Object.keys(attrs).forEach(function (key) {
			el[key] = attrs[key];
		});
		if (onload) el.onload = onload;
		document.head.appendChild(el);
	}

	// Undo Smash Balloon's masonry-mode inline absolute positioning so
	// Splide's own flex-based slide layout controls where items sit.
	function stripMasonryStyles(list, items) {
		list.style.position = '';
		list.style.height = '';
		items.forEach(function (item) {
			item.style.position = '';
			item.style.left = '';
			item.style.top = '';
			item.style.zIndex = '';
		});
		var fixedHeightWrap = root.querySelector('.cff-wrapper-fixed-height');
		if (fixedHeightWrap) {
			fixedHeightWrap.style.height = 'auto';
			fixedHeightWrap.style.overflow = 'visible';
		}
	}

	function buildSlider() {
		if (root.dataset.carouselReady) return; // guard against double init
		var list = root.querySelector('.cff-posts-wrap');
		var items = list ? list.querySelectorAll(':scope > .cff-item') : [];
		if (!list || !items.length) return;

		root.dataset.carouselReady = '1';
		items = Array.prototype.slice.call(items);

		stripMasonryStyles(list, items);

		// Wrap the plugin's existing item list in the structure Splide expects,
		// without cloning or removing any of Smash Balloon's own nodes/handlers.
		var track = document.createElement('div');
		track.className = 'splide__track';
		list.parentNode.insertBefore(track, list);
		track.appendChild(list);

		list.classList.add('splide__list');
		items.forEach(function (item) {
			item.classList.add('splide__slide');
			// Smash Balloon lazy-loads photos via data-src; its own lazy-load
			// observer doesn't reliably fire once posts move into the carousel
			// (and Splide's loop mode clones slides, which never fire it at
			// all), so resolve them eagerly instead of waiting on it.
			item.querySelectorAll('img[data-src]').forEach(function (img) {
				img.src = img.dataset.src;
				img.removeAttribute('data-src');
				img.classList.remove('lazyload');
				img.classList.add('lazyloaded');
			});
		});
		root.classList.add('splide');

		new Splide(root, {
			type: 'loop',
			perPage: 3,
			perMove: 1,
			gap: '1.5rem',
			pagination: true,
			arrows: true,
			autoplay: true,
			interval: 5000,
			pauseOnHover: true,
			pauseOnFocus: true,
			breakpoints: {
				1024: { perPage: 2 },
				640: { perPage: 1 },
			},
		}).mount();

		// If Smash Balloon's masonry JS recalculates positions on resize,
		// strip its inline styles again shortly after so the carousel wins.
		var resizeTimer;
		window.addEventListener('resize', function () {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(function () {
				stripMasonryStyles(list, items);
			}, 150);
		});
	}

	// Smash Balloon loads posts via AJAX after page load, so watch the DOM
	// with a MutationObserver instead of assuming a fixed delay.
	function watchForFeed() {
		buildSlider(); // in case the feed is already cached/loaded
		var observer = new MutationObserver(function () {
			buildSlider();
			if (root.dataset.carouselReady) observer.disconnect();
		});
		observer.observe(root, { childList: true, subtree: true });
	}

	if (window.Splide) {
		watchForFeed();
		return;
	}

	loadAsset('link', {
		rel: 'stylesheet',
		href: 'https://cdn.jsdelivr.net/npm/@splidejs/splide@4/dist/css/splide.min.css',
	});

	loadAsset(
		'script',
		{
			src: 'https://cdn.jsdelivr.net/npm/@splidejs/splide@4/dist/js/splide.min.js',
		},
		watchForFeed
	);
})();
