# Deploying NightGuardian in production

This guide installs the collector on a fresh **Ubuntu 22.04** server with **Apache + PHP-FPM 8.3** and **MySQL 8**.
Every step is a command you can paste in an SSH session (no editor needed). Replace the values in the first block once
and keep the same session open until the end: the later blocks reuse those variables.

Just want to try it? Use [Docker](#trying-it-with-docker) instead (two minutes, not meant for production).

**What you need:** a server with a public IP, a DNS name pointing at it (for example `nightguardian.example.com`), and
a user with `sudo`. 1 vCPU and 1 GB of RAM are enough for a handful of applications.

Contents: [1. Variables](#1-variables) · [2. Packages](#2-packages) · [3. Database](#3-database) ·
[4. Code](#4-the-code) · [5. Configuration](#5-configuration) · [6. Apache](#6-apache-and-php-fpm) ·
[7. HTTPS](#7-https) · [8. Cron and worker](#8-the-scheduler-and-the-queue-worker) · [9. Check it](#9-check-it) ·
[10. Updating](#10-updating) · [11. Backups](#11-backups) · [12. Troubleshooting](#12-troubleshooting) ·
[Docker](#trying-it-with-docker)

---

## 1. Variables

Edit the four values, then paste the block. Nothing is written to disk yet.

```bash
export NG_DOMAIN="nightguardian.example.com"      # the DNS name of this server
export NG_ADMIN_EMAIL="you@example.com"           # first super admin, also used by Let's Encrypt
export NG_ADMIN_NAME="Your Name"
export NG_ADMIN_PASSWORD="$(openssl rand -base64 18)"   # at least 12 characters; printed below, change it later in the panel
export NG_DB_PASSWORD="$(openssl rand -hex 24)"
export NG_DIR="/var/www/nightguardian"
echo "Admin password: $NG_ADMIN_PASSWORD"         # write it down now
```

## 2. Packages

PHP 8.3 comes from the well-known `ppa:ondrej/php` repository (Ubuntu 22.04 ships PHP 8.1).

```bash
sudo apt-get update && sudo apt-get -y upgrade
sudo apt-get install -y software-properties-common ca-certificates curl git unzip acl ufw
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y apache2 mysql-server certbot python3-certbot-apache \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-intl php8.3-zip php8.3-mbstring php8.3-xml php8.3-curl php8.3-bcmath
```

Composer:

```bash
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm /tmp/composer-setup.php
composer --version
```

Firewall (keeps SSH open first):

```bash
sudo ufw allow OpenSSH && sudo ufw allow "Apache Full" && sudo ufw --force enable
```

## 3. Database

```bash
sudo mysql <<SQL
CREATE DATABASE nightguardian CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nightguardian'@'localhost' IDENTIFIED BY '${NG_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON nightguardian.* TO 'nightguardian'@'localhost';
FLUSH PRIVILEGES;
SQL
```

## 4. The code

The application lives in `/var/www/nightguardian` and is owned by `www-data`, the user PHP-FPM runs as. Every command
that touches it is run `sudo -u www-data -H ...`, so no file ends up owned by the wrong user.

```bash
sudo mkdir -p "$NG_DIR"
sudo chown www-data:www-data "$NG_DIR"
sudo -u www-data -H git clone https://github.com/pablozagni/nightguardian.git "$NG_DIR"
cd "$NG_DIR"
sudo -u www-data -H git checkout v0.1.0          # a released tag; use "main" to follow development
sudo -u www-data -H composer install --no-dev --optimize-autoloader --no-interaction
```

## 5. Configuration

```bash
cd "$NG_DIR"
sudo -u www-data -H cp .env.example .env
sudo -u www-data -H sed -i \
  -e "s|^APP_ENV=.*|APP_ENV=production|" \
  -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
  -e "s|^APP_URL=.*|APP_URL=https://${NG_DOMAIN}|" \
  -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" \
  -e "s|^DB_HOST=.*|DB_HOST=127.0.0.1|" \
  -e "s|^DB_DATABASE=.*|DB_DATABASE=nightguardian|" \
  -e "s|^DB_USERNAME=.*|DB_USERNAME=nightguardian|" \
  -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${NG_DB_PASSWORD}|" \
  .env
sudo -u www-data -H php artisan key:generate --force
sudo chmod 640 .env
```

Create the tables and the first super admin (this also runs the migrations):

```bash
sudo -u www-data -H php artisan nightguardian:install \
  --name="$NG_ADMIN_NAME" --email="$NG_ADMIN_EMAIL" --password="$NG_ADMIN_PASSWORD"
sudo -u www-data -H php artisan optimize
```

**E-mail alerts** need a mailer. Set `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and
`MAIL_FROM_ADDRESS` in `.env` (any SMTP service works) and run `sudo -u www-data php artisan optimize` again.
Telegram alerts need nothing here: the bot token is entered in the panel and stored encrypted.

## 6. Apache and PHP-FPM

```bash
sudo tee /etc/apache2/sites-available/nightguardian.conf > /dev/null <<EOF
<VirtualHost *:80>
    ServerName ${NG_DOMAIN}
    DocumentRoot ${NG_DIR}/public

    <Directory ${NG_DIR}/public>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>

    # Hand PHP files to PHP-FPM.
    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # The SDKs authenticate with "Authorization: Bearer ng_..."; Apache does not pass that header to PHP-FPM
    # unless you ask it to. Without this line every event is rejected with 401.
    SetEnvIfNoCase ^Authorization\$ "(.+)" HTTP_AUTHORIZATION=\$1

    ErrorLog \${APACHE_LOG_DIR}/nightguardian-error.log
    CustomLog \${APACHE_LOG_DIR}/nightguardian-access.log combined
</VirtualHost>
EOF

sudo a2enmod proxy_fcgi setenvif rewrite headers
sudo a2enconf php8.3-fpm
sudo a2dissite 000-default
sudo a2ensite nightguardian
sudo apache2ctl configtest && sudo systemctl reload apache2
```

PHP-FPM is the reason a slow or unreachable collector never slows down your applications: they send events after
the response, and the collector answers quickly because the heavy work (alerts) is queued.

## 7. HTTPS

Make sure the DNS name already points at the server, then:

```bash
sudo certbot --apache -d "$NG_DOMAIN" --non-interactive --agree-tos -m "$NG_ADMIN_EMAIL" --redirect
sudo systemctl status certbot.timer --no-pager | head -5     # renewal is automatic
```

The SDKs refuse to be useful over plain `http://` in production (they warn), so do not skip this step.

## 8. The scheduler and the queue worker

Alerts, the daily digest, threshold checks and retention need both. Without them the panel works but nothing is
notified and old events are never deleted.

**Scheduler** (one cron line for `www-data`, runs every minute):

```bash
( sudo crontab -u www-data -l 2>/dev/null | grep -v "artisan schedule:run" ; \
  echo "* * * * * cd ${NG_DIR} && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1" ) | sudo crontab -u www-data -
sudo crontab -u www-data -l
```

**Queue worker** (a systemd service that restarts itself):

```bash
sudo tee /etc/systemd/system/nightguardian-worker.service > /dev/null <<EOF
[Unit]
Description=NightGuardian queue worker
After=network.target mysql.service
Wants=mysql.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=${NG_DIR}
ExecStart=/usr/bin/php8.3 artisan queue:work --tries=3 --sleep=3 --max-time=3600
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now nightguardian-worker
sudo systemctl status nightguardian-worker --no-pager | head -8
```

`--max-time=3600` makes the worker exit every hour so it never holds an old copy of the code or a stale database
connection; systemd starts it again immediately.

## 9. Check it

```bash
curl -sS -o /dev/null -w "login page: HTTP %{http_code}\n" "https://${NG_DOMAIN}/login"      # 200
curl -sS -o /dev/null -w "health:     HTTP %{http_code}\n" "https://${NG_DOMAIN}/up"         # 200
curl -sS -o /dev/null -w "ingest, no token: HTTP %{http_code}\n" -X POST "https://${NG_DOMAIN}/api/v1/ingest"   # 401
sudo -u www-data -H php artisan schedule:list
```

Open `https://nightguardian.example.com`, sign in with the admin e-mail and password from step 1, create a project
and a token (*Projects → your project → Tokens*), then in the application you want to monitor:

```bash
composer require nightguardian/laravel
# in its .env:  NIGHTGUARDIAN_URL=https://nightguardian.example.com   NIGHTGUARDIAN_TOKEN=ng_...
php artisan nightguardian:test
```

The panel shows these exact lines when you create a token. To receive alerts, add a Telegram or e-mail channel in the
project (*Alert channels*) and press **Send test**.

## 10. Updating

Create the update script once:

```bash
sudo tee /usr/local/bin/nightguardian-update > /dev/null <<'EOF'
#!/usr/bin/env bash
# Usage: sudo nightguardian-update            (follows the current branch)
#        sudo nightguardian-update v0.2.0     (moves to a tag or branch)
set -euo pipefail

APP="${NG_DIR:-/var/www/nightguardian}"
run() { sudo -u www-data -H "$@"; }

cd "$APP"
run git fetch --tags --prune

if [ -n "${1:-}" ]; then
    run git checkout "$1"
else
    run git pull --ff-only
fi

run composer install --no-dev --optimize-autoloader --no-interaction
run php artisan migrate --force
run php artisan optimize
run php artisan queue:restart        # workers finish their job and start again with the new code
systemctl reload php8.3-fpm          # drops the opcache of the old code

echo "NightGuardian is now at: $(run git describe --tags --always)"
EOF
sudo chmod +x /usr/local/bin/nightguardian-update
```

From then on, updating is one command (it is the sequence `git pull`, `composer install --no-dev`,
`migrate --force`, `optimize`, plus restarting the worker):

```bash
sudo nightguardian-update            # or: sudo nightguardian-update v0.2.0
```

Read the `CHANGELOG.md` of the new version first. Events sent by your applications while it runs are answered normally
or, in the worst case, rejected with 5xx and kept by the SDK in its local spool until the collector is back.

## 11. Backups

Everything that matters is in the database (and your `.env`, which holds the application key that encrypts the alert
channel secrets: **keep a copy of it somewhere safe**, a restored database is useless without it).

```bash
sudo mkdir -p /var/backups/nightguardian && sudo chmod 700 /var/backups/nightguardian
sudo tee /usr/local/bin/nightguardian-backup > /dev/null <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
DIR=/var/backups/nightguardian
mysqldump --single-transaction --quick --routines nightguardian | gzip > "$DIR/nightguardian-$(date +%F).sql.gz"
cp /var/www/nightguardian/.env "$DIR/env-$(date +%F)"
find "$DIR" -type f -mtime +14 -delete
EOF
sudo chmod +x /usr/local/bin/nightguardian-backup
echo "30 2 * * * root /usr/local/bin/nightguardian-backup" | sudo tee /etc/cron.d/nightguardian-backup > /dev/null
sudo /usr/local/bin/nightguardian-backup && ls -lh /var/backups/nightguardian
```

(`mysqldump` runs as root through the local socket, which `mysql-server` allows by default.) Restore:

```bash
gunzip -c /var/backups/nightguardian/nightguardian-YYYY-MM-DD.sql.gz | sudo mysql nightguardian
```

Copy `/var/backups/nightguardian` off the server regularly (for example with `rsync` or your provider's snapshots).

## 12. Troubleshooting

| Symptom | Look here |
|---|---|
| White page or HTTP 500 | `sudo tail -n 50 /var/www/nightguardian/storage/logs/laravel.log` and `/var/log/apache2/nightguardian-error.log`. Usually permissions: `sudo chown -R www-data:www-data /var/www/nightguardian/storage /var/www/nightguardian/bootstrap/cache` |
| The SDK reports `HTTP 401` though the token is right | The `SetEnvIfNoCase ... HTTP_AUTHORIZATION` line of step 6 is missing, or `a2enmod setenvif` was not run |
| Alerts never arrive | `sudo systemctl status nightguardian-worker` and `sudo journalctl -u nightguardian-worker -n 50`. In the panel, the channel shows its *Last error* |
| Digests, thresholds or retention do nothing | `sudo crontab -u www-data -l` must contain the `schedule:run` line; `sudo -u www-data php artisan schedule:list` lists what runs |
| After editing `.env` nothing changes | `sudo -u www-data php artisan optimize` (the configuration is cached) and `sudo systemctl restart nightguardian-worker` |
| `composer install` runs out of memory | Add swap: `sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile` |
| Disk filling up | Retention deletes events older than each project's *Retention* (30 days by default); lower it in the project settings, or the database grows with your traffic |

Security checklist: `APP_DEBUG=false` (step 5), HTTPS (step 7), `.env` mode 640 and never committed, database user
limited to its own database (step 3), only ports 22, 80 and 443 open (step 2). Report vulnerabilities as described in
[SECURITY.md](../SECURITY.md).

---

## Trying it with Docker

For a quick trial (not for production): `docker-compose.yml` starts the web app, a queue worker, the scheduler and
MySQL. You need Docker with the Compose plugin.

```bash
git clone https://github.com/pablozagni/nightguardian.git
cd nightguardian
docker compose up -d --build
docker compose exec app php artisan nightguardian:install --skip-migrate
```

The last command asks for the admin name, e-mail and password. Then open <http://localhost:8080>.

- Put `DB_PASSWORD`, `APP_URL` (the address your applications will use) and, optionally, `APP_KEY` in a `.env` file next
  to `docker-compose.yml`. Without `APP_KEY`, one is generated once and kept in the `storage` volume.
- Services: `app` (runs the migrations on start), `worker` (queue), `scheduler` (`schedule:work`), `db`.
- Data lives in the `database` and `storage` volumes: `docker compose down` keeps it, `docker compose down -v` deletes it.
- To upgrade: `git pull && docker compose up -d --build`.
- For production use the steps above, or put your own reverse proxy with HTTPS in front of the `app` service.
