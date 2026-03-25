# Product Requirements Document: Internal Corrective Action (legacy acronym: ICAR)

## Document Status

- Status: Draft
- Preferred product name: Internal Corrective Action
- Legacy acronym: `ICAR`
- Source: extracted from legacy ASP.NET subsystem at `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_ICAR/subsystem/` and validated against `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_ICAR/DB_STORED_PROCEDURES/`, `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_ICAR/DB_VIEWS/`, and `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_ICAR/DB_TABLES/`
- Purpose: preserve current business behavior and define the product scope for a modern replacement

## Legacy Reference

Primary legacy screens:

- `mgr_busi_cs_service_form.aspx`
- `mgr_qual_service_assignment.aspx`
- `mgr_prod_service_update.aspx`
- `mgr_qual_service_qac.aspx`
- `mgr_qual_service_qac_status.aspx`
- `mgr_qual_service_reporting.aspx`
- `mgr_prod_service_reporting.aspx`
- `mgr_qual_service_knowledgebase.aspx`
- `mgr_prod_service_knowledgebase.aspx`

Key stored procedures:

- `SP_FORM_SERVICE_ADD_R6`
- `SP_FORM_SERVICE_UPD_ASSIGN_R3`
- `SP_FORM_SERVICE_QAC_ADD_R1`
- `SP_FORM_SERVICE_UPD_R1`
- `SP_FORM_SERVICE_CONTAINMENTACTION_CORRECTION_ADD`
- `SP_FORM_SERVICE_ROOTCAUSE_ADD_R1`
- `SP_FORM_SERVICE_CORRECTIVEACTION_ADD_R1`
- `SP_FORM_SERVICE_UPD_QAC_R2`
- `SP_FORM_SERVICE_VERIFIED_QAC_ADD`
- `SP_FORM_SERVICE_SCAR_ADD_R1`
- `SP_FORM_SERVICE_SCAR_UPD_R1`
- `SP_FORM_SERVICE_REJECT_QAC_R1`

Key tables:

- `TBL_FORM_SERVICE_R3`
- `TBL_FORM_SERVICE_QAC_R1`
- `TBL_FORM_SERVICE_CONTAINMENTACTION_CORRECTION`
- `TBL_FORM_SERVICE_ROOTCAUSE_R1`
- `TBL_FORM_SERVICE_CORRECTIVEACTION_R1`
- `TBL_FORM_SERVICE_VERIFIED_QAC`
- `TBL_FORM_SERVICE_SCAR_R2`
- `TBL_FORM_SERVICE_FILES`
- `TBL_NEXT_NUMBER`

Key views:

- `VIEW_SERVICE_CASE_OPEN_R4`
- `VIEW_SERVICE_CASE_PENDING_R1`
- `VIEW_SERVICE_CASE_REVIEW_R1`
- `VIEW_SERVICE_CASE_CLOSED_R1`
- `VIEW_SERVICE_CASE_ALL_R1`
- `VIEW_SERVICE_CASE_QAC_R8`
- `VIEW_SERVICE_CASE_SCAR`
- `VIEW_SERVICE_KNOWLEDGEBASE_R1`

## 1. Product Summary

Internal Corrective Action is an internal corrective action management product used to log operational complaints, assign investigation ownership, capture corrective actions, coordinate supplier corrective action requests (SCAR), review responses, close cases, and reuse prior cases through reporting and knowledge-base search.

The current subsystem is organized around three primary work areas:

- Internal users submit new internal corrective action cases and track status.
- Quality managers triage, assign, review, reject, and close cases.
- Production managers investigate assigned cases and submit containment, root-cause, and corrective-action responses.

## 2. Problem Statement

The organization needs a single workflow to manage internal corrective action cases from complaint intake through closure, while preserving accountability, evidence, supplier escalation, and historical learning.

## 3. Goals

- Centralize internal corrective action case handling.
- Route each case to the correct department or supplier.
- Enforce a clear lifecycle from intake to closure.
- Capture investigation, containment, root cause, corrective action, and effectiveness verification.
- Support supplier-linked SCAR handling when external parties are involved.
- Provide searchable historical knowledge and management reporting.

## 4. Roles and Access

### 4.1 General Internal User

- Can create internal corrective action cases.
- Can view overall case status dashboards.
- Can delete own open cases before downstream processing.
- Legacy rule indicates access is granted to authenticated users outside `SALESCS`.

### 4.2 Quality Manager

