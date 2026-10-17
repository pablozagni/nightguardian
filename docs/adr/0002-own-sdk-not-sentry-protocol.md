# ADR 0002 — Own SDK protocol instead of the Sentry protocol (for now)

- Status: accepted
- Date: 2026-10-17

## Context
Several self-hosted trackers accept the Sentry envelope protocol and reuse `sentry/sentry-laravel`.
That gives many languages for free.

## Decision
v0.1 uses its own, smaller protocol and its own Laravel SDK because we need:
sending strictly after the response with a disk spool and circuit breaker; an end-user error page with
reference code and feedback; support for Laravel 5.8 (old PHP); zero third-party dependencies in the SDK.

A Sentry-compatible ingest endpoint stays on the roadmap as an *additional* door.

## Consequences
+ Full control of performance and UX.  + Tiny, auditable SDK.
− We must write SDKs for other platforms (community).
