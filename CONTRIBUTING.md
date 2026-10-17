# Contributing to NightGuardian

Thanks for being here! NightGuardian is a young project and every contribution counts:
code, docs, translations, bug reports, ideas.

## Ground rules

- Be kind. See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
- Small PRs get merged faster. One feature or fix per PR.
- Every change in behavior needs tests. CI must be green.
- Code, comments and commits in English. Translations welcome in `lang/`.

## Getting started

```bash
git clone https://github.com/<you>/nightguardian.git
cd nightguardian
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # demo data
php artisan test
```

Or with Docker: `docker compose up -d`.

## Where to help

| Area | Difficulty | Where |
|---|---|---|
| Translations | 🟢 | `lang/<locale>/` |
| Alert channels (Slack, Discord, Teams, webhook) | 🟢🟡 | `app/Alerts/Channels/` — implement `AlertChannel` |
| Panel improvements | 🟡 | `app/Filament/` |
| New SDK (PHP, Node, Python, …) | 🟡🔴 | separate repo — read [docs/sdk-authors.md](docs/sdk-authors.md) |
| Protocol changes | 🔴 | needs an ADR first (`docs/adr/`) |

Check issues labeled `good first issue` and `help wanted`.

## Workflow

1. Open an issue (or comment on one) to say what you're working on.
2. Fork, create a branch: `feat/slack-channel`, `fix/grouping-null-frames`.
3. Follow [Conventional Commits](https://www.conventionalcommits.org): `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`.
4. Run `composer lint` (Pint) and `composer test` (Pest) before pushing.
5. Open the PR using the template. Link the issue.

## Architecture decisions

Big decisions live in `docs/adr/` as short Architecture Decision Records. If your PR changes
the protocol, the data model in a breaking way, or adds a dependency, add an ADR.

## Protocol stability

`/api/v1` is a public contract used by SDKs we don't control. Additive changes only
(new optional fields, new event types). Breaking changes go to `/api/v2`.
