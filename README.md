# Come-Come v0.210 — Family Meal Tracking System

**For neuro-divergent children. Frictionless logging, visibility without gamification.**

> 🇬🇧 English: "Eat-Eat" | 🇵🇹 Português: "Come-Come"

---

## Overview

Come-Come is a Progressive Web Application (PWA) designed to help families track meals, medications, and weight for neuro-divergent children. The system prioritizes:

- **Simplicity** — Minimal cognitive load, streamlined food catalog (5 items)
- **Visibility** — Guardians can review and export detailed reports
- **Privacy** — Self-hosted, PIN-based authentication, no external tracking
- **Internationalization** — Full EN-UK and PT-PT support (~390 translation keys)

---

## Features

### Core Functionality
- 🍽️ **Meal Logging** — 6 configurable meal templates with quantity sliders (0-5 range)
- 💊 **Medication Tracking** — Status logging (taken/missed/skipped) with timestamps; visible to guardian
- ⚖️ **Weight Monitoring** — Daily weight logs with auto-void on same-day updates
- 📊 **PDF Reports** — Exportable reports for clinician reviews

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
- 📝 **~390 translation keys** — Complete UI coverage (~98%)
- 🔄 **Live locale switching** — No page reload required
- 🏷️ **Translation keys** — Meal templates and food catalog items translate automatically

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
5. **Start logging** Child: meals. Guardian: meals, medication, weight.

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
| 1 | Breakfast | meal.breakfast | 🍞 |
| 2 | Morning snack | meal.morning_snack | 🍎 |
| 3 | Lunch | meal.lunch | 🍝 |
| 4 | Afternoon snack | meal.afternoon_snack | 🍪 |
| 5 | Dinner | meal.dinner | 🍛 |
| 6 | Night snack | meal.night_snack | 🥛 |

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
comecome-v0.210/
├── assets/
│   ├── app.js          	# Main application (~2200 lines)
│   ├── styles.css      	# Custom styles
│   └── manifest.json   	# PWA manifest
├── config/
│   └── config.php          # Application configuration
├── data/                   # Database storage (created at install)
├── deploy/
│   ├── install-vps.sh      # VPS deployment script
│   └── install-homeserver.sh
├── docs/
│   ├── change_log.md       # Version history
│   ├── code_of_conduct.md  # contribution rules and review expectations
│   ├── exec_architecture.md # System architecture
│   ├── file_registry.md    # File inventory
│   └── security.md         # Security documentation
├── sql/
│   └── schema.sql          # Database schema
│   └── data_seed.sql       # Data seed
├── src/
│   ├── api.php             # API handlers
│   ├── auth.php            # Authentication
│   ├── backup.php          # Backup/restore
│   ├── db.php              # Database wrapper
│   ├── pdf.php             # PDF generation
│   └── i18n.php            # Internationalization
├── tests/
│   └── run-tests.php  		# Test suite
├── .htaccess           	# URL rewriting
├── app.html         		# Main SPA template
├── index.php           	# API router
├── install.php         	# First-time setup
├── LICENSE.md           	# Apache License v2.0
└── README.md               # This file
```

---

## Version History

### v0.210 — Sprint 21 (Current)
- **Confirm dialogs i18n** — All 8 block/delete confirmations translated
- **Food catalog i18n** — Foods now have translation_key for localized names
- **PIN reset modal** — Title now translated
- **UK spelling** — "Optimise" instead of "Optimize"
- **~98% i18n coverage** — 388 translation keys total

### v0.200 — Sprint 20
- **Simplified schema** — 5-item food catalog for reduced cognitive load
- **i18n meal remediation** — Meal templates use translation keys
- **Localized buttons** — Review/Void buttons now translated
- **History i18n** — Medication status translated in history view

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

## Migration from v0.200

For existing installations:

```sql
-- 1. Add translation_key column to food_catalog
ALTER TABLE food_catalog ADD COLUMN translation_key TEXT;

-- 2. Update default foods
UPDATE food_catalog SET translation_key = 'food.soup' WHERE id = 1;
UPDATE food_catalog SET translation_key = 'food.main' WHERE id = 2;
UPDATE food_catalog SET translation_key = 'food.dessert' WHERE id = 3;
UPDATE food_catalog SET translation_key = 'food.drink' WHERE id = 4;
UPDATE food_catalog SET translation_key = 'food.snack' WHERE id = 5;

-- 3. Add new confirm dialog keys (see schema.sql for full INSERT statements)
```

## Migration from v0.190

For installations on v0.190:

```sql
-- Add translation_key column to meal_templates
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
2. Use `this.t('key.name')` in app.js for dynamic strings
3. For HTML elements, add `data-i18n="key.name"` attribute
4. For master data (meals, foods), add `translation_key` column value

### i18n Key Categories

| Category | Example Key | Count |
|----------|-------------|-------|
| Error messages | `error.load_users` | ~56 |
| Success messages | `success.meal_logged` | ~36 |
| Confirm dialogs | `confirm.void_meal` | 12 |
| Meal templates | `meal.breakfast` | 6 |
| Food items | `food.soup` | 5 |
| Form labels | `form.select` | ~20 |
| UI elements | `user.edit` | ~40 |
| Other | Various | ~200+ |

---

## License (Apache 2.0)
This project is licensed under the **Apache License 2.0** (see `LICENSE.md`).

### Key Conditions
- License and copyright notice
- State changes
- Disclose source

### Limitations
- Trademark use
- Liability
- Warranty

## Contact
- odadroca@acordado.addy.io

## Acknowledgments
- Contributors: my family
- Inspirations: my oldest son and his struggle eat (at all), his inability to remember what he ate (or not), at school
- Libraries / tools:
  - PHP (built-in networking)
  - SQLite
  - Pico CSS (CDN build, used for UI styling)

---

## Contributing
- Fork the Repository
- Create a Feature Branch
- Commit Your Changes
- Push to the Branch
- Open a Pull Request

## Contribution Guidelines
- Follow our Code of Conduct (`docs/code_of_conduct.md`)
- Ensure changes do not break existing behaviors (no automated test suite shipped yet)
- Follow project coding standards (keep changes minimal, consistent, and readable)
- Provide clear, concise documentation for new features

---

## Support

For issues or feature requests, contact the developer.

---

**Version:** 0.210  
**Sprint:** 21  
**i18n Coverage:** ~98%
