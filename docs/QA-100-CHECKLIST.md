# QA 100 Checklist

Use this checklist before shipping SEO Shutterstock Image Assistant.

## Static checks
- Run PHP syntax checks across all PHP files.
- Run WordPress Plugin Check.
- Run PHPCS with WordPress Coding Standards.
- Run PHPCompatibility for the declared PHP requirement.
- Confirm the production package contains built assets and no development-only folders.

## WordPress workflow checks
- Activate and deactivate the plugin on a clean WordPress install.
- Verify the admin menu and settings screens load for administrators.
- Verify non-admin users cannot edit credentials or run privileged actions.
- Save Shutterstock credentials and confirm secrets are masked after saving.
- Test OAuth connect, token refresh, search, license, import and attach flows.
- Test retry suggestions, retry import and retry ACF attach recovery actions.
- Test uninstall with data deletion enabled and disabled.

## Integration checks
- Test with ACF image fields using ID, array and URL return formats.
- Test featured image mapping when enabled.
- Test Elementor Dynamic Tags display the mapped images correctly.
- Test exact-three-image selection enforcement.
- Test duplicate image prevention across multiple posts.

## Browser checks
- Verify keyboard selection states in the image grid.
- Verify responsive admin layout at desktop, tablet and narrow widths.
- Verify logs redact credentials and sensitive tokens.
