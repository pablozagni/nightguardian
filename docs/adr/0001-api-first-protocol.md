# ADR 0001 — API-first, language-agnostic protocol

- Status: accepted
- Date: 2026-10-17

## Context
The first users run Laravel, but the goal is a monitoring tool any developer can adopt and extend.
Tying the collector to Laravel internals would block SDKs for other platforms.

## Decision
The collector only knows a documented HTTP/JSON protocol (`/api/v1/ingest`). Every SDK, including the
Laravel one, is a client of that protocol. The protocol doc + JSON schema are the source of truth.
A dry-run header lets SDK authors validate payloads.

## Consequences
+ Any language can integrate; community SDKs are possible.
+ SDKs and collector evolve independently (versioned API).
− Laravel-specific richness must be expressed through generic fields (`context`, `tags`, typed payloads).
