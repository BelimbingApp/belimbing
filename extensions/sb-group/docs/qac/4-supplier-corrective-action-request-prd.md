# Product Requirements Document: Supplier Corrective Action Request (SCAR)

## Document Status

- Status: Draft
- Source: product proposal inferred from SCAR-related behavior referenced in the legacy Internal Corrective Action (`ICAR`) and Customer Complaint Management (`IOSS`) subsystems, including `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_ICAR/subsystem/`, `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_ICAR/DB_STORED_PROCEDURES/`, `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_ICAR/DB_VIEWS/`, `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_ICAR/DB_TABLES/`, `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_IOSS/subsystem/`, `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_IOSS/DB_STORED_PROCEDURES/`, `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_IOSS/DB_VIEWS/`, and `/mnt/d/Repo/BitBucket/sbg-portals/Beta_SB_IOSS/DB_TABLES/`
- Purpose: define what a dedicated SCAR product should do as the supplier-facing corrective-action workflow for SB Group

## Legacy Reference

There is no dedicated legacy SCAR source code module.

This PRD is derived from SCAR-related sections embedded inside the legacy Internal Corrective Action and Customer Complaint Management workflows.

Embedded legacy screens with SCAR sections:

- `Beta_SB_ICAR/subsystem/mgr_qual_service_assignment.aspx`
- `Beta_SB_ICAR/subsystem/mgr_prod_service_update.aspx`
- `Beta_SB_ICAR/subsystem/mgr_qual_service_qac.aspx`
- `Beta_SB_IOSS/subsystem/mgr_qual_service_assignment.aspx`
- `Beta_SB_IOSS/subsystem/mgr_qual_service_assignment_hod.aspx`
- `Beta_SB_IOSS/subsystem/mgr_prod_service_update.aspx`
- `Beta_SB_IOSS/subsystem/mgr_qual_service_qac.aspx`

Key stored procedures referenced by embedded SCAR behavior:

- `Beta_SB_ICAR/DB_STORED_PROCEDURES/SP_FORM_SERVICE_SCAR_ADD_R1`
- `Beta_SB_ICAR/DB_STORED_PROCEDURES/SP_FORM_SERVICE_SCAR_UPD_R1`
- `Beta_SB_IOSS/DB_STORED_PROCEDURES/SP_FORM_SERVICE_SCAR_ADD`
- `Beta_SB_IOSS/DB_STORED_PROCEDURES/SP_FORM_SERVICE_SCAR_UPD`

Key tables:

- `Beta_SB_ICAR/DB_TABLES/TBL_FORM_SERVICE_SCAR_R2`
- `Beta_SB_IOSS/DB_TABLES/TBL_FORM_SERVICE_SCAR_R1`
- `Beta_SB_ICAR/DB_TABLES/TBL_NEXT_NUMBER`
- `Beta_SB_IOSS/DB_TABLES/TBL_NEXT_NUMBER`

Related views and supporting references:

- `Beta_SB_ICAR/DB_VIEWS/VIEW_SERVICE_CASE_SCAR`
- `Beta_SB_ICAR/DB_VIEWS/VIEW_SUPPLIER_EMAIL`
- `Beta_SB_IOSS/DB_VIEWS/VIEW_SUPPLIER_EMAIL`

Design note:

- Because SCAR was not implemented as a standalone legacy module, this PRD intentionally combines legacy parity fields with modern product design recommendations.

## 1. Product Summary

SCAR is a supplier corrective action management product used to formally escalate supplier-related defects, service failures, quality escapes, and delivery or process nonconformities to external vendors.

It should sit between internal case management and supplier execution:

- Internal teams identify a supplier-linked issue.
- SCAR formalizes the request to the supplier.
- The supplier responds with containment, root cause, and corrective action.
- Internal quality and procurement teams review the supplier response.
- The case is verified, settled, and closed with a clear audit trail.

In a mature setup, SCAR should not be just an email attachment generator. It should be a structured supplier-accountability workflow with deadlines, evidence, follow-up, and performance history.

## 2. Problem Statement

When a defect or operational failure is caused by a supplier, internal teams need a controlled way to request corrective action, track supplier accountability, manage compensation or claim discussions, verify effectiveness, and preserve supplier performance history.

## 3. Goals

