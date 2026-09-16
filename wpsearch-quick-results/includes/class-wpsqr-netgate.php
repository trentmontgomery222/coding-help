<?php
/**
 * Who may reach the remote endpoint, and how often.
 *
 * The remote page has no WordPress login in front of it, so the gate is the
 * security. Everything that decides "may this request through" lives here as
 * pure functions of the request's IP and a rules array, so it can be tested
 * exhaustively without a web server — which, for the code that stands between
 * the open internet and a settings editor, is the point.
 *
 * Rules are evaluated most-specific-wins, then deny-wins on a tie, then a
 * default-deny. An IP that matches nothing is refused: a gate that fails open
 * is not a gate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_NetGate {

	/**
	 * Decide whether an IP is allowed by a set of rules.
	 *
	 * A rule is [ 'action' => 'allow'|'deny', 'value' => '167.102.110.1' or
	 * '196.168.' or '10.0.0.0/8' ]. A bare prefix ending in a dot matches any
	 * address that starts with it; a CIDR matches its block; a full address
	 * matches only itself.
	 *
	 * @param string  $ip
	 * @param array[] $rules
	 * @return bool
	 */
	public static function allows( $ip, $rules ) {
		$ip = self::normalize( $ip );

		if ( '' === $ip ) {
			return false; // unparseable address, refuse
		}

		$best_score  = -1;
		$best_action = 'deny';

		foreach ( (array) $rules as $rule ) {
			$value  = isset( $rule['value'] ) ? trim( (string) $rule['value'] ) : '';
			$action = ( isset( $rule['action'] ) && 'allow' === $rule['action'] ) ? 'allow' : 'deny';

			if ( '' === $value ) {
				continue;
			}

			$score = self::match_score( $ip, $value );

			if ( $score < 0 ) {
				continue;
			}

			// A more specific rule wins outright. On an exact tie in
			// specificity, deny wins — the safe direction for an access gate.
			if ( $score > $best_score || ( $score === $best_score && 'deny' === $action ) ) {
				$best_score  = $score;
				$best_action = $action;
			}
		}

		return 'allow' === $best_action;
	}

	/**
	 * How specifically a value matches an IP.
	 *
	 * Higher is more specific. -1 means no match. A full-address match is the
	 * most specific thing there is; a one-octet prefix the least.
	 */
	public static function match_score( $ip, $value ) {
		$ip = self::normalize( $ip );

		if ( '' === $ip ) {
			return -1;
		}

		// CIDR, e.g. 10.0.0.0/8 — score by prefix length.
		if ( false !== strpos( $value, '/' ) ) {
			return self::cidr_match( $ip, $value ) ? (int) explode( '/', $value )[1] : -1;
		}

		// Prefix, e.g. "196.168." — matches any address starting with it.
		if ( '.' === substr( $value, -1 ) ) {
			if ( 0 === strpos( $ip . '.', $value ) || 0 === strpos( $ip, $value ) ) {
				// Score by how many octets the prefix pins down, so a
				// three-octet prefix beats a one-octet one.
				return 8 * substr_count( rtrim( $value, '.' ), '.' ) + 8;
			}

			return -1;
		}

		// A bare "196.168" with no trailing dot is still a prefix, but only on
		// octet boundaries — it must not let "196.1689" through.
		if ( false === strpos( $value, ':' ) && substr_count( $value, '.' ) < 3 && ! self::is_full_v4( $value ) ) {
			if ( 0 === strpos( $ip . '.', rtrim( $value, '.' ) . '.' ) ) {
				return 8 * substr_count( $value, '.' ) + 8;
			}

			return -1;
		}

		// A full address matches only itself, and is maximally specific.
		return ( self::normalize( $value ) === $ip ) ? 128 : -1;
	}

	protected static function is_full_v4( $value ) {
		return (bool) preg_match( '/^\d{1,3}(\.\d{1,3}){3}$/', $value );
	}

	protected static function cidr_match( $ip, $cidr ) {
		list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, '' );

		$bits = (int) $bits;

		$ip_packed     = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$subnet_packed = @inet_pton( self::normalize( $subnet ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $ip_packed || false === $subnet_packed || strlen( $ip_packed ) !== strlen( $subnet_packed ) ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;

		if ( $bytes > 0 && 0 !== substr_compare( $ip_packed, $subnet_packed, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $rem ) {
			return true;
		}

		$mask = ~( 0xFF >> $rem ) & 0xFF;

		return ( ord( $ip_packed[ $bytes ] ) & $mask ) === ( ord( $subnet_packed[ $bytes ] ) & $mask );
	}

	/** Canonical form, or '' if it isn't an IP at all. */
	public static function normalize( $ip ) {
		$ip = trim( (string) $ip );

		if ( '' === $ip ) {
			return '';
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return false === $packed ? '' : inet_ntop( $packed );
	}

	/**
	 * The client's IP.
	 *
	 * REMOTE_ADDR is the only header that cannot be spoofed by the client, so
	 * it is used unless the site explicitly trusts a proxy header — which it
	 * must opt into, because behind no proxy, trusting X-Forwarded-For hands
	 * every visitor the ability to claim any address they like, including the
	 * one on the allow list.
	 */
	public static function client_ip( $trust_forwarded = false ) {
		if ( $trust_forwarded && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$candidate = self::normalize( trim( $forwarded[0] ) );

			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return self::normalize( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '' );
	}

	/**
	 * Parse the textarea format into rules.
	 *
	 * One per line: "allow 167.102.110.1", "deny 10.", or a bare value which
	 * defaults to allow. Comments after # are ignored.
	 *
	 * @return array[]
	 */
	public static function parse_rules( $text ) {
		$rules = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );

			if ( '' === $line ) {
				continue;
			}

			$parts = preg_split( '/\s+/', $line );

			if ( in_array( strtolower( $parts[0] ), array( 'allow', 'deny' ), true ) ) {
				$action = strtolower( $parts[0] );
				$value  = isset( $parts[1] ) ? $parts[1] : '';
			} else {
				$action = 'allow';
				$value  = $parts[0];
			}

			if ( '' !== $value ) {
				$rules[] = array( 'action' => $action, 'value' => $value );
			}
		}

		return $rules;
	}
}
