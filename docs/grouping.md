# Grouping (fingerprinting)

Goal: the same bug = one issue, even after deploys; different bugs = different issues.

## Algorithm (default `Fingerprinter`)

Parts are joined with `\n` and hashed with SHA-1.

1. If the event has `fingerprint` (array): use it. Replace the element `{{ default }}` with the default parts below.
2. By type:

| Type | Parts |
|---|---|
| `exception`, `test` | type · innermost exception class · **culprit frame** (normalized file + class + function) |
| `log` with exception | same as exception |
| `log` without exception | type · level · channel · normalized message template |
| `job_failed` | type · job class · innermost exception class · culprit frame |
| `schedule_failed` | type · command (normalized) |
| `slow_request` | type · method · route (or normalized path) |
| `slow_query` | type · connection · normalized SQL |

**Culprit frame** = first frame with `in_app: true` (top of stack = most recent). If none, the first frame.
If there are no frames, use the normalized message.

**Line numbers are NOT part of the fingerprint**, so moving code doesn't create new issues.

## Normalization

- File: forward slashes, strip project root and leading `./`, strip release directories like
  `releases/20261017120000/`.
- Messages: replace numbers (`\d+`), UUIDs, hex ids (≥8 chars), quoted strings, e-mails and URLs with
  placeholders; collapse whitespace; truncate to 255.
- SQL: lowercase keywords, collapse `IN (?, ?, ?)` → `IN (?)`, strip comments.
- Class names: strip anonymous class suffixes (`class@anonymous/...`).

## Status transitions on new event

| Current status | Result |
|---|---|
| (no issue) | create, status `new` → alert `new` |
| `new` | stays `new` (counters) |
| `resolved` | → `regressed` → alert `regressed` (unless event release is *older* than `resolved_in_release`) |
| `regressed` | stays |
| `ignored` with `ignored_until` in the past | → `regressed` |
| `ignored` (active) | counters only, no alert |

## Merging

Panel action "Merge into…" re-points fingerprints of B to A, moves events and feedback, sums counters,
sets `B.merged_into_id = A`. Irreversible in v0.1 (documented in the confirmation dialog).

## Tests

`tests/Unit/Grouping/` must include fixtures for: same bug different line → same fingerprint;
different function → different; vendor-only stack; no frames; custom fingerprint with `{{ default }}`;
message normalization cases.
