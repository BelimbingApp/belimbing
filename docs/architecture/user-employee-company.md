# User-Employee-Company Relationship

**Document Type:** Architecture Specification
**Purpose:** Clarify the relationship model between User, Employee, and Company entities
**Last Updated:** 2026-08-05

---

## Overview

BLB uses a three-entity model to handle authentication, employment relationships, and organizational structure:

- **User** - System authentication and access control
- **Employee** - Employment relationship within a company
- **Company** - Organizational entity

---

## Entity Relationships

```
Company
├── users (1:Many) - Users who belong to this company
└── employees (1:Many) - Employment records for this company

User
├── company (Many:1) - Primary company affiliation
└── employees (1:Many) - Employment records across companies (future: multi-company users)

Employee
├── company (Many:1) - Company where employed
└── user (Many:1, nullable) - Associated system user account
```

---

## Domain Model

### User

**Purpose:** System-level authentication and access control

**Key Attributes:**
- `company_id` (nullable) - Primary company affiliation
- `name` - Display name
- `email` - Unique system-wide email for authentication
- `password` - Hashed password

**Responsibilities:**
- System authentication (login)
- Access control and permissions
- External portal access for customers/suppliers
- Admin panel access

**Relationship to Company:**
- `belongsTo(Company)` - Primary company affiliation
- Used for default context (which company data to show on login)

**Relationship to Employee:**
- `hasMany(Employee)` (not currently implemented but implied)
- A user can have multiple employment records (e.g., contractor working for multiple companies)
- Currently assumed 1:1 for internal employees

### Employee

**Purpose:** Employment relationship and HR data

**Key Attributes:**
- `company_id` (required) - Company where employed
- `user_id` (nullable) - Optional link to system user account
- `employee_number` - Unique per company
- `full_name` - Official/legal name (for HR records)
- `short_name` - Preferred/display name
- `email` - Work email (may differ from user email)
- `mobile_number` - Contact number
- `status` - Employment status (active, inactive, terminated, pending)
- `employment_start`, `employment_end` - Employment period

**Responsibilities:**
- HR records (employment dates, employee number, status)
- Organizational structure (department assignments - future)
- Payroll and benefits data (future)
- Performance and training records (future)

**Relationship to Company:**
- `belongsTo(Company)` - Company where employed

**Relationship to User:**
- `belongsTo(User)` - Optional link to system user account
- `nullable` because:
  - Not all employees need system access (e.g., hourly agents, field staff)
  - External contractors may have employee records but no user account
  - Historical employment records may outlive user accounts

### Company

**Purpose:** Organizational entity

**Key Attributes:**
- `parent_id` - Hierarchical company structure
- `name`, `legal_name`, `registration_number`, `tax_id`
- `status` - Company status (active, suspended, pending, archived)

**Responsibilities:**
- Organizational hierarchy (parent-child relationships)
- Legal entity information
- Business relationships (customers, suppliers, partners)
- External access management (portal for customers/suppliers)

**Relationship to User:**
- `hasMany(User)` - Users affiliated with this company
- Includes both internal employees and external users (customer/supplier portal users)

**Relationship to Employee:**
- `hasMany(Employee)` - Employment records for this company

---

## Use Cases

### 1. Internal Employee with System Access

**Scenario:** A regular employee who needs to log in to the system.

```php
// User account for authentication
$user = User::create([
    'company_id' => $company->id,  // Primary company
    'name' => 'John Doe',
    'email' => 'john.doe@company.com',
    'password' => Hash::make('password'),
]);

// Employee record for HR data
$employee = Employee::create([
    'company_id' => $company->id,
    'user_id' => $user->id,  // Link to user account
    'employee_number' => 'EMP-001',
    'full_name' => 'John Richard Doe',
    'short_name' => 'John',
    'email' => 'john.doe@company.com',  // Usually same as user email
    'status' => 'active',
    'employment_start' => '2026-01-15',
]);
```

**Key Points:**
- `user.company_id` and `employee.company_id` are the same
- `user.email` and `employee.email` are typically the same
- `employee.user_id` links the HR record to the system account

### 2. Internal Employee WITHOUT System Access

**Scenario:** Hourly agent, field staff, or factory agent who doesn't need system access.

```php
// Employee record only (no user account)
$employee = Employee::create([
    'company_id' => $company->id,
    'user_id' => null,  // No system access
    'employee_number' => 'EMP-002',
    'full_name' => 'Jane Smith',
    'email' => 'jane.smith@company.com',  // For HR communication
    'status' => 'active',
    'employment_start' => '2026-02-01',
]);
```

**Key Points:**
- `user_id` is `null` - no system login capability
- Employee still has contact information for HR purposes

### 3. External User (Customer/Supplier Portal)

**Scenario:** Customer company needs portal access to view orders and invoices.

```php
// User account for portal access
$externalUser = User::create([
    'company_id' => $customerCompany->id,  // Customer's company
    'name' => 'Alice Johnson',
    'email' => 'alice@customer.com',
    'password' => Hash::make('password'),
]);

// Grant external access via CompanyRelationship and ExternalAccess
$access = ExternalAccess::create([
    'company_id' => $myCompany->id,  // Granting company
    'relationship_id' => $relationship->id,  // Customer relationship
    'user_id' => $externalUser->id,
    'permissions' => ['view_orders', 'view_invoices'],
    'is_active' => true,
]);
```

