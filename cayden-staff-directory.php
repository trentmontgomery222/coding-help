<?php
/**
 * Plugin Name:       Cayden  Staff Directory
 * Description:       Staff Directory system for the website
 * Version:           2.4.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Cayden Riddle
 * License:           GPL-2.0-or-later
 * Text Domain:       cayden-staff-directory
 *
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit; //no access
}

define( 'CAYDENDIR_SD_VERSION',       '2.4.0' );
define( 'CAYDENDIR_SD_DIR',           plugin_dir_path( __FILE__ ) );
define( 'CAYDENDIR_SD_URL',           plugin_dir_url( __FILE__ ) );
define( 'CAYDENDIR_SD_CRON_HOOK',     'CAYDENDIR_sd_daily_sync' );
define( 'CAYDENDIR_SD_DATA_OPTION',   'CAYDENDIR_sd_directory_data' );
define( 'CAYDENDIR_SD_MANUAL_OPTION', 'CAYDENDIR_sd_manual_data' );
define( 'CAYDENDIR_SD_META_OPTION',   'CAYDENDIR_sd_sync_meta' );
define( 'CAYDENDIR_SD_SETTINGS',      'CAYDENDIR_sd_settings' );



//default settings
function CAYDENDIR_sd_default_colors() {
	return array(
		'accent'    => '#1a4f8b',
		'accent_fg' => '#ffffff',
		'text'      => '#1f2329',
		'muted'     => '#4a5568',
		'border'    => '#c8ccd2',
		'row_bg'    => '#ffffff',
		'row_alt'   => '#f5f8fb',
		'selected'  => '#e3edf9',
		'header_bg' => '#eef2f7',
	);
}

/**
 * Default front-end stylesheet.
 *
 * The directory's CSS now lives in Settings › Staff Directory › Custom CSS
 * and is printed inline, so it can be edited from the WordPress admin without
 * touching plugin files. This function is the fallback / seed value: it is the
 * default the Custom CSS box is pre-filled with, and what the plugin falls back
 * to if that box is ever left blank. Edit the box (not this function) to
 * restyle the directory; the colour pickers in Settings keep working because
 * they set the --CAYDENDIR-* variables inline on each directory, overriding the
 * matching rules below.
 */
