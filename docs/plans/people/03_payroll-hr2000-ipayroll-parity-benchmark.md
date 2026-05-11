# people/03_payroll-hr2000-ipayroll-parity-benchmark

**Status:** Phase 4 in progress — Malaysia EPF contribution calculator in place
**Last Updated:** 2026-05-11
**Sources:**
- HR2000 i-Payroll official writeup PDF — https://www.hr2000.com.my/downloads/writeup.ipayroll.pdf
- HR2000 public product page — https://www.hr2000.com.my/
- HR2000 iPayroll Google Play listing — https://play.google.com/store/apps/details?id=com.hrserver.ipayroll
- HR2000 iPayroll Apple App Store listing — https://apps.apple.com/us/app/hr2000-ipayroll/id1636253223
- HR2000 i-Payroll login page — https://www.ipayroll.com.my/Account/Login
- HR2000 Quick Pay user manual, used only for legacy payroll workflow clues — https://www.hr2000.com.my/downloads/manual.qpay.pdf
- `docs/plans/people/01_people-modules.md` — People suite scope
- `docs/plans/people/02_payroll-malaysia-top-level-design.md` — Payroll architecture and Malaysia country-pack design
**Agents:** amp/gpt-5.1-codex

## Problem Essence

HR2000 i-Payroll is a mature Malaysia-focused HRMS/payroll product. Belimbing should benchmark against it to understand the minimum credible feature surface for Malaysian SMB payroll and HR operations, without copying its implementation or accepting its product constraints.

## Desired Outcome

Use HR2000 i-Payroll as a parity reference for BLB's People and Payroll roadmap. Parity means BLB can support the same important operational jobs — payroll processing, statutory compliance, employee self-service, leave/claim/overtime workflows, document access, reporting, audit, and mobile-friendly employee actions — while preserving BLB's own architecture: self-hosted, git-native, extension-based, country-neutral payroll core plus Malaysia country pack. Parity is measured by operational outcomes, not by copying HR2000's tenancy model, hosted operating model, user interface, or Malaysia-centric architecture.

## HR2000 i-Payroll Product Shape

HR2000 positions i-Payroll as a web-based Employee Self-Service HRMS for Malaysian e-Payroll, e-HR, e-Leave, e-Claim, e-TMS, e-Appraisal, e-Document, e-ESS, e-Request, e-Overtime, and mobile apps.

The product appears to optimize for integrated Malaysian HR administration rather than global payroll architecture. Its strengths are breadth, local statutory payroll familiarity, ESS, manager approval workflows, mobile clock-in/out, document distribution, reports, and hosted support. Its tradeoffs relative to BLB are that it is vendor-hosted/managed, Malaysia-centric, and likely has a more traditional UI and extension model.

The main benchmark lesson is that payroll calculation parity is insufficient. HR2000's market value comes from the whole chain that feeds and governs payroll: Leave, Claims, Attendance/Overtime, ESS/MSS, statutory and bank outputs, approvals, role security, period locking, audit trail, and employee document delivery.

## Benchmark Feature Inventory

