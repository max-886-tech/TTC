# TheTrueCerts TTC Reader / Converter

This package is the protected PDF dumps reader system for **thetruecerts.com**.

It converts PDF dumps into encrypted `.ttc` files and opens them through a controlled Windows reader with access-code validation, device binding, expiry checks, and watermarking.

> This is a deterrence system, not unbreakable DRM. It is meant to reduce casual copying, sharing, printing, and screenshots.

## TTC setup included

- Protected file extension: `.ttc`.
- Internal file magic: `TTCF`.
- Reader target: `ttc-reader.exe`.
- Converter target: `ttc-converter.exe`.
- MSI installer name: **TheTrueCerts Reader**.
- File association: `.ttc` opens with TheTrueCerts Reader.
- Default server/API domain: `thetruecerts.com`.
- Server UI wording: dumps/access-code workflow only.
- Default code prefix: `TTC`.

## Main folders

```text
core/           Shared crypto and TTC container format
converter/      Windows GUI converter: PDF -> .ttc
reader-win32/   Windows reader app for .ttc files
server/         PHP license admin + API endpoints
installer/      WiX MSI installer definition
.github/        Windows build workflow
```

## Build on Windows

```bash
cmake -S . -B build
cmake --build build --config Release
```

Expected output:

```text
build/Release/ttc-reader.exe
build/Release/ttc-converter.exe
```

To build the MSI, install WiX Toolset and run:

```bash
cmake --build build --config Release --target ttc-reader-msi
```

Expected MSI:

```text
build/TheTrueCertsReader.msi
```

## Server deployment

Upload the `server/` folder contents to the web root for `thetruecerts.com`, then update:

```php
server/config.php
```

Important values to confirm before production:

- `DB_DSN`, `DB_USER`, `DB_PASS`
- `CODE_PEPPER`, `DEVICE_PEPPER`
- `R2_BUCKET`
- `R2_ENDPOINT`
- `R2_PUBLIC_BASE`
- `R2_ACCESS_KEY`, `R2_SECRET_KEY`
- `APP_DEBUG` should be `false` on production

The C++ reader currently calls:

```text
https://thetruecerts.com/api/get_download.php
https://thetruecerts.com/api/validate.php
```

## Database compatibility note

The UI says “Dumps Name” and “Dumps Code,” but the PHP server keeps the existing database columns `exam_name` and `exam_id` for compatibility with your current schema. This avoids requiring a migration just for the rebrand.

## Security notes

The uploaded source contained hardcoded server/database/R2 credentials. They are preserved in the package because they were already in your source, but for production you should move secrets to environment variables and rotate keys if this ZIP is shared anywhere.
