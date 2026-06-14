# SEO Image Assistant for Shutterstock

Premium WordPress workflow plugin for finding, reviewing, licensing and attaching Shutterstock images to SEO pages via ACF fields.

## Features

- Shutterstock OAuth connection flow.
- Keyword and page-based image search.
- ACF image field mapping.
- Queue-based suggestion workflow.
- Licensed asset download and recovery tools.
- Capability-gated REST API endpoints.
- Optional cleanup on uninstall.

## Requirements

- WordPress 6.5 or newer.
- PHP 8.1 or newer.
- Valid Shutterstock API/OAuth credentials.

## Development

The production plugin uses a lightweight internal PSR-4-compatible autoloader registered in the main plugin file. No Composer runtime is required.

## License

GPL-2.0-or-later.


## 1.6.78 hardening notes

- Activation is PHP 8.1-compatible and no longer runs a full Media Library sync immediately.
- Licensed downloads are checked for allowed MIME type and maximum file size before import.
- Partial Shutterstock licensing responses keep successful license records available for recovery.
- Search uses the stored used-ID index instead of scanning all attachments on every request.
- Uninstall data deletion is disabled by default; enable it explicitly only when permanent cleanup is intended.
