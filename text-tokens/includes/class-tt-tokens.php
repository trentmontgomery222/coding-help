<?php
/**
 * Token storage and CRUD.
 *
 * @package TextTokens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TT_Tokens
 *
 * Reads and writes the token definitions stored in the options table.
 *
 * Data schema (option TT_OPTION_TOKENS): a numerically-indexed array of tokens,
 * each token an associative array:
 *   - id          string  Unique identifier.
 *   - code        string  Inner token text, stored UPPERCASE, no brackets.
 *   - type        string  'static' | 'dynamic'.
 *   - value       string  Replacement text (static tokens only).
 *   - rule        string  Rule slug (dynamic tokens only).
 *   - config      array   Per-rule configuration (dynamic tokens only).
 *   - description string  Optional editor note.
 */
class TT_Tokens {

	/**
	 * Return all stored tokens.
	 *
	 * @return array List of token arrays.
	 */
	public static function all() {
		$tokens = get_option( TT_OPTION_TOKENS, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Persist the full token list.
	 *
	 * @param array $tokens Token arrays.
	 * @return void
	 */
	public static function save_all( array $tokens ) {
		update_option( TT_OPTION_TOKENS, array_values( $tokens ) );
		TT_Resolver::flush_cache();
	}

	/**
	 * Find a token by its id.
	 *
	 * @param string $id Token id.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $token ) {
			if ( isset( $token['id'] ) && $token['id'] === $id ) {
				return $token;
			}
		}
		return null;
	}

	/**
	 * Insert or update a token by id.
	 *
	 * @param array $token Token array (must include 'id').
	 * @return void
	 */
	public static function upsert( array $token ) {
		$tokens  = self::all();
		$updated = false;

		foreach ( $tokens as $i => $existing ) {
			if ( isset( $existing['id'], $token['id'] ) && $existing['id'] === $token['id'] ) {
				$tokens[ $i ] = $token;
				$updated      = true;
				break;
			}
		}

		if ( ! $updated ) {
			$tokens[] = $token;
		}

		self::save_all( $tokens );
	}

	/**
	 * Delete a token by id.
	 *
	 * @param string $id Token id.
	 * @return void
	 */
	public static function delete( $id ) {
		$tokens = array_filter(
			self::all(),
			static function ( $token ) use ( $id ) {
				return ! ( isset( $token['id'] ) && $token['id'] === $id );
			}
		);
		self::save_all( $tokens );
	}

	/**
	 * Normalize a raw code string: strip brackets, trim, uppercase.
	 *
	 * @param string $code Raw code entered by the admin.
	 * @return string
	 */
	public static function normalize_code( $code ) {
		$code = trim( (string) $code );
		$code = ltrim( $code, '[' );
		$code = rtrim( $code, ']' );
		$code = trim( $code );
		return strtoupper( $code );
	}

	/**
	 * Whether a code is already used by a different token.
	 *
	 * @param string $code       Normalized code.
	 * @param string $exclude_id Token id to ignore (for edits).
	 * @return bool
	 */
	public static function code_exists( $code, $exclude_id = '' ) {
		foreach ( self::all() as $token ) {
			if ( isset( $token['code'] ) && $token['code'] === $code ) {
				if ( '' !== $exclude_id && isset( $token['id'] ) && $token['id'] === $exclude_id ) {
					continue;
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * Generate a reasonably unique token id.
	 *
	 * @return string
	 */
	public static function generate_id() {
		return 'tt_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
	}
}
