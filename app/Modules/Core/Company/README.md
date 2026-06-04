# Company Module

The Company Module is a **foundational Core module** for the Belimbing ERP system. Every deployment requires at least one company, making this module essential infrastructure rather than optional functionality.

## Overview

The Company module manages:

- **Company Hierarchy**: Parent-child relationships for company groups and subsidiaries
- **Company Relationships**: External relationships (customers, suppliers, partners, agencies)
- **External Access Control**: Portal access for external parties
- **Business Context**: Registration details and metadata for compliance and AI inference

## Models

### Company

The main company model representing business entities.

**Key Features:**
- Hierarchical structure (parent-child relationships)
- Multiple status states (active, suspended, pending, archived)
- Rich registration and contact information
- Business context metadata for AI services
- Soft deletes for data retention

**Example:**

```php
use App\Modules\Core\Company\Models\Company;

// Create a company
$company = Company::create([
    'name' => 'SBG Holdings',
    'legal_name' => 'SBG Holdings Pte Ltd',
    'registration_number' => '123456789',
    'tax_id' => 'SG-TAX-123456',
    'status' => 'active',
    'email' => 'contact@sbgholdings.com',
    'phone' => '+65 1234 5678',
    'country' => 'Singapore',
]);

// Create a subsidiary
$subsidiary = Company::create([
    'name' => 'SBG Indonesia',
    'parent_id' => $company->id,
    'status' => 'active',
]);

// Access hierarchy
$parent = $subsidiary->parent;
$children = $company->children;
$allAncestors = $subsidiary->ancestors();
$rootCompany = $subsidiary->root();

// Check status
if ($company->isActive()) {
    // Company is active
}

// Change status
$company->suspend();
$company->activate();
$company->archive();
```

### RelationshipType

Defines types of relationships between companies.

Relationship types are **configurable** per deployment. Each deployment can customize the relationship types to match their business model by modifying the Company module config (Config/company.php; config key `company`).

**Default Types (configurable):**
- `internal`: Internal company relationships
- `customer`: Customer relationships
- `supplier`: Supplier relationships
- `partner`: Business partner relationships
- `agency`: Agency relationships (customs brokers, freight forwarders)

To customize relationship types, edit the `relationship_types` array in the Company module config (Config/company.php). You can add, modify, or remove types as needed for your business model. For example, you might combine `supplier` and `agency` into a single `vendor` type, or add industry-specific types like `distributor`, `contractor`, etc.

**Extension Configuration:** Custom extensions can add or override relationship types by merging their own configuration in a Service Provider. See `docs/guides/extensions/config-overrides.md` for details on how extensions can modify configuration.

**Example:**

```php
use App\Modules\Core\Company\Models\RelationshipType;

// Get a relationship type
$customerType = RelationshipType::findByCode('customer');

// Check properties
if ($customerType->allowsExternalAccess()) {
    // This type allows external portal access
}

// Query active external types
$externalTypes = RelationshipType::active()
    ->external()
    ->get();
```

### CompanyRelationship

Manages relationships between companies with temporal validity.

**Key Features:**
- Temporal validity (effective_from, effective_to dates)
- Multiple relationship types between same companies
- Active/ended/pending states
- Rich metadata storage

**Example:**

```php
use App\Modules\Core\Company\Models\CompanyRelationship;

// Create a customer relationship
$relationship = CompanyRelationship::create([
    'company_id' => $myCompany->id,
    'related_company_id' => $customerCompany->id,
    'relationship_type_id' => $customerType->id,
    'effective_from' => now(),
    'effective_to' => null, // Indefinite
    'metadata' => [
        'contract_number' => 'CON-2026-001',
        'credit_limit' => 100000,
    ],
]);

// Check status
if ($relationship->isActive()) {
    // Relationship is currently active
}

// Manage relationship
$relationship->end(); // End today
$relationship->extendTo('2027-12-31'); // Extend to specific date
$relationship->makeIndefinite(); // Remove end date

// Query relationships
$activeCustomers = CompanyRelationship::active()
    ->ofType('customer')
    ->get();
```

### ExternalAccess

Controls portal access for external parties.

**Key Features:**
- Fine-grained permissions
- Access validity periods
- Grant/revoke controls
- Permission management

**Example:**

```php
use App\Modules\Core\Company\Models\ExternalAccess;

// Grant external access
$access = ExternalAccess::create([
    'company_id' => $myCompany->id,
    'relationship_id' => $relationship->id,
    'user_id' => $externalUser->id,
    'permissions' => ['view_orders', 'view_invoices'],
    'is_active' => true,
    'access_granted_at' => now(),
    'access_expires_at' => now()->addYear(),
]);

// Check validity
if ($access->isValid()) {
    // Access is currently valid
}

// Manage permissions
$access->grantPermission('submit_payments');
$access->revokePermission('view_invoices');

if ($access->hasPermission('view_orders')) {
    // User has this permission
}

// Manage access
$access->revoke();
$access->grant();
$access->extendTo('2027-12-31');
$access->makeIndefinite();
```

## Database Schema

### companies

| Column | Type | Description |
|--------|------|-------------|
| id | integer | Primary key (auto-increment) |
| parent_id | unsignedBigInteger | Parent company (nullable) |
| name | string | Company name |
| code | string | Short unique company code |
| status | string | active, suspended, pending, archived |
| legal_name | string | Full legal name |
| registration_number | string | Business registration number |
| tax_id | string | Tax identification number |
| legal_entity_type | string | LLC, Corporation, etc. |
| jurisdiction | string | Registration jurisdiction |
| email | string | Contact email |
| website | string | Company website |
| scope_activities | json | Industry, services, business focus |
| metadata | json | Additional metadata |

