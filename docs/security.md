# Security & privacy

## Threat model (short)

- Monitored apps handle personal and fiscal data. Error payloads can leak it.
- The ingest endpoint is public on the internet and receives untrusted JSON.
- The panel renders that JSON → XSS risk.

## Scrubbing

**In the SDK (primary)** and **again in the collector (defense in depth)**.

Default denylist (case-insensitive, substring match on keys): `password`, `passwd`, `pwd`, `secret`,
`token`, `api_key`, `apikey`, `access_key`, `private_key`, `authorization`, `auth`, `cookie`,
`session`, `csrf`, `xsrf`, `credit_card`, `card_number`, `cvv`, `cuit`*, `cbu`*, `iban`.
(*configurable extras; documented as examples, not defaults, to keep the project neutral.)

Value patterns replaced everywhere in strings: card numbers (Luhn-valid 13–19 digits), `Bearer …`,
JWTs (`eyJ…`), private key blocks. Replacement text: `[Filtered]`.

Headers: SDKs send an **allowlist** only (`user-agent`, `accept`, `accept-language`, `content-type`,
`referer`, `x-requested-with`). Never cookies or authorization.

Off by default (opt-in): client IP, request body, user e-mail/name.

## Ingest hardening

- Token hashed (sha256) at rest; shown once; revocable; prefix `ng_` makes it detectable by secret scanners.
- Constant-time comparison not needed (lookup by hash) but no timing-revealing error messages: always `401`.
- Size limits, gzip-bomb protection (stop decompressing after 5 MB), per-project rate limit, per-IP
  rate limit for invalid tokens (20/min).
- JSON depth limit 32.

## Panel

- Blade escaping everywhere; payload JSON rendered as text in `<pre>`; no `{!! !!}` on event data.
- Stack trace code context rendered as text.
- Policies on every resource; tests for viewer/member/admin boundaries.
- No public registration; invitations via signed URL.
- Optional 2FA; recommend enforcing for super admins.
