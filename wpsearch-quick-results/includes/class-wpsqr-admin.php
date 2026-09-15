<?php
/**
 * Admin screens: a dashboard that answers "is this actually helping?", and
 * the settings that used to live in the WPCode snippets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Admin {

	const CAP = 'manage_options';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_wpsqr_flush', array( $this, 'handle_flush' ) );
		add_action( 'admin_post_wpsqr_warm', array( $this, 'handle_warm' ) );
		add_action( 'admin_post_wpsqr_save', array( $this, 'handle_save' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'Quick Results', 'wpsqr' ),
			__( 'Quick Results', 'wpsqr' ),
			self::CAP,
			'wpsqr',
			array( $this, 'render_dashboard' ),
			'dashicons-search',
			76
		);

		add_submenu_page( 'wpsqr', __( 'Dashboard', 'wpsqr' ), __( 'Dashboard', 'wpsqr' ), self::CAP, 'wpsqr', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'wpsqr', __( 'Settings', 'wpsqr' ), __( 'Settings', 'wpsqr' ), self::CAP, 'wpsqr-settings', array( $this, 'render_settings' ) );
	}

	/* ---- Dashboard ---------------------------------------------------- */

	public function render_dashboard() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$summary = WPSQR_Stats::summary();
		$cache   = WPSQR_Cache::stats();
		$popular = WPSQR_Stats::popular( 25, 30 );
		$zero    = WPSQR_Stats::zero_result_terms( 10 );
		$warm    = get_option( 'wpsqr_last_warm', array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search Quick Results', 'wpsqr' ); ?></h1>

			<h2><?php esc_html_e( 'Status', 'wpsqr' ); ?></h2>
			<table class="widefat striped" style="max-width:60em;margin-bottom:2rem">
				<tbody>
				<?php foreach ( WPSQR_Status::checks() as $check ) : ?>
					<?php
					$colors = array( 'ok' => '#00a32a', 'warn' => '#dba617', 'bad' => '#d63638', 'info' => '#787c82' );
					$color  = isset( $colors[ $check['state'] ] ) ? $colors[ $check['state'] ] : '#787c82';
					?>
					<tr>
						<th scope="row" style="width:14em"><?php echo esc_html( $check['label'] ); ?></th>
						<td>
							<span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600">&#9679;</span>
							<?php echo esc_html( $check['value'] ); ?>
							<?php if ( ! empty( $check['note'] ) ) : ?>
								<p class="description" style="margin:.35em 0 0"><?php echo esc_html( $check['note'] ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $summary['observed'] > 0 && 0 === $summary['rendered'] ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'These searches are being watched, not served — SearchWP is still rendering the results, so nothing is cached yet and the hit rate stays at zero. That is the right order: let the table below fill up, see whether the top terms repeat, then add the shortcode if they do.', 'wpsqr' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( $summary['searches'] < 50 ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'Fewer than 50 searches recorded so far. The numbers below need a few days of real traffic before they mean much — especially the hit rate.', 'wpsqr' ); ?>
				</p></div>
			<?php endif; ?>

			<div style="display:flex;gap:1rem;flex-wrap:wrap;margin:1.5rem 0">
				<?php
				$this->stat_card(
					__( 'Cache hit rate', 'wpsqr' ),
					$summary['rendered'] > 0 ? $summary['hit_rate'] . '%' : '—',
					$summary['rendered'] > 0
						? __( 'Share of searches served without touching the search engine', 'wpsqr' )
						: __( 'Nothing served yet — the shortcode is not in place', 'wpsqr' )
				);
				$this->stat_card(
					__( 'Avg uncached search', 'wpsqr' ),
					$summary['avg_uncached'] > 0 ? $summary['avg_uncached'] . 'ms' : '—',
					__( 'What a cache miss costs', 'wpsqr' )
				);
				$this->stat_card( __( 'Searches recorded', 'wpsqr' ), number_format_i18n( $summary['searches'] ), __( 'Across', 'wpsqr' ) . ' ' . number_format_i18n( $summary['unique_terms'] ) . ' ' . __( 'unique terms', 'wpsqr' ) );
				$this->stat_card( __( 'Cached entries', 'wpsqr' ), number_format_i18n( $cache['entries'] ), __( 'Currently stored result sets', 'wpsqr' ) );
				?>
			</div>

			<?php if ( $summary['searches'] >= 50 ) : ?>
				<?php
				$top_share = 0;
				if ( $popular && $summary['searches'] > 0 ) {
					$top_ten   = array_slice( $popular, 0, 10 );
					$top_count = array_sum( wp_list_pluck( $top_ten, 'searches' ) );
					$top_share = round( ( $top_count / $summary['searches'] ) * 100 );
				}
				?>
				<div class="notice <?php echo $top_share >= 40 ? 'notice-success' : 'notice-warning'; ?>"><p>
					<?php
					printf(
						/* translators: %d: percentage */
						esc_html__( 'The ten most common terms account for %d%% of all searches.', 'wpsqr' ),
						(int) $top_share
					);
					echo ' ';
					echo $top_share >= 40
						? esc_html__( 'Caching is doing real work here.', 'wpsqr' )
						: esc_html__( 'Search traffic is spread thin, so caching will help less than it would on a more repetitive site. The rules and filtering are still worth having.', 'wpsqr' );
					?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1rem 0">
				<?php wp_nonce_field( 'wpsqr_warm' ); ?>
				<input type="hidden" name="action" value="wpsqr_warm">
				<?php submit_button( __( 'Warm the cache now', 'wpsqr' ), 'secondary', 'submit', false ); ?>
				<?php if ( ! empty( $warm['time'] ) ) : ?>
					<span class="description" style="margin-inline-start:1em">
						<?php
						printf(
							/* translators: 1: time, 2: number warmed, 3: number skipped */
							esc_html__( 'Last run %1$s — %2$d warmed, %3$d already fresh', 'wpsqr' ),
							esc_html( $warm['time'] ),
							(int) $warm['warmed'],
							(int) $warm['skipped']
						);
						?>
					</span>
				<?php endif; ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1rem 0">
				<?php wp_nonce_field( 'wpsqr_flush' ); ?>
				<input type="hidden" name="action" value="wpsqr_flush">
				<?php submit_button( __( 'Empty the cache', 'wpsqr' ), 'delete', 'submit', false ); ?>
				<span class="description" style="margin-inline-start:1em">
					<?php esc_html_e( 'Happens automatically whenever a post is saved.', 'wpsqr' ); ?>
				</span>
			</form>

			<h2><?php esc_html_e( 'Most searched (last 30 days)', 'wpsqr' ); ?></h2>
			<table class="widefat striped" style="max-width:60em">
				<thead><tr>
					<th><?php esc_html_e( 'Term', 'wpsqr' ); ?></th>
					<th><?php esc_html_e( 'Searches', 'wpsqr' ); ?></th>
					<th><?php esc_html_e( 'Results', 'wpsqr' ); ?></th>
					<th><?php esc_html_e( 'From cache', 'wpsqr' ); ?></th>
					<th><?php esc_html_e( 'Last searched', 'wpsqr' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $popular ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing recorded yet.', 'wpsqr' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $popular as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $row['display'] ? $row['display'] : $row['term'] ); ?></strong></td>
							<td><?php echo (int) $row['searches']; ?></td>
							<td>
								<?php if ( empty( $row['results_known'] ) ) : ?>
									<span style="color:#787c82" title="<?php esc_attr_e( 'Watched only — this plugin did not run the search', 'wpsqr' ); ?>">&mdash;</span>
								<?php elseif ( 0 === (int) $row['results'] ) : ?>
									<span style="color:#b32d2e">0</span>
								<?php else : ?>
									<?php echo (int) $row['results']; ?>
								<?php endif; ?>
							</td>
							<td><?php echo (int) $row['cached_hits']; ?></td>
							<td><?php echo esc_html( $row['last_searched'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Searches that found nothing', 'wpsqr' ); ?></h2>
			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'The most useful list here. Every row is someone who wanted something and left empty-handed — usually a missing page, or a word your content does not use.', 'wpsqr' ); ?>
			</p>
			<table class="widefat striped" style="max-width:40em">
				<tbody>
				<?php if ( ! $zero ) : ?>
					<tr><td><?php esc_html_e( 'None — every recorded search found something.', 'wpsqr' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $zero as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $row['display'] ? $row['display'] : $row['term'] ); ?></strong></td>
							<td><?php echo (int) $row['searches']; ?> <?php esc_html_e( 'searches', 'wpsqr' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	protected function stat_card( $label, $value, $note ) {
		printf(
			'<div style="flex:1 1 12rem;min-width:12rem;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:1rem 1.15rem">
				<div style="font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:#646970">%s</div>
				<div style="font-size:1.9rem;font-weight:600;line-height:1.2;margin:.15em 0">%s</div>
				<div style="font-size:.8rem;color:#646970">%s</div>
			</div>',
			esc_html( $label ),
			esc_html( $value ),
			esc_html( $note )
		);
	}

	/* ---- Settings ------------------------------------------------------ */

	public function render_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$s = WPSQR_Plugin::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Quick Results Settings', 'wpsqr' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpsqr_save' ); ?>
				<input type="hidden" name="action" value="wpsqr_save">

				<h2><?php esc_html_e( 'Cache', 'wpsqr' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpsqr-ttl"><?php esc_html_e( 'Keep results for', 'wpsqr' ); ?></label></th>
						<td>
							<input type="number" id="wpsqr-ttl" name="ttl" min="60" step="60" value="<?php echo esc_attr( $s['ttl'] ); ?>" class="small-text"> <?php esc_html_e( 'seconds', 'wpsqr' ); ?>
							<p class="description"><?php esc_html_e( 'The cache is emptied whenever a post is saved regardless, so this only matters on a quiet site. Six hours is a reasonable default.', 'wpsqr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-perpage"><?php esc_html_e( 'Results per page', 'wpsqr' ); ?></label></th>
						<td><input type="number" id="wpsqr-perpage" name="per_page" min="1" max="100" value="<?php echo esc_attr( $s['per_page'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Warming', 'wpsqr' ); ?></th>
						<td>
							<label><input type="checkbox" name="warm_enabled" value="1" <?php checked( $s['warm_enabled'], 1 ); ?>>
								<?php esc_html_e( 'Re-run the most popular searches in the background every 15 minutes', 'wpsqr' ); ?></label>
							<p><label><?php esc_html_e( 'How many terms:', 'wpsqr' ); ?>
								<input type="number" name="warm_count" min="1" max="200" value="<?php echo esc_attr( $s['warm_count'] ); ?>" class="small-text"></label></p>
							<p class="description"><?php esc_html_e( 'Needs WP-Cron to be running. On a site with cron disabled this does nothing and the first visitor after each save pays full price.', 'wpsqr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Recording', 'wpsqr' ); ?></th>
						<td>
							<label><input type="checkbox" name="observe" value="1" <?php checked( $s['observe'], 1 ); ?>>
								<?php esc_html_e( 'Record every search, even ones this plugin does not render', 'wpsqr' ); ?></label>
							<p class="description"><?php esc_html_e( 'Leave this on. It is what lets the dashboard tell you whether caching is worth switching on, before you switch it on.', 'wpsqr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Timing', 'wpsqr' ); ?></th>
						<td><label><input type="checkbox" name="show_timing" value="1" <?php checked( $s['show_timing'], 1 ); ?>>
							<?php esc_html_e( 'Show editors how each result set was served, below the results', 'wpsqr' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'SearchWP', 'wpsqr' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Integration', 'wpsqr' ); ?></th>
						<td><label><input type="checkbox" name="searchwp_compat" value="1" <?php checked( $s['searchwp_compat'], 1 ); ?>>
							<?php esc_html_e( 'Redirect /?s= to the results page, link media at the file, and rank attachments last', 'wpsqr' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-path"><?php esc_html_e( 'Results page path', 'wpsqr' ); ?></label></th>
						<td><input type="text" id="wpsqr-path" name="results_path" value="<?php echo esc_attr( $s['results_path'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-param"><?php esc_html_e( 'Query parameter', 'wpsqr' ); ?></label></th>
						<td><input type="text" id="wpsqr-param" name="query_param" value="<?php echo esc_attr( $s['query_param'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'The parameter your search form submits. On this site: swps.', 'wpsqr' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-form"><?php esc_html_e( 'SearchWP form ID', 'wpsqr' ); ?></label></th>
						<td><input type="number" id="wpsqr-form" name="form_id" value="<?php echo esc_attr( $s['form_id'] ); ?>" class="small-text"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Hidden content', 'wpsqr' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpsqr-metakey"><?php esc_html_e( 'Hide-plugin meta key', 'wpsqr' ); ?></label></th>
						<td>
							<input type="text" id="wpsqr-metakey" name="hide_meta_key" value="<?php echo esc_attr( $s['hide_meta_key'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'The post meta key your hide-plugin writes. Results carrying it are removed before the page is built.', 'wpsqr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-metaval"><?php esc_html_e( 'Meta value', 'wpsqr' ); ?></label></th>
						<td><input type="text" id="wpsqr-metaval" name="hide_meta_value" value="<?php echo esc_attr( $s['hide_meta_value'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Leave empty to mean "any value".', 'wpsqr' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-ids"><?php esc_html_e( 'Always hide these posts', 'wpsqr' ); ?></label></th>
						<td><textarea id="wpsqr-ids" name="manual_ids" rows="4" class="large-text code" placeholder="123"><?php echo esc_textarea( implode( "\n", (array) $s['manual_ids'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One post ID per line.', 'wpsqr' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Editors', 'wpsqr' ); ?></th>
						<td><label><input type="checkbox" name="admin_preview" value="1" <?php checked( $s['admin_preview'], 1 ); ?>>
							<?php esc_html_e( 'Let editors still see hidden results, marked', 'wpsqr' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Result rules', 'wpsqr' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Hide post types', 'wpsqr' ); ?></th>
						<td>
							<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : ?>
								<label style="margin-inline-end:1.2em">
									<input type="checkbox" name="block_types[]" value="<?php echo esc_attr( $type->name ); ?>"
										<?php checked( in_array( $type->name, (array) $s['block_types'], true ) ); ?>>
									<?php echo esc_html( $type->labels->name ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Ticking Media removes indexed PDFs and images from results entirely.', 'wpsqr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-burls"><?php esc_html_e( 'Hide URLs containing', 'wpsqr' ); ?></label></th>
						<td><textarea id="wpsqr-burls" name="block_urls" rows="4" class="large-text code" placeholder="/staff-only/"><?php echo esc_textarea( implode( "\n", (array) $s['block_urls'] ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-btitles"><?php esc_html_e( 'Hide titles containing', 'wpsqr' ); ?></label></th>
						<td><textarea id="wpsqr-btitles" name="block_titles" rows="4" class="large-text code" placeholder="Internal"><?php echo esc_textarea( implode( "\n", (array) $s['block_titles'] ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-bq"><?php esc_html_e( 'Return nothing for these searches', 'wpsqr' ); ?></label></th>
						<td>
							<textarea id="wpsqr-bq" name="blocked_queries" rows="5" class="large-text code" placeholder="equals: staff directory&#10;contains: payroll"><?php
								$lines = array();
								foreach ( (array) $s['blocked_queries'] as $rule ) {
									$lines[] = $rule['op'] . ': ' . $rule['value'];
								}
								echo esc_textarea( implode( "\n", $lines ) );
							?></textarea>
							<p class="description">
								<?php esc_html_e( 'One per line, as "equals: phrase" or "contains: word". These never reach the search engine at all.', 'wpsqr' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-empty"><?php esc_html_e( 'No-results message', 'wpsqr' ); ?></label></th>
						<td><input type="text" id="wpsqr-empty" name="empty_message" value="<?php echo esc_attr( $s['empty_message'] ); ?>" class="large-text"></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---- Handlers ------------------------------------------------------ */

	public function handle_save() {
		check_admin_referer( 'wpsqr_save' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'wpsqr' ) );
		}

		$in  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$new = WPSQR_Plugin::defaults();

		$new['ttl']         = max( 60, (int) ( $in['ttl'] ?? 0 ) );
		$new['per_page']    = max( 1, min( 100, (int) ( $in['per_page'] ?? 20 ) ) );
		$new['warm_count']  = max( 1, min( 200, (int) ( $in['warm_count'] ?? 25 ) ) );
		$new['form_id']     = (int) ( $in['form_id'] ?? 0 );

		$new['warm_enabled']    = empty( $in['warm_enabled'] ) ? 0 : 1;
		$new['observe']         = empty( $in['observe'] ) ? 0 : 1;
		$new['show_timing']     = empty( $in['show_timing'] ) ? 0 : 1;
		$new['searchwp_compat'] = empty( $in['searchwp_compat'] ) ? 0 : 1;
		$new['admin_preview']   = empty( $in['admin_preview'] ) ? 0 : 1;

		$new['query_param']     = sanitize_key( $in['query_param'] ?? 'swps' );
		$new['results_path']    = '/' . trim( sanitize_text_field( $in['results_path'] ?? '/search/' ), '/' ) . '/';
		$new['hide_meta_key']   = sanitize_text_field( $in['hide_meta_key'] ?? '' );
		$new['hide_meta_value'] = sanitize_text_field( $in['hide_meta_value'] ?? '' );
		$new['empty_message']   = sanitize_text_field( $in['empty_message'] ?? '' );

		$new['manual_ids']   = array_values( array_filter( array_map( 'intval', self::lines( $in['manual_ids'] ?? '' ) ) ) );
		$new['block_urls']   = self::lines( $in['block_urls'] ?? '' );
		$new['block_titles'] = self::lines( $in['block_titles'] ?? '' );

		$types               = isset( $in['block_types'] ) ? (array) $in['block_types'] : array();
		$new['block_types']  = array_values( array_map( 'sanitize_key', $types ) );

		$new['blocked_queries'] = array();
		foreach ( self::lines( $in['blocked_queries'] ?? '' ) as $line ) {
			$parts = array_map( 'trim', explode( ':', $line, 2 ) );
			if ( count( $parts ) === 2 && '' !== $parts[1] ) {
				$new['blocked_queries'][] = array(
					'op'      => in_array( strtolower( $parts[0] ), array( 'equals', 'contains' ), true ) ? strtolower( $parts[0] ) : 'contains',
					'value'   => $parts[1],
					'message' => '',
				);
			}
		}

		WPSQR_Plugin::update( $new );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=wpsqr-settings' ) ) );
		exit;
	}

	public function handle_flush() {
		check_admin_referer( 'wpsqr_flush' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'wpsqr' ) );
		}

		WPSQR_Cache::flush();

		wp_safe_redirect( add_query_arg( 'flushed', '1', admin_url( 'admin.php?page=wpsqr' ) ) );
		exit;
	}

	public function handle_warm() {
		check_admin_referer( 'wpsqr_warm' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'wpsqr' ) );
		}

		$result = WPSQR_Warmer::run();

		wp_safe_redirect( add_query_arg( 'warmed', (int) $result['warmed'], admin_url( 'admin.php?page=wpsqr' ) ) );
		exit;
	}

	protected static function lines( $text ) {
		$out = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
