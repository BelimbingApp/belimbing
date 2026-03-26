# Core Quality Module

## Overview

The Core Quality module provides industry-standard **NCR / CAPA / SCAR** quality management, aligned with ISO 9001 and IATF 16949 vocabulary. It is designed as a reusable BLB framework module that licensees extend with organisation-specific fields, approval gates, and numbering formats.

**Path:** `app/Modules/Core/Quality/`
**Migration prefix:** `0200_01_25_*`
**Workflow flows:** `quality_ncr`, `quality_scar`

---

## Domain Model

| Model | Table | Purpose |
|-------|-------|---------|
| `Ncr` | `quality_ncrs` | Nonconformance Report — a logged quality issue |
| `Capa` | `quality_capas` | Corrective Action / Preventive Action — the resolution work package (child of NCR) |
| `Scar` | `quality_scars` | Supplier Corrective Action Request — supplier-facing workflow |
| `QualityEvidence` | `quality_evidence` | Typed evidence attachments (polymorphic) |
| `QualityEvent` | `quality_events` | Non-transition domain events |
| `QualityActionItem` | `quality_action_items` | Tracked action items with owners and deadlines |

### Key Relationships

- `Ncr` → `HasOne Capa`, `HasMany Scar`, `MorphMany QualityEvidence`
- `Capa` → `BelongsTo Ncr`, `MorphMany QualityEvidence`
- `Scar` → `BelongsTo Ncr`, `MorphMany QualityEvidence`

### NCR Kind Discriminator

`Ncr.ncr_kind` distinguishes case sources using a single model:

| Value | Label |
|-------|-------|
| `internal` | Internal Corrective Action |
| `customer` | Customer Complaint |
| `incoming_inspection` | Incoming Inspection |
| `process` | Process Nonconformance |

---

## Workflows

Both `Ncr` and `Scar` implement `HasWorkflowStatus` and integrate with the Base Workflow engine.

### NCR Flow (`quality_ncr`)

```
open → under_triage → assigned → in_progress → under_review → verified → closed
                  ↘ rejected        ↗ (rework)            ↘ rejected
open → rejected
```

| Status | Kanban |
|--------|--------|
| `open` | backlog |
| `under_triage` | active |
| `assigned` | active |
| `in_progress` | active |
| `under_review` | active |
| `verified` | active |
| `closed` | done |
| `rejected` | done |

### SCAR Flow (`quality_scar`)

```
draft → issued → acknowledged → containment_submitted → under_investigation
                             ↘ under_investigation ←──────────┘
→ response_submitted → under_review → verification_pending → closed
                                   ↘ action_required → response_submitted (resubmit)
draft/issued → cancelled
draft → rejected
```

| Status | Kanban |
|--------|--------|
| `draft` | backlog |
| `issued` – `verification_pending` | active |
| `closed` | done |
| `rejected` | done |
| `cancelled` | done |

---

## Services

### `NcrService`

Domain service for NCR lifecycle. All mutations go through this service so Livewire pages, APIs, and extensions share the same logic.

| Method | Description |
|--------|-------------|
| `open(Actor, array)` | Create NCR + initial CAPA + StatusHistory |
| `triage(Ncr, Actor, array)` | Transition to `under_triage`, record triage on CAPA |
| `assign(Ncr, Actor, array)` | Transition to `assigned`, set owner |
| `submitResponse(Ncr, Actor, array)` | Submit investigation findings → `under_review` |
| `review(Ncr, Actor, array)` | Approve → `verified` or request rework → `in_progress` |
| `verify(Ncr, Actor, array)` | Confirm effectiveness → `verified` |
| `close(Ncr, Actor, array)` | Close the NCR |
| `reject(Ncr, Actor, array)` | Reject as invalid |

### `ScarService`

Domain service for SCAR lifecycle.

| Method | Description |
|--------|-------------|
| `create(Actor, Ncr, array)` | Create SCAR in draft, linked to NCR |
| `issue(Scar, Actor, array)` | Issue to supplier |
| `acknowledge(Scar, Actor, array)` | Supplier acknowledges receipt |
| `submitContainment(Scar, Actor, array)` | Submit containment action |
| `submitResponse(Scar, Actor, array)` | Submit investigation response |
| `review(Scar, Actor, array)` | Accept or request revision |
| `verify(Scar, Actor, array)` | Verify effectiveness and close |
| `close(Scar, Actor, array)` | Close a verified SCAR |

