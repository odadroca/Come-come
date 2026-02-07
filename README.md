# Come-Come v0.200 — Family Meal Tracking System

**For neuro-divergent children. Frictionless logging, visibility without gamification.**

> 🇬🇧 English: "Eat-Eat" | 🇵🇹 Português: "Come-Come"

---

## Overview

Come-Come is a Progressive Web Application (PWA) designed to help families track meals, medications, and weight for neuro-divergent children. The system prioritizes:

- **Simplicity** — Minimal cognitive load, streamlined food catalog
- **Visibility** — Guardians can review and export detailed reports
- **Privacy** — Self-hosted, PIN-based authentication, no external tracking
- **Internationalization** — Full EN-UK and PT-PT support

---

## Features

### Core Functionality
- 🍽️ **Meal Logging** — 6 configurable meal templates with quantity sliders (0-5 range)
- 💊 **Medication Tracking** — Status logging (taken/missed/skipped) with timestamps
- ⚖️ **Weight Monitoring** — Daily weight logs with auto-void on same-day updates
- 📊 **PDF Reports** — Exportable reports for clinician review

### User Management
- 👶 **Child Accounts** — Simplified interface for self-logging
- 👫 **Guardian Accounts** — Full access to tools, settings, and review
- 🔐 **PIN Authentication** — 4-digit numeric PINs with lockout protection
- 🌐 **Guest Tokens** — Time-limited read-only access for clinicians

### Guardian Tools
- ✅ **Meal Review** — Approve logged meals with audit trail
- 📋 **Food Catalog** — Simplified 5-item catalog (Soup, Main, Dessert, Drink, Snack)
- 💾 **Backup & Restore** — Database snapshots with download/restore
- 🔧 **Database Maintenance** — VACUUM optimization, statistics

### Internationalization (i18n)
- 🇬🇧 English (UK) — "Eat-Eat"
- 🇵🇹 Portuguese (PT) — "Come-Come"
- 📝 **200+ translation keys** — Complete UI coverage
- 🔄 **Live locale switching** — No page reload required
- 🏷️ **Template translation keys** — Meal names translate automatically

---

## Technical Stack

| Component | Technology |
|-----------|------------|
| Frontend | Vanilla JS PWA, Pico CSS |
| Backend | PHP 8.0+ |
| Database | SQLite 3.35+ |
| Server | Apache with mod_rewrite |

---

## Installation

### Requirements
- PHP 8.0 or higher
- SQLite 3.35 or higher
- Apache with mod_rewrite enabled
- Write permissions for `data/` directory

### Quick Start

1. **Upload files** to your web server
2. **Navigate to** `/install.php`
3. **Create first guardian** account with PIN
4. **Add a child** in Guardian Tools → User Management
5. **Start logging** meals

### Deployment Scripts

```bash
# VPS deployment (Ubuntu/Debian)
bash deploy/install-vps.sh

# Home server deployment
bash deploy/install-homeserver.sh
```

---

## Database Schema (Simplified v2)

### Key Tables

| Table | Purpose |
|-------|---------|
| `users` | Authentication (guardian/child/guest) |
| `children` | Child profiles linked to user accounts |
| `meal_templates` | 6 default meals with translation keys |
| `food_catalog` | Simplified 5-item catalog |
| `meal_logs` | Daily meal records |
| `food_quantities` | Food amounts per meal |
| `medication_logs` | Medication status tracking |
| `weight_logs` | Weight measurements |
| `i18n` | Translation key-value pairs |

### Meal Templates (Seeded)

| ID | Name (PT) | Translation Key | Icon |
|----|-----------|-----------------|------|
| 1 | Pequeno Almoço | meal.breakfast | 🍞 |
| 2 | Lanche da Manhã | meal.morning_snack | 🍎 |
| 3 | Almoço | meal.lunch | 🍝 |
| 4 | Lanche da Tarde | meal.afternoon_snack | 🍪 |
| 5 | Jantar | meal.dinner | 🍛 |
| 6 | Lanche da Noite | meal.night_snack | 🥛 |

### Food Catalog (Simplified)

| ID | Name | Category |
|----|------|----------|
| 1 | Soup | starter |
| 2 | Main | main |
| 3 | Dessert | dessert |
| 4 | Drink | drink |
| 5 | Snack | snack |

---

## Security

### Authentication
- 4-digit PIN-based login
- Session tokens with 7-day sliding expiry
- Rate limiting: 5 auth attempts per 5 minutes
- Guardian lockout: 5 failed attempts → 5-minute cooldown

