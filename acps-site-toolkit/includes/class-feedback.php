<?php
/**
 * Feedback system (spec §5). The feedback form is a pre-built TEMPLATE of the
 * form engine — one rendering engine, one submission handler, one accessibility
 * implementation (spec §2). This class owns creating that template and the two
 * entry points: the floating modal trigger and the dedicated page shortcode.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feedback.
 */
class Feedback {

	/**
	 * Create the feedback form as a form-engine template if it doesn't exist.
	 * Categories come from settings and are re-synced on each activation.
	 *
	 * @return Form
	 */
	public static function ensure_feedback_form() {
		$form = Form::feedback_form();
		if ( $form ) {
			return $form;
		}

		$form              = new Form();
		$form->title       = __( 'Site Feedback', 'acps-site-toolkit' );
		$form->slug        = 'site-feedback';
		$form->status      = 'published';
		$form->is_feedback = true;
		$form->fields      = self::default_fields();
		$form->settings    = wp_parse_args(
			array(
				'confirmation_type'    => 'message',
				'confirmation_message' => __( 'Thank you — your feedback helps us improve the site.', 'acps-site-toolkit' ),
				'submit_label'         => __( 'Send feedback', 'acps-site-toolkit' ),
				'notify_admin'         => 1,
			),
			Form::default_settings()
		);
		$form->save();
		return $form;
	}

	/**
	 * Default field set for the feedback form (spec §5.3). Kept deliberately
	 * thin: only the comment is required.
	 *
	 * @return array
	 */
	public static function default_fields() {
		$categories = (array) Settings::get( 'feedback_categories' );
		$options    = array();
		foreach ( $categories as $cat ) {
			$options[] = array( 'label' => $cat, 'value' => $cat );
		}

		$fields = array(
			array(
				'key'      => 'page_ref',
				'type'     => 'page_picker',
				'label'    => __( 'Which page is this about?', 'acps-site-toolkit' ),
				'required' => false,
			),
			array(
				'key'      => 'feedback_type',
				'type'     => 'chips',
				'label'    => __( 'What kind of feedback?', 'acps-site-toolkit' ),
				'options'  => $options,
				'required' => false,
			),
			array(
				'key'      => 'comment',
				'type'     => 'long_text',
				'label'    => __( 'Your feedback', 'acps-site-toolkit' ),
				'help'     => __( 'Tell us what happened or what could be better.', 'acps-site-toolkit' ),
				'required' => true,
			),
			array(
				'key'         => 'contact_email',
				'type'        => 'email',
				'label'       => __( 'Want us to follow up? (optional)', 'acps-site-toolkit' ),
				'placeholder' => __( 'you@example.com', 'acps-site-toolkit' ),
				'required'    => false,
			),
		);

		// Optional screenshot upload, shown for "something's broken" reports.
		if ( Settings::get( 'feedback_allow_screenshot' ) ) {
			$broken = isset( $categories[0] ) ? $categories[0] : "Something's broken";
			$fields[] = array(
				'key'         => 'screenshot',
				'type'        => 'file',
				'label'       => __( 'Attach a screenshot (optional)', 'acps-site-toolkit' ),
				'help'        => __( 'A picture of the problem helps us find it faster.', 'acps-site-toolkit' ),
				'required'    => false,
				'conditional' => array( 'field' => 'feedback_type', 'op' => 'is', 'value' => $broken ),
			);
		}

		return $fields;
	}

	/**
	 * Re-sync the feedback categories into the feedback form's chips field when
	 * settings change, without disturbing other customizations.
	 */
	public static function sync_categories() {
		$form = Form::feedback_form();
		if ( ! $form ) {
			return;
		}
		$categories = (array) Settings::get( 'feedback_categories' );
		$options    = array();
		foreach ( $categories as $cat ) {
			$options[] = array( 'label' => $cat, 'value' => $cat );
		}
		$changed = false;
		foreach ( $form->fields as &$field ) {
			if ( isset( $field['key'] ) && 'feedback_type' === $field['key'] ) {
				$field['options'] = $options;
				$changed          = true;
			}
		}
		unset( $field );
		if ( $changed ) {
			$form->save();
		}
	}

