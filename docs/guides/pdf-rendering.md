# PDF Rendering Prerequisites

**Document Type:** Setup Guide
**Scope:** Developer environment prerequisites for working on `app/Base/Pdf` and any module that produces PDFs
**Related:** `docs/plans/people/04_pdf-generation-strategy.md`

---

## What BLB uses to make PDFs

| Concern | Tool | Bundled? |
|---|---|---|
| HTML → PDF rendering | Chromium via Playwright (Node) | Yes — `playwright` is in `package.json` |
| Subprocess driver | `app/Modules/Core/AI/Services/Browser/PlaywrightRunner` | Yes |
| Renderer service | `app/Base/Pdf/Services/PdfRenderer` | Yes |
| Post-processing (password, encryption, merge) | `qpdf` CLI | **No — install separately** |

`qpdf` is invoked as an external subprocess. Its Apache-2.0 license does not propagate to BLB. `qpdf` is only required when Phase 2 `PdfPostProcessor` features (password protection, merging, etc.) are exercised; the renderer itself produces PDFs without it.

---

## Install qpdf

Pick the command that matches your dev host.

### Linux (Debian/Ubuntu)

```bash
sudo apt-get update && sudo apt-get install -y qpdf
```

### Linux (RHEL/Fedora)

```bash
sudo dnf install -y qpdf
```

### macOS

```bash
brew install qpdf
```

### Windows (winget)

```powershell
winget install --id QPDF.QPDF --accept-package-agreements --accept-source-agreements
```

The winget package is a Nullsoft installer and requires elevation (UAC). User-scope install is not currently supported by upstream; the install is machine-scope.

### Docker / FrankenPHP images

Add `qpdf` to the system package install layer in the image. For the Debian-based FrankenPHP base, add `qpdf` alongside the existing `apt-get install` line in the Dockerfile.

### Verify

```bash
qpdf --version
```

You should see `qpdf version 11.x` or newer. Any 11.x or 12.x release covers the operations BLB depends on (password protection, encryption, merge).

---

## Playwright / Chromium

`bun install` (or `npm install`) brings in the `playwright` package. The first PDF render also needs the Chromium binary to be downloaded:

```bash
bunx playwright install chromium
```

This is required once per machine. CI images that pre-bake the Chromium binary skip this step.

---

## Known dev-environment gaps on Windows

The PHP install bundled with FrankenPHP on Windows (`C:\Users\<user>\.frankenphp\php.exe`) does not ship a `php.ini`. The `extension_dir` points to `C:\php\ext` by default, which may not exist. Pest will fail with `Call to undefined function mb_strimwidth()` or similar until extensions are loaded.

Run tests with explicit extension flags until a project `php.ini` lands:

```bash
php \
  -d extension_dir="C:\\Users\\<user>\\.frankenphp\\ext" \
  -d extension=mbstring \
  -d extension=pdo_sqlite \
  -d extension=sqlite3 \
  -d extension=fileinfo \
  -d extension=openssl \
  -d extension=curl \
  -d extension=intl \
  -d extension=zip \
  vendor/bin/pest --filter=PdfRendererSpike --no-coverage
```

WSL2 / Linux installs use the OS-managed PHP and do not hit this issue.

---

## Where PDFs land

By default `app/Base/Pdf/Services/PdfArtifactWriter` writes to the Laravel `local` disk under `pdf-artifacts/YYYY/MM/DD/<sha256>.pdf`. Configure via `config/pdf.php` or environment:

| Variable | Default | Purpose |
|---|---|---|
| `BLB_PDF_DISK` | `local` | Filesystem disk name from `config/filesystems.php` |
| `BLB_PDF_ARTIFACT_DIR` | `pdf-artifacts` | Top-level directory inside the disk |
| `BLB_PDF_SIGNED_URL_TTL` | `60` | Seconds before a signed render token expires |
| `BLB_PDF_RENDER_TIMEOUT` | `30` | Per-render timeout in seconds (Chromium `page.goto`) |
| `BLB_PDF_PAPER_FORMAT` | `A4` | Default paper size |
| `BLB_PDF_PRINT_BACKGROUND` | `true` | Whether Chromium prints background colors/images |

For production, point `BLB_PDF_DISK` at a configured S3, MinIO, or similar disk that survives container restarts and supports lifecycle policies on the artifact directory.
