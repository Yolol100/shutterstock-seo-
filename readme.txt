=== SEO Shutterstock Image Assistant ===
Contributors: webactueel
Tags: seo, images, shutterstock, acf, elementor
Requires at least: 6.5
Requires PHP: 8.1
Tested up to: 6.8
Stable tag: 1.3.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Premium-ready workflow tool for finding, reviewing, licensing, and attaching Shutterstock images to SEO pages.

== Description ==
SEO Shutterstock Image Assistant helps agencies find pages missing images, generate Shutterstock search terms, review suggestions, select exactly three images, license after explicit approval, import licensed files to the Media Library, and map attachments to ACF image fields used by Elementor Dynamic Tags.

== Installation ==
1. Upload the folder to /wp-content/plugins/.
2. Upload the production zip or folder containing the built assets.
3. Activate the plugin.
4. Open SEO Images > Settings and connect Shutterstock.
5. Map ACF fields image_1, image_2, image_3 and optionally featured_image.

== External Services ==
This plugin connects to the Shutterstock API only after administrators add API credentials. It sends search and licensing requests needed for the selected workflow. No API secrets are logged.


== Privacy ==
This plugin stores Shutterstock credentials, selected Shutterstock image IDs, queue records and plugin logs in WordPress options. Secrets are masked in the UI, redacted from logs, and saved with autoload disabled; database access should still be treated as privileged because API credentials are stored server-side. The plugin sends search terms, image IDs and licensing requests to Shutterstock only when an authorized user uses the workflow. Site owners should disclose Shutterstock as an external service in their privacy policy when the plugin is enabled.

== QA ==
See docs/QA-100-CHECKLIST.md for the release gate. A final premium release requires live Shutterstock OAuth, sandbox licensing, Plugin Check, PHPCS/WPCS, PHPCompatibility, build, lint and ACF/Elementor workflow verification.

== Changelog ==
= 1.3.8 =
* Replacement-safe package: same plugin folder and main plugin file for WordPress upload replacement.
* Fixed queue status handling fatal error.
* Added conservative queue cleanup and duplicate reservation guard.
* Added QA checklist and translation template.

= 1.3.7 =
* Fixed queue status updates that could fatal during REST recovery, licensing and attach flows.
* Added conservative pruning for old completed queue records.
* Added a short reservation lock to reduce concurrent duplicate licensing attempts.
* Added production QA documentation and translation template packaging.

= 1.3.3 =
* Added bulk redownload for previously licensed Shutterstock assets.
* Added multi-select import to Media Library for selected licensed assets.
* Added per-asset success/failure handling for bulk download and import actions.
* Added admin Quality Gate scorecard, runtime readiness endpoint, stronger accessible image selection states, final UI polish, version consistency checks and v1.1 scorecard documentation.

= 0.9.0 =
Premium admin UI polish: hero header, KPI cards, step headers, selection meter, empty states, asset cards, queue/log presentation, refined badges and responsive layout.

= 0.8.0 =
Patched audit blockers: documented Shutterstock OAuth endpoints, required User-Agent headers, explicit licensing size support, reseller metadata defaults, previously licensed asset redownload route, redownload UI action, and updated package/version metadata.

= 0.7.0 =
Added Proof & Hardening gates: package validator, static security smoke check, Shutterstock contract fixtures, PHPUnit static contract tests, expanded Playwright admin workflow tests, privacy policy helper text, role-permission matrix, live Shutterstock test protocol, release-gate documentation, and stricter CI commands.

= 0.4.0 =
Moved admin UI to React/@wordpress-components, improved settings tabs, OAuth-first licensing requirements, build metadata and menu capability alignment.

= 0.3.0 =
Hardened licensing to require an access token for License & Attach, added optional licensing metadata fields, improved ACF field handling, exposed allowed post types in settings, aligned source/build admin JavaScript, added an ABSPATH guard to generated asset metadata, and filtered editorial-looking search results when editorial is disabled.

= 0.2.0 =
Added settings save/test routes, queue processing, duplicate protection, ACF validation, dashboard stats, logs UI, and stricter REST validation.

= 0.1.0 =
Initial MVP architecture.