| Area | HR2000 i-Payroll capability observed | BLB parity implication |
|------|--------------------------------------|------------------------|
| **Payroll cycles** | One company per database, unlimited employee records, five payroll cycles, unlimited years of data storage, monthly/daily/hourly payment support. | BLB Payroll Core needs multiple pay cycles, historical run retention, monthly/daily/hourly inputs, and payroll calendar configuration. Avoid one-company-per-database as a hard architectural constraint; use company/pay-entity scoping instead. |
| **Malaysia statutory payroll** | Auto EPF, SOCSO, EIS, and PCB computation; claims PCB computation is endorsed by LHDN. Government reports are included. | `BelimbingApp/blb-payroll-my` must cover EPF/SOCSO/EIS/PCB with official effective-dated tables/formulas, validation fixtures, and export/report outputs. PCB verification strategy is a parity risk and should be researched before compliance claims. |
| **Payroll outputs** | Payslips, CP8A/EA-style annual forms, PCB2 forms, government reports, management reports, free-format reports, and internet banking modules. Export to text, PDF, Excel, Word, images, RTF, HTML. | BLB needs payslips, EA/CP8A, PCB2, statutory monthly files/reports, bank files, management reports, and exportable tables. Prioritize CSV/XLSX/PDF/text first; avoid legacy formats unless customers need them. |
| **Payroll document distribution** | E-mail and ESS payslip, CP8A and PCB2 forms. e-Document supports password-encrypted PDF and web/e-mail access. | BLB Self-Service should provide payslip and tax-document access. E-mail delivery and PDF passwording are useful parity items, but self-hosted secure portal access should be the primary path. |
| **HR master data** | Career development, accident, achievement, address, benefit, discipline, education, employment history, event, family, insurance, non-pay leave, skill, training. | BLB People modules should include a richer employee profile over time. The list helps define HR master-data breadth beyond payroll. |
| **Leave** | ESS/MSS leave application and approval, entitlement policies, replacement leave with expiry, burn leave, cancel/withdraw, full/half-day, advance leave, max days per application, document attachments, multi-tier/multi-level approval, state holidays, employee/company leave calendars, year planner, e-mail notifications. | BLB Leave should cover entitlement policies, state holidays, approval workflows, attachments, calendars, replacement/carry-forward/expiry rules, cancellations/withdrawals, and notifications. This is a major parity area, not an afterthought. |
| **Claims** | ESS claim application, MSS claim approval, claim entitlement by single value/range/service year, cancel/withdraw, advance claims, approver amount limits, attachments, claim reports. | BLB should treat Claims as a People/Finance-adjacent workflow with entitlements, approval limits, attachments, payroll reimbursement integration, and reporting. |
| **Time management** | Time Management supports basic attendance through complex 24-hour rotating shifts, unlimited shift patterns, conditional allowances, overtime application, exports, and query settings. | BLB Attendance should account for shift patterns, 24-hour/rotating shifts, conditional allowances feeding payroll, overtime workflows, and reporting. |
| **Overtime** | ESS overtime application, MSS approval, cancel/withdraw approved overtime, attachment support, overtime reports. | BLB should model overtime as an approval workflow feeding payroll, not merely as manual payroll input. |
| **ESS/MSS** | Web login for employees and supervisors, profile viewing, leave/claim/overtime application, on-behalf applications, e-Request for personal data changes, supervisor approvals, subordinate summaries, notifications, announcements, encrypted documents. | BLB Self-Service should include employee and supervisor roles, on-behalf workflows where authorized, personal-data change requests, subordinate dashboards, notifications, announcements, and document access. |
| **Mobile app** | Mobile apps on Google Play, Apple App Store, and Huawei AppGallery. Features include login/stay connected, forgot/reset password, GPS/geofenced clock in/out, device binding, clock reminders, leave/claim/overtime application and approval, e-Request, calendar views, notifications, documents, company events, own profile. Google Play shows 100K+ downloads. | BLB should be mobile-friendly from the web first. Native apps are not necessary for initial parity unless SBG requires device binding or native push; GPS/geofence clock-in/out is the main mobile-specific capability to plan for. |
| **Organization and payroll charts** | Organization chart exportable to PNG; payroll charts in bar, line, radar, polar area, pie, doughnut, exportable to PNG. | BLB Reports can provide org chart and payroll cost visualization later. Useful parity, lower priority than payroll correctness and workflows. |
| **Training videos/help** | In-system Video Guide organized by module, with YouTube training videos. | BLB should eventually provide in-app guidance and help surfaces, especially for payroll month-end and statutory setup. This aligns with AI-native assistance but should not block payroll core. |
| **Security/admin** | SSO and 2FA support, role/access rights, audit trail, payroll period locking. | BLB must treat role-based payroll access, audit trail, MFA/SSO readiness, and payroll period/run locking as baseline requirements. |
| **Hosting/operations** | HR2000 hosts and manages the system, with SSL, RAID, firewall, antivirus, DDoS protection, auto backup, 24/7 monitoring, multi-location backups, support, software updates, and 99.75% paid-service uptime claim in SLA materials. | BLB is self-hosted, so parity should be operational runbooks and built-in backup/health/deployment support, not SaaS hosting. This is a deployment/operations parity category, not a product-feature copy. |