function CAYDENDIR_sd_default_css() {
	return <<<'CSS'
/* the styling
   (note: the old first line started with "//" which is NOT a valid CSS
   comment — it made browsers throw away the entire first rule block,
   including all the CSS variables below. Fixed.) */

.CAYDENDIR-sd {
	--CAYDENDIR-fg:        #1f2329;
	--CAYDENDIR-muted:     #4a5568;
	--CAYDENDIR-border:    #c8ccd2;
	--CAYDENDIR-accent:    #1a4f8b;
	--CAYDENDIR-accent-fg: #ffffff;
	--CAYDENDIR-row-bg:    #ffffff;
	--CAYDENDIR-row-alt:   #f5f8fb;
	--CAYDENDIR-selected:  #e3edf9;
	--CAYDENDIR-header-bg: #eef2f7;
	--CAYDENDIR-chip-bg:   #eef2f7;
	--CAYDENDIR-radius:    10px;
	--CAYDENDIR-gap:       1rem;

	max-width: 1620px;
	margin-inline: auto;
	color: var(--CAYDENDIR-fg);
	font-size: inherit;
	line-height: 1.5;
}

.CAYDENDIR-sd *,
.CAYDENDIR-sd *::before,
.CAYDENDIR-sd *::after { box-sizing: border-box; }

/* Anything with the [hidden] attribute is really hidden. */
.CAYDENDIR-sd [hidden] { display: none !important; }

.CAYDENDIR-sd__sr {
	position: absolute !important;
	width: 1px; height: 1px;
	padding: 0; margin: -1px;
	overflow: hidden; clip: rect(0 0 0 0);
	white-space: nowrap; border: 0;
}

.CAYDENDIR-sd__title { margin: 0 0 var(--CAYDENDIR-gap); font-size: 1.6rem; line-height: 1.2; }

/* ---- controls ---- */
.CAYDENDIR-sd__controls { display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.25rem; }
.CAYDENDIR-sd__label { display: block; font-weight: 600; margin-bottom: 0.35rem; }
.CAYDENDIR-sd__input {
	width: 100%; max-width: 28rem; min-height: 44px;
	padding: 0.55rem 0.8rem; font-size: 1rem; color: var(--CAYDENDIR-fg);
	background: #fff; border: 1px solid var(--CAYDENDIR-border); border-radius: var(--CAYDENDIR-radius);
}
.CAYDENDIR-sd__filters { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.CAYDENDIR-sd__chip {
	display: inline-flex; align-items: center; gap: 0.4rem;
	min-height: 44px; padding: 0.4rem 0.85rem; display: none;
	font: inherit; font-size: 0.95rem; color: var(--CAYDENDIR-fg);
	background: var(--CAYDENDIR-chip-bg); border: 1px solid var(--CAYDENDIR-border);
	border-radius: 999px; cursor: pointer;
}
.CAYDENDIR-sd__chip[aria-pressed="true"] {
	background: var(--CAYDENDIR-accent);display: none;color: var(--CAYDENDIR-accent-fg); border-color: var(--CAYDENDIR-accent);
}
.CAYDENDIR-sd__chip[aria-pressed="true"]::before { content: "\2713"; font-weight: 700; display: none;}
.CAYDENDIR-sd__chip--clear { background: transparent; text-decoration: underline; display: none;}

.CAYDENDIR-sd :focus-visible { outline: 3px solid var(--CAYDENDIR-accent); outline-offset: 2px; border-radius: 6px; }

.CAYDENDIR-sd__status { margin: 0 0 1rem; font-weight: 600; color: var(--CAYDENDIR-muted); }
.CAYDENDIR-sd__empty { padding: 1rem; border: 1px dashed var(--CAYDENDIR-border); border-radius: var(--CAYDENDIR-radius); color: var(--CAYDENDIR-muted); }

.CAYDENDIR-sd__photo { flex: 0 0 auto; width: 86px; height: 86px; border-radius: 50%; object-fit: cover; background: var(--CAYDENDIR-chip-bg); }
.CAYDENDIR-sd__photo--placeholder { display: inline-flex; align-items: center; justify-content: center; font-weight: 700; color: var(--CAYDENDIR-muted); }
.CAYDENDIR-sd__photo-wrap { flex: 0 0 auto; display: inline-flex; }
.CAYDENDIR-sd__email, .CAYDENDIR-sd__location { color: var(--CAYDENDIR-accent); word-break: break-word;}

/* ---- "Edited" badge (manual overrides, shown to editors only) ---- */
.CAYDENDIR-sd__badge {
	display: inline-block;
	margin-left: 0.4rem;
	padding: 0.05rem 0.55rem;
	font-size: 0.75rem;
	font-weight: 600;
	line-height: 1.5;
	color: var(--CAYDENDIR-accent);
	background: var(--CAYDENDIR-selected);
	border: 1px solid var(--CAYDENDIR-accent);
	border-radius: 999px;
	vertical-align: middle;
	white-space: nowrap;
}

/* ---- Edit button ---- */
.CAYDENDIR-sd__edit-btn {
	min-height: 44px;
	min-width: 44px;
	padding: 0.35rem 0.95rem;
	font: inherit;
	font-size: 0.95rem;
	font-weight: 600;
	color: var(--CAYDENDIR-accent);
	background: #fff;
	border: 1px solid var(--CAYDENDIR-accent);
	border-radius: 8px;
	cursor: pointer;
}
.CAYDENDIR-sd__edit-btn:hover { background: var(--CAYDENDIR-selected); }
.CAYDENDIR-sd__card-actions { margin: 0.6rem 0 0; }

/* ---- TABLE layout ---- */
.CAYDENDIR-sd__table-wrap { overflow-x: auto; border: 1px solid var(--CAYDENDIR-border); border-radius: var(--CAYDENDIR-radius); }
.CAYDENDIR-sd__table { width: 100%; min-width: 560px; border-collapse: collapse; background: var(--CAYDENDIR-row-bg); }
.CAYDENDIR-sd__table th, .CAYDENDIR-sd__table td { text-align: left; padding: 0.7rem 0.85rem; vertical-align: middle; border-bottom: 1px solid var(--CAYDENDIR-border); }
.CAYDENDIR-sd__table thead th { background: var(--CAYDENDIR-header-bg); font-size: 0.95rem; position: sticky; top: 0; z-index: 1; }
.CAYDENDIR-sd__table tbody tr { background: var(--CAYDENDIR-row-bg); }
.CAYDENDIR-sd__table tbody tr:nth-child(even) { background: var(--CAYDENDIR-row-alt); }
.CAYDENDIR-sd__table tbody tr[data-selected] { background: var(--CAYDENDIR-selected); }
.CAYDENDIR-sd--hover .CAYDENDIR-sd__table tbody tr:hover,
.CAYDENDIR-sd--hover .CAYDENDIR-sd__table tbody tr:focus-within { background: var(--CAYDENDIR-selected); }
.CAYDENDIR-sd__table tbody tr:last-child th,
.CAYDENDIR-sd__table tbody tr:last-child td { border-bottom: 0; }
.CAYDENDIR-sd__table tr[hidden] { display: none; }
.CAYDENDIR-sd__cell-name {align-items: left; font-weight: 600; }
.CAYDENDIR-sd__cell-photo { width: 86px; height: 86px; }
.CAYDENDIR-sd__th-select, .CAYDENDIR-sd__cell-select { width: 44px; text-align: center; }
.CAYDENDIR-sd__select { width: 24px; height: 24px; cursor: pointer; }   /* 2.5.8 target size */
.CAYDENDIR-sd--selectable .CAYDENDIR-sd__table tbody tr { cursor: pointer; }
.CAYDENDIR-sd__th-edit, .CAYDENDIR-sd__cell-edit { width: 1%; white-space: nowrap; }

/* ---- CARDS layout ---- */
.CAYDENDIR-sd__list { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: var(--CAYDENDIR-gap); }
.CAYDENDIR-sd__card { display: flex; gap: 0.9rem; align-items: flex-start; padding: 1rem; background: var(--CAYDENDIR-row-bg); border: 1px solid var(--CAYDENDIR-border); border-radius: var(--CAYDENDIR-radius); }
.CAYDENDIR-sd__card[hidden] { display: none; }
.CAYDENDIR-sd__card .CAYDENDIR-sd__photo { width: 86px; height: 86px; }
.CAYDENDIR-sd__body { min-width: 0; }
.CAYDENDIR-sd__name { margin: 0 0 0.2rem; font-size: 1.05rem; line-height: 1.25; }
.CAYDENDIR-sd__job { margin: 0 0 0.25rem; color: var(--CAYDENDIR-muted); font-weight: 600; }
.CAYDENDIR-sd__tags { list-style: none; margin: 0.5rem 0 0; padding: 0; display: flex;  flex-wrap: wrap; gap: 0.35rem; }
.CAYDENDIR-sd__tag { font-size: 0.8rem; padding: 0.15rem 0.55rem; color: var(--CAYDENDIR-muted); background: var(--CAYDENDIR-chip-bg); border-radius: 999px; }

/* ---- edit dialog ---- */
body.CAYDENDIR-noscroll { overflow: hidden; }

.CAYDENDIR-sd__overlay {
	position: fixed;
	inset: 0;
	z-index: 100000;
	display: flex;
	align-items: flex-start;
	justify-content: center;
	padding: 1rem;
	background: rgba(15, 23, 42, 0.55);
	overflow-y: auto;
}
.CAYDENDIR-sd__dialog {
	width: 100%;
	max-width: 900px;
	margin: 2.5rem auto;
	padding: 1.25rem 1.25rem 1.5rem;
	background: #fff;
	color: var(--CAYDENDIR-fg);
	border: 1px solid var(--CAYDENDIR-border);
	border-radius: var(--CAYDENDIR-radius);
	box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
}
.CAYDENDIR-sd__dialog-title { margin: 0 0 0.5rem; font-size: 0.1rem; line-height: 1.3; }
.CAYDENDIR-sd__dialog-note { margin: 0 0 1rem; font-size: 0.1rem; color: var(--CAYDENDIR-muted); }
.CAYDENDIR-sd__field { margin: 0 0 0.9rem; }
.CAYDENDIR-sd__field label { display: block; font-weight: 600; margin-bottom: 0.3rem; }
.CAYDENDIR-sd__field input {
	width: 100%;
	min-height: 44px;
	padding: 0.5rem 0.75rem;
	font: inherit;
	color: var(--CAYDENDIR-fg);
	background: #fff;
	border: 1px solid var(--CAYDENDIR-border);
	border-radius: 8px;
}
.CAYDENDIR-sd__hint { display: block; margin: 0.3rem 0 0; font-size: 1.55rem; color: var(--CAYDENDIR-muted); }
.CAYDENDIR-sd__dialog-status { min-height: 1.4em; margin: 0.75rem 0 0; font-weight: 600; }
.CAYDENDIR-sd__dialog-actions { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 1rem; }
.CAYDENDIR-sd__btn {
	min-height: 44px;
	padding: 0.5rem 1.05rem;
	font: inherit;
	font-weight: 600;
	color: var(--CAYDENDIR-fg);
	background: #fff;
	border: 1px solid var(--CAYDENDIR-border);
	border-radius: 8px;
	cursor: pointer;
}
.CAYDENDIR-sd__btn--primary {
	color: var(--CAYDENDIR-accent-fg);
	background: var(--CAYDENDIR-accent);
	border-color: var(--CAYDENDIR-accent);
}
.CAYDENDIR-sd__btn--danger {
	color: #8a1f1f;             /* 7.4:1 on white — AA for normal text */
	border-color: #8a1f1f;
	margin-left: auto;
}
.CAYDENDIR-sd__btn[disabled] { opacity: 0.6; cursor: default; }

/* ---- MOBILE: stacked rows, no horizontal scroll ----
   Breakpoint is 600px so every phone (360–430px wide, and your 400px
   target) gets the stacked layout — the table's natural minimum width is
   560px, so anything under ~600px would otherwise scroll sideways.
   Lower this value to 400px if you only want stacking on the very
   smallest screens. Column labels ("Job:", "Location:", "Email:") come
   from the data-label attributes so the relationship survives the
   linearisation (WCAG 1.3.1). */
@media (max-width: 600px) {
	.CAYDENDIR-sd__table-wrap { overflow-x: visible; border: 0; border-radius: 0; }
	.CAYDENDIR-sd__table { display: block; width: 100%; min-width: 0; }
	.CAYDENDIR-sd__table thead { display: none; }
	.CAYDENDIR-sd__table tbody { display: block; width: 100%; }
	.CAYDENDIR-sd__table tbody tr {
		display: block;
		padding: 0.9rem;
		margin: 0 0 0.75rem;
		border: 1px solid var(--CAYDENDIR-border);
		border-radius: var(--CAYDENDIR-radius);
	}
	.CAYDENDIR-sd__table tbody tr:last-child { margin-bottom: 0; }
	.CAYDENDIR-sd__table th,
	.CAYDENDIR-sd__table td {
		display: block;
		width: auto;
		border: 0;
		padding: 0.2rem 0;
	}
	.CAYDENDIR-sd__cell-photo {
		display: inline-block;
		vertical-align: middle;
		width: auto;
		height: auto;
		padding: 0 0.8rem 0.35rem 0;
	}
	.CAYDENDIR-sd__cell-photo .CAYDENDIR-sd__photo { width: 56px; height: 56px; }
	.CAYDENDIR-sd__cell-name { display: block; padding: 0 0 0.3rem; font-size: 1.05rem; }
	.CAYDENDIR-sd__cell-photo + .CAYDENDIR-sd__cell-name {
		display: inline-block;
		vertical-align: middle;
		max-width: calc(100% - 84px);
	}
	.CAYDENDIR-sd__th-select,
	.CAYDENDIR-sd__cell-select { width: auto; text-align: left; }
	.CAYDENDIR-sd__table td[data-label]::before {
		content: attr(data-label) ": ";
		font-weight: 600;
		color: var(--CAYDENDIR-muted);
	}
	.CAYDENDIR-sd__cell-edit { padding-top: 0.55rem; white-space: normal; }
	.CAYDENDIR-sd__list { grid-template-columns: 1fr; }
	.CAYDENDIR-sd__card { min-width: 0; }
	.CAYDENDIR-sd__input { max-width: 100%; }
	.CAYDENDIR-sd__dialog { margin: 1rem auto; }
}

/* ---- motion (2.3.3) ---- */
@media (prefers-reduced-motion: no-preference) {
	.CAYDENDIR-sd__chip, .CAYDENDIR-sd__table tbody tr { transition: background-color 0.15s ease, color 0.15s ease; }
}

/* ---- forced-colors / high contrast ---- */
@media (forced-colors: active) {
	.CAYDENDIR-sd__chip[aria-pressed="true"] { border: 2px solid; }
	.CAYDENDIR-sd :focus-visible { outline: 3px solid; }
	.CAYDENDIR-sd__table tbody tr[data-selected] { outline: 2px solid; outline-offset: -2px; }
	.CAYDENDIR-sd__badge,
	.CAYDENDIR-sd__btn,
	.CAYDENDIR-sd__edit-btn { border: 1px solid; }
	.CAYDENDIR-sd__overlay { background: Canvas; }
}
CSS;
}

/**
 * Default job sort order — the sheet's display labels, highest priority
 * first (derived from JobsSorted run through JobLabels). Editable in
 * Settings › Staff Directory › Sort order.
 */
function CAYDENDIR_sd_default_job_order() {
	return "Board Members\nSuperintendent\nExecutive Assistant\nChief\nDirector\nAdministrative Coordinator\nPublic Information Officer\nCoordinator of Special Projects\nCentral Office Supervisor\nStaff Accountant\nAssistant Supervisor\nCentral Office Principal\nIT Web Developer\nWebmaster\nComputer Technician I\nAnalyst\nComputer Technician II\nContractual Hourly\nPrincipal\nAssistant Principal\nAssistant Principal II\nAdministrative Secretary\nAdministrative Assistant\nSecretary I\nSecretary II\nSchool Financial Secretary\nAccount Clerk\nAccount/Payroll Clerk I\nClerical Assistant\nSpecialist\nSpecial Education Facilitator\nFamily Engagement Coordinator\nSchool Counselor\nProject YES Coordinator\nPupil Personnel Worker\nCoordinator\nPsychologist\nPsychologist (10 Month)\nMental Health Specialist\nSchool Social Worker\nSpeech Pathologist\nCase Manager\nAthletic Trainer\nCareer Coach\nTeacher\nLibrary Media Specialist\nAthletic Coach\nLong-Term Substitute\nContractual Teacher\nInstructional Assistant\nSubstitute Teacher\nSchool Safety Employee\nOperations Specialist\nOperations Foreman\nOperations Driver\nMaintenance Foreman\nMaintenance I\nHead Custodian I\nHead Custodian II\nHead Custodian III\nCustodian\nCustodian / Head Custodian III\nBus Driver\nWarehouse Driver\nLibrary Media Technician\nTechnician Classified\nCafeteria Manager I\nCafeteria Manager II\nCafeteria Manager III\nCafeteria Assistant I\nCafeteria Assistant II\nContractual Instructional Assistant\nSpeech Pathology\nContractual Speech Pathologist\nContractual Social Worker\nSecretary\nContractual Clerical Assistant\nContractual Custodian\nContractual Non-Public\nPartners for Success\nUndefined Job";
}

/**
 * Default location sort order — sheet display labels, highest priority
 * first (derived from LocationsSorted run through LocationLabels).
 * Editable in Settings › Staff Directory › Sort order.
 */
function CAYDENDIR_sd_default_location_order() {
	return "Awaiting Placement\nBoard of Education\nBOE\nInformation Technology\nAcademic Instruction\nSpecial Education School\nStudent Services/School Safety\nTransportation\nMaintenance\nMaintenance School\nOperations Warehouse\nMeal Warehouse\nAllegany High School\nFort Hill High School\nMountain Ridge High School\nCareer Center\nWashington Middle School\nBraddock Middle School\nWestmar Middle School\nMount Savage Middle School\nMount Savage School\nBeall Elementary School\nBel Air Elementary School\nCash Valley Elementary School\nCresaptown Elementary School\nFlintstone Elementary School\nFrost Elementary School\nGeorges Creek Elementary School\nJohn Humbird Elementary School\nMount Savage Elementary School\nNortheast Elementary School\nParkside Elementary School\nSouth Penn Elementary School\nWest Side Elementary School\nWesternport Elementary School\nRestart/Eckhart Program\nAllegany College of Maryland\nSubstitutes\nUndefined Location";
}

function CAYDENDIR_sd_get_settings() {
	$defaults = array(
		'gas_url'        => '',
		'secret_key'     => '',
		'place_code'     => 'AA',
		'signifier'      => 'main staff directory',
		'placement_code' => 'FL',
		'accessor_key'   => '123456789',
		'col_letter'     => 'F',
		'layout'         => 'table',
		'columns'        => array( 'photo', 'location', 'job', 'email' ),
		'hover'          => '1',
		'selectable'     => '0',
		'colors'         => CAYDENDIR_sd_default_colors(),
		'job_order'      => CAYDENDIR_sd_default_job_order(),
		'location_order' => CAYDENDIR_sd_default_location_order(),
		'custom_css'     => CAYDENDIR_sd_default_css(),
	);
	$saved = get_option( CAYDENDIR_SD_SETTINGS, array() );
	$saved = is_array( $saved ) ? $saved : array();
	$out   = wp_parse_args( $saved, $defaults );
	$out['colors'] = wp_parse_args( is_array( $out['colors'] ) ? $out['colors'] : array(), CAYDENDIR_sd_default_colors() );
	if ( ! is_array( $out['columns'] ) ) {
		$out['columns'] = $defaults['columns'];
	}
	return $out;
}

/**
 * The CSS the front end actually uses. Comes from the Custom CSS setting;
 * falls back to the built-in default if that box was left blank. The
 * "</style" guard makes sure stored CSS can never break out of the inline
 * <style> element it is printed inside.
 */
function CAYDENDIR_sd_get_css() {
	$s   = CAYDENDIR_sd_get_settings();
	$css = isset( $s['custom_css'] ) ? (string) $s['custom_css'] : '';
	if ( '' === trim( $css ) ) {
		$css = CAYDENDIR_sd_default_css();
	}
	return str_ireplace( '</style', '<\/style', $css );
}

//handshake logic
function CAYDENDIR_sd_short_code_mod( $text ) {
	$text = strtolower( trim( (string) $text ) );
	$h    = 0;
	$len  = strlen( $text );
	for ( $i = 0; $i < $len; $i++ ) {
		$h = ( $h * 31 + ord( $text[ $i ] ) ) % 65535;
	}
	return $h;
}

function CAYDENDIR_sd_build_id( $settings = null ) {
	if ( null === $settings ) {
		$settings = CAYDENDIR_sd_get_settings();
	}
	return sprintf(
		'%s-%d-%s-%s-%s',
		strtoupper( $settings['place_code'] ),
		CAYDENDIR_sd_short_code_mod( $settings['signifier'] ),
		strtoupper( $settings['placement_code'] ),
		$settings['accessor_key'],
		strtoupper( substr( $settings['col_letter'], 0, 1 ) )
	);
}

function CAYDENDIR_sd_build_token( $id, $secret ) {
	return hash_hmac( 'sha256', $id, $secret );
}

//sync system
/**
 * Collapse duplicate photos. Two entries are duplicates when they resolve to
 * the same Drive file ID, since every thumbnail and Drive URL is built from it.
 * First occurrence wins.
 */
function dis_dedupe_images( $images ) {
	if ( ! is_array( $images ) ) {
		return array();
	}
	$seen = array();
	$out  = array();
	foreach ( $images as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['email'] ) ) {
			continue;
		}
		if ( isset( $seen[ $entry['email'] ] ) ) {
			continue; // already have this photo
		}
		$seen[ $entry['email'] ] = true;
		$out[]                = $entry;
	}
	return $out;
}
function CAYDENDIR_sd_sync() {
	$settings = CAYDENDIR_sd_get_settings();

	if ( empty( $settings['gas_url'] ) || empty( $settings['secret_key'] ) ) {
		$err = 'Apps Script URL or secret key is not set.';
		CAYDENDIR_sd_record_sync( false, $err );
		return new WP_Error( 'CAYDENDIR_sd_config', $err );
	}

	$id    = CAYDENDIR_sd_build_id( $settings );
	$token = CAYDENDIR_sd_build_token( $id, $settings['secret_key'] );
	$acta  = 'directory_sync';

	$url = add_query_arg(
		array(
			'id'    => rawurlencode( $id ),
			'token' => $token,
        	'action' => $acta,
		),
		$settings['gas_url']
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 25,
			'redirection' => 5,
			'headers'     => array( 'Accept' => 'application/json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		CAYDENDIR_sd_record_sync( false, $response->get_error_message() );
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$json = json_decode( $body, true );

	if ( 200 !== $code || ! is_array( $json ) ) {
		$msg = 'Unexpected response (HTTP ' . $code . ').';
		CAYDENDIR_sd_record_sync( false, $msg );
		return new WP_Error( 'CAYDENDIR_sd_http', $msg );
	}

	if ( empty( $json['success'] ) ) {
		$msg = isset( $json['error'] ) ? (string) $json['error'] : 'Apps Script returned an error.';
		CAYDENDIR_sd_record_sync( false, $msg );
		return new WP_Error( 'CAYDENDIR_sd_remote', $msg );
	}

	$clean = CAYDENDIR_sd_sanitize_records( isset( $json['data'] ) ? $json['data'] : array() );
    if ( count($clean) == 0 ){
		CAYDENDIR_sd_record_sync( true, sprintf( '%d records synced. New Data Not Updated, Empty Spreadsheet Detected! ', count( $clean ) ) );
    }else{
		update_option( CAYDENDIR_SD_DATA_OPTION, $clean, false );
		CAYDENDIR_sd_purge_caches();
		CAYDENDIR_sd_record_sync( true, sprintf( '%d records synced.', count( $clean ) ) );
    }

	// NOTE: the sync never reads or writes CAYDENDIR_SD_MANUAL_OPTION.
	// Manual overrides live in their own option and are merged over the
	// synced data at render time, so automatic data can never edit them.

	return $clean;
}
/** Flush WP Engine / object caches so logged-out visitors see changes. */
function CAYDENDIR_sd_purge_caches() {
	if ( class_exists( 'WpeCommon' ) ) {
		if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) { WpeCommon::purge_varnish_cache(); }
		if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) { WpeCommon::purge_memcached(); }
	}
	wp_cache_flush();
}
function CAYDENDIR_sd_record_sync( $ok, $message ) {
	update_option(
		CAYDENDIR_SD_META_OPTION,
		array(
			'time'    => time(),
			'ok'      => (bool) $ok,
			'message' => (string) $message,
		),
		false
	);
}

