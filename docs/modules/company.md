# BLB Company Module Architecture - High-Level Overview

**Document Type:** Module Architecture
**Scope:** Core/Company ownership, company relationships, hierarchy, and tenant-primary-company contract
**Last Updated:** 2026-08-11

## Overview

Core/Company owns organizational entities inside a tenant. Tenant is the outer
isolation and subscription boundary; Company is the inner organizational boundary.
Every company has an explicit immutable tenant assignment, and every provisioned
tenant has one primary company recorded by this module.

## User Story

> Implement the **Core Company Module** to support SBG's multi-company group structure with international operations. This module is foundational to Belimbing as an ERP system - every deployment represents at least one registered business/company. This module manages:
> - **Company Hierarchy:** Parent-child relationships for company groups (e.g., SBG Holdings → SBG Indonesia)
> - **Company Relationships:** Multiple relationship types (internal, customer, supplier, partner, agency)
> - **External Access:** Controlled portal access for customers, suppliers, and agencies
> - **Business Context:** Company scope, activities, registration details for compliance and AI inference

---

## Core Philosophy

The BLB Company Module is a **foundational Core module** that every Belimbing deployment requires. Unlike optional business modules, the Company module is always present because:

1. **Every deployment represents a business** - At minimum, one registered company must exist
2. **Organizational context is universal** - Users, workflows, and data belong to companies
3. **Scope system depends on it** - Company is a key level in the configuration hierarchy

The module manages both **internal company structure** (subsidiaries, departments) AND **external company relationships** (customers, suppliers, partners).

---

## Foundation Layer (Core BLB)

### Essential Company Management

The foundation provides the **core functionality** needed for any ERP deployment:

- **Basic Company Model**: Name, slug, status, registration details
- **Company Status Management**: Active, suspended, pending, archived
- **Company Context**: Current company selection for multi-company users

### Company Hierarchy

Parent-child relationships are **foundational**, not optional:

- **Group Structures**: Parent holding company with subsidiaries
- **International Operations**: Local subsidiaries under regional or global parent
- **Unlimited Depth**: Hierarchy supports any organizational structure

**Example: SBG Group**
```
SBG Holdings (parent)
├── SBG Indonesia (subsidiary)
├── SBG Malaysia (subsidiary)
└── SBG Singapore (subsidiary)
```

### Company Relationships

Companies interact with other companies through typed relationships:

- **Relationship Types**: Internal, customer, supplier, partner, agency
- **Multi-Relationship Support**: A company can have multiple relationship types simultaneously (e.g., both customer AND supplier)
- **Temporal Validity**: Relationships have `effective_from` and `effective_to` dates for historical tracking
- **Configurable Types**: Relationship types stored in configuration tables, customizable per deployment

### External Access Control

Controlled portal access for external parties:

- **Portal Access**: Customers, suppliers, and agencies can access specific data
- **Granular Permissions**: Fine-grained control per relationship type
- **Data Exposure Control**: Each relationship type defines what data is visible

### Business Context

Rich metadata for compliance and intelligent processing:

- **Company Scope & Activities**: Industry, services, business focus
- **Registration Details**: Tax ID, registration number, jurisdiction, legal entity type
- **AI Inference Metadata**: Context that helps AI services make intelligent decisions

### Scope Integration

Company integrates with the scope-based configuration system:

```
Global (default)
└── Company
    └── Department
        └── User
```

- **Configuration Inheritance**: Company settings override global defaults
- **Scope Consumer**: Company implements the "Company" scope level
- **Hierarchical Fallback**: Settings cascade down the scope hierarchy

---

## Data Model Overview

