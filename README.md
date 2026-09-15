# SEO Image Assistant for Shutterstock

SEO Image Assistant for Shutterstock is a WordPress workflow plugin for finding, reviewing, licensing and attaching Shutterstock images to SEO pages through supported ACF image fields.

## Features

- Shutterstock OAuth connection flow.
- Keyword- and page-based Shutterstock image search.
- ACF image-field mapping.
- Queue-based suggestion/review workflow.
- Licensed asset download and recovery handling.
- Capability-gated REST endpoints.
- Bounded background synchronization for used Shutterstock IDs.
- Optional plugin-data cleanup on uninstall.

## Requirements

- WordPress 6.5 or newer.
- PHP 8.1 or newer.
- Valid Shutterstock API/OAuth credentials for live Shutterstock operations.

Current plugin version: `1.6.79`.

The current compatibility gate verifies clean activation on WordPress 6.5 / PHP 8.1 and WordPress 7.1 / PHP 8.3 with ACF 6.8.9 without making live Shutterstock calls.

## Installation

1. Upload the plugin folder or packaged ZIP to WordPress.
2. Activate **SEO Image Assistant for Shutterstock**.
3. Configure the required Shutterstock OAuth/API credentials through the plugin's supported settings flow.
4. Verify connection and image-search behaviour on staging before using licensing/download workflows on production content.

## Safety model

The current runtime includes safeguards around higher-risk image operations:

- licensed downloads are validated for allowed MIME types and maximum file size before import;
- partial licensing successes are retained as recovery records instead of being discarded;
- used Shutterstock IDs are read from a stored index instead of scanning the complete Media Library for every search;
- OAuth callbacks are narrowed to the expected WordPress admin route and expired state is rejected;
- uninstall data deletion is disabled by default and must be explicitly enabled when permanent cleanup is intended.

Do not commit Shutterstock tokens, OAuth secrets or licensed temporary download URLs to the repository or public issues.

## Development

The production plugin uses a lightweight internal PSR-4-compatible autoloader registered by the main plugin file. Composer is not required as a runtime autoloader for the production plugin.

Useful project files:

- `seo-shutterstock-image-assistant.php` — plugin bootstrap and metadata.
- `includes/` — plugin application/integration logic.
- `build/` — built admin assets.
- `languages/` — translations.
- `readme.txt` — WordPress-format distribution documentation.
- `CHANGELOG.md` — release history.

## License

GPL-2.0-or-later. See `LICENSE` and the plugin metadata.