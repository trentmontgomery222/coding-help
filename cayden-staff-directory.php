<?php
/**
 * Plugin Name:       Cayden  Staff Directory
 * Description:       Staff Directory system for the website
 * Version:           2.7.1
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

define( 'CAYDENDIR_SD_VERSION',       '2.7.1' );
define( 'CAYDENDIR_SD_DIR',           plugin_dir_path( __FILE__ ) );
define( 'CAYDENDIR_SD_URL',           plugin_dir_url( __FILE__ ) );
define( 'CAYDENDIR_SD_CRON_HOOK',     'CAYDENDIR_sd_daily_sync' );
define( 'CAYDENDIR_SD_DATA_OPTION',   'CAYDENDIR_sd_directory_data' );
define( 'CAYDENDIR_SD_MANUAL_OPTION', 'CAYDENDIR_sd_manual_data' );
define( 'CAYDENDIR_SD_META_OPTION',   'CAYDENDIR_sd_sync_meta' );
define( 'CAYDENDIR_SD_SETTINGS',      'CAYDENDIR_sd_settings' );

/**
 * Shared Beaver Builder category for all Cayden plugins. Modules stay in the
 * default (Standard) module group, but any Cayden plugin that registers its
 * Beaver Builder module with this exact category string appears together under
 * the "Caydens Plugins" category heading in the content panel. Keep it a plain,
 * untranslated string so the category stays consistent across plugins and site
 * locales.
 */
if ( ! defined( 'CAYDENDIR_BB_CATEGORY' ) ) {
	define( 'CAYDENDIR_BB_CATEGORY', 'Caydens Plugins' );
}

/**
 * Write a plugin error to the PHP error log without ever throwing itself.
 *
 * Used by the try/catch safety nets around every WordPress entry point
 * (shortcode, Beaver Builder module, AJAX, sync, admin pages). The goal is
 * that a bug in this plugin degrades gracefully — an empty spot, a logged
 * message — instead of taking down the whole site with a fatal error.
 *
 * @param string     $where Short label for where the error happened.
 * @param \Throwable $e     The caught error or exception.
 */
function CAYDENDIR_sd_log( $where, $e ) {
	if ( ! function_exists( 'error_log' ) ) {
		return;
	}
	$msg = is_object( $e ) && method_exists( $e, 'getMessage' ) ? $e->getMessage() : (string) $e;
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( sprintf( '[Cayden Staff Directory] %s: %s', $where, $msg ) );
}



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
		'sort_rules'     => CAYDENDIR_sd_default_sort_rules(),
		'handshake_id'   => '',
		'column_templates' => CAYDENDIR_sd_default_column_templates(),
	);
	$saved = get_option( CAYDENDIR_SD_SETTINGS, array() );
	$saved = is_array( $saved ) ? $saved : array();
	$out   = wp_parse_args( $saved, $defaults );
	$out['colors'] = wp_parse_args( is_array( $out['colors'] ) ? $out['colors'] : array(), CAYDENDIR_sd_default_colors() );
	if ( ! is_array( $out['columns'] ) ) {
		$out['columns'] = $defaults['columns'];
	}

	// Sort rules: older versions only stored job_order/location_order. If this
	// site was never saved with the new sort_rules, seed them from those two
	// lists so the ordering is unchanged after upgrading (any custom order the
	// site had is preserved).
	if ( ! array_key_exists( 'sort_rules', $saved ) ) {
		$out['sort_rules'] = array(
			array( 'field' => 'job',      'mode' => 'priority', 'order' => $out['job_order'] ),
			array( 'field' => 'location', 'mode' => 'priority', 'order' => $out['location_order'] ),
		);
	}
	if ( ! is_array( $out['sort_rules'] ) ) {
		$out['sort_rules'] = array();
	}
	$out['sort_rules'] = array_values( array_map( 'CAYDENDIR_sd_normalize_sort_rule', $out['sort_rules'] ) );

	// Handshake ID: older versions compiled it from five separate fields. If
	// this site has no single handshake_id yet, seed it from those legacy
	// fields so the request keeps working unchanged after upgrading.
	if ( ! array_key_exists( 'handshake_id', $saved ) || '' === trim( (string) $out['handshake_id'] ) ) {
		$out['handshake_id'] = CAYDENDIR_sd_compose_legacy_id( $out );
	}

	if ( ! is_array( $out['column_templates'] ) ) {
		$out['column_templates'] = CAYDENDIR_sd_default_column_templates();
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
	try {
		$s   = CAYDENDIR_sd_get_settings();
		$css = isset( $s['custom_css'] ) ? (string) $s['custom_css'] : '';
		if ( '' === trim( $css ) ) {
			$css = CAYDENDIR_sd_default_css();
		}
		return str_ireplace( '</style', '<\/style', $css );
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'get_css', $e );
		return CAYDENDIR_sd_default_css();
	}
}

/* -------------------------------------------------------------------------
 * Column display templates
 *
 * Each visible column is rendered from a template the admin edits in
 * Settings › Staff Directory › Column display. A template is text that may
 * contain HTML plus these building blocks:
 *
 *   {field}              a field's value (HTML-escaped)
 *   {field|fallback}     the value, or "fallback" text if the field is empty
 *   [if field]…[/if]     show the block only when the field is non-empty
 *   [if field]…[else]…[/if]
 *
 * Available fields: firstname, lastname, name, publictitle, job, location,
 * email, id, tags, initials, photo_url.
 *
 * Field VALUES are always HTML-escaped, so data can never inject markup; the
 * surrounding template may use HTML (links, <strong>, icons, <span class>…)
 * and the finished result is run through wp_kses_post so only safe HTML
 * survives. Plain-text templates are tidied so a stranded separator from an
 * empty field disappears (e.g. "{lastname}, {firstname}" shows just the first
 * name when there is no last name). Defaults reproduce today's display.
 * ---------------------------------------------------------------------- */

/** Which columns are template-driven => their admin labels. */
function CAYDENDIR_sd_template_columns() {
	return array(
		'name'        => 'Name',
		'publictitle' => 'Title',
		'job'         => 'Job',
		'location'    => 'Location',
		'email'       => 'Email',
	);
}

/** Placeholder fields => a short description, for the editor help. */
function CAYDENDIR_sd_template_fields() {
	return array(
		'firstname'   => 'First name',
		'lastname'    => 'Last name',
		'name'        => 'Full name',
		'publictitle' => 'Public title',
		'job'         => 'Job (HR title)',
		'location'    => 'Location',
		'email'       => 'Email address',
		'id'          => 'Identification',
		'tags'        => 'Tags (comma list)',
		'initials'    => 'Initials',
		'photo_url'   => 'Photo URL',
	);
}

/** Default template per column — these keep today's display unchanged. */
function CAYDENDIR_sd_default_column_templates() {
	return array(
		'name'        => '{firstname} {lastname}',
		'publictitle' => '{publictitle}',
		'job'         => '{job}',
		'location'    => '{location}',
		'email'       => '[if email]<a class="CAYDENDIR-sd__email" href="mailto:{email}">{email}</a>[/if]',
	);
}

/** Saved templates merged over the defaults (blank falls back to default). */
function CAYDENDIR_sd_get_column_templates( $settings = null ) {
	if ( null === $settings ) {
		$settings = CAYDENDIR_sd_get_settings();
	}
	$saved = ( isset( $settings['column_templates'] ) && is_array( $settings['column_templates'] ) ) ? $settings['column_templates'] : array();
	$out   = array();
	foreach ( CAYDENDIR_sd_default_column_templates() as $col => $default ) {
		$val        = isset( $saved[ $col ] ) ? (string) $saved[ $col ] : '';
		$out[ $col ] = ( '' !== trim( $val ) ) ? $val : $default;
	}
	return $out;
}

/** Raw placeholder => value map for one row (values are NOT yet escaped). */
function CAYDENDIR_sd_column_placeholders( $row ) {
	$row  = CAYDENDIR_sd_normalize_record( $row );
	$tags = ( isset( $row['tags'] ) && is_array( $row['tags'] ) ) ? implode( ', ', $row['tags'] ) : '';
	return array(
		'firstname'   => (string) $row['firstname'],
		'lastname'    => (string) $row['lastname'],
		'name'        => (string) $row['name'],
		'publictitle' => (string) $row['publictitle'],
		'job'         => (string) $row['job'],
		'location'    => (string) $row['location'],
		'email'       => (string) $row['email'],
		'id'          => (string) $row['id'],
		'tags'        => $tags,
		'initials'    => CAYDENDIR_sd_initials( (string) $row['name'] ),
		'photo_url'   => CAYDENDIR_sd_photo_url( (string) $row['photo'] ),
	);
}

/**
 * Evaluate an [if …] condition against a row's raw values.
 *
 * Forms:
 *   field                 true when the field is non-empty
 *   field == value        true when the field equals value
 *   field != value        true when the field does not equal value
 *   field contains value  true when the field contains value
 *
 * Comparisons are trimmed and case-insensitive. The value may be wrapped in
 * single or double quotes (useful for values with leading/trailing spaces).
 */
