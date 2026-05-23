=== SEO Image Assistant for Shutterstock ===
Contributors: webactueel
Tags: seo, images, shutterstock, acf, elementor
Requires at least: 6.5
Requires PHP: 8.1
Tested up to: 6.8
Stable tag: 1.6.77
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Premium-ready workflow tool for finding, reviewing, licensing, and attaching Shutterstock images to SEO pages.

== Description ==
SEO Image Assistant for Shutterstock helps agencies find default-template pages with missing image slots, generate Shutterstock search terms, review suggestions, load results in batches of 50, license after explicit approval, import licensed files to the Media Library, and map attachments to featured and ACF image fields used by Elementor Dynamic Tags.

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/.
2. Activate the plugin through the Plugins menu in WordPress.
3. Open SEO Images > Settings and connect Shutterstock.
4. Map ACF fields image_1, image_2, image_3 and optionally featured_image.

== External Services ==
This plugin connects to Shutterstock only after an administrator adds Shutterstock API/OAuth credentials and uses the workflow.

Service: Shutterstock API
Endpoint: https://api.shutterstock.com/v2
OAuth authorization endpoint: https://api.shutterstock.com/v2/oauth/authorize
OAuth token endpoint: https://api.shutterstock.com/v2/oauth/access_token
Provider terms: https://www.shutterstock.com/terms
Provider privacy policy: https://www.shutterstock.com/privacy

Data sent to Shutterstock can include search keywords generated from page titles, SEO focus keywords, selected Shutterstock image IDs, license size/price/customer metadata, and OAuth authorization/token requests. Shutterstock connection fields such as callback URL, consumer key, consumer secret and access token can be managed by administrators in the dashboard. Stored secrets and tokens are masked in the UI, preserved when the masked value is saved, and kept server-side in WordPress options with autoload disabled. Subscription access is detected from the connected Shutterstock account and secrets are not intentionally written to plugin logs or REST responses.


== Privacy ==
This plugin stores Shutterstock credentials, selected Shutterstock image IDs, queue records and plugin logs in WordPress options. Secrets are masked in the UI, redacted from logs, and saved with autoload disabled; database access should still be treated as privileged because API credentials are stored server-side. The plugin sends search terms, image IDs and licensing requests to Shutterstock only when an authorized user uses the workflow. Site owners should disclose Shutterstock as an external service in their privacy policy when the plugin is enabled.

== QA ==
For final production approval, run Plugin Check, PHPCS/WPCS, PHP lint, JS lint, role-based REST tests, live Shutterstock OAuth/licensing, ACF-on/off checks, queue retry/recovery and uninstall cleanup in staging.

== Changelog ==
= 1.6.77 =
* UI: Moved all admin toast notifications to bottom-center in the WordPress content area.

= 1.6.76 =
* UI: Centered admin toast notifications within the WordPress content area.
* OAuth: Use a queryless WordPress admin callback URL for Shutterstock OAuth compatibility.
* OAuth: Add server-side callback handling so Shutterstock can redirect to /wp-admin/admin.php without a dashboard query string.

= 1.6.74 =
* Security hardening: queue recovery records no longer persist temporary Shutterstock download URLs or sensitive token-like fields.
* Reliability: retry import now requests fresh Shutterstock redownload URLs from stored license IDs.
* Performance: bounded deep missing-page scans and reduced scan payload to post IDs.
* Admin hygiene: replaced OAuth popup status HTML injection with text-only output.

= 1.6.72 =
* Docs: Corrected the Shutterstock OAuth authorization disclosure to match the current account-selection flow.
* Docs: Cleaned duplicate readme changelog entries and updated the workflow description for load-more/autofill image placement.

= 1.6.71 =
* UI: Added a Deselect all button next to Select visible in step 1.
* UI: Step 2 now loads Shutterstock results in fixed batches of 50 and shows the Load more images button underneath the result grid.
* Workflow: Download and replace no longer requires manually selecting enough images; selected images are used first and remaining loaded results are used automatically.
* CSS: Removed remaining CSS priority overrides declarations and kept the admin UI scoped through selector order.

