<?php
/*
WPCode Snippet type: PHP Snippet
No special Insert Location needed — this hooks wp_footer directly in
PHP, so it always prints regardless of how this site's WPCode install
handles JavaScript-type snippet insertion/targeting. Restrict to a
specific page, if needed, by wrapping the whole block in an is_page()
check — see the commented example at the bottom.

This is the exact JS from carousel-loader.js (including the hidden
Beaver Builder list settings reader for speed/perPage), just delivered
via PHP output instead of WPCode's JavaScript Snippet type.
*/
add_action( 'wp_footer', function () {
	?>
	<script>
	var partsInterval = 7;
	var partsPerPage = 3;
	try{
		if(document.getElementById("feedbacktext")){
			document.getElementById("feedbacksettings").style.display = 'block';
		}
	}catch(e){
		console.error(e);
	}
	try{
		console.log(document.querySelectorAll('.fl-list-item-heading-text'));
		document.querySelectorAll('.fl-list-item-heading-text').forEach(function (setting) {
			if(setting.textContent == "How many Should be on the screen for Desktop Devices?"){
				console.log(setting);
				console.log(setting.parentElement.parentElement.querySelector(".fl-list-item-content-text"));
				var value = setting.parentElement.parentElement.querySelector(".fl-list-item-content-text").textContent;
				try{
					value = parseInt(value);
					partsPerPage = value;
					console.log(value);
				}catch(e){
					console.error(e);
				}
				console.log(true);
			}else if(setting.textContent == "How long between sliding (SECONDS)"){
				console.log(setting);
				console.log(setting.parentElement.parentElement.querySelector(".fl-list-item-content-text"));
				var value = setting.parentElement.parentElement.querySelector(".fl-list-item-content-text").textContent;
				try{
					value = parseInt(value);
					partsInterval = value;
					console.log(value);
				}catch(e){
					console.error(e);
				}
				console.log(true);
			}else{
				console.log("ABC" + setting);
				console.log(setting.textContent);
			}
		});
	}catch(e){
		console.error(e);
	}
	(function () {
		var ROOT_ID = 'fb-feed-carousel'; // must match the wrapper div ID around your shortcode
		var root = document.getElementById(ROOT_ID);
		if (!root) return;

		function loadAsset(tag, attrs, onload) {
			try{
			var el = document.createElement(tag);
			Object.keys(attrs).forEach(function (key) {
				el[key] = attrs[key];
			});
			if (onload) el.onload = onload;
			document.head.appendChild(el);
			}catch(e){
				console.error(e);
			}
		}

		// Undo Smash Balloon's masonry-mode inline absolute positioning so
		// Splide's own flex-based slide layout controls where items sit.
		function stripMasonryStyles(list, items) {
			try{
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
						}catch(e){
				console.error(e);
			}
		}

		function buildSlider() {
			try{
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

				// Move the post's first photo/video-poster to the very top of
				// the card as a banner. Smash Balloon wraps it in a different
				// class depending on layout (.cff-media-wrap, .cff-photos,
				// .cff-html5-video, ...), so rather than guess that wrapper,
				// relocate the stable img.cff-feed-image element itself and
				// mark it so the CSS can target it directly.
				var media = item.querySelector('img.cff-feed-image');
				if (media) {
					media.classList.add('cff-carousel-banner');
					item.insertBefore(media, item.firstChild);
				}

				// Put the date and the Read More/Share links on one row
				// together at the bottom of the card. They're separate
				// sibling elements in Smash Balloon's markup (.cff-date and
				// .cff-meta-wrap), so wrap both in a flex row instead of
				// relying on their default stacked layout.
				var dateEl = item.querySelector('.cff-date');
				var metaEl = item.querySelector('.cff-meta-wrap');
				if (dateEl && metaEl) {
					var footer = document.createElement('div');
					footer.className = 'cff-carousel-footer';
					dateEl.parentNode.insertBefore(footer, dateEl);
					footer.appendChild(dateEl);
					footer.appendChild(metaEl);
				}
			});
			root.classList.add('splide');

			new Splide(root, {
				type: 'loop',
				perPage: partsPerPage,
				perMove: 1,
				focus: 0,
				gap: '1.5rem',
				pagination: true,
				arrows: true,
				autoplay: true,
				interval: partsInterval * 1000,
				pauseOnHover: true,
				pauseOnFocus: true,
				breakpoints: {
					1024: { perPage: partsPerPage },
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
						}catch(e){
				console.error(e);
			}
		}

		// Smash Balloon loads posts via AJAX after page load, so watch the DOM
		// with a MutationObserver instead of assuming a fixed delay.
		function watchForFeed() {
			try{
			buildSlider(); // in case the feed is already cached/loaded
			var observer = new MutationObserver(function () {
				buildSlider();
				if (root.dataset.carouselReady) observer.disconnect();
			});
			observer.observe(root, { childList: true, subtree: true });
						}catch(e){
				console.error(e);
			}
		}

		if (window.Splide) {
			try{
			watchForFeed();
			return;
						}catch(e){
				console.error(e);
			}
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
	</script>
	<?php
}, 100 );

/*
To restrict this to one specific page instead of site-wide, wrap the
add_action() call above in a conditional, e.g.:

if ( is_page( 123 ) ) { // replace 123 with the actual page ID
	add_action( 'wp_footer', function () { ... }, 100 );
}
*/
