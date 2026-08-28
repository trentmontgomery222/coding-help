/*
WPCode Snippet type: JavaScript Snippet
Insert location: Footer
Insertion: restrict to just the page(s) with the Facebook feed via
WPCode's "Insertion" tab -> "Page Specific" (or Smart Conditional Logic).

Pure JS on purpose (no <link>/<script> tags) — WPCode's JavaScript
Snippet editor treats the whole box as a JS body and lints out raw HTML
tags. Splide's CSS/JS are pulled in with document.createElement instead
of a literal <script>/<link>, so nothing here needs an HTML Snippet.
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

	function buildSlider() {
		if (root.dataset.carouselReady) return; // guard against double init
		var list = root.querySelector('.cff-list-wrapper');
		var items = list
			? list.querySelectorAll(':scope > .cff-item-wrap')
			: [];
		if (!list || !items.length) return;

		root.dataset.carouselReady = '1';

		// Wrap the plugin's existing item list in the structure Splide expects,
		// without cloning or removing any of Smash Balloon's own nodes/handlers.
		var track = document.createElement('div');
		track.className = 'splide__track';
		list.parentNode.insertBefore(track, list);
		track.appendChild(list);

		list.classList.add('splide__list');
		items.forEach(function (item) {
			item.classList.add('splide__slide');
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
		href: 'https://cdnjs.cloudflare.com/ajax/libs/splide/4.1.4/css/splide.min.css',
	});

	loadAsset(
		'script',
		{
			src: 'https://cdnjs.cloudflare.com/ajax/libs/splide/4.1.4/js/splide.min.js',
		},
		watchForFeed
	);
})();
