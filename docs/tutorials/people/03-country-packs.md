# 3. Country Packs

BLB's payroll engine is country-neutral. The arithmetic that differs between countries — what counts as wages, what tax formula applies, which contributions exist, what's exempt — is expressed as **data** in a country pack. Adding a new country is a configuration exercise, not a code change.

## What a country pack is

A pack is the set of rows that together describe one country's statutory behavior for a given period. It has three layers.

**Pay-item classifications.** `PayrollPayItemClassification` rows scoped by `country_iso` and `effective_from / effective_to`. Each row tags a pay item with a key/value such as "is contributable to retirement fund: yes", "tax path: normal", "exemption category: meal-shift-work", "GL account: 6100-04". The classifier `PayItemClassifier` resolves these at run time. The same `pay_item_code` can mean different things in different countries because each country pack writes its own classification rows.

**Statutory rule set.** `PayrollStatutoryRuleSet` plus `PayrollStatutoryRuleRow` carry the rate tables and bands — retirement contribution rates by age and wage band, social-security ceilings, tax brackets, training-levy thresholds. Each rule set is versioned with an effective period so historical runs reproduce exactly.

**Employer and employee statutory profiles.** `PayrollEmployerStatutoryProfile` and `PayrollEmployeeStatutoryProfile` hold per-entity registrations and elections — employer registration numbers, employee tax IDs, scheme participation flags, voluntary contribution rates, charitable-deduction elections.

## What is *not* in a pack

- Source-of-truth facts. Attendance clock events, leave balances, claim approvals stay in their owning modules regardless of country.
- The calculator's structure. `PayrollRunCalculator` reads classifications and rule rows; it doesn't branch on country.
- The neutral envelope. `PayrollInput` shape is the same everywhere.

If a feature requires a code branch on country, that's a sign the abstraction has leaked and the feature probably belongs in the pack instead.

## The three calculation paths

Most countries' income-tax withholding fits one of three formulas, expressed as the pay item's `tax_path` classification.

**Normal / recurring.** Regular monthly salary, fixed allowances, recurring shift premiums. The amount is annualized, reliefs applied, tax computed, and divided across remaining periods.

**Additional remuneration / lump-sum.** Bonus, irregular incentive, backpay, encashment. Tax is computed with the lump and without; the delta is withheld in the month of payment. Prevents one-off lumps from distorting monthly run-rate.

**Commission (where the country has a separate schedule).** Recurring commission paid every period, with its own formula or its own submission line on the tax-authority schedule.

Pay items declare which path they take through classification. Source modules never need to know.

## Adding a new country

Walk the layers in order:

1. **Pay items.** Confirm the catalogue of pay items the country needs. Most of BLB's pay items are country-neutral codes (e.g. `BASIC`, `OT`, `MEAL_ALLOW_SHIFT`); some may be country-specific.
2. **Classifications.** For each pay item, write `PayrollPayItemClassification` rows for the country: contributability flags, tax path, exemption codes, GL accounts.
3. **Rule set.** Create a `PayrollStatutoryRuleSet` with the contribution rates, tax bands, ceilings, and effective period.
4. **Profiles.** Define what fields the country's employer and employee statutory profiles need — registration numbers, scheme flags, elections.
5. **Forms and remittance.** Country-specific statutory forms (monthly contribution submissions, year-end income statements) are templates that read from the ledger and profiles.

No source module changes. No calculator changes.

## Versioning

Every pack layer is effective-dated. Rates change year-over-year; classifications can change (e.g. a new exemption is introduced); profile fields can be added. Old payroll runs continue to resolve against the rule-set version they were calculated with — that version is stamped on every `PayrollResultLine`.

## Where to look

- `app/Modules/People/Payroll/Models/PayrollPayItemClassification.php` — the classification row.
- `app/Modules/People/Payroll/Services/PayItemClassifier.php` — the resolver.
- `app/Modules/People/Payroll/Models/PayrollStatutoryRuleSet.php` and `PayrollStatutoryRuleRow.php` — the rate tables.
- `app/Modules/People/Payroll/Models/PayrollEmployerStatutoryProfile.php` and `PayrollEmployeeStatutoryProfile.php` — per-entity statutory data.
- `app/Modules/People/Payroll/Services/PayrollRunCalculator.php` — the country-neutral calculator that reads all of the above.
