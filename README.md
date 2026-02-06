# Come-Come v0.170 — Sprint 17 (Token Filter + Slider UI)

Family meal tracking system for neuro-divergent children. Frictionless logging, visibility without gamification.

## Features (Sprint 17)

✅ SQLite database with schema and seed data  
✅ PIN-based authentication (4-digit numeric)  
✅ Session management (7-day sliding window)  
✅ Rate limiting (auth: 5/5min, API: 100/min, guest: 50/min)  
✅ Lockout policy (guardians: 5 attempts, 5-minute cooldown)  
✅ Audit logging (append-only)  
✅ First-time installation script  
✅ Multi-language support (EN-UK, PT-PT)  
✅ Meal logging API (GET, POST, PATCH, review, void)  
✅ Food catalog management (GET, POST, PATCH, block, delete)  
✅ Weight logging with auto-void  
✅ PWA interface (login, meal cards, food input)  
✅ i18n backend + frontend support  
✅ Comprehensive documentation (4 files)  
✅ Medication logging (GET, POST, status: taken/missed/skipped)  
✅ Guest token system (create, list, revoke, time-limited)  
✅ PDF report generation (weight, medication, meals, intake)  
✅ Guardian UI (medication form, token management, PDF export)  
✅ Complete i18n UI strings (130+ translations, EN-UK + PT-PT)  
✅ Backup & restore system (create, download, restore, auto-backup)  
✅ Database maintenance (VACUUM, statistics, cleanup)  
✅ Automated deployment scripts (VPS + home server)  
✅ Test suite (24 unit tests, validation, regression, security)  
✅ All Sprint 11-14 bug fixes (16 bugs resolved)  
✅ Token expiry detection — expired tokens show "Expired" label  
✅ Guardian tool header highlight when expanded  
✅ Medication visibility configuration — guardian toggle  
✅ **Token filter — shows 3 most recent by default, "Show all" option**  
✅ **Slider UI for meal quantities — 0-5 range with fraction display, overflow input for >5**

### Post-E2E Remediation Summary

| Sprint | Theme | Bugs Fixed |
|--------|-------|------------|
| 11 | Critical path fixes | B01, B04, B05, B06, B16 |
| 12 | Locale & state fixes | B02, B03, B12, B13 |
| 13 | Defensive fixes | B07, B09, B10, B11 |
| 14 | Housekeeping | B14, B17, B18 |

**Deferred to future feature sprints:**
- B08 — Per-child food/medication visibility CRUD
- B15 — i18n UI rendering with t() calls  

## Requirements

- PHP 8.1+ with extensions:
  - `pdo_sqlite`
  - `mbstring`
- SQLite 3.35+
- HTTPS (production)
- 500MB disk space

## Installation

### Option 1: Shared Hosting (cPanel)