// make rows clean
function CAYDENDIR_sd_sanitize_records( $data ) {
	if ( ! is_array( $data ) ) {
		return array();
	}
	$clean = array();
	foreach ( $data as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$entry = array(
			'name'  => '',
			'email' => '',
			'photo' => '',
            'publictitle' => '',
			'location' => '',
			'job'  => '',
			'tags'  => array(),
			'id'    => '',
		);
		foreach ( $row as $key => $value ) {
			$k = strtolower( trim( (string) $key ) );
			$v = is_scalar( $value ) ? trim( (string) $value ) : '';

			if ( in_array( $k, array( 'name', 'fullname', 'full name' ), true ) ) {
				$entry['name'] = sanitize_text_field( $v );
			} elseif ( in_array( $k, array( 'email', 'e-mail' ), true ) ) {
				$entry['email'] = sanitize_email( $v );
			} elseif ( in_array( $k, array( 'photo', 'photoid', 'photo id', 'image', 'drive', 'driveid', 'drive id' ), true ) ) {
				$entry['photo'] = sanitize_text_field( $v );
			} elseif ( in_array( $k, array( 'publictitle', 'title', 'public', 'titlepublic' ), true ) ) {
				$entry['publictitle'] = sanitize_text_field( $v );
			} elseif ( in_array( $k, array( 'location', 'facility', 'place', 'area' ), true ) ) {
				$entry['location'] = sanitize_text_field( $v );
			} elseif ( in_array( $k, array( 'job', 'role', 'position', 'jobtitle' ), true ) ) {
				$entry['job'] = sanitize_text_field( $v );
			} elseif ( in_array( $k, array( 'tags', 'tag', 'desc', 'description', 'categories', 'category' ), true ) ) {
				$entry['tags'] = CAYDENDIR_sd_parse_tags( $v );
			} elseif ( in_array( $k, array( 'id', 'identification', 'identifier', 'identity', 'ident' ), true ) ) {
				$entry['id'] = sanitize_text_field( $v );
			}
		}
		if ( '' === $entry['name'] && '' === $entry['email'] ) {
			continue;
		}
		$clean[] = $entry;
	}
	return $clean;
}

function CAYDENDIR_sd_parse_tags( $value ) {
	$parts = preg_split( '/[,;|]+/', (string) $value );
	$tags  = array();
	foreach ( $parts as $p ) {
		$p = sanitize_text_field( trim( $p ) );
		if ( '' !== $p ) {
			$tags[] = $p;
		}
	}
	return array_values( array_unique( $tags ) );
}

function CAYDENDIR_sd_photo_url( $photo, $size = 200 ) {
	$photo = trim( (string) $photo );
	if ( '' === $photo ) {
		return '';
	}

	// strpos() instead of str_contains() so the plugin really runs on PHP 7.4.
	if ( false !== strpos( $photo, 'lh3.googleusercontent' ) ) {
		return $photo;
	}
	if ( preg_match( '#/d/([a-zA-Z0-9_-]+)#', $photo, $m ) ) {
		$photo = $m[1];
	} elseif ( preg_match( '#[?&]id=([a-zA-Z0-9_-]+)#', $photo, $m ) ) {
		$photo = $m[1];
	}
	if ( ! preg_match( '#^[a-zA-Z0-9_-]{10,}$#', $photo ) ) {
		return '';
	}
	return 'https://drive.google.com/thumbnail?id=' . rawurlencode( $photo ) . '&sz=w' . (int) $size;

}

function CAYDENDIR_sd_initials( $name ) {
	$name = trim( (string) $name );
	if ( '' === $name ) {
		return '?';
	}
	$parts    = preg_split( '/\s+/', $name );
	$initials = '';
	foreach ( $parts as $p ) {
		$initials .= strtoupper( function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 1 ) : substr( $p, 0, 1 ) );
		if ( strlen( $initials ) >= 2 ) {
			break;
		}
	}
	return '' !== $initials ? $initials : '?';
}

/* -------------------------------------------------------------------------
 * Manual overrides (persistent data)
 *
 * Manual records are stored in their own option, keyed by a "match key"
 * derived from the row at the moment it was edited:
 *
 *   id:XX-00000-XX-000000000-X   (preferred — the Identification number)
 *   em:someone@acpsmd.org        (fallback when a row has no ID yet)
 *   nm:<md5 of lowercased name>  (last resort — name-only rows)
 *
 * At render time the synced data is merged with the overrides: any synced
 * row whose keys hit an override is replaced by the override, and overrides
 * that no longer match anything in the sync are still shown (persistent).
 * The sync process never touches this option, so automatic data can never
 * edit or delete a manual record. Editing the visible ID does NOT change
 * the match key, so the override keeps matching the original synced row.
 *
 * Overrides can also mark a person hidden ('hidden' => '1'): hidden rows
 * are removed for the public but stay visible to editors with a "Hidden"
 * badge so they can be un-hidden later.
 * ---------------------------------------------------------------------- */

function CAYDENDIR_sd_edit_cap() {
	// Change who may edit with: add_filter( 'CAYDENDIR_sd_edit_cap', function () { return 'edit_others_posts'; } );
	return apply_filters( 'CAYDENDIR_sd_edit_cap', 'manage_options' );
}

function CAYDENDIR_sd_user_can_edit() {
	return is_user_logged_in() && current_user_can( CAYDENDIR_sd_edit_cap() );
}

function CAYDENDIR_sd_normalize_record( $row ) {
	$defaults = array(
		'name'     => '',
		'email'    => '',
		'photo'    => '',
        'publictitle' => '',
		'location' => '',
		'job'      => '',
		'tags'     => array(),
		'id'       => '',
		'hidden'   => '0',
	);
	$row = is_array( $row ) ? $row : array();
	$row = wp_parse_args( $row, $defaults );
	if ( ! is_array( $row['tags'] ) ) {
		$row['tags'] = array();
	}
	return $row;
}

