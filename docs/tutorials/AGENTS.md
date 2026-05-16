# Tutorials Guide (for Agents)

Tutorials live in `docs/tutorials/`. Two shapes coexist:

- **Single-file tutorials** for tools and framework topics (e.g. `livewire-and-blade.md`, `vite-roles.md`). Use when one focused topic.
- **Crash-course folders** for whole domains (e.g. `people/` for HR & payroll). Use when a coder needs domain knowledge — vocabulary, concepts, mental model — before they can touch the code productively.

This file is about **how to write a crash course**. The canonical example is `docs/tutorials/people/`. Read it before writing a new one — the writing rules are easier to internalize from the example than from a checklist.

## When to write a crash course

Write one when:

- A new domain module is landing (finance, manufacturing, healthcare, logistics) and onboarding a coder requires teaching them the domain itself, not just the code.
- Existing code uses jargon and patterns that don't make sense without domain context (chart of accounts, BOM explosion, lot/serial tracking, FIFO/LIFO, etc.).
- A reader has asked the same "why is X called Y / why does Y live in module Z" question more than once.

Do not write one for:

- A single feature — that's a guide or a design doc.
- Reference material (rate tables, code lists) — that's `docs/reference/`.
- An incident or fix recipe — that's a runbook.

## File layout

```
docs/tutorials/<domain>/
├── README.md            # Index + framing paragraph + audience note
├── 01-mental-model.md   # The shape: ledger / pipeline / source-of-truth rule
├── 02-<components>.md   # Catalogue of the domain's primary nouns + ownership
├── 03-<variation>.md    # How variation is expressed (country pack, vertical, scheme)
├── 04-<instance>.md     # One concrete instantiation, as a worked example of ch. 3
├── 05-module-map.md     # Which BLB module owns which fact, with real class names
├── 06-worked-example.md # One scenario end-to-end through real classes + anti-patterns
└── glossary.md          # Terms grouped by framework / generic / country / code shorthand
```

The number of chapters is a guideline, not a target. Six is good. Four can work. Ten is too many — split it.

## Chapter formula

Each chapter:

1. Opens with the rule, fact, or shape — not a preamble.
2. Stays under ~150 lines.
3. Centers on **logic**, not enumeration. The reader should leave understanding *why the code is shaped this way*, not memorizing every name.
4. References real BLB classes, files, or paths so the reader can jump straight from the prose to the code.
5. Is **self-readable** for a reader who landed there from a search.

### The shape every crash course teaches

Domains differ, but the four ideas below recur. Map your domain onto them when planning chapters.

1. **The ledger or end-product.** What is the artifact the domain produces? (Payslip. Trial balance. Work order. Patient record.) Show it as the destination so the rest makes sense as plumbing.
2. **The source-of-truth rule.** Each fact belongs to one module. Identify the owners; the rest of the system trusts them.
3. **The neutral envelope.** How does a source module hand a fact to the consumer without coupling? (`PayrollInput` in People. Likely `JournalEntry` in Finance, `ProductionEvent` in Manufacturing.) Identify the envelope.
4. **Where variation lives.** Country pack, accounting standard, manufacturing methodology, clinical protocol. Variation is **data**, not code branches. Show how it plugs in.

If you cannot identify all four for the domain you're documenting, the domain isn't ready for a crash course — write a design doc first.

## Writing rules

These came out of real editing passes. Follow them.

### Preserve domain terms; gloss them inline

The whole point of a crash course is to teach the vocabulary that bridges coders and users. Never water a term down — keep it and explain it.

- **Bad:** "Fixed allowances — for example, cost-of-living adjustment."
- **Good:** "Fixed allowances — housing allowance, **COLA** (cost-of-living adjustment, paid to offset inflation), fixed transport allowance."

Pattern: **term first, gloss immediately after in parentheses or appositive**. The acronym should look prominent (bold on first use is fine). The reader should be able to walk into a meeting and recognize the word when the user says it.

If a term is country-specific or scheme-specific, say so on first use (`MTD — Malaysia's monthly income-tax withholding`). Don't assume the reader knows.

### Frame the framework as neutral; the instance as one example

If BLB is country/locale/regime-neutral in a domain, **say so up front** in the README, and structure the chapters as: framework abstraction → concrete instantiation. Never let the first instantiation become the design center. The Malaysia chapter in People is *one* worked country pack, not the spine of the doc.

Same idea applies elsewhere: a US-GAAP chapter in Finance is one accounting regime; a discrete-manufacturing chapter is one methodology. The framework chapter comes first.

### Avoid tables when prose works

Tables are useful for true matrix data (acronym → expansion → body). They are a crutch for prose. Default to short paragraphs and bullet lists. The earlier draft of the People crash course used tables everywhere; the rewrite removed them and reads better.

Tables are appropriate for: glossary, rate tables (in `docs/reference/`), feature matrices. Not for: explaining concepts, walking a pipeline, listing components with a sentence of explanation each.

### Use key-value bullets for catalogues

Coders read key-value pairs faster than prose. When listing entities (pay components, account types, BOM line types, etc.), use a sketch like:

```
### <Name>

<One-sentence definition. Examples inline.>

- **Owner:** <Domain/SubModule>
- **Source models:** <model names>
- **Emits:** <what it produces>
- **Statutory / classification:** <generic shape; pack-specific detail elsewhere>
- **Notes:** <anything non-obvious>
```

