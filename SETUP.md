# Setup Guide

This guide describes the current UDCS setup. It replaces older UI-only setup notes.

## Requirements

- XAMPP or equivalent Apache/MySQL/PHP stack
- PHP 8.0 or newer
- MySQL or MariaDB
- Node.js and npm for Tailwind CSS
- Composer if `vendor/` is not already present
- Tesseract OCR installed locally if OCR validation is used

## Database

1. Create a database named `udcs`.
2. Import `udcs.sql`.
3. Confirm `connect.php` points to the correct host, user, password, and database name.

Current local default:

```php
mysqli_connect("localhost", "root", "", "udcs");
```

## Frontend Build

Install Node dependencies:

```powershell
npm install
```

Build CSS:

```powershell
npm run build:css
```

Watch CSS during development:

```powershell
npm run watch:css
```

Do not edit `assets/css/output.css` by hand. Edit:

- `assets/css/input.css`
- `assets/css/tokens.css`
- `assets/css/global.css`
- `assets/css/app-overrides.css`
- `assets/css/role-pages.css`

## PHP Dependencies

The mailer uses PHPMailer through `vendor/autoload.php`.

If `vendor/` is missing, install dependencies:

```powershell
composer install
```

## SMTP Configuration

SMTP values are read from environment variables through `getenv()`.

Required:

```text
SMTP_HOST
SMTP_PORT
SMTP_USERNAME
SMTP_PASSWORD
SMTP_FROM_EMAIL
SMTP_FROM_NAME
```

Use `.env.example` as a reference, but note that this application does not automatically parse `.env` files. Configure the variables in Apache/PHP/XAMPP or the system environment.

## Runtime Folders

These folders are runtime data and should not be committed:

- `uploads/`
- `temp/`

Claim documents should be accessed through:

```text
document_access.php?id=<document_id>
```

Do not link directly to raw uploaded claim files.

## Compatibility Routes

These files intentionally remain as shims:

- `Claimant/form.php`
- `Claimant/update_claim.php`
- `Legal/reports.php`
- `Finance/reports.php`

Do not delete them until old bookmarks, JavaScript calls, and route references are fully retired.

