# ADR 0003 — Fingerprints use culprit frame function, not line numbers

- Status: accepted
- Date: 2026-10-17

## Context
Grouping by class + file + line creates a "new" issue every time a deploy shifts code, causing false alerts.

## Decision
Fingerprint = type + exception class + first in-app frame (normalized file + class + function).
SDKs may override with an explicit `fingerprint`. Issues can be merged in the panel.

## Consequences
+ Stable issues across deploys. − Two different bugs of the same class in the same function merge;
mitigated by custom fingerprints and future "split issue".
