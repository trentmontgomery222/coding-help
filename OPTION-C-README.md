# Option C: Hybrid Semantic HTML Approach

## Philosophy

**Use the right tool for the job:**
- ✅ Semantic HTML via hooks → When we control the output
- ⚠️ Filters → ONLY for third-party content we can't control
- ⚠️ JavaScript → ONLY for dynamic content with no PHP alternative

## What's Included

### ✅ SEMANTIC HTML (No Filters, No JavaScript)

1. **Skip Navigation Links**
   - Uses: `wp_body_open` hook
   - Output: Clean HTML `<div>` with `<a>` tags
   - CSS: Pure CSS for focus states

2. **GTranslate Menu Styling**
   - Uses: Pure CSS
   - No filters, no JavaScript
   - Responsive dropdown styling

### ⚠️ FILTERS (Third-Party Content Only)

3. **Beaver Builder Icon Accessibility**
   - Why: Beaver Builder plugin generates this HTML
   - Filter: `fl_builder_render_module_content`
   - Can't avoid: We don't control Beaver Builder's templates

4. **Google Maps Accessibility**
   - Why: Users/plugins add maps via various methods
   - Filters: `the_content`, `widget_text`, `fl_builder_render_module_content`
   - Can't avoid: User-generated and plugin-generated content

### ⚠️ MINIMAL JAVASCRIPT (Dynamic Content Only)

5. **Landmark IDs**
   - Why: Beaver Builder doesn't add IDs to main/nav
   - Minimal: Single execution, ~10 lines
   - Required: Skip links need target IDs

6. **District Calendar H1**
   - Why: Tribe Events plugin generates HTML dynamically
   - Minimal: Only runs on event pages
   - Required: No PHP hook available

7. **Duplicate Main Landmarks**
   - Why: Beaver Builder creates these dynamically
   - Minimal: Single execution on page load
   - Required: Can't filter at PHP level

8. **GTranslate ARIA Enhancement**
   - Why: Third-party plugin with no PHP hooks
   - Minimal: Only enhances existing HTML
   - Required: Adds accessibility to their output

## What Was Eliminated

### ❌ REMOVED FILTERS

- **Lang Attribute Filter** → Optional header.php instead (see below)

### 📊 Performance Comparison

| Metric | Before | Option C | Improvement |
|--------|--------|----------|-------------|
| Filters | 4 | 2 | 50% reduction |
| JavaScript Blocks | 6 | 4 | 33% reduction |
| Semantic HTML | 0 | 2 | ✅ New |
| Pure CSS | 1 | 2 | ✅ Enhanced |

## Files in This Package

1. **OPTION-C-HYBRID-SEMANTIC-HTML.php** ⭐
   - Main accessibility code
   - Add to functions.php
   - Hybrid approach with minimal filters

2. **OPTIONAL-header.php**
   - Optional file for lang attribute
   - Only use if you want 100% no filters
   - Adds `lang="en"` to `<html>` tag

## Installation

### Basic Installation (Recommended)

1. Open: **Appearance → Theme File Editor**
2. Select: **functions.php** (Beaver Builder Child Theme)
3. Scroll to bottom
4. Copy/paste: **OPTION-C-HYBRID-SEMANTIC-HTML.php**
5. Save
6. Clear WP Engine cache

### Advanced Installation (Optional - For Lang Attribute)

If you want to eliminate the lang attribute filter entirely:

1. Download **OPTIONAL-header.php**
2. Rename to **header.php**
3. Upload to child theme directory via FTP or file manager
4. This adds `<html lang="en">` using pure HTML

**⚠️ WARNING:** Only do this if you're comfortable maintaining header.php

## WCAG Compliance

All fixes maintain full **WCAG 2.1 Level AA** compliance:

| Fix | Criterion | Method |
|-----|-----------|--------|
| Skip Links | 2.4.1 Bypass Blocks | Semantic HTML |
| BB Icons | 4.1.2 Name, Role, Value | Filter |
| Google Maps | 1.3.1 Info & Relationships | Filter |
| Calendar H1 | 1.3.1 Info & Relationships | JavaScript |
| Duplicate Landmarks | 1.3.1 Info & Relationships | JavaScript |
| GTranslate ARIA | 4.1.2 Name, Role, Value | JavaScript |
| GTranslate Styling | 2.4.7 Focus Visible | CSS |

## Why This Approach?

### ✅ Pros

- **Semantic HTML first** - Cleaner, faster, better SEO
- **Minimal filters** - Only where absolutely necessary
- **Less JavaScript** - Better performance, works without JS for skip links
- **Maintainable** - Clear separation of concerns
- **Update-safe** - Doesn't override entire theme templates

### ⚠️ Trade-offs

- **Some JavaScript remains** - Required for dynamic content
- **Some filters remain** - Required for third-party plugins
- **Not 100% pure HTML** - Impossible with Beaver Builder + plugins

### ❌ Why Not 100% Pure HTML?

We can't eliminate all filters/JavaScript because:

1. **Beaver Builder** - Proprietary plugin, we can't edit their templates
2. **Tribe Events** - Generates HTML dynamically after page load
3. **GTranslate** - Third-party plugin with no accessible PHP hooks
4. **User Content** - Google Maps can be added anywhere by users

## Support

This is the **cleanest possible approach** while maintaining:
- Full WCAG 2.1 AA compliance
- Compatibility with Beaver Builder
- Compatibility with existing plugins
- Maximum performance

You cannot reduce filters/JavaScript further without:
- Breaking accessibility
- Creating custom Beaver Builder modules
- Overriding plugin templates (breaks on updates)
