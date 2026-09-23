<?php
/**
 * Getting Started hub view. Rendered by ACPS_LS_Help::render_page().
 *
 * Available vars: $add_url, $settings_url, $checker_url.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$acps_ls_short_example = trailingslashit( acps_ls_link_base() ) . 'open-house';
?>
<div class="wrap acps-ls-wrap acps-ls-help">

	<div id="acps-ls-help-hero" class="acps-ls-hero">
		<div class="acps-ls-hero-text">
			<h1><?php esc_html_e( 'Link Shortener — Getting Started', 'acps-link-shortener' ); ?></h1>
			<p class="acps-ls-lead"><?php esc_html_e( 'Turn long web addresses into short, tidy links you control. No experience needed — this page walks you through everything, and the guided tour points at each button on the real screens.', 'acps-link-shortener' ); ?></p>
			<p>
				<button type="button" class="button button-primary button-hero" data-acps-tour-start="full"><?php esc_html_e( '▶  Start the guided tour', 'acps-link-shortener' ); ?></button>
				<a href="<?php echo esc_url( $add_url ); ?>" class="button button-hero"><?php esc_html_e( '＋  Make a link now', 'acps-link-shortener' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'The tour is safe: it only explains and highlights things. It never changes anything on its own.', 'acps-link-shortener' ); ?></p>
		</div>
		<div class="acps-ls-hero-art" aria-hidden="true">
			<?php echo acps_ls_help_svg( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static trusted SVG. ?>
		</div>
	</div>

	<!-- 30-second walkthrough -->
	<h2 class="acps-ls-h2"><?php esc_html_e( 'Make your first short link in 30 seconds', 'acps-link-shortener' ); ?></h2>
	<div class="acps-ls-steps">
		<div class="acps-ls-step">
			<div class="acps-ls-step-num">1</div>
			<h3><?php esc_html_e( 'Open “Add New Link”', 'acps-link-shortener' ); ?></h3>
			<p><?php esc_html_e( 'In the left menu, click Link Shortener → Add New Link.', 'acps-link-shortener' ); ?></p>
		</div>
		<div class="acps-ls-step">
			<div class="acps-ls-step-num">2</div>
			<h3><?php esc_html_e( 'Paste the long link', 'acps-link-shortener' ); ?></h3>
			<p><?php esc_html_e( 'Put the full web address in the Destination box — the page you want people to land on.', 'acps-link-shortener' ); ?></p>
		</div>
		<div class="acps-ls-step">
			<div class="acps-ls-step-num">3</div>
			<h3><?php esc_html_e( 'Choose the ending', 'acps-link-shortener' ); ?></h3>
			<p><?php
				/* translators: %s: example short URL. */
				printf( esc_html__( 'Type a short word in Slug, e.g. “open-house”. That makes %s.', 'acps-link-shortener' ), '<code>' . esc_html( $acps_ls_short_example ) . '</code>' );
			?></p>
		</div>
		<div class="acps-ls-step">
			<div class="acps-ls-step-num">4</div>
			<h3><?php esc_html_e( 'Click Create Link', 'acps-link-shortener' ); ?></h3>
			<p><?php esc_html_e( 'Done! The short link works immediately. Share it anywhere.', 'acps-link-shortener' ); ?></p>
		</div>
	</div>
	<div class="acps-ls-diagram" aria-hidden="true">
		<?php echo acps_ls_help_svg( 'flow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<!-- What each screen does -->
	<h2 class="acps-ls-h2"><?php esc_html_e( 'What each screen is for', 'acps-link-shortener' ); ?></h2>
	<div class="acps-ls-cards">

		<div class="acps-ls-card">
			<div class="acps-ls-card-ico" aria-hidden="true"><?php echo acps_ls_help_svg( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<h3><?php esc_html_e( 'All Links', 'acps-link-shortener' ); ?></h3>
			<p><?php esc_html_e( 'Every link you have made, with click counts. Edit, turn off, or delete from here.', 'acps-link-shortener' ); ?></p>
			<button type="button" class="button" data-acps-tour-start="links"><?php esc_html_e( 'Show me', 'acps-link-shortener' ); ?></button>
		</div>

		<div class="acps-ls-card">
			<div class="acps-ls-card-ico" aria-hidden="true"><?php echo acps_ls_help_svg( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<h3><?php esc_html_e( 'Add New Link', 'acps-link-shortener' ); ?></h3>
			<p><?php esc_html_e( 'The simple form to create a short link. Destination + a short ending is all you need.', 'acps-link-shortener' ); ?></p>
			<button type="button" class="button" data-acps-tour-start="add"><?php esc_html_e( 'Show me', 'acps-link-shortener' ); ?></button>
		</div>

		<div class="acps-ls-card">
			<div class="acps-ls-card-ico" aria-hidden="true"><?php echo acps_ls_help_svg( 'gear' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<h3><?php esc_html_e( 'Settings', 'acps-link-shortener' ); ?></h3>
			<p><?php esc_html_e( 'Optional extras: a custom short domain, staff accounts, sheet sync, and the link checker schedule.', 'acps-link-shortener' ); ?></p>
			<button type="button" class="button" data-acps-tour-start="settings"><?php esc_html_e( 'Show me', 'acps-link-shortener' ); ?></button>
		</div>

		<div class="acps-ls-card">
			<div class="acps-ls-card-ico" aria-hidden="true"><?php echo acps_ls_help_svg( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<h3><?php esc_html_e( 'Link Manager', 'acps-link-shortener' ); ?></h3>
			<p><?php esc_html_e( 'Automatically checks your links still work and flags broken ones — with how long they have been broken.', 'acps-link-shortener' ); ?></p>
			<button type="button" class="button" data-acps-tour-start="checker"><?php esc_html_e( 'Show me', 'acps-link-shortener' ); ?></button>
		</div>

	</div>

	<!-- Staff / shortcode -->
	<h2 class="acps-ls-h2"><?php esc_html_e( 'Let staff make links (optional)', 'acps-link-shortener' ); ?></h2>
	<div class="acps-ls-panel">
		<ol>
			<li><?php
				/* translators: %s: shortcode. */
				printf( esc_html__( 'Create a normal WordPress page and add this shortcode to it: %s', 'acps-link-shortener' ), '<code>[acps_link_shortener]</code>' );
			?></li>
			<li><?php
				/* translators: %s: Settings link. */
				printf( wp_kses_post( __( 'In %s, add each person a name and password (or send a one-time setup link so they set their own).', 'acps-link-shortener' ) ), '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings → Link Shortener', 'acps-link-shortener' ) . '</a>' );
			?></li>
			<li><?php esc_html_e( 'Staff visit that page, sign in, and create/manage their own links — no admin access needed.', 'acps-link-shortener' ); ?></li>
		</ol>
	</div>

	<!-- FAQ -->
	<h2 class="acps-ls-h2"><?php esc_html_e( 'Common questions', 'acps-link-shortener' ); ?></h2>
	<div class="acps-ls-faq">
		<details>
			<summary><?php esc_html_e( 'My new link shows “Not Found”. What do I do?', 'acps-link-shortener' ); ?></summary>
			<p><?php esc_html_e( 'Go to Settings → Permalinks and click Save once (you don’t have to change anything). That refreshes WordPress’ address list. Also make sure the ending you chose isn’t already a real page on your site.', 'acps-link-shortener' ); ?></p>
		</details>
		<details>
			<summary><?php esc_html_e( 'Can I change where a link points after I make it?', 'acps-link-shortener' ); ?></summary>
			<p><?php esc_html_e( 'No — that’s on purpose, so a short link always goes to exactly one place. Just make a new link for the new destination.', 'acps-link-shortener' ); ?></p>
		</details>
		<details>
			<summary><?php esc_html_e( 'What’s the difference between permanent and temporary?', 'acps-link-shortener' ); ?></summary>
			<p><?php esc_html_e( 'Temporary (302) is the safe default and lets you retire a link later. Permanent (301) tells browsers to remember it forever — best only when you’re sure it will never change.', 'acps-link-shortener' ); ?></p>
		</details>
		<details>
			<summary><?php esc_html_e( 'Is anything going to break my site?', 'acps-link-shortener' ); ?></summary>
			<p><?php esc_html_e( 'No. The plugin is built to fail safely: if a file is missing or an error happens, it pauses itself and shows a notice instead of taking the site down.', 'acps-link-shortener' ); ?></p>
		</details>
	</div>

	<p class="acps-ls-replay">
		<button type="button" class="button button-primary" data-acps-tour-start="full"><?php esc_html_e( '▶  Replay the full guided tour', 'acps-link-shortener' ); ?></button>
	</p>
</div>
<?php
/**
 * Tiny inline SVG icon set for the help page. Uses currentColor so it adapts to
 * light/dark admin schemes. Returns trusted static markup.
 *
 * @param string $name Icon name.
 * @return string
 */
function acps_ls_help_svg( $name ) {
	$icons = array(
		'link'  => '<svg viewBox="0 0 120 80" width="180" height="120" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"><rect x="6" y="30" width="48" height="20" rx="10"/><rect x="66" y="30" width="48" height="20" rx="10"/><line x1="40" y1="40" x2="80" y2="40"/></svg>',
		'flow'  => '<svg viewBox="0 0 520 90" width="100%" height="90" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="24" width="150" height="42" rx="8"/><text x="79" y="50" fill="currentColor" stroke="none" font-size="13" text-anchor="middle">long-url.example/very/long</text><path d="M160 45 h40" stroke-width="3" marker-end="url(#a)"/><rect x="206" y="24" width="120" height="42" rx="8"/><text x="266" y="50" fill="currentColor" stroke="none" font-size="13" text-anchor="middle">Shortener</text><path d="M332 45 h40" stroke-width="3" marker-end="url(#a)"/><rect x="378" y="24" width="138" height="42" rx="8"/><text x="447" y="50" fill="currentColor" stroke="none" font-size="13" text-anchor="middle">site.org/open-house</text><defs><marker id="a" markerWidth="8" markerHeight="8" refX="6" refY="4" orient="auto"><path d="M0 0 L8 4 L0 8 z" fill="currentColor" stroke="none"/></marker></defs></svg>',
		'list'  => '<svg viewBox="0 0 48 48" width="40" height="40" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><circle cx="10" cy="14" r="2.5"/><line x1="18" y1="14" x2="40" y2="14"/><circle cx="10" cy="24" r="2.5"/><line x1="18" y1="24" x2="40" y2="24"/><circle cx="10" cy="34" r="2.5"/><line x1="18" y1="34" x2="40" y2="34"/></svg>',
		'plus'  => '<svg viewBox="0 0 48 48" width="40" height="40" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><circle cx="24" cy="24" r="18"/><line x1="24" y1="15" x2="24" y2="33"/><line x1="15" y1="24" x2="33" y2="24"/></svg>',
		'gear'  => '<svg viewBox="0 0 48 48" width="40" height="40" fill="none" stroke="currentColor" stroke-width="3"><circle cx="24" cy="24" r="7"/><path d="M24 4v6M24 38v6M4 24h6M38 24h6M10 10l4 4M34 34l4 4M38 10l-4 4M14 34l-4 4" stroke-linecap="round"/></svg>',
		'shield'=> '<svg viewBox="0 0 48 48" width="40" height="40" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round"><path d="M24 5l16 6v10c0 11-7 18-16 22-9-4-16-11-16-22V11z"/><path d="M16 24l6 6 10-12" stroke-linecap="round"/></svg>',
	);
	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}