```mermaid
erDiagram
    Company ||--o{ Company : "parent-child"
    Company ||--o{ CompanyRelationship : "has relationships"
    Company ||--o{ ExternalAccess : "grants access"
    CompanyRelationship }o--|| Company : "related to"
    CompanyRelationship }o--|| RelationshipType : "type"
    User }o--o{ Company : "can access via portal"

    Company {
        bigint id PK
        bigint tenant_id FK
        bigint parent_id FK
        string name
        string slug
        string status
        string legal_name
        string registration_number
        string tax_id
        string legal_entity_type
        string jurisdiction
        string email
        string website
        json scope_activities
        json metadata
        timestamps
        deleted_at
    }

    CompanyRelationship {
        bigint id PK
        bigint company_id FK
        bigint related_company_id FK
        bigint relationship_type_id FK
        date effective_from
        date effective_to
        json metadata
        timestamps
        deleted_at
    }

    RelationshipType {
        bigint id PK
        string code
        string name
        string description
        boolean is_external
        boolean is_active
        json metadata
        timestamps
    }

    ExternalAccess {
        bigint id PK
        bigint company_id FK
        bigint relationship_id FK
        json permissions
        boolean is_active
        timestamp access_granted_at
        timestamp access_expires_at
        json metadata
        timestamps
        deleted_at
    }
```

### Key Tables

| Table | Purpose |
|-------|---------|
| `companies` | Core company records with hierarchy |
| `tenant_primary_companies` | One explicit primary company per provisioned tenant |
| `company_relationships` | Links between companies with temporal validity |
| `company_relationship_types` | Configurable relationship type definitions |
| `company_external_accesses` | Portal access permissions per relationship |

### Implementation Notes

- **Primary keys:** All tables use Laravel default `id()` (bigint auto-increment), not UUIDs.
- **Tenant integrity:** `companies.tenant_id` is required with no default, references
  `tenants`, and is immutable in normal operations. Unique `(id, tenant_id)` supports
  the composite parent foreign key, preventing cross-tenant company hierarchies.
- **Primary company:** `tenant_primary_companies.tenant_id` is both PK and FK;
  `company_id` is unique; composite `(company_id, tenant_id)` references
  `companies(id, tenant_id)`. Missing assignment means not yet provisioned. Invalid
  assignments are invariant violations, and primary deletion is blocked until an
  explicit safe transfer.
- **Table names:** Follow Core convention `{module}_{entity}` (e.g. `company_relationship_types`, `company_external_accesses`).
- **Company registration:** Implemented as normalized columns (`legal_name`, `registration_number`, `tax_id`, `legal_entity_type`, `jurisdiction`) plus contact (`email`, `website`) and JSON (`scope_activities`, `metadata`).
- **Soft deletes:** Used on `companies`, `company_relationships`, and `company_external_accesses`.
- **Relationship types:** Seeded from Company module config `company.relationship_types`; model table is `company_relationship_types`.
- **User link:** Users have `company_id` (User module migration); Company module does not own the `users` table.

---

## Vendor Extension Layer

### How Vendors Extend the Foundation

Even with the rich foundation, vendors can add specialized functionality:

#### Trait-Based Extensions

Vendors provide traits that the Company model can optionally use:

- **Advanced Hierarchy**: Multi-level approval chains, delegation rules
- **Company Branding**: Logos, themes, custom domains, white-labeling
- **Financial Integration**: Chart of accounts, cost centers, profit centers

#### Model Relationships

Vendors add new database tables and relationships:

- **Billing/Subscription**: Per-company billing, usage tracking, invoicing
- **Workflow Configuration**: Company-specific workflow rules and routes
- **Extended Audit Logs**: Detailed change tracking per company
- **Document Management**: Company-specific document storage and templates

#### Service Integration

Vendors provide services that integrate with the foundation:

- **External Identity Providers**: LDAP, SAML, OAuth per company
- **Company Analytics**: Business intelligence, reporting dashboards
- **Communication Services**: Company-branded emails, notifications

---

## Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| **Hierarchy in Foundation** | Every ERP needs company grouping; too fundamental to be optional |
| **Multi-Relationship Support** | Real-world requirement: same company can be customer AND supplier |
| **Temporal Validity** | Historical tracking required for compliance and auditing |
| **Configurable Relationship Types** | Different deployments have different relationship taxonomies |
| **External Access as Foundation** | B2B portals are core ERP functionality, not an add-on |
| **Company as Core Module** | Every deployment has at least one company; always required |

---

## Real-World Scenarios

### SBG Group Structure

International conglomerate with multiple subsidiaries:

- **SBG Holdings** (parent company)
  - SBG Indonesia (subsidiary, internal relationship)
  - SBG Malaysia (subsidiary, internal relationship)
  - SBG Singapore (subsidiary, internal relationship)
