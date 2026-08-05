<?php
/**
 * XML sitemap generation.
 *
 * Serves a sitemap index at /sitemap.xml and per-object-type sub-sitemaps at
 * /sitemap-{key}.xml (with pagination as /sitemap-{key}--{page}.xml). When
 * pretty permalinks are disabled, the same output is available via the
 * ?acps_sitemap= query variable.
 *
 * @package ACPS_Sitemap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_Sitemap_XML {

	/** Query variable that drives sitemap output. */
	const QUERY_VAR = 'acps_sitemap';

	/** Transient lifetime for rendered sitemaps, in seconds. */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'maybe_add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 10, 2 );

		// Registered now (before `init`) so it is in place when core checks it.
		add_filter( 'wp_sitemaps_enabled', array( $this, 'filter_core_sitemap_enabled' ) );
	}

	/**
	 * Add rewrite rules on every request when the XML sitemap is enabled.
	 */
	public function maybe_add_rewrite_rules() {
		if ( ACPS_Sitemap::get_setting( 'enable_xml' ) ) {
			self::add_rewrite_rules();
		}
	}

	/**
	 * Register the rewrite rules. Static so it can also run during activation.
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?' . self::QUERY_VAR . '=index', 'top' );
		add_rewrite_rule( '^sitemap-([^/]+)\.xml$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Register the query variable.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Disable the WordPress core sitemap (wp-sitemap.xml) when requested, to
	 * avoid two competing sitemaps.
	 *
	 * @param bool $enabled Whether core sitemaps are enabled.
	 * @return bool
	 */
	public function filter_core_sitemap_enabled( $enabled ) {
		if ( ACPS_Sitemap::get_setting( 'enable_xml' ) && ACPS_Sitemap::get_setting( 'disable_core_sitemap' ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Add the sitemap reference to robots.txt.
	 *
	 * @param string $output  Current robots.txt content.
	 * @param bool   $public  Whether the site is public.
	 * @return string
	 */
	public function robots_txt( $output, $public ) {
		if ( $public && ACPS_Sitemap::get_setting( 'enable_xml' ) && ACPS_Sitemap::get_setting( 'add_to_robots' ) ) {
			$output .= "\nSitemap: " . esc_url( $this->index_url() ) . "\n";
		}
		return $output;
	}

	/* --------------------------------------------------------------------- *
	 * Request handling.
	 * --------------------------------------------------------------------- */

	/**
	 * If the current request targets a sitemap, render it and stop.
	 */
	public function maybe_render() {
		$what = get_query_var( self::QUERY_VAR );
		if ( '' === $what || null === $what ) {
			return;
		}

		if ( ! ACPS_Sitemap::get_setting( 'enable_xml' ) ) {
			return;
		}

		$what = sanitize_text_field( wp_unslash( $what ) );

		if ( 'index' === $what ) {
			$xml = $this->get_cached( 'index', array( $this, 'build_index' ) );
			$this->output( $xml );
			return;
		}

		// Split "key--page" (page defaults to 1).
		if ( false !== strpos( $what, '--' ) ) {
			list( $key, $page ) = explode( '--', $what, 2 );
			$page = max( 1, (int) $page );
		} else {
			$key  = $what;
			$page = 1;
		}

		$providers = $this->get_providers();
		if ( ! isset( $providers[ $key ] ) ) {
			return; // Unknown sitemap: let WordPress serve its normal 404.
		}

		$cache_id = $key . '--' . $page;
		$xml      = $this->get_cached(
			$cache_id,
			function () use ( $key, $page, $providers ) {
				return $this->build_urlset( $key, $providers[ $key ], $page );
			}
		);
		$this->output( $xml );
	}

	/**
	 * Send an XML document to the browser and terminate the request.
	 *
	 * @param string $xml XML body.
	 */
	private function output( $xml ) {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML is escaped as it is assembled.
		exit;
	}

	/**
	 * Return cached output, generating and caching it on a miss.
	 *
	 * @param string   $id        Cache identifier.
	 * @param callable $generator Produces the XML when the cache misses.
	 * @return string
	 */
	private function get_cached( $id, $generator ) {
		$key    = ACPS_Sitemap::cache_key( $id );
		$cached = get_transient( $key );
		if ( false !== $cached && is_string( $cached ) ) {
			return $cached;
		}
		$xml = call_user_func( $generator );
		set_transient( $key, $xml, self::CACHE_TTL );
		return $xml;
	}

	/* --------------------------------------------------------------------- *
	 * Providers (what gets its own sub-sitemap).
	 * --------------------------------------------------------------------- */

	/**
	 * Build the list of sitemap providers keyed by their sitemap key.
	 *
	 * @return array<string,array>
	 */
	private function get_providers() {
		$settings  = ACPS_Sitemap::get_settings();
		$providers = array();

		// A small "extra" sitemap that always carries the site home page.
		$providers['extra'] = array( 'kind' => 'extra' );

		foreach ( (array) $settings['post_types'] as $pt ) {
			if ( post_type_exists( $pt ) ) {
				$providers[ 'pt-' . $pt ] = array(
					'kind' => 'post_type',
					'name' => $pt,
				);
			}
		}

		foreach ( (array) $settings['taxonomies'] as $tx ) {
			if ( taxonomy_exists( $tx ) ) {
				$providers[ 'tax-' . $tx ] = array(
					'kind' => 'taxonomy',
					'name' => $tx,
				);
			}
		}

		return $providers;
	}

	/**
	 * Number of items a provider holds.
	 *
	 * @param array $provider Provider definition.
	 * @return int
	 */
	private function provider_count( $provider ) {
		switch ( $provider['kind'] ) {
			case 'extra':
				return 1;

			case 'post_type':
				$counts = wp_count_posts( $provider['name'] );
				return isset( $counts->publish ) ? (int) $counts->publish : 0;

			case 'taxonomy':
				$count = get_terms(
					array(
						'taxonomy'   => $provider['name'],
						'hide_empty' => true,
						'fields'     => 'count',
					)
				);
				return is_wp_error( $count ) ? 0 : (int) $count;
		}
		return 0;
	}

	/**
	 * Maximum URLs per sub-sitemap (clamped to the sitemaps.org limit).
	 *
	 * @return int
	 */
	private function per_page() {
		$max = (int) ACPS_Sitemap::get_setting( 'max_per_sitemap', 1000 );
		if ( $max < 1 ) {
			$max = 1000;
		}
		return min( $max, 50000 );
	}

	/**
	 * Excluded object IDs.
	 *
	 * @return int[]
	 */
	private function excluded_ids() {
		$ids = ACPS_Sitemap::get_setting( 'exclude_ids', array() );
		return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	}

	/* --------------------------------------------------------------------- *
	 * XML builders.
	 * --------------------------------------------------------------------- */

	/**
	 * Build the sitemap index document.
	 *
	 * @return string
	 */
	private function build_index() {
		$per_page  = $this->per_page();
		$providers = $this->get_providers();

		$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $providers as $key => $provider ) {
			$total = $this->provider_count( $provider );
			if ( $total < 1 ) {
				continue;
			}
			$pages = max( 1, (int) ceil( $total / $per_page ) );
			for ( $page = 1; $page <= $pages; $page++ ) {
				$out .= "\t<sitemap>\n";
				$out .= "\t\t<loc>" . esc_url( $this->sitemap_url( $key, $page ) ) . "</loc>\n";
				$lastmod = $this->provider_lastmod( $provider );
				if ( $lastmod ) {
					$out .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
				}
				$out .= "\t</sitemap>\n";
			}
		}

		$out .= '</sitemapindex>' . "\n";
		return $out;
	}

	/**
	 * Build a single urlset document for a provider page.
	 *
	 * @param string $key      Provider key.
	 * @param array  $provider Provider definition.
	 * @param int    $page     1-based page number.
	 * @return string
	 */
	private function build_urlset( $key, $provider, $page ) {
		$urls = array();

		switch ( $provider['kind'] ) {
			case 'extra':
				$urls[] = array(
					'loc'        => home_url( '/' ),
					'lastmod'    => $this->latest_post_modified(),
					'changefreq' => 'daily',
					'priority'   => '1.0',
				);
				break;

			case 'post_type':
				$urls = $this->post_type_urls( $provider['name'], $page );
				break;

			case 'taxonomy':
				$urls = $this->taxonomy_urls( $provider['name'], $page );
				break;
		}

		$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $urls as $url ) {
			if ( empty( $url['loc'] ) ) {
				continue;
			}
			$out .= "\t<url>\n";
			$out .= "\t\t<loc>" . esc_url( $url['loc'] ) . "</loc>\n";
			if ( ! empty( $url['lastmod'] ) ) {
				$out .= "\t\t<lastmod>" . esc_html( $url['lastmod'] ) . "</lastmod>\n";
			}
			if ( ! empty( $url['changefreq'] ) ) {
				$out .= "\t\t<changefreq>" . esc_html( $url['changefreq'] ) . "</changefreq>\n";
			}
			if ( ! empty( $url['priority'] ) ) {
				$out .= "\t\t<priority>" . esc_html( $url['priority'] ) . "</priority>\n";
			}
			$out .= "\t</url>\n";
		}

		$out .= '</urlset>' . "\n";
		return $out;
	}

	/**
	 * Collect URLs for one page of a post type.
	 *
	 * @param string $post_type Post type name.
	 * @param int    $page      1-based page.
	 * @return array
	 */
	private function post_type_urls( $post_type, $page ) {
		$per_page = $this->per_page();

		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'has_password'           => false,
				'post__not_in'           => $this->excluded_ids(),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$urls      = array();
		$is_page   = ( 'page' === $post_type );
		$front_id  = (int) get_option( 'page_on_front' );

		foreach ( $query->posts as $post ) {
			// The front page is represented by the home URL in the "extra" sitemap.
			if ( $front_id && (int) $post->ID === $front_id ) {
				continue;
			}
			$urls[] = array(
				'loc'        => get_permalink( $post ),
				'lastmod'    => $this->format_date( $post->post_modified_gmt ),
				'changefreq' => $is_page ? 'monthly' : 'weekly',
				'priority'   => $is_page ? '0.8' : '0.6',
			);
		}

		wp_reset_postdata();
		return $urls;
	}

	/**
	 * Collect URLs for one page of a taxonomy's terms.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int    $page     1-based page.
	 * @return array
	 */
	private function taxonomy_urls( $taxonomy, $page ) {
		$per_page = $this->per_page();

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$urls = array();
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$urls[] = array(
				'loc'        => $link,
				'changefreq' => 'weekly',
				'priority'   => '0.4',
			);
		}
		return $urls;
	}

	/* --------------------------------------------------------------------- *
	 * Helpers.
	 * --------------------------------------------------------------------- */

	/**
	 * Most recent modified date for a provider (used as index lastmod).
	 *
	 * @param array $provider Provider definition.
	 * @return string W3C datetime, or empty string.
	 */
	private function provider_lastmod( $provider ) {
		if ( 'post_type' === $provider['kind'] ) {
			$posts = get_posts(
				array(
					'post_type'        => $provider['name'],
					'post_status'      => 'publish',
					'posts_per_page'   => 1,
					'orderby'          => 'modified',
					'order'            => 'DESC',
					'fields'           => 'ids',
					'suppress_filters' => false,
				)
			);
			if ( ! empty( $posts ) ) {
				$modified = get_post_field( 'post_modified_gmt', $posts[0] );
				return $this->format_date( $modified );
			}
		}
		if ( 'extra' === $provider['kind'] ) {
			return $this->latest_post_modified();
		}
		return '';
	}

	/**
	 * The most recent modified date across published posts and pages.
	 *
	 * @return string
	 */
	private function latest_post_modified() {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		if ( ! empty( $posts ) ) {
			return $this->format_date( get_post_field( 'post_modified_gmt', $posts[0] ) );
		}
		return '';
	}

	/**
	 * Format a GMT datetime string as a W3C datetime.
	 *
	 * @param string $gmt_date MySQL datetime in GMT.
	 * @return string
	 */
	private function format_date( $gmt_date ) {
		if ( empty( $gmt_date ) || '0000-00-00 00:00:00' === $gmt_date ) {
			return '';
		}
		$timestamp = strtotime( $gmt_date . ' GMT' );
		if ( ! $timestamp ) {
			return '';
		}
		return gmdate( 'c', $timestamp );
	}

	/**
	 * URL for the sitemap index.
	 *
	 * @return string
	 */
	public function index_url() {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/sitemap.xml' );
		}
		return home_url( '/?' . self::QUERY_VAR . '=index' );
	}

	/**
	 * URL for a sub-sitemap page.
	 *
	 * @param string $key  Provider key.
	 * @param int    $page 1-based page.
	 * @return string
	 */
	public function sitemap_url( $key, $page = 1 ) {
		$slug = ( $page > 1 ) ? $key . '--' . $page : $key;
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/sitemap-' . $slug . '.xml' );
		}
		return home_url( '/?' . self::QUERY_VAR . '=' . rawurlencode( $slug ) );
	}
}
