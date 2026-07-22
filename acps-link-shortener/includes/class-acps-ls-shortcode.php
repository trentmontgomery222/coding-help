<?php
/**
 * Front-end shortcode: a password-gated link creator.
 *
 * Drop [acps_link_shortener] on any page to give staff a nice, self-hosted
 * alternative to Bitly. Each person signs in with their own name + password
 * (configured under Settings -> Link Shortener). Once unlocked, they can create
 * short links right from the page.
 *
 * Security model:
 * - Passwords are stored hashed (wp_hash_password) in the plugin settings.
 * - Sign-in sets a signed, expiring cookie (HMAC over label+expiry with the
 *   site auth salt) so the person is not asked to retype for 8 hours. No
 *   password is ever stored in the cookie.
 * - Submissions are nonce-checked and processed on template_redirect using the
 *   Post/Redirect/Get pattern, so a refresh never re-creates a link.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles the front-end link creator.
 */
class ACPS_LS_Shortcode {

	const COOKIE      = 'acps_ls_gate';
	const NONCE       = 'acps_ls_shortcode';
	const RESULT_ARG  = 'acps_ls_r';
	const GATE_HOURS  = 8;

	/**
	 * Whether the inline assets have been printed already.
	 *
	 * @var bool
	 */
	private static $assets_done = false;

