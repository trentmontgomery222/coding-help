<?php
/**
 * Google Forms bridge — forward a submission into a backing Google Form.
 *
 * This is the mirror image of Google_Forms_Importer. The importer reads a
 * published Google Form's structure into an ACPS form; the bridge takes what a
 * visitor typed into the ACPS form and POSTs it back into the Google Form's
 * public "formResponse" endpoint, so the plugin's accessible, cache-safe form
 * sits ON TOP of a real Google Form and files a genuine Google response.
 *
 * How the mapping works: each ACPS field carries an optional numeric
 * `google_entry_id` (the number in Google's `entry.123456789`). When the form
 * was created via the importer these are filled in automatically; they can also
 * be set by hand in the builder. On submit we build the `entry.*` payload from
 * the fields that have an id and POST it to the form's formResponse URL.
 *
 * Google's endpoint is undocumented but stable: an unauthenticated POST of the
 * `entry.*` values records a response. It ignores unknown/extra params and
 * doesn't return the new response id, so this is best-effort by nature — a
 * failure is logged and never affects the visitor's own submission (which is
 * already saved locally by the time we forward).
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google_Forms_Bridge.
 */
class Google_Forms_Bridge {

	/**
	 * Is the bridge enabled and configured for this form?
	 *
	 * @param Form $form Form.
	 * @return bool
	 */
	public static function is_enabled( Form $form ) {
		$cfg = self::config( $form );
		return ! empty( $cfg['enabled'] ) && '' !== self::response_url( $cfg['url'] );
	}

	/**
	 * The bridge config for a form, always a well-formed array.
	 *
	 * @param Form $form Form.
	 * @return array{enabled:int,url:string}
	 */
	public static function config( Form $form ) {
		$cfg = isset( $form->settings['gforms_bridge'] ) && is_array( $form->settings['gforms_bridge'] )
			? $form->settings['gforms_bridge']
			: array();
		return array(
			'enabled' => ! empty( $cfg['enabled'] ) ? 1 : 0,
			'url'     => isset( $cfg['url'] ) ? (string) $cfg['url'] : '',
		);
	}

