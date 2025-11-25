# Beaver Builder Callout Accessibility Fix

## Summary

This repository contains a fix for accessibility issues in Beaver Builder icon modules on the Acceleration & Enrichment page.

## The Issue

The contact information at the bottom of the page uses Beaver Builder icon modules (phone and email) that create links with only icons—no visible or accessible text for screen readers.

**Current HTML (problematic):**
```html
<a href="tel:1301-759-2069">
  <i class="fas fa-phone"></i>
</a>
```

**Fixed HTML:**
```html
<a href="tel:1301-759-2069" aria-label="Call 301-759-2069">
  <i class="fas fa-phone" aria-hidden="true"></i>
</a>
```

## The Fix

Add filter code to your **child theme's `functions.php`** file to automatically add:
- `aria-label` attributes to icon links
- `aria-hidden="true"` to Font Awesome icons

## Files in This Repository

1. **`accessibility-fix-functions.php`** - PHP code to copy into your functions.php
2. **`IMPLEMENTATION-INSTRUCTIONS.md`** - Detailed step-by-step guide
3. **`README.md`** - This file

## Quick Start

1. Open your child theme's `functions.php` file
2. Copy the code from `accessibility-fix-functions.php`
3. Paste it at the bottom of your `functions.php`
4. Save and test

## Where to Make the Fix

✅ **Child theme's `functions.php`** (Recommended)
- Location: `/wp-content/themes/bb-theme-child/functions.php`
- Safe from updates
- Easy to remove if needed

❌ **Don't edit:**
- Beaver Builder plugin files (breaks on updates)
- Parent theme files (breaks on updates)
- Module HTML directly (not accessible via admin)

## Affected Modules

Based on the page export, these modules will be fixed:

1. **Phone Icon Module** (node: 4gv6axnc8rft)
   - Link: `tel:1301-759-2069`
   - Will get: `aria-label="Call 301-759-2069"`

2. **Email Icon Module** (node: a9xkyiq3m4pb)
   - Link: `mailto:jennifer.ramsey@acpsmd.org`
   - Will get: `aria-label="Email jennifer.ramsey@acpsmd.org"`

## Testing

After implementing the fix:

1. **Clear cache** (browser + WordPress)
2. **Inspect the icons** - Right-click → Inspect Element
3. **Use a screen reader** to verify announcements
4. **Run accessibility checker** (WAVE, axe DevTools)

## Benefits

- ✅ WCAG 2.1 compliant
- ✅ Screen reader friendly
- ✅ No visual changes
- ✅ Automatic for all icon modules
- ✅ Safe from updates
- ✅ Easy to remove

## Support

If you need help:
- See `IMPLEMENTATION-INSTRUCTIONS.md` for detailed steps
- Check PHP error logs if site breaks
- Make sure you're editing the child theme, not parent theme
