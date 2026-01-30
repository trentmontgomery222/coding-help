# The Events Calendar - Accessibility Fixes

## Issue: Invalid `aria-description` Attributes

The Events Calendar plugin uses `aria-description` attributes which have limited browser and assistive technology support (ARIA 1.3). This causes accessibility validation errors and may not work properly with screen readers.

### Affected Elements

1. **View Selector Button** - `tribe-events-c-view-selector__button`
2. **Today Button** - `tribe-events-c-top-bar__today-button`
3. **Datepicker Button** - `tribe-events-c-top-bar__datepicker-button`

### Fix Applied

Replaced `aria-description` with `aria-label` which has universal browser and assistive technology support.

## Installation

Copy the entire `tribe/` folder to your WordPress theme directory:

```
your-theme/
└── tribe/
    └── events/
        └── v2/
            └── components/
                ├── view-selector/
                │   └── button.php
                └── top-bar/
                    ├── today.php
                    └── datepicker/
                        └── button.php
```

### Steps

1. Navigate to your WordPress theme folder: `wp-content/themes/your-theme/`
2. Copy the `tribe/` folder from this repository into your theme
3. Clear any caching plugins (WP Super Cache, W3 Total Cache, etc.)
4. Clear your browser cache
5. Verify the changes by inspecting the calendar elements

## Verification

After installation, inspect the calendar elements in your browser's developer tools. You should see:

- `aria-label="Select Calendar View"` instead of `aria-description`
- `aria-label="Click to select the current month"` instead of `aria-description`
- `aria-label="Click to toggle datepicker"` instead of `aria-description`

## Notes

- These template overrides are compatible with The Events Calendar v6.0+
- If the plugin updates and changes the template structure, you may need to update these overrides
- Always test after plugin updates to ensure compatibility