	/**
	 * Normalize any Google Form URL to its formResponse endpoint.
	 *
	 * Accepts the sharing/viewform URL, the prefilled URL, or an already-correct
	 * formResponse URL, in either the `/d/e/{id}/` (published) or `/d/{id}/`
	 * (edit) shape. Returns '' if it isn't a recognisable Google Forms URL.
	 *
	 * @param string $url Any Google Form URL.
	 * @return string formResponse URL, or ''.
	 */
	public static function response_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || false === strpos( $url, 'docs.google.com/forms' ) ) {
			return '';
		}

		// Drop query string / fragment (e.g. ?usp=sf_link or prefill params).
		$url = preg_replace( '/[?#].*$/', '', $url );

		// Published form: /forms/d/e/{ID}/(viewform|formResponse)
		if ( preg_match( '#(https://docs\.google\.com/forms/d/e/[^/]+)/#', $url, $m ) ) {
			return $m[1] . '/formResponse';
		}
		// Edit-style / direct: /forms/d/{ID}/(viewform|formResponse|edit)
		if ( preg_match( '#(https://docs\.google\.com/forms/d/[^/]+)/#', $url, $m ) ) {
			return $m[1] . '/formResponse';
		}
		// Bare .../d/e/{ID} or .../d/{ID} with no trailing segment.
		if ( preg_match( '#(https://docs\.google\.com/forms/d/(?:e/)?[^/]+)$#', $url, $m ) ) {
			return $m[1] . '/formResponse';
		}
		return '';
	}

	/**
	 * Forward a saved submission to the backing Google Form.
	 *
	 * Never throws and never blocks the visitor's result: it's called after the
	 * entry is already stored. Returns true if Google accepted the POST.
	 *
	 * @param Form  $form   The form.
	 * @param array $values Clean values keyed by field key.
	 * @param array $fields Normalized field list (carries google_entry_id).
	 * @return bool
	 */
	public static function forward( Form $form, $values, $fields ) {
		$cfg = self::config( $form );
		if ( empty( $cfg['enabled'] ) ) {
			return false;
		}
		$endpoint = self::response_url( $cfg['url'] );
		if ( '' === $endpoint ) {
			return false;
		}

		$body = self::build_body( $fields, $values );
		if ( '' === $body ) {
			return false; // Nothing mapped — don't bother Google.
		}

		$resp = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 12,
				'redirection' => 0, // A 200 (or 3xx) means "recorded"; don't follow.
				'blocking'    => true,
				'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'        => $body,
				'user-agent'  => 'Mozilla/5.0 (compatible; ACPSSiteToolkit/1.0)',
			)
		);

		if ( is_wp_error( $resp ) ) {
			self::log( 'POST failed: ' . $resp->get_error_message(), $form );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		// Google returns 200 on success; a 3xx to the "response recorded" page is
		// also a success. 4xx/5xx means the mapping/URL is wrong.
		if ( $code >= 200 && $code < 400 ) {
			return true;
		}
		self::log( 'Google returned HTTP ' . $code . ' — check the form URL and entry ids.', $form );
		return false;
	}

	/**
	 * Build the url-encoded `entry.*` body from mapped fields.
	 *
	 * Multi-value fields (checkbox/chips) send one `entry.{id}` pair per value,
	 * which is how Google records multiple choices. Date and time fields are
	 * split into Google's sub-fields so they land correctly.
	 *
	 * @param array $fields Normalized fields.
	 * @param array $values Values keyed by field key.
	 * @return string URL-encoded body ('' if nothing mapped).
	 */
	private static function build_body( $fields, $values ) {
		$pairs = array();

		foreach ( $fields as $field ) {
			$entry = isset( $field['google_entry_id'] ) ? preg_replace( '/[^0-9]/', '', (string) $field['google_entry_id'] ) : '';
			if ( '' === $entry ) {
				continue;
			}
			$key = $field['key'];
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value = $values[ $key ];
			$name  = 'entry.' . $entry;

			switch ( $field['type'] ) {
				case 'date':
					self::add_date( $pairs, $entry, (string) ( is_array( $value ) ? '' : $value ) );
					break;
				case 'time':
					self::add_time( $pairs, $entry, (string) ( is_array( $value ) ? '' : $value ) );
					break;
				case 'checkbox':
				case 'chips':
					foreach ( (array) $value as $v ) {
						if ( '' !== (string) $v ) {
							$pairs[] = $name . '=' . rawurlencode( (string) $v );
						}
					}
					break;
				default:
					$flat = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
					if ( '' !== $flat ) {
						$pairs[] = $name . '=' . rawurlencode( $flat );
					}
					break;
			}
		}

		return implode( '&', $pairs );
	}

	/**
	 * Add a Google date field (entry.{id}_year/_month/_day) from an ISO value.
	 *
	 * @param array  $pairs Body pairs (by reference).
	 * @param string $entry Numeric entry id.
	 * @param string $value Date value, ideally YYYY-MM-DD.
	 */
	private static function add_date( &$pairs, $entry, $value ) {
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m ) ) {
			$pairs[] = 'entry.' . $entry . '_year=' . (int) $m[1];
			$pairs[] = 'entry.' . $entry . '_month=' . (int) $m[2];
			$pairs[] = 'entry.' . $entry . '_day=' . (int) $m[3];
		} elseif ( '' !== $value ) {
			// Unknown format — send it flat and let Google do its best.
			$pairs[] = 'entry.' . $entry . '=' . rawurlencode( $value );
		}
	}

	/**
	 * Add a Google time field (entry.{id}_hour/_minute) from an HH:MM value.
	 *
	 * @param array  $pairs Body pairs (by reference).
	 * @param string $entry Numeric entry id.
	 * @param string $value Time value, ideally HH:MM (24h).
	 */
	private static function add_time( &$pairs, $entry, $value ) {
		if ( preg_match( '/^(\d{1,2}):(\d{2})/', $value, $m ) ) {
			$pairs[] = 'entry.' . $entry . '_hour=' . (int) $m[1];
			$pairs[] = 'entry.' . $entry . '_minute=' . (int) $m[2];
		} elseif ( '' !== $value ) {
			$pairs[] = 'entry.' . $entry . '=' . rawurlencode( $value );
		}
	}

	/**
	 * Log a bridge problem to the PHP error log (never to the visitor).
	 *
	 * @param string $message Message.
	 * @param Form   $form    Form.
	 */
	private static function log( $message, Form $form ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( '[Cayden Form Manager] Google Forms bridge (form #' . (int) $form->id . '): ' . $message ); // phpcs:ignore
		}
	}
}
