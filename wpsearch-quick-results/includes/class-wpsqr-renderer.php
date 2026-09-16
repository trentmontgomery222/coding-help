<?php
/**
 * Render results.
 *
 * The markup deliberately mirrors SearchWP's own results template — same
 * wrapper, row, title and description classes — so the CSS and the browser
 * rule engine we already built keep working unchanged whether a page is
 * served by this plugin or by SearchWP itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Renderer {

	/**
	 * @param array  $results Return value of WPSQR_Engine::search().
	 * @param string $term    The raw term, for the count notice and highlighting.
	 */
	public static function render( $results, $term ) {
		$settings = WPSQR_Plugin::settings();

		ob_start();

		if ( ! empty( $results['blocked'] ) ) {
			$message = ! empty( $results['blocked']['message'] )
				? $results['blocked']['message']
				: $settings['empty_message'];

			echo '<div class="swp-total-results-notice"><p>' . esc_html( sprintf( 'Found 0 results for %s', $term ) ) . '</p></div>';
			echo '<div class="swp-search-results wpsqr-results"><p class="acps-empty-message">' . esc_html( $message ) . '</p></div>';

			return ob_get_clean();
		}

		$posts = WPSQR_Engine::hydrate( $results['post_ids'] );

		printf(
			'<div class="swp-total-results-notice"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: number of results, 2: search term */
					_n( 'Found %1$s result for %2$s', 'Found %1$s results for %2$s', (int) $results['total'], 'wpsqr' ),
					number_format_i18n( (int) $results['total'] ),
					$term
				)
			)
		);

		if ( ! $posts ) {
			echo '<div class="swp-search-results wpsqr-results"><p class="acps-empty-message">'
				. esc_html( $settings['empty_message'] ) . '</p></div>';

			return ob_get_clean();
		}

		echo '<div class="swp-search-results swp-results-template-1 swp-flex wpsqr-results">';

		foreach ( $posts as $post ) {
			self::render_item( $post, $term );
		}

		echo '</div>';

		if ( $settings['show_timing'] && current_user_can( 'edit_posts' ) ) {
			printf(
				'<p class="wpsqr-timing">%s</p>',
				esc_html(
					sprintf(
						'%s in %dms',
						$results['cached'] ? 'Served from cache' : 'Freshly searched and cached',
						(int) $results['ms']
					)
				)
			);
		}

		return ob_get_clean();
	}

	/**
	 * People matching the search, above the ordinary results.
	 *
	 * Markup carries its own classes rather than reusing the result ones, so
	 * a rule written to hide or reorder page results never accidentally
	 * catches a person.
	 */
	public static function render_people( $term ) {
		$people = WPSQR_People::search( $term );

		if ( ! $people ) {
			return '';
		}

		$settings = WPSQR_Plugin::settings();

		ob_start();

		echo '<section class="wpsqr-people" aria-label="' . esc_attr__( 'People', 'wpsqr' ) . '">';

		$heading = trim( (string) $settings['people_heading'] );
		if ( '' !== $heading ) {
			printf(
				'<h2 class="wpsqr-people__heading">%s</h2>',
				esc_html( str_replace( '{query}', $term, $heading ) )
			);
		}

		echo '<ul class="wpsqr-people__list">';

		foreach ( $people as $person ) {
			self::render_person( $person );
		}

		echo '</ul>';

		$more = WPSQR_People::more_url( $term );

		if ( '' !== $more ) {
			printf(
				'<p class="wpsqr-people__more"><a href="%s">%s</a></p>',
				esc_url( $more ),
				esc_html__( 'Search the full staff directory', 'wpsqr' )
			);
		}

		echo '</section>';

		return ob_get_clean();
	}

	protected static function render_person( $person ) {
		printf(
			'<li class="wpsqr-person"%s>',
			'' === $person['id'] ? '' : ' data-person-id="' . esc_attr( $person['id'] ) . '"'
		);

		if ( $person['photo'] ) {
			printf(
				'<img class="wpsqr-person__photo" src="%s" alt="" loading="lazy" decoding="async" width="56" height="56">',
				esc_url( $person['photo'] )
			);
		} else {
			// A neutral placeholder keeps the row heights even, which matters
			// more than it sounds when half a directory has no photo.
			printf(
				'<span class="wpsqr-person__photo wpsqr-person__photo--empty" aria-hidden="true">%s</span>',
				esc_html( mb_substr( $person['name'], 0, 1 ) )
			);
		}

		echo '<span class="wpsqr-person__detail">';

		if ( $person['url'] ) {
			printf(
				'<a class="wpsqr-person__name" href="%s">%s</a>',
				esc_url( $person['url'] ),
				esc_html( $person['name'] )
			);
		} else {
			printf( '<span class="wpsqr-person__name">%s</span>', esc_html( $person['name'] ) );
		}

		$meta = array_filter( array( $person['job_title'], $person['department'], $person['location'] ) );

		if ( $meta ) {
			printf(
				'<span class="wpsqr-person__meta">%s</span>',
				esc_html( implode( ' · ', $meta ) )
			);
		}

		$contact = array();

		if ( $person['email'] ) {
			$contact[] = sprintf(
				'<a href="mailto:%s">%s</a>',
				esc_attr( $person['email'] ),
				esc_html( $person['email'] )
			);
		}

		if ( $person['phone'] ) {
			$contact[] = esc_html( $person['phone'] );
		}

		if ( $contact ) {
			echo '<span class="wpsqr-person__contact">' . wp_kses_post( implode( ' · ', $contact ) ) . '</span>';
		}

		echo '</span></li>';
	}

	protected static function render_item( $post, $term ) {
		$is_hidden = WPSQR_Hidden::is_hidden( $post->ID );

		$classes = get_post_class( 'swp-result-item', $post->ID );
		if ( $is_hidden ) {
			// Only ever reaches an editor — see WPSQR_Rules::apply().
			$classes[] = 'acps-hidden-result';
			$classes[] = 'acps-admin-hidden';
		}

		$permalink = get_permalink( $post );
		$thumb     = get_the_post_thumbnail( $post, 'thumbnail', array( 'loading' => 'lazy', 'decoding' => 'async' ) );

		printf( '<article class="%s">', esc_attr( implode( ' ', array_map( 'sanitize_html_class', $classes ) ) ) );

		if ( $thumb ) {
			echo '<div class="swp-result-item--img-container"><div class="swp-result-item--img">';
			echo wp_kses_post( $thumb );
			echo '</div></div>';
		}

		echo '<div class="swp-result-item--info-container">';

		printf(
			'<h2 class="entry-title"><a href="%s">%s</a></h2>',
			esc_url( $permalink ),
			wp_kses_post( self::highlight( get_the_title( $post ), $term ) )
		);

		$excerpt = self::description( $post );
		if ( '' !== $excerpt ) {
			printf(
				'<p class="swp-result-item--desc">%s</p>',
				wp_kses_post( self::highlight( $excerpt, $term ) )
			);
		}

		printf(
			'<a href="%s" class="swp-result-item--button">%s</a>',
			esc_url( $permalink ),
			esc_html__( 'Go to Page', 'wpsqr' )
		);

		echo '</div></article>';
	}

	/**
	 * The description shown under a result.
	 *
	 * Order of preference: a description written for search specifically,
	 * then the post's own excerpt, then its content trimmed down. The first
	 * exists because an indexed page's own text often makes a poor summary —
	 * a staff directory whose excerpt is a run of names being the obvious
	 * case — and because that text is what a visitor judges the result by.
	 */
	public static function description( $post ) {
		$settings = WPSQR_Plugin::settings();

		$custom = self::custom_description( $post );
		if ( '' !== $custom ) {
			return $custom;
		}

		$text = has_excerpt( $post ) ? $post->post_excerpt : $post->post_content;
		$text = wp_strip_all_tags( strip_shortcodes( $text ) );
		$text = preg_replace( '/\s+/u', ' ', $text );

		$words = max( 5, (int) $settings['excerpt_words'] );

		return trim( wp_trim_words( $text, $words, ' […]' ) );
	}

	/** A description written specifically for search results, if there is one. */
	public static function custom_description( $post ) {
		$key = WPSQR_Plugin::settings()['desc_meta_key'];

		if ( '' === $key ) {
			return '';
		}

		$post_id = is_object( $post ) ? $post->ID : (int) $post;

		return trim( (string) get_post_meta( $post_id, $key, true ) );
	}

	/**
	 * Wrap the searched words in SearchWP's own highlight markup, so existing
	 * styles apply and the browser rules behave identically either way.
	 *
	 * Escapes first, then inserts markup, so nothing from the post or the
	 * search box can inject HTML.
	 */
	protected static function highlight( $text, $term ) {
		$text = esc_html( $text );

		$words = array_filter( explode( ' ', WPSQR_Normalizer::normalize( $term ) ) );
		if ( ! $words ) {
			return $text;
		}

		$patterns = array_map(
			function ( $word ) {
				return preg_quote( $word, '/' );
			},
			$words
		);

		return (string) preg_replace(
			'/(' . implode( '|', $patterns ) . ')/iu',
			'<mark class="searchwp-highlight">$1</mark>',
			$text
		);
	}
}