Addresses and phone are provided by the Address module via the `addressables` pivot (morphToMany). Address uses `postcode` (aligned with Geonames).

### relationship_types

| Column | Type | Description |
|--------|------|-------------|
| id | integer | Primary key (auto-increment) |
| code | string | Unique code (customer, supplier, etc.) |
| name | string | Display name |
| description | text | Description |
| is_external | boolean | Allows external access |
| is_active | boolean | Active status |
| metadata | json | Configuration |

### company_relationships

| Column | Type | Description |
|--------|------|-------------|
| id | integer | Primary key (auto-increment) |
| company_id | unsignedBigInteger | Primary company |
| related_company_id | unsignedBigInteger | Related company |
| relationship_type_id | unsignedBigInteger | Type of relationship |
| effective_from | date | Start date (nullable) |
| effective_to | date | End date (nullable) |
| metadata | json | Additional data |

### external_accesses

| Column | Type | Description |
|--------|------|-------------|
| id | integer | Primary key (auto-increment) |
| company_id | unsignedBigInteger | Company granting access |
| relationship_id | unsignedBigInteger | Associated relationship |
| user_id | unsignedBigInteger | User with access |
| permissions | json | Granted permissions |
| is_active | boolean | Access active status |
| access_granted_at | timestamp | When access granted |
| access_expires_at | timestamp | Expiration (nullable) |
| metadata | json | Additional data |

## Seeders

### RelationshipTypeSeeder

Seeds relationship types from configuration. The seeder reads from config key `company.relationship_types` (Company module Config/company.php) to create default relationship types. Run during installation:

```bash
php artisan db:seed --class="App\Modules\Core\Company\Seeders\RelationshipTypeSeeder"
```

**Note:** Relationship types are defined in the Company module Config/company.php. You can customize the types before running the seeder, or add/remove types later through the database or by updating the config and re-running the seeder.

## Factories

All models include factories for testing:

```php
// Create companies
$company = Company::factory()->create();
$parent = Company::factory()->parent()->create();
$subsidiary = Company::factory()->subsidiary($parent->id)->create();

// Create relationship types
$type = RelationshipType::factory()->customer()->create();
$type = RelationshipType::factory()->supplier()->create();
$type = RelationshipType::factory()->partner()->create();
```

## Common Use Cases

### Multi-Company Group Structure

```php
// Create parent company
$holdings = Company::create([
    'name' => 'SBG Holdings',
    'status' => 'active',
]);

// Create subsidiaries
$indonesia = Company::create([
    'name' => 'SBG Indonesia',
    'parent_id' => $holdings->id,
]);

$malaysia = Company::create([
    'name' => 'SBG Malaysia',
    'parent_id' => $holdings->id,
]);

// Get all subsidiaries
$subsidiaries = $holdings->children;

// Get root company from any level
$root = $malaysia->root(); // Returns $holdings
```

### Managing Customer Relationships

```php
$customerType = RelationshipType::findByCode('customer');

// Add a customer
$relationship = CompanyRelationship::create([
    'company_id' => $myCompany->id,
    'related_company_id' => $customerCompany->id,
    'relationship_type_id' => $customerType->id,
    'effective_from' => now(),
]);

// Get all active customers
$activeCustomers = $myCompany->activeRelationships()
    ->whereHas('type', fn($q) => $q->where('code', 'customer'))
    ->get();
```

### Granting External Portal Access

```php
// Find the customer relationship
$relationship = CompanyRelationship::where('company_id', $myCompany->id)
    ->where('related_company_id', $customerCompany->id)
    ->ofType('customer')
    ->first();

// Create external user
$externalUser = User::create([
    'name' => 'Customer Portal User',
    'email' => 'user@customer.com',
    'company_id' => $customerCompany->id,
]);

// Grant access
$access = ExternalAccess::create([
    'company_id' => $myCompany->id,
    'relationship_id' => $relationship->id,
    'user_id' => $externalUser->id,
    'permissions' => ['view_orders', 'view_invoices', 'view_statements'],
    'is_active' => true,
]);
```

### Dual Relationships (Customer AND Supplier)

```php
$customerType = RelationshipType::findByCode('customer');
$supplierType = RelationshipType::findByCode('supplier');

// Company is our customer
CompanyRelationship::create([
    'company_id' => $myCompany->id,
    'related_company_id' => $otherCompany->id,
    'relationship_type_id' => $customerType->id,
]);

// Same company is also our supplier
CompanyRelationship::create([
    'company_id' => $myCompany->id,
    'related_company_id' => $otherCompany->id,
    'relationship_type_id' => $supplierType->id,
]);
```

## Architecture Notes

### Foundation vs Vendor Layer

The Company module is **foundation layer** - it provides essential functionality that every deployment needs. Vendors can extend it with:

- **Branding**: Company logos, themes, custom domains
- **Advanced Features**: Multi-level approval, delegation rules
- **Integration**: SSO, LDAP, external identity providers
- **Analytics**: Business intelligence, reporting

The foundation remains stable while vendors innovate in their layer.

### Scope System Integration

Company implements the "Company" scope level in the configuration hierarchy:

```
Global (default)
└── Company
    └── Department
        └── User
```

Configuration settings inherit down the hierarchy, with company-specific settings overriding global defaults.

## Testing

Run tests with:

```bash
php artisan test --filter=Company
```

Test coverage includes:
- Company hierarchy and relationships
- Status transitions
- Relationship temporal validity
- External access permissions
- Scopes and queries
