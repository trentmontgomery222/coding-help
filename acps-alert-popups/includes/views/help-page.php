<?php
/**
 * The Help & Tutorials screen.
 *
 * Rendered by ACPS_Alerts_Help::render_page(), which is the only thing that
 * includes this file, so $this is the help object.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

$acps_progress  = $this->progress();
$acps_checklist = $this->checklist();
$acps_tours     = $this->tours();
$acps_list_url  = admin_url( 'admin.php?page=' . ACPS_Alerts_Admin::MENU_SLUG );
$acps_new_url   = admin_url( 'admin.php?page=acps-alerts-new' );
?>
<div class="wrap acps-help">

	<h1><?php esc_html_e( 'Help &amp; Tutorials', 'acps-alert-popups' ); ?></h1>
	<p class="acps-help__lede">
		<?php esc_html_e( 'Everything you need to run site alerts, in plain language. Start with the guided tour — it points at the real controls and explains them one at a time.', 'acps-alert-popups' ); ?>
	</p>

	<?php // ---------- Guided tours ---------- ?>
	<div class="acps-help-tours">
		<?php foreach ( $acps_tours as $acps_tour_id => $acps_tour ) : ?>
			<?php $acps_done = ACPS_Alerts_Help::tour_done( $acps_tour_id ); ?>
			<div class="acps-tour-card<?php echo $acps_done ? ' is-done' : ''; ?>">
				<div class="acps-tour-card__icon" aria-hidden="true">
					<?php if ( $acps_done ) : ?>
						<span class="dashicons dashicons-yes-alt"></span>
					<?php else : ?>
						<span class="dashicons dashicons-controls-play"></span>
					<?php endif; ?>
				</div>
				<div class="acps-tour-card__body">
					<h2><?php echo esc_html( $acps_tour['title'] ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: %d: number of steps. */
							esc_html( _n( '%d step, on the real screens.', '%d steps, on the real screens.', count( $acps_tour['steps'] ), 'acps-alert-popups' ) ),
							count( $acps_tour['steps'] )
						);

						if ( $acps_done ) {
							echo ' <span class="acps-tour-card__flag">' . esc_html__( 'You have done this one.', 'acps-alert-popups' ) . '</span>';
						}
						?>
					</p>
					<button type="button" class="button button-primary" data-acps-tour="<?php echo esc_attr( $acps_tour_id ); ?>">
						<?php echo $acps_done ? esc_html__( 'Run it again', 'acps-alert-popups' ) : esc_html__( 'Start the tour', 'acps-alert-popups' ); ?>
					</button>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<?php // ---------- Setup checklist ---------- ?>
	<div class="acps-help-checklist">
		<div class="acps-help-checklist__head">
			<h2><?php esc_html_e( 'Your setup', 'acps-alert-popups' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: completed steps, 2: total steps. */
					esc_html__( '%1$d of %2$d done', 'acps-alert-popups' ),
					(int) $acps_progress['done'],
					(int) $acps_progress['total']
				);
				?>
			</p>
		</div>

		<div class="acps-progress" role="img" aria-label="
			<?php
			printf(
				/* translators: %d: percentage complete. */
				esc_attr__( 'Setup %d percent complete', 'acps-alert-popups' ),
				(int) $acps_progress['percent']
			);
			?>
		">
			<span class="acps-progress__bar" style="width:<?php echo esc_attr( $acps_progress['percent'] ); ?>%"></span>
		</div>

		<ol class="acps-checklist">
			<?php foreach ( $acps_checklist as $acps_item ) : ?>
				<li class="acps-checklist__item<?php echo $acps_item['done'] ? ' is-done' : ''; ?>">
					<span class="acps-checklist__mark" aria-hidden="true"><?php echo $acps_item['done'] ? '&#10003;' : ''; ?></span>
					<div class="acps-checklist__text">
						<strong>
							<?php echo esc_html( $acps_item['label'] ); ?>
							<span class="screen-reader-text">
								<?php echo $acps_item['done'] ? esc_html__( '(done)', 'acps-alert-popups' ) : esc_html__( '(not done yet)', 'acps-alert-popups' ); ?>
							</span>
						</strong>
						<span class="acps-checklist__why"><?php echo esc_html( $acps_item['why'] ); ?></span>
						<?php if ( ! $acps_item['done'] ) : ?>
							<span class="acps-checklist__fix"><?php echo esc_html( $acps_item['fix'] ); ?></span>
							<a class="button button-small" href="<?php echo esc_url( $acps_item['url'] ); ?>"><?php echo esc_html( $acps_item['cta'] ); ?></a>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>

	<?php // ---------- How it works ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'How it works', 'acps-alert-popups' ); ?></h2>
		<p><?php esc_html_e( 'There are only two halves to this, and they never overlap.', 'acps-alert-popups' ); ?></p>
		<?php echo ACPS_Alerts_Art::flow(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authored SVG, escaped at source. ?>

		<div class="acps-help-split">
			<div class="acps-help-half">
				<h3><?php esc_html_e( 'Beaver Builder decides what it says', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'The words, the pictures, the buttons, the colours inside the box. If you can build a page in Beaver Builder, you can build an alert.', 'acps-alert-popups' ); ?></p>
			</div>
			<div class="acps-help-half">
				<h3><?php esc_html_e( 'This plugin decides everything else', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'Whether it is on, which pages it appears on, who sees it, when it starts and stops, how it opens, and how often it comes back.', 'acps-alert-popups' ); ?></p>
			</div>
		</div>
	</div>

	<?php // ---------- Step by step ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'Make an alert, step by step', 'acps-alert-popups' ); ?></h2>
		<p><?php esc_html_e( 'Follow these in order the first time. After that it takes about a minute.', 'acps-alert-popups' ); ?></p>

		<ol class="acps-steps-big">
			<li>
				<h3><?php esc_html_e( 'Create the alert and write it', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'Use the Add New Alert button. You get an ordinary WordPress editing screen: give it a title, write what it should say, and save.', 'acps-alert-popups' ); ?></p>
				<p><?php esc_html_e( 'Keep it short: a heading, a sentence or two, and at most one button.', 'acps-alert-popups' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( $acps_new_url ); ?>"><?php esc_html_e( 'Do this now', 'acps-alert-popups' ); ?></a>
			</li>
			<li>
				<h3><?php esc_html_e( 'Publish it', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'An alert left as a draft will never appear, however it is configured. This catches almost everybody once.', 'acps-alert-popups' ); ?></p>
			</li>
			<li>
				<h3><?php esc_html_e( 'Design it in Beaver Builder (optional)', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'Once it is published, a Launch Beaver Builder button appears at the top of the alert. Use it to lay the alert out in the builder, exactly like any other page.', 'acps-alert-popups' ); ?></p>
				<p><?php esc_html_e( 'If you skip this, the alert simply shows what you wrote in the normal editor.', 'acps-alert-popups' ); ?></p>
			</li>
			<li>
				<h3><?php esc_html_e( 'Tick "Alert is live"', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'Scroll down the same screen to Site Alert Settings. That single tick is what puts it in front of visitors — everything else just narrows it down.', 'acps-alert-popups' ); ?></p>
				<a class="button" href="<?php echo esc_url( $acps_list_url ); ?>"><?php esc_html_e( 'Open Site Alerts', 'acps-alert-popups' ); ?></a>
			</li>
			<li>
				<h3><?php esc_html_e( 'Aim it, then save', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'Choose where it shows, who sees it, when it starts and stops, and how often it comes back. Save, and it is running.', 'acps-alert-popups' ); ?></p>
			</li>
			<li>
				<h3><?php esc_html_e( 'Check it on the live site', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'Every alert settings screen has a preview link that shows you the alert on the real site, ignoring its schedule and targeting, without anyone else seeing it.', 'acps-alert-popups' ); ?></p>
			</li>
		</ol>
	</div>

	<?php // ---------- Anatomy ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'What a visitor actually sees', 'acps-alert-popups' ); ?></h2>
		<?php echo ACPS_Alerts_Art::anatomy(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authored SVG. ?>
	</div>

	<?php // ---------- Frequency ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'How often it comes back', 'acps-alert-popups' ); ?></h2>
		<p><?php esc_html_e( 'This is the setting people most often get wrong. "Once per browser session" is right nearly every time.', 'acps-alert-popups' ); ?></p>
		<?php echo ACPS_Alerts_Art::frequency(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authored SVG. ?>
		<p class="acps-callout">
			<?php esc_html_e( 'A visitor’s browser remembers this, not your site. Testing in a private window always gives you a fresh start.', 'acps-alert-popups' ); ?>
		</p>
	</div>

	<?php // ---------- Triggers ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'How it opens', 'acps-alert-popups' ); ?></h2>
		<?php echo ACPS_Alerts_Art::triggers(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authored SVG. ?>
		<p><?php esc_html_e( 'For a closure or an emergency, use Page load. For a newsletter or an event, a delay or a scroll feels far less pushy.', 'acps-alert-popups' ); ?></p>
	</div>

	<?php // ---------- Targeting ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'Choosing which pages show it', 'acps-alert-popups' ); ?></h2>
		<?php echo ACPS_Alerts_Art::targeting(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authored SVG. ?>

		<h3><?php esc_html_e( 'Writing URL paths', 'acps-alert-popups' ); ?></h3>
		<table class="widefat striped acps-help-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'You type', 'acps-alert-popups' ); ?></th>
					<th><?php esc_html_e( 'It matches', 'acps-alert-popups' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>/enrollment</code></td><td><?php esc_html_e( 'That one page, and nothing else.', 'acps-alert-popups' ); ?></td></tr>
				<tr><td><code>/news/*</code></td><td><?php esc_html_e( 'Everything under /news, but not /news itself.', 'acps-alert-popups' ); ?></td></tr>
				<tr><td><code>/news*</code></td><td><?php esc_html_e( 'Everything under /news, and /news itself.', 'acps-alert-popups' ); ?></td></tr>
				<tr><td><code>https://example.org/apply</code></td><td><?php esc_html_e( 'The same as /apply — only the path is compared, so pasting a full link is fine.', 'acps-alert-popups' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<?php // ---------- Severity ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'Severity colours', 'acps-alert-popups' ); ?></h2>
		<?php echo ACPS_Alerts_Art::severity(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authored SVG. ?>
	</div>

	<?php // ---------- Screen map ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'Reading the alerts list', 'acps-alert-popups' ); ?></h2>
		<?php echo ACPS_Alerts_Art::screen_list(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authored SVG. ?>
	</div>

	<?php // ---------- Recipes ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'Ready-made recipes', 'acps-alert-popups' ); ?></h2>
		<p><?php esc_html_e( 'Copy these settings for the jobs that come up most.', 'acps-alert-popups' ); ?></p>

		<div class="acps-recipes">
			<?php
			$acps_recipes = array(
				array(
					'title'    => __( 'Snow day / emergency closure', 'acps-alert-popups' ),
					'subtitle' => __( 'Everyone needs to see this, today.', 'acps-alert-popups' ),
					'settings' => array(
						__( 'Severity', 'acps-alert-popups' )    => __( 'Critical', 'acps-alert-popups' ),
						__( 'Where', 'acps-alert-popups' )       => __( 'Entire site', 'acps-alert-popups' ),
						__( 'Trigger', 'acps-alert-popups' )     => __( 'As soon as the page loads', 'acps-alert-popups' ),
						__( 'Show again', 'acps-alert-popups' )  => __( 'Once per browser session', 'acps-alert-popups' ),
						__( 'Schedule', 'acps-alert-popups' )    => __( 'End it at the end of the day, so it clears itself', 'acps-alert-popups' ),
					),
				),
				array(
					'title'    => __( 'Upcoming event', 'acps-alert-popups' ),
					'subtitle' => __( 'Useful, but not urgent.', 'acps-alert-popups' ),
					'settings' => array(
						__( 'Severity', 'acps-alert-popups' )   => __( 'Information', 'acps-alert-popups' ),
						__( 'Where', 'acps-alert-popups' )      => __( 'Front page only', 'acps-alert-popups' ),
						__( 'Trigger', 'acps-alert-popups' )    => __( 'After a delay of 5 seconds', 'acps-alert-popups' ),
						__( 'Show again', 'acps-alert-popups' ) => __( 'Once every 7 days', 'acps-alert-popups' ),
						__( 'Schedule', 'acps-alert-popups' )   => __( 'Start two weeks out, end the morning after', 'acps-alert-popups' ),
					),
				),
				array(
					'title'    => __( 'Staff-only notice', 'acps-alert-popups' ),
					'subtitle' => __( 'Visitors should never see it.', 'acps-alert-popups' ),
					'settings' => array(
						__( 'Who sees it', 'acps-alert-popups' ) => __( 'Specific roles — pick your staff roles', 'acps-alert-popups' ),
						__( 'Where', 'acps-alert-popups' )       => __( 'Entire site', 'acps-alert-popups' ),
						__( 'Trigger', 'acps-alert-popups' )     => __( 'As soon as the page loads', 'acps-alert-popups' ),
						__( 'Show again', 'acps-alert-popups' )  => __( 'Once per browser session', 'acps-alert-popups' ),
					),
				),
				array(
					'title'    => __( 'A button that opens more detail', 'acps-alert-popups' ),
					'subtitle' => __( 'Nothing pops up uninvited.', 'acps-alert-popups' ),
					'settings' => array(
						__( 'Trigger', 'acps-alert-popups' )    => __( 'Only when something opens it', 'acps-alert-popups' ),
						__( 'Then', 'acps-alert-popups' )       => __( 'Add the Alert Trigger module to a page in Beaver Builder', 'acps-alert-popups' ),
						__( 'Show again', 'acps-alert-popups' ) => __( 'Every page view — they asked for it each time', 'acps-alert-popups' ),
					),
				),
			);

			foreach ( $acps_recipes as $acps_recipe ) :
				?>
				<div class="acps-recipe">
					<h3><?php echo esc_html( $acps_recipe['title'] ); ?></h3>
					<p class="acps-recipe__sub"><?php echo esc_html( $acps_recipe['subtitle'] ); ?></p>
					<dl>
						<?php foreach ( $acps_recipe['settings'] as $acps_k => $acps_v ) : ?>
							<dt><?php echo esc_html( $acps_k ); ?></dt>
							<dd><?php echo esc_html( $acps_v ); ?></dd>
						<?php endforeach; ?>
					</dl>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<?php // ---------- Opening from a page ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'Opening an alert from a button', 'acps-alert-popups' ); ?></h2>
		<p><?php esc_html_e( 'Three ways to do the same thing — use whichever suits you.', 'acps-alert-popups' ); ?></p>
		<ol class="acps-help-list">
			<li>
				<strong><?php esc_html_e( 'The Beaver Builder module', 'acps-alert-popups' ); ?></strong>
				<?php esc_html_e( 'Drag in the "Alert Trigger" module (group: Site Alerts), pick the alert, set the button text. Easiest option.', 'acps-alert-popups' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'A shortcode', 'acps-alert-popups' ); ?></strong>
				<code>[acps_alert_trigger id="123" text="Read the alert"]</code>
			</li>
			<li>
				<strong><?php esc_html_e( 'Any element at all', 'acps-alert-popups' ); ?></strong>
				<?php esc_html_e( 'Give it the class', 'acps-alert-popups' ); ?> <code>acps-alert-open</code> <?php esc_html_e( 'and', 'acps-alert-popups' ); ?> <code>data-alert="123"</code>.
			</li>
		</ol>
		<p class="acps-callout">
			<?php esc_html_e( 'Set that alert’s trigger to "Only when something opens it", or it will pop up on its own as well.', 'acps-alert-popups' ); ?>
		</p>
	</div>

	<?php // ---------- Troubleshooting ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'My alert is not showing', 'acps-alert-popups' ); ?></h2>
		<p><?php esc_html_e( 'Work down this list in order. It is nearly always one of the first three.', 'acps-alert-popups' ); ?></p>

		<div class="acps-faq">
			<?php
			$acps_trouble = array(
				array(
					__( 'The popup is still a draft', 'acps-alert-popups' ),
					__( 'A draft never shows, no matter what the alert settings say. Open the popup and publish it. The alerts list puts a grey "draft" tag next to the name when this is the problem.', 'acps-alert-popups' ),
				),
				array(
					__( 'The alert is switched off', 'acps-alert-popups' ),
					__( 'Check the Status column. "Off" means nobody sees it. Use "Switch on" in the row, or tick "Alert is live" in its settings.', 'acps-alert-popups' ),
				),
				array(
					__( 'You already closed it once', 'acps-alert-popups' ),
					__( 'If it is set to show once per session or once ever, your own browser is remembering that you dismissed it. Open a private window, or clear the site data, to see it fresh.', 'acps-alert-popups' ),
				),
				array(
					__( 'The schedule has not started, or has finished', 'acps-alert-popups' ),
					__( 'The Status column says "On, not showing" for this. Check the start and end dates — and remember they use your site timezone, not yours.', 'acps-alert-popups' ),
				),
				array(
					__( 'The page is excluded, or not targeted', 'acps-alert-popups' ),
					__( 'If "Display on" is set to Selected locations, the page has to match something you listed. And anything on the never-show list is blocked even if it matches.', 'acps-alert-popups' ),
				),
				array(
					__( 'Alerts are hidden from editors', 'acps-alert-popups' ),
					__( 'Settings has an option to hide alerts from people who can edit pages, so they do not get in your way while working. If it is on, log out or use a private window to check.', 'acps-alert-popups' ),
				),
				array(
					__( 'Caching is serving an old page', 'acps-alert-popups' ),
					__( 'If your host or a plugin caches pages, clear that cache after switching an alert on. The preview link bypasses caching, so if the alert shows in preview but not on the page, caching is your answer.', 'acps-alert-popups' ),
				),
				array(
					__( 'Beaver Builder is not active', 'acps-alert-popups' ),
					__( 'Alerts are Beaver Builder popups. If the builder is deactivated the alerts list will tell you so at the top of the screen.', 'acps-alert-popups' ),
				),
			);

			foreach ( $acps_trouble as $acps_i => $acps_item ) :
				?>
				<details class="acps-faq__item"<?php echo 0 === $acps_i ? ' open' : ''; ?>>
					<summary><?php echo esc_html( $acps_item[0] ); ?></summary>
					<p><?php echo esc_html( $acps_item[1] ); ?></p>
				</details>
			<?php endforeach; ?>
		</div>
	</div>

	<?php // ---------- FAQ ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'Questions people ask', 'acps-alert-popups' ); ?></h2>

		<div class="acps-faq">
			<?php
			$acps_faq = array(
				array(
					__( 'Do I have to rebuild the popup to change the wording?', 'acps-alert-popups' ),
					__( 'No. Edit it in Beaver Builder and save. The alert keeps all of its settings — they live separately from the design.', 'acps-alert-popups' ),
				),
				array(
					__( 'Can I show two alerts at once?', 'acps-alert-popups' ),
					__( 'By default only one shows per page view, and the one with the highest priority wins. You can raise that limit in Settings, but two popups at once is a lot to ask of a visitor.', 'acps-alert-popups' ),
				),
				array(
					__( 'Will it show on mobile?', 'acps-alert-popups' ),
					__( 'Yes. The alert shrinks to fit small screens, and whatever you built in Beaver Builder keeps its own responsive settings.', 'acps-alert-popups' ),
				),
				array(
					__( 'What happens when the end date passes?', 'acps-alert-popups' ),
					__( 'It stops showing on its own. The alert stays switched on in the list, so you can reuse it next year by changing the dates.', 'acps-alert-popups' ),
				),
				array(
					__( 'Someone told me the popup traps them. What did I do?', 'acps-alert-popups' ),
					__( 'You probably turned off all three ways of closing it: the close button, clicking the background, and the Escape key. Leave at least one on — keyboard visitors rely on them.', 'acps-alert-popups' ),
				),
				array(
					__( 'Can I test an alert without anyone seeing it?', 'acps-alert-popups' ),
					__( 'Yes. Each alert settings screen has a preview link. It shows you that alert on the live site, ignoring its schedule, targeting and frequency, and only works for people who can edit pages.', 'acps-alert-popups' ),
				),
				array(
					__( 'Does deleting the popup delete the alert?', 'acps-alert-popups' ),
					__( 'Yes — they are the same thing. Deleting the popup in WordPress removes it from the alerts list too. If you only want it to stop, switch it off instead.', 'acps-alert-popups' ),
				),
				array(
					__( 'Who can manage alerts?', 'acps-alert-popups' ),
					__( 'Anyone who can edit pages, which usually means editors and administrators. Changing the global Settings needs an administrator.', 'acps-alert-popups' ),
				),
			);

			foreach ( $acps_faq as $acps_item ) :
				?>
				<details class="acps-faq__item">
					<summary><?php echo esc_html( $acps_item[0] ); ?></summary>
					<p><?php echo esc_html( $acps_item[1] ); ?></p>
				</details>
			<?php endforeach; ?>
		</div>
	</div>

	<?php // ---------- Glossary ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'Words used here', 'acps-alert-popups' ); ?></h2>
		<dl class="acps-glossary">
			<dt><?php esc_html_e( 'Alert', 'acps-alert-popups' ); ?></dt>
			<dd><?php esc_html_e( 'A Beaver Builder popup that this plugin has been told to show.', 'acps-alert-popups' ); ?></dd>

			<dt><?php esc_html_e( 'Popup', 'acps-alert-popups' ); ?></dt>
			<dd><?php esc_html_e( 'The Beaver Builder layout itself — the design and the words.', 'acps-alert-popups' ); ?></dd>

			<dt><?php esc_html_e( 'Live', 'acps-alert-popups' ); ?></dt>
			<dd><?php esc_html_e( 'Switched on, published, and inside its schedule: visitors are seeing it now.', 'acps-alert-popups' ); ?></dd>

			<dt><?php esc_html_e( 'Trigger', 'acps-alert-popups' ); ?></dt>
			<dd><?php esc_html_e( 'What makes the alert open — loading the page, a delay, scrolling, leaving, or a click.', 'acps-alert-popups' ); ?></dd>

			<dt><?php esc_html_e( 'Targeting', 'acps-alert-popups' ); ?></dt>
			<dd><?php esc_html_e( 'The rules for which pages an alert may appear on.', 'acps-alert-popups' ); ?></dd>

			<dt><?php esc_html_e( 'Severity', 'acps-alert-popups' ); ?></dt>
			<dd><?php esc_html_e( 'How serious the alert is. Sets the colour stripe; does not change who sees it.', 'acps-alert-popups' ); ?></dd>

			<dt><?php esc_html_e( 'Priority', 'acps-alert-popups' ); ?></dt>
			<dd><?php esc_html_e( 'The tie-breaker when more alerts qualify than the site is allowed to show at once. Higher wins.', 'acps-alert-popups' ); ?></dd>

			<dt><?php esc_html_e( 'Session', 'acps-alert-popups' ); ?></dt>
			<dd><?php esc_html_e( 'One visit in one browser. It ends when the visitor closes the browser.', 'acps-alert-popups' ); ?></dd>
		</dl>
	</div>

	<p class="acps-help__foot">
		<a class="button button-primary" href="<?php echo esc_url( $acps_list_url ); ?>"><?php esc_html_e( 'Go to Site Alerts', 'acps-alert-popups' ); ?></a>
		<button type="button" class="button" data-acps-tour="first-alert"><?php esc_html_e( 'Replay the guided tour', 'acps-alert-popups' ); ?></button>
	</p>
</div>
