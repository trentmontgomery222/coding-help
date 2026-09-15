<?php
/**
 * WPCode snippet #4 — "Search: filter rules admin"
 *
 * Code Type:     PHP Snippet
 * Location:      Run Everywhere
 * Priority:      11   (must run after snippet #1)
 *
 * Gives you a real UI for the filtering instead of editing code:
 *
 *   • Settings → Search Filters — a page for manual rules: specific post IDs,
 *     URL fragments, title keywords, title rewrites, and the display options.
 *   • A "Hide from search results" checkbox on every post/page edit screen.
 *   • A "Hide from search" / "Show in search" row action in the posts list.
 *   • Bulk actions to flag or unflag many posts at once.
 *
 * Everything written here flows into the same window.ACPS_SEARCH payload the
 * JS already reads, so nothing in snippet #2 needs editing to add a rule.
 *
 * NOTE: do NOT paste the opening <?php tag into WPCode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! defined( 'ACPS_HIDE_META_KEY' ) ) {
	return; // Snippet #1 isn't active — nothing to hang this off.
}

if ( ! defined( 'ACPS_RULES_OPTION' ) ) {
	define( 'ACPS_RULES_OPTION', 'acps_search_filter_rules' );
}

/* =====================================================================
 * Stored rules
 * ===================================================================== */

function acps_search_rule_defaults() {
	return array(
		'manual_ids'     => array(),
		'block_urls'     => array(),
		'block_titles'   => array(),
		'title_rewrites' => array(),
		'hide_mode'      => 'remove',
		'admin_preview'  => 1,
		'empty_message'  => 'No matching results. Try a different search term.',
	);
}

function acps_search_get_rules() {
	$rules = get_option( ACPS_RULES_OPTION, array() );

	return wp_parse_args( is_array( $rules ) ? $rules : array(), acps_search_rule_defaults() );
}

/** Turn a textarea into a clean array, one entry per line. */
function acps_search_lines_to_array( $text ) {
	$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
	$out   = array();

	foreach ( $lines as $line ) {
		$line = trim( sanitize_text_field( $line ) );
		if ( '' !== $line ) {
			$out[] = $line;
		}
	}

	return array_values( array_unique( $out ) );
}

function acps_search_array_to_lines( $value ) {
	return implode( "\n", is_array( $value ) ? $value : array() );
}

function acps_search_rewrites_to_lines( $rewrites ) {
	$lines = array();
	foreach ( (array) $rewrites as $rule ) {
		if ( isset( $rule['match'] ) ) {
			$lines[] = $rule['match'] . ' => ' . ( isset( $rule['replace'] ) ? $rule['replace'] : '' );
		}
	}

	return implode( "\n", $lines );
}

function acps_search_sanitize_rules( $input ) {
	$clean = acps_search_rule_defaults();

	$ids = acps_search_lines_to_array( isset( $input['manual_ids'] ) ? $input['manual_ids'] : '' );
	foreach ( $ids as $id ) {
		// Accept a bare ID or a pasted permalink.
		if ( preg_match( '/(\d+)/', $id, $m ) && get_post( (int) $m[1] ) ) {
			$clean['manual_ids'][] = (int) $m[1];
		} elseif ( filter_var( $id, FILTER_VALIDATE_URL ) ) {
			$found = url_to_postid( $id );
			if ( $found ) {
				$clean['manual_ids'][] = $found;
			}
		}
	}
	$clean['manual_ids'] = array_values( array_unique( $clean['manual_ids'] ) );

	$clean['block_urls']   = acps_search_lines_to_array( isset( $input['block_urls'] ) ? $input['block_urls'] : '' );
	$clean['block_titles'] = acps_search_lines_to_array( isset( $input['block_titles'] ) ? $input['block_titles'] : '' );

	foreach ( acps_search_lines_to_array( isset( $input['title_rewrites'] ) ? $input['title_rewrites'] : '' ) as $line ) {
		$parts = array_map( 'trim', explode( '=>', $line, 2 ) );
		if ( '' !== $parts[0] ) {
			$clean['title_rewrites'][] = array(
				'match'   => $parts[0],
				'replace' => isset( $parts[1] ) ? $parts[1] : '',
			);
		}
	}

	$mode                   = isset( $input['hide_mode'] ) ? $input['hide_mode'] : 'remove';
	$clean['hide_mode']     = in_array( $mode, array( 'remove', 'dim' ), true ) ? $mode : 'remove';
	$clean['admin_preview'] = empty( $input['admin_preview'] ) ? 0 : 1;
	$clean['empty_message'] = sanitize_text_field( isset( $input['empty_message'] ) ? $input['empty_message'] : '' );

	acps_search_flush_hidden_map();

	return $clean;
}