/** All candidate match keys for a row, most specific first. */
function CAYDENDIR_sd_row_keys( $row ) {
	$row  = CAYDENDIR_sd_normalize_record( $row );
	$keys = array();

	$id = trim( (string) $row['id'] );
	if ( '' !== $id ) {
		$keys[] = 'id:' . strtoupper( $id );
	}
	$email = strtolower( trim( (string) $row['email'] ) );
	if ( '' !== $email ) {
		$keys[] = 'em:' . $email;
	}
	$name = strtolower( trim( (string) $row['name'] ) );
	if ( '' !== $name ) {
		$keys[] = 'nm:' . md5( $name );
	}
	if ( empty( $keys ) ) {
		$keys[] = 'nm:' . md5( '' );
	}
	return $keys;
}

/** The primary (most specific) match key for a row. */
function CAYDENDIR_sd_row_key( $row ) {
	$keys = CAYDENDIR_sd_row_keys( $row );
	return $keys[0];
}

function CAYDENDIR_sd_get_manual_data() {
	$manual = get_option( CAYDENDIR_SD_MANUAL_OPTION, array() );
	return is_array( $manual ) ? $manual : array();
}

/**
 * Synced data with manual overrides merged over the top, then sorted by
 * the job/location priority lists (same ordering idea as the spreadsheet).
 * Each returned row carries '_manual' (bool) and '_key' (its match key).
 */
function CAYDENDIR_sd_get_merged_data() {
	$synced = get_option( CAYDENDIR_SD_DATA_OPTION, array() );
	$synced = is_array( $synced ) ? $synced : array();
	$manual = CAYDENDIR_sd_get_manual_data();

	$out  = array();
	$used = array();

	foreach ( $synced as $row ) {
		$row     = CAYDENDIR_sd_normalize_record( $row );
		$matched = false;
		// Try id: first, then em:, then nm: — this keeps overrides working
		// even if the sync later gains ID data it did not have before.
		foreach ( CAYDENDIR_sd_row_keys( $row ) as $key ) {
			if ( isset( $manual[ $key ] ) ) {
				$m            = CAYDENDIR_sd_normalize_record( $manual[ $key ] );
				$m['_manual'] = true;
				$m['_key']    = $key;
				$out[]        = $m;
				$used[ $key ] = true;
				$matched      = true;
				break;
			}
		}
		if ( ! $matched ) {
			$row['_manual'] = false;
			$row['_key']    = CAYDENDIR_sd_row_key( $row );
			$out[]          = $row;
		}
	}

	// Persistent: overrides with no matching synced row still show.
	foreach ( $manual as $key => $m ) {
		if ( isset( $used[ $key ] ) ) {
			continue;
		}
		$m            = CAYDENDIR_sd_normalize_record( $m );
		$m['_manual'] = true;
		$m['_key']    = (string) $key;
		$out[]        = $m;
	}

	return CAYDENDIR_sd_sort_rows( $out );
}

/* -------------------------------------------------------------------------
 * Sorting — the spreadsheet sorts by job rank then location rank via
 * Apps Script, but edited/manual data never goes back to the sheet, so the
 * same sort has to happen here at render time. The rank lists live in
 * Settings › Staff Directory › Sort order (one display label per line).
 * ---------------------------------------------------------------------- */

/** "Label\nLabel\n…" → array( lowercased label => rank ). */
function CAYDENDIR_sd_rank_map( $text ) {
	$map = array();
	$i   = 0;
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$line = strtolower( trim( $line ) );
		if ( '' !== $line && ! isset( $map[ $line ] ) ) {
			$map[ $line ] = $i++;
		}
	}
	return $map;
}

/**
 * Sort rows by job rank, then location rank. Labels not found in the
 * lists sink to the bottom (like the Infinity fallback in the Apps Script
 * sort) — which also makes typos in overrides easy to spot. Ties keep
 * their incoming order, so nothing shuffles arbitrarily.
 */
function CAYDENDIR_sd_sort_rows( $rows ) {
	if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
		return is_array( $rows ) ? $rows : array();
	}

	$s    = CAYDENDIR_sd_get_settings();
	$jobs = CAYDENDIR_sd_rank_map( $s['job_order'] );
	$locs = CAYDENDIR_sd_rank_map( $s['location_order'] );
	$big  = PHP_INT_MAX;

	$i = 0;
	foreach ( $rows as &$r ) {
		$j = strtolower( trim( (string) ( isset( $r['job'] ) ? $r['job'] : '' ) ) );
		$l = strtolower( trim( (string) ( isset( $r['location'] ) ? $r['location'] : '' ) ) );
		$r['_jr'] = isset( $jobs[ $j ] ) ? $jobs[ $j ] : $big;
		$r['_lr'] = isset( $locs[ $l ] ) ? $locs[ $l ] : $big;
		$r['_i']  = $i++;
	}
	unset( $r );

	usort( $rows, function ( $a, $b ) {
		if ( $a['_jr'] !== $b['_jr'] ) { return ( $a['_jr'] < $b['_jr'] ) ? -1 : 1; }
		if ( $a['_lr'] !== $b['_lr'] ) { return ( $a['_lr'] < $b['_lr'] ) ? -1 : 1; }
		return ( $a['_i'] < $b['_i'] ) ? -1 : 1;
	} );

	foreach ( $rows as &$r ) {
		unset( $r['_jr'], $r['_lr'], $r['_i'] );
	}
	unset( $r );

	return $rows;
}

/** Shared shape for AJAX responses so the front end can update a row in place. */
function CAYDENDIR_sd_row_response( $entry, $key, $manual ) {
	$entry = CAYDENDIR_sd_normalize_record( $entry );

	$terms = $entry['tags'];
	if ( '' !== $entry['job'] ) {
		$terms[] = $entry['job'];
	}
	$terms_lower = array_map( 'strtolower', $terms );
	$blob        = strtolower( trim( $entry['name'] . ' ' . $entry['publictitle'] . ' ' . $entry['job'] . ' ' . $entry['location'] . ' ' . $entry['email'] . ' ' . $entry['id'] . ' ' . implode( ' ', $entry['tags'] ) ) );

	return array(
		'key'         => (string) $key,
		'manual'      => (bool) $manual,
		'name'        => $entry['name'],
		'email'       => $entry['email'],
		'photo'       => $entry['photo'],
        'publictitle' => $entry['publictitle'],
		'location'    => $entry['location'],
		'job'         => $entry['job'],
		'id'          => $entry['id'],
		'tags'        => array_values( $entry['tags'] ),
		'tags_joined' => implode( ', ', $entry['tags'] ),
		'search'      => $blob,
		'tagstr'      => implode( '|', $terms_lower ),
		'photo_html'  => CAYDENDIR_sd_photo_markup( $entry['name'], $entry['photo'] ),
		'hidden'      => isset( $entry['hidden'] ) ? (string) $entry['hidden'] : '0',
	);
}