	/**
	 * Render the floating trigger + modal into the footer (entry point A,
	 * spec §5.2). The current page id is baked in here — correct even when this
	 * markup is edge-cached, because the cache is keyed per URL.
	 */
	public static function render_modal() {
		if ( is_admin() ) {
			return;
		}
		// Don't show the widget inside the Beaver Builder editor — it's the
		// front end, so is_admin() is false, but we're editing, not visiting.
		if ( class_exists( 'FLBuilderModel' ) && \FLBuilderModel::is_builder_active() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! Settings::should_show_trigger( $post_id ) ) {
			return;
		}
		// The persistent floating button opens the "Contact us" message form
		// (a chat-style entry point — not live chat). Feedback stays available
		// via the [acps_feedback] page and the inbox.
		$label = trim( (string) Settings::get( 'trigger_label', 'Chat with us' ) );
		if ( '' === $label ) {
			// The trigger must ALWAYS have an accessible name, even if an admin
			// clears the label while using an icon-only trigger. Without this the
			// icon button would be announced only as "button" (WCAG 4.1.2 / 2.4.4).
			$label = __( 'Open the feedback form', 'acps-site-toolkit' );
		}
		$position    = Settings::get( 'trigger_position', 'bottom-right' );
		$icon_url    = Settings::get( 'trigger_icon_url', '' );
		$icon_hover  = Settings::get( 'trigger_icon_hover_url', '' );
		$bg          = Settings::get( 'trigger_bg', '' );
		$transparent = (bool) Settings::get( 'trigger_transparent', false );
		$title       = get_the_title( $post_id );

		// Size comes from the per-device CSS variables (see the dynamic block in
		// Plugin::enqueue_frontend); only the optional background is inline here.
		$trigger_style = '';
		if ( $bg && ! $transparent ) {
			$trigger_style .= 'background:' . $bg . ';border-color:' . $bg . ';';
		}
		$trigger_class = $transparent ? ' acps-trigger--transparent' : '';

		// Popup mode: the floating trigger just OPENS an external popup — e.g. a
		// Beaver Builder popup placed in the header containing a Gravity Forms
		// embed — instead of our own feedback modal. We render only the button:
		// it carries any custom class the popup plugin listens for, plus an
		// optional CSS selector to click. No modal or contact form of our own.
		if ( 'popup' === Settings::get( 'trigger_mode', 'feedback' ) ) {
			$popup_id    = trim( (string) Settings::get( 'trigger_popup_id', '' ) );
			$popup_class = trim( (string) Settings::get( 'trigger_popup_class', '' ) );
			$popup_click = trim( (string) Settings::get( 'trigger_popup_click', '' ) );
			$extra       = $trigger_class . ( '' !== $popup_class ? ' ' . $popup_class : '' );

			// Beaver Builder opens a popup when a link to #<Popup ID> is clicked,
			// so when a Popup ID is set we render the trigger as that exact anchor
			// (the method BB expects). Otherwise it's a button carrying a popup
			// class and/or a click-selector for other popup tools.
			if ( '' !== $popup_id ) {
				$tag       = 'a';
				$tag_attrs = ' href="#' . esc_attr( $popup_id ) . '" style="text-decoration:none;' . esc_attr( $trigger_style ) . '"';
			} else {
				$tag       = 'button';
				$click     = '' !== $popup_click ? ' data-acps-popup-click="' . esc_attr( $popup_click ) . '"' : '';
				$tag_attrs = ' type="button" style="' . esc_attr( $trigger_style ) . '"' . $click;
			}
			?>
			<div class="acps-feedback-root acps-pos-<?php echo esc_attr( $position ); ?>" data-acps-mode="popup">
				<?php if ( $icon_url ) : ?>
					<<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput ?> class="acps-feedback-trigger acps-feedback-trigger--icon<?php echo $icon_hover ? ' has-hover-icon' : ''; ?><?php echo esc_attr( $extra ); ?>" aria-haspopup="dialog"<?php echo $tag_attrs; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
						<img class="acps-feedback-trigger__img acps-icon-rest" src="<?php echo esc_url( $icon_url ); ?>" alt="<?php echo esc_attr( $label ); ?>">
						<?php if ( $icon_hover ) : ?>
							<img class="acps-feedback-trigger__img acps-icon-hover" src="<?php echo esc_url( $icon_hover ); ?>" alt="" aria-hidden="true">
						<?php endif; ?>
					</<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php else : ?>
					<<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput ?> class="acps-feedback-trigger<?php echo esc_attr( $extra ); ?>" aria-haspopup="dialog"<?php echo $tag_attrs; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
						<span class="acps-feedback-trigger__icon" aria-hidden="true">&#128172;</span>
						<span class="acps-feedback-trigger__label"><?php echo esc_html( $label ); ?></span>
					</<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		// Feedback mode (default): render the trigger + our own modal + contact form.
		$form = Form::find_by_slug( Help::CONTACT_SLUG );
		if ( ! $form ) {
			$form = Help::ensure_contact_form();
		}
		if ( ! $form ) {
			return;
		}
		$form_html = Form_Renderer::render( $form, array( 'post_id' => $post_id ) );

		// Child-theme override (receives $form, $form_html, $label, $position,
		// $post_id, $title).
		$override = Form_Renderer::locate_template( 'feedback-modal.php' );
		if ( $override ) {
			include $override;
			return;
		}
		?>
		<div class="acps-feedback-root acps-pos-<?php echo esc_attr( $position ); ?>" data-current-page-id="<?php echo esc_attr( $post_id ); ?>" data-current-page-title="<?php echo esc_attr( $title ); ?>">
			<?php if ( $icon_url ) : ?>
				<?php // Circular icon-only trigger. The icon image carries the accessible
				// name via its alt text, so no empty alt and no redundant aria-label. ?>
				<button type="button" class="acps-feedback-trigger acps-feedback-trigger--icon<?php echo $icon_hover ? ' has-hover-icon' : ''; ?><?php echo esc_attr( $trigger_class ); ?>" aria-haspopup="dialog" aria-controls="acps-feedback-dialog" style="<?php echo esc_attr( $trigger_style ); ?>">
					<img class="acps-feedback-trigger__img acps-icon-rest" src="<?php echo esc_url( $icon_url ); ?>" alt="<?php echo esc_attr( $label ); ?>">
					<?php if ( $icon_hover ) : ?>
						<?php // Hover image is a purely visual duplicate — keep it out of the a11y tree. ?>
						<img class="acps-feedback-trigger__img acps-icon-hover" src="<?php echo esc_url( $icon_hover ); ?>" alt="" aria-hidden="true">
					<?php endif; ?>
				</button>
			<?php else : ?>
				<button type="button" class="acps-feedback-trigger<?php echo esc_attr( $trigger_class ); ?>" aria-haspopup="dialog" aria-controls="acps-feedback-dialog" style="<?php echo esc_attr( $trigger_style ); ?>">
					<span class="acps-feedback-trigger__icon" aria-hidden="true">&#128172;</span>
					<span class="acps-feedback-trigger__label"><?php echo esc_html( $label ); ?></span>
				</button>
			<?php endif; ?>

			<div class="acps-modal-overlay" hidden>
				<div class="acps-modal" id="acps-feedback-dialog" role="dialog" aria-modal="true" aria-labelledby="acps-feedback-title">
					<div class="acps-modal__header">
						<h2 class="acps-modal__title" id="acps-feedback-title"><?php esc_html_e( 'Chat with us', 'acps-site-toolkit' ); ?></h2>
						<button type="button" class="acps-modal__close" aria-label="<?php esc_attr_e( 'Close', 'acps-site-toolkit' ); ?>">&times;</button>
					</div>
					<div class="acps-modal__body">
						<?php echo $form_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Render the dedicated feedback page (entry point B) — the same form, but
	 * the page picker leans on journey history (spec §5.2 B).
	 *
	 * @return string
	 */
	public static function render_page() {
		$form = Form::feedback_form();
		if ( ! $form ) {
			return '';
		}
		return '<div class="acps-feedback-page">' . Form_Renderer::render( $form, array( 'post_id' => get_the_ID() ?: 0 ) ) . '</div>';
	}
}
