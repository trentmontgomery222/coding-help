# Beaver Builder Icon Accessibility Fix - Implementation Guide

## The Problem
Your Beaver Builder icon modules (phone and email links) at the bottom of the Acceleration & Enrichment page are missing descriptive text for screen readers. Links that contain only icons need:
- `aria-label` attributes with descriptive text
- Icons marked with `aria-hidden="true"`

## The Solution

### Option 1: Add Filter Code to functions.php (Recommended)

1. **Access your child theme's `functions.php` file:**
   - Via FTP/SFTP: Navigate to `/wp-content/themes/bb-theme-child/functions.php`
   - Via WP Admin: Go to Appearance → Theme File Editor → functions.php
   - Via hosting control panel file manager

2. **Copy the code from `accessibility-fix-functions.php`** (in this repository)

3. **Paste it at the bottom of your child theme's `functions.php` file**
   - Make sure it's pasted AFTER the existing code
   - Don't replace any existing code, just add it to the end

4. **Save the file**

5. **Test the page:**
   - Clear your cache (browser and WordPress cache if you use a caching plugin)
   - Visit the Acceleration & Enrichment page
   - Right-click on the phone or email icon → Inspect Element
   - You should see something like:
     ```html
     <a href="tel:1301-759-2069" aria-label="Call 301-759-2069">
       <i class="fas fa-phone" aria-hidden="true"></i>
     </a>
     ```

---

## What the Code Does

The filter modifies the HTML output of Beaver Builder icon modules to:

1. **Add descriptive `aria-label` attributes** to links:
   - Phone links get: `aria-label="Call 301-759-2069"`
   - Email links get: `aria-label="Email jennifer.ramsey@acpsmd.org"`

2. **Hide icons from screen readers** by adding `aria-hidden="true"` to Font Awesome icons

3. **Works automatically** for all icon modules that have:
   - A link set
   - Screen reader text configured (which yours do!)

---

## Alternative Option: Screen Reader-Only Text

If you prefer to add visible-but-hidden text instead of aria-labels:

1. **Add this CSS to your child theme's `style.css`:**

```css
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0,0,0,0);
    white-space: nowrap;
    border-width: 0;
}
```

2. **Uncomment the second function** in the `accessibility-fix-functions.php` file (remove the `/*` and `*/` around it)

3. **Comment out the first function** (add `/*` before it and `*/` after it)

This will add hidden `<span class="sr-only">` text inside your links.

---

## Testing with Screen Readers

To verify the fix works:

1. **Use a screen reader:**
   - Windows: NVDA (free) or JAWS
   - Mac: VoiceOver (built-in, Cmd+F5)
   - Chrome extension: ChromeVox

2. **Navigate to the contact section:**
   - The screen reader should announce "Call 301-759-2069" for the phone link
   - The screen reader should announce "Email jennifer.ramsey@acpsmd.org" for the email link

---

## Troubleshooting

**Problem:** Code doesn't seem to work

**Solutions:**
- Clear all caches (browser, WordPress, CDN)
- Make sure you're editing the **child theme**, not the parent theme
- Check for PHP errors (enable WP_DEBUG)
- Make sure the code is pasted correctly (no missing brackets)

**Problem:** Site breaks or shows errors

**Solutions:**
- Remove the code you just added
- Check for syntax errors (missing semicolons, brackets)
- Make sure you didn't accidentally delete existing code

---

## Need Help?

If you have issues implementing this:
1. Take a screenshot of any error messages
2. Check your PHP error log
3. Verify the code was pasted correctly

The fix is safe and won't affect:
- Other modules
- Your site's functionality
- Beaver Builder's core files
- Theme updates (since it's in the child theme)
