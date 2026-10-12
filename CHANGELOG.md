# Changelog

## 1.0.0 – 2026-10-12

First release as a standalone module (`faithcatholic/massmail`). Before this, it lived in the massmail site's `web/modules/custom/massmail`, with its git history.

### Upgrading from the in-site version

- Set `MASSMAIL_AWS_ACCESS_KEY_ID` and `MASSMAIL_AWS_SECRET_ACCESS_KEY` **before** deploying (see the README). Update 10001 deletes the keys stored in config.
- Run `drush updb`. Update 10002 moves the current source CSVs from `public://` to `private://`. Then delete anything left in the public data directory.
- Make sure the bounce address, if set, is verified in SES (see Fixed).

### Changed

- **Drupal 10.3+ and 11.** Dropped Drupal 8/9. Replaced APIs removed in D10/D11: `file_create_url()`, `file_build_uri()`, `drupal_get_path()`, `file_validate_extensions`, `prepareLegacyRequest()`, and the `database.inc`/`schema.inc` includes.
- **AWS SDK for PHP v3** (was v2 / Guzzle 3).
- **AWS keys come from environment variables** (`MASSMAIL_AWS_ACCESS_KEY_ID`, `MASSMAIL_AWS_SECRET_ACCESS_KEY`), not config. The settings page shows whether each is set.
- **Private files only.** Uploaded CSVs and the send log are stored in `private://<data directory>`, and only users with "Access MassMail mail form" can download them. They used to be in public files, where anyone with the URL could download them.
- **Sending runs as `drush massmail:send test|live`**, started in the background by the form. This replaces the standalone `src/MassMailProcess.php` script and the hard-coded `/usr/local/bin/php` path. Senders still only use the web form.
- The sending logic moved into a service (`massmail.manager`, `src/MassMail.php`). Forms and the status controller get their services injected.
- The send form's file fields now show the current file with a Remove button. Notification addresses are in a collapsible section. If the module isn't fully configured, the form links to the settings page.
- The settings page shows whether the private file system is configured, and checks the data directory (trims slashes, rejects `..`).
- Added a Configure link on the Extend page.
- Removed the unused PHPExcel dependency.

### Fixed

- **"Are you sure?" prompts can now be cancelled.** Save & Test and Save & Send used `alert()`, which only has OK, so the send went ahead regardless.
- **Reply-To and Return-Path (bounce) are now actually sent.** They were inside the SES `Message` block, where SES ignores them. The bounce address is now the SES Return-Path, so it must be verified in SES.
- **One failed email no longer stops the send.** Each failure is logged and sending continues. The log ends with sent and failed counts.
- **Errors show on the status page.** A bad key, a missing file, etc. used to leave it on "Sending..." forever. It now shows "Sending failed" and the error.
- **Uploaded CSVs are kept.** They were never marked permanent, so cron deleted them about 6 hours after upload.
- **Blank or malformed CSV rows are skipped.** On PHP 8 they crashed the whole send.
- **Unknown day or service type codes show blank.** They used to repeat the previous row's label, or cause an undefined-variable warning.
- A test send with only one church sends one email instead of failing.
