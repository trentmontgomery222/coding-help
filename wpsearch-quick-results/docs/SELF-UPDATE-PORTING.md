# Porting the remote / background self-update to another plugin

This is the recipe we arrived at for WPSearch Quick Results, written so it can
be dropped into any other self-hosted WordPress plugin. It exists because of a
specific host behaviour, so read the diagnosis first — half of these pieces only
make sense once you know what they are working around.

---

## 1. The problem, precisely

Symptoms we saw, in order:

- Updating from the Plugins screen **while logged in** worked.
- Updating from anywhere **not logged into that browser** failed with
  *"could not write files."*
- It happened with **every plugin**, not just ours — so it is the environment,
  not the code.
- `is_writable()` returned **true** for every file, yet the actual `copy()`
  **failed** — and only on the `.php` files. The `.md`/`.txt`/`.css`/`.js`
  files wrote fine.
- The crash **self-restore (rollback) worked** even from a normal request.

### What that combination means

1. It is **not** ordinary file permissions. If `is_writable()` says yes, a
   plain `copy()` of a `.md` succeeds — so the folder is writable. When only
   `.php` writes fail, something *above* POSIX permissions is refusing them.
2. Because it fails **only on `.php`** and **only for files that are already
   loaded**, the block is "**you may not overwrite a PHP file that is currently
   in use**." New, not-yet-loaded `.php` files can be written; the plugin's own
   loaded `.php` files cannot.
3. That is exactly why the **rollback works**: it runs at the very top of the
   bootstrap, *before* the plugin loads its own `includes/*.php`, so those files
   are **not in use** at that instant and can be overwritten.
4. Separately: after files are swapped, **opcache** may still hold the old
   compiled bytecode for some of them, so the next load runs a mismatched
   old+new mix, fatals, and WordPress **pauses/deactivates** the plugin. That is
   the "it disabled itself after the update" tail.

