# Security Policy

NightGuardian handles error data that may include sensitive information. We take security seriously.

## Reporting a vulnerability

**Do not open a public issue.** Use GitHub's private
[security advisory](../../security/advisories/new) form for this repository.

You will get an acknowledgement within 72 hours and a plan within 7 days.

## Supported versions

Only the latest minor release of the collector and of each SDK line receives security fixes.

## Scope highlights

- Ingest API authentication and token handling
- Scrubbing of secrets (SDK and collector)
- Panel authorization (project roles)
- Stored XSS through event payloads rendered in the panel
