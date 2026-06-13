# Employee Module

The Employee Module is a **foundational Core module** for the Belimbing application platform. It manages employment relationships and HR data within companies.

## Overview

The Employee module manages:

- **Employment Records**: Official employment relationships between individuals and companies
- **Organizational Structure**: Department placement and reporting hierarchy
- **HR Data**: Employee numbers, job titles, contact information, employment periods
- **System Access Linkage**: Optional User account link via `users.employee_id`

## Models

### Employee

The main employee model representing employment relationships.

**Key Features:**
- Company-scoped employment records
- Department placement and reporting structure
- Optional linked User account via `users.employee_id`
- Employee type classification (full-time, part-time, contractor, intern)
- Employment status tracking (pending, probation, active, inactive, terminated)
- Flexible metadata for additional HR data

**Example:**

```php
use App\Modules\Core\Employee\Models\Employee;

// Create an employee with system access
$employee = Employee::create([
    'company_id' => $company->id,
    'department_id' => $department->id,
    'supervisor_id' => $manager->id,  // Reports to manager
    'employee_number' => 'EMP-001',
    'full_name' => 'John Richard Doe',
    'short_name' => 'John',
    'designation' => 'Senior Software Engineer',
    'employee_type' => 'full_time',
    'email' => 'john.doe@company.com',
    'mobile_number' => '+65 9123 4567',
    'status' => 'active',
    'employment_start' => '2026-01-15',
]);

$user->update(['employee_id' => $employee->id]);  // Link user account

// Create an employee WITHOUT system access (e.g., hourly worker)
$employee = Employee::create([
    'company_id' => $company->id,
    'employee_number' => 'EMP-002',
    'full_name' => 'Jane Smith',
    'email' => 'jane.smith@company.com',
    'status' => 'active',
    'employment_start' => '2026-02-01',
]);

// Access relationships
$company = $employee->company;
$department = $employee->department;
$supervisor = $employee->supervisor;
$subordinates = $employee->subordinates;  // Employees who report to this person
$user = $employee->user;  // null if no system access

// Get display name
$displayName = $employee->displayName();  // Uses short_name if available, otherwise full_name

// Query active employees
$activeEmployees = Employee::query()->active()->get();
```

## Database Schema

### employees

| Column | Type | Description |
|--------|------|-------------|
| id | integer | Primary key (auto-increment) |
| company_id | unsignedBigInteger | Company where employed (required) |
| department_id | unsignedBigInteger | Department placement (nullable) |
| supervisor_id | unsignedBigInteger | Reports to (self-referential, nullable) |
| employee_number | string | Unique employee number per company |
| full_name | string | Official/legal name (Passport/ID) |
| short_name | string | Preferred/display name (nullable) |
| designation | string | Job title (nullable, free text) |
| employee_type | string | Employment type (default: 'full_time') |
| email | string | Work email (nullable, indexed) |
| mobile_number | string | Contact number (nullable) |
| status | string | Employment status (default: 'active') |
| employment_start | date | Employment start date (nullable) |
| employment_end | date | Employment end date (nullable) |
| metadata | json | Additional HR data (nullable) |
| created_at | timestamp | Record creation |
| updated_at | timestamp | Last update |

**Indexes:**
- `company_id` (foreign key to companies)
- `department_id` (foreign key to departments)
- `supervisor_id` (indexed, self-referential foreign key)
- `employee_number` (indexed)
- `employee_type` (indexed)
- `email` (indexed)
- `status` (indexed)
- Unique constraint: `['company_id', 'employee_number']`

**Foreign Keys:**
- `company_id` → `companies.id` (cascade on delete)
- `department_id` → `departments.id` (null on delete)
- `supervisor_id` → `employees.id` (null on delete, self-referential)

## Relationships

### Company

**Employee belongs to Company:**

```php
$employee->company;  // BelongsTo
$company->employees;  // HasMany (not explicitly defined yet, but implied)
```

### Department

**Employee belongs to Department (nullable):**

```php
$employee->department;  // BelongsTo (nullable)
$department->employees;  // HasMany (not explicitly defined in Department model)
```

**Why nullable?**
- Employees may not be assigned to a department immediately
- Some roles may be company-wide (e.g., CEO, contractors)

### User

**Employee optionally has one User:**

```php
$employee->user;  // HasOne through users.employee_id (nullable)
$user->employee;  // BelongsTo Employee (nullable)
```