- Create a formal supplier-facing corrective-action workflow.
- Standardize how supplier issues are logged, assigned, and tracked.
- Make supplier responses structured instead of free-form email threads.
- Capture containment, root cause, corrective action, and effectiveness evidence.
- Support both corrective-action-only and corrective-action-plus-compensation flows.
- Give quality and procurement clear visibility into overdue, high-risk, and repeated supplier issues.
- Build a reusable supplier knowledge base and supplier performance history.

## 4. Product Positioning

SCAR should be a dedicated module that can be launched from internal complaint systems such as Internal Corrective Action and Customer Complaint Management, but it should also be capable of operating as its own workflow.

SCAR should support these entry paths:

- created from an internal complaint or corrective-action case
- created from incoming quality inspection failures
- created from production nonconformance linked to supplier material
- created from service or maintenance vendor failures
- created manually by quality or procurement

## 5. Roles and Access

### 5.1 Quality Manager

- Creates SCAR records.
- Defines issue statement, due dates, and required response.
- Reviews supplier root cause and corrective action.
- Verifies effectiveness.
- Closes or reopens SCAR.

### 5.2 QAC / Quality Approver

- Reviews high-impact or critical SCARs.
- Approves closure for material supplier cases or major incidents.

### 5.3 Procurement

- Confirms supplier ownership.
- Coordinates commercial and contractual follow-up.
- Tracks claim value, compensation, debit note, replacement, or service recovery.
- Escalates overdue supplier responses.

### 5.4 Supplier User

- Receives SCAR notification.
- Views issue details and due dates.
- Submits containment, investigation, root cause, and corrective action.
- Uploads evidence.
- Responds to review comments and resubmission requests.

### 5.5 Internal Stakeholder / Requestor

- Can view linked SCAR status.
- Can monitor supplier progress.
- Can provide supporting evidence when requested.

### 5.6 System Administrator

- Maintains supplier contacts, roles, routing rules, severity rules, templates, and notification settings.

## 6. Core Concepts

### 6.1 SCAR Record

A SCAR is the formal supplier corrective-action case.

### 6.2 Linked Source Case

A SCAR may be linked to one or more upstream records such as:

- internal corrective action case number
- customer complaint case number
- incoming QC rejection number
- purchase order / delivery order / invoice number
- batch / lot / production run reference

### 6.3 Supplier Response Package

Each supplier response should be structured around:

- immediate containment
- problem confirmation
- root cause analysis
- corrective action
- implementation date
- objective evidence
- recurrence prevention

### 6.4 Commercial Resolution

For compensation flows, SCAR should also support:

- claim quantity
- claim value
- settlement method
- credit note / debit note / replacement / rework / service credit
- final agreed commercial outcome

## 7. Lifecycle and Status Model

## 7.1 Recommended Statuses

- `DRAFT` - created internally but not yet issued to supplier
- `ISSUED` - formally sent to supplier
- `ACKNOWLEDGED` - supplier has acknowledged receipt
- `CONTAINMENT_SUBMITTED` - supplier provided short-term containment
- `UNDER_INVESTIGATION` - supplier is preparing full analysis
- `RESPONSE_SUBMITTED` - supplier submitted full corrective-action response
- `UNDER_REVIEW` - internal team is reviewing supplier response
- `ACTION_REQUIRED` - supplier must revise or provide more evidence
- `VERIFICATION_PENDING` - corrective action implemented, awaiting effectiveness verification
- `CLOSED` - internal team accepted the response and completed verification
- `REJECTED` - invalid SCAR, wrong supplier, duplicate, or not justified
- `CANCELLED` - withdrawn internally before completion

### 7.2 Workflow Rules

- A new SCAR begins as `DRAFT` or `ISSUED`, depending on creation method.
- Once sent, the supplier must acknowledge receipt.
- Critical SCARs should require containment before full investigation.
- A supplier response is not accepted until required fields and evidence are complete.
- Internal review can move the SCAR back to `ACTION_REQUIRED` any number of times.
- Closure requires internal acceptance and effectiveness verification.

### 7.3 SLA and Escalation Rules

The system should track these due dates independently:

- acknowledgement due date
- containment due date
- full response due date
- verification due date

The system should escalate overdue SCARs to:

- supplier contact
- procurement owner
- quality owner
- optional higher management based on severity or age

## 8. Case Creation Requirements

The system shall allow internal users to create a SCAR with:

- SCAR number
- linked source case number
- supplier name
- supplier site / plant
- supplier contact person
- supplier contact email
- supplier contact phone
- issue date
- issue owner
- issue category
- severity / risk level
- product or service name
- product code or service code
- PO / DO / invoice number
- lot / batch / serial / machine / run reference
- detected area / detection point
- quantity affected
- UOM
- claim quantity
- claim value
- issue description
- defect description
- attachments
- complaint request type