/* =====================================================================
 * Feed the rules into the front end
 * ===================================================================== */

/** Manual IDs join the meta-flagged ones. */
function acps_search_add_manual_ids( $map ) {
	$rules = acps_search_get_rules();

	foreach ( $rules['manual_ids'] as $id ) {
		if ( in_array( (int) $id, $map['ids'], true ) ) {
			continue;
		}

		$map['ids'][] = (int) $id;

		$path = wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
		if ( $path ) {
			$map['paths'][] = untrailingslashit( $path );
		}
	}

	$map['ids']   = array_values( array_unique( $map['ids'] ) );
	$map['paths'] = array_values( array_unique( $map['paths'] ) );

	return $map;
}
add_filter( 'acps_search_hidden_map', 'acps_search_add_manual_ids' );

/** Keyword/URL/display rules ride along in the same payload. */
function acps_search_add_rules_to_bridge( $data ) {
	$rules = acps_search_get_rules();

	$data['rules'] = array(
		'blockUrlContains'   => $rules['block_urls'],
		'blockTitleContains' => $rules['block_titles'],
		'titleRewrites'      => $rules['title_rewrites'],
		'hideMode'           => $rules['hide_mode'],
		'adminSeesHidden'    => (bool) $rules['admin_preview'],
		'emptyMessage'       => $rules['empty_message'],
	);

	return $data;
}
add_filter( 'acps_search_bridge_data', 'acps_search_add_rules_to_bridge' );

/* =====================================================================
 * Everything below is admin-only
 * ===================================================================== */

if ( ! is_admin() ) {
	return;
}

function acps_search_hide_post_types() {
	return array_map( 'trim', explode( ',', ACPS_HIDE_POST_TYPES ) );
}

function acps_search_is_hidden( $post_id ) {
	$value = get_post_meta( $post_id, ACPS_HIDE_META_KEY, true );

	return ( '' === ACPS_HIDE_META_VALUE ) ? ( '' !== $value ) : ( (string) $value === (string) ACPS_HIDE_META_VALUE );
}

function acps_search_set_hidden( $post_id, $hidden ) {
	if ( $hidden ) {
		update_post_meta( $post_id, ACPS_HIDE_META_KEY, ( '' === ACPS_HIDE_META_VALUE ) ? '1' : ACPS_HIDE_META_VALUE );
	} else {
		delete_post_meta( $post_id, ACPS_HIDE_META_KEY );
	}

	acps_search_flush_hidden_map();
}

/* ---- Settings page ------------------------------------------------- */

function acps_search_register_settings() {
	register_setting(
		'acps_search_filters',
		ACPS_RULES_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'acps_search_sanitize_rules',
			'default'           => acps_search_rule_defaults(),
		)
	);
}
add_action( 'admin_init', 'acps_search_register_settings' );

function acps_search_add_settings_page() {
	add_options_page(
		'Search Filters',
		'Search Filters',
		'manage_options',
		'acps-search-filters',
		'acps_search_render_settings_page'
	);
}
add_action( 'admin_menu', 'acps_search_add_settings_page' );

