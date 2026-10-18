# Public demo mode

Run a **public, read-only demo** of NightGuardian (for example `demo.nightguardian.com.ar`) that anyone can look around
without creating an account, and that cleans itself every night. It is generic and **off by default**: nothing below
changes a normal installation.

## What it does

With `NIGHTGUARDIAN_DEMO_MODE=true`:

- **A visitor account with the `viewer` role.** The login page shows its e-mail and password and an **Enter the demo**
  button that signs in with one click (the form is also prefilled, so *Sign in* works too).
- **A permanent banner** at the bottom of every panel page: *"Live demo — the data is reset every night"* (English or
  Spanish; on the login page the language follows the browser).
- **The visitor cannot change anything about their account:** the profile page (name, e-mail, password, two-factor
  authentication) is closed to them and its entry disappears from the user menu. Everyone else (your administrator) keeps it.
- **A lower ingest limit**, `120` events per minute per project by default, so a public demo cannot be used to flood the server.
- **A nightly reset** (`nightguardian:demo-reset`, 04:00 by default) that wipes everything and rebuilds realistic data.

## Configuration

Add to `.env` of the demo instance:

```dotenv
NIGHTGUARDIAN_DEMO_MODE=true
NIGHTGUARDIAN_DEMO_ADMIN_EMAIL=you@example.com            # your own super admin: it survives every reset
NIGHTGUARDIAN_DEMO_INGEST_TOKEN=ng_<40 letters or digits>  # token of the "Demo app" project (see below)
# Optional, with their defaults:
NIGHTGUARDIAN_DEMO_VISITOR_EMAIL=visitor@demo.example.com
NIGHTGUARDIAN_DEMO_VISITOR_PASSWORD=visitor-demo
NIGHTGUARDIAN_DEMO_RATE_LIMIT=120                          # events per minute, per project
NIGHTGUARDIAN_DEMO_RESET_TIME=04:00                        # in the application time zone (APP_TIMEZONE)
```

The visitor password is public by design (it is printed on the login page). The visitor can only read.

Generate a token once with, for example, `php -r 'echo "ng_".substr(bin2hex(random_bytes(20)), 0, 40), PHP_EOL;'`.
Then run `php artisan config:clear` (or `optimize`) and make sure the scheduler runs (`schedule:run` every minute, see
[deploy.md](deploy.md)).

## The nightly reset

`php artisan nightguardian:demo-reset` (scheduled daily at `NIGHTGUARDIAN_DEMO_RESET_TIME`, only in demo mode):

1. Deletes projects, issues, events, feedback, deploys, tokens, members, invitations, alert channels and rules, queued
   jobs and sessions, and **every user except the administrator** named by `NIGHTGUARDIAN_DEMO_ADMIN_EMAIL`
   (if that variable is empty, existing super admins are kept).
2. Recreates the visitor account.
3. Recreates **Demo Shop** and **Demo API** with realistic issues, stack traces, feedback, activity and deploys. There are no
   alert channels, so a demo never sends e-mail or Telegram messages.
4. Recreates the **Demo app** project (rate limit = the demo limit) with the token from `NIGHTGUARDIAN_DEMO_INGEST_TOKEN`,
   so the demo application keeps sending events with the same token after every reset (the token cache is cleared too).
   If the variable is empty the project gets a random token and the command warns you.
5. Gives the visitor the `viewer` role in all three projects.

The command **refuses to run when demo mode is off**: it cannot wipe a real installation by mistake. Run it by hand at
any time to start from scratch.

## Security notes

- Never turn demo mode on in an installation that holds real data: the nightly reset deletes it.
- Use a dedicated server or at least a dedicated database.
- Keep `APP_DEBUG=false` and HTTPS on ([deploy.md](deploy.md)).
- The ingest limit is per project; the public demo application should add its own limit per visitor (the demo app in
  [nightguardian-demo](https://github.com/pablozagni/nightguardian-demo) limits `/try` per IP).