## Parity Targets for BLB

### Payroll v1 parity target

BLB Payroll is credible against HR2000 when it can:

- Run Malaysian monthly payroll with salary, hourly/daily wages, allowances, deductions, overtime, unpaid leave, bonus/additional remuneration, claims reimbursements, and adjustments.
- Calculate EPF, SOCSO, EIS, PCB, zakat treatment, and HRD levy through `BelimbingApp/blb-payroll-my`.
- Produce explainable payslips and statutory line details.
- Generate monthly statutory reports/files and bank payment files.
- Lock/approve/close payroll periods and preserve an audit trail.
- Provide employee self-service access to payslips and annual tax documents.
- Export payroll and statutory reports to practical formats: PDF, CSV/XLSX, and statutory text files.

### People-suite parity target around Payroll

Payroll alone is not enough. HR2000's perceived value comes from adjacent workflows feeding payroll:

- Leave entitlements, approvals, state holidays, calendars, and leave-to-payroll effects.
- Claims entitlements, approvals, attachments, and reimbursement-to-payroll effects.
- Attendance, shifts, mobile clock-in/out, overtime approval, and attendance-to-payroll effects.
- Employee profile updates through e-Request/approval rather than direct uncontrolled edits.
- Supervisor dashboards for subordinate leave, claim, attendance, and approval workload.

## Where BLB Should Intentionally Differ

- **Architecture:** HR2000 appears Malaysia-centric; BLB should keep a country-neutral Payroll Core plus country-pack extensions.
- **Deployment:** HR2000 is vendor-hosted/managed; BLB should remain self-hosted and licensee-owned.
- **Customization:** HR2000 offers reports/plugins/custom services; BLB should make customization git-native through private licensee repos such as `kiatng/blb-sbg` and public first-party packs such as `BelimbingApp/blb-payroll-my`.
- **Auditability:** BLB should make statutory calculation explanations and effective-dated rule versions first-class, not just computed results.
- **AI assistance:** BLB can eventually exceed parity with guided setup, anomaly detection, payroll-run review, and change explanations, but these should come after deterministic payroll correctness.
- **Compliance claims:** HR2000's LHDN-endorsed PCB claim sets a market expectation, but BLB should distinguish “implements official formulas/tables and validates against fixtures” from “formally verified/endorsed” until the verification path is confirmed.
- **Mobile:** HR2000's native mobile app and geofenced clock-in/out are useful benchmarks, but BLB should validate SBG's need before treating native mobile/device-binding as payroll v1 scope. Responsive/PWA self-service may be enough initially.

## Gaps in Current BLB Plans

Current People/Payroll plans already cover the broad module map and country-pack architecture. HR2000 benchmarking adds sharper parity requirements:

- Leave and Claims need entitlement policies, attachments, cancellations/withdrawals, approval limits, and payroll integration.
- Attendance needs shifts, rotating shifts, conditional allowances, overtime workflow, and mobile/geofenced clock-in/out planning.
- Self-Service needs employee and manager modes, on-behalf applications, e-Request profile changes, notifications, announcements, and document access.
- Payroll needs bank files, statutory forms, management reports, practical exports, period locking, audit trail, and role security as baseline scope.
- Operations need backup/recovery/health runbooks because BLB does not outsource hosting to a SaaS vendor.

## Parity Classification

| Classification | Items |
|----------------|-------|
| **Must-have for payroll credibility** | Malaysian statutory calculations, explainable payslips, monthly statutory files/reports, bank payment files, annual employee tax/statutory documents, payroll locking, approvals, audit trail, role-based payroll security. |
| **Must-have for payroll-adjacent workflow parity** | Leave-to-payroll effects, claims reimbursements, attendance/overtime inputs, employee/manager self-service, controlled profile-change requests, document access, notifications. |
| **Later parity enhancers** | Native mobile app, GPS/geofence/device binding, org chart graphics, payroll chart image exports, in-app video guides, broad legacy export formats beyond PDF/CSV/XLSX/statutory text files. |
| **Intentionally different in BLB** | Self-hosted deployment, country-neutral Payroll Core, public country-pack repositories, private licensee customization repos, git-native changes, effective-dated statutory data and calculation explanations. |

