# User Module

The User Module is a **foundational Core module** for the Belimbing application platform. It manages system authentication, access control, and user accounts.

## Overview

The User module manages:

- **Authentication**: System login and password management
- **Access Control**: Permissions and authorization
- **Company Context**: Primary company affiliation for users
- **External Access**: Portal access for customers and suppliers
- **User Sessions**: Remember tokens and email verification

## Models

### User

The main user model representing system authentication accounts.

**Key Features:**
- Extends Laravel's `Authenticatable` base class
- Company affiliation (nullable `company_id`)
- Email-based authentication
- Password hashing
- Remember token support
- Email verification tracking
- Notification support (via Notifiable trait)

**Example:**

```php
use App\Modules\Core\User\Models\User;

// Create internal user (employee)
$user = User::create([
    'company_id' => $company->id,
    'name' => 'John Doe',
    'email' => 'john.doe@company.com',
    'password' => Hash::make('password'),
]);

// Create external user (customer/supplier)
$externalUser = User::create([
    'company_id' => $customerCompany->id,
    'name' => 'Alice Johnson',
    'email' => 'alice@customer.com',
    'password' => Hash::make('password'),
]);

// Access relationships
$company = $user->company;
$externalAccesses = $user->externalAccesses;
$validAccesses = $user->validExternalAccesses;

// Get user initials
$initials = $user->initials();  // "JD" for "John Doe"

// Authenticate
if (Auth::attempt(['email' => $email, 'password' => $password])) {
    // Login successful
}
```

## Database Schema

### users

| Column | Type | Description |
|--------|------|-------------|
| id | integer | Primary key (auto-increment) |
| company_id | unsignedBigInteger | Primary company affiliation (nullable) |
| name | string | Display name |
| email | string | Unique email for authentication |
| email_verified_at | timestamp | Email verification timestamp (nullable) |
| password | string | Hashed password |
| remember_token | string | Remember me token (nullable) |
| created_at | timestamp | Record creation |
| updated_at | timestamp | Last update |

**Indexes:**
- `company_id` (foreign key to companies)
- `email` (unique index for authentication)

**Foreign Keys:**
- `company_id` → `companies.id` (null on delete)

### password_reset_tokens

Laravel default password reset tokens table.

## Relationships

### Company

**User belongs to Company (nullable):**

```php
$user->company;  // BelongsTo (nullable)
$company->users;  // HasMany (not explicitly defined, but implied)
```

**Why nullable?**
- System admin users may not belong to any company
- Allows user accounts to exist before company assignment
- Future: Multi-company users with switchable context

### ExternalAccess

**User has many external accesses:**

```php
$user->externalAccesses;  // HasMany
$user->validExternalAccesses;  // HasMany (filtered for valid accesses)
```

External accesses grant portal permissions to customer/supplier users.

### Employee (Future)

Not yet implemented, but implied:

```php
// Future: User can have multiple employee records (multi-company)
$user->employees;  // HasMany
$user->primaryEmployee();  // HasOne (based on user.company_id)
```

See [User-Employee-Company Relationship](../../../docs/architecture/user-employee-company.md) for detailed model.

## Methods

### initials()

Get the user's initials from their name:

```php
$user->name = 'John Doe';
$user->initials();  // "JD"

$user->name = 'Alice Marie Johnson';
$user->initials();  // "AJ" (first two words only)
```

## User Types

### 1. Internal Employees with System Access

Users who are employees of the company and need system access:

```php
// User account for authentication
$user = User::create([
    'company_id' => $company->id,
    'name' => 'John Doe',
    'email' => 'john.doe@company.com',
    'password' => Hash::make('password'),
]);

// Linked Employee record (see Employee module)
$employee = Employee::create([
    'company_id' => $company->id,
    'user_id' => $user->id,  // Link to user
    'employee_number' => 'EMP-001',
    'full_name' => 'John Richard Doe',
]);
```

**Characteristics:**
- `user.company_id` matches `employee.company_id`
- Can log in to system
- Has employee record with HR data

### 2. External Users (Customer/Supplier Portal)

Users from external companies who access the portal:

```php
// User account for portal access
$externalUser = User::create([
    'company_id' => $customerCompany->id,  // Their own company
    'name' => 'Alice Johnson',
    'email' => 'alice@customer.com',
    'password' => Hash::make('password'),
]);

// Grant external access via Company module
$access = ExternalAccess::create([
    'company_id' => $myCompany->id,  // Granting company
    'user_id' => $externalUser->id,
    'permissions' => ['view_orders', 'view_invoices'],
]);
```

