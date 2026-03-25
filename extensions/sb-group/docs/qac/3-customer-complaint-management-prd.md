# Product Requirements Document: Customer Complaint Management (legacy acronym: IOSS)

## Document Status

- Status: Draft
- Preferred product name: Customer Complaint Management
- Legacy acronym: `IOSS`
- Source: extracted from legacy ASP.NET subsystem at `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_IOSS/subsystem/` and validated against `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_IOSS/DB_STORED_PROCEDURES/`, `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_IOSS/DB_VIEWS/`, and `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_IOSS/DB_TABLES/`
- Purpose: preserve implemented behavior and define modernization scope for the replacement product

## Legacy Reference

Primary complaint workflow screens:

- `mgr_busi_cs_service_form_cs.aspx`
- `mgr_qual_service_assignment.aspx`
- `mgr_qual_service_assignment_hod.aspx`
- `mgr_prod_service_update.aspx`
- `mgr_qual_service_qac.aspx`
- `mgr_busi_cs_service_form_follow_up.aspx`
- `mgr_qual_service_qac_status.aspx`
- `mgr_qual_service_reporting.aspx`
- `mgr_busi_cs_service_form_knowledgebase.aspx`

Key stored procedures:

- `SP_FORM_SERVICE_ADD`
- `SP_FORM_SERVICE_COMPLAINT_ADD_R1`
- `SP_FORM_SERVICE_QAC_CHECK_ADD_R1`
- `SP_FORM_SERVICE_UPD_QACHOD`
- `SP_FORM_SERVICE_UPD_PROD`
- `SP_FORM_SERVICE_CONTAINMENTACTION_CORRECTION_ADD`
- `SP_FORM_SERVICE_ROOTCAUSE_ADD_R1`
- `SP_FORM_SERVICE_CORRECTIVEACTION_ADD_R1`
- `SP_FORM_SERVICE_UPD_QAC`
- `SP_FORM_SERVICE_UPD_QAC2`
- `SP_FORM_SERVICE_QAC_ADD`
- `SP_FORM_SERVICE_VERIFIED_QAC_ADD`
- `SP_FORM_SERVICE_UPD_CS`
- `SP_FORM_SERVICE_SCAR_ADD`
- `SP_FORM_SERVICE_RETURN_QAC`
- `SP_FORM_SERVICE_REJECT_QAC`

Key tables:

- `TBL_FORM_SERVICE`
- `TBL_FORM_SERVICE_COMPLAINT_R1`
- `TBL_FORM_SERVICE_QAC_CHECK_R1`
- `TBL_FORM_SERVICE_CONTAINMENTACTION_CORRECTION`
- `TBL_FORM_SERVICE_ROOTCAUSE_R1`
- `TBL_FORM_SERVICE_CORRECTIVEACTION_R1`
- `TBL_FORM_SERVICE_QAC`
- `TBL_FORM_SERVICE_VERIFIED_QAC`
- `TBL_FORM_SERVICE_SCAR_R1`
- `TBL_FORM_SERVICE_FILES`
- `TBL_NEXT_NUMBER`

Key views:

- `VIEW_SERVICE_CASE_ALL_R2`
- `VIEW_SERVICE_CASE_QC_R3`
- `VIEW_SERVICE_CASE_PENDING_R4`
- `VIEW_SERVICE_CASE_REVIEW_R4`
- `VIEW_SERVICE_CASE_CLOSED_R3`
- `VIEW_SERVICE_CASE_CLOSED2`
- `VIEW_SERVICE_KNOWLEDGEBASE_R2`
- `VIEW_FORM_SERVICE_R2`

## 1. Product Summary

Customer Complaint Management is a multi-module internal business system centered on customer complaint and service-case management, with supporting business, quality, production, reporting, CRM, administration, and vendor-managed-inventory capabilities.

The core workflow manages a customer complaint from business intake through QA preliminary investigation, QAC HOD approval, department response, QAC review, and customer-facing follow-up. Around that workflow, the subsystem also provides customer master data tools, business reporting, sales and order inquiry, user administration, and VMI dashboards.

## 2. Problem Statement

The organization needs a single system to intake customer complaints, coordinate cross-functional corrective action, track ownership and status, capture investigation evidence, preserve institutional knowledge, and support related commercial visibility such as customer profiles, sales, orders, and stock views.