Lead with the owning module precisely — `People/Attendance`, not "Attendance," and not "owned by Attendance." The `<Domain>/<SubModule>` path lets the reader map the entry onto a directory in `app/Modules/` without looking it up.

### Each chapter needs a Background section

The catalogue is the *what*. The Background section answers the questions the catalogue does not:

- **What problem are we solving?** State the friction that the design responds to (often: many modules contributing facts to one artifact without coupling).
- **How does BLB solve it?** State the chosen mechanism in two or three bullets.
- **Where is the key abstraction defined?** Name the canonical table or class, with file path. (For the People domain: `PayrollPayItem` at `app/Modules/People/Payroll/Models/`.)
- **What alternatives were considered? Why rejected?** Coders trust a design more when they can see the path not taken.
- **What trade-offs were accepted?** Every choice costs something. Name the cost and how it is mitigated.

Without this section, the catalogue reads like a directory listing. With it, the reader understands *why* the directory is shaped this way and can extend it without breaking the pattern.

### Be explicit about module boundaries

A reader asking "is X in this domain?" needs an unambiguous answer, especially when the answer is no.

- List the sub-modules in the domain explicitly (`People/Settings`, `People/Attendance`, etc.).
- For things the reader might expect but that live elsewhere (e.g. Sales/Commission, Finance/GL), say so directly and give the reason. The reason is almost always: the source-of-truth data lives in a different domain.
- When a future module is anticipated but not yet built, say "today: direct input into <module>; future: <hypothetical module>." The reader needs to know whether to look for a missing module or extend an existing one.

### Avoid AI slop

- No narrative preambles ("In this chapter, we will explore..."). Start with the content.
- No closing summaries ("In summary, we have seen..."). End where the content ends.
- No "by the end of this chapter you will..." learning-objectives boilerplate.
- No filler transitions ("Now that we have covered X, let's turn to Y").
- No emojis.
- No flowery language ("delve into," "unlock," "robust"). State the thing.

The reader's time is the constraint. A short sentence is better than a clear paragraph; a clear paragraph is better than a section.

### Ground every chapter in real code

Reference real class names, file paths, model names. The point of a tutorial that lives in the repo (rather than on a wiki) is that the reader can click through. A chapter with no `app/Modules/...` references is a smell.

### Worked example traces one thing end-to-end

The worked-example chapter is the payoff. Pick one realistic scenario and walk it through every layer touched, naming the actual classes and showing the actual envelope row. Include an "anti-patterns to spot" section at the end — that's where future maintainers learn what *not* to do.

### Glossary groups by audience need

Split the glossary into sections (framework terms, generic domain concepts, country/regime-specific terms, code shorthand). A flat alphabetical list hides the structure of the vocabulary.

## Process

1. **Read the existing crash course** in `docs/tutorials/people/` first. See the patterns in context.
2. **Survey the domain.** What does a user say in a meeting? Which acronyms recur? Which decisions surprised you when reading the code? Those are your chapter seeds.
3. **Map the domain to the four shapes** (ledger, source-of-truth, envelope, variation). If a shape doesn't fit, document why and adapt — but force yourself to try.
4. **Draft the README first.** It forces you to commit to a chapter list and an audience.
5. **Write chapter 1 (mental model) next.** If you can't write a clean mental model, the rest will be confused.
6. **Then the catalogue (chapter 2) and variation (chapter 3) in parallel.**
7. **The worked example last** — by then you know what's worth tracing.
8. **Glossary grows as you write.** Don't write it up front; harvest terms as they appear in earlier chapters.

## Checklist before shipping

- [ ] Every acronym is glossed on its first appearance in each chapter (not just in the glossary).
- [ ] Every chapter references at least one real file or class in `app/`.
- [ ] No tables that could be bullet lists.
- [ ] No narrative preambles or AI-isms.
- [ ] README clearly says the framework is neutral (if it is) and which chapter is the worked instance.
- [ ] Worked example traces one scenario through real classes with the actual envelope row shape.
- [ ] Anti-patterns section in the worked example.
- [ ] Glossary grouped by audience need, not alphabetical.
- [ ] A coder unfamiliar with the domain can read it in ~30 minutes and immediately find their way around `app/Modules/<Domain>/*`.

## Examples of domains likely to need crash courses

These are speculative — write them when the corresponding modules land.

- **Finance / accounting.** Chart of accounts, double-entry, journals, ledgers, trial balance, sub-ledgers (AR/AP), accruals, multi-currency, accounting standards (MFRS/IFRS/GAAP), period close, audit trail.
- **Manufacturing.** Bill of materials, routing, work order, work center, lot/serial, MRP, costing methods (standard/FIFO/LIFO/weighted average), shop floor events, capacity, yield, scrap.
- **Inventory & warehouse.** SKU, stock movement, putaway, picking, cycle count, reservation, costing layers, multi-location, transfer order.
- **Sales & CRM.** Lead, opportunity, quote, order, pipeline stage, forecast, commission scheme.
- **Procurement.** Requisition, RFQ, PO, GRN, three-way match, vendor master, payment terms.
- **Healthcare.** Patient, encounter, episode, diagnosis (ICD), procedure (CPT/HCPCS), clinical pathway, claim, prior auth.
- **Logistics.** Consignment, shipment, leg, hub, milestone, proof of delivery, incoterms, customs declaration.

Each has its own ledger, source-of-truth shape, neutral envelope, and variation surface. The framework writes itself once you find them.
