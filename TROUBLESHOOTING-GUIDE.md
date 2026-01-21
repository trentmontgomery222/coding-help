# Accessibility Fixes Troubleshooting Guide

## Issue: Code Not Taking Effect After Pasting

If you've pasted the code from `FINAL-PASTE-INTO-FUNCTIONS.php` but the fixes aren't working yet, follow these steps:

---

## Step 1: Verify Code Was Actually Saved in WordPress

1. Log into WordPress Admin
2. Go to: **Appearance → Theme Editor**
3. Select: **functions.php** (in BB Child Theme)
4. Scroll to **line 27**
5. **Verify you see:**
   ```php
   add_filter( 'fl_builder_render_module_content', 'fix_bb_icon_accessibility', 10, 2 );
   ```
6. Scroll down further and verify you see:
   ```php
   // FIX #1: ARIA Landmarks
   // FIX #2: Skip Navigation
   // ... etc
   ```
7. **If you DON'T see this code**: The paste didn't save properly. Try again.

---

## Step 2: Clear ALL WP Engine Caches

**CRITICAL**: WP Engine has multiple cache layers. You must clear ALL of them:

### In WP Engine Admin Portal:
1. **Page Cache**: Click "Purge All Caches"
2. **Network Cache**: Click "Clear Cache" in network settings
3. **Object Cache**: Flush Redis/Memcached

### In WordPress Admin (if you have a caching plugin):
1. Go to **WP Engine → Caching**
2. Click **"Purge All Caches"**
3. Also clear any other caching plugins (WP Super Cache, W3 Total Cache, etc.)

---

## Step 3: Hard Refresh Your Browser

**Windows/Linux:**
- Chrome/Firefox/Edge: Press `Ctrl + Shift + R`
- Or: `Ctrl + F5`

**Mac:**
- Chrome/Firefox/Safari: Press `Cmd + Shift + R`
- Or: `Cmd + Option + R`

---

## Step 4: Verify Fixes Are Running

### Open Browser Console:
1. Press `F12` on your keyboard
2. Click the **"Console"** tab
3. Hard refresh the page (`Ctrl + Shift + R`)

### What You Should See in Console:
```
FIX #1: Main landmark already exists with ID: fl-main-content
FIX #2: Added skip navigation link
FIX #5: Marked background video as decorative
FIX #18: Removed aria-hidden from focusable Google Map
...etc
```

### If You DON'T See These Messages:
- The code is NOT running yet
- Go back to Step 1 and verify the code was saved

---

## Step 5: Check Google Maps Specifically

### In Browser Console, run this command:
```javascript
document.querySelector('iframe[src*="google.com/maps"]')
```

### Then check if aria-hidden is present:
```javascript
document.querySelector('iframe[src*="google.com/maps"]').getAttribute('aria-hidden')
```

### Expected Results:
- **If it returns `null`**: ✅ Fix is working! (aria-hidden was removed)
- **If it returns `"true"`**: ❌ Fix not working yet (code not running)

---

## Step 6: Verify No Old Code Conflicts

Make sure you **DELETED** the old accessibility code (lines 27-218) before pasting the new code.

### Check for duplicate console messages:
- If you see **"Adding accessibility landmarks..."** AND **"FIX #1: Main landmark..."**
- This means old code is still there
- Delete everything from line 27 onwards FIRST, then paste new code

---

## Step 7: Check for JavaScript Errors

### In Browser Console:
- Look for any **red error messages**
- Common issues:
  - `Uncaught SyntaxError` = Code wasn't pasted correctly
  - `Uncaught ReferenceError` = Missing closing brackets

### If You See Errors:
1. Copy the ENTIRE content from `FINAL-PASTE-INTO-FUNCTIONS.php` again
2. Delete old code from line 27 onwards in WordPress
3. Paste fresh copy
4. Save and test again

---

## Step 8: Verify File Permissions (Advanced)

### In WP Engine SSH or File Manager:
```bash
ls -la wp-content/themes/bb-theme-child/functions.php
```

**Expected**: `-rw-r--r--` (readable and writable)

**If different**: Fix permissions:
```bash
chmod 644 wp-content/themes/bb-theme-child/functions.php
```

---

## Step 9: Test in Different Browser

Sometimes browser caching is persistent. Try:
1. **Private/Incognito Window**: `Ctrl + Shift + N` (Chrome) or `Ctrl + Shift + P` (Firefox)
2. **Different Browser**: Try Chrome if you used Firefox, or vice versa

---

## Step 10: Verify Theme Is Active

1. Go to: **Appearance → Themes**
2. Verify **"BB Child Theme"** is active (NOT the parent theme)
3. If parent theme is active, activate the child theme

---

## Common Issues & Solutions

### Issue: "The code looks correct but nothing changed"
**Solution**: Cache wasn't cleared properly. Wait 5 minutes, then clear cache again.

### Issue: "Console shows JavaScript errors"
**Solution**: Code wasn't pasted completely. Re-paste the ENTIRE file.

### Issue: "Some fixes work, but not others"
**Solution**: Certain elements load dynamically. Wait for page to fully load, then check console.

### Issue: "It works in one browser but not another"
**Solution**: Clear cache in the other browser or use private/incognito mode.

---

## Final Verification Checklist

Once fixes are working, verify in ARC Toolkit and axe DevTools:

### Expected Results:
- ✅ **Duplicate main landmarks**: FIXED (should go from 2 errors to 0)
- ✅ **ARIA attribute not allowed on generic role**: FIXED (background videos)
- ✅ **aria-hidden on focusable element**: FIXED (Google Maps)
- ✅ **Total errors**: Should drop significantly

### Console Output Should Show:
```
FIX #1: Added ID to existing main landmark
FIX #2: Added skip navigation link
FIX #5: Marked background video as decorative
FIX #18: Removed aria-hidden from focusable Google Map
FIX #21: Enhanced dropdown menu keyboard navigation
```

---

## Still Not Working?

### Export your functions.php file:
1. In WordPress: **Appearance → Theme Editor**
2. Copy the ENTIRE content of functions.php
3. Save it to a text file
4. Check if the accessibility code is actually there

### Check PHP error logs:
1. In WP Engine dashboard: **Sites → [Your Site] → Logs**
2. Look for PHP errors related to functions.php
3. Common issues:
   - Missing closing brackets `}`
   - Missing closing PHP tag `?>`
   - Syntax errors from incomplete paste

---

## What Success Looks Like

### Browser Console:
- Multiple "FIX #X:" messages appear
- No JavaScript errors (red text)

### ARC Toolkit:
- Errors reduced from 21 to single digits or zero
- Warnings are acceptable (false positives)

### Google Maps:
- No longer has `aria-hidden="true"` attribute
- Has `title` and `aria-label` attributes
- Is keyboard focusable

### Navigation:
- Tab key works through all menu items
- Arrow keys work in dropdowns
- Escape key closes dropdowns

---

## Contact Information

If you've followed all steps and fixes still aren't working:
1. Export your functions.php file
2. Check the PHP error logs
3. Try deactivating other plugins temporarily to rule out conflicts