## 3. Goals

- Centralize complaint and service-case handling.
- Enforce a structured lifecycle across business, quality, QAC HOD, production, and customer follow-up.
- Capture corrective-action evidence and closure decisions.
- Support supplier-linked SCAR escalation when needed.
- Preserve historical knowledge through search and reporting.
- Provide business users with adjacent CRM, sales, order, and stock visibility.

## 4. Product Modules

### 4.1 Administration

- User account maintenance
- Salesman account maintenance
- Department and module access control

### 4.2 Business Manager / CRM

- Service form intake
- Service follow-up
- Service inquiry
- Customer profile
- Customer sales info
- Business knowledge base
- Business reporting

### 4.3 Quality Manager

- Preliminary investigation and assignment
- QAC HOD approval queue
- Re-assignment
- Review, evaluation, and closure
- Status dashboard
- Knowledge base
- Reporting

### 4.4 Production Manager

- Service update
- Knowledge base
- Reporting

### 4.5 Vendor Managed Inventory

- Customer stock trend
- Customer stock level
- SB stock offering

### 4.6 Sales and Order Information

- Sales overview, delivered, yearly, payment, and salesperson reports
- Local order current, outstanding, open listing, detail inquiry, advance, and reporting
- Sales knowledge-base and inquiry-style pages

## 5. Roles and Access

### 5.1 Administration Manager

- Maintains user accounts and access flags.

### 5.2 Business / CS User

- Logs complaints.
- Performs customer follow-up and inquiry.
- Maintains customer profile and customer sales info.
- Views business reporting and business knowledge base.

### 5.3 Quality Manager

- Performs preliminary investigation.
- Assigns responsible department and supplier.
- Creates SCAR-linked escalation.
- Reviews case outcomes and closes cases.

### 5.4 QAC HOD

- Approves or rejects cases after QA preliminary assignment.
- Returns cases to QAC when rework is needed before department action.

### 5.5 Production / Responsible Department User

- Responds to assigned cases.
- Records containment, correction, root causes, corrective actions, and supporting evidence.

### 5.6 Supplier / Procurement Participants

- Participate primarily through SCAR-related notification and email exchange.
- No supplier self-service portal is present in the subsystem.

### 5.7 VMI / Business Viewer

- Views stock trend and stock-level dashboards.

## 6. Core Complaint Workflow Scope

### 6.1 Complaint Intake

The system shall allow business or CS users to create a new service case from customer transaction data.

Data captured on intake:

- reference number
- invoice number
- sales order number
- JR lot number
- customer name
- contact
- tel
- email
- fax
- invoice date
- salesman
- country / region
- product name
- product code
- affected / claim quantity
- UOM
- claim value
- category
- complaint title / subject
- dimension
- issued by
- issuing date
- problem description
- complaint attachment

Behavior:

- Users can search by invoice number.
- Users can search by JR lot number.
- The system loads invoice item lines for selection.
- New cases are created with status `OPEN`.
- The system stores the reporting user and reported timestamp.
- The system sends internal notification to quality.
- The system can send customer acknowledgement email.
- Users can delete previously logged open records from their case list.

### 6.2 QA Preliminary Investigation and Assignment

Quality managers shall be able to open an `OPEN` case and perform initial evaluation.

Assignment-stage inputs:

- complaint and transaction context
- preliminary investigation by QA department
- due date to response
- responsible department
- assigned supplier
- claim value adjustments
- title and complaint refinements
- dimension
- issued by / issuing date

Behavior:

- Submitting this stage forwards the case to QAC HOD.
- The legacy implementation sets case status to `OPENQC`.
- The system sends notification that QAC HOD action is required.

### 6.3 QAC HOD Approval

QAC HOD shall review the QA-assigned case before department action.

Available actions:

- Accepted For Future Action
- Not Justified
- Return QAC

Behavior:

- Accepted cases move to `PENDING`.
- Not-justified cases trigger rejection handling.
- Return-to-QAC sends the case back for quality-side rework.
- The system sends notifications to department, supplier, or procurement as relevant.

### 6.4 Department / Production Response

Production or responsible departments shall respond to `PENDING` cases.

Response fields:

- containment action
- correction
- root cause for occurrence
- corrective action for occurrence
- effective date for occurrence action
- root cause for leakage
- corrective action for leakage
- effective date for leakage action
- supporting attachments

Observed attachment categories in legacy behavior:

- complaint attachment
- PRD attachment
- occurrence attachment / evidence
- leakage attachment / evidence

Behavior:

- Submitting the department response moves the case to `REVIEW`.
- Notifications are sent for QAC review.

### 6.5 QAC Review and Closure

Quality managers shall review department responses and decide final disposition.

Review-stage inputs:

- all complaint and assignment information
- department response
- root cause and corrective action content
- supplier / SCAR details when present
- QAC comments

Behavior:

- QAC can close the case, moving it to `CLOSED`.
- QAC can reject or send back the case, returning it to `PENDING`.
- Closed cases become available to follow-up, reporting, and knowledge-base features.

### 6.6 Customer Follow-Up and Final Filing

Business users shall perform the outward-facing follow-up after quality closure.

Follow-up-stage fields and actions:

- customer contact details review
- root cause review
- corrective action review
- QAC / QC comments review
- supporting attachment review
- cost value entry
- `File & Closed` action

Behavior:

- The system distinguishes cases pending customer confirmation from already checked and closed cases.
- Follow-up acts as the final customer-facing resolution stage after internal closure.

## 7. Supplier / SCAR Handling

If a supplier is assigned during quality assignment, the system shall expose a linked SCAR section.

SCAR data observed in the subsystem:

- SCAR number
- company name
- PO / DO number
- linked customer complaint case number
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

Complaint request types observed:

- Corrective Action
- Corrective Action & Compensation

Behavior:

- Supplier assignment conditionally shows or links SCAR details.
- Supplier and procurement contacts are notified through email-driven flows.
- SCAR remains visible through approval, production update, and review.

## 8. Status Model

### 8.1 Confirmed Core Statuses

- `OPEN` - newly logged complaint
- `OPENQC` - quality-assigned and awaiting QAC HOD action
- `PENDING` - approved and waiting for department response
- `REVIEW` - department response submitted and awaiting QAC review
- `CLOSED` - closed by quality

### 8.2 UI Status Buckets Observed

- Service - Open Status
- Service - QAC HOD Status
- Service - Pending Status
- Service - Review Status
- Service - Closed Status & Awaiting CS Forward To Customer
- Pending Review & Confirmation To Customer
- Checked & Closed

### 8.3 Additional Actions / Outcomes Observed

- Not Justified
- Reject / Rejected
- Return QAC
- Re-assignment

Some older options such as `ON HOLD` and `USE AS IT IS` appear only in commented code and should be treated as inactive legacy remnants.

## 9. Main Screens and Feature Requirements

### 9.1 Main Menu

The product shall provide menu entry points for:

- Administration Manager
- Business Manager
- Quality Manager
- Production Manager
- Vendor Managed Inventories

### 9.2 Service Status Dashboard

The quality dashboard shall show separate grids for:

- open cases
- QAC HOD cases
- pending cases
- review cases
- closed cases awaiting CS forwarding

Dashboard fields include combinations of:

- case number
- customer
- date/time
- product
- quantity
- UOM
- complaint
- reported by
- status
- SCAR number
- QAC approval identity and date
- assigned department and assignment date
- department action identity and date
- QAC action date

### 9.3 Knowledge Bases

The system shall provide at least three knowledge-base contexts:

- Business knowledge base
- Quality knowledge base
- Production knowledge base

Business knowledge base fields observed:

- case number
- customer details
- contact details
- customer reference / SO
- reporting user and timestamp
- status
- region
- type
- salesman
- value
- department ownership
- product
- quantity
- UOM
- complaint
- root cause
- corrective action
- QAC comment

Behavior:

- Users can search by case text.
- Closed and historically useful cases are surfaced for reuse.
- Quality detail flow includes CPAR-related legacy action via email.

### 9.4 Reporting

The system shall provide date-range reporting with summary and detail views.

Summary breakdowns observed:

- cases and cost by status
- cases and cost by region
- cases and cost by region and type
- cases and cost by type and region
- cases and cost by PIC department
- cases and cost by case type
- cases and cost by case type and department
- cases and cost by department and case type

