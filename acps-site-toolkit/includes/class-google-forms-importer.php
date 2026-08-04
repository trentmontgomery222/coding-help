<?php
/**
 * Google Forms importer.
 *
 * A published Google Form embeds its whole structure in a JS variable,
 * FB_PUBLIC_LOAD_DATA_. We fetch the public form page (or accept pasted page
 * source), extract that JSON, and map Google's question types onto this
 * plugin's field types to create a draft form.
 *
 * Google's format is undocumented and can change, so every read is defensive
 * and unknown types fall back to a short-text field the admin can adjust.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google_Forms_Importer.
 */
class Google_Forms_Importer {

	/**
	 * Import a Google Form into a draft Form.
	 *
	 * @param string $url  Public Google Form URL (…/viewform). Optional if $html given.
	 * @param string $html Raw page source (fallback when the server can't fetch).
	 * @return int|\WP_Error New form id, or error.
	 */
	public static function import( $url, $html = '' ) {
		$html = trim( (string) $html );

		if ( '' === $html ) {
			$url = esc_url_raw( trim( $url ) );
			if ( ! $url || false === strpos( $url, 'docs.google.com/forms' ) ) {
				return new \WP_Error( 'bad_url', __( 'Please enter a valid Google Forms URL (docs.google.com/forms/…).', 'acps-site-toolkit' ) );
			}
			$resp = wp_remote_get(
				$url,
				array(
					'timeout'    => 20,
					'user-agent' => 'Mozilla/5.0 (compatible; ACPSSiteToolkit/1.0)',
				)
			);
			if ( is_wp_error( $resp ) ) {
				return new \WP_Error( 'fetch_failed', __( 'Could not fetch the form. Paste the page source instead.', 'acps-site-toolkit' ) . ' (' . $resp->get_error_message() . ')' );
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return new \WP_Error( 'fetch_status', __( 'Google returned an error fetching the form. Paste the page source instead.', 'acps-site-toolkit' ) );
			}
			$html = wp_remote_retrieve_body( $resp );
		}

		$data = self::extract_data( $html );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return self::build_form( $data, $url );
	}

	/**
	 * Pull the FB_PUBLIC_LOAD_DATA_ array out of the page source.
	 *
	 * @param string $html Page source.
	 * @return array|\WP_Error
	 */
	private static function extract_data( $html ) {
		if ( ! preg_match( '/FB_PUBLIC_LOAD_DATA_\s*=\s*(\[.*?\])\s*;/s', $html, $m ) ) {
			return new \WP_Error( 'no_data', __( 'This does not look like a Google Form page (the form data was not found).', 'acps-site-toolkit' ) );
		}
		$decoded = json_decode( $m[1], true );
		if ( ! is_array( $decoded ) || ! isset( $decoded[1][1] ) || ! is_array( $decoded[1][1] ) ) {
			return new \WP_Error( 'parse', __( 'Found the form but could not read its questions.', 'acps-site-toolkit' ) );
		}
		return $decoded;
	}

	/**
	 * Turn the decoded Google data into a Form.
	 *
	 * @param array  $data Decoded FB_PUBLIC_LOAD_DATA_.
	 * @param string $url  Source URL (for reference).
	 * @return int|\WP_Error
	 */
	private static function build_form( $data, $url ) {
		$title = '';
		if ( isset( $data[1][8] ) && is_string( $data[1][8] ) && '' !== $data[1][8] ) {
			$title = $data[1][8];
		} elseif ( isset( $data[3] ) && is_string( $data[3] ) ) {
			$title = $data[3];
		}
		$title = $title ? sanitize_text_field( $title ) : __( 'Imported Google Form', 'acps-site-toolkit' );

		$items    = $data[1][1];
		$fields   = array();
		$page     = 1;
		$multipage = false;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$q_title = isset( $item[1] ) ? sanitize_text_field( (string) $item[1] ) : '';
			$q_help  = isset( $item[2] ) ? sanitize_text_field( (string) $item[2] ) : '';
			$q_type  = isset( $item[3] ) ? (int) $item[3] : 0;

			// Page break → start a new page.
			if ( 8 === $q_type ) {
				$page++;
				$multipage = true;
				continue;
			}

			// Section header / title-only block → heading.
			if ( 6 === $q_type ) {
				$fields[] = array( 'type' => 'heading', 'label' => $q_title, 'content' => $q_help, 'page' => $page );
				continue;
			}

			// Images/videos and grids we can't represent → leave a note heading.
			if ( in_array( $q_type, array( 7, 11, 12 ), true ) ) {
				$fields[] = array(
					'type'    => 'heading',
					'label'   => $q_title,
					'content' => __( '(This question type could not be imported automatically — please rebuild it here.)', 'acps-site-toolkit' ),
					'page'    => $page,
				);
				continue;
			}

			$answer   = isset( $item[4][0] ) && is_array( $item[4][0] ) ? $item[4][0] : array();
			$required = ! empty( $answer[2] );
			$options  = array();
			if ( isset( $answer[1] ) && is_array( $answer[1] ) ) {
				foreach ( $answer[1] as $opt ) {
					$label = is_array( $opt ) && isset( $opt[0] ) ? sanitize_text_field( (string) $opt[0] ) : '';
					if ( '' !== $label ) {
						$options[] = array( 'label' => $label, 'value' => $label );
					}
				}
			}

			$field = array(
				'type'     => self::map_type( $q_type ),
				'label'    => $q_title,
				'help'     => $q_help,
				'required' => (bool) $required,
				'page'     => $page,
			);

			if ( in_array( $field['type'], array( 'radio', 'checkbox', 'dropdown' ), true ) ) {
				$field['options'] = $options;
			}
			if ( 'scale' === $field['type'] && $options ) {
				$nums              = array_map( 'intval', wp_list_pluck( $options, 'value' ) );
				$field['scale_min'] = $nums ? min( $nums ) : 1;
				$field['scale_max'] = $nums ? max( $nums ) : 5;
			}

			$fields[] = $field;
		}

		if ( ! $fields ) {
			return new \WP_Error( 'empty', __( 'No importable questions were found in that form.', 'acps-site-toolkit' ) );
		}

		$form         = new Form();
		$form->title  = $title;
		$form->status = 'draft'; // review before publishing.
		$form->fields = $fields;
		$form->settings = wp_parse_args(
			array( 'multipage' => $multipage ? 1 : 0 ),
			Form::default_settings()
		);
		$form->save();

		return $form->id;
	}

	/**
	 * Map a Google question type code to one of our field types.
	 *
	 * @param int $type Google type code.
	 * @return string
	 */
	private static function map_type( $type ) {
		$map = array(
			0  => 'short_text',
			1  => 'long_text',
			2  => 'radio',
			3  => 'dropdown',
			4  => 'checkbox',
			5  => 'scale',
			9  => 'date',
			10 => 'time',
			13 => 'file',
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : 'short_text';
	}
}
