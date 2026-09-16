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
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_wpsqr_flush', array( $this, 'handle_flush' ) );
		add_action( 'admin_post_wpsqr_warm', array( $this, 'handle_warm' ) );
		add_action( 'admin_post_wpsqr_save', array( $this, 'handle_save' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'wpsqr' ) ) {
			return;
		}

		wp_enqueue_style( 'wpsqr-admin', WPSQR_URL . 'assets/css/admin.css', array(), WPSQR_VERSION );
		wp_enqueue_script( 'wpsqr-admin-rules', WPSQR_URL . 'assets/js/admin-rules.js', array(), WPSQR_VERSION, true );
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

				<h2><?php esc_html_e( 'People results', 'wpsqr' ); ?></h2>
				<p class="wpsqr-hint">
					<?php esc_html_e( 'When someone searches a name, show that person above the ordinary results instead of the directory page they appear on. Requires a staff directory plugin that implements the integration — see INTEGRATION.md in the plugin folder.', 'wpsqr' ); ?>
					<?php if ( ! WPSQR_People::has_provider() ) : ?>
						<br><strong><?php esc_html_e( 'No directory plugin is connected yet, so these settings do nothing so far.', 'wpsqr' ); ?></strong>
					<?php endif; ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'People results', 'wpsqr' ); ?></th>
						<td><label><input type="checkbox" name="people_enabled" value="1" <?php checked( $s['people_enabled'], 1 ); ?>>
							<?php esc_html_e( 'Show matching people above search results', 'wpsqr' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-people-limit"><?php esc_html_e( 'How many', 'wpsqr' ); ?></label></th>
						<td>
							<input type="number" id="wpsqr-people-limit" name="people_limit" min="1" max="50" value="<?php echo esc_attr( $s['people_limit'] ); ?>" class="small-text">
							<?php esc_html_e( 'people at most', 'wpsqr' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-people-min"><?php esc_html_e( 'Minimum term length', 'wpsqr' ); ?></label></th>
						<td>
							<input type="number" id="wpsqr-people-min" name="people_min_chars" min="2" max="10" value="<?php echo esc_attr( $s['people_min_chars'] ); ?>" class="small-text">
							<?php esc_html_e( 'characters', 'wpsqr' ); ?>
							<p class="description"><?php esc_html_e( 'Shorter searches match half the directory and help nobody, so they are not sent to it at all.', 'wpsqr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-people-heading"><?php esc_html_e( 'Heading', 'wpsqr' ); ?></label></th>
						<td>
							<input type="text" id="wpsqr-people-heading" name="people_heading" value="<?php echo esc_attr( $s['people_heading'] ); ?>" class="large-text">
							<p class="description"><?php esc_html_e( '{query} is replaced with what was searched for. Leave empty for no heading.', 'wpsqr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-people-more"><?php esc_html_e( 'Full directory link', 'wpsqr' ); ?></label></th>
						<td>
							<input type="text" id="wpsqr-people-more" name="people_more_url" value="<?php echo esc_attr( $s['people_more_url'] ); ?>" class="large-text" placeholder="/staff/directory/">
							<p class="description"><?php esc_html_e( 'Shown under the people, for searches with more matches than fit. Leave empty to omit.', 'wpsqr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Contact details', 'wpsqr' ); ?></th>
						<td>
							<label><input type="checkbox" name="people_show_email" value="1" <?php checked( $s['people_show_email'], 1 ); ?>>
								<?php esc_html_e( 'Show email addresses', 'wpsqr' ); ?></label><br>
							<label><input type="checkbox" name="people_show_phone" value="1" <?php checked( $s['people_show_phone'], 1 ); ?>>
								<?php esc_html_e( 'Show phone numbers', 'wpsqr' ); ?></label>
							<p class="description">
								<?php esc_html_e( 'Off by default, and worth leaving off unless you have decided otherwise. A directory page publishing an address is a decision about that page; repeating it across search results, for anyone who types a common surname, is a different one.', 'wpsqr' ); ?>
							</p>
						</td>
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
				<p class="wpsqr-hint">
					<?php esc_html_e( 'Rules run top to bottom against each result. "Hide" and "Keep" are final — an early Keep protects a result from every rule below it.', 'wpsqr' ); ?>
					<br>
					<?php esc_html_e( 'Use "+ and/or…" to add conditions. Every "and" must match; if you add any "or" conditions, at least one of those must match too. "The search" tests what the visitor typed, so a rule can depend on the search as well as the result.', 'wpsqr' ); ?>
					<br>
					<?php
					printf(
						/* translators: %s: list of placeholder variables */
						esc_html__( 'Values and output text understand %s.', 'wpsqr' ),
						'<code>{query}</code>, <code>{title}</code>, <code>{desc}</code>, <code>{url}</code>, <code>{type}</code>, <code>{id}</code>'
					);
					?>
					<br>
					<?php esc_html_e( 'Hide and Keep run on the server — and so never reach the browser — as long as every condition tests Title, URL, ID, Post type or the search. A condition on the description or full text moves the whole rule into the browser.', 'wpsqr' ); ?>
				</p>

				<?php $this->rule_table( 'result_rules', (array) $s['result_rules'] ); ?>

				<h2><?php esc_html_e( 'Search term rules', 'wpsqr' ); ?></h2>
				<p class="wpsqr-hint">
					<?php esc_html_e( 'These act on what the visitor typed, before any result is looked at. First match wins. A blocked term never reaches the search engine, which also makes it the fastest possible search.', 'wpsqr' ); ?>
				</p>

				<?php $this->query_rule_table( 'query_rules', (array) $s['query_rules'] ); ?>

				<h2><?php esc_html_e( 'Presentation', 'wpsqr' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Filtered results', 'wpsqr' ); ?></th>
						<td>
							<label><input type="radio" name="hide_mode" value="remove" <?php checked( $s['hide_mode'], 'remove' ); ?>>
								<?php esc_html_e( 'Remove them from the page', 'wpsqr' ); ?></label><br>
							<label><input type="radio" name="hide_mode" value="dim" <?php checked( $s['hide_mode'], 'dim' ); ?>>
								<?php esc_html_e( 'Grey out and label them', 'wpsqr' ); ?></label>
							<p class="description"><?php esc_html_e( 'Greying out is for checking your rules catch what you expect. It only affects browser-side rules — a server-side Hide has already removed the result.', 'wpsqr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Result count', 'wpsqr' ); ?></th>
						<td>
							<label><input type="checkbox" name="update_count" value="1" <?php checked( $s['update_count'], 1 ); ?>>
								<?php esc_html_e( 'Correct the "Found 271 results" notice for anything filtered in the browser', 'wpsqr' ); ?></label>
							<p class="description"><?php esc_html_e( 'The notice counts the whole result set while the page shows one page of it, so the number is reduced by what was filtered rather than replaced. Turn this off to leave it alone entirely.', 'wpsqr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-words"><?php esc_html_e( 'Description length', 'wpsqr' ); ?></label></th>
						<td>
							<input type="number" id="wpsqr-words" name="excerpt_words" min="5" max="200" value="<?php echo esc_attr( $s['excerpt_words'] ); ?>" class="small-text">
							<?php esc_html_e( 'words', 'wpsqr' ); ?>
							<p class="description"><?php esc_html_e( 'Applies to descriptions this plugin builds. A page with its own excerpt, or a search description written on the edit screen, is used as written.', 'wpsqr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpsqr-desckey"><?php esc_html_e( 'Search description field', 'wpsqr' ); ?></label></th>
						<td>
							<input type="text" id="wpsqr-desckey" name="desc_meta_key" value="<?php echo esc_attr( $s['desc_meta_key'] ); ?>" class="regular-text">
							<p class="description">
								<?php esc_html_e( 'Meta key holding a per-page search description. Leave as-is unless you already store one elsewhere — set it to an existing key (a SEO plugin\'s, say) to reuse those.', 'wpsqr' ); ?>
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

	/* ---- Rule builder -------------------------------------------------- */

	protected function rule_table( $name, $rules ) {
		$fields = array(
			'title'   => __( 'Title', 'wpsqr' ),
			'url'     => __( 'URL path', 'wpsqr' ),
			'id'      => __( 'Post ID', 'wpsqr' ),
			'type'    => __( 'Post type', 'wpsqr' ),
			'excerpt' => __( 'Excerpt', 'wpsqr' ),
			'text'    => __( 'Anything in the row', 'wpsqr' ),
		);

		$actions = array(
			'hide'    => __( 'Hide it', 'wpsqr' ),
			'keep'    => __( 'Keep it (protect from later rules)', 'wpsqr' ),
			'dim'     => __( 'Grey it out', 'wpsqr' ),
			'rewrite' => __( 'Find and replace text', 'wpsqr' ),
			'setDesc' => __( 'Replace the description', 'wpsqr' ),
			'badge'   => __( 'Add a badge', 'wpsqr' ),
			'top'     => __( 'Move to the top', 'wpsqr' ),
			'bottom'  => __( 'Move to the bottom', 'wpsqr' ),
		);

		$this->render_table( $name, $rules, $fields, $actions );
	}

	protected function query_rule_table( $name, $rules ) {
		$actions = array(
			'noResults' => __( 'Return no results', 'wpsqr' ),
			'notice'    => __( 'Show a notice above the results', 'wpsqr' ),
			'redirect'  => __( 'Send them to a page instead', 'wpsqr' ),
			'allow'     => __( 'Allow (shield from rules below)', 'wpsqr' ),
		);

		$this->render_table( $name, $rules, null, $actions );
	}

	protected function render_table( $name, $rules, $fields, $actions ) {
		$ops = self::operators();
		?>
		<div class="wpsqr-rules" data-wpsqr-rules data-name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( array_values( $rules ) as $i => $rule ) : ?>
				<?php $this->render_rule( $name, $i, $rule, $fields, $actions, $ops ); ?>
			<?php endforeach; ?>
		</div>

		<p class="wpsqr-no-rules"><?php esc_html_e( 'No rules yet.', 'wpsqr' ); ?></p>

		<p>
			<button type="button" class="button" data-add-rule="<?php echo esc_attr( $name ); ?>">
				<?php esc_html_e( '+ Add rule', 'wpsqr' ); ?>
			</button>
		</p>

		<template id="wpsqr-template-<?php echo esc_attr( $name ); ?>">
			<?php $this->render_rule( $name, 0, array(), $fields, $actions, $ops, true ); ?>
		</template>

		<template id="wpsqr-cond-<?php echo esc_attr( $name ); ?>">
			<?php $this->render_condition( $name . '[0][conds][0]', array(), $fields, $ops ); ?>
		</template>
		<?php
	}

	protected static function operators() {
		return array(
			'contains' => __( 'contains', 'wpsqr' ),
			'equals'   => __( 'is exactly', 'wpsqr' ),
			'starts'   => __( 'starts with', 'wpsqr' ),
			'ends'     => __( 'ends with', 'wpsqr' ),
			'regex'    => __( 'matches pattern', 'wpsqr' ),
			'in'       => __( 'is any of (comma separated)', 'wpsqr' ),
		);
	}

	/** Fields a condition can test. Query rules only ever look at the search. */
	protected static function condition_fields( $fields ) {
		if ( ! $fields ) {
			return array( 'query' => __( 'the search', 'wpsqr' ) );
		}

		return $fields + array( 'query' => __( 'the search', 'wpsqr' ) );
	}

	protected function render_rule( $name, $i, $rule, $fields, $actions, $ops, $is_template = false ) {
		$base = $name . '[' . $i . ']';
		$get  = function ( $key, $default = '' ) use ( $rule ) {
			return isset( $rule[ $key ] ) ? $rule[ $key ] : $default;
		};
		?>
		<div class="wpsqr-rule">
			<div class="wpsqr-rule-head">
				<span class="wpsqr-when-label"><?php esc_html_e( 'When', 'wpsqr' ); ?></span>

				<?php if ( $fields ) : ?>
					<select name="<?php echo esc_attr( $base ); ?>[when]">
						<?php foreach ( self::condition_fields( $fields ) as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $get( 'when', 'title' ), $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>

				<label class="wpsqr-not">
					<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[not]" value="1" <?php checked( $get( 'not' ), 1 ); ?>>
					<?php esc_html_e( 'not', 'wpsqr' ); ?>
				</label>

				<select name="<?php echo esc_attr( $base ); ?>[op]">
					<?php foreach ( $ops as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $get( 'op', 'contains' ), $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<input type="text" class="wpsqr-value" name="<?php echo esc_attr( $base ); ?>[value]"
					value="<?php echo esc_attr( $get( 'value' ) ); ?>"
					placeholder="<?php esc_attr_e( 'Edited Hidden', 'wpsqr' ); ?>">

				<button type="button" class="wpsqr-remove" title="<?php esc_attr_e( 'Remove this rule', 'wpsqr' ); ?>">&times;</button>
			</div>

			<div class="wpsqr-conds">
				<?php foreach ( array_values( (array) $get( 'conds', array() ) ) as $ci => $cond ) : ?>
					<?php $this->render_condition( $base . '[conds][' . $ci . ']', $cond, $fields, $ops ); ?>
				<?php endforeach; ?>
			</div>

			<div class="wpsqr-rule-foot">
				<button type="button" class="button-link wpsqr-add-cond"><?php esc_html_e( '+ and/or…', 'wpsqr' ); ?></button>

				<span class="wpsqr-then-label"><?php esc_html_e( 'then', 'wpsqr' ); ?></span>

				<select class="wpsqr-then" name="<?php echo esc_attr( $base ); ?>[then]">
					<?php foreach ( $actions as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $get( 'then' ), $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select data-extra="target" name="<?php echo esc_attr( $base ); ?>[target]" hidden>
					<option value="title" <?php selected( $get( 'target', 'title' ), 'title' ); ?>><?php esc_html_e( 'in the title', 'wpsqr' ); ?></option>
					<option value="desc" <?php selected( $get( 'target' ), 'desc' ); ?>><?php esc_html_e( 'in the description', 'wpsqr' ); ?></option>
					<option value="both" <?php selected( $get( 'target' ), 'both' ); ?>><?php esc_html_e( 'in both', 'wpsqr' ); ?></option>
				</select>

				<input type="text" data-extra="replace" name="<?php echo esc_attr( $base ); ?>[replace]"
					value="<?php echo esc_attr( $get( 'replace' ) ); ?>" placeholder="<?php esc_attr_e( 'replace with…', 'wpsqr' ); ?>" hidden>
				<input type="text" data-extra="desc" name="<?php echo esc_attr( $base ); ?>[desc]"
					value="<?php echo esc_attr( $get( 'desc' ) ); ?>" placeholder="<?php esc_attr_e( 'Results for {query}', 'wpsqr' ); ?>" hidden>
				<input type="text" data-extra="label" name="<?php echo esc_attr( $base ); ?>[label]"
					value="<?php echo esc_attr( $get( 'label' ) ); ?>" placeholder="<?php esc_attr_e( 'badge text', 'wpsqr' ); ?>" hidden>
				<input type="text" data-extra="message" name="<?php echo esc_attr( $base ); ?>[message]"
					value="<?php echo esc_attr( $get( 'message' ) ); ?>" placeholder="<?php esc_attr_e( 'Nothing found for {query}', 'wpsqr' ); ?>" hidden>
				<input type="text" data-extra="url" name="<?php echo esc_attr( $base ); ?>[url]"
					value="<?php echo esc_attr( $get( 'url' ) ); ?>" placeholder="/menus/" hidden>
			</div>
		</div>
		<?php
	}

	protected function render_condition( $base, $cond, $fields, $ops ) {
		$get = function ( $key, $default = '' ) use ( $cond ) {
			return isset( $cond[ $key ] ) ? $cond[ $key ] : $default;
		};
		?>
		<div class="wpsqr-cond">
			<select class="wpsqr-join" name="<?php echo esc_attr( $base ); ?>[join]">
				<option value="and" <?php selected( $get( 'join', 'and' ), 'and' ); ?>><?php esc_html_e( 'and', 'wpsqr' ); ?></option>
				<option value="any" <?php selected( $get( 'join' ), 'any' ); ?>><?php esc_html_e( 'or', 'wpsqr' ); ?></option>
			</select>

			<select name="<?php echo esc_attr( $base ); ?>[when]">
				<?php foreach ( self::condition_fields( $fields ) as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $get( 'when', 'query' ), $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="wpsqr-not">
				<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[not]" value="1" <?php checked( $get( 'not' ), 1 ); ?>>
				<?php esc_html_e( 'not', 'wpsqr' ); ?>
			</label>

			<select name="<?php echo esc_attr( $base ); ?>[op]">
				<?php foreach ( $ops as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $get( 'op', 'contains' ), $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input type="text" class="wpsqr-value" name="<?php echo esc_attr( $base ); ?>[value]"
				value="<?php echo esc_attr( $get( 'value' ) ); ?>" placeholder="<?php esc_attr_e( 'staff', 'wpsqr' ); ?>">

			<button type="button" class="wpsqr-remove wpsqr-remove-cond" title="<?php esc_attr_e( 'Remove this condition', 'wpsqr' ); ?>">&times;</button>
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

		$new['people_enabled']    = empty( $in['people_enabled'] ) ? 0 : 1;
		$new['people_show_email'] = empty( $in['people_show_email'] ) ? 0 : 1;
		$new['people_show_phone'] = empty( $in['people_show_phone'] ) ? 0 : 1;
		$new['people_limit']      = max( 1, min( 50, (int) ( $in['people_limit'] ?? 5 ) ) );
		$new['people_min_chars']  = max( 2, min( 10, (int) ( $in['people_min_chars'] ?? 3 ) ) );
		$new['people_heading']    = sanitize_text_field( $in['people_heading'] ?? '' );
		$new['people_more_url']   = esc_url_raw( $in['people_more_url'] ?? '' );

		$new['manual_ids']   = array_values( array_filter( array_map( 'intval', self::lines( $in['manual_ids'] ?? '' ) ) ) );
		$new['hide_mode']     = in_array( $in['hide_mode'] ?? '', array( 'remove', 'dim' ), true ) ? $in['hide_mode'] : 'remove';
		$new['excerpt_words'] = max( 5, min( 200, (int) ( $in['excerpt_words'] ?? 40 ) ) );
		$new['desc_meta_key'] = sanitize_text_field( $in['desc_meta_key'] ?? '' );
		$new['update_count'] = empty( $in['update_count'] ) ? 0 : 1;

		$new['result_rules'] = self::sanitize_rules( $in['result_rules'] ?? array(), true );
		$new['query_rules']  = self::sanitize_rules( $in['query_rules'] ?? array(), false );

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

	/**
	 * Clean a submitted rule set.
	 *
	 * Rows with an empty value are dropped rather than stored — an empty
	 * rule would either match nothing (noise in the list) or, worse, match
	 * everything if a comparison were ever written carelessly.
	 *
	 * @param array $rows
	 * @param bool  $is_result True for result rules, false for query rules.
	 */
	protected static function sanitize_rules( $rows, $is_result ) {
		$clean = array();

		$actions = $is_result
			? WPSQR_Rules::ACTIONS
			: array( 'noResults', 'notice', 'redirect', 'allow' );

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$value = isset( $row['value'] ) ? trim( sanitize_text_field( $row['value'] ) ) : '';
			if ( '' === $value ) {
				continue;
			}

			$op   = isset( $row['op'] ) ? $row['op'] : 'contains';
			$then = isset( $row['then'] ) ? $row['then'] : ( $is_result ? 'hide' : 'noResults' );

			$rule = array(
				'op'    => in_array( $op, WPSQR_Rules::OPS, true ) ? $op : 'contains',
				'value' => $value,
				'then'  => in_array( $then, $actions, true ) ? $then : ( $is_result ? 'hide' : 'noResults' ),
			);

			if ( $is_result ) {
				$when         = isset( $row['when'] ) ? $row['when'] : 'title';
				$rule['when'] = in_array( $when, WPSQR_Rules::FIELDS, true ) ? $when : 'title';
			}

			// A bad pattern would otherwise fail silently on every search.
			if ( 'regex' === $rule['op'] && false === @preg_match( '/' . str_replace( '/', '\/', $value ) . '/iu', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				continue;
			}

			if ( ! empty( $row['not'] ) ) {
				$rule['not'] = 1;
			}

			$rule['conds'] = self::sanitize_conditions( $row['conds'] ?? array(), $is_result );

			if ( ! $rule['conds'] ) {
				unset( $rule['conds'] );
			}

			if ( 'rewrite' === $rule['then'] ) {
				$target         = isset( $row['target'] ) ? $row['target'] : 'title';
				$rule['target'] = in_array( $target, array( 'title', 'desc', 'both' ), true ) ? $target : 'title';
			}

			foreach ( array( 'replace', 'label', 'message', 'desc' ) as $extra ) {
				if ( isset( $row[ $extra ] ) && '' !== trim( (string) $row[ $extra ] ) ) {
					$rule[ $extra ] = sanitize_text_field( $row[ $extra ] );
				}
			}

			if ( isset( $row['url'] ) && '' !== trim( (string) $row['url'] ) ) {
				$rule['url'] = esc_url_raw( $row['url'] );
			}

			$clean[] = $rule;
		}

		return $clean;
	}

	/** Extra conditions hanging off a rule. */
	protected static function sanitize_conditions( $rows, $is_result ) {
		$clean = array();

		// A query rule can only ever test the search itself.
		$allowed = $is_result ? WPSQR_Rules::FIELDS : array( 'query' );

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$value = isset( $row['value'] ) ? trim( sanitize_text_field( $row['value'] ) ) : '';

			// An empty condition would silently widen the rule it hangs off,
			// which is worse than it simply not being there.
			if ( '' === $value ) {
				continue;
			}

			$when = isset( $row['when'] ) ? $row['when'] : 'query';
			$op   = isset( $row['op'] ) ? $row['op'] : 'contains';

			$cond = array(
				'join'  => ( isset( $row['join'] ) && 'any' === $row['join'] ) ? 'any' : 'and',
				'when'  => in_array( $when, $allowed, true ) ? $when : $allowed[0],
				'op'    => in_array( $op, WPSQR_Rules::OPS, true ) ? $op : 'contains',
				'value' => $value,
			);

			if ( ! empty( $row['not'] ) ) {
				$cond['not'] = 1;
			}

			$clean[] = $cond;
		}

		return $clean;
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
