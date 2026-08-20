<?php
/**
 * Front-end shortcode: a password-gated link dashboard.
 *
 * [acps_link_shortener] gives staff a self-hosted Bitly alternative:
 *   1. Sign in with your own name + password (no link required to sign in).
 *   2. Once in, create short links, see how many you have left (per-user limit),
 *      and manage/delete the links you made.
 *
 * Per-user options (set under Settings -> Link Shortener):
 *   - max_links: cap how many shortcode links a person may create (0 = no cap).
 *   - namespace: force a first path segment, e.g. "katherine" so their links are
 *     acpsmd.org/katherine/whatever.
 *
 * Security: passwords hashed; sign-in sets a signed, expiring cookie (HMAC over
 * label+expiry with the site auth salt); every action is nonce-checked and
 * processed on template_redirect with the Post/Redirect/Get pattern.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles the front-end dashboard.
 */
class ACPS_LS_Shortcode {

	const COOKIE     = 'acps_ls_gate';
	const NONCE      = 'acps_ls_shortcode';
	const RESULT_ARG = 'acps_ls_r';
	const GATE_HOURS = 8;

	/**
	 * Whether inline assets were already printed this page.
	 *
	 * @var bool
	 */
	private static $assets_done = false;

	/**
	 * Hook shortcode + submission handler.
	 */
	public function register() {
		add_shortcode( 'acps_link_shortener', array( $this, 'render_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'handle_submit' ) );
	}

	/**
	 * Crash-safe wrapper around render(): a rendering error shows a small note
	 * instead of breaking the whole page the shortcode is on.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function render_shortcode( $atts = array() ) {
		try {
			return $this->render( $atts );
		} catch ( Throwable $e ) {
			if ( function_exists( 'acps_ls_log_error' ) ) {
				acps_ls_log_error( 'shortcode render', $e );
			}
			return '<p>' . esc_html__( 'The link tool is temporarily unavailable. Please try again later.', 'acps-link-shortener' ) . '</p>';
		}
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
		$_COOKIE[ self::COOKIE ] = $value;
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
	 * The signed-in person label from a valid gate cookie, or false.
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

		return acps_ls_get_person( $label ) ? $label : false;
	}

	/**
	 * The current front-end URL (used so PRG returns to the shortcode page).
	 *
	 * @return string
	 */
	private static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return home_url( $uri );
	}

	/**
	 * Print the hidden marker + nonce + return-URL fields shared by every form.
	 */
	private static function form_fields() {
		echo '<input type="hidden" name="acps_ls_shortcode" value="1" />';
		echo '<input type="hidden" name="acps_ls_return" value="' . esc_url( self::current_url() ) . '" />';
		wp_nonce_field( self::NONCE, 'acps_ls_sc_nonce' );
	}

	/* --------------------------------------------------------------------- */
	/* Submission handling (PRG)                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Handle a front-end submission, then redirect back with a result token.
	 */
	public function handle_submit() {
		try {
			$this->handle_submit_inner();
		} catch ( Throwable $e ) {
			// A submission error must not break the page load.
			if ( function_exists( 'acps_ls_log_error' ) ) {
				acps_ls_log_error( 'shortcode submit', $e );
			}
		}
	}

	/**
	 * The actual submission logic (wrapped by handle_submit()).
	 */
	private function handle_submit_inner() {
		if ( empty( $_POST['acps_ls_shortcode'] ) ) {
			return;
		}

		// Sign out (no nonce needed — it only clears a cookie).
		if ( isset( $_POST['acps_ls_signout'] ) ) {
			self::clear_gate_cookie();
			$this->redirect_back( null );
			return;
		}

		if ( ! isset( $_POST['acps_ls_sc_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['acps_ls_sc_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		$result = array( 'ok' => false, 'errors' => array() );

		// --- Set password via a one-time setup link ---
		if ( isset( $_POST['acps_ls_do_setpw'] ) ) {
			$token = isset( $_POST['acps_ls_setup_token'] ) ? sanitize_text_field( wp_unslash( $_POST['acps_ls_setup_token'] ) ) : '';
			$label = acps_ls_lookup_setup_token( $token );

			if ( ! $label ) {
				$result['errors']['setup'] = __( 'This setup link is invalid or has expired. Ask your administrator for a new one.', 'acps-link-shortener' );
				$this->store_and_redirect( $result );
				return;
			}

			$pw1 = isset( $_POST['acps_ls_new_password'] ) ? (string) wp_unslash( $_POST['acps_ls_new_password'] ) : '';
			$pw2 = isset( $_POST['acps_ls_new_password2'] ) ? (string) wp_unslash( $_POST['acps_ls_new_password2'] ) : '';

			if ( strlen( $pw1 ) < 8 ) {
				$result['errors']['password'] = __( 'Please use a password of at least 8 characters.', 'acps-link-shortener' );
			} elseif ( $pw1 !== $pw2 ) {
				$result['errors']['password'] = __( 'The two passwords do not match.', 'acps-link-shortener' );
			}

			if ( empty( $result['errors'] ) ) {
				acps_ls_set_person_password( $label, $pw1 );
				acps_ls_consume_setup_token( $token );
				self::set_gate_cookie( $label ); // Sign them straight in.
				$result['ok']       = true;
				$result['signedin'] = true;
			}
			$this->store_and_redirect( $result );
			return;
		}

		// --- Sign in (separate action; does NOT create a link) ---
		if ( isset( $_POST['acps_ls_do_signin'] ) ) {
			$label = self::current_person();
			if ( ! $label ) {
				$name     = isset( $_POST['acps_ls_name'] ) ? sanitize_text_field( wp_unslash( $_POST['acps_ls_name'] ) ) : '';
				$password = isset( $_POST['acps_ls_password'] ) ? (string) wp_unslash( $_POST['acps_ls_password'] ) : '';
				$label    = acps_ls_authenticate_person( $name, $password );
				if ( ! $label ) {
					$result['errors']['auth'] = __( 'That name and password did not match. Please try again.', 'acps-link-shortener' );
					$this->store_and_redirect( $result );
					return;
				}
				self::set_gate_cookie( $label );
			}
			$result['ok']       = true;
			$result['signedin'] = true;
			$this->store_and_redirect( $result );
			return;
		}

		// Everything below requires an authenticated person.
		$label = self::current_person();
		if ( ! $label ) {
			$result['errors']['auth'] = __( 'Your session expired. Please sign in again.', 'acps-link-shortener' );
			$this->store_and_redirect( $result );
			return;
		}
		$person = acps_ls_get_person( $label );

		// --- Delete one of my own links ---
		if ( isset( $_POST['acps_ls_do_delete'] ) ) {
			$id   = isset( $_POST['acps_ls_link_id'] ) ? absint( wp_unslash( $_POST['acps_ls_link_id'] ) ) : 0;
			$link = $id ? ACPS_LS_DB::get( $id ) : null;

			if ( $link && 'shortcode' === $link->source && strtolower( (string) $link->creator_label ) === strtolower( $label ) ) {
				ACPS_LS_DB::delete( $id );
				$result['ok']      = true;
				$result['deleted'] = true;
			} else {
				$result['errors']['delete'] = __( 'That link could not be removed (it is not one of yours).', 'acps-link-shortener' );
			}
			$this->store_and_redirect( $result );
			return;
		}

		// --- Create a link ---
		if ( isset( $_POST['acps_ls_do_create'] ) ) {
			$max = (int) $person['max_links'];
			if ( $max > 0 && ACPS_LS_DB::count_by_creator( $label ) >= $max ) {
				$result['errors']['limit'] = sprintf(
					/* translators: %d: link limit. */
					__( 'You have reached your limit of %d links. Delete one to create another.', 'acps-link-shortener' ),
					$max
				);
				$this->store_and_redirect( $result );
				return;
			}

			$raw_dest    = isset( $_POST['acps_ls_destination'] ) ? wp_unslash( $_POST['acps_ls_destination'] ) : '';
			$destination = ACPS_LS_DB::validate_destination( $raw_dest );
			if ( is_wp_error( $destination ) ) {
				$result['errors']['destination'] = $destination->get_error_message();
			}

			$namespace = $person['namespace'];
			$userpart  = isset( $_POST['acps_ls_slug'] ) ? sanitize_title( wp_unslash( $_POST['acps_ls_slug'] ) ) : '';

			if ( '' === $userpart ) {
				$slug = ACPS_LS_DB::generate_unique_slug( 6, $namespace );
			} else {
				$slug       = ( '' !== $namespace ) ? $namespace . '/' . $userpart : $userpart;
				$slug_check = ACPS_LS_DB::validate_slug( $slug );
				if ( is_wp_error( $slug_check ) ) {
					$result['errors']['slug'] = $slug_check->get_error_message();
				}
			}

			if ( empty( $result['errors'] ) ) {
				$created = ACPS_LS_DB::create(
					array(
						'slug'          => $slug,
						'destination'   => $destination,
						'title'         => '',
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
				}
			}
			$this->store_and_redirect( $result );
			return;
		}

		// Unknown action: just bounce back.
		$this->redirect_back( null );
	}

	/**
	 * Store a result in a short-lived transient and redirect back (PRG).
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
		// Prefer the page the form was on (carried in a hidden field), so we
		// return to the shortcode page rather than the homepage when the browser
		// does not send a Referer header. wp_safe_redirect keeps it same-host.
		$back = '';
		if ( ! empty( $_POST['acps_ls_return'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$back = esc_url_raw( wp_unslash( $_POST['acps_ls_return'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( ! $back ) {
			$back = wp_get_referer();
		}
		if ( ! $back ) {
			$back = home_url( '/' );
		}
		// Drop the one-time setup param so a consumed link is never re-shown.
		$back = remove_query_arg( array( self::RESULT_ARG, 'acps_ls_setup' ), $back );
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
	 * @param array $atts Attributes (unused).
	 * @return string
	 */
	public function render( $atts = array() ) {
		$result = $this->pull_result();

		// One-time password setup link (?acps_ls_setup=TOKEN).
		if ( isset( $_GET['acps_ls_setup'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $this->render_setup( $result );
		}

		// current_person() returns the label string; the dashboard needs the
		// full person record.
		$label  = self::current_person();
		$person = $label ? acps_ls_get_person( $label ) : null;

		ob_start();
		echo $this->assets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="acps-ls-box">';

		if ( ! acps_ls_get_people() ) {
			echo '<h2 class="acps-ls-box__title">' . esc_html__( 'Create a short link', 'acps-link-shortener' ) . '</h2>';
			echo '<p class="acps-ls-note">' . esc_html__( 'No users have been set up yet. An administrator can add them under Settings → Link Shortener.', 'acps-link-shortener' ) . '</p>';
			echo '</div>';
			return ob_get_clean();
		}

		echo '<div class="acps-ls-live screen-reader-text" role="status" aria-live="polite"></div>';

		$this->render_alerts( $result );

		if ( ! $person ) {
			$this->render_signin();
		} else {
			$this->render_dashboard( $person, $result );
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Render result alerts (success + errors).
	 *
	 * @param array|null $result Result payload.
	 */
	private function render_alerts( $result ) {
		if ( ! $result ) {
			return;
		}

		if ( ! empty( $result['ok'] ) && ! empty( $result['short_url'] ) ) {
			$this->render_success( $result );
		} elseif ( ! empty( $result['deleted'] ) ) {
			echo '<div class="acps-ls-alert acps-ls-alert--success" role="status"><p>' . esc_html__( 'Link deleted.', 'acps-link-shortener' ) . '</p></div>';
		}

		if ( ! empty( $result['errors'] ) ) {
			echo '<div class="acps-ls-alert acps-ls-alert--error" role="alert"><ul>';
			foreach ( $result['errors'] as $msg ) {
				echo '<li>' . esc_html( $msg ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	/**
	 * Render the sign-in-only form.
	 */
	private function render_signin() {
		?>
		<h2 class="acps-ls-box__title"><?php esc_html_e( 'Sign in', 'acps-link-shortener' ); ?></h2>
		<p class="acps-ls-signedout"><?php esc_html_e( 'Sign in to create and manage your short links.', 'acps-link-shortener' ); ?></p>
		<form method="post" class="acps-ls-form" action="">
			<?php self::form_fields(); ?>
			<div class="acps-ls-field">
				<label for="acps-ls-name"><?php esc_html_e( 'Your name', 'acps-link-shortener' ); ?></label>
				<input type="text" name="acps_ls_name" id="acps-ls-name" autocomplete="username" required />
			</div>
			<div class="acps-ls-field">
				<label for="acps-ls-password"><?php esc_html_e( 'Password', 'acps-link-shortener' ); ?></label>
				<input type="password" name="acps_ls_password" id="acps-ls-password" autocomplete="current-password" required />
			</div>
			<button type="submit" name="acps_ls_do_signin" value="1" class="acps-ls-submit"><?php esc_html_e( 'Sign in', 'acps-link-shortener' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render the one-time password-setup screen.
	 *
	 * @param array|null $result Result payload.
	 * @return string
	 */
	private function render_setup( $result ) {
		$token = sanitize_text_field( wp_unslash( $_GET['acps_ls_setup'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$label = acps_ls_lookup_setup_token( $token );

		ob_start();
		echo $this->assets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="acps-ls-box">';
		echo '<div class="acps-ls-live screen-reader-text" role="status" aria-live="polite"></div>';

		$this->render_alerts( $result );

		if ( ! $label ) {
			echo '<h2 class="acps-ls-box__title">' . esc_html__( 'Setup link', 'acps-link-shortener' ) . '</h2>';
			echo '<p class="acps-ls-note">' . esc_html__( 'This setup link is invalid, already used, or expired. Please ask your administrator for a new one.', 'acps-link-shortener' ) . '</p>';
			echo '</div>';
			return ob_get_clean();
		}
		?>
		<h2 class="acps-ls-box__title"><?php esc_html_e( 'Set your password', 'acps-link-shortener' ); ?></h2>
		<p class="acps-ls-signedout">
			<?php
			printf(
				/* translators: %s: person name. */
				esc_html__( 'Welcome, %s. Choose a password to finish setting up your account.', 'acps-link-shortener' ),
				'<strong>' . esc_html( $label ) . '</strong>'
			);
			?>
		</p>
		<form method="post" class="acps-ls-form" action="">
			<?php self::form_fields(); ?>
			<input type="hidden" name="acps_ls_setup_token" value="<?php echo esc_attr( $token ); ?>" />
			<div class="acps-ls-field">
				<label for="acps-ls-newpw"><?php esc_html_e( 'New password', 'acps-link-shortener' ); ?></label>
				<input type="password" name="acps_ls_new_password" id="acps-ls-newpw" autocomplete="new-password" minlength="8" required />
			</div>
			<div class="acps-ls-field">
				<label for="acps-ls-newpw2"><?php esc_html_e( 'Confirm password', 'acps-link-shortener' ); ?></label>
				<input type="password" name="acps_ls_new_password2" id="acps-ls-newpw2" autocomplete="new-password" minlength="8" required />
			</div>
			<p class="acps-ls-help"><?php esc_html_e( 'Use at least 8 characters.', 'acps-link-shortener' ); ?></p>
			<button type="submit" name="acps_ls_do_setpw" value="1" class="acps-ls-submit"><?php esc_html_e( 'Set password &amp; sign in', 'acps-link-shortener' ); ?></button>
		</form>
		<?php
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Render the signed-in dashboard (create + manage).
	 *
	 * @param array      $person Person record.
	 * @param array|null $result Result payload.
	 */
	private function render_dashboard( $person, $result ) {
		$label     = $person['label'];
		$namespace = $person['namespace'];
		$max       = (int) $person['max_links'];
		$mine      = ACPS_LS_DB::get_by_creator( $label );
		$used      = count( $mine );
		$at_limit  = ( $max > 0 && $used >= $max );

		$base_prefix = trailingslashit( acps_ls_link_base() . ( '' !== ACPS_LS_SLUG_PREFIX ? '/' . ACPS_LS_SLUG_PREFIX : '' ) . ( '' !== $namespace ? '/' . $namespace : '' ) );
		?>
		<div class="acps-ls-topbar">
			<h2 class="acps-ls-box__title"><?php esc_html_e( 'Your short links', 'acps-link-shortener' ); ?></h2>
			<form method="post" action="" class="acps-ls-signoutform">
				<?php self::form_fields(); ?>
				<button type="submit" name="acps_ls_signout" value="1" class="acps-ls-link-btn">
					<?php
					printf(
						/* translators: %s: person name. */
						esc_html__( 'Sign out (%s)', 'acps-link-shortener' ),
						esc_html( $label )
					);
					?>
				</button>
			</form>
		</div>

		<p class="acps-ls-usage">
			<?php
			if ( $max > 0 ) {
				printf(
					/* translators: 1: used count, 2: max count. */
					esc_html__( 'You have created %1$d of %2$d links.', 'acps-link-shortener' ),
					(int) $used,
					(int) $max
				);
			} else {
				printf(
					/* translators: %d: used count. */
					esc_html__( 'You have created %d links.', 'acps-link-shortener' ),
					(int) $used
				);
			}
			?>
		</p>

		<?php if ( $at_limit ) : ?>
			<div class="acps-ls-alert acps-ls-alert--info"><p><?php esc_html_e( 'You have reached your link limit. Delete a link below to make room for a new one.', 'acps-link-shortener' ); ?></p></div>
		<?php else : ?>
			<form method="post" class="acps-ls-form" action="">
				<?php self::form_fields(); ?>
				<div class="acps-ls-field">
					<label for="acps-ls-destination"><?php esc_html_e( 'Destination URL', 'acps-link-shortener' ); ?></label>
					<input type="url" name="acps_ls_destination" id="acps-ls-destination" placeholder="https://example.com/long/page" required />
				</div>
				<div class="acps-ls-field">
					<label for="acps-ls-slug"><?php esc_html_e( 'Custom name (optional)', 'acps-link-shortener' ); ?></label>
					<div class="acps-ls-inputgroup">
						<span class="acps-ls-inputgroup__prefix"><?php echo esc_html( $base_prefix ); ?></span>
						<input type="text" name="acps_ls_slug" id="acps-ls-slug" placeholder="<?php esc_attr_e( 'auto if blank', 'acps-link-shortener' ); ?>" />
					</div>
					<?php if ( '' !== $namespace ) : ?>
						<p class="acps-ls-help">
							<?php
							printf(
								/* translators: %s: namespace segment. */
								esc_html__( 'Your links always start with %s.', 'acps-link-shortener' ),
								'<code>' . esc_html( '/' . $namespace . '/' ) . '</code>'
							);
							?>
						</p>
					<?php endif; ?>
				</div>
				<button type="submit" name="acps_ls_do_create" value="1" class="acps-ls-submit"><?php esc_html_e( 'Create short link', 'acps-link-shortener' ); ?></button>
			</form>
		<?php endif; ?>

		<?php if ( $mine ) : ?>
			<table class="acps-ls-mylinks">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Short link', 'acps-link-shortener' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Destination', 'acps-link-shortener' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Clicks', 'acps-link-shortener' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'acps-link-shortener' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $mine as $link ) : ?>
						<?php $url = acps_ls_short_url( $link->slug ); ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $url ); ?></a>
								<button type="button" class="acps-ls-copy acps-ls-copy--sm" data-clipboard-text="<?php echo esc_attr( $url ); ?>" aria-label="<?php esc_attr_e( 'Copy short link', 'acps-link-shortener' ); ?>"><?php esc_html_e( 'Copy', 'acps-link-shortener' ); ?></button>
							</td>
							<td class="acps-ls-dest"><?php echo esc_html( $link->destination ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $link->clicks ) ); ?></td>
							<td>
								<form method="post" action="" class="acps-ls-deleteform" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this short link? This cannot be undone.', 'acps-link-shortener' ) ); ?>');">
									<?php self::form_fields(); ?>
									<input type="hidden" name="acps_ls_link_id" value="<?php echo (int) $link->id; ?>" />
									<button type="submit" name="acps_ls_do_delete" value="1" class="acps-ls-delete-btn"><?php esc_html_e( 'Delete', 'acps-link-shortener' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="acps-ls-note"><?php esc_html_e( 'You have not created any links yet.', 'acps-link-shortener' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the success panel (new link + copy).
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
		.acps-ls-box{max-width:680px;margin:1.5rem auto;padding:1.75rem;border:1px solid #d7dbe0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06);font-family:inherit;color:#1d2327;box-sizing:border-box}
		.acps-ls-box *{box-sizing:border-box}
		.acps-ls-box__title{margin:0;font-size:1.35rem;line-height:1.2}
		.acps-ls-topbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:.75rem;flex-wrap:wrap}
		.acps-ls-signoutform{margin:0}
		.acps-ls-usage{margin:.25rem 0 1rem;color:#50575e}
		.acps-ls-field{margin-bottom:1rem}
		.acps-ls-field label{display:block;font-weight:600;margin-bottom:.35rem}
		.acps-ls-field input{width:100%;padding:.6rem .7rem;border:1px solid #8c8f94;border-radius:8px;font-size:1rem}
		.acps-ls-field input:focus,.acps-ls-copy:focus,.acps-ls-submit:focus,.acps-ls-result__url:focus,.acps-ls-delete-btn:focus,.acps-ls-link-btn:focus{outline:3px solid #1d4ed8;outline-offset:1px}
		.acps-ls-inputgroup{display:flex;align-items:stretch}
		.acps-ls-inputgroup__prefix{display:flex;align-items:center;padding:.55rem .6rem;background:#f0f0f1;border:1px solid #8c8f94;border-right:0;border-radius:8px 0 0 8px;color:#3c434a;font-size:.85rem;white-space:nowrap;max-width:60%;overflow:hidden;text-overflow:ellipsis}
		.acps-ls-inputgroup input{border-radius:0 8px 8px 0}
		.acps-ls-help{margin:.4rem 0 0;font-size:.85rem;color:#50575e}
		.acps-ls-submit{display:inline-block;margin-top:.25rem;padding:.7rem 1.4rem;background:#1d4ed8;color:#fff;border:0;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer}
		.acps-ls-submit:hover{background:#1740b0}
		.acps-ls-alert{padding:.9rem 1rem;border-radius:8px;margin-bottom:1.1rem}
		.acps-ls-alert--error{background:#fcf0f1;border:1px solid #d63638;color:#8a1f21}
		.acps-ls-alert--error ul{margin:0;padding-left:1.2rem}
		.acps-ls-alert--success{background:#edfaef;border:1px solid #1a7f37;color:#0f5323}
		.acps-ls-alert--info{background:#eef4fb;border:1px solid #2271b1;color:#0a4b78}
		.acps-ls-success__label{margin:0 0 .5rem;font-weight:600}
		.acps-ls-result{display:flex;gap:.5rem}
		.acps-ls-result__url{flex:1;padding:.55rem .7rem;border:1px solid #8c8f94;border-radius:8px;font-size:1rem;background:#fff}
		.acps-ls-copy{padding:.55rem 1rem;background:#1a7f37;color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer}
		.acps-ls-copy:hover{background:#14682d}
		.acps-ls-copy--sm{padding:.25rem .6rem;font-size:.8rem;margin-left:.4rem}
		.acps-ls-signedout{margin:0 0 1rem;color:#50575e}
		.acps-ls-link-btn{background:none;border:0;color:#1d4ed8;text-decoration:underline;cursor:pointer;padding:0;font-size:.9rem}
		.acps-ls-note{color:#50575e}
		.acps-ls-mylinks{width:100%;border-collapse:collapse;margin-top:1.25rem;font-size:.9rem}
		.acps-ls-mylinks th,.acps-ls-mylinks td{text-align:left;padding:.5rem .5rem;border-bottom:1px solid #e2e4e7;vertical-align:top}
		.acps-ls-mylinks th{border-bottom:2px solid #c3c4c7}
		.acps-ls-dest{max-width:220px;word-break:break-all;color:#50575e}
		.acps-ls-deleteform{margin:0}
		.acps-ls-delete-btn{background:none;border:1px solid #d63638;color:#b32d2e;border-radius:6px;padding:.25rem .6rem;cursor:pointer;font-size:.8rem}
		.acps-ls-delete-btn:hover{background:#fcf0f1}
		.screen-reader-text{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px);white-space:nowrap}
		</style>
		<script>
		(function(){
			document.addEventListener('click',function(e){
				var b=e.target.closest&&e.target.closest('.acps-ls-copy');
				if(!b)return;
				e.preventDefault();
				var text=b.getAttribute('data-clipboard-text')||'';
				var box=b.closest('.acps-ls-box');
				var live=box?box.querySelector('.acps-ls-live'):null;
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
