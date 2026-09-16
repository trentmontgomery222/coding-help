# Staff Directory → WPSearch Quick Results

**A brief for whoever is modifying the staff directory plugin.**

You are changing an existing WordPress staff directory plugin so that people
appear as results in the site's search. Your side of the work is one filter.
The search plugin — WPSearch Quick Results — handles caching, rendering,
escaping and layout. You supply data.

Do not modify the search plugin. Everything below is already implemented and
waiting on the other side.

---

## Why this exists

Today a search for a staff member's name returns the *Staff Directory page*,
with an excerpt that reads:

> Staff Directory Search the directory Photo Name Title Job Location Email
> Edit Aaron Kerr Edited Hidden Warehouse Driver WAREHOUSE DRIVER Operations…

That is the directory's admin interface text leaking into public search
results, and it does not answer the question the visitor asked. After this
change, searching a name returns **that person**.

---

## What you implement

One filter. Return an array of people whose **name** matches the query.

```php
add_filter( 'wpsqr_people_search', 'acps_directory_search_people', 10, 2 );

/**
 * @param array $people Always starts empty. Append your matches.
 * @param array $args   query, limit, fields, viewer.
 * @return array
 */
function acps_directory_search_people( $people, $args ) {
	$query = trim( (string) $args['query'] );
	$limit = (int) $args['limit'];

	// … your lookup here …

	return $people;
}
```

### `$args`

| Key | Type | Meaning |
|---|---|---|
| `query` | string | Raw search term, as typed. Not normalized or escaped. |
| `limit` | int | Maximum people to return. Returning more is not an error; the extras are discarded. |
| `fields` | string[] | Which fields to match on. **Currently always `['name']`** — see Scope below. |
| `viewer` | string | `'visitor'` or `'editor'`. See Visibility. |

### What a person looks like

```php
$people[] = array(
	'id'         => 'WP-1-SD-1-E',                         // your record ID, as a string
	'name'       => 'Aaron Kerr',                          // required
	'url'        => 'https://…/staff/directory/aaron-kerr/', // strongly preferred
	'job_title'  => 'Warehouse Driver',                    // optional
	'department' => 'Operations',                          // optional
	'location'   => 'Transportation Center',               // optional
	'photo'      => 'https://…/uploads/2026/01/kerr.jpg',  // optional, square works best
	'email'      => 'aaron.kerr@acpsmd.org',               // optional — see Contact details
	'phone'      => '(301) 555-0100',                      // optional — see Contact details
);
```

- `name` is the only truly required field. A row without one is dropped.
- **`id` is an opaque string.** Whatever identifies the record in your
  storage — a post ID, a merge key, `WP-1-SD-1-E`. It is never parsed, cast or
  assumed numeric; it is used to recognise the same person appearing twice, so
  it only needs to be stable and unique within your directory. Sending none is
  fine; de-duplication falls back to the URL, then the name.
- `url` should be the person's own profile page if one exists, otherwise a
  deep link into the directory that lands on them. Without it the name renders
  as plain text, which is a much weaker result.
- Return plain strings. Do not escape, do not wrap in HTML, do not add
  highlight markup. The search plugin escapes everything and adds its own.
- Unknown keys are discarded. Send what you have; omit what you don't.

---

## Rules you must follow

### 1. Visibility — the important one

**Only return people who are visible to the public.** Apply exactly the same
visibility logic the directory's own public listing applies. If a person is
hidden, unlisted, draft, private, retired, or opted out, they must not appear
here.

That word *Hidden* in the excerpt above is a real record in this directory
that admins can see and visitors cannot. Getting this wrong publishes someone
who asked not to be published.

The `viewer` argument is `'editor'` for a logged-in user who can edit posts,
`'visitor'` for everyone else. **When in doubt, return the visitor-safe set
for both.** Showing an editor slightly less than they could see is a cosmetic
problem; the reverse is a disclosure. Results are cached separately per
viewer bucket, so returning different sets is safe — just not required.