### `EvidenceService`

File operations for typed evidence attachments.

| Method | Description |
|--------|-------------|
| `upload(Model, UploadedFile, string, ?int, array)` | Upload and create `QualityEvidence` record |
| `replace(QualityEvidence, UploadedFile, ?int)` | Replace file, delete old from storage |
| `archive(QualityEvidence)` | Delete record and file from storage |

### `DefaultNumberingService`

Default implementation of `NumberingService` — sequential numbering with configurable prefix (e.g. `NCR-000001`). Licensees override this binding to provide custom formats.

---

## Extension Points

### `NumberingService` Contract

```php
interface NumberingService
{
    public function nextNcrNumber(string $ncrKind): string;
    public function nextScarNumber(): string;
}
```

Bound to `DefaultNumberingService` by the module. Licensees rebind in their own `ServiceProvider` to implement custom numbering (e.g. SB Group's `NextNumber2` / `NextNumber7` format).

### `metadata` JSON Column

All core tables include a nullable `metadata` JSON column for licensee-specific data that doesn't warrant a dedicated column.

### Workflow Seeders

Licensees can seed additional statuses and transitions into the `quality_ncr` or `quality_scar` flows (e.g. SB Group adds `pending_hod_approval`, `follow_up_pending`, `follow_up_completed`).

---

## Configuration

Published as `config('quality')`:

| Key | Description |
|-----|-------------|
| `ncr_kinds` | Supported NCR kind codes and labels |
| `severity_levels` | Severity levels: critical, major, minor, observation |
| `evidence_types` | 9 normalized evidence type codes |
| `scar_request_types` | SCAR request type options |
| `numbering.ncr_prefix` | Default NCR number prefix |
| `numbering.scar_prefix` | Default SCAR number prefix |
| `numbering.pad_length` | Zero-pad length for sequential numbers |

---

## Authorization

29 capability keys registered via `Config/authz.php`:

- **NCR:** `quality.ncr.{create,view,triage,assign,respond,review,verify,close,reject}`
- **SCAR:** `quality.scar.{create,view,issue,review,accept,rework,close,cancel,reject}`
- **Evidence:** `quality.evidence.{upload,view}`
- **Knowledge/Reports:** `quality.knowledge.view`, `quality.report.view`
- **Workflow transitions:** `workflow.quality_ncr.*`, `workflow.quality_scar.*`

---

## Routes

All routes require `auth` middleware with `authz:` capability checks.

| Route Name | URL Path | Component |
|------------|----------|-----------|
| `quality.ncr.index` | `quality/ncr` | `Ncr\Index` |
| `quality.ncr.create` | `quality/ncr/create` | `Ncr\Create` |
| `quality.ncr.show` | `quality/ncr/{ncr}` | `Ncr\Show` |
| `quality.scar.index` | `quality/scar` | `Scar\Index` |
| `quality.scar.create` | `quality/scar/create` | `Scar\Create` |
| `quality.scar.show` | `quality/scar/{scar}` | `Scar\Show` |

---

## File Structure

```
app/Modules/Core/Quality/
├── ServiceProvider.php
├── Config/
│   ├── authz.php
│   ├── menu.php
│   └── quality.php
├── Contracts/
│   └── NumberingService.php
├── Database/
│   ├── Migrations/          (6 migrations, prefix 0200_01_25_*)
│   ├── Seeders/
│   │   ├── NcrWorkflowSeeder.php
│   │   └── ScarWorkflowSeeder.php
│   └── Factories/
│       ├── NcrFactory.php
│       └── ScarFactory.php
├── Models/
│   ├── Ncr.php
│   ├── Capa.php
│   ├── Scar.php
│   ├── QualityEvidence.php
│   ├── QualityEvent.php
│   └── QualityActionItem.php
├── Services/
│   ├── NcrService.php
│   ├── ScarService.php
│   ├── DefaultNumberingService.php
│   └── EvidenceService.php
├── Livewire/
│   ├── Ncr/
│   │   ├── Index.php
│   │   ├── Create.php
│   │   └── Show.php
│   └── Scar/
│       ├── Index.php
│       ├── Create.php
│       └── Show.php
└── Routes/
    └── web.php

resources/core/views/livewire/quality/
├── ncr/
│   ├── index.blade.php
│   ├── create.blade.php
│   └── show.blade.php
└── scar/
    ├── index.blade.php
    ├── create.blade.php
    └── show.blade.php
```
