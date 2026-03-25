# QAC Domain Model

## Document Status

- Status: Draft
- Purpose: define the target domain model and product architecture for the sb-group QAC module
- Position: this document is the implementation-first architecture for the module and takes precedence over legacy schema shape
- Base implementation backbone: `app/Base/Workflow`
- Source PRDs:
  - `extensions/sb-group/docs/qac/2-internal-corrective-action-prd.md`
  - `extensions/sb-group/docs/qac/3-customer-complaint-management-prd.md`
  - `extensions/sb-group/docs/qac/4-supplier-corrective-action-request-prd.md`

## 1. Problem Essence

QAC needs one deep module that turns scattered complaint and corrective-action work into a single operational system for intake, triage, investigation, supplier escalation, verification, closure, and institutional learning.

## 2. Design Stance

This module should not reproduce the legacy systems as screen-driven forms backed by stage-specific tables.

It should deliberately replace that design with:

- one coherent domain model
- one policy-driven workflow engine built on `app/Base/Workflow`
- one evidence and decision timeline
- one searchable operational memory
- one AI-assisted working surface for operators, reviewers, and managers

The goal is not to digitize old paperwork more neatly. The goal is to change how quality work is done.

## 3. Product Principles

### 3.1 Deep Module, Simple Surface

The module should hide workflow complexity behind a small set of obvious operations:

- open a case
- triage a case
- assign ownership
- record a response
- request rework
- verify effectiveness
- issue a supplier request
- close a case
- search prior cases

Users should not need to understand internal table boundaries, workflow branches, or legacy status names.

### 3.2 Policy Over Status Spaghetti

The system should be driven by action policies, not by ad hoc string statuses.

That means:

- statuses exist, but they are derived from permitted transitions
- each transition has explicit preconditions
- each transition produces auditable decisions and events
- routing, SLA, escalation, and required evidence are policy concerns, not page-code concerns

BLB implementation note:

- QAC should reuse `app/Base/Workflow/Services/WorkflowEngine`, `TransitionValidator`, `StatusTransition`, `StatusConfig`, `StatusHistory`, and `HasWorkflowStatus` rather than introducing a parallel workflow engine

### 3.3 Dossier Over Form Fragments

A case should feel like a living dossier, not a chain of disconnected forms.

Every participant should work from the same case workspace containing:

- current summary
- timeline
- decisions
- evidence
- action items
- open risks
- linked supplier actions

### 3.4 Evidence First

Claims, decisions, and closure should be grounded in evidence.

The system should treat:

- attachments
- photos
- PDFs
- root-cause narratives
- verification notes
- supplier responses

as first-class operational material, not side files.

### 3.5 AI as a Working Partner, Not a Decoration

The module should be AI-native.

AI should help users:

- extract structure from messy inputs
- summarize long case histories
- propose classifications and owners
- detect duplicates and similar past cases
- draft root-cause and corrective-action candidates
- draft supplier communications
- identify missing evidence before transition
- generate closure narratives and knowledge entries

AI output must always be reviewable, attributable, and reversible.

## 4. Target User Experience

The modern QAC experience should look like this:

1. A user reports an issue in natural language, with files if needed.
2. The system structures the report automatically.
3. QAC receives a triage-ready case with suggested category, severity, owner, and similar historical cases.
4. Investigators work inside one shared case workspace instead of multiple stage pages.
5. Supplier escalation is created as a linked but independent workstream when needed.
6. The system continuously tells each actor what is missing, overdue, risky, or likely to be wrong.
7. Closure produces a reusable institutional memory entry automatically.

This is materially better than the legacy model of manually hopping between queues, forms, and email threads.

## 5. Public Module Interface

The domain should expose a small set of commands and queries.

### 5.1 Commands

- `openCase()`
- `enrichCase()`
- `triageCase()`
- `assignCase()`
- `approveCase()`
- `returnCase()`
- `rejectCase()`
- `recordDepartmentResponse()`
- `requestRework()`
- `verifyCase()`
- `closeCase()`
- `completeFollowUp()`
- `issueSupplierRequest()`
- `updateSupplierRequest()`
- `closeSupplierRequest()`

### 5.2 Queries

- `getCaseWorkspace()`
- `getInbox()`
- `getTimeline()`
- `getKnowledgeMatches()`
- `getRiskQueue()`
- `getSupplierPerformanceView()`
- `getReportingProjection()`

These interfaces should stay stable even if the internal persistence design evolves.

## 5A. Base Workflow Alignment

QAC should be an opinionated module built on top of `app/Base/Workflow`, not beside it.