Detailed reporting includes:

- customer and contact details
- customer reference numbers
- logged by and logged date/time
- status
- region
- type
- salesman
- value / cost
- assigned department and action timestamps
- product
- quantity and UOM
- category / complaint / preliminary investigation
- root cause
- corrective action
- QAC comment

### 9.5 Customer Profile and Customer Sales Info

The system shall support customer relationship context with:

- customer profile maintenance
- VIP name and DOB
- contact information
- remarks
- region and salesperson mapping
- calendar and listing views for customer-profile follow-up
- customer sales info and historical sales context

### 9.6 User Administration

The administration module shall support:

- create user
- update user
- delete user
- assign department
- assign internal and external email
- set password
- enable module access flags for administration, business, quality, and production

### 9.7 Sales and Order Information

The subsystem includes broader business visibility pages that should be preserved as in-scope modules unless explicitly de-scoped:

- order current
- order outstanding
- order open listing
- order detail inquiry
- order advance
- order reporting
- sales overall
- sales delivered
- sales yearly
- sales reporting
- payment reporting
- salesperson reporting and performance
- local and IBU sales reports
- sales detail inquiry
- sales customer aging
- sales knowledge base

### 9.8 Vendor Managed Inventory

The subsystem includes stock-visibility pages for:

- customer stock trend
- customer stock level
- SB stock offering

## 10. Notifications

The product shall support email notifications for at least these events:

- new complaint submission
- QAC assignment forwarded to QAC HOD
- QAC HOD approval to department / supplier
- supplier-linked SCAR escalation
- department response submitted
- QAC rejection or send-back
- case closure
- customer acknowledgement and follow-up communication

The legacy implementation contains extensive hard-coded supplier email routing, indicating notifications are a critical operational dependency.

## 11. Core User Journeys

### 11.1 Complaint Intake Journey

1. Business user opens Service Form.
2. User searches invoice or JR lot.
3. User selects complaint item from transaction detail.
4. User enters complaint details, value, and attachment.
5. System creates a case in `OPEN`.
6. Quality is notified.

### 11.2 Quality Assignment Journey

1. Quality reviews the complaint.
2. Quality records preliminary investigation.
3. Quality sets due date and responsible department.
4. Optional supplier assignment creates or exposes SCAR details.
5. Case moves to `OPENQC` for QAC HOD approval.

### 11.3 QAC HOD Approval Journey

1. QAC HOD reviews case and assignment.
2. HOD accepts for future action, rejects as not justified, or returns to QAC.
3. Accepted cases move to `PENDING` and notify the next responsible party.

### 11.4 Department Response Journey

1. Responsible department opens assigned case.
2. Department records containment, correction, root causes, and corrective actions.
3. Department uploads evidence.
4. Case moves to `REVIEW`.

### 11.5 QAC Closure Journey

1. QAC reviews the department response.
2. QAC records review comments.
3. QAC either closes the case or sends it back.
4. Closed cases appear in historical views.

### 11.6 Customer Follow-Up Journey

1. Business user reviews closed case details.
2. Business user prepares outward response and records cost value.
3. Business user files and closes the customer-facing record.

## 12. Data Requirements and Recommended QAC Model Boundaries

For the QAC module, the complaint workflow should be implemented with three main models:

- `CustomerComplaintCase` - the complaint header, customer context, and complaint detail record
- `ComplaintResolutionWorkflow` - the assignment, approval, investigation, containment, corrective-action, review, verification, and follow-up package
- `SupplierCorrectiveActionRequest` - the optional supplier escalation record linked to the complaint case

Attachments, notifications, surveys, knowledge-base snapshots, and reporting projections should be child artifacts around these three models rather than separate primary business models.

### 12.1 CustomerComplaintCase

The complaint case model shall preserve both the header row and the complaint detail row used by the legacy system.

Header and customer-context fields:

- case number
- customer name
- customer contact
- customer document / invoice number
- customer sales order number
- customer date / invoice date
- reported by
- reported at
- current status
- current quality owner name
- current due date
- current owner timestamp
- customer code
- customer display name
- customer address / metadata fields currently stored in legacy `madd1`, `madd2`, `madd3a`
- reject / return reason currently stored in legacy `madd3b`
- complaint title / subject currently stored in legacy `madd4`
- tel
- fax
- attention / contact name
- email
- region / country bucket
- complaint group / type bucket
- salesperson
- salesperson email
- area
- market
- claim or cost value
- current department
- current department timestamp
- current department actor name
- customer SO
- case type
- line count
- OPS / internal-external marker