### 2. Name matching only, for now

Match against the person's name and nothing else. First name, last name, full
name, and sensible partials:

| Query | Should match "Aaron Kerr" |
|---|---|
| `aaron` | yes |
| `kerr` | yes |
| `aaron kerr` | yes |
| `kerr aaron` | yes — try reversed order |
| `aar` | yes — prefix of a name part |
| `a` | no — the plugin filters terms under 3 characters before calling you |
| `warehouse driver` | **no** — that is a job title, not a name |

Do not match job titles, departments, locations or bios yet. A search for
"driver" returning forty people is worse than returning none, and job-title
matching is a separate decision with its own ranking problem. The `fields`
argument exists so this can be widened later without changing the contract.

Matching should be case-insensitive and should ignore accents if your data has
them.

### 3. Order by relevance

Best matches first. A reasonable order:

1. Exact full-name match
2. Exact last-name match
3. Exact first-name match
4. Prefix match on either name part
5. Anything else

The search plugin preserves your order exactly.

### 4. Stay fast

This runs on a page a visitor is waiting for. Results are cached for ten
minutes, but a cache miss should still be quick — aim under 50ms.

- Query the database directly, with an index on the name column.
- Do not load every person and filter in PHP.
- Do not make HTTP requests.
- Do not call `get_post_meta()` in a loop over hundreds of records.

### 5. Tell the plugin when your data changes

So cached people results don't go stale after an edit:

```php
do_action( 'wpsqr_people_changed' );
```

Fire it whenever a person is added, edited, deleted, hidden or unhidden. It is
cheap; fire it more often rather than less.

### 6. Point at your own directory page, if you have one

Optional. The people block can show a "Search the full staff directory" link
under the results. There is a setting for the URL, but you almost certainly
know your own page better than the setting does — you can find the one
carrying your shortcode — so you get first refusal:

```php
add_filter( 'wpsqr_people_more_url', function ( $url, $term ) {
	$page = acps_directory_find_page(); // however you locate it

	return $page
		? add_query_arg( 'sd_name', rawurlencode( $term ), $page )
		: $url;
}, 10, 2 );
```

Return the configured `$url` unchanged to leave the setting in charge, or `''`
for no link at all.

### 7. Identify yourself

So the admin screen can confirm the integration is live:

```php
add_filter( 'wpsqr_people_providers', function ( $providers ) {
	$providers[] = array(
		'name'     => 'ACPS Staff Directory',
		'version'  => ACPS_DIRECTORY_VERSION,
		'contract' => 1,
	);

	return $providers;
} );
```

Without this, **Quick Results → Dashboard** shows "No directory plugin
connected" even if your filter works — it has no other way to tell the
difference between "not installed" and "installed but matched nobody".

### 8. Degrade quietly

If the search plugin isn't active, your filter simply never runs — nothing to
guard. But do not make your directory plugin *depend* on it: no fatal errors,
no admin notices demanding it, no disabled features. The two must work apart.

---

## Reference implementation

A working shape, assuming people are a `staff` custom post type with a
`_acps_hidden` meta flag. Adapt to the real storage.