Complaint request types should include at least:

- Corrective Action
- Corrective Action & Compensation

### 8.1 Legacy Compatibility Baseline

The modern SCAR model should preserve the minimum field set already proven in the legacy ICAR and Customer Complaint Management systems.

Legacy-backed minimum fields:

- SCAR number
- supplier company name
- PO / DO / invoice number
- linked parent case number
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

Behavior inferred from the legacy systems:

- SCAR is created only after supplier assignment from the parent case workflow.
- SCAR remains visible during internal assignment, response, review, and closure stages.
- Legacy systems use system-generated sequential SCAR numbers from a next-number table; the modern product should preserve human-readable sequential numbering.

### 8.2 Recommended Position Inside the Three-Model QAC Design

Inside the QAC module, SCAR should be the third main model alongside the internal or customer-facing case model and the internal resolution workflow model.

Recommended relationship:

- one internal case or customer complaint case may link to zero or more SCAR records
- each SCAR belongs to exactly one parent case
- each SCAR may reference one active internal workflow stage at a time

If business policy allows only one supplier per case, the first release may enforce one active SCAR per case while keeping the data model ready for multiple SCARs later

## 9. Supplier Portal / Supplier Response Requirements

I think a modern SCAR should provide a supplier-facing response experience rather than relying only on email.

The supplier shall be able to:

- view issued SCAR details
- acknowledge receipt
- download attachments
- submit containment action
- submit root cause analysis
- submit corrective action plan
- submit implementation dates
- submit prevention of recurrence plan
- upload evidence
- ask clarifying questions
- respond to internal review comments

Suggested structured response sections:

- problem understanding
- immediate containment
- affected lots / shipments / customers
- root cause method used
- root cause statement
- corrective action implemented
- preventive action / systemic prevention
- verification method used by supplier
- implementation owner
- implementation date
- attached evidence

## 10. Internal Review Requirements

Quality and procurement shall be able to:

- review supplier submissions section by section
- accept or reject each response cycle
- request additional evidence
- request clarification
- return the SCAR for rework
- log internal comments not visible to supplier
- log supplier-visible comments
- approve verification readiness
- close the SCAR

For compensation-related cases, procurement shall be able to:

- record claim decision
- record settlement amount
- record settlement type
- record settlement date
- record whether the case remains open pending commercial resolution

## 11. Verification Requirements

Closure should not depend only on the supplier saying the action is complete.

The system should support internal verification using:

- verification result
- verified by
- verification date
- verification method
- verification notes
- verification evidence

Verification outcomes should include:

- effective
- partially effective
- ineffective

If verification is ineffective, the SCAR should reopen into `ACTION_REQUIRED` or `UNDER_INVESTIGATION`.

## 12. Attachments and Evidence

The system shall support attachment categories such as:

- original complaint evidence
- inspection reports
- photos / videos
- COA / test reports
- supplier response documents
- 8D report or equivalent
- containment evidence
- corrective-action evidence
- commercial claim evidence
- verification evidence

Attachments should remain downloadable for all authorized participants throughout the lifecycle.

### 12.1 Legacy Attachment Compatibility

The legacy parent systems do not use a dedicated SCAR attachment table. Instead, SCAR relies heavily on evidence already attached to the parent case.

Minimum parity requirements for integration with legacy-style parent workflows:

- inherit visibility to the parent complaint attachment
- inherit visibility to department support attachments
- inherit visibility to occurrence-side evidence
- inherit visibility to leakage-side evidence

The modern SCAR module should improve on this by allowing SCAR-specific attachments such as:

- supplier acknowledgement
- 8D report
- supplier root-cause evidence
- corrective-action implementation evidence
- debit note / credit note evidence
- verification evidence linked to supplier closure

## 13. Notifications

The system shall notify stakeholders for:

- SCAR issuance
- supplier acknowledgement overdue
- containment overdue
- response overdue
- supplier response submitted
- review comments issued
- rework requested
- verification due
- SCAR closed
- commercial settlement completed

Notification channels should include at least:

- email
- in-app notification

Optional later channels:

- supplier portal inbox
- Teams / Slack integration

### 13.1 Parent-Workflow Notification Rules

Because SCAR is launched from a parent quality case, the modern product should also support parent-linked notifications:

- SCAR created from Internal Corrective Action
- SCAR created from Customer Complaint Management
- parent case returned or reopened while SCAR is still active
- parent case closed while SCAR remains open
- SCAR closed and parent case ready for final internal closure

## 14. Dashboards and Reporting

The system should provide dashboards for:

- open SCARs by status
- overdue SCARs
- high-severity SCARs
- SCARs by supplier
- SCARs by material / service category
- SCARs by plant / site / department
- SCARs with compensation claims
- repeat issues by supplier
- closure lead time
- verification failure rate

Reports should support:

- date range filtering
- supplier filtering
- category filtering
- severity filtering
- status filtering
- linked source-case filtering
- export for audit and supplier review meetings

## 15. Knowledge Base and Supplier History

SCAR should build a reusable supplier knowledge base.

Users should be able to search past SCARs by:

- supplier
- product code
- defect type
- root cause
- corrective action
- plant / location
- linked source case
- commercial outcome

The supplier history view should show:

- number of SCARs
- repeat defects
- average response time
- average closure time
- overdue rate
- verification failure rate
- compensation totals
- latest open high-risk items

## 16. Suggested Integrations

SCAR should integrate with:

- Internal Corrective Action (`ICAR`)
- Customer Complaint Management (`IOSS`)
- purchase orders and supplier master
- incoming quality inspection records
- nonconformance records
- document storage
- email service
- ERP supplier and material masters

### 16.1 Supplier Master and Routing Rules

The modern product should not hard-code supplier contact routing in UI logic.

It should instead integrate with or maintain:

- supplier master
- supplier site / plant master
- supplier contact list
- supplier escalation contacts
- procurement owner mapping
- supplier category or commodity mapping

This is a direct improvement over the legacy parent systems, which often embed supplier email addresses in application code.

## 17. Business Rules I Recommend

- Critical supplier issues must require containment within 24 hours or a configured SLA.
- Full supplier response should use a structured format, not free-form email only.
- Compensation cases should not close until commercial settlement is explicitly recorded.
- Closure should require internal verification, not just supplier completion.
- Repeated SCARs for the same supplier and defect family should trigger escalation.
- Supplier contacts should be versioned so historical records preserve who was contacted.
- Parent case status and SCAR status should be related but not identical; a parent case may stay under internal review while a SCAR is still open.
- Every SCAR should record the parent case type and parent case number at creation time, even if the display label differs between Internal Corrective Action and Customer Complaint Management.

## 18. Non-Functional Expectations

- Full audit trail for all status changes, comments, notifications, and file uploads.
- Clear separation between internal-only notes and supplier-visible notes.
- Strong attachment and access controls.
- Easy supplier access with low-friction login or secure token-based access.
- Searchable historical archive for audit, quality review, and supplier scorecards.
- Configurable SLA, severity, and escalation rules.

## 19. Out of Scope for First Release

- full supplier scorecard analytics beyond basic trend reporting
- automated CAPA effectiveness scoring using AI
- supplier contract management
- multi-tier supplier cascading workflows
- chargeback accounting automation inside ERP

## 20. Recommended Release Scope

### Release 1

- SCAR creation from internal cases
- supplier issuance and acknowledgement
- structured supplier response form
- internal review and rework loop
- verification and closure
- attachments
- notifications
- dashboard and core reporting
- supplier history view

### Release 2

- procurement settlement workflow
- deeper ERP integrations
- supplier self-service portal enhancements
- recurring issue detection and escalation automation
- supplier performance scorecards

## 21. My Design Opinion

SCAR should be treated as a first-class workflow, not just a child form inside Internal Corrective Action or Customer Complaint Management.

Why:

- supplier accountability has different actors, due dates, and evidence needs than internal corrective action
- compensation handling belongs with procurement and supplier coordination, not only quality
- repeated supplier issues should build supplier intelligence over time
- external collaboration works better with structured response flows than with email-only exchanges

So my recommendation is:

- keep SCAR linkable from Internal Corrective Action (`ICAR`) and Customer Complaint Management (`IOSS`)
- but model SCAR as its own module with its own statuses, participants, SLAs, and reporting

## 22. Open Design Questions

- Should suppliers log into a portal, or should first release use secure email links?
- Should SCAR use a formal 8D template, or a simpler structured corrective-action form?
- Should procurement and quality both be required to close compensation cases?
- Should one internal case be allowed to create multiple SCARs when multiple suppliers are involved?
- Should supplier performance metrics be visible to procurement only, or to broader management?