- **External Relationships**:
  - Global suppliers (raw materials)
  - Regional distributors (customers)
  - Logistics partners (agencies)

### Manufacturing Company

Single company with extensive external relationships:

- **One Company**: Acme Manufacturing Ltd.
- **Supplier Relationships**: Raw material vendors, component suppliers
- **Customer Relationships**: Distributors, retailers, direct customers
- **Agency Relationships**: Customs brokers, freight forwarders

### Professional Services Firm

Project-based organization:

- **Holding Company**: Consulting Group International
- **Regional Offices**: As subsidiaries with local registration
- **Client Companies**: Customer relationships with project metadata
- **Partner Firms**: Partner relationships for joint ventures

---

## Relationship with Other Modules

### User Module

- Users belong to one primary company (`company_id`)
- Multi-company users can access multiple companies
- External users access via portal based on relationship permissions
- User permissions can be scoped to company level

### Employee Module

- Employees belong to companies (via `company_id` in Employee module)
- Employee module manages people records independently from Company module
- Company module focuses on organizational structure; Employee module focuses on people records

### Scope System

- Company implements the "Company" scope level
- Configuration inherits: Global → Company → Department → User
- Company-specific settings override global defaults

### Workflow Module

- Company context affects workflow routing
- Different companies can have different workflow configurations
- Cross-company workflows supported for group operations

### AI Services

- Business context informs AI inference
- Registration details provide compliance context
- Relationship types help AI understand business interactions
- Tenant fallback uses the explicitly assigned primary company, never an oldest-company inference

### Tenancy and framework provisioning

- Base/Tenancy owns tenant identity and cannot depend on Core/Company, so the primary
  relationship and `PrimaryCompanyManager` live here.
- Base owns the narrow `FrameworkPrimitivesProvisioner` contract; Core/Company
  implements transactional, sequence-safe, idempotent creation of the platform
  operator tenant, primary company, relationship, initial admin, and Lara.
- IDs have no runtime role semantics. Legacy tenant/company ID 1 values are retained
  only as deterministic migration input for old installations.

---

## Extension Mechanism Flow

### How It All Works Together

1. **Foundation Provides Base**: Company + hierarchy + relationships work standalone
2. **Vendors Add Features**: Branding, billing, advanced permissions, etc.
3. **Auto-Discovery**: System automatically detects and enables vendor features
4. **Graceful Degradation**: If vendor packages are missing, foundation still works
5. **Layered Override**: Vendors can override foundation behavior when needed

### Example Company Journey

#### Basic Foundation Company

- Company created with name, registration details
- Basic hierarchy (if applicable)
- Standard relationship types available
- External company relationships configured

#### With Branding Vendor Package

- Same foundation experience
- **Plus** custom logo and theme per company
- **Plus** branded login screens
- **Plus** custom email templates

#### With Billing Vendor Package

- Same foundation + branding experience
- **Plus** subscription management per company
- **Plus** usage tracking and invoicing
- **Plus** payment integration

---

## Benefits of This Architecture

### For Foundation Development

- **Focus**: Core team focuses on essential company functionality
- **Stability**: Foundation rarely changes, providing stable base
- **Testing**: Easier to test and maintain smaller codebase
- **Performance**: Optimized for common company operations

### For Vendor Development

- **Flexibility**: Can add any company-related functionality
- **Independence**: Develop and test features independently
- **Reusability**: Same vendor package works across BLB installations
- **Market Opportunity**: Can sell specialized company management features

### For End Users/Clients

- **Completeness**: All essential company features available out-of-box
- **Customization**: Add extensions for specific industry needs
- **Scalability**: Start with single company, grow to group structure
- **Future-Proof**: Add capabilities without system rewrites

---

## Future Extensibility

The architecture supports unlimited growth:

- **Advanced Compliance**: GDPR data residency, industry-specific regulations
- **AI-Powered Insights**: Company health scores, relationship recommendations
- **Blockchain Integration**: Immutable relationship and transaction records
- **Cross-Instance Federation**: Company relationships across BLB deployments
- **Advanced Analytics**: Business intelligence, predictive modeling

The foundation remains stable while innovation happens in the vendor layer, providing reliability and unlimited extensibility.
