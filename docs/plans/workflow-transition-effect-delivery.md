# Workflow Transition Effect Delivery

Status: Proposed
Last Updated: 2026-07-16
Sources: `docs/modules/workflow/design.md`, `app/Base/Workflow/Services/TransitionOutboxDispatcher.php`, `app/Base/Workflow/Listeners/SendTransitionNotification.php`
Agents: {Codex}/{GPT-5}

## Problem Essence

Committed workflow transitions are delivered from a durable, at-least-once outbox, but the built-in notification listener has no durable per-effect idempotency boundary. A replay after a partial listener failure can duplicate an already-sent notification, while each outbox row also retains a complete raw model snapshot even when listeners only need the stable transition payload.

## Desired Outcome

Every transition-derived side effect has a stable identity, explicit delivery state, bounded retry behavior, and operator-visible failure evidence. Outbox payloads retain only the minimum model projection justified by their consumers and are compacted or removed under a documented retention policy without weakening replay of unresolved work.

## Top-Level Components

- A transition-effect ledger owns per-listener, recipient, and channel delivery state.
- A model snapshot projector defines the small, JSON-safe event projection; the flattened `TransitionPayload` remains the preferred listener contract.
- A reconciler retries unresolved effects using connector-supported idempotency keys where available.
- A retention task compacts delivered outbox payloads and prunes terminal effect records after the configured audit window.
- Workflow operations expose pending, retrying, terminally failed, and oldest-unresolved effect counts.

## Design Decisions

### Side-effect delivery

Keeping listener idempotency as documentation alone is simple but cannot protect a multi-recipient listener that fails halfway through. Moving the event directly to the queue changes where retries happen but preserves the same duplicate window. The recommended design is a transactional effect ledger keyed by transition event, listener, recipient, and channel. Delivery remains honestly at least once; connectors receive the stable effect key as their idempotency key when supported, while local database effects use it as a uniqueness constraint.

### Snapshot projection

Retaining every raw model attribute gives exact replay but duplicates unrelated and potentially sensitive data indefinitely. Reloading the current model stores less but silently changes historical event meaning. The recommended contract stores the model class, key, key name, committed status, and an optional model-owned workflow projection with an explicit allowlist. Listeners use `TransitionPayload` for historical facts and request current state deliberately when current state is what they mean.

### Retention

Deleting delivered rows immediately minimizes storage but removes useful delivery evidence. Permanent full-payload retention increases exposure without improving unresolved delivery. The recommended policy keeps compact delivery metadata for an audit window, preserves unresolved rows until disposition, and removes bulky snapshots from successfully delivered rows once no pending effect references them.

## Public Contract

- A transition event is identified by its stable outbox `event_key` and history id.
- An effect is identified by the transition event plus listener, recipient, and channel; retrying the same effect cannot create a second local record.
- Business-process correctness cannot depend on a notification being sent. Domain state and durable process work items commit independently; notification failure only raises operational attention.
- Model projections are explicit allowlists. Hidden attributes, credentials, tokens, and arbitrary raw model attributes are not part of the default event contract.
- Unresolved effect failures remain queryable and retryable; terminal disposition requires an operator-visible reason.

## Phases

### Phase 1 — Effect identity and persistence

- [ ] Define the stable effect-key contract and persist per-effect pending, leased, delivered, and terminal-failure state.
- [ ] Make event dispatch materialize intended effects idempotently before connector delivery.
- [ ] Prove crash recovery at every boundary: before send, after send before acknowledgement, and midway through multiple recipients.

### Phase 2 — Notification migration

- [ ] Move `SendTransitionNotification` onto the effect ledger with one effect per recipient and channel.
- [ ] Use deterministic database-notification identities and pass connector idempotency keys where supported.
- [ ] Surface unsupported exactly-once connector semantics honestly while preventing duplicate local notification rows.

### Phase 3 — Minimal event projections

- [ ] Introduce the workflow snapshot projection contract with a safe minimal default.
- [ ] Migrate built-in listeners to `TransitionPayload` plus explicit projections and remove full raw model snapshots from new outbox rows.
- [ ] Add regression tests proving hidden or credential-like attributes never enter an outbox payload.

### Phase 4 — Retention and operations

- [ ] Add scheduled compaction/pruning with configurable audit windows and unresolved-row protection.
- [ ] Expose pending, retrying, failed, and oldest-unresolved effects in workflow operations.
- [ ] Document recovery and terminal-disposition procedures and prove them with focused command tests.

### Phase 5 — Consumer audit

- [ ] Audit every `TransitionCompleted` listener for effect identity and snapshot needs.
- [ ] Verify business modules, including Investment, do not use notifications as a correctness signal.
- [ ] Run transition, replay, notification, retention, and module integration suites as the completion proof.
