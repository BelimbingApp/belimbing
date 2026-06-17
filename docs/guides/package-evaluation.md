# Package Evaluation Checklist

Before adding a Composer package that creates database tables, use this evaluation framework to decide whether to adopt the package or build the functionality ourselves.

## Evaluation Process

### 1. Table Name Conflict Check

**Before installing:**

```bash
# Check package's migrations for table names
composer show vendor/package-name --path
cat vendor/vendor/package-name/database/migrations/*.php | grep "Schema::create"
```

**Questions to answer:**
- [ ] What tables does the package create?
- [ ] Do any table names conflict with existing BLB tables?
- [ ] Are the table names generic enough to potentially conflict with future BLB modules?
- [ ] Does the package use unprefixed generic names (e.g., `countries`, `permissions`, `users`)?

**Conflict examples:**
- ❌ Package uses `countries` - conflicts with potential geography modules
- ❌ Package uses `permissions` - conflicts with our `base_permissions`
- ✅ Package uses `telescope_entries` - namespaced, unlikely to conflict
- ✅ Package uses `personal_access_tokens` - specific, unlikely to conflict

---

### 2. Build vs. Buy Decision

**Complexity Assessment:**
- [ ] How complex is the package functionality?
- [ ] Could we reasonably implement this ourselves in 1-2 weeks?
- [ ] Does the package have many edge cases we'd need to handle?

**Maintenance Assessment:**
- [ ] Can we maintain our own implementation long-term?
- [ ] Do we have the expertise to handle the domain?
- [ ] Will this functionality need frequent updates?

**Control Assessment:**
- [ ] Do we need full control over the data structure?
- [ ] Will we need to customize the functionality significantly?
- [ ] Does the package integrate well with BLB's architecture?

**Integration Assessment:**
- [ ] Does the package follow Laravel conventions we use?
- [ ] Does it work with our module structure?
- [ ] Can it be easily extended via our extension system?

---

### 3. Decision Matrix

| Factor | Adopt Package | Build Own |
|--------|--------------|-----------|
| **Complexity** | High complexity, many edge cases | Simple, well-defined scope |
| **Control** | Standard functionality, no customization | Need full control over structure |
| **Maintenance** | Actively maintained by community | We have domain expertise |
| **Integration** | Fits BLB architecture well | Needs deep BLB integration |
| **Table Names** | Namespaced, no conflicts | Generic names, conflicts likely |

---

### 4. Decision Options

**Option A: Adopt Package (Accept Table Names)**
- Accept package's table names as-is
- Do NOT rename package tables
- Document in `database/PACKAGES.md`
- Ensure BLB tables use prefixes to avoid conflicts

**Option B: Build Our Own Module**
- Create module in `app/Modules/Core/`, `app/Modules/Operation/`, or `app/Modules/Commerce/`
- Follow BLB table naming conventions
- Full control and customization
- Example: Geonames module (we built instead of using package)

**Option C: Fork and Modify (Last Resort)**
- Only if absolutely necessary
- Creates maintenance burden
- Must track upstream changes manually

---

### 5. Documentation

**If adopting package, document in `database/PACKAGES.md`:**

```markdown
### [Package Name]
- **Composer**: vendor/package-name
- **Version**: 1.2.3
- **Tables**: table1, table2, table3
- **Conflicts**: None / [describe any conflicts]
- **Decision Date**: YYYY-MM-DD
- **Rationale**: [why we chose this package]
- **Notes**: [any special considerations]
```

**If building own module, document decision:**

```markdown
## Evaluated But Not Used

### [Package Name]
- **Evaluated**: YYYY-MM-DD
- **Reason**: [why we chose to build our own]
- **Alternative**: app/Modules/Core/ModuleName
- **Tables**: [our table names]
```

---

## Real-World Examples

### Example 1: Geonames (Built Own)

**Evaluation:**
- ❌ Package used generic table names: `countries`, `cities`, `admin1_codes`
- ❌ High conflict potential with geography-related features
- ✅ Scope well-defined, manageable to implement
- ✅ Needed full control over data structure

**Decision:** Build own module as `app/Modules/Core/Geonames`
- Tables: `geonames_countries`, `geonames_admin1`, `geonames_cities`
- Full control, no conflicts, integrated with BLB architecture

### Example 2: Laravel Sanctum (Adopted)

**Evaluation:**
- ✅ Complex authentication system
- ✅ Well-maintained by Laravel team
- ✅ Tables are namespaced: `personal_access_tokens`
- ✅ No conflict with BLB tables

**Decision:** Adopt package as-is
- Accept `personal_access_tokens` table name
- No customization needed

### Example 3: Spatie Laravel-Permission (Hypothetical)

**Evaluation:**
- ⚠️ Uses generic names: `permissions`, `roles`
- ⚠️ Potential conflict with `base_permissions` (framework infrastructure)
- ✅ Complex permission system, well-maintained
- ❓ Different purpose than our `base_permissions`

**Decision:** Could adopt IF purposes don't overlap
- Their `permissions` = user permissions
- Our `base_permissions` = framework permission definitions
- Document clearly to avoid confusion

---

## Quick Decision Tree

```
Does package create database tables?
├─ NO → Adopt freely
└─ YES → Check table names
    ├─ Namespaced/specific? → Likely safe to adopt
    └─ Generic names? → Evaluate build vs buy
        ├─ Complex functionality? → Consider adopting
        ├─ Simple functionality? → Consider building own
        └─ Critical control needed? → Build own
```

---

## Related Documentation

- `database/AGENTS.md` - Database migration guidelines
- `database/PACKAGES.md` - Registry of adopted packages
- `docs/architecture/database.md` - Complete database architecture and naming standards