```php
add_filter( 'wpsqr_people_search', 'acps_directory_search_people', 10, 2 );

function acps_directory_search_people( $people, $args ) {
	global $wpdb;

	$query = trim( (string) $args['query'] );
	$limit = max( 1, (int) $args['limit'] );

	if ( mb_strlen( $query ) < 3 ) {
		return $people;
	}

	$like = '%' . $wpdb->esc_like( $query ) . '%';

	// Also try the query reversed, so "kerr aaron" finds "Aaron Kerr".
	$parts    = preg_split( '/\s+/', $query );
	$reversed = ( count( $parts ) === 2 ) ? $parts[1] . ' ' . $parts[0] : $query;
	$like_rev = '%' . $wpdb->esc_like( $reversed ) . '%';

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_title
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} m
			          ON m.post_id = p.ID AND m.meta_key = '_acps_hidden'
			  WHERE p.post_type = 'staff'
			    AND p.post_status = 'publish'
			    AND m.meta_id IS NULL                 -- visibility: never relax this
			    AND ( p.post_title LIKE %s OR p.post_title LIKE %s )
			  ORDER BY
			    CASE
			      WHEN p.post_title = %s THEN 0       -- exact full name
			      WHEN p.post_title LIKE %s THEN 1    -- starts with
			      ELSE 2
			    END,
			    p.post_title ASC
			  LIMIT %d",
			$like,
			$like_rev,
			$query,
			$wpdb->esc_like( $query ) . '%',
			$limit
		)
	);

	foreach ( $rows as $row ) {
		$people[] = array(
			'id'         => (int) $row->ID,
			'name'       => $row->post_title,
			'url'        => get_permalink( $row->ID ),
			'job_title'  => get_post_meta( $row->ID, 'job_title', true ),
			'department' => get_post_meta( $row->ID, 'department', true ),
			'location'   => get_post_meta( $row->ID, 'location', true ),
			'photo'      => get_the_post_thumbnail_url( $row->ID, 'thumbnail' ),
		);
	}

	return $people;
}
```

Note the `m.meta_id IS NULL` join. Doing visibility in SQL rather than
filtering afterwards means a mistake later in the function can't accidentally
reveal a hidden person.

---

## Contact details

Email and phone are **opt-in on the search side** and default to off. Send
them if you have them; the site decides whether they are displayed. Do not
work around this.

The reasoning: a directory page publishing an email is a decision about that
page. Scattering the same address across search results — indexed, cached,
shown to anyone who types a common surname — is a different decision, and it
belongs to whoever runs the site.

---

## How to test

1. Activate both plugins.
2. **Quick Results → Dashboard**, Status panel: *Staff directory* should show
   your plugin's name and version.
3. Search a staff member's surname. They appear above the normal results.
4. Search a partial: `kerr` and `ker` both find Aaron Kerr.
5. Search reversed: `kerr aaron` finds him.
6. Search a job title: `warehouse driver` returns **no** people.
7. Hide someone in the directory, then search their name — nothing. Fire
   `wpsqr_people_changed` on hide, or you'll be looking at a ten-minute cache.
8. Log out entirely and repeat step 7. This is the test that matters.
9. Search two characters: no people, no database query.
10. Deactivate the search plugin: the directory still works normally.

---

## Scope

**In scope now:** name matching, visible people, relevance order, cache
invalidation, provider identification.

**Deliberately not yet:** job title and department matching, nicknames and
aliases, fuzzy or phonetic matching, school filters, pagination. The `fields`
argument is the extension point for all of it — the contract does not need to
change to add them.

When we do widen it, `fields` will carry these names, so both sides agree in
advance rather than negotiating it later:

| `fields` entry | Matches against |
|---|---|
| `name` | Person's name. The only one sent today. |
| `job_title` | Job or public title |
| `department` | Department or office |
| `location` | School or building |
| `tags` | Whatever free-form tags the directory keeps |

Ignore any entry you have no data for; do not guess a near-match. `fields` is
always a list, and `name` will always be in it.

**Contract version 1.** If it has to change incompatibly, the version number
goes up and both sides check it.

### Changes since first issue

Both clarifications, not contract changes — an implementation written against
the original text still works.

- `id` is documented as an opaque string. The original example showed
  `'id' => 482`, which read as "integer"; the search plugin was casting it to
  one, so a non-numeric identifier became `0` and every result de-duplicated
  into a single person. Fixed on the search side, and the example now shows a
  string. **Caught by the directory implementer before it shipped.**
- `wpsqr_people_more_url` added, so a directory can supply its own page link.
- The `fields` vocabulary for a future widening is written down above.