Complaint detail fields:

- complaint detail line number
- product name
- affected quantity
- UOM
- complaint narrative
- complaint category
- claim quantity value / claim amount
- product code
- dimension
- issued by
- issuing date

Behavior:

- Case number shall be system-generated. Legacy behavior uses `TBL_NEXT_NUMBER.NextNumber2`.
- Complaint detail line number shall be system-generated. Legacy behavior uses `TBL_NEXT_NUMBER.NextNumber3`.
- New cases shall default to `OPEN`.
- `CountDoc` defaults to `1` in the legacy schema and indicates the complaint line count.
- The modern product should normalize overloaded legacy `madd*` fields into named domain fields instead of preserving them as generic storage.

### 12.2 ComplaintResolutionWorkflow

The workflow model shall own the full corrective-action lifecycle after case creation.

Preliminary investigation and assignment fields:

- quality preliminary investigation comment
- due date to response
- responsible department
- assigned supplier
- assignment actor
- assignment timestamp
- QAC HOD approval timestamp

Department investigation fields:

- containment action
- correction
- root cause - why occurred
- root cause - why leakage
- corrective action - occurrence branch
- effective date - occurrence branch
- corrective action - leakage branch
- effective date - leakage branch
- department actor name
- department action timestamp

Quality review and verification fields:

- quality review comment
- QAC feedback
- verified effective
- verified by
- verification date
- CN issued
- DN received
- case type at closure
- quality reviewer name
- review due date

Customer follow-up fields:

- customer-facing follow-up notes
- reviewed root cause summary
- reviewed corrective action summary
- reviewed QAC comment
- finalized cost value
- follow-up actor
- follow-up timestamp

Behavior:

- The workflow model must preserve revision history for QAC prelim, root cause, and corrective action because the legacy system archives old rows before replacing them.
- Quality review comment and verification must not be merged into a single free-text field.
- Customer follow-up is part of the workflow lifecycle even though it occurs after internal quality closure.

### 12.3 SupplierCorrectiveActionRequest

The complaint workflow shall link to the shared SCAR model when supplier ownership is involved.

Required SCAR linkage fields for complaint parity:

- SCAR number
- supplier company name
- PO / DO number
- linked customer complaint case number
- product name
- product code
- detected area
- dimension
- issued by
- issuing date
- complaint request type
- claim quantity
- UOM
- claim value
- problem description
- created by system user
- created at timestamp

Behavior:

- SCAR number shall be system-generated. Legacy complaint behavior uses `TBL_NEXT_NUMBER.NextNumber7`.
- SCAR creation is conditional on supplier assignment.
- SCAR shall remain visible across `OPENQC`, `PENDING`, `REVIEW`, and `CLOSED` stages.

### 12.4 Status Model Required for the QAC Module

The complaint workflow shall preserve these legacy lifecycle states:

- `OPEN` - case logged by business / CS
- `OPENQC` - quality assignment complete and awaiting QAC HOD approval
- `PENDING` - approved and awaiting department investigation
- `REVIEW` - department response submitted and awaiting quality review
- `CLOSED` - quality accepted the internal resolution
- `CLOSED2` - business follow-up completed and customer-facing filing finished

Recommended explicit event outcomes for the modern product:

- `RETURNED_TO_QAC`
- `SENT_BACK_FOR_REWORK`
- `NOT_JUSTIFIED`
- `REJECTED`

These event outcomes are needed because the legacy system stores some reject and return behavior only as overwritten status fields or as free-text reason values.

### 12.5 Transition Logic to Preserve

The modern product shall preserve these legacy transitions:

- `OPEN -> OPENQC` on quality assignment submission
- `OPENQC -> PENDING` on QAC HOD approval
- `OPENQC -> OPEN` on return to QAC
- `PENDING -> REVIEW` on department investigation submission
- `REVIEW -> PENDING` on quality send-back / rework request
- `REVIEW -> CLOSED` on quality closure
- `CLOSED -> CLOSED2` on business follow-up completion