function CAYDENDIR_sd_eval_condition( $cond, $vals ) {
	$cond  = trim( (string) $cond );
	$field = $cond;
	$op    = '';
	$value = '';

	if ( preg_match( '/^([a-z_]+)\s*(==|!=)\s*(.*)$/is', $cond, $c ) ) {
		$field = $c[1];
		$op    = $c[2];
		$value = $c[3];
	} elseif ( preg_match( '/^([a-z_]+)\s+contains\s+(.*)$/is', $cond, $c ) ) {
		$field = $c[1];
		$op    = 'contains';
		$value = $c[2];
	}

	$field = strtolower( trim( $field ) );
	$value = trim( $value );

	// Strip one layer of surrounding quotes from the comparison value.
	if ( strlen( $value ) >= 2 ) {
		$q = $value[0];
		if ( ( '"' === $q || "'" === $q ) && substr( $value, -1 ) === $q ) {
			$value = substr( $value, 1, -1 );
		}
	}

	$fieldval = isset( $vals[ $field ] ) ? trim( (string) $vals[ $field ] ) : '';

	switch ( $op ) {
		case '==':
			return 0 === strcasecmp( $fieldval, $value );
		case '!=':
			return 0 !== strcasecmp( $fieldval, $value );
		case 'contains':
			return '' !== $value && false !== stripos( $fieldval, $value );
		default:
			return '' !== $fieldval;
	}
}

/**
 * Render a template for a row into safe HTML.
 *
 * Steps: resolve [if]/[else] blocks against the raw values, substitute
 * {field} and {field|fallback} with HTML-escaped values, tidy plain-text
 * output, then wp_kses_post the whole thing so only safe HTML remains.
 */
function CAYDENDIR_sd_apply_template( $template, $row ) {
	$vals     = CAYDENDIR_sd_column_placeholders( $row );
	$template = (string) $template;

	// ---- 1. Conditionals: [if CONDITION]…[else]…[/if] (innermost first).
	// CONDITION is "field" (non-empty), "field == value", "field != value" or
	// "field contains value" — comparisons are trimmed and case-insensitive. ----
	$re    = '/\[if\s+([^\]]+?)\]((?:(?!\[if\s|\[\/if\]).)*?)\[\/if\]/is';
	$guard = 0;
	while ( preg_match( $re, $template ) && $guard++ < 50 ) {
		$template = preg_replace_callback(
			$re,
			function ( $m ) use ( $vals ) {
				$has   = CAYDENDIR_sd_eval_condition( $m[1], $vals );
				$parts = preg_split( '/\[else\]/i', $m[2], 2 );
				$yes   = isset( $parts[0] ) ? $parts[0] : '';
				$no    = isset( $parts[1] ) ? $parts[1] : '';
				return $has ? $yes : $no;
			},
			$template
		);
	}

	// ---- 2. {field|fallback} and {field} substitution (values escaped). ----
	$out = preg_replace_callback(
		'/\{([a-z_]+)(?:\|([^{}]*))?\}/',
		function ( $m ) use ( $vals ) {
			$field    = $m[1];
			$fallback = isset( $m[2] ) ? $m[2] : '';
			$value    = isset( $vals[ $field ] ) ? (string) $vals[ $field ] : '';
			if ( '' === trim( $value ) ) {
				return esc_html( $fallback );
			}
			return esc_html( $value );
		},
		$template
	);

	// ---- 3. Tidy. HTML templates are left alone apart from trimming ends;
	// plain-text templates get whitespace/separator clean-up so an empty
	// field never leaves a dangling comma or double space. ----
	if ( false === strpos( $out, '<' ) ) {
		$out = preg_replace( '/\s+/', ' ', $out );
		$out = preg_replace( '/\s*([,|·•])\s*\1\s*/', '$1 ', $out );
		$out = trim( $out );
		$out = trim( $out, " \t,|·•-" );
	}
	$out = trim( $out );

	// ---- 4. Safety net: allow only post-safe HTML (strips scripts, event
	// handlers and other unsafe markup via wp_kses_post). ----
	return wp_kses_post( $out );
}

/**
 * The HTML to display for a column of a row, using its template. Returns a
 * safe HTML string (may be empty). Never throws — on any error it falls back
 * to the escaped raw field value.
 */
function CAYDENDIR_sd_column_display( $col, $row, $settings = null ) {
	try {
		$templates = CAYDENDIR_sd_get_column_templates( $settings );
		$tpl       = isset( $templates[ $col ] ) ? $templates[ $col ] : '';
		if ( '' !== trim( $tpl ) ) {
			return CAYDENDIR_sd_apply_template( $tpl, $row );
		}
		$row = CAYDENDIR_sd_normalize_record( $row );
		return isset( $row[ $col ] ) ? esc_html( (string) $row[ $col ] ) : '';
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'column display', $e );
		$row = is_array( $row ) ? $row : array();
		return isset( $row[ $col ] ) ? esc_html( (string) $row[ $col ] ) : '';
	}
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

/**
 * Legacy composer. Older versions built the handshake ID out of five separate
 * fields (Dialing Out Location, Text Name, Plugin Code, Gate Address,
 * Location). The ID is now a single editable value, but this still runs once,
 * during migration, to seed that value from a site's old fields so nothing
 * breaks on upgrade.
 */
function CAYDENDIR_sd_compose_legacy_id( $s ) {
	return sprintf(
		'%s-%d-%s-%s-%s',
		strtoupper( isset( $s['place_code'] ) ? $s['place_code'] : '' ),
		CAYDENDIR_sd_short_code_mod( isset( $s['signifier'] ) ? $s['signifier'] : '' ),
		strtoupper( isset( $s['placement_code'] ) ? $s['placement_code'] : '' ),
		isset( $s['accessor_key'] ) ? $s['accessor_key'] : '',
		strtoupper( substr( isset( $s['col_letter'] ) ? $s['col_letter'] : '', 0, 1 ) )
	);
}

/**
 * The handshake ID sent to the Apps Script. It is now stored as one value
 * ('handshake_id') that the admin edits directly, instead of being compiled
 * from five separate fields. Falls back to the legacy composed value if the
 * single field is somehow empty.
 */
function CAYDENDIR_sd_build_id( $settings = null ) {
	if ( null === $settings ) {
		$settings = CAYDENDIR_sd_get_settings();
	}
	$id = isset( $settings['handshake_id'] ) ? trim( (string) $settings['handshake_id'] ) : '';
	if ( '' === $id ) {
		$id = CAYDENDIR_sd_compose_legacy_id( $settings );
	}
	return $id;
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
/**
 * Safe wrapper for the sync. Never lets an unexpected error escape (it runs on
 * cron and from the admin), recording the failure instead so the site and the
 * scheduler keep working.
 */
function CAYDENDIR_sd_sync() {
	try {
		return CAYDENDIR_sd_sync_run();
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'sync', $e );
		CAYDENDIR_sd_record_sync( false, 'Unexpected error during sync: ' . $e->getMessage() );
		return new WP_Error( 'CAYDENDIR_sd_exception', $e->getMessage() );
	}
}

function CAYDENDIR_sd_sync_run() {
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
			'name'      => '',
			'firstname' => '',
			'lastname'  => '',
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
			} elseif ( in_array( $k, array( 'firstname', 'first name', 'first', 'fname', 'givenname', 'given name' ), true ) ) {
				$entry['firstname'] = sanitize_text_field( $v );
			} elseif ( in_array( $k, array( 'lastname', 'last name', 'last', 'lname', 'surname', 'familyname', 'family name' ), true ) ) {
				$entry['lastname'] = sanitize_text_field( $v );
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
		// Fill in whichever of name / firstname / lastname is missing so every
		// downstream consumer (search, sorting, templates) has a full name and
		// both parts to work with.
		$entry = CAYDENDIR_sd_fill_name_parts( $entry );

		if ( '' === $entry['name'] && '' === $entry['email'] ) {
			continue;
		}
		$clean[] = $entry;
	}
	return $clean;
}

/**
 * Keep name / firstname / lastname consistent on a record.
 *
 * The sheet may send a combined "name", or split "First"/"Last" columns, or
 * both. This fills the gaps so every consumer has all three:
 *   - name blank but a part present  -> name = "First Last"
 *   - both parts blank but name set  -> split name on the first space
 * The combined name stays authoritative for search, sorting and match keys, so
 * splitting is only a display/edit convenience and never changes the name.
 */
