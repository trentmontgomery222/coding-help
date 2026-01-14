# Events Calendar Page Diagnostic

## Critical Errors Found

### 1. Multiple Main Landmarks (3 errors)
**Issue**: The events calendar page has MORE THAN ONE main landmark

**Possible Causes:**
1. Event calendar plugin is creating its own `<main>` element
2. FIX #1 is creating a second main
3. Page builder creating conflicting landmarks

**To Diagnose:**
Open events page console and run:
```javascript
document.querySelectorAll('main, [role="main"]')
```

This will show ALL main elements. Should be 1, but likely shows 2+.

**Expected Fix**: Update FIX #1 to better detect and handle multiple mains

---

### 2. Main Landmark Inside Another Landmark
**Issue**: A main landmark is nested inside another landmark (banner, navigation, etc.)

**To Diagnose:**
```javascript
document.querySelectorAll('main, [role="main"]').forEach(function(main, i) {
    var parent = main.closest('[role="banner"], [role="navigation"], [role="contentinfo"], header, nav, footer');
    console.log('Main #' + (i+1) + ':', main.tagName, 'Parent landmark:', parent ? parent.tagName + ' role=' + parent.getAttribute('role') : 'none');
});
```

**Fix**: Remove or relocate misplaced main landmark

---

### 3. Page Missing Level-One Heading (2 errors)
**Issue**: Events calendar page doesn't have an `<h1>` tag

**To Diagnose:**
```javascript
document.querySelector('h1')
```

Returns `null` = no h1 found

**Fix**: Need to add FIX #23 to ensure every page has an h1

---

### 4. Google Maps aria-hidden (1 error)
**Status**: Should be fixed by aggressive FIX #18 once code is updated

---

### 5. Menu Pattern Errors (20 errors)
**Status**: Beaver Builder issue, safe to ignore (same as homepage)

---

## Testing Instructions for Events Page

1. **Navigate to**: https://acpsmdprod.wpenginepowered.com/events/
2. **Open Console** (F12)
3. **Run diagnostic queries** above
4. **Check for FIX messages** - are they running on this page?
5. **Count main landmarks** - should be 1, not 2+

---

## Next Steps

1. ✅ Update WordPress code with aggressive FIX #18
2. ❓ Diagnose duplicate main landmarks on events page
3. ❓ Add FIX #23 for missing h1 tags
4. ❓ Test on multiple pages (homepage, events, contact, etc.)