	/**
	 * Hook shortcode + submission handler.
	 */
	public function register() {
		add_shortcode( 'acps_link_shortener', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_submit' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Gate cookie                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * Set the signed gate cookie for a person.
	 *
	 * @param string $label Person label.
	 */
	private static function set_gate_cookie( $label ) {
		$expires = time() + ( self::GATE_HOURS * HOUR_IN_SECONDS );
		$payload = base64_encode( $label ) . '.' . $expires; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$sig     = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		$value   = $payload . '.' . $sig;

		$path = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		setcookie( self::COOKIE, $value, $expires, $path, defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '', is_ssl(), true );
		$_COOKIE[ self::COOKIE ] = $value; // Available within this same request.
	}

	/**
	 * Clear the gate cookie (sign out).
	 */
	private static function clear_gate_cookie() {
		$path = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		setcookie( self::COOKIE, '', time() - 3600, $path, defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '', is_ssl(), true );
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * The currently signed-in person label from a valid gate cookie, or false.
	 *
	 * @return string|false
	 */
	public static function current_person() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return false;
		}

		$parts = explode( '.', sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
		if ( 3 !== count( $parts ) ) {
			return false;
		}

		list( $b64, $expires, $sig ) = $parts;
		$expected = hash_hmac( 'sha256', $b64 . '.' . $expires, wp_salt( 'auth' ) );

		if ( ! hash_equals( $expected, $sig ) || (int) $expires < time() ) {
			return false;
		}

		$label = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $label ) {
			return false;
		}

		// Confirm the person still exists in settings.
		foreach ( acps_ls_get_people() as $person ) {
			if ( $person['label'] === $label ) {
				return $label;
			}
		}
		return false;
	}

	/* --------------------------------------------------------------------- */
	/* Submission handling (PRG)                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Handle a front-end submission, then redirect back with a result token.
	 */
	public function handle_submit() {
		if ( empty( $_POST['acps_ls_shortcode'] ) ) {
			return;
		}

		// Sign-out request.
		if ( isset( $_POST['acps_ls_signout'] ) ) {
			self::clear_gate_cookie();
			$this->redirect_back( null );
			return;
		}

		if ( ! isset( $_POST['acps_ls_sc_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['acps_ls_sc_nonce'] ) ), self::NONCE ) ) {
			return; // Ignore forged/expired submissions.
		}

		$result = array(
			'ok'          => false,
			'errors'      => array(),
			'short_url'   => '',
			'destination' => '',
			'slug'        => '',
		);

		// Determine the actor: existing cookie, or a fresh name + password.
		$label = self::current_person();
		if ( ! $label ) {
			$name     = isset( $_POST['acps_ls_name'] ) ? sanitize_text_field( wp_unslash( $_POST['acps_ls_name'] ) ) : '';
			$password = isset( $_POST['acps_ls_password'] ) ? (string) wp_unslash( $_POST['acps_ls_password'] ) : '';
			$label    = acps_ls_authenticate_person( $name, $password );
			if ( $label ) {
				self::set_gate_cookie( $label );
			}
		}

		if ( ! $label ) {
			$result['errors']['auth'] = __( 'That name and password did not match. Please try again.', 'acps-link-shortener' );
			$this->store_and_redirect( $result );
			return;
		}

		// If only signing in (no destination submitted), just unlock.
		$raw_dest = isset( $_POST['acps_ls_destination'] ) ? trim( (string) wp_unslash( $_POST['acps_ls_destination'] ) ) : '';
		if ( '' === $raw_dest ) {
			$result['ok']       = true;
			$result['signedin'] = true;
			$this->store_and_redirect( $result );
			return;
		}

		// Validate destination.
		$destination = ACPS_LS_DB::validate_destination( $raw_dest );
		if ( is_wp_error( $destination ) ) {
			$result['errors']['destination'] = $destination->get_error_message();
		}

		// Slug: custom (validated) or auto-generated.
		$custom = isset( $_POST['acps_ls_slug'] ) ? sanitize_title( wp_unslash( $_POST['acps_ls_slug'] ) ) : '';
		if ( '' !== $custom ) {
			$slug_check = ACPS_LS_DB::validate_slug( $custom );
			if ( is_wp_error( $slug_check ) ) {
				$result['errors']['slug'] = $slug_check->get_error_message();
			}
			$slug = $custom;
		} else {
			$slug = ACPS_LS_DB::generate_unique_slug();
		}

		if ( empty( $result['errors'] ) ) {
			$created = ACPS_LS_DB::create(
				array(
					'slug'          => $slug,
					'destination'   => $destination,
					'title'         => '',
					// Front-end links are always 302 (permanent is disabled).
					'redirect_type' => 302,
					'is_active'     => 1,
					'source'        => 'shortcode',
					'creator_label' => $label,
				)
			);

			if ( is_wp_error( $created ) ) {
				$result['errors']['general'] = $created->get_error_message();
			} else {
				$result['ok']          = true;
				$result['short_url']   = acps_ls_short_url( $slug );
				$result['destination'] = $destination;
				$result['slug']        = $slug;
			}
		}

		$this->store_and_redirect( $result );
	}

	/**
	 * Store the result in a short-lived transient and redirect back (PRG).
	 *
	 * @param array $result Result payload.
	 */
	private function store_and_redirect( $result ) {
		$token = wp_generate_password( 20, false, false );
		set_transient( 'acps_ls_sc_' . $token, $result, 5 * MINUTE_IN_SECONDS );
		$this->redirect_back( $token );
	}

	/**
	 * Redirect back to the submitting page, optionally with a result token.
	 *
	 * @param string|null $token Result token or null.
	 */
	private function redirect_back( $token ) {
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = home_url( '/' );
		}
		$back = remove_query_arg( self::RESULT_ARG, $back );
		if ( $token ) {
			$back = add_query_arg( self::RESULT_ARG, $token, $back );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Rendering                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Render the shortcode.
	 *
	 * @param array $atts Shortcode attributes (unused).
	 * @return string
	 */
	public function render( $atts = array() ) {
		$result = $this->pull_result();
		$person = self::current_person();

		ob_start();
		echo $this->assets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
		?>
		<div class="acps-ls-box">
			<h2 class="acps-ls-box__title"><?php esc_html_e( 'Create a short link', 'acps-link-shortener' ); ?></h2>

			<?php
			if ( ! acps_ls_get_people() ) {
				echo '<p class="acps-ls-note">' . esc_html__( 'No users have been set up yet. An administrator can add them under Settings → Link Shortener.', 'acps-link-shortener' ) . '</p>';
				echo '</div>';
				return ob_get_clean();
			}

			// Success / result messaging.
			if ( $result && ! empty( $result['ok'] ) && ! empty( $result['short_url'] ) ) {
				$this->render_success( $result );
			}

			// Error messaging.
			if ( $result && ! empty( $result['errors'] ) ) {
				echo '<div class="acps-ls-alert acps-ls-alert--error" role="alert"><ul>';
				foreach ( $result['errors'] as $msg ) {
					echo '<li>' . esc_html( $msg ) . '</li>';
				}
				echo '</ul></div>';
			}
			?>

			<!-- ARIA live region for copy feedback. -->
			<div class="acps-ls-live screen-reader-text" role="status" aria-live="polite"></div>

			<form method="post" class="acps-ls-form" action="">
				<input type="hidden" name="acps_ls_shortcode" value="1" />
				<?php wp_nonce_field( self::NONCE, 'acps_ls_sc_nonce' ); ?>

				<?php if ( ! $person ) : ?>
					<p class="acps-ls-signedout"><?php esc_html_e( 'Sign in to create links.', 'acps-link-shortener' ); ?></p>
					<div class="acps-ls-field">
						<label for="acps-ls-name"><?php esc_html_e( 'Your name', 'acps-link-shortener' ); ?></label>
						<input type="text" name="acps_ls_name" id="acps-ls-name" autocomplete="username" required />
					</div>
					<div class="acps-ls-field">
						<label for="acps-ls-password"><?php esc_html_e( 'Password', 'acps-link-shortener' ); ?></label>
						<input type="password" name="acps_ls_password" id="acps-ls-password" autocomplete="current-password" required />
					</div>
				<?php else : ?>
					<p class="acps-ls-signedin">
						<?php
						printf(
							/* translators: %s: person name. */
							esc_html__( 'Signed in as %s.', 'acps-link-shortener' ),
							'<strong>' . esc_html( $person ) . '</strong>'
						);
						?>
						<button type="submit" name="acps_ls_signout" value="1" class="acps-ls-link-btn"><?php esc_html_e( 'Sign out', 'acps-link-shortener' ); ?></button>
					</p>
				<?php endif; ?>

				<div class="acps-ls-field">
					<label for="acps-ls-destination"><?php esc_html_e( 'Destination URL', 'acps-link-shortener' ); ?></label>
					<input type="url" name="acps_ls_destination" id="acps-ls-destination" placeholder="https://example.com/long/page" required />
				</div>

				<div class="acps-ls-field">
					<label for="acps-ls-slug"><?php esc_html_e( 'Custom name (optional)', 'acps-link-shortener' ); ?></label>
					<div class="acps-ls-inputgroup">
						<span class="acps-ls-inputgroup__prefix"><?php echo esc_html( trailingslashit( acps_ls_link_base() . ( '' !== ACPS_LS_SLUG_PREFIX ? '/' . ACPS_LS_SLUG_PREFIX : '' ) ) ); ?></span>
						<input type="text" name="acps_ls_slug" id="acps-ls-slug" placeholder="<?php esc_attr_e( 'auto-generated if blank', 'acps-link-shortener' ); ?>" />
					</div>
					<p class="acps-ls-help"><?php esc_html_e( 'Leave blank for a random short name. Lowercase letters, numbers and hyphens only. Each name can point to only one destination.', 'acps-link-shortener' ); ?></p>
				</div>

				<button type="submit" class="acps-ls-submit"><?php esc_html_e( 'Create short link', 'acps-link-shortener' ); ?></button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the success panel with copy button.
	 *
	 * @param array $result Result payload.
	 */
	private function render_success( $result ) {
		$url = $result['short_url'];
		?>
		<div class="acps-ls-alert acps-ls-alert--success" role="status">
			<p class="acps-ls-success__label"><?php esc_html_e( 'Your short link is ready:', 'acps-link-shortener' ); ?></p>
			<div class="acps-ls-result">
				<input type="text" class="acps-ls-result__url" value="<?php echo esc_attr( $url ); ?>" readonly aria-label="<?php esc_attr_e( 'Short URL', 'acps-link-shortener' ); ?>" onfocus="this.select();" />
				<button type="button" class="acps-ls-copy" data-clipboard-text="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'acps-link-shortener' ); ?></button>
			</div>
			<?php if ( ! empty( $result['destination'] ) ) : ?>
				<p class="acps-ls-success__dest">
					<?php
					printf(
						/* translators: %s: destination URL. */
						esc_html__( 'Redirects to: %s', 'acps-link-shortener' ),
						'<span>' . esc_html( $result['destination'] ) . '</span>'
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Fetch (and consume) a stored result by token from the URL.
	 *
	 * @return array|null
	 */
	private function pull_result() {
		if ( empty( $_GET[ self::RESULT_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}
		$token  = sanitize_text_field( wp_unslash( $_GET[ self::RESULT_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = get_transient( 'acps_ls_sc_' . $token );
		if ( false === $result ) {
			return null;
		}
		delete_transient( 'acps_ls_sc_' . $token );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Inline, self-contained CSS + JS (printed once per page).
	 *
	 * @return string
	 */
	private function assets() {
		if ( self::$assets_done ) {
			return '';
		}
		self::$assets_done = true;

		ob_start();
		?>
		<style>
		.acps-ls-box{max-width:640px;margin:1.5rem auto;padding:1.75rem;border:1px solid #d7dbe0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06);font-family:inherit;color:#1d2327;box-sizing:border-box}
		.acps-ls-box *{box-sizing:border-box}
		.acps-ls-box__title{margin:0 0 1rem;font-size:1.4rem;line-height:1.2}
		.acps-ls-field{margin-bottom:1rem}
		.acps-ls-field label{display:block;font-weight:600;margin-bottom:.35rem}
		.acps-ls-field input{width:100%;padding:.6rem .7rem;border:1px solid #8c8f94;border-radius:8px;font-size:1rem}
		.acps-ls-field input:focus,.acps-ls-copy:focus,.acps-ls-submit:focus,.acps-ls-result__url:focus{outline:3px solid #1d4ed8;outline-offset:1px}
		.acps-ls-inputgroup{display:flex;align-items:stretch}
		.acps-ls-inputgroup__prefix{display:flex;align-items:center;padding:.55rem .6rem;background:#f0f0f1;border:1px solid #8c8f94;border-right:0;border-radius:8px 0 0 8px;color:#3c434a;font-size:.9rem;white-space:nowrap}
		.acps-ls-inputgroup input{border-radius:0 8px 8px 0}
		.acps-ls-help{margin:.4rem 0 0;font-size:.85rem;color:#50575e}
		.acps-ls-submit{display:inline-block;margin-top:.5rem;padding:.7rem 1.4rem;background:#1d4ed8;color:#fff;border:0;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer}
		.acps-ls-submit:hover{background:#1740b0}
		.acps-ls-alert{padding:.9rem 1rem;border-radius:8px;margin-bottom:1.1rem}
		.acps-ls-alert--error{background:#fcf0f1;border:1px solid #d63638;color:#8a1f21}
		.acps-ls-alert--error ul{margin:0;padding-left:1.2rem}
		.acps-ls-alert--success{background:#edfaef;border:1px solid #1a7f37;color:#0f5323}
		.acps-ls-success__label{margin:0 0 .5rem;font-weight:600}
		.acps-ls-result{display:flex;gap:.5rem}
		.acps-ls-result__url{flex:1;padding:.55rem .7rem;border:1px solid #8c8f94;border-radius:8px;font-size:1rem;background:#fff}
		.acps-ls-copy{padding:.55rem 1rem;background:#1a7f37;color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer}
		.acps-ls-copy:hover{background:#14682d}
		.acps-ls-success__dest{margin:.6rem 0 0;font-size:.85rem;word-break:break-all}
		.acps-ls-signedin,.acps-ls-signedout{margin:0 0 1rem;font-size:.95rem}
		.acps-ls-link-btn{background:none;border:0;color:#1d4ed8;text-decoration:underline;cursor:pointer;padding:0;font-size:.9rem;margin-left:.5rem}
		.acps-ls-note{color:#50575e}
		.screen-reader-text{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px);white-space:nowrap}
		</style>
		<script>
		(function(){
			document.addEventListener('click',function(e){
				var b=e.target.closest&&e.target.closest('.acps-ls-copy');
				if(!b)return;
				e.preventDefault();
				var text=b.getAttribute('data-clipboard-text')||'';
				var live=b.closest('.acps-ls-box').querySelector('.acps-ls-live');
				function ok(){ if(live){live.textContent='';setTimeout(function(){live.textContent='Short URL copied to clipboard.';},50);} var t=b.textContent;b.textContent='✓';setTimeout(function(){b.textContent=t;},1500); }
				function fb(){ var a=document.createElement('textarea');a.value=text;a.style.position='absolute';a.style.left='-9999px';document.body.appendChild(a);a.select();try{document.execCommand('copy');ok();}catch(x){}document.body.removeChild(a); }
				if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).then(ok).catch(fb);}else{fb();}
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}