### Data Protection
- SQLite database stored outside web root
- Prepared statements for SQL injection prevention
- CSRF protection on state-changing operations
- Audit logging for all sensitive actions

### Access Control
- Role-based permissions (guardian/child/guest)
- Child accounts: Limited to meal logging
- Guest tokens: Read-only, time-limited

---

## API Endpoints

### Authentication
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/auth/login` | Login with user_id + PIN |
| POST | `/auth/logout` | End session |
| GET | `/auth/whoami` | Current session info |

### Meals
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/meals/{child_id}/{date}` | Get meals for date |
| POST | `/meals` | Log new meal |
| POST | `/meals/{id}/review` | Mark meal reviewed |
| POST | `/meals/{id}/void` | Void meal entry |

### Catalog
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/catalog/templates` | Get meal templates |
| GET | `/catalog/foods` | Get food catalog |

### Reports
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/report/{child_id}` | Generate PDF report |
| GET | `/history/{child_id}/{start}/{end}` | Get date range history |

---

## Project Structure

```
comecome-v0.200/
├── config/
│   └── config.php          # Application configuration
├── data/                   # Database storage (created at install)
├── deploy/
│   ├── install-vps.sh      # VPS deployment script
│   └── install-homeserver.sh
├── docs/
│   ├── change_log.md       # Version history
│   ├── exec_architecture.md # System architecture
│   ├── file_registry.md    # File inventory
│   └── security.md         # Security documentation
├── public/
│   ├── assets/
│   │   ├── app.js          # Main application (~2200 lines)
│   │   ├── styles.css      # Custom styles
│   │   └── manifest.json   # PWA manifest
│   ├── app.html            # Main SPA template
│   ├── index.php           # API router
│   ├── install.php         # First-time setup
│   └── .htaccess           # URL rewriting
├── sql/
│   └── schema.sql          # Database schema + seed data
├── src/
│   ├── api.php             # API handlers
│   ├── auth.php            # Authentication
│   ├── backup.php          # Backup/restore
│   ├── db.php              # Database wrapper
│   ├── i18n.php            # Internationalization
│   └── pdf.php             # PDF generation
├── tests/
│   └── run-tests.php       # Test suite
└── README.md               # This file
```

---

## Version History

### v0.200 — Sprint 20 (Current)
- **Simplified schema** — 5-item food catalog for reduced cognitive load
- **i18n meal remediation** — Meal templates use translation keys
- **Localized buttons** — Review/Void buttons now translated
- **History i18n** — Medication status translated in history view
- **New translation keys** — meal.review, meal.void, meal.pending, meal.default

### v0.190 — Sprint 19
- Dynamic JavaScript strings translated (100+ replacements)
- Error/success messages use t() function
- Confirmation dialogs localized

### v0.180 — Sprint 18
- HTML static strings with data-i18n attributes
- applyTranslations() function for DOM updates
- Live locale switching

### v0.170 — Sprint 17
- Token filter (show 3 by default)
- Slider UI for meal quantities (0-5 range)

### v0.160 — Sprint 16
- Medication visibility configuration
- child_sees_medications toggle

### v0.150 — Sprint 15
- Expired token detection
- Guardian tool header highlight

### v0.110-0.140 — Sprints 11-14
- 16 bug fixes from E2E testing
- History functionality restored
- PDF generation fixed
- Guest URL routing corrected

---

## Migration from v0.190

For existing installations:

```sql
-- Add translation_key column
ALTER TABLE meal_templates ADD COLUMN translation_key TEXT;

-- Update default templates
UPDATE meal_templates SET translation_key = 'meal.breakfast' WHERE id = 1;
UPDATE meal_templates SET translation_key = 'meal.morning_snack' WHERE id = 2;
UPDATE meal_templates SET translation_key = 'meal.lunch' WHERE id = 3;
UPDATE meal_templates SET translation_key = 'meal.afternoon_snack' WHERE id = 4;
UPDATE meal_templates SET translation_key = 'meal.dinner' WHERE id = 5;
UPDATE meal_templates SET translation_key = 'meal.night_snack' WHERE id = 6;
```

Then add new i18n keys from schema.sql (meal.review, meal.void, meal.pending, meal.default).

---

## Development

### Running Tests

```bash
cd tests
php run-tests.php
```

### Adding New Translations

1. Add key to `sql/schema.sql` for both EN-UK and PT-PT
2. Use `this.t('key.name')` in app.js
3. For HTML elements, add `data-i18n="key.name"` attribute

---

## License

Private project. All rights reserved.

---

## Support

For issues or feature requests, contact the development team.