= 1.6.67 =
* Restored Plugin URI, Author URI and Update URI headers.
* Added release hygiene files including full GPL license, gitattributes and Composer metadata in the repository source.
* Hardened queue lock release with finally cleanup and Action Scheduler deactivation cleanup.
* Rebuilt the step 2 search row as a direct input and action button so both controls align cleanly.
* Kept the search controls responsive while preserving the selected-image counter and Shutterstock result selection flow.

= 1.6.60 =
* Hardening: added duplicate queue execution locks and safer Action Scheduler / WP-Cron scheduling checks.
* Hardening: added deactivation cleanup for plugin cron hooks.
* Performance: moved search and missing-page count caches behind a versioned cache helper with explicit TTLs and invalidation on settings changes.
* Performance: scoped admin assets to the exact dashboard screen and kept options/cache state non-autoloaded.
* Reliability: fixed undefined settings cleanup drift, duplicate sanitize array entries, and a duplicate import conditional.
* Reliability: improved uninstall cleanup for plugin cache, queue-lock and reservation-lock options.
* Observability: bounded and recursively sanitized log context payloads.

= 1.6.56 =
* Design: Re-applied intentional design touches from the original layered CSS that were lost during the consolidation in 1.6.55.
* Design: Search input on step 2 is now 52 px tall with a softer focus glow so it reads as the primary action.
* Design: Image cards in step 2 use clearer hover/selection states and larger selection indicators.
* Design: Page rows, missing-slot badges, selection summary, option chips, safe-search switch and row action links were refined for readability and alignment.

= 1.6.55 =
* Design: Complete rewrite of the admin CSS into a consolidated WordPress-native design system.
* Design: Consolidated conflicting color, spacing, card, form-control and button systems.
* Accessibility: Added visible focus states and reduced-motion handling across the admin UI.
* Cleanup: Removed dead UI classes and undefined CSS custom-property references.

= 1.6.54 =
* Fixed ACF, queue status, timezone counter, REST capability and legacy plugin detection issues.
* Hardened settings and query initialization behavior.

= 1.6.30 =
* Added REST argument schemas for search and queue routes after capability hardening.
* Confirmed activation no longer deletes or deactivates legacy plugins automatically.

= 1.6.29 =
* Hardened search, suggestions and queue endpoints behind a dedicated capability.
* Limited Shutterstock search and queue batch sizes and tightened REST validation.

= 1.6.28 =
* Fixed keyword search row alignment so the input and Search button sit on one consistent baseline.

= 1.6.27 =
* Unified admin UI styling across buttons, fields, selects, cards, labels, tabs, tables/lists, toggle controls and toasts.
* Removed conflicting checkbox/select CSS and normalized focus, hover and responsive behavior.
* Removed native browser select arrows where they duplicated the WordPress chevron.

= 1.6.24 =
* Removed duplicate dashboard header copy and cleaned settings/connection UI.
* Replaced old checkbox CSS with plugin-specific checkbox chips.
* Added editable Shutterstock connection fields and a Test verbinding action.

= 1.6.14 =
* Added activation-time legacy plugin replacement logging.

= 1.6.13 =
* Changed Shutterstock licensing to use an active image subscription returned for the connected account.
* Updated connection UI while preserving existing server-side tokens during settings saves.

= 1.6.9 =
* Added clearer WordPress.org-style external service disclosure for Shutterstock endpoints, data shared, terms and privacy links.

= 1.6.8 =
* Refactored REST routes into focused route traits and cleaned admin CSS hotfixes.

= 1.6.0 =
* Consolidated premium admin CSS and removed obsolete visual-only styles.

= 1.5.4 =
* Added required OAuth user.view scope for Shutterstock subscription checks.

= 1.5.0 =
* Added active Shutterstock subscription filtering before licensing.
* Moved image license subscription ID to the Shutterstock licensing URL for API compatibility.
* Added contributor/license metadata and release validation documentation.
* Kept the original plugin folder slug so WordPress replaces the existing plugin.

= 1.4.5 =
* Hardened editor queue visibility and removed sensitive details from non-admin dashboard responses.

= 1.4.1 =
* Consolidated the admin design-system stylesheet and aligned settings, cards, controls and workflow layouts.
