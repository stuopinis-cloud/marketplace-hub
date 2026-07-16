# Production queue worker (systemd)

Marketplace Hub must process long-running work only through the Laravel queue.

## Worker command

```bash
php artisan queue:work database --sleep=3 --tries=1 --timeout=7200 --memory=512
```

`--timeout=7200` covers the daily marketplace sync job timeout.

## systemd unit

`/etc/systemd/system/marketplace-hub-queue.service`:

```ini
[Unit]
Description=Marketplace Hub queue worker
After=network.target postgresql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=3
WorkingDirectory=/var/www/marketplace-hub
ExecStart=/usr/bin/php /var/www/marketplace-hub/artisan queue:work database --sleep=3 --tries=1 --timeout=7200 --memory=512
KillMode=process
TimeoutStopSec=7300

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now marketplace-hub-queue
sudo journalctl -u marketplace-hub-queue -f
```

## Scheduler cron

Keep schedule:run fast. It only dispatches queue jobs / stuck detection:

```cron
* * * * * cd /var/www/marketplace-hub && php artisan schedule:run >> /dev/null 2>&1
```

## Verify

```bash
php artisan schedule:list
php artisan queue:monitor
# Admin dashboard → Job health widget
```