/* ---- AJAX: save a manual override ---- */
add_action( 'wp_ajax_CAYDENDIR_sd_save_manual', 'CAYDENDIR_sd_ajax_save_manual' );
function CAYDENDIR_sd_ajax_save_manual() {
	if ( ! CAYDENDIR_sd_user_can_edit() ) {
		wp_send_json_error( array( 'message' => 'You are not allowed to edit the directory.' ), 403 );
	}
	check_ajax_referer( 'CAYDENDIR_sd_edit', 'nonce' );

	$text = function ( $field ) {
		if ( ! isset( $_POST[ $field ] ) || ! is_string( $_POST[ $field ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
	};

	$raw_email = ( isset( $_POST['email'] ) && is_string( $_POST['email'] ) ) ? trim( wp_unslash( $_POST['email'] ) ) : '';
	$email     = sanitize_email( $raw_email );
	if ( '' !== $raw_email && '' === $email ) {
		wp_send_json_error( array( 'message' => 'That email address does not look valid.' ) );
	}

	$entry = array(
		'name'     => $text( 'name' ),
		'email'    => $email,
		'photo'    => $text( 'photo' ),
        'publictitle' => $text('publictitle'),
		'location' => $text( 'location' ),
		'job'      => $text( 'job' ),
		'tags'     => CAYDENDIR_sd_parse_tags( $text( 'tags' ) ),
		'id'       => $text( 'id' ),
		'hidden'   => ( isset( $_POST['hidden'] ) && '1' === $_POST['hidden'] ) ? '1' : '0',
	);

	if ( '' === $entry['name'] && '' === $entry['email'] ) {
		wp_send_json_error( array( 'message' => 'Enter at least a name or an email address.' ) );
	}

	// The match key is the row's ORIGINAL identity — it never changes when
	// the visible ID is edited, so future syncs keep being overridden.
	$key = $text( 'key' );
	if ( '' === $key ) {
		$key = CAYDENDIR_sd_row_key( $entry );
	}

	$entry['_updated']    = time();
	$entry['_updated_by'] = get_current_user_id();

	$manual         = CAYDENDIR_sd_get_manual_data();
	$manual[ $key ] = $entry;
	update_option( CAYDENDIR_SD_MANUAL_OPTION, $manual, false );
	CAYDENDIR_sd_purge_caches();

	wp_send_json_success( CAYDENDIR_sd_row_response( $entry, $key, true ) );
}

/* ---- AJAX: remove a manual override (row goes back to synced data) ---- */
add_action( 'wp_ajax_CAYDENDIR_sd_delete_manual', 'CAYDENDIR_sd_ajax_delete_manual' );
function CAYDENDIR_sd_ajax_delete_manual() {
	if ( ! CAYDENDIR_sd_user_can_edit() ) {
		wp_send_json_error( array( 'message' => 'You are not allowed to edit the directory.' ), 403 );
	}
	check_ajax_referer( 'CAYDENDIR_sd_edit', 'nonce' );

	$key = ( isset( $_POST['key'] ) && is_string( $_POST['key'] ) ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	if ( '' === $key ) {
		wp_send_json_error( array( 'message' => 'Missing row key.' ) );
	}

	$manual = CAYDENDIR_sd_get_manual_data();
	if ( isset( $manual[ $key ] ) ) {
		unset( $manual[ $key ] );
		update_option( CAYDENDIR_SD_MANUAL_OPTION, $manual, false );
		CAYDENDIR_sd_purge_caches();
	}

	// If a synced row matches the removed key, hand it back so the front
	// end can restore the automatic data in place.
	$synced = get_option( CAYDENDIR_SD_DATA_OPTION, array() );
	$synced = is_array( $synced ) ? $synced : array();
	foreach ( $synced as $row ) {
		$row = CAYDENDIR_sd_normalize_record( $row );
		if ( in_array( $key, CAYDENDIR_sd_row_keys( $row ), true ) ) {
			wp_send_json_success( array_merge(
				array( 'restored' => true ),
				CAYDENDIR_sd_row_response( $row, CAYDENDIR_sd_row_key( $row ), false )
			) );
		}
	}

	wp_send_json_success( array(
		'restored' => false,
		'key'      => $key,
	) );
}

//activate plugin stuff and deactive

register_activation_hook( __FILE__, 'CAYDENDIR_sd_activate' );
function CAYDENDIR_sd_activate() {
	if ( ! wp_next_scheduled( CAYDENDIR_SD_CRON_HOOK ) ) {
		$ts = strtotime( 'tomorrow 3:00am' );
		wp_schedule_event( $ts ? $ts : ( time() + DAY_IN_SECONDS ), 'daily', CAYDENDIR_SD_CRON_HOOK );
	}
	CAYDENDIR_sd_sync();
}

register_deactivation_hook( __FILE__, 'CAYDENDIR_sd_deactivate' );
function CAYDENDIR_sd_deactivate() {
	$ts = wp_next_scheduled( CAYDENDIR_SD_CRON_HOOK );
	if ( $ts ) {
		wp_unschedule_event( $ts, CAYDENDIR_SD_CRON_HOOK );
	}
}

add_action( CAYDENDIR_SD_CRON_HOOK, 'CAYDENDIR_sd_sync' );

//stuff to make it usable on website

add_action( 'wp_enqueue_scripts', 'CAYDENDIR_sd_register_assets' );
function CAYDENDIR_sd_register_assets() {
	// The style handle intentionally has NO source file of its own. The
	// stylesheet is supplied as inline CSS from the Custom CSS setting (see
	// CAYDENDIR_sd_get_css()), which is attached with wp_add_inline_style()
	// when the shortcode renders. Registering with a false src is the
	// standard WordPress pattern for an inline-only ("dynamic") stylesheet.
	wp_register_style( 'CAYDENDIR-sd', false, array(), CAYDENDIR_SD_VERSION );
	wp_register_script( 'CAYDENDIR-sd', plugin_dir_url(__FILE__) . 'CAYDENDIR-directory.js', array(), CAYDENDIR_SD_VERSION, true );
}

/** Build the inline CSS-variable style string from saved colors. */
function CAYDENDIR_sd_color_style( $colors ) {
	$map = array(
		'accent'    => '--CAYDENDIR-accent',
		'accent_fg' => '--CAYDENDIR-accent-fg',
		'text'      => '--CAYDENDIR-fg',
		'muted'     => '--CAYDENDIR-muted',
		'border'    => '--CAYDENDIR-border',
		'row_bg'    => '--CAYDENDIR-row-bg',
		'row_alt'   => '--CAYDENDIR-row-alt',
		'selected'  => '--CAYDENDIR-selected',
		'header_bg' => '--CAYDENDIR-header-bg',
	);
	$parts = array();
	foreach ( $map as $key => $var ) {
		if ( ! empty( $colors[ $key ] ) ) {
			$parts[] = $var . ':' . $colors[ $key ];
		}
	}
	return implode( ';', $parts );
}

//photo markup
function CAYDENDIR_sd_photo_markup( $name, $photo ) {
	$url = CAYDENDIR_sd_photo_url( $photo );
	if ( $url ) {
		return sprintf(
			'<img class="CAYDENDIR-sd__photo" src="%s" alt="%s" loading="lazy" decoding="async" width="48" height="48">',
			esc_url( $url ),
			esc_attr( '' !== $name ? sprintf( 'Photo of %s', $name ) : '' )
		);
	}
	return sprintf(
		'<span class="CAYDENDIR-sd__photo CAYDENDIR-sd__photo--placeholder" aria-hidden="true">%s</span>',
		esc_html( CAYDENDIR_sd_initials( $name ) )
	);
}

add_shortcode( 'CAYDENDIR_staff_directory', 'CAYDENDIR_sd_render' );
function CAYDENDIR_sd_render( $atts ) {
	$settings = CAYDENDIR_sd_get_settings();
	$atts = shortcode_atts(
		array(
			'heading' => 'Staff Directory',
			'match'   => 'any', // any = OR across selected tags, all = AND.
			'layout'  => '',     // override settings layout if provided
		),
		$atts,
		'CAYDENDIR_staff_directory'
	);

	// Synced data with manual overrides merged over it (already sorted).
	$data = CAYDENDIR_sd_get_merged_data();

	$can_edit = CAYDENDIR_sd_user_can_edit();

	// Hidden people are removed for the public; editors still see them
	// (with a "Hidden" badge) so they can be un-hidden later.
	if ( ! $can_edit ) {
		$data = array_values( array_filter( $data, function ( $row ) {
			return empty( $row['hidden'] ) || '1' !== (string) $row['hidden'];
		} ) );
	}

	wp_enqueue_style( 'CAYDENDIR-sd' );
	wp_enqueue_script( 'CAYDENDIR-sd' );

	// Attach the Custom CSS setting as the directory's stylesheet. Done once
	// per request even when the shortcode appears several times on a page.
	static $CAYDENDIR_css_done = false;
	if ( ! $CAYDENDIR_css_done ) {
		wp_add_inline_style( 'CAYDENDIR-sd', CAYDENDIR_sd_get_css() );
		$CAYDENDIR_css_done = true;
	}

	if ( $can_edit ) {
		wp_localize_script( 'CAYDENDIR-sd', 'CAYDENDIRSD', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'CAYDENDIR_sd_edit' ),
		) );
	}

	$layout     = $atts['layout'] ? $atts['layout'] : $settings['layout'];
	$layout     = ( 'cards' === $layout ) ? 'cards' : 'table';
	$cols       = $settings['columns'];
	$show_photo = in_array( 'photo', $cols, true );
    $show_publictitle = in_array( 'publictitle', $cols, true);
	$show_job  = in_array( 'job', $cols, true );
	$show_location = in_array( 'location', $cols, true );
	$show_email = in_array( 'email', $cols, true );
	$selectable = ( '1' === (string) $settings['selectable'] );
	$hover      = ( '1' === (string) $settings['hover'] );
	$match      = ( 'all' === strtolower( $atts['match'] ) ) ? 'all' : 'any';

	// Filter chips: union of "type" values and tags.
	$all_tags = array();
	foreach ( $data as $row ) {
		if ( ! empty( $row['job'] ) ) {
			$all_tags[ $row['job'] ] = true;
		}
		if ( ! empty( $row['tags'] ) && is_array( $row['tags'] ) ) {
			foreach ( $row['tags'] as $t ) {
				$all_tags[ $t ] = true;
			}
		}
	}
	$all_tags = array_keys( $all_tags );
	sort( $all_tags, SORT_NATURAL | SORT_FLAG_CASE );

	$uid     = 'CAYDENDIR-sd-' . wp_unique_id();
	$style   = CAYDENDIR_sd_color_style( $settings['colors'] );
	$classes = 'CAYDENDIR-sd CAYDENDIR-sd--' . $layout;
	if ( $hover ) {
		$classes .= ' CAYDENDIR-sd--hover';
	}
	if ( $selectable ) {
		$classes .= ' CAYDENDIR-sd--selectable';
	}

	ob_start();
	?>
	<section class="<?php echo esc_attr( $classes ); ?>" id="<?php echo esc_attr( $uid ); ?>" aria-label="<?php echo esc_attr( $atts['heading'] ); ?>" data-match="<?php echo esc_attr( $match ); ?>" data-selectable="<?php echo $selectable ? '1' : '0'; ?>" data-can-edit="<?php echo $can_edit ? '1' : '0'; ?>"<?php echo $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?>>
		<h2 class="CAYDENDIR-sd__title"><?php echo esc_html( $atts['heading'] ); ?></h2>

		<div class="CAYDENDIR-sd__controls">
			<div class="CAYDENDIR-sd__search" role="search">
				<label class="CAYDENDIR-sd__label" for="<?php echo esc_attr( $uid ); ?>-q">Search the directory</label>
				<input type="search" id="<?php echo esc_attr( $uid ); ?>-q" class="CAYDENDIR-sd__input"
					placeholder="Search by Name, School, Job, Email&hellip;" autocomplete="off" spellcheck="false" data-CAYDENDIR-search>
			</div>


		</div>

		<p class="CAYDENDIR-sd__status" role="status" aria-live="polite" data-CAYDENDIR-status></p>

		<?php
		if ( empty( $data ) ) :
			?>
			<p class="CAYDENDIR-sd__empty">The directory hasn&rsquo;t been synced yet. An administrator can sync it under <strong>Settings &rsaquo; Staff Directory</strong>.</p>
			<?php
		elseif ( 'table' === $layout ) :
			?>
			<div class="CAYDENDIR-sd__table-wrap">
				<table class="CAYDENDIR-sd__table">
					<thead>
						<tr>
							<?php if ( $selectable ) : ?>
								<th scope="col" class="CAYDENDIR-sd__th-select"><span class="CAYDENDIR-sd__sr">Select</span></th>
							<?php endif; ?>
							<?php if ( $show_photo || $can_edit ) : ?>
								<th scope="col" class="CAYDENDIR-sd__th-photo"><span class="CAYDENDIR-sd__sr">Photo</span></th>
							<?php endif; ?>
							<th scope="col">Name</th>
							<?php if ( $show_publictitle || $can_edit) : ?>
								<th scope="col">Title</th>
							<?php endif; ?>
							<?php if ( $show_job || $can_edit) : ?>
								<th scope="col">Job</th>
							<?php endif; ?>
							<?php if ( $show_location || $can_edit ) : ?>
								<th scope="col">Location</th>
							<?php endif; ?>
							<?php if ( $show_email || $can_edit) : ?>
								<th scope="col">Email</th>
							<?php endif; ?>
							<?php if ( $can_edit ) : ?>
								<th scope="col" class="CAYDENDIR-sd__th-edit">Edit</th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php
						$i = 0;
						foreach ( $data as $row ) :
							$i++;
							$name  = isset( $row['name'] ) ? $row['name'] : '';
							$email = isset( $row['email'] ) ? $row['email'] : '';
							$location = isset( $row['location'] ) ? $row['location'] : '';
    						$publictitle = isset($row['publictitle']) ? $row['publictitle'] : '';
							$job  = isset( $row['job'] ) ? $row['job'] : '';
							$tags  = ( isset( $row['tags'] ) && is_array( $row['tags'] ) ) ? $row['tags'] : array();
							$photo = isset( $row['photo'] ) ? $row['photo'] : '';
							$rid   = isset( $row['id'] ) ? $row['id'] : '';
							$hidden    = ( isset( $row['hidden'] ) && '1' === (string) $row['hidden'] );
							$is_manual = ! empty( $row['_manual'] );
							$key       = isset( $row['_key'] ) ? $row['_key'] : CAYDENDIR_sd_row_key( $row );

							$filter_terms = $tags;
							if ( '' !== $job ) {
								$filter_terms[] = $job;
							}
							$terms_lower = array_map( 'strtolower', $filter_terms );
							$search_blob = strtolower( trim( $name . ' ' . $publictitle . ' ' . $job . ' ' . $location . ' ' . $email . ' ' . $rid . ' ' . implode( ' ', $tags ) ) );
							$place         = preg_replace( '/[^0-9+]/', '', $location );
							$rowid       = $uid . '-r' . $i;

							$record_attr = '';
							if ( $can_edit ) {
								$record = array(
									'key'      => $key,
									'manual'   => $is_manual,
									'name'     => $name,
									'email'    => $email,
									'photo'    => $photo,
                                    'publictitle' => $publictitle,
									'job'      => $job,
									'location' => $location,
									'id'       => $rid,
									'tags'     => implode( ', ', $tags ),
									'hidden'   => $hidden ? '1' : '0',
								);
								$record_attr = ' data-key="' . esc_attr( $key ) . '" data-record="' . esc_attr( wp_json_encode( $record ) ) . '"';
							}
							?>
							<tr data-CAYDENDIR-item
								data-tags="<?php echo esc_attr( implode( '|', $terms_lower ) ); ?>"
								data-search="<?php echo esc_attr( $search_blob ); ?>"<?php echo $record_attr; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?>>
								<?php if ( $selectable ) : ?>
									<td class="CAYDENDIR-sd__cell-select" data-label="Select">
										<input type="checkbox" class="CAYDENDIR-sd__select" id="<?php echo esc_attr( $rowid ); ?>" data-CAYDENDIR-select
											aria-label="<?php echo esc_attr( sprintf( 'Select %s', '' !== $name ? $name : 'row' ) ); ?>">
									</td>
								<?php endif; ?>
								<?php if ( $show_photo || $can_edit ) : ?>
									<td class="CAYDENDIR-sd__cell-photo" data-CAYDENDIR-photo-wrap><?php echo CAYDENDIR_sd_photo_markup( $name, $photo ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<?php endif; ?>
								<th scope="row" class="CAYDENDIR-sd__cell-name">
									<span data-CAYDENDIR-field="name"><?php echo esc_html( $name ); ?></span>
									<?php if ( $can_edit ) : ?>
										<span class="CAYDENDIR-sd__badge" data-CAYDENDIR-manual-badge<?php echo $is_manual ? '' : ' hidden'; ?>>Edited</span>
										<span class="CAYDENDIR-sd__badge" data-CAYDENDIR-hidden-badge<?php echo $hidden ? '' : ' hidden'; ?>>Hidden</span>
									<?php endif; ?>
								</th>
								<?php if ( $show_publictitle || $can_edit ) : ?>
									<td data-label="publictitle"><span data-CAYDENDIR-field="publictitle"><?php echo '' !== $publictitle ? esc_html( $publictitle ) : '&mdash;'; ?></span></td>
								<?php endif; ?>
								<?php if ( $show_job || $can_edit ) : ?>
									<td data-label="Job"><span data-CAYDENDIR-field="job"><?php echo '' !== $job ? esc_html( $job ) : '&mdash;'; ?></span></td>
								<?php endif; ?>
								<?php if ( $show_location ) : ?>
									<td class="CAYDENDIR-sd__location" data-label="Location"><span data-CAYDENDIR-field="location"><?php echo '' !== $location ? esc_html( $location ) : '&mdash;'; ?></span></td>
								<?php endif; ?>
								<?php if ( $show_email || $can_edit) : ?>
									<td data-label="Email">
										<span data-CAYDENDIR-email-wrap data-dash="1"><?php if ( '' !== $email ) : ?><a class="CAYDENDIR-sd__email" href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a><?php else : ?>&mdash;<?php endif; ?></span>
									</td>
								<?php endif; ?>
								<?php if ( $can_edit ) : ?>
									<td class="CAYDENDIR-sd__cell-edit">
										<button type="button" class="CAYDENDIR-sd__edit-btn" data-CAYDENDIR-edit aria-haspopup="dialog">Edit<span class="CAYDENDIR-sd__sr" data-CAYDENDIR-edit-name> <?php echo esc_html( '' !== $name ? $name : $email ); ?></span></button>
									</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		else : // cards
			?>
			<ul class="CAYDENDIR-sd__list" data-CAYDENDIR-list>
				<?php
				foreach ( $data as $row ) :
					$name  = isset( $row['name'] ) ? $row['name'] : '';
					$email = isset( $row['email'] ) ? $row['email'] : '';
					$location = isset( $row['location'] ) ? $row['location'] : '';
					$job  = isset( $row['job'] ) ? $row['job'] : '';
    				$publictitle = isset($row['publictitle']) ? $row['publictitle'] : '';
					$tags  = ( isset( $row['tags'] ) && is_array( $row['tags'] ) ) ? $row['tags'] : array();
					$photo = isset( $row['photo'] ) ? $row['photo'] : '';
					$rid   = isset( $row['id'] ) ? $row['id'] : '';
					$hidden    = ( isset( $row['hidden'] ) && '1' === (string) $row['hidden'] );
					$is_manual = ! empty( $row['_manual'] );
					$key       = isset( $row['_key'] ) ? $row['_key'] : CAYDENDIR_sd_row_key( $row );

					$filter_terms = $tags;
					if ( '' !== $job ) {
						$filter_terms[] = $job;
					}
					$terms_lower = array_map( 'strtolower', $filter_terms );
					$search_blob = strtolower( trim( $name . ' ' . $publictitle . ' ' . $job . ' ' . $location . ' ' . $email . ' ' . $rid . ' ' . implode( ' ', $tags ) ) );
					$place         = preg_replace( '/[^0-9+]/', '', $location );

					$record_attr = '';
					if ( $can_edit ) {
						$record = array(
							'key'      => $key,
							'manual'   => $is_manual,
							'name'     => $name,
							'email'    => $email,
							'photo'    => $photo,
                            'publictitle' => $publictitle,
							'job'      => $job,
							'location' => $location,
							'id'       => $rid,
							'tags'     => implode( ', ', $tags ),
							'hidden'   => $hidden ? '1' : '0',
						);
						$record_attr = ' data-key="' . esc_attr( $key ) . '" data-record="' . esc_attr( wp_json_encode( $record ) ) . '"';
					}
					?>
					<li class="CAYDENDIR-sd__card" data-CAYDENDIR-item data-tags="<?php echo esc_attr( implode( '|', $terms_lower ) ); ?>" data-search="<?php echo esc_attr( $search_blob ); ?>"<?php echo $record_attr; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?>>
						<?php if ( $show_photo || $can_edit ) : ?>
							<span class="CAYDENDIR-sd__photo-wrap" data-CAYDENDIR-photo-wrap><?php echo CAYDENDIR_sd_photo_markup( $name, $photo ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<?php endif; ?>
						<div class="CAYDENDIR-sd__body">
							<h3 class="CAYDENDIR-sd__name" data-CAYDENDIR-wrap="name"<?php echo '' !== $name ? '' : ' hidden'; ?>>
								<span data-CAYDENDIR-field="name"><?php echo esc_html( $name ); ?></span>
								<?php if ( $can_edit ) : ?>
									<span class="CAYDENDIR-sd__badge" data-CAYDENDIR-manual-badge<?php echo $is_manual ? '' : ' hidden'; ?>>Edited</span>
									<span class="CAYDENDIR-sd__badge" data-CAYDENDIR-hidden-badge<?php echo $hidden ? '' : ' hidden'; ?>>Hidden</span>
								<?php endif; ?>
							</h3>
							<?php if ( $show_publictitle || $can_edit ) : ?>
								<p class="CAYDENDIR-sd__job" data-CAYDENDIR-wrap="publictitle"<?php echo '' !== $publictitle ? '' : ' hidden'; ?>><span data-CAYDENDIR-field="publictitle"><?php echo esc_html( $publictitle ); ?></span></p>
							<?php endif; ?>
							<?php if ( $show_job || $can_edit) : ?>
								<p class="CAYDENDIR-sd__job" data-CAYDENDIR-wrap="job"<?php echo '' !== $job ? '' : ' hidden'; ?>><span data-CAYDENDIR-field="job"><?php echo esc_html( $job ); ?></span></p>
							<?php endif; ?>
							<?php if ( $show_location || $can_edit) : ?>
								<p class="CAYDENDIR-sd__location" data-CAYDENDIR-wrap="location"<?php echo '' !== $location ? '' : ' hidden'; ?>><span data-CAYDENDIR-field="location"><?php echo esc_html( $location ); ?></span></p>
							<?php endif; ?>
							<?php if ( $show_email || $can_edit) : ?>
								<span data-CAYDENDIR-email-wrap><?php if ( '' !== $email ) : ?><a class="CAYDENDIR-sd__email" href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a><?php endif; ?></span>
							<?php endif; ?>
							<ul class="CAYDENDIR-sd__tags" aria-label="Tags" data-CAYDENDIR-tags<?php echo ! empty( $tags ) ? '' : ' hidden'; ?>>
								<?php foreach ( $tags as $t ) : ?>
									<li class="CAYDENDIR-sd__tag"><?php echo esc_html( $t ); ?></li>
								<?php endforeach; ?>
							</ul>
							<?php if ( $can_edit ) : ?>
								<p class="CAYDENDIR-sd__card-actions">
									<button type="button" class="CAYDENDIR-sd__edit-btn" data-CAYDENDIR-edit aria-haspopup="dialog">Edit<span class="CAYDENDIR-sd__sr" data-CAYDENDIR-edit-name> <?php echo esc_html( '' !== $name ? $name : $email ); ?></span></button>
								</p>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
		endif;

		// ---- edit dialog (only rendered for users who can edit) ----
		if ( $can_edit && ! empty( $data ) ) :
			?>
			<p class="CAYDENDIR-sd__sr" aria-live="polite" data-CAYDENDIR-announce></p>
			<div class="CAYDENDIR-sd__overlay" data-CAYDENDIR-modal hidden>
				<div class="CAYDENDIR-sd__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $uid ); ?>-dlg-title" data-CAYDENDIR-dialog>
					<h3 class="CAYDENDIR-sd__dialog-title" id="<?php echo esc_attr( $uid ); ?>-dlg-title">Edit directory entry</h3>
					<p class="CAYDENDIR-sd__dialog-note">Saving stores this person as a <strong>manual override</strong>. The automatic sync can never change or remove it. Use &ldquo;Remove manual override&rdquo; to go back to the synced data.</p>
					<form data-CAYDENDIR-edit-form novalidate>
						<input type="hidden" data-CAYDENDIR-f="key">
						<p class="CAYDENDIR-sd__field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-name">Name</label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-name" data-CAYDENDIR-f="name" autocomplete="off">
						</p>
						<p class="CAYDENDIR-sd__field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-email">Email</label>
							<input type="email" id="<?php echo esc_attr( $uid ); ?>-f-email" data-CAYDENDIR-f="email" autocomplete="off" spellcheck="false">
						</p>
						<p class="CAYDENDIR-sd__field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-publictitle">Title</label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-publictitle" data-CAYDENDIR-f="publictitle" autocomplete="off" spellcheck="false" aria-describedby="<?php echo esc_attr( $uid ); ?>-f-publictitle-hint">
							<span class="CAYDENDIR-sd__hint" id="<?php echo esc_attr( $uid ); ?>-f-publictitle-hint"><strong>This is the Part that is Displayed Its the Job Title for this person For example Secondary Education Director.</strong></span>
						</p>
						<p class="CAYDENDIR-sd__field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-job"  style="color: red;">Job</label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-job" data-CAYDENDIR-f="job" autocomplete="off" spellcheck="false" aria-describedby="<?php echo esc_attr( $uid ); ?>-f-job-hint">
							<span class="CAYDENDIR-sd__hint" id="<?php echo esc_attr( $uid ); ?>-f-job-hint" style="color: red;"><strong>READ BEFORE CHANGING! This is Not Displayed By Default unless Configured To in Settings, It is More of the HR Job Title So for Example like Director. This is Case And Spelling Sensitive It Controls the Main Sorting in the List To Change sorting order see the Settings Menu! CHANGE TITLE To Change What Is Displayed In the List</strong></span>
						</p>
						<p class="CAYDENDIR-sd__field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-location">Location</label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-location" data-CAYDENDIR-f="location" autocomplete="off">
						</p>
						<p class="CAYDENDIR-sd__field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-photo">Photo</label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-photo" data-CAYDENDIR-f="photo" autocomplete="off" spellcheck="false" aria-describedby="<?php echo esc_attr( $uid ); ?>-f-photo-hint">
							<span class="CAYDENDIR-sd__hint" id="<?php echo esc_attr( $uid ); ?>-f-photo-hint">Google Drive file ID, a Drive link, or an lh3.googleusercontent URL. Leave blank to show initials.</span>
						</p>
						<p class="CAYDENDIR-sd__field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-tags">Tags</label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-tags" data-CAYDENDIR-f="tags" autocomplete="off" aria-describedby="<?php echo esc_attr( $uid ); ?>-f-tags-hint">
							<span class="CAYDENDIR-sd__hint" id="<?php echo esc_attr( $uid ); ?>-f-tags-hint">Separate tags with commas.</span>
						</p>
						<p class="CAYDENDIR-sd__field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-id">Identification (ID)</label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-id" data-CAYDENDIR-f="id" autocomplete="off" spellcheck="false" aria-describedby="<?php echo esc_attr( $uid ); ?>-f-id-hint">
							<span class="CAYDENDIR-sd__hint" id="<?php echo esc_attr( $uid ); ?>-f-id-hint">You can change the stored ID. The override keeps matching this row&rsquo;s original identity (shown below), so the sync still cannot overwrite it.</span>
						</p>
						<p class="CAYDENDIR-sd__field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-hidden">
								<input type="checkbox" id="<?php echo esc_attr( $uid ); ?>-f-hidden" data-CAYDENDIR-f="hidden" value="1">
								Hide from directory
							</label>
							<span class="CAYDENDIR-sd__hint">Hidden people are invisible to the public but still shown to editors here, marked &ldquo;Hidden&rdquo;, so you can un-hide them later.</span>
						</p>
						<p class="CAYDENDIR-sd__hint" data-CAYDENDIR-keyline></p>
						<p class="CAYDENDIR-sd__dialog-status" role="status" aria-live="polite" data-CAYDENDIR-edit-status></p>
						<div class="CAYDENDIR-sd__dialog-actions">
							<button type="submit" class="CAYDENDIR-sd__btn CAYDENDIR-sd__btn--primary" data-CAYDENDIR-save>Save changes</button>
							<button type="button" class="CAYDENDIR-sd__btn" data-CAYDENDIR-cancel>Cancel</button>
							<button type="button" class="CAYDENDIR-sd__btn CAYDENDIR-sd__btn--danger" data-CAYDENDIR-remove hidden>Remove manual override</button>
						</div>
					</form>
				</div>
			</div>
			<?php
		endif;
		?>
	</section>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'CAYDENDIR_sd_admin_menu' );
function CAYDENDIR_sd_admin_menu() {
	add_options_page( 'CAYDENDIR Staff Directory', 'Staff Directory', 'manage_options', 'CAYDENDIR-staff-directory', 'CAYDENDIR_sd_settings_page' );
}

add_action( 'admin_enqueue_scripts', 'CAYDENDIR_sd_admin_assets' );
function CAYDENDIR_sd_admin_assets( $hook ) {
	if ( 'settings_page_CAYDENDIR-staff-directory' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );
	wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".CAYDENDIR-color-field").wpColorPicker();});' );
}

add_action( 'admin_init', 'CAYDENDIR_sd_register_settings' );
function CAYDENDIR_sd_register_settings() {
	register_setting( 'CAYDENDIR_sd_group', CAYDENDIR_SD_SETTINGS, 'CAYDENDIR_sd_sanitize_settings' );
}

function CAYDENDIR_sd_sanitize_settings( $input ) {
	$out = CAYDENDIR_sd_get_settings();
	$in  = is_array( $input ) ? $input : array();

	$out['gas_url']        = esc_url_raw( trim( isset( $in['gas_url'] ) ? $in['gas_url'] : '' ) );
	$out['secret_key']     = trim( (string) ( isset( $in['secret_key'] ) ? $in['secret_key'] : '' ) );
	$out['place_code']     = substr( strtoupper( preg_replace( '/[^A-Za-z]/', '', isset( $in['place_code'] ) ? $in['place_code'] : 'AA' ) ), 0, 2 );
	$out['signifier']      = sanitize_text_field( isset( $in['signifier'] ) ? $in['signifier'] : '' );
	$out['placement_code'] = substr( strtoupper( preg_replace( '/[^A-Za-z]/', '', isset( $in['placement_code'] ) ? $in['placement_code'] : 'FL' ) ), 0, 2 );
	$out['accessor_key']   = preg_replace( '/[^A-Za-z0-9]/', '', isset( $in['accessor_key'] ) ? $in['accessor_key'] : '' );

	$letter            = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', isset( $in['col_letter'] ) ? $in['col_letter'] : 'F' ), 0, 1 ) );
	$out['col_letter'] = '' !== $letter ? $letter : 'F';

	if ( '' === $out['place_code'] )     { $out['place_code'] = 'AA'; }
	if ( '' === $out['placement_code'] ) { $out['placement_code'] = 'FL'; }

	// Display
	$out['layout'] = ( isset( $in['layout'] ) && 'cards' === $in['layout'] ) ? 'cards' : 'table';

	$allowed_cols = array( 'photo', 'job', 'publictitle', 'location', 'email' );
	$picked       = isset( $in['columns'] ) && is_array( $in['columns'] ) ? $in['columns'] : array();
	$out['columns'] = array_values( array_intersect( $allowed_cols, $picked ) );

	$out['hover']      = ( ! empty( $in['hover'] ) ) ? '1' : '0';
	$out['selectable'] = ( ! empty( $in['selectable'] ) ) ? '1' : '0';

	// Sort order lists (one display label per line; blank falls back to defaults)
	$job_order = isset( $in['job_order'] ) ? sanitize_textarea_field( $in['job_order'] ) : $out['job_order'];
	$out['job_order'] = '' !== trim( $job_order ) ? $job_order : CAYDENDIR_sd_default_job_order();

	$location_order = isset( $in['location_order'] ) ? sanitize_textarea_field( $in['location_order'] ) : $out['location_order'];
	$out['location_order'] = '' !== trim( $location_order ) ? $location_order : CAYDENDIR_sd_default_location_order();

	// Custom CSS — the front-end stylesheet. Only administrators (manage_options)
	// can reach this form, so the CSS is stored as-is rather than run through
	// text sanitisers that would mangle quotes, braces and CSS syntax. Null
	// bytes are stripped, and a blank box restores the built-in default. The
	// "</style" break-out guard is applied on output in CAYDENDIR_sd_get_css().
	if ( isset( $in['custom_css'] ) ) {
		$css = str_replace( "\0", '', (string) $in['custom_css'] );
		$out['custom_css'] = ( '' !== trim( $css ) ) ? $css : CAYDENDIR_sd_default_css();
	}

	// Colors
	$defaults_colors = CAYDENDIR_sd_default_colors();
	$colors_in       = isset( $in['colors'] ) && is_array( $in['colors'] ) ? $in['colors'] : array();
	$colors          = array();
	foreach ( $defaults_colors as $key => $default ) {
		$val           = isset( $colors_in[ $key ] ) ? sanitize_hex_color( $colors_in[ $key ] ) : '';
		$colors[ $key ] = $val ? $val : $default;
	}
	$out['colors'] = $colors;

	return $out;
}

function CAYDENDIR_sd_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['CAYDENDIR_sd_sync_now'] ) && check_admin_referer( 'CAYDENDIR_sd_sync_now_action', 'CAYDENDIR_sd_sync_now_nonce' ) ) {
		$result = CAYDENDIR_sd_sync();
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'CAYDENDIR_sd', 'sync_failed', 'Sync failed: ' . esc_html( $result->get_error_message() ), 'error' );
		} else {
			add_settings_error( 'CAYDENDIR_sd', 'sync_ok', sprintf( 'Sync complete — %d records cached. Manual overrides were not touched.', count( $result ) ), 'updated' );
		}
	}

	// Remove a manual override from the admin list.
	if ( isset( $_POST['CAYDENDIR_sd_del_manual'] ) && check_admin_referer( 'CAYDENDIR_sd_del_manual_action', 'CAYDENDIR_sd_del_manual_nonce' ) ) {
		$del_key = ( isset( $_POST['CAYDENDIR_sd_manual_key'] ) && is_string( $_POST['CAYDENDIR_sd_manual_key'] ) )
			? sanitize_text_field( wp_unslash( $_POST['CAYDENDIR_sd_manual_key'] ) )
			: '';
		$manual_list = CAYDENDIR_sd_get_manual_data();
		if ( '' !== $del_key && isset( $manual_list[ $del_key ] ) ) {
			unset( $manual_list[ $del_key ] );
			update_option( CAYDENDIR_SD_MANUAL_OPTION, $manual_list, false );
			CAYDENDIR_sd_purge_caches();
			add_settings_error( 'CAYDENDIR_sd', 'manual_removed', 'Manual override removed — that row will show synced data again.', 'updated' );
		}
	}

	$s      = CAYDENDIR_sd_get_settings();
	$meta   = get_option( CAYDENDIR_SD_META_OPTION, array() );
	$id     = CAYDENDIR_sd_build_id( $s );
	$next   = wp_next_scheduled( CAYDENDIR_SD_CRON_HOOK );
	$opt    = CAYDENDIR_SD_SETTINGS;
	$manual = CAYDENDIR_sd_get_manual_data();

	$col_labels = array(
		'photo' => 'Photo',
        'publictitle' => 'Public Title',
		'job'  => 'Job',
		'location' => 'Location',
		'email' => 'Email',
	);
	$color_labels = array(
		'accent'    => 'Accent (links, active chip)',
		'accent_fg' => 'Accent text (on accent)',
		'text'      => 'Body text',
		'muted'     => 'Muted text',
		'border'    => 'Borders / lines',
		'row_bg'    => 'Row background',
		'row_alt'   => 'Alternate row background',
		'selected'  => 'Selected / hovered row',
		'header_bg' => 'Header row background',
	);

	settings_errors( 'CAYDENDIR_sd' );
	?>
	<div class="wrap">
		<h1>CAYDENDIR Staff Directory</h1>

		<form method="post" action="options.php">
			<?php settings_fields( 'CAYDENDIR_sd_group' ); ?>

			<h2>Connection &amp; handshake</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="CAYDENDIR_gas_url">Apps Script Web App URL</label></th>
					<td><input name="<?php echo esc_attr( $opt ); ?>[gas_url]" id="CAYDENDIR_gas_url" type="url" class="regular-text code" value="<?php echo esc_attr( $s['gas_url'] ); ?>" placeholder="https://script.google.com/macros/s/…/exec"></td>
				</tr>
				<tr>
					<th scope="row"><label for="CAYDENDIR_secret">Encrypter Key</label></th>
					<td><input name="<?php echo esc_attr( $opt ); ?>[secret_key]" id="CAYDENDIR_secret" type="text" class="regular-text" value="<?php echo esc_attr( $s['secret_key'] ); ?>" autocomplete="new-password">
					<p class="description">The key that Encrypts the other Valuesto send to the AppScript.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="CAYDENDIR_place">Dialing Out Location</label></th>
					<td><input name="<?php echo esc_attr( $opt ); ?>[place_code]" id="CAYDENDIR_place" type="text" maxlength="2" value="<?php echo esc_attr( $s['place_code'] ); ?>"> <span class="description">2 Letter Id of the place dialing to the web App, For wordpress its WP.</span></td>
				</tr>
				<tr>
					<th scope="row"><label for="CAYDENDIR_sig">Text Name</label></th>
					<td><input name="<?php echo esc_attr( $opt ); ?>[signifier]" id="CAYDENDIR_sig" type="text" class="regular-text" value="<?php echo esc_attr( $s['signifier'] ); ?>"> <p class="description">Short Phrase to be Hashed, sense staff directory was the first thing made its the tauri</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="CAYDENDIR_placement">Plugin Code</label></th>
					<td><input name="<?php echo esc_attr( $opt ); ?>[placement_code]" id="CAYDENDIR_placement" type="text" maxlength="2" value="<?php echo esc_attr( $s['placement_code'] ); ?>"> <span class="description">2 letters Identifing the Plugin Calling so for Staff Directory its SD.</span></td>
				</tr>
				<tr>
					<th scope="row"><label for="CAYDENDIR_accessor">Gate Address</label></th>
					<td><input name="<?php echo esc_attr( $opt ); ?>[accessor_key]" id="CAYDENDIR_accessor" type="text" class="regular-text" value="<?php echo esc_attr( $s['accessor_key'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="CAYDENDIR_col">Location</label></th>
					<td><input name="<?php echo esc_attr( $opt ); ?>[col_letter]" id="CAYDENDIR_col" type="text" maxlength="1" value="<?php echo esc_attr( $s['col_letter'] ); ?>"> <span class="description">A–Z &rarr; The location of the plugin where its used, &#10003;, Identification, Tags — use <code>E</code> so the Identification numbers (used to match manual edits) and Tags are included in the sync. <code>F</code> stops before the Identification column.</span></td>
				</tr>
			</table>

			<h2>Layout &amp; columns</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Layout</th>
					<td>
						<fieldset>
							<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[layout]" value="table" <?php checked( 'table', $s['layout'] ); ?>> Table (rows)</label><br>
							<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[layout]" value="cards" <?php checked( 'cards', $s['layout'] ); ?>> Cards (grid)</label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row">Columns to show</th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">Columns to show</legend>
							<?php foreach ( $col_labels as $key => $label ) : ?>
								<label style="display:inline-block;margin-right:1em;">
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[columns][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $s['columns'], true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description">Name is always shown.</p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row">Row behaviour</th>
					<td>
						<fieldset>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[hover]" value="1" <?php checked( '1', $s['hover'] ); ?>> Highlight rows on hover/focus</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[selectable]" value="1" <?php checked( '1', $s['selectable'] ); ?>> Selectable rows (adds a checkbox; click a row to select it)</label>
						</fieldset>
					</td>
				</tr>
			</table>

			<h2>Sort order</h2>
			<p class="description">The directory sorts by Job priority first, then Location priority — same idea as the spreadsheet, but applied in WordPress so manually edited people land in the right place too. One label per line, highest priority first. Anything not listed sinks to the bottom (a handy flag for typos). Labels must match the displayed Job/Location text (case doesn&rsquo;t matter).</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="CAYDENDIR_job_order">Job order</label></th>
					<td><textarea id="CAYDENDIR_job_order" name="<?php echo esc_attr( $opt ); ?>[job_order]" rows="10" class="large-text code"><?php echo esc_textarea( $s['job_order'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="CAYDENDIR_location_order">Location order</label></th>
					<td><textarea id="CAYDENDIR_location_order" name="<?php echo esc_attr( $opt ); ?>[location_order]" rows="10" class="large-text code"><?php echo esc_textarea( $s['location_order'] ); ?></textarea></td>
				</tr>
			</table>

			<h2>Colours</h2>
			<table class="form-table" role="presentation">
				<?php foreach ( $color_labels as $key => $label ) : ?>
					<tr>
						<th scope="row"><label for="CAYDENDIR_color_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input type="text" id="CAYDENDIR_color_<?php echo esc_attr( $key ); ?>" class="CAYDENDIR-color-field"
							name="<?php echo esc_attr( $opt ); ?>[colors][<?php echo esc_attr( $key ); ?>]"
							value="<?php echo esc_attr( $s['colors'][ $key ] ); ?>"
							data-default-color="<?php echo esc_attr( $s['colors'][ $key ] ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p class="description">Keep the accent dark enough against its text colour for at least 4.5:1 contrast (WCAG AA).</p>

			<h2>Custom CSS</h2>
			<p class="description">This box <strong>is</strong> the stylesheet the directory uses on the front end — everything the directory looks like comes from here. Edit it to restyle the directory without touching any plugin files. It is pre-filled with the built-in default styles; <strong>leave it blank and save to restore those defaults</strong>. The colour pickers above still work: they set the <code>--CAYDENDIR-*</code> variables inline on each directory, so they override the matching rules here. Only administrators can edit this.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="CAYDENDIR_custom_css">Directory CSS</label></th>
					<td><textarea id="CAYDENDIR_custom_css" name="<?php echo esc_attr( $opt ); ?>[custom_css]" rows="28" class="large-text code" spellcheck="false" style="white-space:pre;font-family:Menlo,Consolas,monospace;"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
					<p class="description">Applied inside a single inline <code>&lt;style&gt;</code> tag on any page that shows the directory.</p></td>
				</tr>
			</table>

			<?php submit_button( 'Save settings' ); ?>
		</form>

		<hr>
		<h2>Composed handshake ID</h2>
		<p><code style="font-size:14px;"><?php echo esc_html( $id ); ?></code></p>

		<h2>Sync</h2>
		<p>
			<?php if ( ! empty( $meta['time'] ) ) : ?>
				Last sync: <strong><?php echo esc_html( human_time_diff( (int) $meta['time'] ) . ' ago' ); ?></strong>
				— <?php echo esc_html( ! empty( $meta['ok'] ) ? 'success' : 'failed' ); ?>
				<?php if ( ! empty( $meta['message'] ) ) : ?>(<?php echo esc_html( $meta['message'] ); ?>)<?php endif; ?>
			<?php else : ?>
				Never synced.
			<?php endif; ?>
		</p>
		<?php if ( $next ) : ?>
			<p>Next automatic sync: <strong><?php echo esc_html( wp_date( 'Y-m-d H:i', $next ) ); ?></strong></p>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'CAYDENDIR_sd_sync_now_action', 'CAYDENDIR_sd_sync_now_nonce' ); ?>
			<?php submit_button( 'Sync now', 'secondary', 'CAYDENDIR_sd_sync_now', false ); ?>
		</form>

		<hr>
		<h2>Manual overrides (<?php echo (int) count( $manual ); ?>)</h2>
		<p class="description">These entries were edited by hand (via the Edit buttons on the public directory). They are persistent: the automatic sync never changes or removes them, and they always overrule the synced data. Removing an override here lets the synced data show again for that row.</p>
		<?php if ( empty( $manual ) ) : ?>
			<p>No manual overrides yet. Log in and use the <strong>Edit</strong> button on a directory row to create one.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th scope="col">Name</th>
						<th scope="col">Email</th>
						<th scope="col">ID</th>
						<th scope="col">Hidden</th>
						<th scope="col">Match key</th>
						<th scope="col">Updated</th>
						<th scope="col"><span class="screen-reader-text">Actions</span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $manual as $mkey => $mrow ) : ?>
						<?php $mrow = CAYDENDIR_sd_normalize_record( $mrow ); ?>
						<tr>
							<td><?php echo esc_html( $mrow['name'] ); ?></td>
							<td><?php echo esc_html( $mrow['email'] ); ?></td>
							<td><?php echo esc_html( $mrow['id'] ); ?></td>
							<td><?php echo ( '1' === (string) $mrow['hidden'] ) ? '<strong>Yes</strong>' : 'No'; ?></td>
							<td><code><?php echo esc_html( (string) $mkey ); ?></code></td>
							<td>
								<?php
								if ( isset( $mrow['_updated'] ) && is_numeric( $mrow['_updated'] ) ) {
									echo esc_html( human_time_diff( (int) $mrow['_updated'] ) . ' ago' );
								} else {
									echo '&mdash;';
								}
								?>
							</td>
							<td>
								<form method="post" style="margin:0;">
									<?php wp_nonce_field( 'CAYDENDIR_sd_del_manual_action', 'CAYDENDIR_sd_del_manual_nonce' ); ?>
									<input type="hidden" name="CAYDENDIR_sd_manual_key" value="<?php echo esc_attr( (string) $mkey ); ?>">
									<?php submit_button( 'Remove override', 'small', 'CAYDENDIR_sd_del_manual', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<hr>
		<h2>Usage</h2>
        <p>Editing the Naming Schemes for Jobs and Department Names can be done here https://script.google.com/a/acpsmd.org/macros/s/AKfycbwnGz3D1Nxepbh2lA0bcpv7XGyiyuEMczvG_NiQAhpsSmMgO6XUft2TBwo-phDAYhN5Qw/exec?id=SS-64344-AL-1921216158201-P </p>
		<p>Add to a page or a Beaver Builder <em>HTML</em> module:</p>
		<p><code>[CAYDENDIR_staff_directory heading="Search People"]</code></p>
		<p class="description">Optional <code>layout="cards"</code> or <code>layout="table"</code> overrides the setting per placement. <code>match="all"</code> requires every selected tag.</p>
		<p class="description">Logged-in administrators see an <strong>Edit</strong> button on every row of the public directory. Edits are saved as manual overrides (see above), can hide a person from the public, and re-sort into place automatically. Change who may edit with the <code>CAYDENDIR_sd_edit_cap</code> filter.</p>
	</div>
	<?php
}
