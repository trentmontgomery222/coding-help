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

	<?php // ---------- The status board ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'The status page is the control panel', 'acps-alert-popups' ); ?></h2>
		<p><?php esc_html_e( 'Two modules go on your status page, and between them they are the whole system. You can post an update from either the status page or the quick Post an Alert form in wp-admin — both change the same one alert.', 'acps-alert-popups' ); ?></p>
		<ul class="acps-help-list">
			<li><strong><?php esc_html_e( 'Current Alert', 'acps-alert-popups' ); ?></strong> — <?php esc_html_e( 'the popup itself. You write the alert here and switch it on here, and every setting it has is on its tabs. It only shows in the builder; visitors see it as a popup on every other page.', 'acps-alert-popups' ); ?></li>
			<li><strong><?php esc_html_e( 'School Status Board', 'acps-alert-popups' ); ?></strong> — <?php esc_html_e( 'the template. It shows the alert\'s heading and message as the banner, in the colour of its status level, and lists the past updates underneath. The banner is those two things and nothing else — for a badge or the level word, put [schoolstatus] wherever you want it.', 'acps-alert-popups' ); ?></li>
		</ul>

		<?php echo ACPS_Alerts_Art::board_flow(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authored SVG. ?>

		<h3><?php esc_html_e( 'Setting the page up, once', 'acps-alert-popups' ); ?></h3>
		<ol class="acps-help-list">
			<li><?php esc_html_e( 'Edit your status page in Beaver Builder.', 'acps-alert-popups' ); ?></li>
			<li><?php esc_html_e( 'Drag in the "Current Alert" module — it is in the Site Alerts group. This is the popup.', 'acps-alert-popups' ); ?></li>
			<li><?php esc_html_e( 'Drag in the "School Status Board" module underneath it.', 'acps-alert-popups' ); ?></li>
			<li><?php esc_html_e( 'Under Board, set the wording for when nothing is happening ("School Status: NORMAL" and the paragraph beneath it).', 'acps-alert-popups' ); ?></li>
			<li><?php esc_html_e( 'Save the page. You never have to touch this part again.', 'acps-alert-popups' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Posting an update, every time after that', 'acps-alert-popups' ); ?></h3>
		<ol class="acps-help-list">
			<li><?php esc_html_e( 'Edit the status page in Beaver Builder and open the Current Alert module.', 'acps-alert-popups' ); ?></li>
			<li><?php esc_html_e( 'On the Popup tab, rewrite the heading and the text, and pick the status level — that is the severity.', 'acps-alert-popups' ); ?></li>
			<li><?php esc_html_e( 'On the On/off tab, set "Show this alert now" to "Yes — it is live".', 'acps-alert-popups' ); ?></li>
			<li><?php esc_html_e( 'Save. That is it — under a minute, start to finish.', 'acps-alert-popups' ); ?></li>
		</ol>
		<p class="acps-callout">
			<?php esc_html_e( 'There is only ever one Current Alert. You are always modifying it, never making another one — it is there on a quiet day too, just switched off.', 'acps-alert-popups' ); ?>
		</p>

		<h3><?php esc_html_e( 'Fixing a typo', 'acps-alert-popups' ); ?></h3>
		<p><?php esc_html_e( 'What is in that module IS the current status. Correct the wording, save, and the update people are reading changes. You do not get a second copy underneath it, and the daily cut-off is not pushed back — an update fixed at lunchtime still comes down at the usual time.', 'acps-alert-popups' ); ?></p>
		<p class="acps-callout">
			<?php esc_html_e( 'To replace it with something genuinely new, just rewrite the boxes. The previous wording is filed into the archive at the cut-off, or you can file a record of it yourself from the archive tab.', 'acps-alert-popups' ); ?>
		</p>

		<h3><?php esc_html_e( 'What happens at the cut-off', 'acps-alert-popups' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: %s: cut-off time, e.g. 17:50. */
				esc_html__( 'Every day at %s, a Current Alert set to come down automatically is filed into the archive and switched off. It stops popping up and drops off the banner, but stays on the status page in the list of past updates. Its wording is left alone, ready for you to rewrite next time.', 'acps-alert-popups' ),
				'<strong>' . esc_html( ACPS_Alerts_Status::cutoff_time() ) . '</strong>'
			);
			?>
		</p>
		<p><?php esc_html_e( 'An update posted after the cut-off runs until the following day, so a 9pm closure notice does not vanish the moment you post it. Change the time in Settings.', 'acps-alert-popups' ); ?></p>
		<p><?php esc_html_e( 'To keep something up indefinitely, set "Take it down" to "Keep it up until I switch it off". Nothing will take it down but you.', 'acps-alert-popups' ); ?></p>

		<h3><?php esc_html_e( 'Filling in things that already happened', 'acps-alert-popups' ); ?></h3>
		<p><?php esc_html_e( 'You can write up a past event and send it straight to the archive, so the list of past updates is complete from day one — useful when you are moving over from somewhere else.', 'acps-alert-popups' ); ?></p>
		<ol class="acps-help-list">
			<li><?php esc_html_e( 'Open the Status Board module as usual.', 'acps-alert-popups' ); ?></li>
			<li><?php esc_html_e( 'Fill in the headline, message and status level for the past event.', 'acps-alert-popups' ); ?></li>
			<li><?php esc_html_e( 'Use the "Add a past event to the archive" section, not the Current Alert section.', 'acps-alert-popups' ); ?></li>
			<li><?php esc_html_e( 'Type the date it happened, as YYYY-MM-DD. That decides where it sits in the list.', 'acps-alert-popups' ); ?></li>
			<li><?php esc_html_e( 'Save. Repeat for each past event — the boxes clear themselves each time.', 'acps-alert-popups' ); ?></li>
		</ol>
		<p class="acps-callout">
			<?php esc_html_e( 'An archived entry never pops up and never reaches the banner, whatever else is set. It is a record, not an announcement.', 'acps-alert-popups' ); ?>
		</p>
		<p><?php esc_html_e( 'Archived entries are records, not alerts. They live in a list of their own, so filing one never touches the Current Alert or the Normal Alert.', 'acps-alert-popups' ); ?></p>

		<h3><?php esc_html_e( 'Checking an update before anyone sees it', 'acps-alert-popups' ); ?></h3>
		<p><?php esc_html_e( 'On the On/off tab, set "Who can see it" to "Staff only". The update goes live on the real status page and the real popup, but only people who can manage alerts see it — everybody else sees the normal status. The board shows you a dashed "Staff preview" strip so you cannot forget it is staged.', 'acps-alert-popups' ); ?></p>
		<p><?php esc_html_e( 'When you are happy with it, set "Who can see it" back to "Everybody" and save.', 'acps-alert-popups' ); ?></p>

		<h3><?php esc_html_e( 'Designing the popup itself', 'acps-alert-popups' ); ?></h3>
		<p><?php esc_html_e( 'The Current Alert module is the popup, so what you see in the builder is what visitors get: a coloured badge for the status level, your heading, your text, and a link underneath. Its Style tab decides where the box sits, how wide it is, whether the page dims behind it, and how it can be closed.', 'acps-alert-popups' ); ?></p>
		<p><?php esc_html_e( 'The heading is always the title you typed, never the words "School Status". The level shows as the badge above it, and as a small label if you switch "Show the level word" on. The same is true of the banner on the status page.', 'acps-alert-popups' ); ?></p>
		<p><?php esc_html_e( 'The link under the message defaults to "View updates" and goes to the status page unless you give it somewhere else. Clear the link text to drop it.', 'acps-alert-popups' ); ?></p>
		<p><?php esc_html_e( 'The popup never appears on the status page itself, so the page stays readable while you are working on it. Everywhere else, it is what visitors see.', 'acps-alert-popups' ); ?></p>
	</div>

	<?php // ---------- How it works ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'The quick way: Post an Alert', 'acps-alert-popups' ); ?></h2>
		<p><?php esc_html_e( 'When you just need to change the words fast, you do not have to open Beaver Builder. Site Alerts → Post an Alert (there is a button on the alerts list too) is a one-screen form.', 'acps-alert-popups' ); ?></p>
		<ol class="acps-help-list">
			<li><strong><?php esc_html_e( 'Level', 'acps-alert-popups' ); ?></strong> — <?php esc_html_e( 'the status level, which sets the colour of the popup and the banner and the word shown on them.', 'acps-alert-popups' ); ?></li>
			<li><strong><?php esc_html_e( 'Header', 'acps-alert-popups' ); ?></strong> — <?php esc_html_e( 'the title on the popup and the status page.', 'acps-alert-popups' ); ?></li>
			<li><strong><?php esc_html_e( 'Text', 'acps-alert-popups' ); ?></strong> — <?php esc_html_e( 'the message body.', 'acps-alert-popups' ); ?></li>
		</ol>
		<p><?php esc_html_e( 'Press Post alert and, in one step, the header and text are written straight into the popup, the level is set, and the alert goes live. The popup, the status board and any [schoolstatus] shortcode all update together.', 'acps-alert-popups' ); ?></p>
		<p class="acps-callout">
			<?php esc_html_e( 'It touches only those three things. Any extra content, images, buttons, styling or layout you built into the popup are left exactly as they are — the form and Beaver Builder edit the same popup, so you never end up with two. The boxes come pre-filled with what the popup says now, an empty box leaves that piece alone, and re-wording an alert that is already up does not restart its daily cut-off.', 'acps-alert-popups' ); ?>
		</p>
		<p><a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => ACPS_Alerts_Admin::MENU_SLUG, 'acps_view' => 'post' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Post an Alert', 'acps-alert-popups' ); ?></a></p>
	</div>

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
		<h2><?php esc_html_e( 'Put out an alert, step by step', 'acps-alert-popups' ); ?></h2>
		<p><?php esc_html_e( 'Follow these in order the first time. After that it takes about a minute.', 'acps-alert-popups' ); ?></p>

		<ol class="acps-steps-big">
			<li>
				<h3><?php esc_html_e( 'Open the status page in Beaver Builder', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'This is the page with the School Status Board module on it. Everything is done from there — you do not create anything in wp-admin.', 'acps-alert-popups' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>"><?php esc_html_e( 'Open Pages', 'acps-alert-popups' ); ?></a>
			</li>
			<li>
				<h3><?php esc_html_e( 'Edit the Current Alert module', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'It is the popup on the page. On its Popup tab, rewrite the heading and the text, and pick the status level — Hold, Secure, Lockdown, Evacuate, Shelter, or one of the everyday levels.', 'acps-alert-popups' ); ?></p>
				<p><?php esc_html_e( 'Keep it short: a heading and a sentence or two. There is only one Current Alert, so you are always editing it, never adding another.', 'acps-alert-popups' ); ?></p>
			</li>
			<li>
				<h3><?php esc_html_e( 'Decide how far it goes', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'On the On/off tab, "Pop up across the site" puts it in front of everyone on every page. Leave it off and the update stays on the status page banner only.', 'acps-alert-popups' ); ?></p>
				<p><?php esc_html_e( 'Set "Who can see it" to "Staff only" if you want to check it first — it goes live for people who can manage alerts and nobody else.', 'acps-alert-popups' ); ?></p>
			</li>
			<li>
				<h3><?php esc_html_e( 'Switch it on and save', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'Set "Show this alert now" to "Yes — it is live" and save the page. That single choice is what puts it in front of visitors.', 'acps-alert-popups' ); ?></p>
			</li>
			<li>
				<h3><?php esc_html_e( 'Leave it to come down on its own', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'At the daily cut-off it files itself into the archive and switches itself off. Choose "Keep it up until I switch it off" if it needs to outlast the day.', 'acps-alert-popups' ); ?></p>
			</li>
			<li>
				<h3><?php esc_html_e( 'Everything else is on the same module', 'acps-alert-popups' ); ?></h3>
				<p><?php esc_html_e( 'Which pages show it, who sees it, how it opens and how often it comes back, where the box sits and how it closes — all of it is on the Current Alert module\'s tabs. There is nothing to set in wp-admin.', 'acps-alert-popups' ); ?></p>
				<p><?php esc_html_e( 'Site Alerts in wp-admin is there to check state and to edit the Normal Alert; it is not where you post.', 'acps-alert-popups' ); ?></p>
				<a class="button" href="<?php echo esc_url( $acps_list_url ); ?>"><?php esc_html_e( 'Open Site Alerts', 'acps-alert-popups' ); ?></a>
			</li>
		</ol>
	</div>

	<?php // ---------- The status shortcode ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'Putting the status anywhere', 'acps-alert-popups' ); ?></h2>
		<p><?php esc_html_e( 'The shortcode below prints the current status wherever you type it — at the top of the popup, in a header, in a sidebar, in a post. It reads the same Current Alert the status board reads, so every place that shows the status is showing the same thing, and all of them change the moment you change the alert.', 'acps-alert-popups' ); ?></p>

		<p><code>[schoolstatus]</code></p>

		<h3><?php esc_html_e( 'Choosing what it prints', 'acps-alert-popups' ); ?></h3>
		<table class="widefat striped acps-help-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'You type', 'acps-alert-popups' ); ?></th>
					<th><?php esc_html_e( 'You get', 'acps-alert-popups' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>[schoolstatus]</code></td><td><?php esc_html_e( 'The badge and the level word — HOLD, LOCKDOWN, NORMAL.', 'acps-alert-popups' ); ?></td></tr>
				<tr><td><code>[schoolstatus show="icon"]</code></td><td><?php esc_html_e( 'Just the coloured badge. This is the one to put at the top of the popup.', 'acps-alert-popups' ); ?></td></tr>
				<tr><td><code>[schoolstatus show="all"]</code></td><td><?php esc_html_e( 'Badge, level word, its SRP directive, the headline and the message.', 'acps-alert-popups' ); ?></td></tr>
				<tr><td><code>[schoolstatus show="icon headline"]</code></td><td><?php esc_html_e( 'Any parts you like, in the order you list them: icon, level, directive, headline, message.', 'acps-alert-popups' ); ?></td></tr>
				<tr><td><code>[schoolstatus when="live"]</code></td><td><?php esc_html_e( 'Nothing at all on a normal day. Use this in a header that should stay empty until something happens.', 'acps-alert-popups' ); ?></td></tr>
				<tr><td><code>[schoolstatus link="yes"]</code></td><td><?php esc_html_e( 'The whole thing becomes a link to the status page.', 'acps-alert-popups' ); ?></td></tr>
				<tr><td><code>[schoolstatus layout="row" size="40"]</code></td><td><?php esc_html_e( 'Side by side instead of stacked, with a smaller badge. Also takes align="left|center|right".', 'acps-alert-popups' ); ?></td></tr>
				<tr><td><code>[schoolstatus source="alert"]</code></td><td><?php esc_html_e( 'The Current Alert\'s own level, whether or not it is showing. This is the one to use inside the popup.', 'acps-alert-popups' ); ?></td></tr>
				<tr><td><code>[schoolstatus color="#ffffff"]</code></td><td><?php esc_html_e( 'Draw it in a colour of your choosing, for a dark background the SRP colour does not read on.', 'acps-alert-popups' ); ?></td></tr>
			</tbody>
		</table>

		<p class="acps-callout">
			<?php esc_html_e( 'The shortcode styles itself, so it looks right on a page that loads none of this plugin\'s stylesheets — which is what lets it work inside the popup on every other page of the site.', 'acps-alert-popups' ); ?>
		</p>

		<h3><?php esc_html_e( 'Which status it reports', 'acps-alert-popups' ); ?></h3>
		<p><?php esc_html_e( 'These are two different questions, and inside the popup the second one is the right one to ask.', 'acps-alert-popups' ); ?></p>
		<ul class="acps-help-list">
			<li><strong><?php esc_html_e( 'source="board"', 'acps-alert-popups' ); ?></strong> — <?php esc_html_e( 'the default: what the status page says. The Current Alert while it is showing, the normal state once it is over.', 'acps-alert-popups' ); ?></li>
			<li><strong><?php esc_html_e( 'source="alert"', 'acps-alert-popups' ); ?></strong> — <?php esc_html_e( 'what the Current Alert itself says, showing or not. Use this in the popup: the popup IS that alert, so it should keep the alert\'s own badge instead of flipping to Normal the moment the event is filed.', 'acps-alert-popups' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'When a level colour does not read', 'acps-alert-popups' ); ?></h3>
		<p><?php esc_html_e( 'The SRP colours are chosen to read on white. On a dark background the same colour can be nearly invisible, so you can say what a level should look like in one place without changing it anywhere else.', 'acps-alert-popups' ); ?></p>
		<ul class="acps-help-list">
			<li><?php esc_html_e( 'For the status page: the Status Board module has a "Level colours on this board" section, with one colour per level. That colour is the banner\'s — its head on the card, or the whole banner on the solid style. Leave one empty to keep the standard colour.', 'acps-alert-popups' ); ?></li>
			<li><?php esc_html_e( 'For a shortcode: add color="#ffffff" to that one placement.', 'acps-alert-popups' ); ?></li>
		</ul>
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

	<?php // ---------- SRP ---------- ?>
	<div class="acps-help-section">
		<h2><?php esc_html_e( 'Status levels use the Standard Response Protocol', 'acps-alert-popups' ); ?></h2>
		<p class="acps-callout">
			<?php esc_html_e( 'The status level IS the severity — there is no second setting. You pick it on the Current Alert module, on its Popup tab under "Status level". It sets the word on the banner, the colour of the banner and the popup stripe, and the coloured badge above the heading.', 'acps-alert-popups' ); ?>
		</p>
		<p><?php esc_html_e( 'The status levels are the five SRP actions from the "I Love U Guys" Foundation — the same vocabulary your staff and students are trained on. The website says exactly what the drill says, with the same word and the same directive underneath it.', 'acps-alert-popups' ); ?></p>

		<?php echo ACPS_Alerts_Art::severity(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authored SVG. ?>

		<table class="widefat striped acps-help-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Level', 'acps-alert-popups' ); ?></th>
					<th><?php esc_html_e( 'Shows as', 'acps-alert-popups' ); ?></th>
					<th><?php esc_html_e( 'Use it when', 'acps-alert-popups' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$acps_when = array(
					'normal'   => __( 'Nothing is happening. This is the default when no update is live.', 'acps-alert-popups' ),
					'info'     => __( 'A notice that is not a response action — an event, a write-up of something already resolved.', 'acps-alert-popups' ),
					'hold'     => __( 'The hallways need to be clear. Business as usual inside the room.', 'acps-alert-popups' ),
					'secure'   => __( 'The threat is outside. Bring everyone in and lock the outside doors.', 'acps-alert-popups' ),
					'shelter'  => __( 'A hazard needs a specific safety strategy — tornado, hazmat, earthquake.', 'acps-alert-popups' ),
					'evacuate' => __( 'People need to move to a location.', 'acps-alert-popups' ),
					'lockdown' => __( 'The threat is inside. Locks, lights, out of sight.', 'acps-alert-popups' ),
				);

				foreach ( ACPS_Alerts_Status::levels() as $acps_key => $acps_level ) :
					if ( ! empty( $acps_level['legacy'] ) ) {
						continue;
					}
					?>
					<tr>
						<td><strong style="color:<?php echo esc_attr( $acps_level['color'] ); ?>"><?php echo esc_html( $acps_level['label'] ); ?></strong></td>
						<td>
							<strong><?php echo esc_html( $acps_level['banner'] ); ?></strong>
							<?php if ( '' !== $acps_level['directive'] ) : ?>
								<br /><em><?php echo esc_html( wp_strip_all_tags( $acps_level['directive'] ) ); ?></em>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $acps_when[ $acps_key ] ) ? $acps_when[ $acps_key ] : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="acps-callout">
			<?php esc_html_e( 'Check these directives against your own district training materials before you go live. If your wording differs, a developer can adjust it with the acps_alerts_status_levels filter — do not let the website and the drill disagree.', 'acps-alert-popups' ); ?>
		</p>
		<p><?php esc_html_e( 'Updates written before the move to SRP still work: their old wording keeps rendering, and you can switch them to an SRP action whenever you like.', 'acps-alert-popups' ); ?></p>
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
						__( 'Status level', 'acps-alert-popups' ) => __( 'Lockdown, or one of the other SRP actions', 'acps-alert-popups' ),
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
						__( 'Status level', 'acps-alert-popups' ) => __( 'Information', 'acps-alert-popups' ),
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
					__( 'I posted an update and it vanished by the next morning. Why?', 'acps-alert-popups' ),
					sprintf(
						/* translators: %s: cut-off time. */
						__( 'That is the daily cut-off doing its job. Anything set to come down automatically archives itself at %s each day. If you want an update to stay, choose "Keep it up until I archive it" when you post it, or change its "Take it down" setting in Site Alerts.', 'acps-alert-popups' ),
						ACPS_Alerts_Status::cutoff_time()
					),
				),
				array(
					__( 'I fixed a typo — did that make a second update?', 'acps-alert-popups' ),
					__( 'No. There is only ever one Current Alert, so editing the wording corrects the live update in place. You never get a second copy, and the daily cut-off is not pushed back.', 'acps-alert-popups' ),
				),
				array(
					__( 'Can I have more than one update live at once?', 'acps-alert-popups' ),
					__( 'No. There is one Current Alert and it is what shows; the Normal Alert is only the resting state. To change what is up, rewrite the Current Alert — from Post an Alert or on the status page — rather than adding another.', 'acps-alert-popups' ),
				),
				array(
					__( 'How do I put an archived update back?', 'acps-alert-popups' ),
					__( 'Site Alerts → hover the row → "Bring back". Its daily cut-off starts again from that moment, so it will not archive itself the second you restore it.', 'acps-alert-popups' ),
				),
				array(
					__( 'Do I have to rebuild the popup to change the wording?', 'acps-alert-popups' ),
					__( 'No. Edit it in Beaver Builder and save. The alert keeps all of its settings — they live separately from the design.', 'acps-alert-popups' ),
				),
				array(
					__( 'What is the Normal Alert for, then?', 'acps-alert-popups' ),
					__( 'It is the resting state the status board shows when nothing is happening. It never pops up and never interrupts anybody — only the Current Alert does that.', 'acps-alert-popups' ),
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
					__( 'Can I delete an alert?', 'acps-alert-popups' ),
					__( 'No. The site keeps exactly two — the Current Alert and the Normal Alert — and both are protected from deletion, so the system can never be left with nothing to show. To stop an alert, switch it off; it stays ready for next time.', 'acps-alert-popups' ),
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

			<dt><?php esc_html_e( 'Status level', 'acps-alert-popups' ); ?></dt>
			<dd><?php esc_html_e( 'What is happening, in SRP words — Hold, Secure, Shelter, Evacuate, Lockdown, plus Normal and Information. It sets the banner wording and the colour of both the banner and the popup stripe. It is the only urgency setting there is.', 'acps-alert-popups' ); ?></dd>

			<dt><?php esc_html_e( 'Current Alert', 'acps-alert-popups' ); ?></dt>
			<dd><?php esc_html_e( 'The one alert you write and switch on. It is the popup module on the status page, and it is always there — on a quiet day it is simply off.', 'acps-alert-popups' ); ?></dd>

			<dt><?php esc_html_e( 'Session', 'acps-alert-popups' ); ?></dt>
			<dd><?php esc_html_e( 'One visit in one browser. It ends when the visitor closes the browser.', 'acps-alert-popups' ); ?></dd>
		</dl>
	</div>

	<p class="acps-help__foot">
		<a class="button button-primary" href="<?php echo esc_url( $acps_list_url ); ?>"><?php esc_html_e( 'Go to Site Alerts', 'acps-alert-popups' ); ?></a>
		<button type="button" class="button" data-acps-tour="first-alert"><?php esc_html_e( 'Replay the guided tour', 'acps-alert-popups' ); ?></button>
	</p>
</div>
