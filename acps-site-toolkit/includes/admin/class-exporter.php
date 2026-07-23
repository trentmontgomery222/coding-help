<?php
/**
 * CSV export for entries / feedback (spec §5.6, §6.5, §7.6).
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

use ACPS\SiteToolkit\Form;
use ACPS\SiteToolkit\Entries;
use ACPS\SiteToolkit\Field_Types;
use ACPS\SiteToolkit\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exporter.
 */
class Exporter {

	/**
	 * Stream all entries for a form (or all forms) as CSV.
	 *
	 * @param int $form_id Form id, or 0 for all.
	 */
	public static function stream_entries( $form_id ) {
		$form   = $form_id ? Form::find( $form_id ) : null;
		$fields = $form ? Field_Types::normalize_list( $form->fields ) : array();

		$result  = Entries::query( array( 'form_id' => $form_id, 'per_page' => 100000, 'status' => '' ) );
		$rows    = $result['rows'];

		$filename = 'acps-entries-' . ( $form_id ? $form_id . '-' : '' ) . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );

		// Header row.
		$header = array( 'Entry ID', 'Submitted', 'Status', 'Page', 'Journey path' );
		$keys   = array();
		if ( $fields ) {
			foreach ( $fields as $f ) {
				if ( Field_Types::is_input( $f['type'] ) && 'hidden' !== $f['type'] ) {
					$header[] = $f['label'] ? $f['label'] : $f['key'];
					$keys[]   = $f['key'];
				}
			}
		} else {
			$header[] = 'Values';
		}
		fputcsv( $out, $header );

		foreach ( $rows as $row ) {
			$data   = Entries::get( (int) $row->id );
			$values = $data ? $data['values'] : array();
			$page   = $row->page_id ? ( get_the_title( (int) $row->page_id ) ?: ( '#' . $row->page_id ) ) : '';
			$path   = $row->session_id ? implode( ' > ', Analytics::session_path( (int) $row->session_id ) ) : '';

			$line = array( $row->id, $row->submitted_at, $row->status, $page, $path );

			if ( $keys ) {
				foreach ( $keys as $k ) {
					$v      = isset( $values[ $k ] ) ? $values[ $k ] : '';
					$line[] = is_array( $v ) ? implode( ', ', $v ) : $v;
				}
			} else {
				$flat = array();
				foreach ( $values as $k => $v ) {
					$flat[] = $k . '=' . ( is_array( $v ) ? implode( '|', $v ) : $v );
				}
				$line[] = implode( ' ; ', $flat );
			}

			fputcsv( $out, $line );
		}

		fclose( $out );
	}
}
