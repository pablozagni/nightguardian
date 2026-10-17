# Writing a NightGuardian SDK

You don't need to know the collector's code. You need [protocol/v1.md](protocol/v1.md).

## Minimum viable SDK (a weekend)

1. Config: `url`, `token`, `environment`, `release`, `enabled`.
2. Hook the platform's global error handler(s).
3. Build an `exception` event (UUID v4 `id`, frames with `in_app`).
4. Scrub (denylist keys + header allowlist).
5. POST gzip JSON after the response / in background, 2 s timeout.
6. Never throw. Never capture your own errors.

## Production-ready SDK

7. Spool to disk on failure, bounded; drain later with a lock.
8. Circuit breaker (60 s doubling to 15 min).
9. Code context (±5 lines) for in-app frames.
10. `reference` code for user-facing errors (`NG-XXXX-XXXX`, Crockford base32).
11. A `test` command/function that sends a `test` event and prints the result.
12. Optional: log capture, background jobs, slow operations, deploy markers, feedback.

## Validate against a real collector

```bash
curl -X POST https://your-collector/api/v1/ingest \
  -H "Authorization: Bearer $NG_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-NightGuardian-Dry-Run: 1" \
  --data @docs/protocol/examples/exception.json
```

The response lists every validation error per event, without storing anything.

## Naming & listing

- Repo: `nightguardian-<platform>` (e.g. `nightguardian-node`).
- `sdk.name` in events: same as the repo name.
- Open a PR adding your SDK to the table in the main README. Community SDKs are welcome; we'll link them
  as "community" until they pass the checklist above.