- Can access assignment, review, closure, knowledge base, and reporting.
- Legacy authorization flag: `mgrqual = 'Y'`.

### 4.3 Production Manager

- Can access department response, production knowledge base, and reporting.
- Legacy authorization flag: `mgrprod = 'Y'`.

### 4.4 Procurement / Supplier Coordination

- No dedicated active UI is present in the legacy subsystem.
- Procurement participates through SCAR-related email notifications.
- A dormant procurement section exists in the menu and should be treated as future-scope rather than current-scope behavior.

## 5. In-Scope Capabilities

### 5.1 Main Navigation

The system shall provide a main menu with these work areas:

- Internal CAR
  - Service Form
  - Service Status
- Quality Manager
  - Service Assignment
  - Service Review (evaluation and case closure)
  - Knowledge Base
  - Reporting
- Production Manager
  - Service Update
  - Knowledge Base

### 5.2 Internal Corrective Action Case Intake

The system shall allow internal users to create a new case with:

- reference number
- category/title
- product name
- product code
- dimension
- affected quantity
- detected area
- UOM
- issued by
- issuing date
- problem description
- complaint attachment
- operation attention

Behavior:

- New cases shall default to status `OPEN`.
- The system shall store the submitter identity and submission timestamp.
- Users shall be able to clear the form before submission.
- Users shall be able to delete an open case from their case list.

### 5.3 Status Dashboard

The system shall provide a status view grouped into:

- Open
- Pending
- Review

Each list shall show, at minimum:

- reference number
- date/time
- product name
- product code
- quantity
- problem description
- UOM
- dimension
- issued by
- issuing date
- submitter

Pending and review states shall additionally show:

- SCAR number, when present
- investigation result
- due date to response

### 5.4 Quality Assignment

Quality managers shall be able to open an `OPEN` case and perform assignment.

The assignment step shall support:

- viewing the original complaint and attachment
- entering investigation result
- setting due date to response
- selecting responsible department
- selecting assigned supplier
- reloading an existing case by case number
- rejecting a case with rejection reason

Responsible department options observed in legacy behavior include production units plus planning, logistic, store, Schwaner, QAC/QC, R&D, procurement, IT, sales, and shipping.

Behavior:

- Submitting assignment shall transition the case to `PENDING`.
- Rejecting at this stage shall transition the case to `REJECTED`.
- Rejection shall require a reason.
- The system shall send assignment-related notifications.

### 5.5 Supplier / SCAR Handling

If a quality manager assigns a supplier, the system shall expose and maintain a linked SCAR record.

The SCAR record shall capture:

- SCAR number
- company name
- PO/DO/invoice number
- linked internal corrective action case number
- product name
- product code
- detected area
- dimension
- issued by
- issuing date
- complaint request type
- affected quantity
- UOM
- claim value
- problem description
- complaint attachment

Complaint request types observed in the legacy system:

- Corrective Action
- Corrective Action & Compensation

Behavior:

- SCAR creation shall be conditional on supplier assignment.
- Procurement and supplier contacts shall receive email notification.
- SCAR shall remain linked to the internal corrective action case through subsequent review and closure stages.

### 5.6 Production / Department Investigation Update

Production managers shall be able to work cases assigned to their department while the case is `PENDING`.

The response form shall support:

- viewing original complaint data and complaint attachment
- viewing assignment data and linked supplier/SCAR data
- entering investigation result
- entering containment action
- entering correction
- entering root cause for occurrence
- entering corrective action for occurrence
- entering effective date for occurrence action
- entering root cause for leakage
- entering corrective action for leakage
- entering effective date for leakage action
- uploading supporting attachments

Supporting attachments observed in legacy behavior:

- complaint attachment
- occurrence attachment/evidence
- leakage attachment/evidence
- general production response attachment

Behavior:

- Submitting the production response shall transition the case to `REVIEW`.
- The system shall notify quality for follow-up review.

### 5.7 Quality Review, Evaluation, and Closure

Quality managers shall be able to review cases in `REVIEW` status.

The review step shall support:

- viewing all complaint, assignment, production, and SCAR data
- updating or confirming root-cause and corrective-action content
- entering QAC comment
- selecting whether implementation is verified effective
- entering verified by
- entering verification date
- uploading review-stage attachment(s)
- re-emailing case details
- rejecting a case back for rework
- closing the case

Behavior:

- Closing shall transition the case to `CLOSED`.
- Rejecting from review shall transition the case back to `PENDING`.
- Review rejection is used when investigation must be redone.

### 5.8 Knowledge Base

The system shall provide searchable historical case lookup for managers.

Quality knowledge base shall support lookup by free text and present, at minimum:

- case number
- case date
- product
- quantity
- complaint
- root cause (occurred)
- root cause (leakage)
- corrective action (occurred)
- corrective action (leakage)
- SCAR number
- responsible department
- assigned supplier
- PIC user
- PIC department
- PIC date

Production knowledge base shall support lookup by free text and present, at minimum:

- case number
- case date
- product
- quantity
- complaint
- root cause
- corrective action
- PIC user
- PIC department
- PIC date

Behavior:

- Historical results should primarily reflect resolved or reusable cases rather than rejected records.
- Managers shall be able to open a case detail view from knowledge-base results.

### 5.9 Reporting

#### Quality Reporting

The system shall provide date-range reporting for non-rejected cases with a detailed table containing:

- case number
- title
- case date
- product
- quantity
- complaint
- root cause
- corrective action
- PIC user
- PIC department
- PIC date

#### Production Reporting

The system shall provide date-range reporting with both summary and detail views.

Summary cuts observed in legacy behavior:

- cases by status
- cases by region
- cases by region and type
- cases by type and region
- cases by PIC department
- cases by case type
- cases by case type and department
- cases by department and case type

Detailed production reporting includes historical customer-facing fields inherited from an older complaint model:

- customer
- contact
- tel
- fax
- email
- customer reference number
- logged by
- logged date/time
- status
- region
- type
- salesman
- PIC department
- PIC name
- PIC date/time
- product
- quantity
- UOM
- complaint
- root cause
- corrective action
- QAC comment

## 6. Workflow Lifecycle

### 6.1 Primary Statuses

- `OPEN` - submitted and awaiting quality triage
- `PENDING` - assigned and awaiting department or supplier response
- `REVIEW` - department response submitted and awaiting quality review
- `CLOSED` - quality has accepted and closed the case
- `REJECTED` - quality rejected the case during assignment stage

### 6.2 Workflow Rules

- New submission creates an `OPEN` case.
- Quality assignment moves `OPEN -> PENDING`.
- Production update moves `PENDING -> REVIEW`.
- Quality closure moves `REVIEW -> CLOSED`.
- Quality rework request moves `REVIEW -> PENDING`.
- Quality rejection during assignment moves `OPEN -> REJECTED`.

### 6.3 Verification Layer

The system shall track effectiveness verification separately from top-level case status using:

- verified effective implementation
- verified by
- verification date

## 7. Notifications

The system shall send email notifications for at least these events:

- new internal corrective action submission
- assignment to department
- assignment to supplier / SCAR creation
- production response submitted
- quality review update
- case rejection
- case closure
- manual re-email of case details

Email content should include the case reference number, problem context, and action expectations relevant to the recipient.

## 8. Core User Journeys

### 8.1 Standard Internal Case

1. Internal user logs a complaint.
2. System creates case as `OPEN`.
3. Quality manager reviews and assigns ownership.
4. Assigned department investigates and submits response.
5. Quality manager evaluates response.
6. Quality either closes the case or sends it back for rework.

### 8.2 Supplier Escalation

1. Quality manager decides supplier involvement is required.
2. System creates or exposes linked SCAR details.
3. Supplier and procurement receive notification.
4. Department and quality continue the corrective-action workflow.
5. Case closes once response is accepted.

### 8.3 Knowledge Reuse

1. Manager searches prior cases.
2. Manager reviews previous root causes and corrective actions.
3. Historical learning informs new case handling.

### 8.4 Reporting and Oversight

1. Manager selects a reporting date range.
2. Manager reviews trend summaries and detailed case output.
3. Management uses the output to monitor workload, hotspots, and closure quality.

## 9. Data Requirements and Recommended QAC Model Boundaries

This PRD should drive a QAC implementation with three main models:

- `InternalCorrectiveActionCase` - the primary case header and intake record
- `CorrectiveActionResolution` - the assignment, investigation, containment, root-cause, corrective-action, review, verification, and evidence package linked to the case
- `SupplierCorrectiveActionRequest` - the optional supplier escalation record linked to the case

Attachments, history rows, and notifications should be modeled as child records under these three main models rather than as separate top-level business models.

### 9.1 InternalCorrectiveActionCase

