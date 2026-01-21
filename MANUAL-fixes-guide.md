# Manual Accessibility Fixes Guide

## Fix #1: Search Bar Icon ✅ AUTOMATED

**Issue:** Search icon button has no aria-label
**Code:** Automatically adds `aria-label="Search"` to search buttons and inputs
**Action:** Add the JavaScript code to functions.php (already in SPECIFIC-fixes file)

---

## Fix #2: Silent Background Video ⚠️ NEEDS MANUAL FIX

**Issue:** Video has no audio and no transcript

### ✅ What You've Already Done:
- Added `muted` attribute ✓
- Video is decorative background ✓

### 🔧 What You Need to Do:

**Option A: Add Description Text (Recommended)**

Add a visible description below or near the video:

```html
<div class="video-description">
    <p>This video shows [describe the visual content - e.g., "a montage of
    student activities including classroom learning, sports, and arts programs"]</p>
</div>
```

**Option B: Add Screen Reader Text**

If you want it hidden from sighted users:

```html
<div class="screen-reader-text">
    Video description: Students engaged in various learning activities
    including classroom instruction, hands-on projects, and collaborative work.
</div>
```

Add this CSS to hide it visually:

```css
.screen-reader-text {
    position: absolute;
    left: -10000px;
    width: 1px;
    height: 1px;
    overflow: hidden;
}
```

**Option C: Mark as Decorative**

If the video is purely decorative and conveys no important information:

```html
<video aria-hidden="true" role="presentation" muted loop playsinline>
    <source src="your-video.mp4" type="video/mp4">
</video>
```

### 📝 Where to Add This:

1. Go to your page editor
2. Add an HTML module above/below the slider
3. Paste the description code
4. Style it to match your design

---

## Fix #3: Smart Slider ARIA Hidden ✅ PARTIALLY AUTOMATED

**Issue:** Slide backgrounds are `aria-hidden="true"` but contain meaningful images

### ✅ Code Fix (Automatic):
The JavaScript code will:
- Extract alt text from `data-alt` and `data-title` attributes
- Apply it to the images
- Label the slider container

### 🔧 Manual Verification Needed:

1. **Check Smart Slider Settings:**
   - Edit your slider in Smart Slider 3
   - For each slide, go to slide settings
   - Find "SEO" or "Accessibility" section
   - Add descriptive alt text for each slide image

2. **Example Alt Text:**
   ```
   Slide 1: "Winter scene with snow-covered school bus and trees"
   Slide 2: "Students in classroom working on science project"
   Slide 3: "School building exterior with welcoming entrance"
   ```

3. **Add Slider Label:**
   In Smart Slider settings:
   - General → Accessibility
   - Add "ARIA Label": "Featured school images" (or similar)

---

## Fix #4: Callout Module Buttons ✅ AUTOMATED + MANUAL OPTIONS

**Issue:** Icon and text are separate links going to the same place

### ✅ Automatic Fix (JavaScript):
The code will:
- Hide icon link from screen readers (`aria-hidden="true"`)
- Remove icon link from tab order (`tabindex="-1"`)
- Keep text link accessible

### 🏆 BETTER FIX (Beaver Builder Settings):

**Option A: Disable Icon Link**
1. Edit the Callout module
2. Click on the "Link" tab
3. Under "Icon Link", select **"No"** or **"None"**
4. Keep "Title Link" set to "Yes"
5. Save

This way there's only ONE link instead of two!

**Option B: Use Button Module Instead**
1. Delete the Callout module
2. Add a Button module
3. Add your icon in the button
4. Add your text
5. Set the link
6. Style to match

**Option C: Combine Manually**
Edit the Callout module HTML (Advanced → HTML Element):
```html
<a href="https://paypams.com/HomePage.aspx" class="callout-button">
    <i class="fas fa-icon" aria-hidden="true"></i>
    <span>PayPams</span>
</a>
```

---

## Fix #5: UserFeedback Form ✅ AUTOMATED

**Issue:** Form fields are hidden with `aria-hidden="true"`

### ✅ Code Fix (Automatic):
The JavaScript will:
- Remove `aria-hidden` from form containers
- Add `aria-label` to textareas using their placeholder text
- Ensure all form elements are accessible

### 🔧 Additional Manual Checks:

1. **Test the Form:**
   - Press Tab to navigate
   - Ensure you can reach all fields
   - Verify field labels are announced by screen readers

2. **If Issues Persist, Contact Plugin Author:**
   - UserFeedback by MonsterInsights should be accessible by default
   - If aria-hidden persists, report to: https://wordpress.org/support/plugin/userfeedback/
   - They may have an accessibility setting in plugin options

3. **Check Plugin Settings:**
   - Go to **UserFeedback → Settings**
   - Look for any accessibility options
   - Ensure forms are not set to "hidden" by default

---

## Testing Checklist

After applying all fixes:

### ✅ WAVE Browser Extension:
- [ ] Run WAVE on your page
- [ ] Errors should be significantly reduced
- [ ] Check each of the 5 areas specifically

### ✅ Keyboard Navigation:
- [ ] Tab through entire page
- [ ] Skip duplicate links (icon links should be hidden)
- [ ] Reach all form fields
- [ ] See visible focus on all elements

### ✅ Screen Reader (NVDA/JAWS):
- [ ] Search button announces "Search button"
- [ ] Video is either described or marked decorative
- [ ] Slider images have alt text
- [ ] Only ONE link per callout (not two)
- [ ] Form fields are reachable and labeled

---

## Priority Order

1. **CRITICAL - Do First:**
   - Fix #4: Callout buttons (manual fix in Beaver Builder)
   - Fix #5: UserFeedback form (apply JavaScript)

2. **HIGH - Do Next:**
   - Fix #1: Search bar (apply JavaScript)
   - Fix #2: Video description (add manual text)

3. **MEDIUM - Verify:**
   - Fix #3: Smart Slider (verify settings)

---

## Need Help?

If any of these don't work:
1. Check browser Console (F12) for error messages
2. Verify the JavaScript code is running (look for console.log messages)
3. Test with WAVE after each fix to see improvements
4. Send me specific error messages if issues persist
