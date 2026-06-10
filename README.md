# Unified Digital Claims System

UDCS is a PHP/XAMPP claim workflow system for deceased-customer asset claims. It supports claimant intake, legal review, finance review, admin oversight, document access control, notifications, PDF exports, and role-specific dashboards.

## Active Portals

- Public entry: `index.php`
- Unified access: `access.php`
- Staff login: `login.php`
- Claimant access: `claimant-access.php`
- Claimant portal: `Claimant/dashboard.php`
- Admin portal: `Admin/dashboard.php`
- Legal portal: `Legal/dashboard.php`
- Finance portal: `Finance/dashboard.php`

## Core Directories

- `Claimant/` claimant intake, claim views, exports, profile, and messaging.
- `Admin/` accounts, claims review, activity trail, exports, and oversight dashboard.
- `Legal/` legal queue, review actions, exports, and legal notifications.
- `Finance/` finance queue, verification actions, exports, and finance notifications.
- `components/` shared UI, workflow, claim, notification, PDF, and document helpers.
- `assets/css/` design tokens, Tailwind input/output, global styles, and shared overrides.
- `uploads/` runtime claim/user files. Do not publish this folder.
- `temp/` runtime/generated artifacts. Do not publish this folder.
- `tcpdf/` bundled TCPDF library used for PDF exports.
- `vendor/` Composer dependencies used by email flows.

## Current Workflow

1. Claimant submits a claim through `Claimant/form_v2.php`.
2. Documents are staged and validated by `components/claim_documents.php`.
3. Claim structure and cleanup logic live in `components/claims_v2.php`.
4. Legal reviews the claim in `Legal/claims.php`.
5. Finance reviews settlement/asset verification in `Finance/claims.php`.
6. Admin monitors claims, users, and activity in `Admin/`.
7. Claim documents are served through `document_access.php`, not raw upload links.

## Setup

Use XAMPP with Apache, MySQL/MariaDB, and PHP. Import `udcs.sql` into a database named `udcs`, then confirm `connect.php` matches the local database credentials.

Install frontend dependencies and build CSS:

```powershell
npm install
npm run build:css
```

Install PHP dependencies if `vendor/` is not present:

```powershell
composer install
```

Mail sending expects SMTP environment variables:

```text
SMTP_HOST
SMTP_PORT
SMTP_USERNAME
SMTP_PASSWORD
SMTP_FROM_EMAIL
SMTP_FROM_NAME
```

The app currently reads those through `getenv()`. In XAMPP, set them through the server environment or Apache/PHP configuration.

## Important Runtime Notes

- `uploads/` contains sensitive runtime files and should stay out of source control.
- `document_access.php` is the controlled document gateway for claim files.
- `Claimant/form.php`, `Claimant/update_claim.php`, `Legal/reports.php`, and `Finance/reports.php` are compatibility shims.
- `DEAD_CODE_AUDIT.md` documents what was removed, what should stay, and what should be excluded before publishing.
- `vendor/` and `tcpdf/` are blocked from direct web access with `.htaccess`.

## Verification Commands

Run syntax checks after PHP changes:

```powershell
C:\xampp\php\php.exe -l path\to\file.php
```

Run a full PHP syntax sweep when preparing handoff:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
```

