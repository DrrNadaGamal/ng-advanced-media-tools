# NG Advanced Media Tools

WordPress plugin with two media tools: import images from a URL, and find unused (orphan) media with a quarantine and restore step instead of instant deletion.

Product direction, scope and delivery: Nada Gamal.

## Import from URL
Paste up to 20 image URLs (Media > Media Tools). Accepts JPG, PNG, GIF, WebP up to 10 MB; the file content is checked, not just the extension. Private, local and reserved addresses are refused (SSRF guard), as are non-http(s) schemes. Optional alt text; the source URL is saved on the attachment. Only imports images you have the right to use.

## Unused media finder
Scans posts and pages (not trashed ones), all post meta (page builders such as Elementor store images there), options and widgets, featured images, site icon and logo, block image IDs and file names including resized variants. Results are a checklist. "Move to quarantine" moves the original and every size (and WebP/AVIF copies) into `uploads/ng-quarantine`; the attachment stays in the database. Restore puts files back. Permanent delete works only on quarantined items. Quarantine, restore and delete need the administrator role.

## Tests
`tests/run-tests.php`: 31 checks, 0 failed on current WordPress with PHP 8.2 (WordPress Playground, import tested against a local mock server): URL and IP rejection, jpg import with sizes and alt, non-image and disguised-text rejection, oversize and 404 handling, nine orphan-detection cases (content, featured, builder meta, block ID, widget option, trashed-only), quarantine/restore/purge safety, capability check, 4-language page, escaping.

## Honest limits
- Detection is text matching: an image referenced only by code, a theme file, or a plugin table is not seen and could be listed as unused. Always review the list and use quarantine first.
- The hostname check resolves DNS once; a hostile DNS that changes answers between the check and the download is not covered beyond WordPress's own request protections.
- Not tested with Elementor/Bricks pages in a real editor, or on a live host. FR/DE labels are machine translations.