### 5A.1 How QAC should use Base Workflow

- workflow-bearing models should implement `HasWorkflowStatus`
- each QAC flow should be registered as a Base Workflow flow
- allowed actions should map to `StatusTransition` edges
- role and permission rules should map to transition capabilities and guard classes
- transition side effects should map to `action_class`
- status timeline should come from `StatusHistory`
- status notifications should reuse transition notification infrastructure where suitable

### 5A.2 Proposed QAC flows in Base Workflow terms

- `qac_internal_case`
- `qac_customer_case`
- `qac_supplier_request`

Best judgement:

- the top-level workflow participant should be the case-bearing model for each flow, not a separate hidden workflow table owned only by QAC
- `SupplierCorrectiveActionRequest` should be its own Base Workflow participant with its own flow code

## 6. Three Main Business Models

The module should keep exactly three top-level business models.

### 6.1 `CaseRecord`

Purpose:

- represent the business issue itself

Owns:

- identity
- source context
- reporter context
- business subject details
- complaint / issue description
- product and transaction context
- top-level status
- current ownership summary
- severity and classification

Does not own:

- the detailed corrective-action lifecycle
- supplier-facing response lifecycle

Base Workflow alignment:

- `CaseRecord` should be the workflow participant for case-level flows and should implement `HasWorkflowStatus`
- its `status` column should represent the broad business-visible case state, while detailed operational stage information may live in workflow metadata and child records

Subtypes supported by the same model:

- Internal Corrective Action
- Customer Complaint Management

Recommended fields:

- `id`
- `case_no`
- `case_source`
- `case_kind`
- `title`
- `summary`
- `status`
- `severity`
- `case_type`
- `case_category`
- `reported_at`
- `reported_by_name`
- `reported_by_email`
- `created_by_user`
- `created_at`
- `current_owner_name`
- `current_owner_email`
- `current_owner_department`
- `current_owner_assigned_at`
- `reject_reason`
- `reject_at`
- `is_supplier_related`
- `requires_follow_up`
- `follow_up_completed_at`

Internal-case specific fields:

- `attention_scope`
- `clause_reference`
- `product_name`
- `product_code`
- `quantity`
- `uom`
- `detected_area`
- `dimension`
- `issued_by`
- `issuing_date`
- `issue_description`
- `submitted_department`

Customer-complaint specific fields:

- `customer_name`
- `customer_contact`
- `customer_document_no`
- `customer_sales_order_no`
- `customer_document_date`
- `customer_code`
- `customer_display_name`
- `customer_tel`
- `customer_fax`
- `customer_email`
- `customer_attention_name`
- `region`
- `complaint_group`
- `salesperson_name`
- `salesperson_email`
- `area`
- `market`
- `ops_marker`
- `claim_or_cost_value`

Detail-line note:

- complaint line detail may be stored in child rows, but it remains within the `CaseRecord` boundary, not a separate top-level business model

### 6.2 `CaseResolutionWorkflow`

Purpose:

- represent the internal work needed to resolve a case

Owns:

- triage
- assignment
- due dates and action items
- approval gates
- containment and correction
- root cause
- corrective action
- review and verification
- final closure readiness
- customer-facing follow-up readiness where relevant

Does not own:

- original business case identity
- supplier-facing escalation as an independent stream

Base Workflow alignment:

- `CaseResolutionWorkflow` should not become a second generic workflow engine inside QAC
- it should be the domain aggregate that stores resolution data, policy snapshots, action items, and structured metadata around the case workflow already executed via Base Workflow
- if a separate workflow-bearing model is ever introduced here, it must justify why `CaseRecord` alone cannot remain the workflow participant

Recommended fields:

- `id`
- `case_record_id`
- `workflow_status`
- `triage_summary`
- `triage_confidence`
- `assigned_department`
- `assigned_supplier_name`
- `assignment_comment`
- `assignment_due_at`
- `assigned_by`
- `assigned_at`
- `approval_state`
- `approved_by`
- `approved_at`
- `rework_reason`
- `containment_action`
- `correction`
- `root_cause_occurred`
- `root_cause_leakage`
- `corrective_action_occurred`
- `effective_date_occurred`
- `corrective_action_leakage`
- `effective_date_leakage`
- `quality_review_comment`
- `quality_feedback`
- `verification_effective`
- `verified_by`
- `verified_at`
- `cn_issued`
- `dn_received`
- `follow_up_notes`
- `finalized_cost_value`
- `follow_up_by`
- `follow_up_at`
- `closed_by`
- `closed_at`

Design note:

- the workflow should store the current structured state and also emit append-only workflow events; this replaces the legacy pattern of copying rows into many stage-specific tables

### 6.3 `SupplierCorrectiveActionRequest`

Purpose:

- represent the supplier-facing corrective-action stream linked to a case

Owns:

- supplier issue statement
- supplier obligations and due dates
- supplier response package
- compensation handling
- supplier verification and closure

Does not own:

- the main internal case lifecycle
- department response lifecycle

Base Workflow alignment:

- `SupplierCorrectiveActionRequest` should implement `HasWorkflowStatus`
- its workflow should be configured as an independent Base Workflow flow so supplier work can progress independently of the parent case while remaining linked to it

Recommended fields:

- `id`
- `scar_no`
- `case_record_id`
- `parent_case_no`
- `parent_case_source`
- `supplier_name`
- `supplier_site`
- `supplier_contact_name`
- `supplier_contact_email`
- `supplier_contact_phone`
- `po_do_invoice_no`
- `product_name`
- `product_code`
- `detected_area`
- `dimension`
- `issued_by`
- `issuing_date`
- `request_type`
- `severity`
- `claim_quantity`
- `uom`
- `claim_value`
- `problem_description`
- `issue_owner`
- `status`
- `acknowledgement_due_at`
- `containment_due_at`
- `response_due_at`
- `verification_due_at`
- `containment_response`
- `root_cause_response`
- `corrective_action_response`
- `supplier_response_submitted_at`
- `commercial_resolution_type`
- `commercial_resolution_amount`
- `commercial_resolution_at`
- `verified_by`
- `verified_at`
- `closed_by`
- `closed_at`

## 7. Supporting Records

These are important, but they are not top-level business models.

- `CaseEvent`
- `CaseDecision`
- `CaseAttachment`
- `CaseComment`
- `ActionItem`
- `NotificationLog`
- `KnowledgeProjection`
- `ReportingProjection`
- `AIArtifact`

### 7.1 `CaseEvent`

QAC should not invent a large family of custom transition events as its primary backbone.

Instead, transition history and transition notifications should be driven by Base Workflow using:

- `StatusHistory`
- `TransitionCompleted`

QAC-specific event records should be reserved for facts that are not simply workflow transitions, such as:

- attachment ingested
- AI artifact accepted
- knowledge entry published
- external file or email imported

Recommended fields for non-transition QAC events:

- `id`
- `case_record_id`
- `workflow_id`
- `scar_id`
- `event_type`
- `actor_type`
- `actor_id`
- `payload`
- `occurred_at`

### 7.2 `CaseAttachment`

Attachments should be typed evidence with clear ownership.

Owner rules:

- original complaint evidence -> `CaseRecord`
- department evidence -> `CaseResolutionWorkflow`
- supplier evidence -> `SupplierCorrectiveActionRequest`

Legacy type mapping to preserve:

- Internal Corrective Action
  - `USER`
  - `PRD`
  - `OCCURED`
  - `LEAKAGE`
- Customer Complaint Management
  - `SLS`
  - `PRD`
  - `OCCURED`
  - `LEAKAGE`

Normalized catalog:

- `ORIGINAL_COMPLAINT`
- `DEPARTMENT_SUPPORT`
- `OCCURRENCE_EVIDENCE`
- `LEAKAGE_EVIDENCE`
- `SUPPLIER_RESPONSE`
- `COMMERCIAL_EVIDENCE`
- `VERIFICATION_EVIDENCE`

Recommended fields:

- `id`
- `owner_type`
- `owner_id`
- `legacy_type`
- `normalized_type`
- `filename`
- `storage_key`
- `uploaded_by`
- `uploaded_at`
- `is_primary`

### 7.3 `AIArtifact`

This is the major AI-native addition.

An `AIArtifact` is a machine-generated but human-reviewable output attached to a case, workflow, or SCAR.

Examples:

- extracted field candidates from PDFs or images
- suggested classification
- suggested assignee or department
- duplicate or similar-case matches
- root-cause hypotheses
- corrective-action drafts
- closure summary draft
- supplier email draft
- risk summary
- missing-evidence checklist

Recommended fields:

- `id`
- `owner_type`
- `owner_id`
- `artifact_type`
- `model_name`
- `input_refs`
- `content`
- `confidence`
- `review_status`
- `accepted_by`
- `accepted_at`
- `created_at`

Policy:

- AI artifacts are advisory until explicitly accepted
- accepted AI artifacts may be promoted into business fields or events
- the original AI output must remain visible for audit and learning

## 7A. Proposed PRs to Base Workflow