**Characteristics:**
- `user.company_id` points to their own company (customer/supplier)
- No `Employee` record in our company
- Access granted via `ExternalAccess` model (Company module)

### 3. System Admins (Future)

Users who manage the system but don't belong to any company:

```php
$admin = User::create([
    'company_id' => null,  // No company affiliation
    'name' => 'System Admin',
    'email' => 'admin@system.com',
    'password' => Hash::make('password'),
]);
```

**Characteristics:**
- `user.company_id` is `null`
- System-wide permissions
- Can access all companies (future)

## Common Use Cases

### User Registration

```php
use Illuminate\Support\Facades\Hash;
use App\Modules\Core\User\Models\User;

$user = User::create([
    'company_id' => $company->id,
    'name' => $request->input('name'),
    'email' => $request->input('email'),
    'password' => Hash::make($request->input('password')),
]);

// Send email verification
$user->sendEmailVerificationNotification();
```

### Authentication

```php
use Illuminate\Support\Facades\Auth;

// Attempt login
if (Auth::attempt(['email' => $email, 'password' => $password], $remember)) {
    $user = Auth::user();
    
    // Redirect based on user type
    if ($user->company_id) {
        return redirect()->route('dashboard');
    }
} else {
    return back()->withErrors(['email' => 'Invalid credentials']);
}
```

### Password Reset

```php
use Illuminate\Support\Facades\Password;

// Request password reset
$status = Password::sendResetLink(
    ['email' => $request->input('email')]
);

// Reset password
Password::reset(
    $request->only('email', 'password', 'password_confirmation', 'token'),
    function ($user, $password) {
        $user->password = Hash::make($password);
        $user->save();
    }
);
```

### Checking External Access

```php
// Check if user has valid external access to a company
$hasAccess = $user->validExternalAccesses()
    ->where('company_id', $myCompany->id)
    ->exists();

if ($hasAccess) {
    // Grant portal access
}
```

## Factories

User model includes a factory for testing:

```php
// Create user
$user = User::factory()->create();

// Create user for specific company
$user = User::factory()->for($company)->create();

// Create user with specific email
$user = User::factory()->create([
    'email' => 'test@example.com'
]);

// Create unverified user
$user = User::factory()->unverified()->create();
```

## Architecture Notes

### User vs Employee

**User** is for system authentication and access control.
**Employee** is for HR data and employment relationships.

They are separate because:
1. Not all employees need system access (hourly workers, field staff)
2. External users (customers/suppliers) are Users but not Employees
3. A user might have multiple employee records (future: multi-company contractors)

See [User-Employee-Company Relationship](../../../docs/architecture/user-employee-company.md) for full design rationale.

### Why company_id on User?

**Purpose:** Establish a "primary" company affiliation for default context.

**Benefits:**
- On login, system knows which company's data to display by default
- Simplifies UI/UX for single-company users
- Enables external users to have their own company context

**Trade-offs:**
- Can create redundancy with Employee.company_id for internal employees
- Requires careful handling for multi-company scenarios

### Module Dependencies

User module depends on:
- **Company** module (optional) - Users may belong to companies
- **Laravel Authentication** - Extends base Authenticatable class

User module is used by:
- **Employee** module - Employees link to User accounts via `user_id`
- **Company** module - ExternalAccess links to User accounts

## Security Considerations

### Password Storage

- Passwords are hashed using Laravel's `Hash` facade (bcrypt by default)
- Never store or log plaintext passwords
- Use `password` cast for automatic hashing (Laravel 9+)

### Email Uniqueness

- Email is globally unique (system-wide constraint)
- Prevents duplicate accounts
- Use email verification for additional security

### Remember Token

- `remember_token` is automatically managed by Laravel
- Used for "remember me" functionality
- Regenerated on logout for security

## Future Enhancements

1. **Two-Factor Authentication (2FA)**: Add 2FA support for enhanced security
2. **OAuth Integration**: Social login (Google, Microsoft, etc.)
3. **API Tokens**: Personal access tokens for API authentication
4. **Session Management**: View and revoke active sessions
5. **Multi-Company Context**: Switch between companies for multi-company users
6. **Role-Based Access Control (RBAC)**: Fine-grained permissions system
7. **Audit Log**: Track user actions and changes

## Testing

Run tests with:

```php
php artisan test --filter=User
```

Test coverage includes:
- User creation and authentication
- Password hashing and verification
- Company relationships
- External access queries
- Email uniqueness validation
