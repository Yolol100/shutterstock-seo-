# Changelog

## 1.6.78
- Fix activation fatal risk on PHP 8.1 by replacing PHP 8.2-only `true` return types.
- Fix activation timeout/memory risk by moving legacy attachment sync to small scheduled batches.
- Add MIME and filesize validation before importing licensed Shutterstock downloads.
- Preserve partial licensing successes as recovery records for retry import.
- Remove Media Library attachment scans from the search hot path.
- Narrow OAuth callback handling to `admin.php` and reject expired state before token exchange.
- Default uninstall data deletion to off for safer accidental uninstall recovery.

- Moved every admin toast notification to bottom-center within the WordPress content area.
- Use a queryless WordPress admin callback URL for Shutterstock OAuth compatibility.
- Add server-side OAuth callback handling so Shutterstock can redirect to `/wp-admin/admin.php` without a dashboard query string.


## 1.6.74
- Security hardening: queue recovery records no longer persist temporary Shutterstock download URLs or sensitive token-like fields.
- Reliability: retry import now requests fresh Shutterstock redownload URLs from stored license IDs.
- Performance: bounded deep missing-page scans and reduced scan payload to post IDs.
- Admin hygiene: replaced OAuth popup status HTML injection with text-only output.

## 1.6.73
- Fix: Added missing WP_Error, WP_REST_Request and WP_REST_Response imports to every REST trait. Without these, every REST request raised a fatal error because PHP resolved the unqualified class names inside the plugin's own namespace.
- Fix: Removed unsupported "Update URI: false" plugin header.
- Performance: Settings::get() now uses a per-request static cache; previously every call ran the full sanitize() pipeline.
- Reliability: ImageImporter::used_shutterstock_ids() is now a pure reader. Database sync against attachment postmeta moved to sync_used_ids_from_attachments() and runs in small scheduled batches after activation.
- Reliability: Queue option now has a 1000-item hard cap; the oldest items are pruned first when exceeded.
- i18n: All admin UI strings are English in the source; Dutch fallbacks removed. Removed "WA Corporate" reference.
- Docs: Removed duplicate installation step in readme.txt; corrected Composer note in README.md.

## 1.6.72
- Added Deselect all beside Select visible in step 1.
- Moved Load more images underneath the step 2 image grid and made Shutterstock load-more batches fixed at 50 results.
- Allowed Download and replace to run without manually selecting images; selected images are prioritized, then loaded results fill the remaining slots automatically.
- Removed remaining CSS priority overrides declarations from admin CSS.

## 1.6.67
- Restored complete plugin header metadata.
- Added repository hygiene files and Composer metadata.
- Hardened queue lock release and Action Scheduler cleanup.

## 1.6.60
- Added queue processing locks and duplicate async scheduling guards.
- Added deactivation cleanup for WP-Cron queue hooks.
- Added versioned cache helper and cache invalidation on settings updates.
- Scoped admin assets to the exact plugin dashboard screen.
- Fixed Settings.php undefined variable and duplicate settings key drift.
- Fixed ImageImporter duplicate conditional and made reservation locks release through finally.
- Expanded uninstall cleanup for cache version, queue locks and reservation locks.
- Improved log context sanitization and payload bounds.

## 1.6.57
- Cleanup dead settings and imports
- Improved uninstall cleanup
- Simplified ACF pipeline
