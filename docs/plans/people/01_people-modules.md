# people/01_people-modules

**Status:** Identified
**Last Updated:** 2026-05-11
**Sources:**
- `app/Modules/People/` — current module tree (Config, Employees)
- `app/Modules/Core/Employee/` — core Employee model and domain logic
- `app/Modules/Core/Company/` — company hierarchy used for scoping
- `docs/plans/people/02_payroll-malaysia-top-level-design.md` — Payroll architecture and Malaysia country-pack research
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` — HR2000 i-Payroll parity benchmark
- `docs/plans/people/04_pdf-generation-strategy.md` — PDF rendering infrastructure (complete); Payroll and Self-Service consume `App\Base\Pdf\Jobs\RenderPdfJob` for every visual document
- `docs/architecture/pdf-rendering.md` — renderer surface, template convention (`resources/core/views/pdf/<module>/...`), concurrency model
- `docs/plans/AGENTS.md` — plan conventions
**Agents:** claude-code/opus-4.6, amp/gpt-5.1-codex

## Problem Essence

The People domain currently has only a basic employee listing. An effective HR function requires modules for organizational structure, attendance, leave, claims, payroll, recruitment, performance, training, and employee self-service — none of which exist yet.

## Desired Outcome

A modular HR suite under `app/Modules/People/` that gives a small-to-mid-size business the core people-management capabilities they need, without requiring a separate HRIS. Each module is a self-contained Livewire submodule following the existing pattern (Config, Routes, Livewire components) and scoped to the licensee's company hierarchy.

## Top-Level Components

| Module | Description |
|--------|-------------|
| **Employees** | Employee directory with search, filtering, and status tracking across the company hierarchy. Views employee profiles, employment details, and organizational placement. *(Exists today — listing only.)* |
| **Onboarding** | Structured checklist-driven workflows for new-hire orientation: document collection, equipment provisioning, training assignments, and probation tracking through to confirmation. |
| **Attendance** | Daily attendance recording, clock-in/clock-out tracking, overtime calculation, and attendance summary reports. Supports shift patterns, rotating/complex shifts, conditional allowances that feed payroll, and future mobile/geofenced attendance where needed. |
| **Leave** | Leave entitlement configuration by type (annual, sick, unpaid, etc.), balance tracking, request and approval workflows, carry-forward rules, and team calendar visibility. |
| **Claims** | Employee expense and benefit claim workflows with entitlement policies, document attachments, approval limits, cancellation/withdrawal handling, payroll reimbursement integration, and claim reports. HR2000 parity makes this a first-class People workflow rather than a payroll footnote. |
| **Payroll** | Salary structure definition, allowances and deductions, payslip generation, and payroll run processing. Architecturally, Payroll should be a country-neutral core with extension-based country packs rather than a Malaysia-only module; Malaysia statutory behavior belongs in a first-party `BelimbingApp/blb-payroll-my` pack, while SBG-specific customization belongs privately in `kiatng/blb-sbg`. See `02_payroll-malaysia-top-level-design.md`. |
| **Recruitment** | Job requisition creation, candidate pipeline tracking (application → screening → interview → offer → hire), interview scheduling, and conversion of accepted candidates into employee records. |
| **Performance** | Goal setting, review cycles (probation, annual, ad-hoc), manager and self-assessments, rating scales, and performance improvement plans. Supports configurable review templates. |
| **Training** | Training program catalog, session scheduling, employee enrollment, attendance tracking, certification/expiry management, and training-needs analysis tied to roles or performance outcomes. |
| **Disciplinary** | Incident recording, investigation tracking, disciplinary action workflow (warning → show-cause → hearing → outcome), appeal handling, and case closure with document attachments. |
| **Self-Service** | Employee and manager/supervisor portal for viewing payslips and statutory documents, submitting leave/claim/overtime requests, controlled personal-data change requests, authorized on-behalf requests, subordinate summaries, notifications, announcements, company policies, and employment letters. |
| **Reports** | Cross-module HR analytics: headcount, attrition, leave utilization, attendance trends, payroll summaries, and recruitment pipeline metrics. Configurable date ranges and company-hierarchy scoping. |