**Key Points:**
- User's `company_id` points to their own company (the customer)
- No `Employee` record in our company
- External access granted via `ExternalAccess` model

### 4. Multi-Company Contractor (Future Enhancement)

**Scenario:** Contractor works for multiple companies within the same BLB group.

```php
// One user account
$user = User::create([
    'company_id' => $primaryCompany->id,  // Primary affiliation
    'name' => 'Bob Contractor',
    'email' => 'bob@contractor.com',
    'password' => Hash::make('password'),
]);

// Multiple employee records
$employee1 = Employee::create([
    'company_id' => $companyA->id,
    'user_id' => $user->id,
    'employee_number' => 'CON-001',
    'full_name' => 'Bob Contractor',
    'status' => 'active',
]);

$employee2 = Employee::create([
    'company_id' => $companyB->id,
    'user_id' => $user->id,
    'employee_number' => 'CON-002',
    'full_name' => 'Bob Contractor',
    'status' => 'active',
]);
```

**Key Points:**
- One `User` record, multiple `Employee` records
- User's `company_id` is the "primary" company for default context
- System can switch company context based on active employee record

---

## Data Integrity Rules

### Foreign Key Constraints

```php
// Company → User (nullable reference)
User.company_id → Company.id (nullOnDelete)
// If company deleted, set user.company_id to null

// Company → Employee (cascade delete)
Employee.company_id → Company.id (cascadeOnDelete)
// If company deleted, delete all employee records

// User → Employee (nullable reference)
Employee.user_id → User.id (nullOnDelete)
// If user deleted, set employee.user_id to null (preserve HR record)
```

### Validation Rules

1. **User.email** must be globally unique (system-wide)
2. **Employee.employee_number** must be unique per company (`unique(['company_id', 'employee_number'])`)
3. **Employee.email** can be duplicate across companies (work emails may overlap)
4. If `Employee.user_id` is set, `User.company_id` should typically match `Employee.company_id` (for internal employees)

---

## Design Rationale

### Why User has company_id?

**Purpose:** Establish a "primary" company affiliation for default context.

**Benefits:**
- On login, system knows which company's data to display by default
- Simplifies UI/UX for single-company users (no company selector needed)
- Enables external users (customers/suppliers) to have their own company context

**Trade-offs:**
- Can create redundancy with Employee.company_id for internal employees
- Requires careful handling for multi-company scenarios

### Why Employee has nullable user_id?

**Purpose:** Allow HR records to exist independently of system access.

**Benefits:**
- Not all employees need system access (hourly agents, field staff)
- Historical employment records can outlive user accounts
- External contractors can have employee records without full system access
- Supports data retention policies (delete user, keep HR record)

**Trade-offs:**
- Two entities to manage for employees with system access
- Potential for data inconsistency if not carefully managed

### Licensee Company (id=1)

**Purpose:** Identify the company that is the licensee operating this Belimbing instance.

**Convention:** The licensee company is always `id=1`, created during installation via `scripts/setup-steps/60-migrations.sh`.

**Key Points:**
- Created before the initial admin user during setup
- Admin user is automatically assigned `company_id=1`
- Cannot be deleted (enforced at both model and UI level)
- `Company::LICENSEE_ID` constant and `$company->isLicensee()` method provide programmatic access
- All other companies (customers, suppliers, partners) exist in relation to the licensee

**Why id=1 Convention:**
- Simple and deterministic — no config files or flags needed
- Always available after installation — no risk of misconfiguration
- No database migration needed — works with existing schema
- Clear and obvious — first company created is the operator

---

## Future Enhancements

### 1. Employee.hasOne(User) Relationship

Add explicit relationship to User model:

```php
// Employee.php
public function user(): BelongsTo
{
    return $this->belongsTo(User::class, 'user_id');
}

// User.php  
public function employees(): HasMany
{
    return $this->hasMany(Employee::class, 'user_id');
}

public function primaryEmployee(): HasOne
{
    return $this->hasOne(Employee::class, 'user_id')
        ->where('company_id', $this->company_id)
        ->where('status', 'active')
        ->latest('employment_start');
}
```

### 2. Company Context Switching

For users with multiple employee records, allow switching active company context:

```php
// Session-based company switching
$user->switchCompany($companyId);

// Get current employee context
$currentEmployee = $user->currentEmployee();
```

### 3. Validation Rules

Add database-level checks:

```php
// Validation: If employee has user_id, warn if companies don't match
if ($employee->user_id && $employee->company_id !== $employee->user->company_id) {
    Log::warning("Employee company mismatch", [
        'employee_id' => $employee->id,
        'employee_company' => $employee->company_id,
        'user_company' => $employee->user->company_id,
    ]);
}
```

---

## Summary

**User-Employee-Company Model:**
- **User** - System authentication (1 account, multiple companies possible)
- **Employee** - HR record (1 per company, optional user link)
- **Company** - Organizational entity (has users and employees)

**Key Design Principles:**
1. User for authentication, Employee for HR data - separate concerns
2. Nullable relationships allow flexibility (not all employees need access)
3. company_id on both User and Employee supports different use cases
4. Foreign key constraints preserve data integrity

**Typical Pattern:**
- Internal employee: User + Employee (linked via user_id)
- Employee without access: Employee only (user_id null)
- External portal user: User only (no Employee record)

---

**Related Documents:**
- `app/Core/User/README.md` - User Module documentation
- `app/Core/Employee/README.md` - Employee Module documentation
- `app/Core/Company/README.md` - Company Module documentation
