<?php
/*
WPCode Snippet type: PHP Snippet
No special Insert Location needed — this hooks wp_head/wp_footer
directly in PHP, so it always prints regardless of how this site's
WPCode install handles JavaScript/CSS-type snippet insertion/targeting.
Restrict to a specific page, if needed, by wrapping the add_action()
calls in an is_page() check — see the commented example at the bottom.

This is the exact JS + CSS from carousel-loader.js/carousel-theme.css
(including the hidden Beaver Builder list settings reader for
speed/perPage), just delivered via PHP output instead of WPCode's
JavaScript/CSS Snippet types.
*/

add_action( 'wp_head', function () {
	?>
	<style>
	.feedbacksettings {
		display: none;
	}

	#fb-feed-carousel {
		position: relative;
		max-width: 100%;
	}

	/* Kill Smash Balloon's own grid/flex-wrap rules once items become slides */
	#fb-feed-carousel .cff-posts-wrap.splide__list {
		flex-wrap: nowrap !important;
	}

	/* Masonry-mode feeds set a fixed-height, scroll-to-load-more wrapper —
	   let the carousel size to its own content instead of clipping/scrolling */
	#fb-feed-carousel .cff-wrapper-fixed-height {
		height: auto !important;
		overflow: visible !important;
	}

	#fb-feed-carousel .splide__track {
		overflow: hidden;
	}

	/* !important throughout this rule so it wins regardless of Smash
	   Balloon's own stylesheet. */
	#fb-feed-carousel .splide__slide {
		box-sizing: border-box !important;
		background: #f6f6f6 !important;
		border-radius: 4px !important;
		box-shadow: 0 1px 6px rgba(0, 0, 0, 0.15) !important;
		padding: 12px !important;
		height: 700px !important;
		overflow: hidden !important;
		display: flex !important;
		flex-direction: column !important;
	}

	/* Photo/video-poster banner — carousel-loader.php's JS moves this img
	   to be the card's first direct child and tags it .cff-carousel-banner. */
	#fb-feed-carousel .splide__slide > img.cff-carousel-banner {
		display: block;
		width: calc(100% + 32px);
		height: 350px;
		object-fit: cover;
	/* 	margin: -16px -16px 12px -16px; */
		flex: 0 0 auto;
	}

	/* Album posts can carry several photos — only the banner one should show */
	#fb-feed-carousel .splide__slide img.cff-feed-image:not(.cff-carousel-banner) {
		display: none !important;
	}

	/* Whatever wrapper Smash Balloon originally put the image in is now
	   empty (the image itself was relocated above) — hide its leftover
	   shell/chrome under any of the class names it's used across layouts. */
	#fb-feed-carousel .splide__slide .cff-media-wrap,
	#fb-feed-carousel .splide__slide .cff-html5-video,
	#fb-feed-carousel .splide__slide .cff-photos,
	#fb-feed-carousel .splide__slide .cff-single-photo {
		display: none !important;
	}

	/* Clamp post text to a consistent number of lines so cards line up evenly. */
	#fb-feed-carousel .splide__slide .cff-post-text {
		font-size: 14px !important;
		line-height: 1.5 !important;
		max-height: 6em !important;
		overflow: hidden !important;
		display: -webkit-box !important;
		-webkit-line-clamp: 4 !important;
		-webkit-box-orient: vertical !important;
		margin: 0px !important;
		margin-top: 10px !important;
	}

	/* Date + Read More/Share sit together as one row at the bottom of the
	   card. Smash Balloon already makes the card itself the positioning
	   anchor for these, so keep position:absolute and anchor it with
	   bottom/left rather than fighting it back to static. */
	#fb-feed-carousel .splide__slide .cff-carousel-footer,
	#fb-feed-carousel .splide__slide .cff-carousel-footer .cff-date,
	#fb-feed-carousel .splide__slide .cff-carousel-footer .cff-meta-wrap,
	#fb-feed-carousel .splide__slide .cff-carousel-footer .cff-post-links {
		position: absolute;
		left: 1px;
		right: auto !important;
		bottom: -20px;
		padding-left: 23px;
		padding-bottom: 243px;
	}

	#fb-feed-carousel .splide__slide .cff-carousel-footer {
		display: flex !important;
		align-items: center !important;
		gap: 8px !important;
		font-size: 12px !important;
	}

	#fb-feed-carousel .splide__slide .cff-date {
		color: #999;
		white-space: nowrap;
	}

	#fb-feed-carousel .splide__slide .cff-post-links {
		display: flex;
		align-items: center;
		gap: 4px;
		white-space: nowrap;
	}

	#fb-feed-carousel .splide__slide .cff-post-links a {
	/* 	color: #1877f2; */
		text-decoration: none;
	}

	#fb-feed-carousel .splide__slide .cff-post-links a:hover {
		text-decoration: underline;
	}

	/* Hide the "..." expand link and comment/like counts — BB's carousel
	   doesn't show these, keep the card to image + excerpt + date/links */
	#fb-feed-carousel .splide__slide .cff-expand,
	#fb-feed-carousel .splide__slide .cff-react-wrapper {
		display: none !important;
	}

	/* Arrows — mimic BB's circular overlay arrow buttons. top/transform
	   are !important because Splide's own JS resets the arrow's inline
	   `top` on click — force the centered position so it wins over that. */
	#fb-feed-carousel .splide__arrow {
		background: rgba(0, 0, 0, 0.55);
		width: 40px;
		height: 40px;
		display:none;
		opacity: 1;
		top: 50% !important;
		transform: translateY(-50%) !important;
	}

	#fb-feed-carousel .splide__arrow:hover {
		background: rgba(0, 0, 0, 0.8);
	}

	#fb-feed-carousel .splide__arrow svg {
		fill: #fff;
		width: 16px;
		height: 16px;
	}

	#fb-feed-carousel .splide__arrow--prev {
		left: 0.5em;
	}

	#fb-feed-carousel .splide__arrow--next {
		right: 0.5em;
	}

	#fb-feed-carousel .splide__arrow:focus, #fb-feed-carousel .splide__arrow:focus-visible {
		outline: 2px solid #1877f2 !important;
		outline-offset: 2px !important;
		border-radius: 50% !important;
	}

	button:active, input[type="button"]:active, input[type="submit"]:active, button:focus, input[type="button"]:focus, input[type="submit"]:focus {
		color: #fff;
	}

	/* Dots — centered pagination row below the cards */
	#fb-feed-carousel .splide__pagination {
		position: relative;
		bottom: auto;
		margin-top: 16px;
	}

	#fb-feed-carousel .splide__pagination__page {
		background: #ccc;
		opacity: 1;
		width: 9px;
		height: 9px;
		margin: 0 4px;
		transition: background-color 0.2s ease;
	}

	#fb-feed-carousel .splide__pagination__page.is-active {
		background: #1877f2;
		transform: scale(1.2);
	}

	/* Hide Smash Balloon's "Load More" button — see README, it should stay off */
	#fb-feed-carousel .cff-loadmore-wrapper,
	#fb-feed-carousel .cff-load-more {
		display: none !important;
	}
	</style>
	<?php
}, 100 );

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