The case model shall preserve the full intake header and routing metadata observed in the legacy forms and tables.

User-facing intake fields:

- case number / reference number
- case date and time
- status
- title / subject
- operation attention / attention scope
- product name
- product code
- affected quantity
- detected area
- problem description
- UOM
- issued by
- issuing date
- dimension
- original complaint attachment

Legacy-backed header fields that should remain available in the data model even if hidden in the first release UI:

- attention / operation marker from `DocAttention`
- clause / reference from `DocClause`
- responsible department from `DocDepartment`
- case type from `DocCaseType`
- case category from `DocCategory`

Audit and routing fields that the modern product shall track explicitly instead of overloading the header:

- submitted by name
- submitted by email
- created by system user name
- created by system user email
- created at timestamp
- system counter / revision counter
- current PIC name
- current PIC email
- current PIC department
- current PIC assigned timestamp
- reject reason
- reject timestamp

Behavior:

- Case number shall be system-generated from a numbering service. Legacy behavior uses `TBL_NEXT_NUMBER.NextNumber2`.
- New cases shall default to `OPEN`.
- Only open cases owned by the submitter should be deletable from the intake page.
- Legacy hidden fields that currently default to `-` or inconsistent values must be normalized in the modern model instead of remaining overloaded string placeholders.

### 9.2 CorrectiveActionResolution

The resolution model shall own the full internal workflow after intake.

Assignment and preliminary investigation fields:

- investigation result
- due date to response
- responsible department
- assigned supplier
- assignment actor
- assignment timestamp

Containment and correction fields:

- containment action
- correction

Root-cause fields:

- root cause - why occurred
- root cause - why leakage
- root-cause author
- root-cause recorded timestamp
- root-cause revision line / history index

Corrective-action fields:

- corrective action - occurrence branch
- effective date - occurrence branch
- corrective action - leakage branch
- effective date - leakage branch
- action owner
- action date string fields preserved only for migration if required
- corrective-action recorded timestamp
- corrective-action revision line / history index

Quality review and verification fields:

- quality review comment
- verified effective implementation
- verified by
- verification date
- debit note received flag or note
- review actor
- review timestamp

Implementation note:

- The legacy ICAR schema clearly stores verification in `TBL_FORM_SERVICE_VERIFIED_QAC`, but quality review comment persistence is inconsistent across screens and views. The modern product should treat quality review comment as a mandatory first-class field on the resolution model rather than relying on ambiguous legacy storage.

### 9.3 SupplierCorrectiveActionRequest

The SCAR model shall preserve the supplier escalation fields already used by ICAR.

Required SCAR fields for parity:

- SCAR number
- company name
- PO / DO / invoice number
- linked internal corrective action case number
- product name
- product code
- detected area
- dimension
- issued by
- issuing date
- complaint request type
- affected quantity / claim quantity
- UOM
- claim value
- problem description
- created by system user
- created at timestamp

Behavior:

- SCAR number shall be system-generated from a numbering service. Legacy behavior uses `TBL_NEXT_NUMBER.NextNumber1`.
- SCAR shall only be created when supplier assignment is present.
- SCAR shall remain visible through case review and closure.

### 9.4 Attachment Model Under the Three Main Models

Attachments are not a fourth business model; they are typed evidence records linked to a case or resolution package.

The modern product shall preserve at least these attachment types:

- `USER` - original complaint attachment
- `PRD` - department / production supporting attachment
- `OCCURED` - occurrence-branch corrective-action evidence
- `LEAKAGE` - leakage-branch corrective-action evidence

Attachment metadata shall include:

- attachment id
- parent case number
- attachment type
- stored file path / blob key
- original filename
- uploader
- uploaded at

Behavior:

- Legacy behavior uses `TBL_FORM_SERVICE_FILES` and `TBL_FORM_SERVICE_FILES_D` with `DocType` and file paths.
- The modern product should allow multiple attachments per type, even though some legacy screens effectively display only one active file per type.
- Replaced or deleted attachments must be archived in history.

### 9.5 Lifecycle Rules and Status Transitions

The internal corrective action case shall support this canonical state machine:

- `OPEN` - created and awaiting quality triage
- `PENDING` - assigned to internal department or supplier path and awaiting response
- `REVIEW` - internal response submitted and awaiting quality review
- `CLOSED` - quality accepted the response and completed closure
- `REJECTED` - rejected during assignment or review routing

Allowed transitions backed by the legacy workflow:

- `OPEN -> PENDING` on assignment
- `OPEN -> REJECTED` on quality rejection during triage
- `PENDING -> REVIEW` on department response submission
- `REVIEW -> PENDING` on quality send-back / rework request
- `REVIEW -> CLOSED` on quality closure

The system shall also record transition metadata:

- actor
- actor role
- previous status
- next status
- transition reason
- transition timestamp

### 9.6 Validations, Defaults, and Legacy Logic

The modern product shall preserve or formalize these observed rules:

- intake requires title, product, product code, dimension, quantity, detected area, problem description, issued by, and issuing date
- operation attention must be selected at intake
- assignment requires an existing case and investigation result
- legacy code enforces that at least one of responsible department or supplier must be selected; the modern product should allow both, but require at least one accountable owner
- quantity, claim value, and numeric-like fields must be validated as numbers
- date fields must be validated as proper dates
- verification effective should be a controlled value, not free text
- reject reason shall be required whenever a rejection action is used

Legacy normalization notes:

- `DocAttention` is currently used inconsistently against the UI label “internal operation”; replace this with a clear controlled field in the modern product
- `DocClause`, `DocCaseType`, and `DocCategory` should remain optional but explicitly modeled for future taxonomy use
- history tables for root cause and corrective action show that revisions are a real business requirement; do not flatten these into overwrite-only fields

### 9.7 Reporting and Knowledge-Base Data Sets

The reporting and knowledge-base features shall draw from the three main models and preserve these joined outputs:

- case number
- case date
- status
- title
- product
- product code
- quantity
- UOM
- detected area
- issue / complaint text
- responsible department
- assigned supplier
- investigation result
- root cause occurred
- root cause leakage
- corrective action occurred
- corrective action leakage
- verification result
- quality review comment
- SCAR number
- PIC user and department
- key timestamps

### 9.8 Notification and Routing Requirements

The modern product shall replace hard-coded email routing with configurable routing rules, while preserving the same business triggers.

Required notification triggers:

- new case submitted
- case assigned to department
- case assigned to supplier / SCAR created
- department response submitted
- review send-back / rejection
- case closed
- manual resend of case details

Recipients may include:

- submitter
- assigned department PIC
- quality manager
- procurement
- supplier contacts

### 9.9 Legacy-to-Modern Decisions

The second-pass audit supports these implementation decisions:

- keep CPAR-related behavior adjacent to the module, not inside the core three-model design
- keep procurement notification support, but do not make procurement a required top-level model for release 1
- preserve case type and category in the schema even if the first release UI does not fully expose them
- convert legacy file-path attachment storage into managed document storage with typed evidence records
- convert legacy PIC header overwrites into explicit workflow events and assignee history

## 10. Non-Functional Expectations

- Role-based access must align with operational responsibilities.
- Attachments must remain downloadable throughout the case lifecycle.
- Search and reporting must support historical retrieval for closed-case learning.
- Case history should be auditable across submission, assignment, response, review, rejection, and closure.

## 11. Legacy Constraints and Migration Notes

- The legacy subsystem contains older customer-complaint reporting fields, suggesting the data model evolved from a broader complaint system.
- A legacy CPAR email pathway exists in older knowledge-base detail code and should be treated as adjacent legacy behavior, not confirmed core scope.
- Procurement UI is commented out in the legacy menu, so procurement workflow should be considered optional or future scope unless the business confirms otherwise.
- The current system appears heavily email-driven for supplier collaboration; no supplier self-service portal is present.
- Some legacy statuses such as `ON HOLD` and `USE AS IT IS` appear only in commented code and are not part of the active workflow defined here.

## 12. Open Questions for Product Validation

- Should procurement receive a dedicated work queue in the replacement product?
- Should supplier collaboration remain email-based or move to portal-based interaction?
- Should customer-facing complaint attributes remain in the data model, or be removed from the internal corrective action scope?
- Should case categorization and case type be formalized, since the legacy implementation appears inconsistent in parts of the flow?
- Are compensation and claim-value fields mandatory whenever a supplier is assigned, or only for selected request types?

## 13. Recommended First Release Scope

For parity-focused modernization, the first release should include:

- case intake
- status dashboard
- quality assignment
- production response
- quality review and closure
- SCAR linkage
- knowledge base
- date-range reporting
- email notifications
- attachment handling

This scope captures the active business behavior present in the legacy subsystem while leaving dormant procurement and CPAR extensions for later validation.
