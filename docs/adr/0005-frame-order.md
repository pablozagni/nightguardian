# ADR 0005 — Stack frames are ordered most recent first

- Status: accepted
- Date: 2026-10-17

## Context
Protocol v1 §4.1 did not say in which order `frames` are sent. The collector already assumes
"top of stack first" when it picks the culprit frame for grouping (first `in_app` frame, see
`docs/grouping.md`), and the Laravel SDK builds them that way.

## Decision
`frames` are ordered from the most recent call (where the exception was thrown) to the oldest
(the entry point). The first `in_app` frame is the culprit. SDKs for languages whose native traces
use the opposite order must reverse them.

## Consequences
+ Grouping and the panel can rely on one order.  + Clarification only: the protocol is still a
draft and no existing SDK or collector behaviour changes.