Transition metadata shall always include:

- actor
- actor role
- previous status
- next status
- action reason
- action timestamp

### 12.6 Attachment and Evidence Requirements

The complaint workflow shall preserve typed evidence records under the case or workflow.

Legacy attachment types to preserve:

- `SLS` - original complaint attachment
- `PRD` - department / production support attachment
- `OCCURED` - occurrence-side evidence
- `LEAKAGE` - leakage-side evidence

Attachment metadata shall include:

- attachment id
- parent case number
- attachment type
- stored file path / blob key
- original filename
- uploader
- uploaded at

Behavior:

- Legacy screens often show only one active attachment per type even though the schema allows multiple rows.
- The modern product should allow multiple attachments per type with a clear primary attachment indicator.
- Replaced and deleted files should remain in history.

### 12.7 Validations, Defaults, and Legacy Rules

The modern product shall formalize these observed behaviors:

- intake requires customer and complaint fields sufficient to identify the transaction and issue
- invoice number and JR lot lookup must remain supported where source data exists
- claim value, quantity, and cost fields must be validated as numeric
- issuing date and due date must be validated as dates
- missing contact fields currently default to `-`; the modern product should use nullable fields and explicit display fallbacks instead
- `OPS` defaults to an internal marker in the CS flow and should become an explicit controlled field
- case type and complaint category exist in the schema but are weakly enforced in the UI; the modern product should support controlled taxonomy without forcing placeholder values like `-`
- reject reason must be captured as a named field, not hidden inside overloaded `madd3b`

### 12.8 Notifications and Routing Requirements

The complaint workflow shall preserve these business notification triggers:

- new complaint submitted
- assignment forwarded to QAC HOD
- QAC HOD approved to department
- supplier-linked SCAR issued
- department response submitted
- return to QAC
- rejection / not justified action
- quality closure
- business follow-up / customer communication
- customer survey invitation or survey completion when that adjacent module is retained

The modern product should externalize recipients and templates into configuration instead of hard-coding addresses in page logic.

### 12.9 Scope Boundaries for the Three-Model QAC Module

Inside the core three-model QAC scope:

- complaint case intake and header
- complaint detail
- assignment and approval flow
- department investigation
- containment, root cause, and corrective action
- quality review and verification
- follow-up closure state handling
- evidence attachments
- optional SCAR linkage

Outside the core three-model QAC scope, but adjacent and integratable:

- customer survey
- customer profile and calendar
- authentication and user administration
- sales and order inquiry/reporting
- VMI dashboards
- CRM and customer sales context outside the complaint record itself

## 13. Non-Functional Expectations

- Role-based access must remain aligned to operational teams.
- Attachments must remain downloadable across the full lifecycle.
- Search and reporting must support historical learning and management review.
- Notification workflows must be preserved during modernization.
- Case history must remain auditable across all workflow transitions.

## 14. Legacy Constraints and Migration Notes

- Customer Complaint Management is broader than a single complaint module; the legacy subsystem combines complaint workflow, CRM, sales/order inquiry, administration, and VMI.
- The complaint workflow is the deepest and most operationally coupled area, so it should be prioritized for modernization.
- Supplier interaction is email-driven; no supplier portal is implemented.
- QAC HOD is a distinct approval step and should not be collapsed without explicit business confirmation.
- Business follow-up introduces a post-closure customer-confirmation phase that sits outside pure internal QA closure.
- Case type and category fields appear in reporting but are inconsistently surfaced in forms, so taxonomy needs validation during redesign.
- Quality knowledge flow contains CPAR-adjacent behavior that may be legacy or optional scope.

## 15. Recommended Modernization Release Scope

### Release 1

- complaint intake
- QA preliminary investigation and assignment
- QAC HOD approval step
- department response workflow
- QAC review and closure
- business follow-up and final filing
- status dashboards
- SCAR linkage
- knowledge bases
- complaint reporting
- notifications
- attachment handling

### Release 2

- customer profile tools
- customer sales info
- user administration
- sales and order inquiry/reporting migration
- VMI dashboards

This sequencing preserves the critical corrective-action workflow first, then migrates the adjacent information modules that make Customer Complaint Management a broader business operating portal.