function acps_search_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$rules = acps_search_get_rules();
	$map   = acps_search_get_hidden_map();
	?>
	<div class="wrap">
		<h1>Search Filters</h1>
		<p class="description" style="max-width:46em">
			Rules applied to the search results page in the browser. They tidy up what
			visitors see &mdash; they do not remove anything from the search index, so
			don't rely on them for genuinely private content.
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'acps_search_filters' ); ?>

			<h2>Manual rules</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="acps-manual-ids">Hide these posts</label></th>
					<td>
						<textarea id="acps-manual-ids" name="<?php echo esc_attr( ACPS_RULES_OPTION ); ?>[manual_ids]"
							rows="5" cols="50" class="large-text code"
							placeholder="123&#10;https://example.org/some-page/"><?php
							echo esc_textarea( acps_search_array_to_lines( $rules['manual_ids'] ) );
						?></textarea>
						<p class="description">One post ID or full URL per line. Pasted URLs are converted to IDs on save.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-block-urls">Hide URLs containing</label></th>
					<td>
						<textarea id="acps-block-urls" name="<?php echo esc_attr( ACPS_RULES_OPTION ); ?>[block_urls]"
							rows="5" cols="50" class="large-text code"
							placeholder="/staff-only/&#10;/archive/2019/"><?php
							echo esc_textarea( acps_search_array_to_lines( $rules['block_urls'] ) );
						?></textarea>
						<p class="description">One fragment per line. Any result whose link contains it is filtered out.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-block-titles">Hide titles containing</label></th>
					<td>
						<textarea id="acps-block-titles" name="<?php echo esc_attr( ACPS_RULES_OPTION ); ?>[block_titles]"
							rows="5" cols="50" class="large-text code"
							placeholder="Internal&#10;Draft &mdash;"><?php
							echo esc_textarea( acps_search_array_to_lines( $rules['block_titles'] ) );
						?></textarea>
						<p class="description">One phrase per line. Case-insensitive.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-rewrites">Rewrite titles</label></th>
					<td>
						<textarea id="acps-rewrites" name="<?php echo esc_attr( ACPS_RULES_OPTION ); ?>[title_rewrites]"
							rows="5" cols="50" class="large-text code"
							placeholder="ACPS - =&gt; &#10;Dept. =&gt; Department"><?php
							echo esc_textarea( acps_search_rewrites_to_lines( $rules['title_rewrites'] ) );
						?></textarea>
						<p class="description">
							One rule per line as <code>find =&gt; replace</code>. Leave the right side empty to delete the text.
							Cosmetic only &mdash; the underlying post is untouched.
						</p>
					</td>
				</tr>
			</table>

			<h2>Display</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Filtered results</th>
					<td>
						<label>
							<input type="radio" name="<?php echo esc_attr( ACPS_RULES_OPTION ); ?>[hide_mode]"
								value="remove" <?php checked( $rules['hide_mode'], 'remove' ); ?>>
							Remove from the page
						</label><br>
						<label>
							<input type="radio" name="<?php echo esc_attr( ACPS_RULES_OPTION ); ?>[hide_mode]"
								value="dim" <?php checked( $rules['hide_mode'], 'dim' ); ?>>
							Grey out and label them <em>(useful while testing your rules)</em>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Editors</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( ACPS_RULES_OPTION ); ?>[admin_preview]"
								value="1" <?php checked( $rules['admin_preview'], 1 ); ?>>
							Let logged-in editors still see filtered results, marked &ldquo;Hidden from visitors&rdquo;
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-empty">No-results message</label></th>
					<td>
						<input type="text" id="acps-empty" class="regular-text"
							name="<?php echo esc_attr( ACPS_RULES_OPTION ); ?>[empty_message]"
							value="<?php echo esc_attr( $rules['empty_message'] ); ?>">
						<p class="description">Shown when every result on the page was filtered away.</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2>Currently hidden <span class="count">(<?php echo count( $map['ids'] ); ?>)</span></h2>
		<?php if ( empty( $map['ids'] ) ) : ?>
			<p>Nothing is being hidden yet.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:60em">
				<thead>
					<tr><th>Title</th><th>Type</th><th>Source</th><th></th></tr>
				</thead>
				<tbody>
				<?php
				foreach ( $map['ids'] as $id ) :
					$post = get_post( $id );
					if ( ! $post ) {
						continue;
					}
					$source = acps_search_is_hidden( $id ) ? 'Hidden flag' : 'Manual list';
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
						</td>
						<td><?php echo esc_html( get_post_type( $id ) ); ?></td>
						<td><?php echo esc_html( $source ); ?></td>
						<td>
							<a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank" rel="noopener">View</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/* ---- Per-post checkbox --------------------------------------------- */