function CAYDENDIR_sd_fill_name_parts( $row ) {
	$row   = is_array( $row ) ? $row : array();
	$name  = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
	$first = isset( $row['firstname'] ) ? trim( (string) $row['firstname'] ) : '';
	$last  = isset( $row['lastname'] ) ? trim( (string) $row['lastname'] ) : '';

	if ( '' === $name && ( '' !== $first || '' !== $last ) ) {
		$name = trim( $first . ' ' . $last );
	} elseif ( '' === $first && '' === $last && '' !== $name ) {
		$sp = strpos( $name, ' ' );
		if ( false === $sp ) {
			$first = $name;
			$last  = '';
		} else {
			$first = substr( $name, 0, $sp );
			$last  = trim( substr( $name, $sp + 1 ) );
		}
	}

	$row['name']      = $name;
	$row['firstname'] = $first;
	$row['lastname']  = $last;
	return $row;
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
		'name'      => '',
		'firstname' => '',
		'lastname'  => '',
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
	// Keep name / first / last consistent (fills gaps in either direction).
	$row = CAYDENDIR_sd_fill_name_parts( $row );
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
 * Configurable sort rules
 *
 * Sorting is driven by an ordered list of rules saved in Settings › Staff
 * Directory › Sort rules. Each rule picks a field and a mode:
 *   - priority : rank by a custom label list (one per line, highest first);
 *                anything not listed sinks to the bottom.
 *   - asc      : A → Z alphabetical on that field.
 *   - desc     : Z → A on that field.
 * Rules apply in order: the first sorts everyone, and each rule below only
 * breaks the ties left by the rules above it (so two priority rules on Job
 * then Location reproduce the original ordering). Fields available for a
 * rule and the mode labels live in the two helpers below.
 * ---------------------------------------------------------------------- */

/** Fields a sort rule may target => their admin labels. */
function CAYDENDIR_sd_sort_fields() {
	return array(
		'name'        => 'Name (full)',
		'lastname'    => 'Last name',
		'firstname'   => 'First name',
		'publictitle' => 'Title',
		'job'         => 'Job',
		'location'    => 'Location',
		'email'       => 'Email',
		'id'          => 'Identification (ID)',
	);
}

/** Sort modes a rule may use => their admin labels. */
function CAYDENDIR_sd_sort_modes() {
	return array(
		'priority' => 'Custom priority list',
		'asc'      => 'A → Z (alphabetical)',
		'desc'     => 'Z → A (reverse)',
	);
}

/** The out-of-the-box rules: Job priority, then Location priority. */
function CAYDENDIR_sd_default_sort_rules() {
	return array(
		array( 'field' => 'job',      'mode' => 'priority', 'order' => CAYDENDIR_sd_default_job_order() ),
		array( 'field' => 'location', 'mode' => 'priority', 'order' => CAYDENDIR_sd_default_location_order() ),
	);
}

/** Coerce one raw rule into a valid { field, mode, order } shape. */
function CAYDENDIR_sd_normalize_sort_rule( $rule ) {
	$fields = CAYDENDIR_sd_sort_fields();
	$modes  = CAYDENDIR_sd_sort_modes();
	$rule   = is_array( $rule ) ? $rule : array();
	$field  = isset( $rule['field'] ) ? (string) $rule['field'] : '';
	$mode   = isset( $rule['mode'] ) ? (string) $rule['mode'] : '';
	if ( ! isset( $fields[ $field ] ) ) { $field = 'job'; }
	if ( ! isset( $modes[ $mode ] ) )   { $mode  = 'priority'; }
	return array(
		'field' => $field,
		'mode'  => $mode,
		'order' => isset( $rule['order'] ) ? (string) $rule['order'] : '',
	);
}

/** The saved sort rules, normalized. An empty array means "no custom sort". */
function CAYDENDIR_sd_get_sort_rules( $settings = null ) {
	if ( null === $settings ) {
		$settings = CAYDENDIR_sd_get_settings();
	}
	$rules = ( isset( $settings['sort_rules'] ) && is_array( $settings['sort_rules'] ) ) ? $settings['sort_rules'] : array();
	return array_values( array_map( 'CAYDENDIR_sd_normalize_sort_rule', $rules ) );
}

/**
 * One editable sort-rule row for the settings screen. $index is the numeric
 * position, or the "__INDEX__" placeholder for the hidden JS template (whose
 * inputs are disabled so the template itself never submits).
 */
function CAYDENDIR_sd_render_sort_rule_row( $opt, $index, $rule, $is_template = false ) {
	$rule   = CAYDENDIR_sd_normalize_sort_rule( $rule );
	$fields = CAYDENDIR_sd_sort_fields();
	$modes  = CAYDENDIR_sd_sort_modes();
	$name   = esc_attr( $opt ) . '[sort_rules][' . esc_attr( (string) $index ) . ']';
	$dis    = $is_template ? ' disabled' : '';
	$level  = is_numeric( $index ) ? ( (int) $index + 1 ) : 1;
	$show   = ( 'priority' === $rule['mode'] );

	ob_start();
	?>
	<div class="CAYDENDIR-rule" data-CAYDENDIR-rule>
		<span class="CAYDENDIR-rule__handle" data-CAYDENDIR-handle title="Drag to reorder" aria-hidden="true">&#9776;</span>
		<span class="CAYDENDIR-rule__level" data-CAYDENDIR-level><?php echo (int) $level; ?></span>

		<select name="<?php echo $name; ?>[field]" data-CAYDENDIR-field aria-label="Sort field"<?php echo $dis; ?>>
			<?php foreach ( $fields as $fkey => $flabel ) : ?>
				<option value="<?php echo esc_attr( $fkey ); ?>" <?php selected( $rule['field'], $fkey ); ?>><?php echo esc_html( $flabel ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="<?php echo $name; ?>[mode]" data-CAYDENDIR-mode aria-label="Sort mode"<?php echo $dis; ?>>
			<?php foreach ( $modes as $mkey => $mlabel ) : ?>
				<option value="<?php echo esc_attr( $mkey ); ?>" <?php selected( $rule['mode'], $mkey ); ?>><?php echo esc_html( $mlabel ); ?></option>
			<?php endforeach; ?>
		</select>

		<button type="button" class="button-link button-link-delete" data-CAYDENDIR-remove>Remove</button>

		<div class="CAYDENDIR-rule__order" data-CAYDENDIR-order-wrap<?php echo $show ? '' : ' hidden'; ?>>
			<label class="CAYDENDIR-rule__order-label">Priority order &mdash; one label per line, highest priority first (anything not listed sinks to the bottom)
				<textarea name="<?php echo $name; ?>[order]" rows="8" class="large-text code" spellcheck="false" aria-label="Priority order list"<?php echo $dis; ?>><?php echo esc_textarea( $rule['order'] ); ?></textarea>
			</label>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Sorting — the spreadsheet sorts via Apps Script, but edited/manual data
 * never goes back to the sheet, so the same sort has to happen here at render
 * time using the configurable rules above.
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
 * Sort rows by the configured rules, in order. Each rule either ranks a field
 * against a custom priority list (unlisted values sink to the bottom, like the
 * Infinity fallback in the Apps Script sort — which also flags typos) or sorts
 * it alphabetically A→Z / Z→A. Later rules only break ties left by earlier
 * ones, and equal rows keep their incoming order (stable). With no rules
 * configured, rows are left in their merged order.
 */
function CAYDENDIR_sd_sort_rows( $rows ) {
	if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	$rules = CAYDENDIR_sd_get_sort_rules();
	if ( empty( $rules ) ) {
		return array_values( $rows );
	}

	// Pre-build a rank lookup for each priority rule so usort() stays cheap.
	$maps = array();
	foreach ( $rules as $ri => $rule ) {
		if ( 'priority' === $rule['mode'] ) {
			$maps[ $ri ] = CAYDENDIR_sd_rank_map( $rule['order'] );
		}
	}

	$rows = array_values( $rows );
	$i    = 0;
	foreach ( $rows as &$r ) {
		$r['_i'] = $i++; // stable final tiebreak
	}
	unset( $r );

	usort( $rows, function ( $a, $b ) use ( $rules, $maps ) {
		foreach ( $rules as $ri => $rule ) {
			$field = $rule['field'];
			$av = strtolower( trim( (string) ( isset( $a[ $field ] ) ? $a[ $field ] : '' ) ) );
			$bv = strtolower( trim( (string) ( isset( $b[ $field ] ) ? $b[ $field ] : '' ) ) );

			if ( 'priority' === $rule['mode'] ) {
				$ar = isset( $maps[ $ri ][ $av ] ) ? $maps[ $ri ][ $av ] : PHP_INT_MAX;
				$br = isset( $maps[ $ri ][ $bv ] ) ? $maps[ $ri ][ $bv ] : PHP_INT_MAX;
				if ( $ar !== $br ) {
					return ( $ar < $br ) ? -1 : 1;
				}
			} else {
				$cmp = strnatcasecmp( $av, $bv );
				if ( 0 !== $cmp ) {
					return ( 'desc' === $rule['mode'] ) ? -$cmp : $cmp;
				}
			}
		}
		return ( $a['_i'] < $b['_i'] ) ? -1 : 1;
	} );

	foreach ( $rows as &$r ) {
		unset( $r['_i'] );
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
	$blob        = strtolower( trim( $entry['name'] . ' ' . $entry['firstname'] . ' ' . $entry['lastname'] . ' ' . $entry['publictitle'] . ' ' . $entry['job'] . ' ' . $entry['location'] . ' ' . $entry['email'] . ' ' . $entry['id'] . ' ' . implode( ' ', $entry['tags'] ) ) );

	// Templated display HTML so the front end shows edits exactly as the
	// server would render them.
	return array(
		'key'         => (string) $key,
		'manual'      => (bool) $manual,
		'name'        => $entry['name'],
		'firstname'   => $entry['firstname'],
		'lastname'    => $entry['lastname'],
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
		// Templated display HTML per column (what the visible cells should show).
		'name_display'        => CAYDENDIR_sd_column_display( 'name', $entry ),
		'publictitle_display' => CAYDENDIR_sd_column_display( 'publictitle', $entry ),
		'job_display'         => CAYDENDIR_sd_column_display( 'job', $entry ),
		'location_display'    => CAYDENDIR_sd_column_display( 'location', $entry ),
		'email_display'       => CAYDENDIR_sd_column_display( 'email', $entry ),
	);
}

/* ---- AJAX: save a manual override ---- */
add_action( 'wp_ajax_CAYDENDIR_sd_save_manual', 'CAYDENDIR_sd_ajax_save_manual' );
function CAYDENDIR_sd_ajax_save_manual() {
	try {
		CAYDENDIR_sd_ajax_save_manual_run();
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'ajax save', $e );
		wp_send_json_error( array( 'message' => 'Something went wrong saving. Please try again.' ), 500 );
	}
}
function CAYDENDIR_sd_ajax_save_manual_run() {
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
		'name'      => $text( 'name' ),
		'firstname' => $text( 'firstname' ),
		'lastname'  => $text( 'lastname' ),
		'email'    => $email,
		'photo'    => $text( 'photo' ),
        'publictitle' => $text('publictitle'),
		'location' => $text( 'location' ),
		'job'      => $text( 'job' ),
		'tags'     => CAYDENDIR_sd_parse_tags( $text( 'tags' ) ),
		'id'       => $text( 'id' ),
		'hidden'   => ( isset( $_POST['hidden'] ) && '1' === $_POST['hidden'] ) ? '1' : '0',
	);

	// Combine First/Last into the stored name (and split when only a name was
	// given), so the record is consistent no matter which fields were filled.
	$entry = CAYDENDIR_sd_fill_name_parts( $entry );

	if ( '' === $entry['name'] && '' === $entry['email'] ) {
		wp_send_json_error( array( 'message' => 'Enter at least a first or last name, or an email address.' ) );
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
	try {
		CAYDENDIR_sd_ajax_delete_manual_run();
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'ajax delete', $e );
		wp_send_json_error( array( 'message' => 'Something went wrong removing the override. Please try again.' ), 500 );
	}
}
function CAYDENDIR_sd_ajax_delete_manual_run() {
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

/**
 * Safe public entry point for the shortcode and the Beaver Builder module.
 * Wraps the real renderer so a runtime error shows nothing (or a hidden note
 * for editors) instead of white-screening the page it is placed on. Any
 * output buffers opened by the renderer are unwound before returning.
 */
function CAYDENDIR_sd_render( $atts = array() ) {
	$ob_level = ob_get_level();
	try {
		return CAYDENDIR_sd_render_directory( $atts );
	} catch ( \Throwable $e ) {
		// Discard a half-built buffer the renderer may have left open.
		while ( ob_get_level() > $ob_level ) {
			ob_end_clean();
		}
		CAYDENDIR_sd_log( 'shortcode render', $e );
		// The fallback itself must never throw, so guard the editor check.
		try {
			if ( function_exists( 'CAYDENDIR_sd_user_can_edit' ) && CAYDENDIR_sd_user_can_edit() ) {
				return '<p style="padding:1rem;border:1px dashed #c00;color:#c00;">The Staff Directory could not be displayed. The error was logged for the site administrator.</p>';
			}
		} catch ( \Throwable $ignored ) {
			// fall through
		}
		return '';
	}
}

function CAYDENDIR_sd_render_directory( $atts ) {
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
							$row   = CAYDENDIR_sd_normalize_record( $row );
							$name  = $row['name'];
							$firstname = $row['firstname'];
							$lastname  = $row['lastname'];
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

							// Templated display text per column.
							$d_name  = CAYDENDIR_sd_column_display( 'name', $row, $settings );
							$d_title = CAYDENDIR_sd_column_display( 'publictitle', $row, $settings );
							$d_job   = CAYDENDIR_sd_column_display( 'job', $row, $settings );
							$d_loc   = CAYDENDIR_sd_column_display( 'location', $row, $settings );
							$d_email = CAYDENDIR_sd_column_display( 'email', $row, $settings );

							$filter_terms = $tags;
							if ( '' !== $job ) {
								$filter_terms[] = $job;
							}
							$terms_lower = array_map( 'strtolower', $filter_terms );
							$search_blob = strtolower( trim( $name . ' ' . $firstname . ' ' . $lastname . ' ' . $publictitle . ' ' . $job . ' ' . $location . ' ' . $email . ' ' . $rid . ' ' . implode( ' ', $tags ) ) );
							$place         = preg_replace( '/[^0-9+]/', '', $location );
							$rowid       = $uid . '-r' . $i;

							$record_attr = '';
							if ( $can_edit ) {
								$record = array(
									'key'      => $key,
									'manual'   => $is_manual,
									'name'     => $name,
									'firstname' => $firstname,
									'lastname'  => $lastname,
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
									<span data-CAYDENDIR-field="name"><?php echo $d_name; // phpcs:ignore WordPress.Security.EscapeOutput -- safe HTML from CAYDENDIR_sd_column_display ?></span>
									<?php if ( $can_edit ) : ?>
										<span class="CAYDENDIR-sd__badge" data-CAYDENDIR-manual-badge<?php echo $is_manual ? '' : ' hidden'; ?>>Edited</span>
										<span class="CAYDENDIR-sd__badge" data-CAYDENDIR-hidden-badge<?php echo $hidden ? '' : ' hidden'; ?>>Hidden</span>
									<?php endif; ?>
								</th>
								<?php if ( $show_publictitle || $can_edit ) : ?>
									<td data-label="publictitle"><span data-CAYDENDIR-field="publictitle"><?php echo '' !== $d_title ? $d_title : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput -- safe HTML ?></span></td>
								<?php endif; ?>
								<?php if ( $show_job || $can_edit ) : ?>
									<td data-label="Job"><span data-CAYDENDIR-field="job"><?php echo '' !== $d_job ? $d_job : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput -- safe HTML ?></span></td>
								<?php endif; ?>
								<?php if ( $show_location ) : ?>
									<td class="CAYDENDIR-sd__location" data-label="Location"><span data-CAYDENDIR-field="location"><?php echo '' !== $d_loc ? $d_loc : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput -- safe HTML ?></span></td>
								<?php endif; ?>
								<?php if ( $show_email || $can_edit) : ?>
									<td data-label="Email">
										<span data-CAYDENDIR-field="email"><?php echo '' !== $d_email ? $d_email : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput -- safe HTML ?></span>
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
					$row   = CAYDENDIR_sd_normalize_record( $row );
					$name  = $row['name'];
					$firstname = $row['firstname'];
					$lastname  = $row['lastname'];
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

					// Templated display text per column.
					$d_name  = CAYDENDIR_sd_column_display( 'name', $row, $settings );
					$d_title = CAYDENDIR_sd_column_display( 'publictitle', $row, $settings );
					$d_job   = CAYDENDIR_sd_column_display( 'job', $row, $settings );
					$d_loc   = CAYDENDIR_sd_column_display( 'location', $row, $settings );
					$d_email = CAYDENDIR_sd_column_display( 'email', $row, $settings );

					$filter_terms = $tags;
					if ( '' !== $job ) {
						$filter_terms[] = $job;
					}
					$terms_lower = array_map( 'strtolower', $filter_terms );
					$search_blob = strtolower( trim( $name . ' ' . $firstname . ' ' . $lastname . ' ' . $publictitle . ' ' . $job . ' ' . $location . ' ' . $email . ' ' . $rid . ' ' . implode( ' ', $tags ) ) );
					$place         = preg_replace( '/[^0-9+]/', '', $location );

					$record_attr = '';
					if ( $can_edit ) {
						$record = array(
							'key'      => $key,
							'manual'   => $is_manual,
							'name'     => $name,
							'firstname' => $firstname,
							'lastname'  => $lastname,
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
							<h3 class="CAYDENDIR-sd__name" data-CAYDENDIR-wrap="name"<?php echo '' !== $d_name ? '' : ' hidden'; ?>>
								<span data-CAYDENDIR-field="name"><?php echo $d_name; // phpcs:ignore WordPress.Security.EscapeOutput -- safe HTML ?></span>
								<?php if ( $can_edit ) : ?>
									<span class="CAYDENDIR-sd__badge" data-CAYDENDIR-manual-badge<?php echo $is_manual ? '' : ' hidden'; ?>>Edited</span>
									<span class="CAYDENDIR-sd__badge" data-CAYDENDIR-hidden-badge<?php echo $hidden ? '' : ' hidden'; ?>>Hidden</span>
								<?php endif; ?>
							</h3>
							<?php if ( $show_publictitle || $can_edit ) : ?>
								<p class="CAYDENDIR-sd__job" data-CAYDENDIR-wrap="publictitle"<?php echo '' !== $d_title ? '' : ' hidden'; ?>><span data-CAYDENDIR-field="publictitle"><?php echo $d_title; // phpcs:ignore WordPress.Security.EscapeOutput -- safe HTML ?></span></p>
							<?php endif; ?>
							<?php if ( $show_job || $can_edit) : ?>
								<p class="CAYDENDIR-sd__job" data-CAYDENDIR-wrap="job"<?php echo '' !== $d_job ? '' : ' hidden'; ?>><span data-CAYDENDIR-field="job"><?php echo $d_job; // phpcs:ignore WordPress.Security.EscapeOutput -- safe HTML ?></span></p>
							<?php endif; ?>
							<?php if ( $show_location || $can_edit) : ?>
								<p class="CAYDENDIR-sd__location" data-CAYDENDIR-wrap="location"<?php echo '' !== $d_loc ? '' : ' hidden'; ?>><span data-CAYDENDIR-field="location"><?php echo $d_loc; // phpcs:ignore WordPress.Security.EscapeOutput -- safe HTML ?></span></p>
							<?php endif; ?>
							<?php if ( $show_email || $can_edit) : ?>
								<p class="CAYDENDIR-sd__card-email" data-CAYDENDIR-wrap="email"<?php echo '' !== $d_email ? '' : ' hidden'; ?>><span data-CAYDENDIR-field="email"><?php echo $d_email; // phpcs:ignore WordPress.Security.EscapeOutput -- safe HTML ?></span></p>
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
							<label for="<?php echo esc_attr( $uid ); ?>-f-firstname">First name</label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-firstname" data-CAYDENDIR-f="firstname" autocomplete="off">
						</p>
						<p class="CAYDENDIR-sd__field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-lastname">Last name</label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-lastname" data-CAYDENDIR-f="lastname" autocomplete="off" aria-describedby="<?php echo esc_attr( $uid ); ?>-f-lastname-hint">
							<span class="CAYDENDIR-sd__hint" id="<?php echo esc_attr( $uid ); ?>-f-lastname-hint">First and Last combine into the displayed name. How they show is set under Settings &rsaquo; Column display.</span>
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
	add_submenu_page( 'options-general.php', 'Staff Directory Help', 'Staff Directory Help', 'manage_options', 'CAYDENDIR-staff-directory-help', 'CAYDENDIR_sd_help_page' );
}

/* -------------------------------------------------------------------------
 * Beaver Builder module
 *
 * Registers a native "Staff Directory" module so the directory can be dropped
 * onto a page from the Beaver Builder content panel, instead of only via the
 * [CAYDENDIR_staff_directory] shortcode. The module simply wraps the same
 * renderer. Registration only happens when Beaver Builder is active.
 * ---------------------------------------------------------------------- */
add_action( 'init', 'CAYDENDIR_sd_register_bb_module' );
function CAYDENDIR_sd_register_bb_module() {
	// This runs on every request. Guard hard: a missing module file or a
	// Beaver Builder API change must never fatal the whole site.
	try {
		if ( ! class_exists( 'FLBuilderModule' ) || ! class_exists( 'FLBuilder' ) ) {
			return; // Beaver Builder is not active.
		}
		$module_file = CAYDENDIR_SD_DIR . 'modules/cayden-staff-directory/cayden-staff-directory.php';
		if ( ! is_readable( $module_file ) ) {
			// The module folder was not uploaded — skip it. The shortcode
			// still works, and the rest of the site keeps running.
			CAYDENDIR_sd_log( 'BB module', 'module file missing: ' . $module_file );
			return;
		}
		require_once $module_file;
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'BB module registration', $e );
	}
}

add_action( 'admin_enqueue_scripts', 'CAYDENDIR_sd_admin_assets' );
function CAYDENDIR_sd_admin_assets( $hook ) {
	if ( 'settings_page_CAYDENDIR-staff-directory' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );
	wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".CAYDENDIR-color-field").wpColorPicker();});' );
	// Sort-rules builder: add / remove / drag-reorder rows and show the
	// priority textarea only for priority rules.
	wp_enqueue_script( 'CAYDENDIR-sd-admin', plugin_dir_url( __FILE__ ) . 'CAYDENDIR-admin.js', array( 'jquery', 'jquery-ui-sortable' ), CAYDENDIR_SD_VERSION, true );
}

add_action( 'admin_init', 'CAYDENDIR_sd_register_settings' );
function CAYDENDIR_sd_register_settings() {
	register_setting( 'CAYDENDIR_sd_group', CAYDENDIR_SD_SETTINGS, 'CAYDENDIR_sd_sanitize_settings' );
}

function CAYDENDIR_sd_sanitize_settings( $input ) {
	try {
		return CAYDENDIR_sd_sanitize_settings_run( $input );
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'settings sanitize', $e );
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( 'CAYDENDIR_sd', 'sanitize_failed', 'Settings could not be saved because of an unexpected error. Your previous settings were kept.', 'error' );
		}
		// Return the last-known-good settings so nothing is corrupted.
		return CAYDENDIR_sd_get_settings();
	}
}

function CAYDENDIR_sd_sanitize_settings_run( $input ) {
	$out = CAYDENDIR_sd_get_settings();
	$in  = is_array( $input ) ? $input : array();

	$out['gas_url']        = esc_url_raw( trim( isset( $in['gas_url'] ) ? $in['gas_url'] : '' ) );
	$out['secret_key']     = trim( (string) ( isset( $in['secret_key'] ) ? $in['secret_key'] : '' ) );
	// Handshake ID — one editable value now (was five separate fields that the
	// plugin compiled together). Keep letters, digits, dashes and underscores
	// and strip everything else (including whitespace). A blank submission
	// leaves the previous value in place. The legacy part-fields are left as
	// they are so CAYDENDIR_sd_build_id() can still fall back to them.
	$hid = isset( $in['handshake_id'] ) ? (string) $in['handshake_id'] : '';
	$hid = preg_replace( '/[^A-Za-z0-9_\-]/', '', $hid );
	if ( '' !== $hid ) {
		$out['handshake_id'] = $hid;
	}

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

	// Sort rules — an ordered list of { field, mode, order }. The array-key
	// order the form submits is the on-screen (priority) order, so keep it.
	// Submitting no rows at all is allowed and means "no custom sort".
	if ( isset( $in['sort_rules'] ) && is_array( $in['sort_rules'] ) ) {
		$rules_in = $in['sort_rules'];
		ksort( $rules_in, SORT_NUMERIC );
		$rules = array();
		foreach ( $rules_in as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$r          = CAYDENDIR_sd_normalize_sort_rule( $rule );
			$r['order'] = ( 'priority' === $r['mode'] ) ? sanitize_textarea_field( isset( $rule['order'] ) ? $rule['order'] : '' ) : '';
			$rules[]    = $r;
		}
		$out['sort_rules'] = $rules;
	} else {
		$out['sort_rules'] = array();
	}

	// Column display templates — one template per column. Templates may contain
	// HTML and the [if]/{field} building blocks, so keep that markup but run it
	// through wp_kses_post to strip anything unsafe (scripts, event handlers).
	// Blank falls back to that column's default (handled by get_column_templates).
	if ( isset( $in['column_templates'] ) && is_array( $in['column_templates'] ) ) {
		$tpl_in  = $in['column_templates'];
		$tpl_out = array();
		foreach ( CAYDENDIR_sd_default_column_templates() as $col => $default ) {
			$tpl_out[ $col ] = isset( $tpl_in[ $col ] ) ? wp_kses_post( (string) $tpl_in[ $col ] ) : $default;
		}
		$out['column_templates'] = $tpl_out;
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
	try {
		CAYDENDIR_sd_settings_page_run();
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'settings page', $e );
		echo '<div class="wrap"><h1>CAYDENDIR Staff Directory</h1><div class="notice notice-error"><p>The settings screen hit an unexpected error. It has been logged. The rest of your site is unaffected.</p></div></div>';
	}
}

function CAYDENDIR_sd_settings_page_run() {

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
		<p><a href="<?php echo esc_url( admin_url( 'options-general.php?page=CAYDENDIR-staff-directory-help' ) ); ?>" class="button">Open the Help guide &amp; troubleshooting &rarr;</a></p>

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
					<th scope="row"><label for="CAYDENDIR_handshake_id">Handshake ID</label></th>
					<td>
						<input name="<?php echo esc_attr( $opt ); ?>[handshake_id]" id="CAYDENDIR_handshake_id" type="text" class="large-text code" value="<?php echo esc_attr( CAYDENDIR_sd_build_id( $s ) ); ?>" spellcheck="false" autocomplete="off" placeholder="WP-12345-SD-123456789-F">
						<p class="description">The full handshake ID sent to the Apps Script, entered as <strong>one value</strong> (for example <code>WP-12345-SD-123456789-F</code>). Paste the ID your Apps Script generator gives you — the plugin no longer compiles it from separate <em>Dialing Out Location / Text Name / Plugin Code / Gate Address / Location</em> fields. It is signed with the Encrypter Key above to build the request token, so it must match exactly what the Apps Script expects. See the <a href="<?php echo esc_url( admin_url( 'options-general.php?page=CAYDENDIR-staff-directory-help' ) ); ?>">Help guide</a> for how the ID is structured.</p>
					</td>
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

			<h2>Column display</h2>
			<p class="description">Build exactly what each column shows. A template can mix plain text, <strong>HTML</strong> (links, <code>&lt;strong&gt;</code>, icons&hellip;) and these building blocks:</p>
			<ul class="CAYDENDIR-tpl-legend">
				<li><code>{field}</code> &mdash; the field&rsquo;s value</li>
				<li><code>{field|fallback}</code> &mdash; the value, or <em>fallback</em> text when it&rsquo;s empty</li>
				<li><code>[if field]&hellip;[/if]</code> &mdash; show the block only when the field has a value</li>
				<li><code>[if field]&hellip;[else]&hellip;[/if]</code> &mdash; show one thing or another</li>
				<li><code>[if field == value]</code>, <code>[if field != value]</code>, <code>[if field contains value]</code> &mdash; compare a field (case-insensitive)</li>
			</ul>
			<p class="description">Field values are always escaped, so data can never break your layout; only safe HTML is kept. Leave a box blank and save to restore its default. Click a field button to insert it into the box your cursor is in.</p>
			<?php
			$tpl_vals = CAYDENDIR_sd_get_column_templates( $s );
			$tpl_defs = CAYDENDIR_sd_default_column_templates();
			$tpl_flds = CAYDENDIR_sd_template_fields();
			?>
			<style>
				.CAYDENDIR-tpl-legend{max-width:820px;margin:.2em 0 1em;padding-left:1.2em;}
				.CAYDENDIR-tpl-legend li{margin:.15em 0;}
				.CAYDENDIR-tpl{max-width:900px;margin:0 0 18px;padding:12px 14px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;}
				.CAYDENDIR-tpl__label{font-weight:600;font-size:14px;margin:0 0 6px;}
				.CAYDENDIR-tpl__chips{display:flex;flex-wrap:wrap;gap:5px;margin:0 0 6px;}
				.CAYDENDIR-tpl__chip{cursor:pointer;font:inherit;font-size:12px;padding:2px 8px;border:1px solid #c3c4c7;border-radius:999px;background:#f6f7f7;}
				.CAYDENDIR-tpl__chip:hover{background:#e9ecef;}
				.CAYDENDIR-tpl textarea{width:100%;min-height:60px;font-family:Menlo,Consolas,monospace;font-size:13px;}
				.CAYDENDIR-tpl__foot{display:flex;flex-wrap:wrap;gap:14px;align-items:baseline;margin-top:6px;}
				.CAYDENDIR-tpl__default{color:#646970;font-size:12px;}
				.CAYDENDIR-tpl__preview{margin-top:8px;padding:8px 10px;border:1px dashed #c3c4c7;border-radius:4px;background:#fafafa;}
				.CAYDENDIR-tpl__preview-label{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#646970;margin:0 0 3px;}
			</style>
			<div id="CAYDENDIR-col-templates" data-CAYDENDIR-col-templates>
				<?php foreach ( CAYDENDIR_sd_template_columns() as $tcol => $tlabel ) : ?>
					<div class="CAYDENDIR-tpl" data-CAYDENDIR-tpl>
						<p class="CAYDENDIR-tpl__label"><label for="CAYDENDIR_tpl_<?php echo esc_attr( $tcol ); ?>"><?php echo esc_html( $tlabel ); ?> column</label></p>
						<div class="CAYDENDIR-tpl__chips" aria-hidden="true">
							<?php foreach ( $tpl_flds as $fkey => $flabel ) : ?>
								<button type="button" class="CAYDENDIR-tpl__chip" data-CAYDENDIR-insert="{<?php echo esc_attr( $fkey ); ?>}" title="<?php echo esc_attr( $flabel ); ?>">{<?php echo esc_html( $fkey ); ?>}</button>
							<?php endforeach; ?>
							<button type="button" class="CAYDENDIR-tpl__chip" data-CAYDENDIR-insert="[if field]…[/if]" title="Conditional block">[if]</button>
						</div>
						<textarea id="CAYDENDIR_tpl_<?php echo esc_attr( $tcol ); ?>" data-CAYDENDIR-tpl-input
							name="<?php echo esc_attr( $opt ); ?>[column_templates][<?php echo esc_attr( $tcol ); ?>]"
							rows="2" spellcheck="false" autocomplete="off"><?php echo esc_textarea( isset( $tpl_vals[ $tcol ] ) ? $tpl_vals[ $tcol ] : '' ); ?></textarea>
						<div class="CAYDENDIR-tpl__foot">
							<span class="CAYDENDIR-tpl__default">Default: <code><?php echo esc_html( $tpl_defs[ $tcol ] ); ?></code></span>
						</div>
						<div class="CAYDENDIR-tpl__preview">
							<p class="CAYDENDIR-tpl__preview-label">Live preview (sample person)</p>
							<div data-CAYDENDIR-tpl-preview></div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="description"><strong>Examples:</strong>
				<code>{lastname}, {firstname}</code> &middot;
				<code>&lt;strong&gt;{name}&lt;/strong&gt;</code> &middot;
				<code>[if publictitle]{publictitle}[else]{job}[/if]</code> &middot;
				<code>[if location]📍 {location}[/if]</code> &middot;
				<code>&lt;a href="mailto:{email}"&gt;Email {firstname}&lt;/a&gt;</code> &middot;
				<code>[if job == Principal]⭐ {name}[else]{name}[/if]</code> &middot;
				<code>[if location contains Elementary]🍎[/if]</code>
			</p>

			<h2>Sort rules</h2>
			<p class="description">The directory is sorted by these rules, in order. The first rule sorts everyone; each rule below only breaks the ties left by the rules above it (so two &ldquo;priority&rdquo; rules on Job then Location give the classic ordering). For each rule pick a <strong>field</strong> and how it sorts: a <strong>Custom priority list</strong> (type the order, one label per line, highest first — anything not listed sinks to the bottom, which handily flags typos), <strong>A&nbsp;&rarr;&nbsp;Z</strong>, or <strong>Z&nbsp;&rarr;&nbsp;A</strong>. Priority-list labels must match the displayed text for that field (case doesn&rsquo;t matter). Drag the <span aria-hidden="true">&#9776;</span> handle to reorder, remove a rule to drop that level, and add as many levels as you like. Remove every rule to leave people in their synced order.</p>
			<style>
				#CAYDENDIR-sort-rules{max-width:820px;}
				.CAYDENDIR-rule{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:10px 12px;margin:0 0 8px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;}
				.CAYDENDIR-rule__handle{cursor:grab;color:#787c82;font-size:18px;line-height:1;-webkit-user-select:none;user-select:none;}
				.CAYDENDIR-rule__level{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:26px;padding:0 7px;border-radius:999px;background:#f0f0f1;font-weight:600;}
				.CAYDENDIR-rule .button-link-delete{margin-left:auto;}
				.CAYDENDIR-rule__order{flex:0 0 100%;margin-top:4px;}
				.CAYDENDIR-rule__order-label{display:block;font-weight:600;}
				.CAYDENDIR-rule__order-label textarea{font-weight:400;margin-top:4px;}
				#CAYDENDIR-sort-rules .ui-sortable-helper{box-shadow:0 3px 10px rgba(0,0,0,.18);}
				#CAYDENDIR-sort-rules .ui-sortable-placeholder{visibility:visible!important;height:56px;margin:0 0 8px;border:1px dashed #c3c4c7;border-radius:6px;background:#f6f7f7;}
			</style>
			<div id="CAYDENDIR-sort-rules" data-CAYDENDIR-rules>
				<?php
				foreach ( $s['sort_rules'] as $ri => $rule ) {
					echo CAYDENDIR_sd_render_sort_rule_row( $opt, $ri, $rule ); // phpcs:ignore WordPress.Security.EscapeOutput -- row built with esc_*/selected() internally
				}
				?>
			</div>
			<p><button type="button" class="button" data-CAYDENDIR-add-rule>+ Add sort rule</button></p>
			<div class="CAYDENDIR-rule-template" data-CAYDENDIR-rule-template hidden>
				<?php echo CAYDENDIR_sd_render_sort_rule_row( $opt, '__INDEX__', array( 'field' => 'name', 'mode' => 'asc', 'order' => '' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>

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
		<h2>Handshake ID in use</h2>
		<p><code style="font-size:14px;"><?php echo esc_html( $id ); ?></code></p>
		<p class="description">This is the exact ID the plugin will send. It mirrors the <strong>Handshake ID</strong> field above.</p>

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
		<p><strong>Beaver Builder:</strong> in the builder, open the content panel and drag the <strong>Staff Directory</strong> module (in the <em>Caydens Plugins</em> category) onto the page — no shortcode needed. Its settings (heading, layout, tag match) are on the module.</p>
		<p><strong>Shortcode</strong> (any page, block, or an HTML module):</p>
		<p><code>[CAYDENDIR_staff_directory heading="Search People"]</code></p>
		<p class="description">Optional <code>layout="cards"</code> or <code>layout="table"</code> overrides the setting per placement. <code>match="all"</code> requires every selected tag.</p>
		<p class="description">Logged-in administrators see an <strong>Edit</strong> button on every row of the public directory. Edits are saved as manual overrides (see above), can hide a person from the public, and re-sort into place automatically. Change who may edit with the <code>CAYDENDIR_sd_edit_cap</code> filter.</p>
		<p class="description">Full instructions and fixes are on the <a href="<?php echo esc_url( admin_url( 'options-general.php?page=CAYDENDIR-staff-directory-help' ) ); ?>">Help guide</a>.</p>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Help / troubleshooting guide
 * ---------------------------------------------------------------------- */

function CAYDENDIR_sd_help_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	try {
		CAYDENDIR_sd_help_page_run();
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'help page', $e );
		echo '<div class="wrap"><h1>Staff Directory &mdash; Help</h1><div class="notice notice-error"><p>The help screen hit an unexpected error. It has been logged.</p></div></div>';
	}
}

function CAYDENDIR_sd_help_page_run() {
	$settings_url = admin_url( 'options-general.php?page=CAYDENDIR-staff-directory' );
	$bb_active    = class_exists( 'FLBuilderModule' );
	?>
	<div class="wrap CAYDENDIR-help">
		<h1>Staff Directory &mdash; Help &amp; Troubleshooting</h1>
		<p><a href="<?php echo esc_url( $settings_url ); ?>" class="button">&larr; Back to Settings</a></p>

		<style>
			.CAYDENDIR-help{max-width:900px;}
			.CAYDENDIR-help .card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:4px 20px 16px;margin:0 0 18px;}
			.CAYDENDIR-help h2{margin-top:22px;}
			.CAYDENDIR-help h3{margin:18px 0 6px;}
			.CAYDENDIR-help code{background:#f0f0f1;padding:1px 5px;border-radius:3px;}
			.CAYDENDIR-help .toc{columns:2;-webkit-columns:2;margin:0 0 8px;padding-left:18px;}
			.CAYDENDIR-help .fix{border-left:4px solid #2271b1;background:#f6f7f7;padding:8px 14px;margin:0 0 12px;border-radius:0 4px 4px 0;}
			.CAYDENDIR-help .fix p{margin:.3em 0;}
			.CAYDENDIR-help table.kv{border-collapse:collapse;margin:6px 0;}
			.CAYDENDIR-help table.kv th,.CAYDENDIR-help table.kv td{border:1px solid #dcdcde;padding:6px 10px;text-align:left;vertical-align:top;}
		</style>

		<div class="card">
			<h2 id="contents">What&rsquo;s in this guide</h2>
			<ul class="toc">
				<li><a href="#quickstart">Quick start</a></li>
				<li><a href="#place">Putting the directory on a page</a></li>
				<li><a href="#connection">Connection &amp; Handshake ID</a></li>
				<li><a href="#sync">How syncing works</a></li>
				<li><a href="#editing">Editing people (manual overrides)</a></li>
				<li><a href="#sorting">Sort rules</a></li>
				<li><a href="#columns">Layout &amp; columns</a></li>
				<li><a href="#display">Column display &amp; name split</a></li>
				<li><a href="#css">Colours &amp; Custom CSS</a></li>
				<li><a href="#trouble">Troubleshooting &mdash; steps to fix</a></li>
			</ul>
		</div>

		<div class="card">
			<h2 id="quickstart">Quick start</h2>
			<ol>
				<li>Open <a href="<?php echo esc_url( $settings_url ); ?>">Settings &rsaquo; Staff Directory</a>.</li>
				<li>Fill in the <strong>Apps Script Web App URL</strong>, the <strong>Encrypter Key</strong>, and the <strong>Handshake ID</strong> (see <a href="#connection">below</a>).</li>
				<li>Click <strong>Save settings</strong>, then click <strong>Sync now</strong>. You should see &ldquo;<em>N records synced</em>&rdquo;.</li>
				<li>Put the directory on a page &mdash; drag the Beaver Builder module or paste the shortcode (<a href="#place">below</a>).</li>
				<li>Adjust <a href="#sorting">Sort rules</a>, <a href="#columns">columns</a>, and <a href="#css">colours</a> to taste.</li>
			</ol>
		</div>

		<div class="card">
			<h2 id="place">Putting the directory on a page</h2>
			<h3>Beaver Builder module <?php echo $bb_active ? '' : '<span class="description">(Beaver Builder is not active on this site right now)</span>'; ?></h3>
			<p>In the builder, open the <strong>+</strong> content panel, find the <strong>Staff Directory</strong> module under the <strong>Caydens Plugins</strong> category, and drag it onto the page. No shortcode needed. In the module settings you can set:</p>
			<ul>
				<li><strong>Heading</strong> &mdash; the title shown above the search box.</li>
				<li><strong>Layout</strong> &mdash; <em>Use the plugin setting</em>, <em>Table</em>, or <em>Cards</em> (overrides the global setting just for this placement).</li>
				<li><strong>Tag match</strong> &mdash; <em>Any</em> or <em>All</em> when filtering by tags.</li>
			</ul>
			<p class="description"><strong>For other Cayden plugins:</strong> to share this same <em>Caydens Plugins</em> category, register your Beaver Builder module with <code>'category' =&gt; 'Caydens Plugins'</code> (this plugin exposes the exact string as the <code>CAYDENDIR_BB_CATEGORY</code> constant). Any module using that category value appears together under this heading, in the default module group.</p>
			<h3>Shortcode</h3>
			<p>Works in any page, post, block, or an HTML module:</p>
			<p><code>[CAYDENDIR_staff_directory heading="Search People"]</code></p>
			<table class="kv">
				<tr><th>Attribute</th><th>Values</th><th>What it does</th></tr>
				<tr><td><code>heading</code></td><td>any text</td><td>Title above the search box.</td></tr>
				<tr><td><code>layout</code></td><td><code>table</code> / <code>cards</code></td><td>Overrides the global layout for this placement.</td></tr>
				<tr><td><code>match</code></td><td><code>any</code> / <code>all</code></td><td><code>all</code> requires every selected tag; <code>any</code> matches any.</td></tr>
			</table>
		</div>

		<div class="card">
			<h2 id="connection">Connection &amp; Handshake ID</h2>
			<p>Three fields under <strong>Connection &amp; handshake</strong> let the plugin talk to your Google Apps Script:</p>
			<table class="kv">
				<tr><th>Field</th><th>What it is</th></tr>
				<tr><td><strong>Apps Script Web App URL</strong></td><td>The <code>/exec</code> URL of your deployed Apps Script web app.</td></tr>
				<tr><td><strong>Encrypter Key</strong></td><td>The shared secret. It signs the Handshake ID (HMAC-SHA256) to make the request token. It must match the key in the Apps Script.</td></tr>
				<tr><td><strong>Handshake ID</strong></td><td>One value identifying this request, e.g. <code>WP-12345-SD-123456789-F</code>. <strong>Paste it as a whole</strong> from your Apps Script generator.</td></tr>
			</table>
			<p><strong>This changed:</strong> the Handshake ID used to be compiled from five separate boxes (Dialing Out Location, Text Name, Plugin Code, Gate Address, Location). It is now a single field so it is never re-compiled &mdash; what you paste is exactly what gets sent. The pieces still, by convention, read as
			<code>PLACE-NUMBER-PLUGIN-GATE-COLUMN</code>:</p>
			<table class="kv">
				<tr><th>Piece</th><th>Old field</th><th>Example</th></tr>
				<tr><td>Place</td><td>Dialing Out Location</td><td><code>WP</code> (WordPress)</td></tr>
				<tr><td>Number</td><td>Text Name (hashed)</td><td><code>12345</code></td></tr>
				<tr><td>Plugin</td><td>Plugin Code</td><td><code>SD</code> (Staff Directory)</td></tr>
				<tr><td>Gate</td><td>Gate Address</td><td><code>123456789</code></td></tr>
				<tr><td>Column</td><td>Location</td><td><code>F</code> stops before the ID column; <code>E</code> includes the Identification numbers (needed to match manual edits) and Tags</td></tr>
			</table>
			<p>The <strong>Handshake ID in use</strong> box on the Settings page always shows exactly what will be sent. When you upgraded, your old five fields were combined automatically into this one value, so nothing broke.</p>
		</div>

		<div class="card">
			<h2 id="sync">How syncing works</h2>
			<p>The plugin caches the directory in WordPress and refreshes it <strong>automatically once a day</strong>, plus whenever you press <strong>Sync now</strong>. The Settings page shows the last sync time and result.</p>
			<ul>
				<li>Syncing <strong>never touches manual overrides</strong> &mdash; hand edits always win and are kept.</li>
				<li>If the spreadsheet comes back empty, the plugin <strong>keeps the previous data</strong> rather than wiping the directory, and says so in the sync message.</li>
				<li>After a sync the plugin flushes caches (including WP Engine) so logged-out visitors see the update.</li>
			</ul>
		</div>

		<div class="card">
			<h2 id="editing">Editing people (manual overrides)</h2>
			<p>Logged-in administrators see an <strong>Edit</strong> button on every row of the public directory. Saving an edit stores that person as a <strong>manual override</strong>:</p>
			<ul>
				<li>Overrides are <strong>permanent</strong> and always beat the synced data &mdash; the daily sync can never change or delete them.</li>
				<li><strong>First name</strong> and <strong>Last name</strong> combine into the displayed name (formatted by <a href="#display">Column display</a>).</li>
				<li><strong>Title</strong> is what shows publicly; <strong>Job</strong> is the HR title that drives sorting (case- and spelling-sensitive).</li>
				<li>Tick <strong>Hide from directory</strong> to remove someone from the public list; editors still see them with a <em>Hidden</em> badge and can un-hide later.</li>
				<li>To hand a row back to the automatic data, open it and choose <strong>Remove manual override</strong>. You can also remove overrides from the list at the bottom of the Settings page.</li>
			</ul>
		</div>

		<div class="card">
			<h2 id="sorting">Sort rules</h2>
			<p>Under <strong>Sort rules</strong> you build an ordered list. The first rule sorts everyone; each rule below only breaks the ties left above it (so two &ldquo;priority&rdquo; rules on Job then Location give the classic order). Each rule has a <strong>field</strong> and a <strong>mode</strong>:</p>
			<ul>
				<li><strong>Custom priority list</strong> &mdash; type the order, one label per line, highest first. Anything not listed sinks to the bottom (a handy way to spot typos).</li>
				<li><strong>A &rarr; Z</strong> / <strong>Z &rarr; A</strong> &mdash; alphabetical either direction.</li>
			</ul>
			<p>Drag the handle to reorder, <strong>Remove</strong> to drop a level, <strong>+ Add sort rule</strong> for more. Remove every rule to leave people in their synced order. Priority labels must match the displayed text for that field (case doesn&rsquo;t matter).</p>
		</div>

		<div class="card">
			<h2 id="columns">Layout &amp; columns</h2>
			<p><strong>Layout</strong> chooses Table or Cards. <strong>Columns to show</strong> picks which fields appear (Name is always shown). <strong>Row behaviour</strong> toggles hover highlighting and selectable rows. A Beaver Builder module or shortcode <code>layout=""</code> attribute can override the layout per placement.</p>
		</div>

		<div class="card">
			<h2 id="display">Column display &amp; the First/Last name split</h2>
			<p>People now have a <strong>First name</strong> and a <strong>Last name</strong> (the sheet can send them as separate columns, and the Edit dialog has both). They are combined for display, so by default the directory reads exactly as before.</p>
			<p><strong>Column display</strong> (in Settings) lets you build exactly what each column shows. A template can mix plain text, <strong>HTML</strong>, and these building blocks:</p>
			<table class="kv">
				<tr><th>Block</th><th>Does</th></tr>
				<tr><td><code>{field}</code></td><td>Inserts that field&rsquo;s value.</td></tr>
				<tr><td><code>{field|fallback}</code></td><td>The value, or the <em>fallback</em> text when the field is empty.</td></tr>
				<tr><td><code>[if field]&hellip;[/if]</code></td><td>Shows the block only when the field has a value.</td></tr>
				<tr><td><code>[if field]&hellip;[else]&hellip;[/if]</code></td><td>Shows one thing or the other.</td></tr>
				<tr><td><code>[if field == value]</code></td><td>Shows the block only when the field equals <em>value</em> (case-insensitive; use quotes for values with spaces).</td></tr>
				<tr><td><code>[if field != value]</code></td><td>&hellip; when the field does <strong>not</strong> equal <em>value</em>.</td></tr>
				<tr><td><code>[if field contains value]</code></td><td>&hellip; when the field contains <em>value</em>.</td></tr>
			</table>
			<p>Fields you can use: <code>{firstname}</code> <code>{lastname}</code> <code>{name}</code> <code>{publictitle}</code> <code>{job}</code> <code>{location}</code> <code>{email}</code> <code>{id}</code> <code>{tags}</code> <code>{initials}</code> <code>{photo_url}</code>.</p>
			<p><strong>Examples:</strong></p>
			<ul>
				<li><code>{lastname}, {firstname}</code> &rarr; &ldquo;Doe, Jane&rdquo; (the stray comma disappears when there is no last name).</li>
				<li><code>&lt;strong&gt;{name}&lt;/strong&gt;</code> &rarr; the name in bold.</li>
				<li><code>[if publictitle]{publictitle}[else]{job}[/if]</code> &rarr; the public title, or the job when there is no title.</li>
				<li><code>[if location]📍 {location}[/if]</code> &rarr; a pin and the location, or nothing.</li>
				<li><code>&lt;a href="mailto:{email}"&gt;Email {firstname}&lt;/a&gt;</code> &rarr; a custom email link.</li>
				<li><code>[if job == Principal]&lt;strong&gt;{name}&lt;/strong&gt;[else]{name}[/if]</code> &rarr; bold the principals only.</li>
				<li><code>[if location contains Elementary]🍎 {location}[else]{location}[/if]</code> &rarr; an apple for elementary schools.</li>
			</ul>
			<p>Each template box has a row of field buttons that insert a <code>{field}</code> at your cursor, and a <strong>live preview</strong> that updates as you type. Leave a box blank and save to restore its default.</p>
			<p><strong>Safety:</strong> a person&rsquo;s data is always escaped, so nothing in the spreadsheet can break your layout or inject code; only safe HTML in the template itself is kept (scripts are removed). Search still matches the full name, first name, last name and every other field no matter how the columns are displayed.</p>
		</div>

		<div class="card">
			<h2 id="css">Colours &amp; Custom CSS</h2>
			<p>The <strong>Colours</strong> pickers set the theme colours. They are applied as <code>--CAYDENDIR-*</code> CSS variables directly on each directory, so they always win over the stylesheet.</p>
			<p>The <strong>Custom CSS</strong> box <em>is</em> the directory&rsquo;s stylesheet &mdash; edit it to restyle anything without touching files. It is pre-filled with the built-in defaults; <strong>clear it and save to restore them</strong>.</p>
		</div>

		<div class="card">
			<h2 id="trouble">Troubleshooting &mdash; steps to fix</h2>

			<h3>&ldquo;Sync failed&rdquo; or an error after Sync now</h3>
			<div class="fix">
				<p><strong>1.</strong> Check the <strong>Apps Script Web App URL</strong> ends in <code>/exec</code> and the deployment is set to &ldquo;Anyone&rdquo; access.</p>
				<p><strong>2.</strong> Confirm the <strong>Encrypter Key</strong> here matches the key in the Apps Script exactly (no stray spaces).</p>
				<p><strong>3.</strong> Confirm the <strong>Handshake ID</strong> matches what the Apps Script expects &mdash; compare it to the <strong>Handshake ID in use</strong> box.</p>
				<p><strong>4.</strong> Re-deploy the Apps Script (a new version) if you changed it, then press <strong>Sync now</strong> again. The sync message names the exact HTTP error.</p>
			</div>

			<h3>Directory shows &ldquo;hasn&rsquo;t been synced yet&rdquo; / is empty</h3>
			<div class="fix">
				<p><strong>1.</strong> Press <strong>Sync now</strong>. If it succeeds with <em>0 records</em>, the spreadsheet the Apps Script reads is empty or the wrong tab.</p>
				<p><strong>2.</strong> If a person is missing, check they aren&rsquo;t marked <strong>Hidden</strong> (editors see hidden rows with a badge).</p>
				<p><strong>3.</strong> Empty syncs deliberately keep the last good data, so a working directory won&rsquo;t suddenly blank out.</p>
			</div>

			<h3>Photos aren&rsquo;t showing (initials appear instead)</h3>
			<div class="fix">
				<p><strong>1.</strong> The photo must be a Google Drive file ID, a Drive link, or an <code>lh3.googleusercontent</code> URL.</p>
				<p><strong>2.</strong> The Drive file must be shared so anyone with the link can view it.</p>
				<p><strong>3.</strong> Edit the person and paste a clean Drive link into the <strong>Photo</strong> field; blank shows initials by design.</p>
			</div>

			<h3>My edits keep &ldquo;disappearing&rdquo; after a sync</h3>
			<div class="fix">
				<p>They shouldn&rsquo;t &mdash; overrides are permanent. If an edit isn&rsquo;t sticking, make sure you clicked <strong>Save changes</strong> (not just closed the dialog), and that you&rsquo;re logged in as an administrator. Check the person appears in <strong>Manual overrides</strong> at the bottom of Settings.</p>
			</div>

			<h3>People are in the wrong order</h3>
			<div class="fix">
				<p><strong>1.</strong> Order is driven by <a href="#sorting">Sort rules</a>, not the spreadsheet. A person at the very bottom usually means their Job/Location text doesn&rsquo;t match any label in a priority list (check spelling).</p>
				<p><strong>2.</strong> Remember sorting is on the <strong>Job</strong> field, not the displayed <strong>Title</strong>.</p>
			</div>

			<h3>The directory looks unstyled / my CSS change broke it</h3>
			<div class="fix">
				<p><strong>1.</strong> Open <strong>Custom CSS</strong>, clear the box completely, and <strong>Save settings</strong> &mdash; this restores the built-in default stylesheet.</p>
				<p><strong>2.</strong> Re-apply your changes a bit at a time to find the line that broke it.</p>
			</div>

			<h3>The Beaver Builder module isn&rsquo;t in the list</h3>
			<div class="fix">
				<p><strong>1.</strong> Beaver Builder must be active. <?php echo $bb_active ? 'It is active on this site.' : '<strong>It is not active on this site right now.</strong>'; ?></p>
				<p><strong>2.</strong> Look under the <strong>Caydens Plugins</strong> category (or use the module search) in the content panel.</p>
				<p><strong>3.</strong> The shortcode always works as a fallback.</p>
			</div>

			<h3>Logged-out visitors don&rsquo;t see my change</h3>
			<div class="fix">
				<p>That&rsquo;s caching. Editing and syncing both flush the plugin&rsquo;s caches automatically, but if your host or a CDN caches pages, clear that cache too, then reload in a private window.</p>
			</div>
		</div>

		<p><a href="<?php echo esc_url( $settings_url ); ?>" class="button">&larr; Back to Settings</a></p>
	</div>
	<?php
}
