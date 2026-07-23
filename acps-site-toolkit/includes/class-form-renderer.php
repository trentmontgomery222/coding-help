<?php
/**
 * Accessible form renderer (spec §8.2).
 *
 * Getting this markup right once means every form — including the feedback
 * form, which is just a template of this engine — inherits the accessibility
 * work (spec §8.4).
 *
 * CACHING NOTE (spec §7.5): the security nonce and time-trap token are NOT
 * printed here. Baking a nonce into edge-cached HTML makes it go stale and
 * submissions fail. The markup carries only placeholders; forms.js fetches the
 * token bundle after load and injects the hidden inputs. This whole block of
 * HTML is therefore safe to cache.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form_Renderer.
 */
class Form_Renderer {

	/**
	 * Render a form to an HTML string.
	 *
	 * @param Form  $form Form to render.
	 * @param array $args Context: post_id (page the form sits on), instance suffix.
	 * @return string
	 */
	public static function render( Form $form, $args = array() ) {
		$fields = Field_Types::normalize_list( $form->fields );
		if ( empty( $fields ) && ! $form->is_feedback ) {
			return '';
		}

		// Allow a child theme to override the whole template (spec §10).
		$override = self::locate_template( 'form.php' );
		if ( $override ) {
			ob_start();
			$acps_form   = $form;   // phpcs:ignore
			$acps_fields = $fields; // phpcs:ignore
			$acps_args   = $args;   // phpcs:ignore
			include $override;
			return ob_get_clean();
		}

		$uid      = 'acps-form-' . $form->id . '-' . wp_rand( 1000, 9999 );
		$post_id  = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : ( get_the_ID() ?: 0 );
		$multipage = ! empty( $form->settings['multipage'] );
		$pages    = self::group_pages( $fields );
		$style    = self::style_attr( $form );

		ob_start();
		?>
		<div class="acps-form-wrap" <?php echo $style; // phpcs:ignore ?>>
			<form class="acps-form" id="<?php echo esc_attr( $uid ); ?>"
				data-acps-form="<?php echo esc_attr( $form->id ); ?>"
				data-acps-page="<?php echo esc_attr( $post_id ); ?>"
				data-multipage="<?php echo $multipage ? '1' : '0'; ?>"
				novalidate>

				<?php // Error summary: focus is moved here on failed submit (SC 3.3.1/3.3.3). ?>
				<div class="acps-error-summary" id="<?php echo esc_attr( $uid ); ?>-errors"
					role="alert" tabindex="-1" hidden>
					<h2 class="acps-error-summary__title"><?php esc_html_e( 'There is a problem', 'acps-site-toolkit' ); ?></h2>
					<ul class="acps-error-summary__list"></ul>
				</div>

				<?php if ( $multipage && count( $pages ) > 1 ) : ?>
					<p class="acps-step-indicator" aria-live="polite">
						<?php
						/* translators: 1: current step, 2: total steps */
						printf( esc_html__( 'Step %1$s of %2$s', 'acps-site-toolkit' ), '<span class="acps-step-current">1</span>', '<span class="acps-step-total">' . esc_html( count( $pages ) ) . '</span>' );
						?>
					</p>
				<?php endif; ?>

				<?php foreach ( $pages as $page_num => $page_fields ) : ?>
					<div class="acps-page" data-page="<?php echo esc_attr( $page_num ); ?>" <?php echo ( $multipage && $page_num > 1 ) ? 'hidden' : ''; ?>>
						<?php foreach ( $page_fields as $field ) : ?>
							<?php echo self::render_field( $field, $uid ); // phpcs:ignore ?>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>

				<?php // Honeypot: real name is injected by JS. Hidden from sighted AND AT users (spec §7.4/§8). ?>
				<div class="acps-hp" aria-hidden="true">
					<label>Leave this field blank
						<input type="text" data-acps-hp="1" tabindex="-1" autocomplete="off">
					</label>
				</div>

				<?php if ( ! empty( $form->settings['spam_challenge_q'] ) || Settings::get( 'spam_challenge_enable' ) ) : ?>
					<?php echo self::render_challenge( $uid ); // phpcs:ignore ?>
				<?php endif; ?>

				<?php // Token placeholders — populated after load, never cached. ?>
				<input type="hidden" name="acps_nonce" value="">
				<input type="hidden" name="acps_ts" value="">
				<input type="hidden" name="acps_session" value="" data-acps-session-slot="1">
				<input type="hidden" name="acps_form_id" value="<?php echo esc_attr( $form->id ); ?>">
				<input type="hidden" name="acps_page_id" value="<?php echo esc_attr( $post_id ); ?>">

				<div class="acps-actions">
					<?php if ( $multipage && count( $pages ) > 1 ) : ?>
						<button type="button" class="acps-btn acps-prev" hidden><?php esc_html_e( 'Back', 'acps-site-toolkit' ); ?></button>
						<button type="button" class="acps-btn acps-next"><?php esc_html_e( 'Next', 'acps-site-toolkit' ); ?></button>
					<?php endif; ?>
					<button type="submit" class="acps-btn acps-submit" <?php echo ( $multipage && count( $pages ) > 1 ) ? 'hidden' : ''; ?>>
						<?php echo esc_html( $form->settings['submit_label'] ?: __( 'Submit', 'acps-site-toolkit' ) ); ?>
					</button>
				</div>

				<?php // Success / status region (spec §8.2 role=status). ?>
				<div class="acps-status" role="status" aria-live="polite"></div>

				<noscript>
					<p class="acps-noscript"><?php esc_html_e( 'This form requires JavaScript to guard against spam and to work with the site cache.', 'acps-site-toolkit' ); ?></p>
				</noscript>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a single field with full accessible markup.
	 *
	 * @param array  $field Normalized field.
	 * @param string $uid   Form unique id prefix.
	 * @return string
	 */
	public static function render_field( $field, $uid ) {
		$type = $field['type'];
		$fid  = $uid . '-' . $field['key'];
		$name = 'fields[' . $field['key'] . ']';

		// Non-input presentational fields.
		if ( 'section' === $type ) {
			return '<hr class="acps-section-break" aria-hidden="true">' .
				( $field['label'] ? '<h3 class="acps-section-title">' . esc_html( $field['label'] ) . '</h3>' : '' ) .
				( $field['content'] ? '<div class="acps-section-content">' . wp_kses_post( wpautop( $field['content'] ) ) . '</div>' : '' );
		}
		if ( 'heading' === $type ) {
			return ( $field['label'] ? '<h3 class="acps-field-heading">' . esc_html( $field['label'] ) . '</h3>' : '' ) .
				( $field['content'] ? '<div class="acps-field-static">' . wp_kses_post( wpautop( $field['content'] ) ) . '</div>' : '' );
		}
		if ( 'hidden' === $type ) {
			$val = self::conditional_default( $field );
			return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '">';
		}

		// Describedby: help text + a reserved slot for the field's inline error.
		$describedby = array();
		$help_id     = $fid . '-help';
		$err_id      = $fid . '-error';
		if ( '' !== $field['help'] ) {
			$describedby[] = $help_id;
		}
		$describedby[]  = $err_id;
		$describedby_at = 'aria-describedby="' . esc_attr( implode( ' ', $describedby ) ) . '"';

		$required_at = $field['required'] ? 'aria-required="true"' : '';
		$req_mark    = $field['required']
			? ' <span class="acps-required">(' . esc_html__( 'required', 'acps-site-toolkit' ) . ')</span>'
			: '';

		$cond_attr = self::conditional_attr( $field );

		$is_group = in_array( $type, array( 'radio', 'checkbox', 'chips' ), true );

		ob_start();
		echo '<div class="acps-field acps-field--' . esc_attr( $type ) . '" data-key="' . esc_attr( $field['key'] ) . '" ' . $cond_attr . '>'; // phpcs:ignore

		if ( $is_group ) {
			// Grouped controls MUST be in a fieldset/legend (spec §8.2). Help and
			// error are associated to the fieldset via aria-describedby.
			echo '<fieldset class="acps-fieldset" ' . $describedby_at . ' ' . $required_at . '>'; // phpcs:ignore
			echo '<legend class="acps-legend">' . esc_html( $field['label'] ) . $req_mark . '</legend>'; // phpcs:ignore
			if ( '' !== $field['help'] ) {
				echo '<p class="acps-help" id="' . esc_attr( $help_id ) . '">' . esc_html( $field['help'] ) . '</p>';
			}
			echo self::render_choices( $field, $name, $fid ); // phpcs:ignore
			echo '<p class="acps-field-error" id="' . esc_attr( $err_id ) . '" role="alert"></p>';
			echo '</fieldset>';
		} else {
			// A real, programmatically-associated <label>. Placeholders are NOT
			// labels (spec §8.2).
			echo '<label class="acps-label" for="' . esc_attr( $fid ) . '">' . esc_html( $field['label'] ) . $req_mark . '</label>'; // phpcs:ignore
			if ( '' !== $field['help'] ) {
				echo '<p class="acps-help" id="' . esc_attr( $help_id ) . '">' . esc_html( $field['help'] ) . '</p>';
			}
			echo self::render_control( $field, $name, $fid, $required_at, $describedby_at ); // phpcs:ignore
			echo '<p class="acps-field-error" id="' . esc_attr( $err_id ) . '" role="alert"></p>';
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Render the input control for non-group field types.
	 *
	 * @param array  $field       Field.
	 * @param string $name        Input name.
	 * @param string $fid         Field DOM id.
	 * @param string $required_at aria-required attr.
	 * @param string $describedby aria-describedby attr.
	 * @return string
	 */
	private static function render_control( $field, $name, $fid, $required_at, $describedby ) {
		$type        = $field['type'];
		$placeholder = $field['placeholder'] ? 'placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '';
		$default     = esc_attr( self::conditional_default( $field ) );
		$autocomplete = self::autocomplete_for( $field );

		switch ( $type ) {
			case 'long_text':
				return '<textarea class="acps-input" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" rows="5" '
					. $required_at . ' ' . $describedby . ' ' . $placeholder . '>' . esc_textarea( $field['default'] ) . '</textarea>';

			case 'email':
				return '<input type="email" class="acps-input" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="' . $default . '" '
					. $required_at . ' ' . $describedby . ' ' . $placeholder . ' autocomplete="email">';

			case 'number':
				return '<input type="number" class="acps-input" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="' . $default . '" '
					. $required_at . ' ' . $describedby . ' ' . $placeholder . '>';

			case 'date':
				return '<input type="date" class="acps-input" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="' . $default . '" '
					. $required_at . ' ' . $describedby . '>';

			case 'time':
				return '<input type="time" class="acps-input" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="' . $default . '" '
					. $required_at . ' ' . $describedby . '>';

			case 'file':
				// A real file input (a button), never a drag-only drop zone (SC 2.5.7).
				return '<input type="file" class="acps-input acps-file" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" '
					. $required_at . ' ' . $describedby . '>';

			case 'dropdown':
				$out = '<select class="acps-input" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" ' . $required_at . ' ' . $describedby . '>';
				$out .= '<option value="">' . esc_html__( 'Choose…', 'acps-site-toolkit' ) . '</option>';
				foreach ( $field['options'] as $opt ) {
					$sel  = ( $field['default'] === $opt['value'] ) ? ' selected' : '';
					$out .= '<option value="' . esc_attr( $opt['value'] ) . '"' . $sel . '>' . esc_html( $opt['label'] ) . '</option>';
				}
				$out .= '</select>';
				return $out;

			case 'page_picker':
				// The feedback page picker (spec §5.3). Options are injected after
				// load by feedback.js: "the page I was just on", the last N pages
				// by TITLE, "the site in general", plus an "other page" fallback.
				// Rendering it as a real <select> keeps it fully keyboard/AT usable.
				$out  = '<select class="acps-input acps-page-picker" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" data-acps-pagepicker="1" ' . $required_at . ' ' . $describedby . '>';
				$out .= '<option value="">' . esc_html__( 'Loading your recent pages…', 'acps-site-toolkit' ) . '</option>';
				$out .= '<option value="__general__">' . esc_html__( 'The site in general', 'acps-site-toolkit' ) . '</option>';
				$out .= '<option value="__other__">' . esc_html__( 'Another page (search)…', 'acps-site-toolkit' ) . '</option>';
				$out .= '</select>';
				// Fallback free-text search field, revealed when "Another page" is chosen.
				$out .= '<div class="acps-page-picker-other" hidden>';
				$out .= '<label class="acps-label" for="' . esc_attr( $fid ) . '-other">' . esc_html__( 'Which page? Type to search', 'acps-site-toolkit' ) . '</label>';
				$out .= '<input type="text" class="acps-input" id="' . esc_attr( $fid ) . '-other" name="fields[' . esc_attr( $field['key'] ) . '_other]" autocomplete="off">';
				$out .= '</div>';
				return $out;

			case 'scale':
				return self::render_scale( $field, $name, $fid, $required_at, $describedby );

			case 'rating':
				return self::render_rating( $field, $name, $fid, $required_at, $describedby );

			case 'short_text':
			default:
				$ac = $autocomplete ? 'autocomplete="' . esc_attr( $autocomplete ) . '"' : '';
				return '<input type="text" class="acps-input" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="' . $default . '" '
					. $required_at . ' ' . $describedby . ' ' . $placeholder . ' ' . $ac . '>';
		}
	}

	/**
	 * Render choices for radio / checkbox / chips.
	 *
	 * @param array  $field Field.
	 * @param string $name  Base name.
	 * @param string $fid   Field id prefix.
	 * @return string
	 */
	private static function render_choices( $field, $name, $fid ) {
		$type  = $field['type'];
		$multi = ( 'checkbox' === $type );
		$input = $multi ? 'checkbox' : 'radio';
		$iname = $multi ? $name . '[]' : $name;
		$class = ( 'chips' === $type ) ? 'acps-choices acps-choices--chips' : 'acps-choices';

		$out = '<div class="' . esc_attr( $class ) . '">';
		$i   = 0;
		foreach ( $field['options'] as $opt ) {
			$oid = $fid . '-opt-' . $i;
			$out .= '<div class="acps-choice">';
			$out .= '<input type="' . $input . '" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $iname ) . '" value="' . esc_attr( $opt['value'] ) . '">';
			$out .= '<label for="' . esc_attr( $oid ) . '">' . esc_html( $opt['label'] ) . '</label>';
			$out .= '</div>';
			$i++;
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * Linear scale as an accessible radio group.
	 */
	private static function render_scale( $field, $name, $fid, $required_at, $describedby ) {
		$min = min( $field['scale_min'], $field['scale_max'] );
		$max = max( $field['scale_min'], $field['scale_max'] );
		$out = '<div class="acps-scale" role="radiogroup" ' . $describedby . '>';
		for ( $v = $min; $v <= $max; $v++ ) {
			$oid  = $fid . '-s-' . $v;
			$out .= '<span class="acps-scale__item"><input type="radio" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '">';
			$out .= '<label for="' . esc_attr( $oid ) . '">' . esc_html( $v ) . '</label></span>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * Rating as an accessible radio group with numeric labels (star visuals via CSS).
	 */
	private static function render_rating( $field, $name, $fid, $required_at, $describedby ) {
		$max = max( 2, min( 10, $field['scale_max'] ?: 5 ) );
		$out = '<div class="acps-rating" role="radiogroup" ' . $describedby . '>';
		for ( $v = 1; $v <= $max; $v++ ) {
			$oid  = $fid . '-r-' . $v;
			/* translators: %d: rating value */
			$lbl  = sprintf( _n( '%d star', '%d stars', $v, 'acps-site-toolkit' ), $v );
			$out .= '<span class="acps-rating__item"><input type="radio" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '">';
			$out .= '<label for="' . esc_attr( $oid ) . '"><span class="screen-reader-text">' . esc_html( $lbl ) . '</span><span aria-hidden="true">★</span></label></span>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * The optional accessible plain-text challenge (spec §7.4 layer 6).
	 */
	private static function render_challenge( $uid ) {
		$q = Settings::get( 'spam_challenge_q' );
		if ( ! $q ) {
			return '';
		}
		$fid = $uid . '-challenge';
		return '<div class="acps-field acps-field--challenge">'
			. '<label class="acps-label" for="' . esc_attr( $fid ) . '">' . esc_html( $q ) . ' <span class="acps-required">(' . esc_html__( 'required', 'acps-site-toolkit' ) . ')</span></label>'
			. '<input type="text" class="acps-input" id="' . esc_attr( $fid ) . '" name="acps_challenge" autocomplete="off">'
			. '<p class="acps-field-error" id="' . esc_attr( $fid ) . '-error" role="alert"></p>'
			. '</div>';
	}

	/**
	 * autocomplete token for a field (SC 1.3.5).
	 */
	private static function autocomplete_for( $field ) {
		$key = strtolower( $field['key'] . ' ' . $field['label'] );
		if ( 'email' === $field['type'] || false !== strpos( $key, 'email' ) ) {
			return 'email';
		}
		if ( false !== strpos( $key, 'name' ) ) {
			return 'name';
		}
		if ( false !== strpos( $key, 'phone' ) || false !== strpos( $key, 'tel' ) ) {
			return 'tel';
		}
		return '';
	}

	/**
	 * Group fields by page number for multi-page rendering.
	 */
	private static function group_pages( $fields ) {
		$pages = array();
		foreach ( $fields as $f ) {
			$p             = max( 1, (int) $f['page'] );
			$pages[ $p ][] = $f;
		}
		ksort( $pages );
		return $pages ? $pages : array( 1 => array() );
	}

	/**
	 * Emit data-* attributes describing a field's conditional-visibility rule
	 * so forms.js can show/hide it (spec §7.3).
	 */
	private static function conditional_attr( $field ) {
		$c = isset( $field['conditional'] ) ? $field['conditional'] : array();
		if ( empty( $c['enabled'] ) || empty( $c['rules'] ) ) {
			return '';
		}
		// The whole rule set (logic + action + rules) travels as one JSON
		// attribute; forms.js evaluates it. Starts hidden for "show" actions so
		// there is no flash before JS runs; visible for "hide" actions.
		$json   = wp_json_encode( array(
			'logic'  => $c['logic'],
			'action' => $c['action'],
			'rules'  => $c['rules'],
		) );
		$hidden = ( 'show' === $c['action'] ) ? 'hidden' : '';
		return 'data-acps-cond="' . esc_attr( $json ) . '" ' . $hidden;
	}

	/**
	 * Default value, honouring hidden-field dynamic tokens like {page_id}.
	 */
	private static function conditional_default( $field ) {
		$val = $field['default'];
		if ( 'hidden' === $field['type'] ) {
			$val = str_replace(
				array( '{page_id}', '{page_url}' ),
				array( get_the_ID() ?: '', esc_url_raw( home_url( add_query_arg( array(), null ) ) ) ),
				$val
			);
		}
		return $val;
	}

	/**
	 * Inline style hook for per-form accent/width (spec §10 styling defaults).
	 */
	private static function style_attr( Form $form ) {
		$accent = isset( $form->settings['style']['accent'] ) ? $form->settings['style']['accent'] : '';
		$styles = array();
		if ( $accent && preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $accent ) ) {
			$styles[] = '--acps-accent:' . $accent;
		}
		$width = isset( $form->settings['style']['width'] ) ? $form->settings['style']['width'] : 'full';
		if ( 'narrow' === $width ) {
			$styles[] = '--acps-max-width:32rem';
		}
		return $styles ? 'style="' . esc_attr( implode( ';', $styles ) ) . '"' : '';
	}

	/**
	 * Locate a template, letting the child theme override it (spec §10).
	 *
	 * @param string $file Template file name.
	 * @return string|false Absolute path or false.
	 */
	public static function locate_template( $file ) {
		$located = locate_template( array( 'acps-site-toolkit/' . $file ) );
		if ( $located ) {
			return $located;
		}
		return false; // Renderer uses its built-in markup.
	}
}