## Phases

- [x] **Phase 0 — Boundary parity:** keep HR2000 benchmarking at the operational-job level and preserve BLB's repo boundaries: Payroll Core in `belimbingapp/belimbing`, Malaysia statutory behavior in `BelimbingApp/blb-payroll-my`, and SBG customization in `kiatng/blb-sbg`. {amp/gpt-5.1-codex}
- [x] **Phase 1 — Core payroll parity:** ensure `02` Phase 1 covers HR2000's basic payroll-cycle expectations: pay periods, multiple pay inputs, historical run retention, run approval/locking, result lines, and basic payslips. {amp/gpt-5.1-codex}
- [x] **Phase 2 — Classification parity:** ensure `02` Phase 2 covers the HR2000 gap hidden behind “auto statutory calculation”: every pay item must have inspectable statutory treatment before final calculation. {amp/gpt-5.1-codex}
- [x] **Phase 3 — Profile parity:** ensure `02` Phase 3 captures employer and employee statutory setup needed before Malaysia statutory calculations can be credible. {amp/gpt-5.1-codex}
- [ ] **Phase 4 — Contribution parity:** ensure `02` Phase 4 covers EPF, SOCSO, EIS, and HRD levy before PCB, with explanation and validation output. Effective-dated statutory rule-table storage, registered Malaysia country-pack skeleton, core country-pack calculation orchestration, and the first EPF contribution calculator are in place; SOCSO/EIS/HRD calculators and broader statutory line explanations remain open.
- [ ] **Phase 5 — Output/control parity:** ensure `02` Phase 5 covers HR2000's baseline operational outputs: payslips, statutory contribution reports, employer cost reports, bank exports, practical report exports, payroll lock reports, and audit evidence. PDF rendering infrastructure is in place per `04_pdf-generation-strategy.md` (`App\Base\Pdf\Jobs\RenderPdfJob` as the queue-friendly entry point); HR2000-parity items that need PDF (payslip, statutory reports, employer cost report) consume that infrastructure rather than introducing a parallel engine.
- [ ] **Phase 6 — Claims parity:** use HR2000's e-Claim workflow as the first payroll-adjacent module target: claim entitlement, attachment, approval, payroll reimbursement, and claim reporting.
- [ ] **Phase 7 — Attendance/overtime parity:** use HR2000's e-TMS/e-Overtime as a staged target: approved overtime should feed payroll early; rotating shifts, conditional allowances, mobile/geofence, and device binding are follow-ups unless SBG confirms day-one need.
- [ ] **Phase 8 — PCB/zakat parity:** treat PCB, zakat, and any formal LHDN verification question as a later statutory phase after contribution calculations and explanations are proven.
- [ ] **Phase 9 — Self-Service document parity:** match HR2000's ESS document access with portal-first payslip and annual-document access; e-mail/PDF passwording remain conditional parity items. The technical capability for password-encrypted PDF is shipped (`App\Base\Pdf\Services\PdfPostProcessor::protectWithPassword`, AES-256 via qpdf) — the parity decision is whether to enable it, not whether to build it.
- [ ] **Phase 10 — SBG validation and later parity:** validate native mobile, geofencing, org/payroll charts, in-app training videos, broad legacy exports, and e-Appraisal with SBG before promoting them from later parity enhancers into implementation scope.

## Open Questions

- Which HR2000 bank formats are actually needed by SBG?
- Does SBG require native mobile apps, or is responsive/PWA web acceptable for initial field use?
- Does SBG need GPS/geofenced clock-in/out and device binding?
- Which statutory exports and annual forms are required in the first payroll year?
- Is LHDN endorsement/verification a product requirement before BLB can be used for SBG payroll, or can BLB start with internal validation against official examples and calculators?
- Are HR2000 e-Appraisal and HR development records parity targets for the first People suite, or are payroll-adjacent modules enough for initial delivery?
