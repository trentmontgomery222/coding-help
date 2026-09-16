<?php
/**
 * Plugin Name: WPSQR Example People Provider
 * Description: A fake staff directory for testing the people-results integration. Not for production.
 * Version:     1.0.0
 *
 * Drop this in as its own plugin and activate it to see people results working
 * before the real directory integration is written. Six invented staff, one of
 * them hidden, so the visibility rule can be tested as well as the matching.
 *
 * INTEGRATION.md describes the contract this implements.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wpsqr_example_people() {
	return array(
		array( 'id' => 1, 'name' => 'Aaron Kerr', 'job_title' => 'Warehouse Driver', 'department' => 'Operations', 'visible' => true ),
		array( 'id' => 2, 'name' => 'Mary Sibley', 'job_title' => 'Counselor', 'location' => 'Fort Hill', 'visible' => true ),
		array( 'id' => 3, 'name' => 'Abigail Twigg', 'job_title' => 'Counselor', 'location' => 'Fort Hill', 'visible' => true ),
		array( 'id' => 4, 'name' => 'Ethan DeVore', 'job_title' => 'Supervisor of Early Childhood', 'department' => 'Academics', 'visible' => true ),
		array( 'id' => 5, 'name' => 'Sarah Welsh', 'job_title' => 'Director of Student Services', 'visible' => true ),
		// The one that must never appear in results.
		array( 'id' => 6, 'name' => 'Hidden Person', 'job_title' => 'Test Record', 'visible' => false ),
	);
}

add_filter( 'wpsqr_people_search', 'wpsqr_example_people_search', 10, 2 );

function wpsqr_example_people_search( $people, $args ) {
	$query = strtolower( trim( (string) $args['query'] ) );

	if ( mb_strlen( $query ) < 3 ) {
		return $people;
	}

	$parts    = preg_split( '/\s+/', $query );
	$reversed = ( 2 === count( $parts ) ) ? $parts[1] . ' ' . $parts[0] : $query;

	$matches = array();

	foreach ( wpsqr_example_people() as $person ) {
		// Visibility first, always.
		if ( empty( $person['visible'] ) ) {
			continue;
		}

		$name = strtolower( $person['name'] );

		if ( false === strpos( $name, $query ) && false === strpos( $name, $reversed ) ) {
			continue;
		}

		// Crude relevance: exact name, then prefix, then anything.
		$rank = 2;
		if ( $name === $query ) {
			$rank = 0;
		} elseif ( 0 === strpos( $name, $query ) ) {
			$rank = 1;
		}

		$person['_rank'] = $rank;
		$matches[]       = $person;
	}

	usort(
		$matches,
		function ( $a, $b ) {
			return $a['_rank'] === $b['_rank'] ? strcmp( $a['name'], $b['name'] ) : $a['_rank'] - $b['_rank'];
		}
	);

	foreach ( array_slice( $matches, 0, (int) $args['limit'] ) as $person ) {
		$people[] = array(
			'id'         => $person['id'],
			'name'       => $person['name'],
			'url'        => home_url( '/staff/directory/#person-' . $person['id'] ),
			'job_title'  => $person['job_title'] ?? '',
			'department' => $person['department'] ?? '',
			'location'   => $person['location'] ?? '',
		);
	}

	return $people;
}

add_filter( 'wpsqr_people_providers', function ( $providers ) {
	$providers[] = array(
		'name'     => 'Example People Provider (testing only)',
		'version'  => '1.0.0',
		'contract' => 1,
	);

	return $providers;
} );