**Prerequisites:** Hosting with PHP 8.1+, SQLite support, HTTPS (Let's Encrypt).

**Steps:**

1. **Download release**
   ```
   comecome-v0.01.zip
   ```

2. **Extract locally**
   - Windows: Right-click → Extract All
   - Mac: Double-click zip file

3. **Connect via FTP** (FileZilla)
   - Host: `ftp.yourdomain.com`
   - Username/password: from cPanel

4. **Upload files**
   - `public/` contents → `/public_html/` (or `/httpdocs/`)
   - `src/`, `config/`, `sql/`, `data/` → `/home/username/comecome/` (OUTSIDE web root)

5. **Edit configuration**
   - cPanel File Manager → `/home/username/comecome/config/config.php`
   - Set `DB_PATH` to `/home/username/comecome/data/comecome.db`
   - Set `REQUIRE_HTTPS = true`
   - Change `UNLOCK_CODE` to random 8 digits

6. **Run installer**
   - Visit `https://yourdomain.com/install.php`
   - Enter guardian name and 4-digit PIN
   - Installer deletes itself after success

7. **Enable SSL** (if not already enabled)
   - cPanel → SSL/TLS → Let's Encrypt → Install certificate

8. **Test**
   - Visit `https://yourdomain.com`
   - Log in with guardian PIN

### Option 2: VPS (Ubuntu 24.04)

**Stack:** nginx + PHP 8.3-FPM + SQLite + Certbot

```bash
# 1. Install dependencies
sudo apt update
sudo apt install -y nginx php8.3-fpm php8.3-sqlite3 php8.3-mbstring \
                    certbot python3-certbot-nginx git

# 2. Clone repository
cd /var/www
sudo git clone https://github.com/youruser/comecome.git
sudo chown -R www-data:www-data comecome/

# 3. Configure nginx
sudo nano /etc/nginx/sites-available/comecome
```

Paste this configuration:

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    
    root /var/www/comecome/public;
    index index.php;
    
    # Deny access to sensitive files
    location ~ /(config|data|sql|src) {
        deny all;
        return 404;
    }
    
    # Serve static files
    location /assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
    
    # Route all requests through index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # PHP-FPM
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

```bash
# 4. Enable site
sudo ln -s /etc/nginx/sites-available/comecome /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx

# 5. Set up SSL
sudo certbot --nginx -d yourdomain.com

# 6. Create data directory
sudo mkdir -p /var/www/comecome/data
sudo chown www-data:www-data /var/www/comecome/data
sudo chmod 750 /var/www/comecome/data

# 7. Run installer
# Visit https://yourdomain.com/install.php in browser

# 8. Set up backups (optional)
sudo crontab -e -u www-data
# Add: 0 2 * * * cp /var/www/comecome/data/comecome.db \
#                  /var/www/comecome/data/backups/comecome_$(date +\%Y\%m\%d).db
```

### Option 3: Home Server (Raspberry Pi + Caddy)

**Stack:** Caddy (auto HTTPS) + PHP 8.1-FPM + SQLite + Dynamic DNS

```bash
# 1. Install Caddy
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/setup.deb.sh' | sudo bash
sudo apt install -y caddy php8.1-fpm php8.1-sqlite3 php8.1-mbstring

# 2. Clone repository
cd /var/www
sudo git clone https://github.com/youruser/comecome.git
sudo chown -R www-data:www-data comecome/

# 3. Configure Caddy
sudo nano /etc/caddy/Caddyfile
```

Add:

```
yourdomain.duckdns.org {
    root * /var/www/comecome/public
    php_fastcgi unix//run/php/php8.1-fpm.sock
    file_server
    
    @notpublic {
        path /config/* /data/* /sql/* /src/*
    }
    respond @notpublic 404
}
```

```bash
# 4. Restart Caddy
sudo systemctl restart caddy

# 5. Set up dynamic DNS (DuckDNS)
# Visit https://www.duckdns.org → Create account → Add domain
# Add to cron:
# */5 * * * * curl "https://www.duckdns.org/update?domains=yourdomain&token=YOUR_TOKEN"

# 6. Port forwarding
# Router admin → Forward port 443 → Pi's local IP (192.168.1.x)

# 7. Run installer
# Visit https://yourdomain.duckdns.org/install.php
```

## Troubleshooting

### "Database file not writable"
```bash
# Check permissions
ls -la /path/to/comecome/data/

# Fix permissions
chmod 640 comecome.db
chown www-data:www-data comecome.db
```

### "HTTPS required"
Enable SSL certificate (Let's Encrypt) or temporarily disable HTTPS check:
```php
// config/config.php
define('REQUIRE_HTTPS', false); // INSECURE - testing only
```

### "Rate limit exceeded"
Wait 5 minutes or clear rate limits:
```bash
sqlite3 /path/to/comecome.db "DELETE FROM rate_limits;"
```

### "Account locked"
Wait 5 minutes for automatic unlock, or use emergency unlock code:
```
# POST /auth/unlock with {user_id, pin, unlock_code}
```

## API Endpoints (Sprint 1–5)

### Authentication

**POST** `/auth/login`
```json
{
  "role": "guardian",
  "user_id": 1,
  "pin": "1234"
}
```

Response:
```json
{
  "session_token": "a3f7c2e1...",
  "user": {
    "id": 1,
    "role": "guardian",
    "locale": "en-UK",
    "profile": {"id": 1, "name": "Parent Name"}
  },
  "children": [{"id": 1, "name": "Child Name", "active": 1}]
}
```

**POST** `/auth/logout`

Response:
```json
{"success": true}
```

**GET** `/health`

Response:
```json
{
  "status": "ok",
  "version": "0.01",
  "database": true,
  "schema_version": 1
}
```

**GET** `/children`  
*Requires: guardian auth*

Response:
```json
[
  {"id": 1, "name": "Child Name", "active": 1, "created_at": "2026-02-05 10:00:00", "locale": "en-UK"}
]
```

### User Management (Sprint 5)

**GET** `/users` — List all users (guardian only)  
**GET** `/users/:id` — Get single user (guardian only)  
**POST** `/users/child` — Create child `{name, pin, locale?}`  
**POST** `/users/guardian` — Create guardian `{name, pin, locale?}`  
**PATCH** `/users/:id` — Edit user `{name?, locale?}`  
**POST** `/users/:id/pin` — Change PIN `{current_pin, new_pin}`  
**POST** `/users/:id/pin/reset` — Reset PIN `{new_pin}` (guardian override)  
**POST** `/users/:id/block` — Block/deactivate user  
**POST** `/users/:id/unblock` — Unblock/reactivate user  
**DELETE** `/users/:id` — Delete user (if no historical data)  

### Food Catalog (Sprint 5 additions)

**GET** `/catalog/foods/all` — List all foods including blocked (guardian only)  
**POST** `/catalog/foods/:id/unblock` — Unblock food item  

### Medication Catalog (Sprint 6)

**GET** `/catalog/medications` — List active medications (guardian only)  
**GET** `/catalog/medications/all` — List all medications including blocked (guardian only)  
**GET** `/catalog/medications/:id` — Get single medication (guardian only)  
**POST** `/catalog/medications` — Create medication `{name, dose, notes?}`  
**PATCH** `/catalog/medications/:id` — Edit medication `{name?, dose?, notes?}`  
**POST** `/catalog/medications/:id/block` — Block medication  
**POST** `/catalog/medications/:id/unblock` — Unblock medication  
**DELETE** `/catalog/medications/:id` — Delete medication (if no log references)  

### History (Sprint 8)

**GET** `/history/:childId/:startDate/:endDate` — Combined history (meals+meds+weights) for date range (max 90 days)  

### Meal Templates (Sprint 9)

**GET** `/catalog/templates` — List active meal templates (any auth)  
**GET** `/catalog/templates/all` — List all templates including blocked (guardian only)  
**GET** `/catalog/templates/:id` — Get single template (any auth)  
**POST** `/catalog/templates` — Create template `{name, icon?, sort_order?}`  
**PATCH** `/catalog/templates/:id` — Edit template `{name?, icon?, sort_order?}`  
**POST** `/catalog/templates/reorder` — Bulk reorder `{order: [{id, sort_order}]}`  
**POST** `/catalog/templates/:id/block` — Block template  
**POST** `/catalog/templates/:id/unblock` — Unblock template  
**DELETE** `/catalog/templates/:id` — Delete template (if no log references)  

### Future (v1.0+)

### i18n & Locale (Sprint 10)

**GET** `/i18n/locales` — List supported locales and default (any auth)  
**GET** `/i18n/translations/:locale` — Get all translations for locale (any auth)  
**POST** `/i18n/translations` — Add/update translation `{locale, key, value}` (guardian only)  
**POST** `/i18n/locale` — Switch user's locale `{locale}` (any auth)  

### Future (v1.1+)

- Offline support with service worker + IndexedDB
- Multi-household tenancy
- Advanced food database integration
- Calorie counting
- Photo logging
- Push notifications
- CSV/Excel export
- Mobile app (React Native or Flutter)

## Security Notes

### PIN Storage
- PINs hashed with bcrypt (cost=12)
- Never logged in plaintext
- Guardian lockout after 5 failed attempts (5-minute cooldown)
- Children: no lockout (unlimited retries)

### Session Management
- 64-char hex tokens (random_bytes(32))
- httpOnly cookies (XSS protection)
- Secure flag (HTTPS only)
- SameSite=Lax (CSRF mitigation)
- 7-day sliding window (auto-extends on activity)

### Rate Limiting
- Auth endpoints: 5 requests / 5 minutes per IP
- API endpoints: 100 requests / 1 minute per IP
- Guest endpoints: 50 requests / 1 minute per IP (Sprint 3)

### Emergency Unlock
If both guardians locked:
1. Wait 5 minutes (auto-expires)
2. OR use unlock code (set in `config/config.php`)

## Database Schema

### Core Tables
- `users` — Accounts (guardian, child, guest)
- `children` — Child profiles
- `guardians` — Guardian profiles
- `sessions` — Active sessions
- `audit_log` — Append-only action log
- `rate_limits` — Request throttling

### Master Data
- `food_catalog` — Food items (25 seeded)
- `meal_templates` — Meal types (6 seeded)
- `meal_template_foods` — Food slots in templates
- `medications` — Medication definitions
- `child_meal_blocks` — Per-child visibility
- `child_medication_blocks` — Per-child visibility

### Historical Data (Sprint 2+)
- `meal_logs` — Daily meal entries
- `food_quantities` — Food amounts in meals
- `weight_logs` — Daily weight tracking
- `medication_logs` — Medication adherence

### Localization
- `i18n` — Translation strings (EN-UK, PT-PT)
- `settings` — System config

## Maintenance

### Backups
```bash
# Manual backup
cp /path/to/comecome.db /path/to/backup_$(date +%Y%m%d).db

# Automated (add to cron)
0 2 * * * cp /path/to/comecome.db /path/to/backups/comecome_$(date +\%Y\%m\%d).db
```

### Vacuum (reclaim space)
```bash
# Weekly maintenance
sqlite3 /path/to/comecome.db "VACUUM;"
```

### Clean old sessions
```bash
sqlite3 /path/to/comecome.db "DELETE FROM sessions WHERE expires_at < datetime('now');"
```

### Clean old rate limits
```bash
sqlite3 /path/to/comecome.db "DELETE FROM rate_limits WHERE window_start < strftime('%s', 'now') - 3600;"
```

## File Structure

```
comecome-v0.01/
├── config/
│   └── config.php          # Configuration constants
├── data/
│   ├── comecome.db         # SQLite database (created by installer)
│   ├── backups/            # Auto backups
│   └── logs/               # Error logs
├── public/                 # Web root (ONLY this exposed via HTTP)
│   ├── index.php           # Entry point
│   ├── install.php         # First-time setup
│   ├── .htaccess           # URL rewriting
│   └── assets/             # Static files (Sprint 2+)
├── sql/
│   └── schema.sql          # Database schema + seed data
├── src/
│   ├── db.php              # PDO wrapper
│   ├── auth.php            # Authentication system
│   ├── api.php             # API handlers (Sprint 2+)
│   ├── pdf.php             # PDF generation (Sprint 3)
│   └── i18n.php            # Localization (Sprint 2+)
└── README.md               # This file
```

## Changelog

### v0.01 (Sprint 1) — Foundation
- SQLite schema with 15 tables
- Seed data: 6 meal templates, 25 foods
- PIN authentication (bcrypt, cost=12)
- Session management (7-day sliding window)
- Rate limiting (5/5min auth, 100/min API)
- Guardian lockout (5 attempts, 5-minute cooldown)
- Audit logging (append-only)
- Installation script
- i18n foundation (EN-UK, PT-PT strings)
- Health check endpoint

## License

Open source (FOSS). License TBD.

## Support

GitHub Issues: (repository URL TBD)

## Roadmap

- ✅ **Sprint 1:** Foundation (database, auth, install)
- 🚧 **Sprint 2:** Core features (meals, foods, weights, PWA UI)
- 🚧 **Sprint 3:** Guardian features (medications, tokens, PDF export)
- 🚧 **Sprint 4:** Polish (i18n UI, deployment docs, testing)
