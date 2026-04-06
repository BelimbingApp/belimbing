# Playbook-Driven Framework Direction

## Problem Essence

Belimbing may evolve from a hook-first customization story toward a playbook-first model driven by Lara and Kodi, but two fundamentals are still too fuzzy to promise in the public brief: the structure of a playbook and the shape of the discussion flow that turns a playbook into actionable TODOs.

## Status

Proposed

## Desired Outcome

Define whether Belimbing should treat playbooks as first-class framework assets, what form they take in the repository, how Lara and Kodi consume them, how playbook-guided discussions with the user should work, and what role remains for hooks, templates, and other extension points.

The outcome of this work should be concrete enough to justify brief-level positioning changes without overpromising architecture that does not yet exist.

The practical target is a clear flow where:

- Lara uses a playbook as the scaffold for Q&A, discovery, and recommendation-driven discussion with the user
- the discussion converges into explicit TODOs rather than dissolving into chat history
- Kodi consumes those TODOs as the implementation contract for building or adapting the module

## Public Contract

Until this direction is shaped and pressure-tested, the public brief should continue describing Belimbing in terms that are already defensible today.

If this work succeeds, Belimbing will be able to describe a clear contract along these lines:

- playbooks are first-class, versioned assets for constructing non-trivial business capabilities such as HR or accounting
- framework primitives remain the execution substrate underneath those playbooks
- Lara uses playbooks as guided discussion inputs rather than treating every business request as a blank-slate requirements interview
- Kodi uses the resulting TODOs as guided build inputs rather than treating every implementation as a blank-slate generation task
- low-level hooks remain available as implementation tools or advanced escape hatches, not the primary product story

## Top-Level Components

### Playbook Artifact Model

Define what a playbook actually is: narrative guidance, structured specification, machine-readable constraints, generated scaffolding, tests, or some combination.

### Framework Primitive Surface

Define the code-level building blocks that playbooks can rely on so the playbook layer stays deep and the public interface stays simple.

### Agent Execution Model

Define how Lara and Kodi discover, interpret, instantiate, customize, and validate a playbook.

### Discussion And TODO Distillation Model

Define how Lara uses the playbook to drive structured Q&A with the user, how the conversation stays bounded and recommendation-driven, and how the resulting understanding is distilled into explicit TODOs for Kodi.

### Conformance And Evolution Model

Define how playbooks are versioned, tested, upgraded, and safely diverged for project-specific needs.

## Design Decisions

- We should treat this as a product-architecture investigation, not a wording exercise for the brief.
- We should not update the brief to position Belimbing as playbook-first until the repository shape and execution model are concrete enough to defend.
- The strongest working hypothesis is that playbooks will need both human-readable guidance and machine-usable structure; plain prose alone is unlikely to be sufficient for reliable agent-driven builds.
- The playbook should be treated as a working conversation scaffold, not just as static module documentation.
- Lara should own discovery, clarification, and recommendation-driven discussion with the user; Kodi should own execution after that discussion has converged into TODOs.
- The immediate product value is likely not "generate a full HR system from one prompt" but "guide the user through a structured module conversation that reliably produces a strong TODO plan."
- Lara should use the knowledge-base direction as supporting context, but the playbook should remain a separate artifact focused on building a specific business capability rather than storing general reusable knowledge.
- Hooks should be treated as supporting substrate for playbooks and advanced customization, not assumed to remain the main customization story.
- HR and accounting are useful pressure tests because they are non-trivial enough to expose missing structure, invariants, and upgrade risks.

## Phases

### Phase 1

Goal: make the idea concrete enough to discuss structure instead of slogans.

Scope:

- [ ] Define what counts as a playbook in Belimbing terms
- [ ] Identify the minimum sections or artifacts a playbook must contain
- [ ] Decide whether the initial shape is markdown-first, schema-backed, code-backed, or a hybrid
- [ ] Define Lara's role in a playbook-guided discussion and Kodi's role after TODO handoff
- [ ] Write down what a playbook is explicitly not, so the concept does not sprawl into generic documentation

Assumptions:

- A playbook for something like HR or accounting will need stronger structure than a normal guide or cookbook page
- Lara and Kodi are core consumers of the playbook, not optional afterthoughts
- TODOs are the required end product of the discussion phase; chat history alone is not a sufficient implementation contract

Risks:

- The concept may remain too vague and collapse into documentation language without execution value
- The concept may become too heavy and turn every module into a mini-platform before the framework primitives are ready
- The discussion model may become too open-ended, causing Lara to ask broad exploratory questions without converging on actionable TODOs

Progress:

- [x] Capture the idea as a todo before changing the brief
- [x] Add Lara/Kodi and TODO distillation to the problem statement
- [ ] Produce a first recommended playbook shape
- [ ] Produce a first recommended discussion shape

### Phase 2

Goal: define the relationship between playbooks and framework internals.

Scope:

- [ ] Map which framework primitives a playbook should be allowed to compose
- [ ] Separate primary playbook interfaces from advanced hooks and escape hatches
- [ ] Define how module generation, customization, and upgrades should interact with playbooks
- [ ] Identify which parts belong in reusable code and which parts belong in playbook assets
- [ ] Decide which parts Lara reads directly during discussion versus which parts are only relevant to Kodi during implementation

Risks:

- A shallow primitive surface will force playbooks to leak too much implementation detail
- An overly large primitive surface will recreate the current hook-heavy story under a different name

### Phase 3

Goal: define the discussion loop that turns playbooks into TODOs.

Scope:

- [ ] Define the phases of a Lara-user playbook discussion, from discovery through convergence
- [ ] Decide what Lara should ask, what she should infer, and what she should recommend without pushing speculative design questions onto the user
- [ ] Define the handoff artifact from Lara to Kodi: todo doc, structured checklist, or another explicit build contract
- [ ] Identify where human approval happens before Kodi starts implementation

Assumptions:

- The best initial flow is likely Lara-first for discovery and Kodi-second for execution, rather than both agents talking to the user at once
- The discussion should be recommendation-driven and bounded by the playbook, not a free-form endless interview

Risks:

- Lara may ask too many generic questions if the playbook does not encode enough structure
- The handoff to Kodi may lose important nuance if TODOs are too thin or too implementation-heavy

### Phase 4

Goal: validate the model against non-trivial business modules.

Scope:

- [ ] Sketch an HR playbook outline
- [ ] Sketch an accounting playbook outline
- [ ] Compare the two to find required shared structure versus domain-specific variation
- [ ] Identify the minimum conformance tests that make a playbook trustworthy

Progress:

- [ ] Use at least two concrete module examples to pressure-test the model before any brief rewrite

### Phase 5

Goal: decide whether the brief should change and how strongly it should claim this direction.

Scope:

- [ ] Reassess the brief language once the playbook structure is concrete
- [ ] Update the brief only if the positioning is backed by a defensible repository and agent model
- [ ] Keep the brief conservative if the idea remains exploratory

Evidence:

- This document exists specifically to avoid promising a playbook-first architecture before the structure is mature enough to justify that promise