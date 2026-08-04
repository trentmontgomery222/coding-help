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
		$form = Form::find_by_slug( Help::CONTACT_SLUG );
		if ( ! $form ) {
			$form = Help::ensure_contact_form();
		}
		if ( ! $form ) {
			return;
		}

		$label     = Settings::get( 'trigger_label', 'Chat with us' );
		$position  = Settings::get( 'trigger_position', 'bottom-right' );
		$icon_url  = Settings::get( 'trigger_icon_url', '' );
		$size      = (int) Settings::get( 'trigger_size', 64 );
		$bg        = Settings::get( 'trigger_bg', '' );
		$title     = get_the_title( $post_id );
		$form_html = Form_Renderer::render( $form, array( 'post_id' => $post_id ) );

		// Inline style drives the circle: size + optional background colour.
		$trigger_style = '--acps-trigger-size:' . max( 24, min( 200, $size ) ) . 'px;';
		if ( $bg ) {
			$trigger_style .= 'background:' . $bg . ';border-color:' . $bg . ';';
		}

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
				<?php // Circular icon-only trigger. The label is the accessible name. ?>
				<button type="button" class="acps-feedback-trigger acps-feedback-trigger--icon" aria-haspopup="dialog" aria-controls="acps-feedback-dialog" aria-label="<?php echo esc_attr( $label ); ?>" style="<?php echo esc_attr( $trigger_style ); ?>">
					<img class="acps-feedback-trigger__img" src="<?php echo esc_url( $icon_url ); ?>" alt="">
				</button>
			<?php else : ?>
				<button type="button" class="acps-feedback-trigger" aria-haspopup="dialog" aria-controls="acps-feedback-dialog" style="<?php echo esc_attr( $trigger_style ); ?>">
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
