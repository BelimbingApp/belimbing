# People & Payroll: Crash Course

A coder's primer on the People domain. Read in order; each chapter is short.

BLB is a country-neutral HR/payroll framework. The core modules (Attendance, Leave, Claim, Payroll, Settings) carry no country logic. Country-specific behavior — tax, social security, contribution rates, exemptions — lives in **country packs** that plug into Payroll through data, not code. Malaysia is the current pack because that's where this project started; the same pipeline runs for any other country once its pack is in place.

1. [Mental model](01-mental-model.md) — payslip as ledger; source-of-truth rule; the pipeline.
2. [Pay components](02-pay-components.md) — every line that can appear on a payslip and who owns it.
3. [Country packs](03-country-packs.md) — how country-specific statutory behavior plugs in.
4. [Malaysia pack](04-malaysia-pack.md) — the current country pack, as a worked example.
5. [Module map](05-module-map.md) — which BLB module owns which fact, with real class names.
6. [Worked example](06-worked-example.md) — a conditional allowance traced end-to-end.
7. [Glossary](glossary.md) — acronyms, statutory terms, BLB code shorthand.

Audience: developer with no HR background who needs to navigate `app/Modules/People/*` quickly. Skim chapters 1–3 for the shape; keep 5 open as a lookup.
