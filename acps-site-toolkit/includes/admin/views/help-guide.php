<?php
/**
 * Built-in Help Guide: how to do everything and where it lives.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

use ACPS\SiteToolkit\Settings;
use ACPS\SiteToolkit\Form;
use ACPS\SiteToolkit\Help;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$help_url  = Settings::get( 'help_guide_url', '' );
$feedback  = Form::feedback_form();
$contact   = Form::find_by_slug( Help::CONTACT_SLUG );
$media     = Form::find_by_slug( Help::MEDIA_SLUG );
$fb_id     = $feedback ? $feedback->id : '?';
$contact_id = $contact ? $contact->id : '?';
$media_id  = $media ? $media->id : '?';

$menu = admin_url( 'admin.php?page=acps-st' );
?>
<div class="wrap acps-admin acps-help">
	<h1><?php esc_html_e( 'Cayden Form Manager — Help Guide', 'acps-site-toolkit' ); ?></h1>

	<?php if ( $help_url ) : ?>
		<p><a class="button button-primary" href="<?php echo esc_url( $help_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open your organisation’s help guide', 'acps-site-toolkit' ); ?> ↗</a></p>
	<?php endif; ?>

	<?php $forms_new = admin_url( 'admin.php?page=acps-st-forms&action=new' ); ?>

	<!-- ============ Guided tours ============ -->
	<h2 id="tours" style="margin-top:1rem"><span class="dashicons dashicons-video-alt3" aria-hidden="true" style="color:#2271b1"></span> <?php esc_html_e( 'Guided tours — I’ll walk you through it', 'acps-site-toolkit' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Like a video, but right here in the screen: each tour dims the page and points at the exact button to use, one step at a time. Press Esc any time to stop.', 'acps-site-toolkit' ); ?></p>
	<div class="acps-tours-panel">
		<div class="acps-tour-card">
			<h3><span class="dashicons dashicons-flag" aria-hidden="true"></span> <?php esc_html_e( 'The 2-minute overview', 'acps-site-toolkit' ); ?></h3>
			<p><?php esc_html_e( 'New here? Start with this. It shows you the whole plugin and where everything lives.', 'acps-site-toolkit' ); ?></p>
			<button type="button" class="button button-primary" data-acps-tour="overview"><?php esc_html_e( 'Start tour', 'acps-site-toolkit' ); ?></button>
		</div>
		<div class="acps-tour-card">
			<h3><span class="dashicons dashicons-forms" aria-hidden="true"></span> <?php esc_html_e( 'Build a form, step by step', 'acps-site-toolkit' ); ?></h3>
			<p><?php esc_html_e( 'Opens the builder and walks you through adding fields, settings, and saving.', 'acps-site-toolkit' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'acps_tour', 'build-form', $forms_new ) ); ?>"><?php esc_html_e( 'Start tour', 'acps-site-toolkit' ); ?></a>
		</div>
		<div class="acps-tour-card">
			<h3><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> <?php esc_html_e( 'Work the Feedback inbox', 'acps-site-toolkit' ); ?></h3>
			<p><?php esc_html_e( 'Shows you how to read, filter, export, and reply to messages.', 'acps-site-toolkit' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=acps-st&acps_tour=feedback-inbox' ) ); ?>"><?php esc_html_e( 'Start tour', 'acps-site-toolkit' ); ?></a>
		</div>
	</div>

	<!-- ============ Illustrated: build your first form ============ -->
	<div class="acps-card" id="first-form">
		<h2><?php esc_html_e( 'Build your first form in 4 steps', 'acps-site-toolkit' ); ?></h2>
		<svg class="acps-shot" viewBox="0 0 640 240" role="img" aria-labelledby="acps-shot1-t" preserveAspectRatio="xMidYMid meet">
			<title id="acps-shot1-t"><?php esc_attr_e( 'The form builder has three columns: field types on the left, your form in the middle, and the selected field’s settings on the right.', 'acps-site-toolkit' ); ?></title>
			<rect x="0" y="0" width="640" height="240" fill="#f6f7f7"/>
			<rect x="12" y="12" width="616" height="34" rx="6" fill="#fff" stroke="#dcdce0"/>
			<rect x="24" y="22" width="120" height="14" rx="3" fill="#e7f2fb"/>
			<rect x="516" y="20" width="100" height="18" rx="4" fill="#2271b1"/>
			<text x="566" y="33" font-family="sans-serif" font-size="11" fill="#fff" text-anchor="middle">Save form</text>
			<!-- left: field types -->
			<rect x="12" y="58" width="150" height="170" rx="6" fill="#fff" stroke="#dcdce0"/>
			<text x="24" y="78" font-family="sans-serif" font-size="11" font-weight="bold" fill="#50575e">FIELD TYPES</text>
			<rect x="24" y="88" width="126" height="22" rx="5" fill="#f0f6fb"/><text x="34" y="103" font-family="sans-serif" font-size="10" fill="#1d2327">Short text</text>
			<rect x="24" y="116" width="126" height="22" rx="5" fill="#f0f6fb"/><text x="34" y="131" font-family="sans-serif" font-size="10" fill="#1d2327">Email</text>
			<rect x="24" y="144" width="126" height="22" rx="5" fill="#f0f6fb"/><text x="34" y="159" font-family="sans-serif" font-size="10" fill="#1d2327">Dropdown</text>
			<rect x="24" y="172" width="126" height="22" rx="5" fill="#f0f6fb"/><text x="34" y="187" font-family="sans-serif" font-size="10" fill="#1d2327">Checkbox</text>
			<!-- middle: canvas -->
			<rect x="174" y="58" width="288" height="170" rx="6" fill="#fff" stroke="#2271b1"/>
			<text x="186" y="78" font-family="sans-serif" font-size="11" font-weight="bold" fill="#50575e">YOUR FORM</text>
			<rect x="186" y="88" width="264" height="40" rx="6" fill="#fbfdff" stroke="#2271b1"/>
			<circle cx="200" cy="108" r="8" fill="#2271b1"/><text x="200" y="112" font-family="sans-serif" font-size="10" fill="#fff" text-anchor="middle">1</text>
			<rect x="216" y="98" width="150" height="9" rx="2" fill="#1d2327"/><rect x="216" y="112" width="220" height="9" rx="2" fill="#e0e0e3"/>
			<rect x="186" y="136" width="264" height="40" rx="6" fill="#fff" stroke="#dcdce0"/>
			<circle cx="200" cy="156" r="8" fill="#a7aaad"/><text x="200" y="160" font-family="sans-serif" font-size="10" fill="#fff" text-anchor="middle">2</text>
			<rect x="216" y="146" width="120" height="9" rx="2" fill="#1d2327"/><rect x="216" y="160" width="220" height="9" rx="2" fill="#e0e0e3"/>
			<!-- right: settings -->
			<rect x="474" y="58" width="154" height="170" rx="6" fill="#fff" stroke="#dcdce0"/>
			<text x="486" y="78" font-family="sans-serif" font-size="11" font-weight="bold" fill="#50575e">SETTINGS</text>
			<rect x="486" y="90" width="60" height="8" rx="2" fill="#787c82"/><rect x="486" y="102" width="130" height="18" rx="4" fill="#f0f0f1"/>
			<rect x="486" y="128" width="60" height="8" rx="2" fill="#787c82"/><rect x="486" y="140" width="130" height="18" rx="4" fill="#f0f0f1"/>
			<rect x="486" y="166" width="90" height="8" rx="2" fill="#787c82"/><rect x="486" y="178" width="130" height="30" rx="4" fill="#f0f0f1"/>
		</svg>
		<ol class="acps-steps">
			<li><h3><?php esc_html_e( 'Open the builder', 'acps-site-toolkit' ); ?></h3><p><?php printf( wp_kses_post( __( 'Go to <strong>Forms → Add New</strong>, or just <a href="%s">click here</a>.', 'acps-site-toolkit' ) ), esc_url( $forms_new ) ); ?></p></li>
			<li><h3><?php esc_html_e( 'Add fields', 'acps-site-toolkit' ); ?></h3><p><?php esc_html_e( 'Click a field type on the left (Short text, Email, Dropdown…). It drops into the middle. Click a field to change its label, help text, or make it required on the right.', 'acps-site-toolkit' ); ?></p></li>
			<li><h3><?php esc_html_e( 'Set it up', 'acps-site-toolkit' ); ?></h3><p><?php esc_html_e( 'Scroll below the builder for the confirmation message, notification emails, auto-reply, and access control. All optional — the defaults are sensible.', 'acps-site-toolkit' ); ?></p></li>
			<li><h3><?php esc_html_e( 'Publish & place it', 'acps-site-toolkit' ); ?></h3><p><?php esc_html_e( 'Set Status to Published, click Save. Then copy the form’s shortcode from the Forms list and paste it into any page — or use the “ACPS Form” block.', 'acps-site-toolkit' ); ?></p></li>
		</ol>
		<p><a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'acps_tour', 'build-form', $forms_new ) ); ?>"><span class="dashicons dashicons-welcome-learn-more" aria-hidden="true" style="vertical-align:text-bottom"></span> <?php esc_html_e( 'Show me — start the interactive tour', 'acps-site-toolkit' ); ?></a></p>
	</div>

	<!-- ============ Illustrated: Google Form bridge ============ -->
	<div class="acps-card" id="google-bridge">
		<h2><?php esc_html_e( 'Put your form “on top of” a Google Form', 'acps-site-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Keep using a Google Form behind the scenes, but give people this plugin’s nicer, accessible form. Every answer is copied into your Google Form automatically.', 'acps-site-toolkit' ); ?></p>
		<svg class="acps-shot" viewBox="0 0 640 170" role="img" aria-labelledby="acps-shot2-t" preserveAspectRatio="xMidYMid meet">
			<title id="acps-shot2-t"><?php esc_attr_e( 'A visitor fills in your website form; the plugin copies the answers into your Google Form as a real response.', 'acps-site-toolkit' ); ?></title>
			<rect x="0" y="0" width="640" height="170" fill="#f6f7f7"/>
			<rect x="30" y="34" width="200" height="104" rx="8" fill="#fff" stroke="#2271b1"/>
			<text x="130" y="26" font-family="sans-serif" font-size="12" fill="#1d2327" text-anchor="middle" font-weight="bold">Your website form</text>
			<rect x="46" y="52" width="120" height="9" rx="2" fill="#1d2327"/><rect x="46" y="66" width="168" height="16" rx="4" fill="#f0f0f1"/>
			<rect x="46" y="90" width="90" height="9" rx="2" fill="#1d2327"/><rect x="46" y="104" width="168" height="16" rx="4" fill="#f0f0f1"/>
			<!-- arrow -->
			<line x1="242" y1="86" x2="392" y2="86" stroke="#2271b1" stroke-width="3"/>
			<polygon points="392,86 380,79 380,93" fill="#2271b1"/>
			<text x="317" y="76" font-family="sans-serif" font-size="11" fill="#2271b1" text-anchor="middle" font-weight="bold">answers copied</text>
			<text x="317" y="104" font-family="sans-serif" font-size="11" fill="#50575e" text-anchor="middle">automatically</text>
			<!-- google form -->
			<rect x="410" y="34" width="200" height="104" rx="8" fill="#fff" stroke="#673ab7"/>
			<text x="510" y="26" font-family="sans-serif" font-size="12" fill="#1d2327" text-anchor="middle" font-weight="bold">Your Google Form</text>
			<rect x="426" y="52" width="60" height="9" rx="2" fill="#673ab7"/><rect x="426" y="66" width="168" height="16" rx="4" fill="#f3eefb"/>
			<rect x="426" y="90" width="60" height="9" rx="2" fill="#673ab7"/><rect x="426" y="104" width="168" height="16" rx="4" fill="#f3eefb"/>
		</svg>
		<p><strong><?php esc_html_e( 'Easiest way — import it:', 'acps-site-toolkit' ); ?></strong></p>
		<ol class="acps-steps">
			<li><h3><?php esc_html_e( 'Import the Google Form', 'acps-site-toolkit' ); ?></h3><p><?php printf( wp_kses_post( __( 'Go to <strong>Forms → Import Google Form</strong> and paste your Google Form’s share link. <a href="%s">Open the importer</a>.', 'acps-site-toolkit' ) ), esc_url( admin_url( 'admin.php?page=acps-st-forms&action=import' ) ) ); ?></p></li>
			<li><h3><?php esc_html_e( 'It wires itself up', 'acps-site-toolkit' ); ?></h3><p><?php esc_html_e( 'A matching form is created AND the “Google Form bridge” is switched on with every field mapped to Google for you. Nothing else to do.', 'acps-site-toolkit' ); ?></p></li>
			<li><h3><?php esc_html_e( 'Publish it', 'acps-site-toolkit' ); ?></h3><p><?php esc_html_e( 'Review the fields, set Status to Published, Save, and place it on a page. Submissions now appear in both your Entries and your Google Form’s responses.', 'acps-site-toolkit' ); ?></p></li>
		</ol>
		<p class="description"><?php esc_html_e( 'Building by hand instead? In the form editor open “Google Form bridge”, tick it on, paste the Google Form link, and set each field’s “Google Form field ID” (the number in entry.123456789) in that field’s settings.', 'acps-site-toolkit' ); ?></p>
	</div>

	<p class="description"><?php esc_html_e( 'Everything this plugin does and where to find it. Jump to a section:', 'acps-site-toolkit' ); ?></p>

	<nav aria-label="<?php esc_attr_e( 'Help contents', 'acps-site-toolkit' ); ?>" class="acps-card">
		<ul>
			<li><a href="#overview"><?php esc_html_e( '1. Overview & where things live', 'acps-site-toolkit' ); ?></a></li>
			<li><a href="#chat"><?php esc_html_e( '2. The “Chat with us” button', 'acps-site-toolkit' ); ?></a></li>
			<li><a href="#feedback"><?php esc_html_e( '3. Feedback (inbox, statuses, deleting)', 'acps-site-toolkit' ); ?></a></li>
			<li><a href="#qa"><?php esc_html_e( '4. Q&A / Help widget', 'acps-site-toolkit' ); ?></a></li>
			<li><a href="#forms"><?php esc_html_e( '5. Building forms', 'acps-site-toolkit' ); ?></a></li>
			<li><a href="#conditional"><?php esc_html_e( '6. Conditional logic & multi-page', 'acps-site-toolkit' ); ?></a></li>
			<li><a href="#access"><?php esc_html_e( '7. Sharing / restricting a form', 'acps-site-toolkit' ); ?></a></li>
			<li><a href="#placing"><?php esc_html_e( '8. Placing a form on a page', 'acps-site-toolkit' ); ?></a></li>
			<li><a href="#entries"><?php esc_html_e( '9. Entries & CSV export', 'acps-site-toolkit' ); ?></a></li>
			<li><a href="#analytics"><?php esc_html_e( '10. Analytics', 'acps-site-toolkit' ); ?></a></li>
			<li><a href="#settings"><?php esc_html_e( '11. Settings reference', 'acps-site-toolkit' ); ?></a></li>
			<li><a href="#caching"><?php esc_html_e( '12. Caching — read this if a change won’t show', 'acps-site-toolkit' ); ?></a></li>
			<li><a href="#trouble"><?php esc_html_e( '13. Troubleshooting', 'acps-site-toolkit' ); ?></a></li>
		</ul>
	</nav>

	<div class="acps-card" id="overview">
		<h2>1. <?php esc_html_e( 'Overview & where things live', 'acps-site-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Everything is under the “Cayden Form Manager” menu in the left admin sidebar:', 'acps-site-toolkit' ); ?></p>
		<ul class="ul-disc">
			<li><strong><?php esc_html_e( 'Feedback', 'acps-site-toolkit' ); ?></strong> — <?php esc_html_e( 'incoming feedback submissions and their status/notes.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Forms', 'acps-site-toolkit' ); ?></strong> — <?php esc_html_e( 'build and edit forms; every form has a shortcode.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Entries', 'acps-site-toolkit' ); ?></strong> — <?php esc_html_e( 'submissions to any form, with CSV export.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Analytics', 'acps-site-toolkit' ); ?></strong> — <?php esc_html_e( 'page traffic, paths, and the feedback/traffic overlay.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Q&A / Help', 'acps-site-toolkit' ); ?></strong> — <?php esc_html_e( 'the question-and-answer pairs for the help widget.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Settings', 'acps-site-toolkit' ); ?></strong> — <?php esc_html_e( 'the button, tracking, spam, styling, access, and this guide’s URL.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Help Guide', 'acps-site-toolkit' ); ?></strong> — <?php esc_html_e( 'this page.', 'acps-site-toolkit' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Three ready-made forms are created for you: the Site Feedback form, the Contact us form, and the Media Coverage Request form.', 'acps-site-toolkit' ); ?></p>
	</div>

	<div class="acps-card" id="chat">
		<h2>2. <?php esc_html_e( 'The “Chat with us” button', 'acps-site-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'The round floating button on the front of the site opens the Contact us form (it emails your team — it is not live chat).', 'acps-site-toolkit' ); ?></p>
		<p><strong><?php esc_html_e( 'Where to change it:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'Settings → Feedback.', 'acps-site-toolkit' ); ?></p>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'Show on all pages / only specific pages / all except some.', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Position (corner or edge tab) and the label (used as the button’s screen-reader name).', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Icon image URL, plus a second “hover / open” icon that swaps in on hover, focus, or while the popup is open.', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Circle size per device (laptop / tablet / phone) and background colour — or tick “Transparent background” for just the icon with no circle, ring or shadow.', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Popup width (default 1200px on laptop; shrinks to fit smaller screens).', 'acps-site-toolkit' ); ?></li>
		</ul>
	</div>

	<div class="acps-card" id="feedback">
		<h2>3. <?php esc_html_e( 'Feedback', 'acps-site-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'The Feedback screen lists submissions. Click one to see the full message, the page it was about, and the visitor’s journey before submitting.', 'acps-site-toolkit' ); ?></p>
		<ul class="ul-disc">
			<li><strong><?php esc_html_e( 'Status', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'New, In progress, Resolved, Won’t fix, Spam.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Assign', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'an item to a staff member and add internal notes.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Delete', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'open an item and use “Delete permanently”, or tick rows in the list and use Bulk actions → Move to Trash / Delete permanently.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Export', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'the “Export CSV” button at the top.', 'acps-site-toolkit' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'A standalone feedback page (with the recent-pages picker) can be placed with the shortcode:', 'acps-site-toolkit' ); ?> <code>[acps_feedback]</code></p>
		<p><strong><?php esc_html_e( 'Triage any form here:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'use the “Form” dropdown at the top to switch this inbox to any other form’s submissions — with the same assign, status, notes and delete tools.', 'acps-site-toolkit' ); ?></p>
	</div>

	<div class="acps-card" id="qa">
		<h2>4. <?php esc_html_e( 'Q&A / Help widget', 'acps-site-toolkit' ); ?></h2>
		<p><strong><?php esc_html_e( 'Where:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'Cayden Form Manager → Q&A / Help. Add question/answer pairs (Add question / Remove), then Save.', 'acps-site-toolkit' ); ?></p>
		<p><?php esc_html_e( 'Put the widget on any page:', 'acps-site-toolkit' ); ?></p>
		<ul class="ul-disc">
			<li><code>[acps_qa]</code> — <?php esc_html_e( 'ask box + answers + an “ask a question” contact fallback.', 'acps-site-toolkit' ); ?></li>
			<li><code>[acps_qa show_contact="0"]</code> — <?php esc_html_e( 'answers only, no contact form.', 'acps-site-toolkit' ); ?></li>
			<li><code>[acps_contact]</code> — <?php esc_html_e( 'just the Contact us message form.', 'acps-site-toolkit' ); ?></li>
		</ul>
	</div>

	<div class="acps-card" id="forms">
		<h2>5. <?php esc_html_e( 'Building forms', 'acps-site-toolkit' ); ?></h2>
		<p><strong><?php esc_html_e( 'Where:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'Cayden Form Manager → Forms → Add New (or Edit).', 'acps-site-toolkit' ); ?></p>
		<p><?php esc_html_e( 'The builder has three columns: field types on the left, your form in the middle, and the selected field’s settings on the right.', 'acps-site-toolkit' ); ?></p>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'Click a field type on the left to add it.', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Click a field to edit its label, help text, required toggle, options, etc.', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Reorder with the ▲ / ▼ buttons or the position box — no dragging needed.', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Use “Preview” to see the front-end look, then set Status to Published and Save.', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Form-level settings (below the builder): confirmation message/redirect, admin notifications, auto-reply with merge tags like {field:email}, accent colour.', 'acps-site-toolkit' ); ?></li>
		</ul>
		<p><strong><?php esc_html_e( 'Response limits:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'in form settings you can cap responses per device (by anonymised IP + browser, like the spam protection) and/or a total for the whole form; 0 means unlimited. Over-limit submissions are turned away with your message.', 'acps-site-toolkit' ); ?></p>
		<p><?php esc_html_e( 'On the Forms list you can also Duplicate a form or Delete it.', 'acps-site-toolkit' ); ?></p>
		<p><strong><?php esc_html_e( 'Import from Google Forms:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'Forms → “Import Google Form”. Paste the public form link; a matching draft is created for you to review and publish.', 'acps-site-toolkit' ); ?></p>
	</div>

	<div class="acps-card" id="conditional">
		<h2>6. <?php esc_html_e( 'Conditional logic & multi-page', 'acps-site-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Select a field, open “Conditional visibility”, and tick to enable it. Choose Show or Hide, ALL (AND) or ANY (OR), then add one or more rules such as:', 'acps-site-toolkit' ); ?></p>
		<p><em><?php esc_html_e( 'Show this field when [Feedback type] [is] [Something’s broken].', 'acps-site-toolkit' ); ?></em></p>
		<p><?php esc_html_e( 'Operators include is, is not, contains, doesn’t contain, greater/less than, is empty, is not empty. Hidden fields are never required.', 'acps-site-toolkit' ); ?></p>
		<p><strong><?php esc_html_e( 'Multi-page:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'give each field a “Page number”, then turn on “Multi-page” in form settings. Visitors get a “Step X of Y” indicator with Back/Next.', 'acps-site-toolkit' ); ?></p>
	</div>

	<div class="acps-card" id="access">
		<h2>7. <?php esc_html_e( 'Sharing / restricting a form', 'acps-site-toolkit' ); ?></h2>
		<p><strong><?php esc_html_e( 'Where:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'the “Access & sharing” section at the bottom of the form builder. Methods can be combined:', 'acps-site-toolkit' ); ?></p>
		<ul class="ul-disc">
			<li><strong><?php esc_html_e( 'Require login', 'acps-site-toolkit' ); ?></strong> — <?php esc_html_e( 'optionally limit to chosen roles (e.g. staff).', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Password', 'acps-site-toolkit' ); ?></strong> — <?php esc_html_e( 'visitors enter a password to reveal the form.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Secret link', 'acps-site-toolkit' ); ?></strong> — <?php esc_html_e( 'tick “Share via a private link” and save. Anyone who opens the copyable link sees the form pop up automatically (on the home page by default) — you don’t need to place it on a page. Regenerate the link any time to revoke the old one.', 'acps-site-toolkit' ); ?></li>
		</ul>
	</div>

	<div class="acps-card" id="placing">
		<h2>8. <?php esc_html_e( 'Placing a form on a page', 'acps-site-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Three ways, all identical in output:', 'acps-site-toolkit' ); ?></p>
		<ul class="ul-disc">
			<li><strong><?php esc_html_e( 'Shortcode', 'acps-site-toolkit' ); ?></strong>: <code>[acps_form id="<?php echo esc_html( (string) $media_id ); ?>"]</code> <?php esc_html_e( '(the ID is shown on the Forms list).', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Block', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'add the “ACPS Form” block and pick the form.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Beaver Builder', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'drop in the “ACPS Form” module and choose the form (or the feedback form).', 'acps-site-toolkit' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Handy IDs on this site:', 'acps-site-toolkit' ); ?>
			<?php esc_html_e( 'Feedback', 'acps-site-toolkit' ); ?> = <code><?php echo esc_html( (string) $fb_id ); ?></code>,
			<?php esc_html_e( 'Contact us', 'acps-site-toolkit' ); ?> = <code><?php echo esc_html( (string) $contact_id ); ?></code>,
			<?php esc_html_e( 'Media Coverage Request', 'acps-site-toolkit' ); ?> = <code><?php echo esc_html( (string) $media_id ); ?></code>.
		</p>
	</div>

	<div class="acps-card" id="entries">
		<h2>9. <?php esc_html_e( 'Entries & CSV export', 'acps-site-toolkit' ); ?></h2>
		<p><strong><?php esc_html_e( 'Where:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'Cayden Form Manager → Entries. Pick a form, search, and open any entry to see all answers plus the page it was submitted from and the visitor’s journey. Use “Export CSV” for a spreadsheet, or “Delete permanently” on an entry.', 'acps-site-toolkit' ); ?></p>
		<p><strong><?php esc_html_e( 'Visitors:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'each submission is linked to the visitor’s unique ID. Cayden Form Manager → Visitors lists everyone; search by ID or name, open one to see all their submissions, and add a name or internal notes. If a form has a field named “accname”, its value automatically becomes that visitor’s name. Search on the Entries screen also matches visitor ID and name.', 'acps-site-toolkit' ); ?></p>
	</div>

	<div class="acps-card" id="analytics">
		<h2>10. <?php esc_html_e( 'Analytics', 'acps-site-toolkit' ); ?></h2>
		<p><strong><?php esc_html_e( 'Where:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'Cayden Form Manager → Analytics. The main table is sorted by the feedback/traffic overlay — pages with lots of traffic AND feedback rise to the top. Click “View” on a page for its came-from / went-to paths. Below are common paths, possible dead ends, and a 30-day trend. Every table is the accessible version of the data.', 'acps-site-toolkit' ); ?></p>
		<p><?php esc_html_e( 'Note: analytics fills in from real visits on the live (cached) site — you may see little data in staging.', 'acps-site-toolkit' ); ?></p>
		<p><strong><?php esc_html_e( 'Unique users:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'total unique users plus new/active counts, from a persistent first-party ID per browser — counted once, never missed (a cleared cookie or a different browser just counts as a new user).', 'acps-site-toolkit' ); ?></p>
		<p><strong><?php esc_html_e( 'Devices, browsers & operating systems:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'a card breaks down sessions, views and average time on page by device type, browser, and OS.', 'acps-site-toolkit' ); ?></p>
		<p><strong><?php esc_html_e( 'Who’s on the site now:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'the live card at the top of Analytics shows pages being read right now (auto-updating) so you can avoid editing a page someone is on.', 'acps-site-toolkit' ); ?></p>
		<p><strong><?php esc_html_e( 'Logged-in admins are not counted', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'in analytics or the visitor live view — your own browsing won’t skew the numbers.', 'acps-site-toolkit' ); ?></p>
	</div>

	<div class="acps-card" id="settings">
		<h2>11. <?php esc_html_e( 'Settings reference', 'acps-site-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Settings live under the WordPress “Settings” menu → Cayden Form Manager, organised into tabs (Features, Feedback, Forms, Analytics, Spam, Appearance, Help, Access & data). One Save button stores every tab.', 'acps-site-toolkit' ); ?></p>
		<ul class="ul-disc">
			<li><strong><?php esc_html_e( 'Features', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'turn whole features on/off — feedback widget, Q&A widget, restricted forms, analytics.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Forms', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'store submitter IP/browser on entries, and the max upload size for form files.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Analytics', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'master switch, what to collect (page views, time on page, referrers, unique users), sampling rate, and which dashboard cards to show.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Feedback', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'button visibility, position, label, resting + hover icon URLs, per-device size, background colour or transparent, popup width, categories, recent-pages count, notification emails.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Journey tracking & privacy', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'turn tracking on/off, consent mode, session idle window, data retention (auto-purge), and how much of the user agent to store.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Spam prevention', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'honeypot, time trap, rate limit, keyword blocklist, and an optional plain-text question. No image CAPTCHA.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Stylesheet', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'the full front-end CSS, editable. Keep the honeypot and focus-outline rules.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Help', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'the Help Guide URL for your own external doc.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Access & data', 'acps-site-toolkit' ); ?></strong>: <?php esc_html_e( 'let editors view reports (read-only), and “preserve data on uninstall”.', 'acps-site-toolkit' ); ?></li>
		</ul>
	</div>

	<div class="acps-card" id="caching">
		<h2>12. <?php esc_html_e( 'Caching — read this if a change won’t show', 'acps-site-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'This site uses WP Engine’s full-page edge cache. Front-end changes (the button, forms, styles) are baked into cached pages, so after you save a setting or upload new files you must:', 'acps-site-toolkit' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Load any wp-admin page once (applies pending updates).', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Purge all caches (WP Engine → Caching, or “Purge all caches” in the admin bar).', 'acps-site-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Hard-refresh your browser (Ctrl/Cmd + Shift + R).', 'acps-site-toolkit' ); ?></li>
		</ol>
		<p><?php esc_html_e( 'Tracking and form submissions are built to work through the cache automatically — this only affects seeing visual changes.', 'acps-site-toolkit' ); ?></p>
	</div>

	<div class="acps-card" id="trouble">
		<h2>13. <?php esc_html_e( 'Troubleshooting', 'acps-site-toolkit' ); ?></h2>
		<ul class="ul-disc">
			<li><strong><?php esc_html_e( 'A change isn’t showing:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'purge the cache (section 12).', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( '“Your session expired” on submit:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'the page sat cached a long time — reload and submit again. This is handled automatically for fresh loads.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Feedback emails not arriving:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'check the notification email(s) in Settings and your spam folder.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'A restricted form shows “no access”:', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'confirm the visitor is logged in / has the role, or is using the secret link.', 'acps-site-toolkit' ); ?></li>
			<li><strong><?php esc_html_e( 'Deactivating never deletes data;', 'acps-site-toolkit' ); ?></strong> <?php esc_html_e( 'deleting the plugin only removes data if “preserve data on uninstall” is off.', 'acps-site-toolkit' ); ?></li>
		</ul>
	</div>

	<p><a href="<?php echo esc_url( $menu ); ?>">← <?php esc_html_e( 'Back to Cayden Form Manager', 'acps-site-toolkit' ); ?></a></p>
</div>