function acps_search_add_meta_box() {
	add_meta_box(
		'acps-search-hide',
		'Search visibility',
		'acps_search_render_meta_box',
		acps_search_hide_post_types(),
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'acps_search_add_meta_box' );

function acps_search_render_meta_box( $post ) {
	wp_nonce_field( 'acps_search_hide_' . $post->ID, 'acps_search_hide_nonce' );
	?>
	<label>
		<input type="checkbox" name="acps_search_hide" value="1" <?php checked( acps_search_is_hidden( $post->ID ) ); ?>>
		Hide from search results
	</label>
	<p class="description" style="margin-top:.5em">
		The page stays published and reachable by direct link &mdash; it just won't be
		listed on the search results page.
	</p>
	<?php
}

function acps_search_save_meta_box( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	$nonce = isset( $_POST['acps_search_hide_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['acps_search_hide_nonce'] ) ) : '';
	if ( ! $nonce || ! wp_verify_nonce( $nonce, 'acps_search_hide_' . $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	acps_search_set_hidden( $post_id, ! empty( $_POST['acps_search_hide'] ) );
}
add_action( 'save_post', 'acps_search_save_meta_box' );

/* ---- Row action in the posts list ---------------------------------- */

function acps_search_row_action( $actions, $post ) {
	if ( ! in_array( $post->post_type, acps_search_hide_post_types(), true ) ) {
		return $actions;
	}

	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return $actions;
	}

	$hidden = acps_search_is_hidden( $post->ID );

	$url = wp_nonce_url(
		add_query_arg(
			array(
				'acps_toggle_hide' => $post->ID,
				'acps_hide_to'     => $hidden ? '0' : '1',
			),
			admin_url( 'edit.php?post_type=' . $post->post_type )
		),
		'acps_toggle_hide_' . $post->ID
	);

	$actions['acps_hide'] = sprintf(
		'<a href="%s">%s</a>',
		esc_url( $url ),
		$hidden ? 'Show in search' : 'Hide from search'
	);

	return $actions;
}
add_filter( 'post_row_actions', 'acps_search_row_action', 10, 2 );
add_filter( 'page_row_actions', 'acps_search_row_action', 10, 2 );

function acps_search_handle_row_action() {
	if ( empty( $_GET['acps_toggle_hide'] ) ) {
		return;
	}

	$post_id = (int) $_GET['acps_toggle_hide'];
	$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'acps_toggle_hide_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( 'Not allowed.' );
	}

	acps_search_set_hidden( $post_id, ! empty( $_GET['acps_hide_to'] ) );

	wp_safe_redirect( remove_query_arg( array( 'acps_toggle_hide', 'acps_hide_to', '_wpnonce' ) ) );
	exit;
}
add_action( 'admin_init', 'acps_search_handle_row_action' );

/* ---- Bulk actions --------------------------------------------------- */

function acps_search_register_bulk_actions() {
	foreach ( acps_search_hide_post_types() as $type ) {
		add_filter( "bulk_actions-edit-{$type}", 'acps_search_bulk_actions' );
		add_filter( "handle_bulk_actions-edit-{$type}", 'acps_search_handle_bulk', 10, 3 );
	}
}
add_action( 'admin_init', 'acps_search_register_bulk_actions' );

function acps_search_bulk_actions( $actions ) {
	$actions['acps_hide']   = 'Hide from search';
	$actions['acps_unhide'] = 'Show in search';

	return $actions;
}

function acps_search_handle_bulk( $redirect, $action, $post_ids ) {
	if ( 'acps_hide' !== $action && 'acps_unhide' !== $action ) {
		return $redirect;
	}

	$count = 0;
	foreach ( $post_ids as $post_id ) {
		if ( current_user_can( 'edit_post', $post_id ) ) {
			acps_search_set_hidden( $post_id, 'acps_hide' === $action );
			$count++;
		}
	}

	return add_query_arg( 'acps_bulk_done', $count, $redirect );
}

function acps_search_bulk_notice() {
	if ( ! isset( $_GET['acps_bulk_done'] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-success is-dismissible"><p>Search visibility updated for %d item(s).</p></div>',
		(int) $_GET['acps_bulk_done']
	);
}
add_action( 'admin_notices', 'acps_search_bulk_notice' );

/* ---- "Hidden" column in the posts list ------------------------------ */

function acps_search_register_columns() {
	foreach ( acps_search_hide_post_types() as $type ) {
		add_filter( "manage_{$type}_posts_columns", 'acps_search_add_column' );
		add_action( "manage_{$type}_posts_custom_column", 'acps_search_render_column', 10, 2 );
	}
}
add_action( 'admin_init', 'acps_search_register_columns' );

function acps_search_add_column( $columns ) {
	$columns['acps_search'] = 'Search';

	return $columns;
}

function acps_search_render_column( $column, $post_id ) {
	if ( 'acps_search' !== $column ) {
		return;
	}

	echo acps_search_is_hidden( $post_id )
		? '<span style="color:#d63638" title="Hidden from search results">&#9679; Hidden</span>'
		: '<span style="color:#8c8f94">&mdash;</span>';
}
