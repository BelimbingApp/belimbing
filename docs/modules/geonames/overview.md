# Geonames Module

**Document Type:** Module Documentation
**Purpose:** Document the Geonames module and its reference datasets for countries, admin divisions, postcodes, and cities.
**Last Updated:** 2026-03-29

## Overview

The Geonames module provides geographic reference data imported from [geonames.org](https://www.geonames.org/). It manages countries, admin1 divisions (states/provinces), postcodes, and cities. Data is imported via admin UI or CLI seeders, depending on the dataset and workflow.

Related documents:

- `docs/modules/geonames/cities-integration-review.md`

---

## 1. Module Structure

```text
app/Modules/Core/Geonames/
├── Config/
│   ├── authz.php
│   └── menu.php
├── Database/
│   ├── Migrations/
│   └── Seeders/
│       ├── Concerns/
│       │   └── DownloadsGeonamesFile.php
│       ├── CountrySeeder.php
│       ├── Admin1Seeder.php
│       ├── PostcodeSeeder.php
│       ├── CitySeeder.php
│       └── GeonamesSeeder.php
├── Events/
│   └── PostcodeImportProgress.php
├── Jobs/
│   └── ImportPostcodes.php
├── Models/
│   ├── Country.php
│   ├── Admin1.php
│   ├── Postcode.php
│   └── City.php
├── Routes/
│   └── web.php
└── Services/
    └── GeonamesDownloader.php
```

---

## 2. Data Sources

All data is sourced from [geonames.org](https://www.geonames.org/):

| Dataset | Source File | Scale |
| :--- | :--- | :--- |
| **Countries** | `countryInfo.txt` | Single file, ~250 records |
| **Admin1** | `admin1CodesASCII.txt` | Single file, ~3,800 records |
| **Postcodes** | Per-country `{ISO}.zip` (e.g. `US.zip`) | Can be large |
| **Cities** | `cities15000.zip` | Global city dataset, filtered to larger populated places |

---

## 3. Admin UI

Current pages under `/admin/geonames/`:

- **Countries:** View and refresh country names.
- **Admin1 Divisions:** View and refresh division names.
- **Postcodes:** Import or update per-country postcode data with progress feedback.

Cities are currently stored and seeded, but do not yet have a dedicated admin page. See `docs/modules/geonames/cities-integration-review.md` for recommended integration directions.

---

## 4. Seeders

- **Download strategy:** `GeonamesDownloader` performs fetches with ETag and TTL-aware caching.
- **Shared download concern:** `DownloadsGeonamesFile` centralizes common download behavior for Geonames seeders.
- All downloaded files are cached under `storage/download/geonames/`.
- **CountrySeeder / Admin1Seeder:** Upsert strategy preserves user-edited names.
- **PostcodeSeeder:** Deletes and re-inserts per country transactionally. Keeps ZIPs so subsequent runs can reuse ETag-aware downloads and cached extracted text files.
- **CitySeeder:** Downloads `cities15000.zip`, extracts `cities15000.txt`, then upserts city rows by `geoname_id`.
- **GeonamesSeeder:** Convenience seeder that runs Country, Admin1, Postcode, and City seeders in sequence.

Example CLI usage:

```bash
php artisan db:seed --class=CountrySeeder
```

---

## 5. Postcode Import Flow

1. User selects countries.
2. Livewire dispatches `ImportPostcodes` to the queue.
3. The UI starts a one-off queue worker in the background with `queue:work --once`.
4. The job runs `PostcodeSeeder`, which downloads, parses, and imports per country.
5. Each step broadcasts `PostcodeImportProgress` via Reverb.
6. Frontend listeners update progress and refresh on completion.

**Progress UI:** A generic importing message is shown immediately. Live per-country progress requires Reverb to be configured and running. See `docs/architecture/broadcasting.md`.

**Queue worker:** The UI starts a one-off worker after each dispatch so imports can run without requiring a permanently running worker.

---

## 6. Key Design Decisions

- **Name preservation:** User-edited names for countries and admin1 divisions are preserved during updates.
- **Postcode strategy:** Postcodes are delete-and-reinsert rather than selectively updated.
- **City strategy:** Cities are treated as canonical imported reference data keyed by `geoname_id`.
- **Shared download logic:** Common download behavior is extracted so Geonames seeders stay consistent.
- **Import vs Update:** Postcode import excludes already imported countries; update re-imports existing ones.
- **Update visibility:** Update actions are hidden when the underlying dataset is absent.
