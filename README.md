# Marketplace Hub

Laravel-based integration platform for importing products from external sources (starting with Shopify), storing them in a normalized internal database, and exporting marketplace feeds (e.g. Varle.lt XML).

## Stack

| Component | Technology |
|-----------|------------|
| Framework | Laravel 13 |
| Database | PostgreSQL |
| Cache / sessions / queues | PostgreSQL (database driver, MVP) |
| Scheduler | Laravel scheduler |
| Admin panel | Filament 4 |

## Requirements

- PHP 8.4+
- Composer
- Node.js 20+ (for Vite assets)
- PostgreSQL 16+
- PHP extensions: `intl`, `pdo_pgsql`, `zip`

## Project structure

```
app/
├── Actions/              # Single-purpose application actions
├── DTO/                  # Data transfer objects
├── Enums/                # Domain enumerations
├── Jobs/                 # Queued background jobs
├── Services/
│   ├── Shopify/          # Shopify API integration
│   ├── Import/           # Generic import orchestration
│   ├── Export/           # Feed export orchestration
│   └── Marketplace/
│       └── Varle/        # Varle.lt XML feed generation
└── Filament/             # Admin panel resources
```

Integration settings live in `config/marketplace.php` and are driven by environment variables.

## Local PostgreSQL setup

This project uses **PostgreSQL only** — locally on your Mac and later on an Ubuntu VPS. There is no Supabase and no Redis requirement for the MVP.

### macOS (local development)

1. **Install PostgreSQL**

   Using Homebrew:

   ```bash
   brew install postgresql@16
   brew services start postgresql@16
   ```

   Add PostgreSQL to your PATH if needed (Homebrew prints the exact command after install).

2. **Create the database and role**

   ```bash
   psql postgres
   ```

   In the `psql` shell:

   ```sql
   CREATE USER marketplace_hub WITH PASSWORD 'your_local_password';
   CREATE DATABASE marketplace_hub OWNER marketplace_hub;
   GRANT ALL PRIVILEGES ON DATABASE marketplace_hub TO marketplace_hub;
   \q
   ```

   If you prefer peer authentication with no password (common on a local Mac), create the role matching your macOS username instead:

   ```sql
   CREATE USER marketplace_hub WITH LOGIN CREATEDB;
   CREATE DATABASE marketplace_hub OWNER marketplace_hub;
   ```

3. **Configure Laravel**

   ```bash
   cd "/Users/matas/Documents/Marketplace hub"
   cp .env.example .env
   php artisan key:generate
   ```

   Set your database credentials in `.env`:

   ```env
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=marketplace_hub
   DB_USERNAME=marketplace_hub
   DB_PASSWORD=your_local_password
   ```

   Leave `DB_PASSWORD` empty only if your local PostgreSQL role uses trust/peer auth.

4. **Run migrations**

   Migrations included in this project create:

   - `users`, `password_reset_tokens`, `sessions`
   - `cache`, `cache_locks`
   - `jobs`, `job_batches`, `failed_jobs`

   ```bash
   php artisan migrate
   ```

