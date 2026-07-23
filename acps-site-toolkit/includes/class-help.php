<?php
/**
 * Help surfaces: a "Contact us" message form (emails the team) and a
 * self-service Q&A / FAQ widget with pre-set answers that falls through to the
 * contact form when no answer fits.
 *
 * The contact form is another template of the form engine (like feedback), so
 * it inherits the engine's accessibility, spam handling, and cache-safe token
 * flow. The Q&A content is static and rendered server-side, so it is fully
 * cache-safe; searching/filtering happens client-side in qa.js.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Help.
 */
class Help {

	const CONTACT_SLUG = 'contact-us';
	const QA_OPTION    = 'acps_st_qa';

	/**
	 * Create the contact form template if missing.
	 *
	 * @return Form
	 */
	public static function ensure_contact_form() {
		$form = Form::find_by_slug( self::CONTACT_SLUG );
		if ( $form ) {
			return $form;
		}

		$form         = new Form();
		$form->title  = __( 'Contact us', 'acps-site-toolkit' );
		$form->slug   = self::CONTACT_SLUG;
		$form->status = 'published';
		$form->fields = array(
			array( 'key' => 'name', 'type' => 'short_text', 'label' => __( 'Your name', 'acps-site-toolkit' ), 'required' => false ),
			array( 'key' => 'email', 'type' => 'email', 'label' => __( 'Your email', 'acps-site-toolkit' ), 'required' => true, 'help' => __( 'So we can reply to you.', 'acps-site-toolkit' ) ),
			array( 'key' => 'subject', 'type' => 'short_text', 'label' => __( 'Subject', 'acps-site-toolkit' ), 'required' => false ),
			array( 'key' => 'message', 'type' => 'long_text', 'label' => __( 'Message', 'acps-site-toolkit' ), 'required' => true ),
		);
		$form->settings = wp_parse_args(
			array(
				'submit_label'         => __( 'Send message', 'acps-site-toolkit' ),
				'confirmation_message' => __( "Thanks for reaching out — we'll get back to you by email.", 'acps-site-toolkit' ),
				'notify_admin'         => 1,
				'notify_subject'       => __( 'New contact message: {field:subject}', 'acps-site-toolkit' ),
				'autoreply_enable'     => 1,
				'autoreply_field'      => 'email',
				'autoreply_subject'    => __( 'We received your message', 'acps-site-toolkit' ),
				'autoreply_body'       => __( "Thanks for getting in touch. We've received your message and will reply soon.", 'acps-site-toolkit' ),
			),
			Form::default_settings()
		);
		$form->save();
		return $form;
	}

	/**
	 * Render the contact form.
	 *
	 * @return string
	 */
	public static function render_contact() {
		$form = Form::find_by_slug( self::CONTACT_SLUG );
		if ( ! $form ) {
			$form = self::ensure_contact_form();
		}
		return '<div class="acps-contact">' . Form_Renderer::render( $form, array( 'post_id' => get_the_ID() ?: 0 ) ) . '</div>';
	}

	/**
	 * Get the Q&A items.
	 *
	 * @return array[] Each: q, a.
	 */
	public static function qa_items() {
		$data = get_option( self::QA_OPTION, array() );
		$items = ( is_array( $data ) && ! empty( $data['items'] ) ) ? $data['items'] : array();
		$out   = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['q'] ) ) {
				$out[] = array(
					'q' => (string) $item['q'],
					'a' => isset( $item['a'] ) ? (string) $item['a'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * Save Q&A items (from the admin screen).
	 *
	 * @param array $items Raw items.
	 */
	public static function save_qa( $items ) {
		$clean = array();
		foreach ( (array) $items as $item ) {
			$q = isset( $item['q'] ) ? sanitize_text_field( $item['q'] ) : '';
			$a = isset( $item['a'] ) ? wp_kses_post( $item['a'] ) : '';
			if ( '' !== trim( $q ) ) {
				$clean[] = array( 'q' => $q, 'a' => $a );
			}
		}
		update_option( self::QA_OPTION, array( 'items' => $clean ) );
	}

	/**
	 * Render the Q&A / help widget: searchable accordion of pre-set answers,
	 * with an "ask a question" fallback to the contact form.
	 *
	 * @param array $atts show_contact (bool), title.
	 * @return string
	 */
	public static function render_qa( $atts = array() ) {
		$atts = wp_parse_args(
			$atts,
			array(
				'show_contact' => true,
				'title'        => __( 'Questions & answers', 'acps-site-toolkit' ),
			)
		);
		$items = self::qa_items();

		ob_start();
		?>
		<div class="acps-qa" data-acps-qa>
			<h2 class="acps-qa__title"><?php echo esc_html( $atts['title'] ); ?></h2>

			<div class="acps-qa__searchwrap">
				<label class="acps-label" for="acps-qa-search"><?php esc_html_e( 'Ask a question', 'acps-site-toolkit' ); ?></label>
				<input type="search" id="acps-qa-search" class="acps-qa__search acps-input" placeholder="<?php esc_attr_e( 'Type your question…', 'acps-site-toolkit' ); ?>" data-acps-qa-search autocomplete="off">
				<?php if ( $items ) : ?>
					<p class="acps-qa__hint description"><?php esc_html_e( 'Matching answers appear below as you type — or browse the list.', 'acps-site-toolkit' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( $items ) : ?>
				<ul class="acps-qa__list" data-acps-qa-list>
					<?php foreach ( $items as $i => $item ) :
						$panel_id = 'acps-qa-panel-' . $i;
						$btn_id   = 'acps-qa-btn-' . $i;
						?>
						<li class="acps-qa__item" data-acps-qa-item data-q="<?php echo esc_attr( wp_strip_all_tags( $item['q'] . ' ' . $item['a'] ) ); ?>">
							<h3 class="acps-qa__q">
								<button type="button" class="acps-qa__toggle" id="<?php echo esc_attr( $btn_id ); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>">
									<span class="acps-qa__q-text"><?php echo esc_html( $item['q'] ); ?></span>
									<span class="acps-qa__icon" aria-hidden="true">+</span>
								</button>
							</h3>
							<div class="acps-qa__a" id="<?php echo esc_attr( $panel_id ); ?>" role="region" aria-labelledby="<?php echo esc_attr( $btn_id ); ?>" hidden>
								<?php echo wp_kses_post( wpautop( $item['a'] ) ); ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="acps-qa__noresults" data-acps-qa-noresults hidden><?php esc_html_e( 'No matching questions.', 'acps-site-toolkit' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No questions have been added yet.', 'acps-site-toolkit' ); ?></p>
			<?php endif; ?>

			<?php if ( $atts['show_contact'] ) : ?>
				<div class="acps-qa__ask">
					<p class="acps-qa__ask-prompt"><?php esc_html_e( "Didn't find your answer?", 'acps-site-toolkit' ); ?>
						<button type="button" class="acps-btn acps-qa__ask-btn" aria-expanded="false" data-acps-qa-ask><?php esc_html_e( 'Ask a question', 'acps-site-toolkit' ); ?></button>
					</p>
					<div class="acps-qa__contact" data-acps-qa-contact hidden>
						<?php echo self::render_contact(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
