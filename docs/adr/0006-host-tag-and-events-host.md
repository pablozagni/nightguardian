# ADR 0006 — `tags.host` convention and `events.host`

- Status: accepted
- Date: 2026-10-19

## Context
Apps sold as one copy per customer run the same code in many installations. Sharing one project (one token) keeps the same bug a
single issue, but until now nothing told the installations apart, and one project per installation hides how widespread a bug is.

## Decision
- Protocol v1 recommends the tag `host` (the installation's host name). The Laravel SDK sends it by default (host of `APP_URL`).
- The collector stores it in `events.host` (string 255, nullable, indexed), falling back to the host of `request.url`.
  Existing events are backfilled in chunks by the migration.
- The panel shows the affected installations of an issue, filters the triage list by host and shows the host in the events list;
  alerts name the host and, when an issue was seen in several, the number of installations.

## Consequences
+ Additive: no schema change, no new required field, v1 SDKs and collectors keep interoperating.
+ One column to query instead of a JSON path inside `payload`.
- Hosts that change over time (a domain rename) appear as two installations until the old events are pruned.