5. **Create an admin user and start the app**

   ```bash
   php artisan make:filament-user
   php artisan serve
   ```

   In a second terminal, run the queue worker:

   ```bash
   php artisan queue:work database
   ```

   Open [http://localhost:8000/admin](http://localhost:8000/admin).

   **Important:** run `php artisan serve` on your Mac — do not use the Docker `app` container if `.env` has `DB_HOST=127.0.0.1`. Inside Docker, `127.0.0.1` is the container itself, not your Mac's PostgreSQL. Either run PHP locally (recommended above) or set `DB_HOST=postgres` when using `docker compose up`.

### Ubuntu VPS (production / staging)

Production target example: `https://hub.gudle.lt`

#### 1. Required server packages

```bash
sudo apt update
sudo apt install -y \
  nginx \
  postgresql postgresql-contrib \
  php8.4-fpm php8.4-cli \
  php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-intl php8.4-bcmath php8.4-gd \
  git unzip curl supervisor certbot python3-certbot-nginx
```

**PHP extensions required:** `pdo_pgsql`, `mbstring`, `xml`, `curl`, `zip`, `intl`, `bcmath`, `gd`, `openssl`

Install Composer globally if needed:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

#### 2. PostgreSQL setup

```bash
sudo -u postgres psql
```

```sql
CREATE USER marketplace_hub WITH PASSWORD 'strong_production_password';
CREATE DATABASE marketplace_hub OWNER marketplace_hub;
GRANT ALL PRIVILEGES ON DATABASE marketplace_hub TO marketplace_hub;
\q
```

#### 3. Deploy application code

```bash
sudo mkdir -p /var/www/marketplace-hub
sudo chown -R $USER:www-data /var/www/marketplace-hub
cd /var/www/marketplace-hub
git clone <your-repo-url> .
```

Copy production environment file and generate app key:

```bash
cp .env.production.example .env
php artisan key:generate
```

Edit `.env` and set at minimum:

- `APP_URL=https://hub.gudle.lt`
- `DB_PASSWORD`
- `SHOPIFY_CLIENT_ID`
- `SHOPIFY_CLIENT_SECRET`

See `.env.production.example` for the full production template.

#### 4. Production deployment commands

Run on every deploy:

```bash
cd /var/www/marketplace-hub

composer install --no-dev --optimize-autoloader
php artisan marketplace:deploy --with-composer
php artisan make:filament-user   # first deploy only
php artisan db:seed --class=AutomationScheduleSeeder   # optional
php artisan marketplace:health-check
```

`marketplace:deploy` runs:

```bash
php artisan migrate --force
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Preview deploy steps without executing:

```bash
php artisan marketplace:deploy --dry-run
```

#### 5. Storage link and feed directories

Public feeds and CSV exports are served from `storage/app/public`:

```bash
php artisan storage:link
```

The app also auto-creates these directories on boot if missing:

- `storage/app/public/feeds`
- `storage/app/public/exports`

#### 6. File permissions

```bash
cd /var/www/marketplace-hub
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

#### 7. Nginx config

Create `/etc/nginx/sites-available/marketplace-hub`:

```nginx
server {
    listen 80;
    server_name hub.gudle.lt;
    root /var/www/marketplace-hub/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable site and reload:

```bash
sudo ln -s /etc/nginx/sites-available/marketplace-hub /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

#### 8. SSL (Let's Encrypt)

```bash
sudo certbot --nginx -d hub.gudle.lt
```

Certbot updates Nginx for HTTPS and auto-renewal. Verify:

```bash
sudo certbot renew --dry-run
```

#### 9. Queue worker (Supervisor)

Create `/etc/supervisor/conf.d/marketplace-hub-worker.conf`:

```ini
[program:marketplace-hub-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/marketplace-hub/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/marketplace-hub/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start marketplace-hub-worker:*
```

#### 10. Laravel scheduler (cron)

Cron runs every minute. Actual Shopify/Varle sync time is configured in admin (**Automation → Schedules**).

```cron
* * * * * cd /var/www/marketplace-hub && php artisan schedule:run >> /dev/null 2>&1
```

Add with:

```bash
sudo crontab -u www-data -e
```

Local scheduler testing:

```bash
php artisan schedule:work
```

Seed default disabled schedule:

```bash
php artisan db:seed --class=AutomationScheduleSeeder
```

#### 11. Health checks

HTTP health endpoint (for uptime monitors / load balancers):

```bash
curl https://hub.gudle.lt/health
```

Example response:

```json
{
  "status": "ok",
  "app": "Marketplace Hub",
  "time": "2026-06-30T12:00:00+00:00",
  "database": "ok"
}
```

Detailed server-side check:

```bash
php artisan marketplace:health-check
```

Checks database, writable storage, feed path, Shopify credentials, and latest sync jobs.

Laravel also exposes `/up` via the framework health route.

#### 12. Post-deploy verification

```bash
php artisan marketplace:health-check
curl -I https://hub.gudle.lt/admin/login
curl -I https://hub.gudle.lt/feeds/varle.xml
php artisan shopify:test-connection
```

Open admin: `https://hub.gudle.lt/admin`

## Environment variables

| Variable | Purpose |
|----------|---------|
| `DB_*` | PostgreSQL connection |
| `QUEUE_CONNECTION=database` | Queue driver (MVP) |
| `CACHE_STORE=database` | Cache driver (MVP) |
| `SESSION_DRIVER=database` | Session driver (MVP) |
| `SHOPIFY_SHOP` | Shopify myshopify.com domain |
| `SHOPIFY_CLIENT_ID` | Shopify app client ID |
| `SHOPIFY_CLIENT_SECRET` | Shopify app client secret |
| `SHOPIFY_API_VERSION` | Shopify API version |
| `VARLE_FEED_PATH` | Relative path under `storage/app/public` for Varle XML |
| `VARLE_FEED_PUBLIC_URL` | Public URL for the Varle feed |
| `APP_STORE_URL` | Store URL used in exported feeds |

See `.env.example` for local development and `.env.production.example` for production/staging.

## Optional: Docker (PostgreSQL only)

If you prefer Docker for local development:

```bash
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan make:filament-user
```

When using Docker, set `DB_HOST=postgres` and `DB_PASSWORD=secret` in `.env` (Docker Compose creates the `marketplace_hub` user with password `secret`).

## Useful commands

```bash
# Run tests
composer test

# Seed default automation schedule (disabled by default)
php artisan db:seed --class=AutomationScheduleSeeder

# Run daily sync manually
php artisan marketplace:daily-sync

# Process due automation schedules once
php artisan marketplace:run-due-schedules

# Production deployment checklist
php artisan marketplace:deploy --dry-run
php artisan marketplace:deploy --with-composer

# Production health check
php artisan marketplace:health-check

# HTTP health endpoint
curl http://127.0.0.1:8000/health

# Run scheduler locally (instead of cron)
php artisan schedule:work

# Clear caches
php artisan optimize:clear

# Tail logs
php artisan pail

# Start all dev processes (server, queue, logs, Vite)
composer dev
```

## Roadmap

- Shopify product import
- Normalized product catalog schema
- Varle.lt XML feed export
- Supplier CSV/XML imports
- Additional marketplace connectors
- Translations and status dashboards

## License

MIT