**Why nullable?**
- Not all employees need system access (hourly workers, field staff)
- Historical employment records can outlive user accounts
- Supports data retention (delete user, keep HR record)

See [User-Employee-Company Relationship](../../../docs/architecture/user-employee-company.md) for detailed relationship model.

### Supervisor & Subordinates

**Self-referential relationships for reporting structure:**

```php
$employee->supervisor;     // BelongsTo Employee (nullable)
$employee->subordinates;   // HasMany Employee
```

**Usage:**
```php
// Get org chart upwards
$manager = $employee->supervisor;
$director = $manager->supervisor;

// Get team downwards
$team = $manager->subordinates;
foreach ($team as $member) {
    echo $member->full_name;
}

// Check if employee is a manager
if ($employee->subordinates()->exists()) {
    // Employee has direct reports
}
```

### Address

**Employee can have multiple addresses:**

```php
$employee->addresses;  // MorphToMany via addressables pivot
```

Addresses are managed by the Address module via polymorphic `addressables` pivot table.

## Scopes

### active()

Query only active employees:

```php
Employee::query()->active()->get();
```

## Common Use Cases

### Creating Employee with System Access

```php
// First create user account
$user = User::create([
    'company_id' => $company->id,
    'name' => 'John Doe',
    'email' => 'john.doe@company.com',
    'password' => Hash::make('password'),
]);

// Then create employee record
$employee = Employee::create([
    'company_id' => $company->id,
    'employee_number' => 'EMP-001',
    'full_name' => 'John Richard Doe',
    'short_name' => 'John',
    'email' => 'john.doe@company.com',
    'status' => 'active',
    'employment_start' => now(),
]);

$user->update(['employee_id' => $employee->id]);
```

### Creating Employee WITHOUT System Access

```php
// Employee only (no user account)
$employee = Employee::create([
    'company_id' => $company->id,
    'employee_number' => 'EMP-002',
    'full_name' => 'Jane Smith',
    'email' => 'jane.smith@company.com',  // For HR communication
    'mobile_number' => '+65 9876 5432',
    'status' => 'active',
    'employment_start' => now(),
]);
```

### Querying Employees

```php
// Get all active employees for a company
$activeEmployees = Employee::query()
    ->where('company_id', $company->id)
    ->active()
    ->get();

// Find employee by number
$employee = Employee::query()
    ->where('company_id', $company->id)
    ->where('employee_number', 'EMP-001')
    ->first();

// Get employees with system access
$usersWithAccess = Employee::query()
    ->whereHas('user')
    ->with('user')
    ->get();

// Get employees without system access
$noAccess = Employee::query()
    ->doesntHave('user')
    ->get();
```

### Terminating Employment

```php
$employee->update([
    'status' => 'terminated',
    'employment_end' => now(),
]);

// Or use soft delete to archive
$employee->delete();  // Soft delete (deleted_at set)

// Permanently delete (use with caution)
$employee->forceDelete();
```

## Factories

Employee model includes a factory for testing:

```php
// Create employee
$employee = Employee::factory()->create();

// Create employee for specific company
$employee = Employee::factory()->for($company)->create();

// Create employee with user account
$employee = Employee::factory()
    ->for($company)
    ->create();

$user->update(['employee_id' => $employee->id]);

// Create employee without user account
$employee = Employee::factory()
    ->for($company)
    ->create();
```

## Architecture Notes

### Employee vs User

**Employee** is for HR data and employment relationships.
**User** is for system authentication and access control.

They are separate entities because:
1. Not all employees need system access
2. HR records need to persist even if user access is revoked
3. A user might have multiple employee records (multi-company contractors)

See [User-Employee-Company Relationship](../../../docs/architecture/user-employee-company.md) for full rationale.

### Module Dependencies

Employee module depends on:
- **Company** module (required) - Every employee belongs to a company
- **User** module (optional) - Employees may be linked to user accounts
- **Address** module (optional) - For employee addresses

### Future Enhancements

1. **Department Assignment**: Link employees to departments
2. **Job Titles/Positions**: Track position history
3. **Reporting Structure**: Manager-subordinate relationships
4. **Payroll Integration**: Link to payroll records
5. **Benefits Enrollment**: Track benefits and entitlements
6. **Performance Reviews**: Track reviews and appraisals
7. **Training Records**: Track training and certifications

## Testing

Run tests with:

```bash
php artisan test --filter=Employee
```

Test coverage includes:
- Employee creation and updates
- Company and user relationships
- Active employee scopes
- Data integrity (unique employee numbers per company)
