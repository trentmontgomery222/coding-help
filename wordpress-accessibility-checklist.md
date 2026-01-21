# WordPress Accessibility Checklist for Screen Readers

## Immediate Checks

### 1. HTML Language Attribute
- [ ] Verify `<html lang="en">` (or appropriate language) exists in your theme
- Location: Usually in `header.php` or set via WordPress settings

### 2. Skip Navigation Link
- [ ] Add skip link as first focusable element
- [ ] Must link to `#main-content` or similar
- [ ] Should be visible on keyboard focus

### 3. Landmark Regions (CRITICAL)
Verify these exist in your theme templates:
- [ ] `<header>` or `role="banner"` - Site header
- [ ] `<nav>` or `role="navigation"` - Main menu
- [ ] `<main>` or `role="main"` - Primary content (MUST have only ONE per page)
- [ ] `<footer>` or `role="contentinfo"` - Site footer
- [ ] `<aside>` or `role="complementary"` - Sidebars (if applicable)

### 4. Heading Hierarchy
- [ ] ONE `<h1>` per page (usually page/post title)
- [ ] No skipped heading levels (h1 → h3 is wrong)
- [ ] Logical document outline

### 5. Images
- [ ] All `<img>` tags have `alt` attributes
- [ ] Decorative images use `alt=""` (empty)
- [ ] Informative images have descriptive alt text

### 6. Links
- [ ] No "click here" or "read more" without context
- [ ] Icon-only links have `aria-label` or visually-hidden text
- [ ] Link text describes destination

### 7. Forms
- [ ] All inputs have associated `<label>` elements
- [ ] Use `<fieldset>` and `<legend>` for grouped inputs
- [ ] Error messages are properly announced

### 8. Beaver Builder Specific Issues

#### Known Problems:
- Beaver Builder outputs everything as `<div>` elements
- No semantic HTML by default
- Modules lack proper ARIA attributes

#### Fixes Required:
1. **Check Theme Builder Templates**
   - Header template must use `<header>` tag
   - Footer template must use `<footer>` tag

2. **Wrap Page Content**
   - Main content area needs `<main>` wrapper
   - Check `page.php`, `single.php`, `index.php`

3. **Navigation Menus**
   - Must be wrapped in `<nav>` element
   - Add `aria-label` to distinguish multiple navs

## Testing Tools

### Browser Extensions:
1. **WAVE** - Visual accessibility checker
2. **axe DevTools** - Comprehensive ARIA/accessibility testing
3. **Lighthouse** - Chrome DevTools audit

### Screen Reader Testing:
1. **NVDA** (Windows - Free) - Download from nvaccess.org
2. **JAWS** (Windows - Commercial)
3. **VoiceOver** (Mac - Built-in, press Cmd+F5)

### Testing Checklist:
- [ ] WAVE shows landmarks in Structure panel
- [ ] axe DevTools returns 0 violations
- [ ] Can navigate entire site using only Tab key
- [ ] Screen reader announces all content logically
- [ ] Screen reader can navigate by headings (H key in NVDA/JAWS)
- [ ] Screen reader can navigate by landmarks (D key in NVDA/JAWS)

## Common Beaver Builder Template Locations

Check these files in your theme:
```
wp-content/themes/[your-theme]/
├── header.php          ← Must have <header> tag
├── footer.php          ← Must have <footer> tag
├── page.php            ← Should wrap content in <main>
├── single.php          ← Should wrap content in <main>
├── index.php           ← Should wrap content in <main>
└── functions.php       ← Add accessibility fixes here
```

## If You're Using Beaver Themer:

1. Go to **Beaver Builder → Theme Layouts**
2. Edit your **Header** layout
   - Advanced tab → HTML Element
   - Change from `div` to `header`
   - Add `role="banner"` in Advanced → CSS/HTML settings

3. Edit your **Footer** layout
   - Change element to `footer`
   - Add `role="contentinfo"`

4. For each **Page** layout:
   - Wrap content rows in a `<main>` element
   - Add `id="main-content"` and `role="main"`

## CSS for Skip Link

Add to your theme's `style.css`:
```css
.skip-link {
    position: absolute;
    top: -40px;
    left: 0;
    background: #000;
    color: #fff;
    padding: 8px 16px;
    text-decoration: none;
    z-index: 100000;
    font-size: 14px;
}

.skip-link:focus {
    top: 0;
    outline: 2px solid #fff;
    outline-offset: 2px;
}

.screen-reader-text {
    position: absolute;
    left: -10000px;
    width: 1px;
    height: 1px;
    overflow: hidden;
}

.screen-reader-text:focus {
    position: static;
    width: auto;
    height: auto;
}
```

## WCAG 2.1 AA Requirements for Maryland (MSDE)

### Level A (Must Have):
- ✓ Text alternatives for images
- ✓ Keyboard accessible
- ✓ Enough time to read content
- ✓ No seizure-inducing flashing
- ✓ Navigable with landmarks
- ✓ Identifiable purpose (headings, labels)

### Level AA (Must Have):
- ✓ Color contrast minimum 4.5:1 for text
- ✓ Color contrast 3:1 for large text (18pt+)
- ✓ No information conveyed by color alone
- ✓ Resize text to 200% without loss of functionality
- ✓ Multiple ways to find pages (nav, search, sitemap)
- ✓ Headings and labels descriptive
- ✓ Focus visible on all interactive elements

## Priority Order

1. **CRITICAL** - Add landmarks (header, nav, main, footer)
2. **CRITICAL** - Add skip navigation link
3. **CRITICAL** - Fix heading hierarchy
4. **HIGH** - Add alt text to all images
5. **HIGH** - Fix color contrast issues
6. **MEDIUM** - Ensure keyboard navigation works
7. **MEDIUM** - Add ARIA labels to icon links
8. **LOW** - Add focus indicators
