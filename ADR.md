# ADR: Semantic Log Format

Status: Accepted — implemented (Unreleased).

## Context

The log records nested application operations for both humans (via `stree`) and AI agents. Requirements: total record order, JSON Schema draft-07 validation, one request = one immutable document, no streaming, explicit meaning over inference.

History:

- **0.8** — each open nested `open` / `events` / `close`; close was a property of its open.
- **0.9** — split each scope into separate `open` and `events` arrays. Lost the inter-order of opens and events within a scope.
- **0.10** — single ordered `children` array per scope (`kind: open|event`); close a property of its open; root `close[]` for orphan closes.
- **Open problem** — the desired shape was one ordered tape including closes (`open 1, open 2, event 1, close 2, close 1`). An attempt to encode it in JSON object keys failed: RFC 8259 does not guarantee member order.
- **Unreleased** — this ADR's decisions implemented: single ordered `log` array per interval (no `kind`); a matched close stays a slot (`close`) on its interval; an orphan close is a root record carrying `openId`, spliced into the root `log` at the position it was encountered.

## Candidates

| Candidate | Verdict | Reason |
|---|---|---|
| Order embedded in keys (`"open 1": …`) | Rejected | Depends on member order (spec-unsafe); duplicate keys collapse silently; unvalidatable |
| Tree + `seq` on every record | Rejected | Two sources of truth (tree vs `seq`) that can disagree; monotonicity is not schema-expressible |
| Flat tape + `depth` | Rejected | Machine-optimal but loses the tree for human/AI readers; its main benefit (streaming) is not required |
| `close` as an entry in `children` | Superseded | "Last element is special" is positional semantics in a homogeneous list; a close is not *content* of its scope |

## Decisions

**1. A matched close is an end observation, not a record in the sequence.**
The premise "0.10 loses the close position" was wrong for matched closes: a close always occurs at the end of its scope by definition, so its position is structural. The only actual information loss in 0.10 was the position of *orphan* closes (isolated in a root `close[]` array). Fix: matched closes stay in a dedicated `close` slot (pairing is structural and visually local); orphans move into the root `log` inline, where they stand as records of their own because no interval owns them. The root `close[]` array disappears. Note that `SemanticLogger` itself never emits an orphan: a misuse close is discarded and recorded as a `semantic_logger_error` point. The orphan shape exists for documents assembled from raw records and for readers; the writer rule and the reader rule are deliberately different.

**2. Kindless: record kind is read from structure.**
No `kind` field. A record with `close` is a completed interval; with `log` (empty or not) but no `close`, an interrupted interval; a root record with `openId` is an orphan close; with none of `close`, `log`, `openId`, a point. The four cases are disjoint, so discrimination does not depend on reading order. Structure cannot contradict a declared kind because there is none.

**3. An interval announces itself with `log`, empty or not.**
`open()` and `event()` are different observations *to the logger*, and a scope still open at flush is a fact the logger itself observed. Writer rule: `open()` creates a record with `log: []` at that moment; on close an empty `log` may be omitted (the `close` already marks the interval); a point never carries `log`. The alternative — reducing an empty unclosed open to a point — was rejected for two reasons. Locality: the same crash would be recorded as "interrupted" or "point" depending on whether an unrelated child record happened to exist, so the classification of a record would hinge on its children. Self-description: a bare log must be able to say it was truncated without consulting an external profile. `close: null` remains unnecessary.

**4. The container key is `log`.**
Fractal and self-similar: a log contains records; an interval record may contain a log; the document is the outermost log. Genre-true (this is a log, not a trace) and short (token economy). `events` was rejected: intervals are not events, and it collides with telemetry vocabulary. Elements are called *records* (cf. `LogRecord` in Java/Python logging).

**5. Meaning resolves through `schemaUrl` or a `types` index.**
Record-level `schemaUrl` (expanded form) and the document-level `types: {type: schemaUrl}` map (compacted form) are lossless serializations of one model. A record's schema is its `schemaUrl`, otherwise `types[type]`; when both are present they must agree, and a mismatch is an error — there is no precedence, only normalization that fills the missing side. Invariant: within a document, one `type` always resolves to one schema — type names are the document's vocabulary. Whether to emit URLs, short ids, or a hoisted index is a representation choice; the structure only requires that every record resolves to exactly one semantic definition.

**6. Explicit schemas are model-independent.**
The value of schema references is not compensating for LLM weakness — models may guess `uid` as "user id" when it means "unique index id". No capability of inference reaches application-internal facts; only explicit definition does. Schemas additionally enable validation by non-AI consumers.

**7. No telemetry vocabulary.**
`span` was rejected: an OTel span is one object with start/end *attributes*, while this format's open/close are two *observations* of one record, and order comes from array position, not timestamps. Borrowing the name would mislead exactly the readers who know it. `context` is kept knowingly: it is this library's own domain term (`AbstractContext`), and its OTel meaning (propagation identifiers) only collides if telemetry alignment is claimed — which this decision explicitly rejects. The mapping (interval ≈ span, point ≈ event) belongs in documentation, not in names.

**8. ALPS as the semantic layer.**
ALPS contributes the conceptual split — ontology (what fields mean) / taxonomy (how records are shaped) / choreography (what happened) — and its define-once-reference-many vocabulary discipline. Effect semantics (safe / idempotent / unsafe) belong in type definitions, not in log records. An ALPS profile is the static model; a log is a recorded instance of it. The log borrows the discipline, not the ALPS syntax; inline `title`/`doc` stay on the profile side for token economy.

## Consequences

Accepted trade-offs:

- Orphan close positions are approximate at root granularity. Exact positions for broken logs would require the flat tape format; judged an edge case of an edge case.
- Presence-based discrimination makes raw schema validation errors less legible than a `kind` const mismatch; the validator must generate kind-aware messages.
- `log` is a common word; the element term *record* must be defined in the spec.

Simplifications gained:

- No `kind`, no root `close[]`, no `contains`/`maxContains` schema hacks, no "close must be last" code rule — the structure enforces placement.
- `id` is optional. A writer MAY always emit it or emit it only where referenced (`openId`, `links`); readers MUST NOT require it.
- Draft-07 remains sufficient; 2020-12 adds nothing needed.

Validation split: JSON Schema checks shape and presence-based discrimination; a code validator checks `id` uniqueness (among emitted ids), orphan `openId` mismatch, `type`↔schema consistency (1:1, including `schemaUrl` vs `types[type]` agreement), interrupted intervals (warning), and `context` conformance (two-stage).