QAC can use Base Workflow today, but a few targeted improvements would make it a much stronger foundation.

### 7A.1 Enriched Generic Transition Event

Base Workflow already emits `TransitionCompleted`, which is the right architectural direction.

Proposed PR:

- enrich the event contract so listeners and projectors receive a normalized transition payload directly, without needing to infer important values from the model and transition records

Recommended payload fields:

- `flow`
- `flow_model`
- `flow_id`
- `from_status`
- `to_status`
- `actor_id`
- `actor_role`
- `actor_department`
- `assignees`
- `comment`
- `comment_tag`
- `attachments`
- `metadata`
- `transitioned_at`

Why:

- QAC needs one general transition event backbone, not many individuated workflow events
- projectors for inboxes, dossier timelines, notifications, AI triggers, and reporting should not need to reconstruct `from_status` or couple tightly to Eloquent internals

### 7A.2 Transition Payload DTO

Proposed PR:

- add a dedicated DTO for transition event payloads so listeners receive a stable contract rather than raw model plus transition objects only

Why:

- reduces coupling between modules and Base Workflow implementation details
- makes cross-module projection code simpler and safer

### 7A.3 Richer Transition Actions

Proposed PR:

- allow transition actions to access richer transition payload/context without pulling directly from model state only

Why:

- QAC actions often need structured metadata, assignees, evidence references, and AI-assisted suggestions

### 7A.4 Better Projection Hooks

Proposed PR:

- define a cleaner extension point for building read models such as dossiers, inboxes, kanban summaries, and risk queues from transition history

Why:

- QAC is projection-heavy and will benefit from a standard BLB way to build module read models on top of Base Workflow

## 8. Workflow as Policy Engine

The module should not hard-code page-specific transitions.

It should centralize workflow rules in a policy layer that answers:

- who can act
- what action is allowed now
- what is required before the action is valid
- what deadlines apply
- who must be notified
- what event should be emitted

### 8.1 Policy Inputs

- case source
- case severity
- supplier involvement
- assigned department
- actor role
- current workflow state
- evidence completeness
- organization rules

### 8.2 Policy Outputs

- allowed actions
- required fields
- required attachment types
- required approvals
- SLA targets
- escalation recipients
- next queue placement

### 8.3 Why This Is Better

This removes the main legacy failure mode:

- business logic scattered across forms, stored procedures, and hard-coded email routing

Instead, BLB gets one module boundary that owns workflow behavior cleanly.

It also avoids a new failure mode:

- building a second ad hoc workflow mechanism inside the QAC module when `app/Base/Workflow` already provides the core primitives

## 9. Status Design

Statuses should be few, semantic, and owned by the right model.

Implementation note:

- Base Workflow remains the source of truth for allowed transitions
- QAC domain code may expose richer derived stage labels, but it should not bypass Base Workflow transition rules

### 9.1 `CaseRecord.status`

Recommended canonical values:

- `OPEN`
- `IN_PROGRESS`
- `UNDER_REVIEW`
- `CLOSED`
- `REJECTED`

Purpose:

- communicate the case state to broad users and management

### 9.2 `CaseResolutionWorkflow.workflow_status`

Recommended values:

- `TRIAGE_PENDING`
- `ASSIGNMENT_PENDING`
- `AWAITING_APPROVAL`
- `AWAITING_RESPONSE`
- `UNDER_QUALITY_REVIEW`
- `SENT_BACK_FOR_REWORK`
- `VERIFIED`
- `COMPLETED`
- `REJECTED`

Purpose:

- express the internal work stage precisely

### 9.3 `SupplierCorrectiveActionRequest.status`

Recommended values:

- `DRAFT`
- `ISSUED`
- `ACKNOWLEDGED`
- `CONTAINMENT_SUBMITTED`
- `UNDER_INVESTIGATION`
- `RESPONSE_SUBMITTED`
- `UNDER_REVIEW`
- `ACTION_REQUIRED`
- `VERIFICATION_PENDING`
- `CLOSED`
- `REJECTED`
- `CANCELLED`

Purpose:

- express the supplier-facing lifecycle independently of the main case

## 10. AI-Native Capabilities

This module should use AI as core workflow infrastructure.

### 10.1 Intake Intelligence

At case creation, AI should:

- extract fields from uploaded documents
- normalize complaint text
- suggest category, type, and severity
- identify likely department ownership
- detect supplier involvement indicators
- surface similar prior cases

### 10.2 Investigator Copilot

During triage and response, AI should:

- summarize the case so far
- point out missing fields or evidence
- suggest root-cause frames
- propose corrective-action patterns from similar cases
- generate structured drafts from unstructured notes

### 10.3 Reviewer Copilot

During review and closure, AI should:

- compare the case against similar closed cases
- detect weak or circular root causes
- detect mismatch between issue, cause, and action
- flag missing verification evidence
- draft closure rationale and knowledge entries

### 10.4 Supplier Collaboration Assistant

For SCAR, AI should:

- draft supplier-facing requests
- summarize supplier responses
- identify missing response sections
- compare supplier responses against prior supplier behavior
- draft internal procurement and quality summaries

### 10.5 Knowledge Engine

The system should not rely only on keyword search.

It should support:

- semantic similarity search
- clustering of repeated issues
- retrieval of similar root causes and actions
- supplier recurrence detection
- cross-case narrative summarization

### 10.6 Safety Rules

- AI suggestions never auto-close a case
- AI suggestions never silently rewrite accepted business facts
- AI outputs must show provenance and confidence
- sensitive data access must follow the same authorization model as normal records

## 11. Search, Memory, and Reporting

Search and reporting should be projections, not primary write models.

Required projections:

- work inbox
- risk inbox
- approval inbox
- supplier inbox
- knowledge base
- case dossier view
- management reporting
- supplier performance view

The most important new projection is the case dossier view:

- a single narrative workspace built from the case, workflow, SCAR, events, attachments, decisions, and AI artifacts

BLB implementation note:

- dossier timelines should primarily be projected from `StatusHistory` plus non-transition QAC events and accepted AI artifacts

## 12. Numbering and Identity

Recommended policy:

- use UUIDs for internal keys
- use human-readable business numbers for cases and SCARs
- keep numbering generation behind a numbering service

Legacy reference:

- ICAR / IOSS case number -> `NextNumber2`
- IOSS complaint detail line -> `NextNumber3`
- file attachment line -> `NextNumber5`
- IOSS SCAR number -> `NextNumber7`

## 13. Legacy Mapping Strategy

Legacy structures are inputs to migration, not templates for the new design.

### 13.1 Map Legacy Rows Into the New Model

- legacy case headers -> `CaseRecord`
- legacy complaint detail rows -> `CaseRecord` child detail rows
- legacy QAC check / assignment / containment / root cause / corrective action / verification rows -> `CaseResolutionWorkflow` plus Base Workflow `StatusHistory`
- legacy SCAR rows -> `SupplierCorrectiveActionRequest`
- legacy file rows -> `CaseAttachment`

### 13.2 Do Not Preserve These Legacy Patterns

- overloaded generic fields like `madd*`
- stage-specific tables as the main mental model
- hard-coded supplier email routing in UI logic
- hidden workflow decisions encoded only in free-text fields
- page-level ownership of business rules

## 14. Recommended Implementation Sequence

1. build `CaseRecord`
2. build `CaseResolutionWorkflow`
3. build `CaseEvent`, `CaseAttachment`, and `ActionItem`
4. build `AIArtifact` and the case dossier view
5. build `SupplierCorrectiveActionRequest`
6. build knowledge and reporting projections

Why this order:

- case and workflow are the stable core
- event and attachment infrastructure unlocks auditability and AI context
- SCAR becomes much easier once the parent case model is strong
- reporting should consume the domain, not shape it

## 15. What I Would Improve Further

If we want the module to be genuinely modern rather than only cleaner than legacy, I would also add:

- dynamic workboards instead of fixed status pages
- explicit action items with owners and deadlines instead of implicit queue responsibility
- decision records for approval, reject, and return actions
- duplicate-case prevention at intake using semantic similarity
- reusable corrective-action pattern library generated from closed cases
- supplier health and recurrence intelligence from SCAR history
- automatic closure package generation for audit and management review

## 16. Key Design Decisions

- Keep exactly three main business models.
- Make workflow policy-driven.
- Make the case dossier the main user experience.
- Make evidence and event history first-class.
- Treat AI output as governed operational artifacts.
- Treat SCAR as a first-class model even though it was not a standalone legacy module.
- Use legacy data for reference and migration, not for architecture.

## 17. Open Decisions for Planning

- Should `CaseRecord` use one shared table with nullable source-specific fields, or a shared core plus source-specific detail tables?
- Should follow-up for customer complaint management remain part of `CaseResolutionWorkflow`, or be extracted into a dedicated closure event type?
- Should release 1 allow multiple active SCARs per case?
- Should AI classification suggestions be saved automatically as drafts, or only when a user explicitly requests them?
- Should semantic knowledge search be release 1 or release 2?