Everything below follows from those four facts. (If on your host a brand-new
`.php` also fails to write — see the probe in §5 — then it is a *total* PHP
write block and only host/SFTP/cron-as-owner can update; staging won't help.)

---

## 2. The shape of the solution

Provide **three** ways to install, in increasing robustness, plus safety:

1. **Direct install** — normal WordPress upgrader. Works where the host allows
   it.
2. **Background (queued) install** — apply the write in a context that *is*
   allowed to write PHP (system cron as the site user, or the next admin
   request), not the logged-out front-end request.
3. **Staged install** — write the new files to a staging folder now (new files
   are allowed), then copy them over the live files **in the early bootstrap
   window, before the plugin's PHP is loaded** — the same instant the rollback
   uses. This is the one that beats "can't overwrite in-use PHP."

Plus:

- **opcache reset** after every self-copy, so the new code actually runs.
- **`ensure_active()`** in the same request, so the plugin is never left
  disabled/paused.
- **rollback backup** armed before any swap, so a bad version is undone.

---

## 3. Piece A — the updater core (the original)

A self-hosted updater that fetches a JSON manifest and can install on demand.

- `request_url()` = `manifest_base ?plugin=<slug>&site=<url>&key=<key>`.
- `remote()` fetches + caches the manifest `{ version, download_url, … }`.
- `inject_update_entry($transient, $force)` builds the update-transient entry
  **directly** (not via the `pre_set_site_transient_update_plugins` filter), so
  you can install even with the wp-admin "update available" notice suppressed,
  and `$force` lets a *same-version* reinstall proceed (a "force failsafe
  reinstall").
- `install_now($force)` runs `Plugin_Upgrader::upgrade()` with an
  `Automatic_Upgrader_Skin` (no admin session needed).

Force reinstall detail (so the upgrader doesn't short-circuit on same version):

```php
$transient = $this->inject_update_entry( get_site_transient( 'update_plugins' ), $force );
if ( $force && isset( $transient->checked[ $this->basename() ] ) ) {
    unset( $transient->checked[ $this->basename() ] ); // make core think it differs
}
set_site_transient( 'update_plugins', $transient );
```

---

## 4. Piece B — front-end filesystem init

From a non-admin request WordPress has no page to show its FTP-credentials
form, so the upgrader asks for credentials, gets none, and bails. Force the
credential-free **direct** method for the request:

```php
$force_direct = static function () { return 'direct'; };
add_filter( 'filesystem_method', $force_direct, 99 );
$ready = WP_Filesystem();               // requires wp-admin/includes/file.php
remove_filter( 'filesystem_method', $force_direct, 99 );
```

---

## 5. Piece C — the write probe (diagnose, don't guess)

Before blaming code, *measure*. Two tests:

- **Per-file writability** of the unpacked release against its real destination
  (existing file writable, or its parent dir writable), reported per path — and
  it **continues past failures** instead of aborting like `copy_dir` does.
- **Live write test**: actually create then delete a throwaway
  `writetest.md / .txt / .js / .css / .php` in the plugin folder. This is the
  decisive one:

```php
foreach ( array( 'md', 'txt', 'js', 'css', 'php' ) as $ext ) {
    $f = $dir . '/wpsqr-writetest-' . $tag . '.' . $ext;
    $ok = ( false !== @file_put_contents( $f, "test\n" ) );
    $ok && @unlink( $f );
    // report "new .$ext : OK|FAILED"
}
```

- **new `.php` : OK** → the host only blocks overwriting *in-use* PHP → staging
  (§7) will work.
- **new `.php` : FAILED** → the host blocks *all* PHP writes by the web user →
  nothing web-side works; use SFTP / cron-as-owner / an admin session.

---

## 6. Piece D — manual per-file copy fallback

WordPress's `copy_dir()` aborts the whole install on the first file it can't
handle. When the probe shows the files *are* writable, copy them yourself and
**continue past any single failure**, reporting which failed:

```php
foreach ( $recursive_iterator as $item ) {
    $target = $dest . '/' . $rel;
    if ( $item->isDir() ) { wp_mkdir_p( $target ); continue; }
    if ( ! @copy( $src, $target ) ) { @chmod( $target, 0644 ); if ( ! @copy( $src, $target ) ) $failed[] = $rel; }
}
```

Call this as a fallback when `Plugin_Upgrader::upgrade()` returns false/WP_Error.

---

## 7. Piece E — the staged install (the key fix)

This is what beats "can't overwrite in-use PHP." Two halves.

**Stage now** (from any request — writes *new* files, which are allowed):

```php
$package = download_url( $remote['download_url'] );
$base    = WP_CONTENT_DIR . '/myplugin-staging-' . wp_generate_password( 8, false, false );
unzip_file( $package, $base );
$source  = locate_main_dir( $base );                 // folder containing the main plugin file
update_option( 'myplugin_staged_install', array( 'dir' => $source, 'version' => $remote['version'] ), false );
```

**Apply early** — in the plugin's main file, **before** you load the plugin's
own includes (this is the pristine window; the includes are not in use yet):

```php
// main plugin file, right after the guard loads, BEFORE load_includes():
Guard::maybe_apply_staged();
```

```php
public static function maybe_apply_staged() {
    $stage = get_option( 'myplugin_staged_install' );
    if ( ! is_array( $stage ) || empty( $stage['dir'] ) || ! is_dir( $stage['dir'] ) ) {
        if ( false !== $stage ) delete_option( 'myplugin_staged_install' );
        return false;
    }
    self::arm_rollback( MYPLUGIN_VERSION );            // back up current first
    $ok = self::copy_tree( $stage['dir'], untrailingslashit( MYPLUGIN_PATH ) );
    self::remove_tree( $stage['dir'] );
    delete_option( 'myplugin_staged_install' );

    if ( $ok ) {
        if ( function_exists( 'opcache_reset' ) ) @opcache_reset();   // §9
        update_option( 'myplugin_should_be_active', 1, false );
        update_option( 'myplugin_post_update_check', time(), false );
    } else {
        self::disarm_rollback();
    }
    return $ok;
}
```

`copy_tree` / `remove_tree` are plain recursive `@copy`/`@unlink` (no
`WP_Filesystem` — it must work with as little loaded as possible).

Flow for the user: **Stage → reload any page → applied.** No admin needed.

---

## 8. Piece F — background (queued) install

For hosts where the writable context is cron or an admin request, not the
front-end. Queue a marker and let a writable context apply it:

```php
public function queue_install( $force ) {
    update_option( 'myplugin_pending_update', array( 'force' => $force, 'requested' => time(), 'attempts' => 0 ), false );
    if ( ! wp_next_scheduled( 'myplugin_apply_pending' ) ) wp_schedule_single_event( time() + 20, 'myplugin_apply_pending' );
    if ( function_exists( 'spawn_cron' ) ) spawn_cron();
}
```

```php
add_action( 'myplugin_apply_pending', array( $this, 'apply_pending_update' ) );
add_action( 'admin_init',             array( $this, 'apply_pending_update' ) ); // writable context
```

`apply_pending_update()`: take a short transient lock; give up after N attempts
or a day; run `install_now($force)`; on success `delete_option()` the marker and
call `ensure_active()` (§10); on failure bump `attempts`, reschedule, and record
the outcome for display.

> Why not "just schedule it"? WordPress's own WP-Cron is triggered by a web
> visit and runs **as the web user** — same block. Only a scheduler the host
> runs **as the site/owner user** (real system cron, or `wp cron event run …`
> over SSH) escapes it. The queue plugs into whichever the host provides; a
> process cannot promote itself to another OS user.

---

## 9. Piece G — reset opcache after every self-copy

The fix for "it disabled itself after updating." After the files are swapped,
opcache may still serve old bytecode → fatal → WordPress pauses the plugin.

```php
if ( function_exists( 'opcache_reset' ) ) @opcache_reset();
```

Put it right after: the staged copy, the manual copy, and at the start of the
post-update check. (`Plugin_Upgrader` already invalidates opcache itself.)

---

## 10. Piece H — never leave it disabled (`ensure_active`)

A deactivated plugin can't re-enable itself next request — it doesn't run. So
re-enable **in the same request as the install**, and clear a recovery-mode
pause too:

```php
public function ensure_active() {
    if ( ! function_exists( 'is_plugin_active' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if ( ! is_plugin_active( $this->basename() ) ) activate_plugin( $this->basename() );

    if ( function_exists( 'wp_paused_plugins' ) ) {           // clear WSOD/recovery pause
        $paused = wp_paused_plugins();
        foreach ( array( $this->basename(), $this->slug() ) as $key ) {
            if ( ! method_exists( $paused, 'get' ) || $paused->get( $key ) ) $paused->delete( $key );
        }
    }
}
```

Call it from the background installer on success and from the post-update check.

---

## 11. Piece I — the safety net (rollback guard)

Independent of updating, and what makes all the above safe to trigger remotely:

- `register_shutdown_function` catches a fatal **in this plugin's own path** and
  trips *safe mode* (a flag), so a bad release can't white-screen the site.
- On the next request, the bootstrap — before loading anything else — either
  **rolls back** to the pre-update backup (if an update armed one) or runs in
  safe mode.
- `arm_rollback()` copies the current files to a backup dir *before* a swap;
  `maybe_rollback()` restores them; `disarm_rollback()` drops the backup once
  the new code has loaded cleanly.

This is also the proof-of-concept that the early-bootstrap window can write
in-use `.php` files — staging just reuses it going forward.

---

## 12. Order to implement in a new plugin

1. Guard: shutdown catch, safe mode, `arm/maybe/disarm_rollback`,
   `copy_tree`/`remove_tree`. (Safety first.)
2. Updater core (§3) + front-end FS init (§4).
3. `ensure_active()` (§10) and opcache reset (§9) — wire into the install paths.
4. Staged install (§7) — the main fix; add `maybe_apply_staged()` to the
   bootstrap before includes load.
5. Background queue (§8) — for cron/admin-context hosts.
6. Probe (§5) — so you can *see* which host case you're in.

Then expose Stage / Queue / Reinstall(force) / Probe as buttons on whatever
admin or remote page triggers them.

---

## 13. What is deliberately NOT done

We do **not** try to bypass a host that blocks *all* PHP writes by the web user
(new `.php` fails in the probe). Defeating that is exactly what malware wants,
and it would be the wrong thing to ship. On such hosts the legitimate routes are
SFTP, a Git deploy, an admin-session update, or the host running cron as the
owner — and the staged/queued paths will simply report that they couldn't write,
rather than pretending to work.
