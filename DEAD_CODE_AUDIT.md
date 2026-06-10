# Dead Code and Legacy Cleanup Audit

Date: 2026-05-06

## Removed now

- `tmp_probe_users.php`
  - Reason: disabled public debug probe, no active references.
- `test_ocr.php`
  - Reason: disabled public OCR test endpoint, no active references.
- `temp/`
  - Reason: generated/test artifacts only.
- `Claimant/temp_ocr/`
  - Reason: OCR scratch folder; claim upload code recreates it when needed.
- `node_modules/`
  - Reason: rebuildable frontend dependency folder.
- `tcpdf/.git/`
  - Reason: nested third-party Git history, not runtime code.
- `tcpdf/examples/`
  - Reason: third-party examples are not referenced by active app code.
- `tcpdf/tools/`
  - Reason: TCPDF utility scripts are not used by the app runtime.
- `css/`, `js/`
  - Reason: old root Bootstrap/static asset copies with no active PHP/HTML references.
- `Admin/css/`, `Admin/js/`, `Claimant/css/`, `Claimant/js/`, `Finance/css/`, `Finance/js/`, `Legal/css/`, `Legal/js/`
  - Reason: duplicate local Bootstrap asset copies with no active PHP/HTML references.

## Keep for compatibility

- `Claimant/form.php`
  - Redirect shim to `Claimant/form_v2.php`.
  - Keep until all bookmarks, docs, and route references use `form_v2.php` directly.
- `Claimant/update_claim.php`
  - Legacy JSON/redirect shim to the redesigned claim form.
  - Keep until old AJAX/update links are fully retired.
- `Legal/reports.php`
  - Compatibility route redirecting reports traffic to `Legal/claims.php`.
- `Finance/reports.php`
  - Compatibility route redirecting reports traffic to `Finance/claims.php`.
- `Admin/load_claim_details.php`, `Legal/load_claim_details.php`, `Finance/load_claim_details.php`
  - Still used by current claim review modals.
- `Claimant/get_claim_details.php`, `Claimant/get_documents.php`
  - Still used by claimant-side detail/document flows.
- `Claimant/navbar.php`, `Admin/navbar.php`, `Legal/navbar.php`, `Finance/navbar.php`
  - Still included by active pages.

## Safe to exclude from published repository

- `node_modules/`
  - Rebuild with `npm install` from `package-lock.json`.
- `vendor/`
  - Rebuild with `composer install` from `composer.json`.
  - Current runtime uses `vendor/autoload.php`, so do not delete from the live XAMPP folder unless Composer dependencies are installed.
- `uploads/`
  - Runtime user/claim files. Keep locally, exclude from source control.
- root sample uploads such as `uploads/stock_items.pdf` and `uploads/My Legal Cases - BizCourt Manager.pdf`
  - Treat as local test data unless deliberately used for demos.
- `tesseract-ocr-w64-setup-5.5.0.20241111.exe`
  - Installer artifact. Keep outside the repo or document it as an external prerequisite.
- `desktop.ini`
  - Windows metadata file. Exclude from source control.
- `index.php.backup`
  - Local backup artifact. Exclude from source control once the current `index.php` is accepted.
- `index_new.html`, `login_new.html`
  - Preview/prototype pages referenced by `IMPLEMENTATION.md`.
  - Exclude after Step 17 either folds their useful notes into documentation or confirms they are no longer needed.

## Verify before deleting

- `Claimant/download_file.php`
  - Hardened in Step 15. It appears unreferenced by current UI, but it maps to chat attachment storage. Delete only after confirming chat attachments are no longer supported.
- `auth.php`
  - Referenced by audit documentation and may still be a compatibility helper. Keep until auth routes are fully consolidated.
- `otp_mailer_config.php`
  - Compatibility shim after SMTP cleanup. Remove only after confirming no deployment scripts include it.
- `udcs.sql`
  - Keep if it is the canonical schema/seed file. If it contains real or messy local data, replace it with a clean schema-only export before publishing.

## Reviewer notes

- Public access to `vendor/` and `tcpdf/` is now blocked with `.htaccess`, but a cleaner repository should still avoid committing generated dependency folders when reproducible install files exist.
- The most important clarity improvement for GitHub/GitLab is not deleting active compatibility routes early. It is documenting which files are shims and excluding generated/runtime data from source control.
