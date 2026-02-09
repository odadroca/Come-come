-- Come-Come Database Schema v1.0
-- SQLite 3.35+ required

-- Schema version tracking
CREATE TABLE IF NOT EXISTS schema_version (
    version INTEGER PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO schema_version (version) VALUES (2);

-- System settings
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

INSERT INTO settings (key, value) VALUES 
    ('default_locale', 'en-UK'),
    ('app_name', 'Come-Come'),
    ('child_sees_medications', 'false');

-- Users (guardians, children, guests)
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role TEXT NOT NULL CHECK(role IN ('guardian', 'child', 'guest')),
    pin_hash TEXT,
    locale TEXT DEFAULT 'en-UK',
    locked_until TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Children profiles
CREATE TABLE IF NOT EXISTS children (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    active INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);

-- Guardian profiles
CREATE TABLE IF NOT EXISTS guardians (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);

-- Food catalog (master data)
CREATE TABLE IF NOT EXISTS food_catalog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    translation_key TEXT,
    category TEXT NOT NULL CHECK(category IN ('starter', 'main', 'dessert', 'drink', 'snack')),
    blocked INTEGER DEFAULT 0,
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Meal templates (master data)
CREATE TABLE IF NOT EXISTS meal_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    translation_key TEXT,
    icon TEXT DEFAULT '🍽️',
    sort_order INTEGER NOT NULL,
    blocked INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Food slots in meal templates (many-to-many)
CREATE TABLE IF NOT EXISTS meal_template_foods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    meal_template_id INTEGER NOT NULL,
    food_catalog_id INTEGER NOT NULL,
    sort_order INTEGER NOT NULL,
    FOREIGN KEY (meal_template_id) REFERENCES meal_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (food_catalog_id) REFERENCES food_catalog(id) ON DELETE RESTRICT
);

-- Per-child meal template visibility
CREATE TABLE IF NOT EXISTS child_meal_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL,
    meal_template_id INTEGER NOT NULL,
    blocked INTEGER DEFAULT 1,
    UNIQUE(child_id, meal_template_id),
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    FOREIGN KEY (meal_template_id) REFERENCES meal_templates(id) ON DELETE CASCADE
);

-- Daily meal logs (historical data)
CREATE TABLE IF NOT EXISTS meal_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL,
    meal_template_id INTEGER NOT NULL,
    log_date DATE NOT NULL,
    note TEXT,
    reviewed_by INTEGER,
    reviewed_at TIMESTAMP,
    voided_at TIMESTAMP,
    created_by INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(child_id, meal_template_id, log_date),
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE RESTRICT,
    FOREIGN KEY (meal_template_id) REFERENCES meal_templates(id) ON DELETE RESTRICT,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
);

-- Food quantities in logged meals
CREATE TABLE IF NOT EXISTS food_quantities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    meal_log_id INTEGER NOT NULL,
    food_catalog_id INTEGER NOT NULL,
    quantity_decimal REAL NOT NULL CHECK(quantity_decimal >= 0),
    FOREIGN KEY (meal_log_id) REFERENCES meal_logs(id) ON DELETE CASCADE,
    FOREIGN KEY (food_catalog_id) REFERENCES food_catalog(id) ON DELETE RESTRICT
);

-- Weight logs (historical data)
CREATE TABLE IF NOT EXISTS weight_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL,
    log_date DATE NOT NULL,
    weight_kg REAL NOT NULL CHECK(weight_kg > 0),
    uom TEXT DEFAULT 'kg',
    voided_at TIMESTAMP,
    created_by INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
);

-- Unique constraint for non-voided weight logs
CREATE UNIQUE INDEX idx_weight_logs_unique ON weight_logs(child_id, log_date) 
WHERE voided_at IS NULL;

-- Medications (master data)
CREATE TABLE IF NOT EXISTS medications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    dose TEXT NOT NULL,
    notes TEXT,
    blocked INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Per-child medication visibility
CREATE TABLE IF NOT EXISTS child_medication_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL,
    medication_id INTEGER NOT NULL,
    blocked INTEGER DEFAULT 1,
    UNIQUE(child_id, medication_id),
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE
);

-- Medication logs (historical data)
CREATE TABLE IF NOT EXISTS medication_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL,
    medication_id INTEGER NOT NULL,
    log_date DATE NOT NULL,
    log_time TIME,
    status TEXT NOT NULL CHECK(status IN ('taken', 'missed', 'skipped')),
    notes TEXT,
    created_by INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE RESTRICT,
    FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
);

-- Guest/clinician sessions
CREATE TABLE IF NOT EXISTS guest_sessions (
    token TEXT PRIMARY KEY,
    child_id INTEGER NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP,
    created_by INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
);

-- Session management
CREATE TABLE IF NOT EXISTS sessions (
    token TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Rate limiting
CREATE TABLE IF NOT EXISTS rate_limits (
    ip_address TEXT NOT NULL,
    endpoint TEXT NOT NULL,
    window_start INTEGER NOT NULL,
    request_count INTEGER DEFAULT 1,
    PRIMARY KEY (ip_address, endpoint, window_start)
);

-- Audit log (append-only)
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,
    entity_id INTEGER,
    action TEXT NOT NULL,
    actor_id INTEGER,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    details_json TEXT
);

-- Internationalization
CREATE TABLE IF NOT EXISTS i18n (
    locale TEXT NOT NULL,
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    PRIMARY KEY (locale, key)
);

-- Indexes for query performance
CREATE INDEX idx_meal_logs_child_date ON meal_logs(child_id, log_date);
CREATE INDEX idx_food_quantities_meal ON food_quantities(meal_log_id);
CREATE INDEX idx_weight_logs_child_date ON weight_logs(child_id, log_date);
CREATE INDEX idx_medication_logs_child_date ON medication_logs(child_id, log_date);
CREATE INDEX idx_audit_log_timestamp ON audit_log(timestamp DESC);
CREATE INDEX idx_sessions_expires ON sessions(expires_at);
CREATE INDEX idx_guest_sessions_expires ON guest_sessions(expires_at);


-- Seed data: Meal templates (6 default meals)
INSERT INTO meal_templates (id, name, translation_key, icon, sort_order) VALUES
    (1, 'Breakfast', 'meal.breakfast', '🍞', 1),
    (2, 'Morning snack', 'meal.morning_snack', '🍎', 2),
    (3, 'Lunch', 'meal.lunch', '🍝', 3),
    (4, 'Afternoon snack', 'meal.afternoon_snack', '🍪', 4),
    (5, 'Dinner', 'meal.dinner', '🍛', 5),
    (6, 'Night snack', 'meal.night_snack', '🥛', 6);

-- Seed data: Food catalog (simplified - 5 generic items with translation keys)
INSERT INTO food_catalog (id, name, translation_key, category, blocked) VALUES
    (1, 'Soup', 'food.soup', 'starter', 0),
    (2, 'Main', 'food.main', 'main', 0),
    (3, 'Dessert', 'food.dessert', 'dessert', 0),
    (4, 'Drink', 'food.drink', 'drink', 0),
    (5, 'Snack', 'food.snack', 'snack', 0);

-- Seed data: Default meal template foods (example combinations)
-- Breakfast
INSERT INTO meal_template_foods (meal_template_id, food_catalog_id, sort_order) VALUES
    (1, 2, 1), -- (main)
    (1, 3, 2), -- (dessert)
    (1, 4, 3); -- (drink)

-- Morning Snack
INSERT INTO meal_template_foods (meal_template_id, food_catalog_id, sort_order) VALUES
    (2, 5, 1), -- (snack)
    (2, 4, 2); -- (drink)

-- Lunch
INSERT INTO meal_template_foods (meal_template_id, food_catalog_id, sort_order) VALUES
    (3, 1, 1), -- starter)
    (3, 2, 2), -- (main)
    (3, 3, 3), -- (dessert)
    (3, 4, 4); -- (drink)

-- Afternoon Snack
INSERT INTO meal_template_foods (meal_template_id, food_catalog_id, sort_order) VALUES
    (4, 5, 1), -- (snack)
    (4, 4, 2); -- (drink)

-- Dinner
INSERT INTO meal_template_foods (meal_template_id, food_catalog_id, sort_order) VALUES
    (5, 1, 1), -- starter)
    (5, 2, 2), -- (main)
    (5, 3, 3), -- (dessert)
    (5, 4, 4); -- (drink)

-- Night Snack
INSERT INTO meal_template_foods (meal_template_id, food_catalog_id, sort_order) VALUES
    (6, 5, 1), -- (snack)
    (6, 4, 2); -- (drink)


-- Seed data: Basic i18n strings (EN-UK and PT-PT)
INSERT INTO i18n (locale, key, value) VALUES
    -- English UK - Core
    ('en-UK', 'app.name', 'Eat-Eat'),
    ('en-UK', 'login.title', 'Login'),
    ('en-UK', 'login.select_role', 'Select your role'),
    ('en-UK', 'login.child', 'Child'),
    ('en-UK', 'login.guardian', 'Guardian'),
    ('en-UK', 'login.pin', 'PIN'),
    ('en-UK', 'login.submit', 'Log in'),
    ('en-UK', 'login.error.invalid', 'Invalid PIN'),
    ('en-UK', 'login.error.locked', 'Account locked. Try again in {minutes} minutes.'),
    ('en-UK', 'logout', 'Logout'),
    ('en-UK', 'date', 'Date'),
    ('en-UK', 'today', 'Today'),
    -- Meal templates
    ('en-UK', 'meal.breakfast', 'Breakfast'),
    ('en-UK', 'meal.morning_snack', 'Morning Snack'),
    ('en-UK', 'meal.lunch', 'Lunch'),
    ('en-UK', 'meal.afternoon_snack', 'Afternoon Snack'),
    ('en-UK', 'meal.dinner', 'Dinner'),
    ('en-UK', 'meal.night_snack', 'Night Snack'),
    ('en-UK', 'meal.notes', 'Notes'),
    ('en-UK', 'meal.save', 'Save'),
    ('en-UK', 'meal.cancel', 'Cancel'),
    ('en-UK', 'meal.reviewed', 'Reviewed'),
    ('en-UK', 'meal.review', 'Review'),
    ('en-UK', 'meal.void', 'Void'),
    ('en-UK', 'meal.default', 'Meal'),
    ('en-UK', 'meal.pending', 'Pending review'),
    -- Logs
    ('en-UK', 'logs.today', 'Today''s Logs'),
    ('en-UK', 'logs.history', 'History (Last 7 Days)'),
    ('en-UK', 'logs.none', 'No logs for this date'),
    ('en-UK', 'logs.meals', 'Meals'),
    ('en-UK', 'logs.medications', 'Medications'),
    ('en-UK', 'logs.weight', 'Weight'),
    ('en-UK', 'logs.no_medications', 'No medication logs.'),
    ('en-UK', 'logs.no_weight', 'No weight logged.'),
    ('en-UK', 'logs.for_date', 'Logs for'),
    ('en-UK', 'logs.no_meals', 'No meals logged for this date.'),
    ('en-UK', 'logs.no_history', 'No history for the last 7 days.'),
    -- Guardian tools
    ('en-UK', 'guardian.tools', 'Guardian Tools'),
    ('en-UK', 'guardian.medication', 'Medication Log'),
    ('en-UK', 'guardian.weight', 'Weight Log'),
    ('en-UK', 'guardian.tokens', 'Guest Tokens (Clinician Access)'),
    ('en-UK', 'guardian.export', 'Export Report'),
    ('en-UK', 'guardian.food_catalog', 'Food Catalog'),
    ('en-UK', 'guardian.backup', 'Backup & Restore'),
    ('en-UK', 'guardian.templates', 'Meal Templates'),
    ('en-UK', 'guardian.medication_catalog', 'Medication Catalog'),
    ('en-UK', 'guardian.users', 'User Management'),
    ('en-UK', 'guardian.translations', 'Translations (i18n)'),
    -- Medication
    ('en-UK', 'medication.log', 'Log Medication'),
    ('en-UK', 'medication.medication', 'Medication'),
    ('en-UK', 'medication.date', 'Date'),
    ('en-UK', 'medication.time', 'Time (optional)'),
    ('en-UK', 'medication.status', 'Status'),
    ('en-UK', 'medication.status.taken', 'Taken'),
    ('en-UK', 'medication.status.missed', 'Missed'),
    ('en-UK', 'medication.status.skipped', 'Skipped'),
    ('en-UK', 'medication.notes', 'Notes'),
    ('en-UK', 'medication.add', 'Add New Medication'),
    ('en-UK', 'medication.name', 'Medication Name'),
    ('en-UK', 'medication.dose', 'Dose'),
    ('en-UK', 'medication.notes_optional', 'Notes (optional)'),
    ('en-UK', 'medication.edit', 'Edit Medication'),
    -- Weight
    ('en-UK', 'weight.log', 'Log Weight'),
    ('en-UK', 'weight.kg', 'Weight (kg)'),
    -- Token
    ('en-UK', 'token.create', 'Create New Token'),
    ('en-UK', 'token.none', 'No active tokens'),
    ('en-UK', 'token.revoke', 'Revoke'),
    ('en-UK', 'token.revoked', 'Revoked'),
    ('en-UK', 'token.expires', 'Expires'),
    ('en-UK', 'token.expiry_prompt', 'Token expiry:\n1 = 30 minutes\n2 = 2 hours\n3 = 12 hours\n4 = 1 day'),
    ('en-UK', 'token.copy_url', 'Guest access URL (copy this):'),
    ('en-UK', 'token.expired', 'Expired'),
    ('en-UK', 'token.show_fewer', 'Show fewer'),
    ('en-UK', 'token.show_all', 'Show all'),
    -- Report
    ('en-UK', 'report.range', 'Report Range'),
    ('en-UK', 'report.30days', 'Last 30 Days'),
    ('en-UK', 'report.all', 'Whole History (max 365 days)'),
    ('en-UK', 'report.download', 'Download PDF Report'),
    -- Food
    ('en-UK', 'food.add', 'Add New Food'),
    ('en-UK', 'food.name', 'Food Name'),
    ('en-UK', 'food.category', 'Category'),
    ('en-UK', 'food.category.starter', 'Starter'),
    ('en-UK', 'food.category.main', 'Main'),
    ('en-UK', 'food.category.dessert', 'Dessert'),
    ('en-UK', 'food.category.drink', 'Drink'),
    ('en-UK', 'food.category.snack', 'Snack'),
    ('en-UK', 'food.edit', 'Edit Food'),
    ('en-UK', 'food.soup', 'Soup'),
    ('en-UK', 'food.main', 'Main'),
    ('en-UK', 'food.dessert', 'Dessert'),
    ('en-UK', 'food.drink', 'Drink'),
    ('en-UK', 'food.snack', 'Snack'),
    -- General
    ('en-UK', 'success', 'Success'),
    ('en-UK', 'error', 'Error'),
    ('en-UK', 'save', 'Save'),
    ('en-UK', 'cancel', 'Cancel'),
    ('en-UK', 'settings', 'Settings'),
    ('en-UK', 'at', 'at'),
    -- Backup
    ('en-UK', 'backup.create', 'Create Backup'),
    ('en-UK', 'backup.restore', 'Restore Backup'),
    ('en-UK', 'backup.download', 'Download'),
    ('en-UK', 'backup.none', 'No backups available'),
    ('en-UK', 'backup.create_now', 'Create Backup Now'),
    ('en-UK', 'backup.available', 'Available Backups'),
    ('en-UK', 'backup.stats', 'Database Statistics'),
    ('en-UK', 'backup.vacuum', 'Optimize Database (VACUUM)'),
    ('en-UK', 'backup.created', 'Created'),
    ('en-UK', 'backup.size', 'Size'),
    -- Template
    ('en-UK', 'template.add', 'Add New Template'),
    ('en-UK', 'template.name', 'Template Name'),
    ('en-UK', 'template.icon', 'Icon (emoji)'),
    ('en-UK', 'template.sort', 'Sort Order'),
    ('en-UK', 'template.edit', 'Edit Meal Template'),
    -- User
    ('en-UK', 'user.add_child', 'Add Child'),
    ('en-UK', 'user.add_guardian', 'Add Guardian'),
    ('en-UK', 'user.add', 'Add User'),
    ('en-UK', 'user.name', 'Name'),
    ('en-UK', 'user.pin_confirm', 'Confirm PIN'),
    ('en-UK', 'user.reset_pin', 'Reset PIN'),
    ('en-UK', 'user.new_pin', 'New PIN (4 digits)'),
    ('en-UK', 'user.confirm_new_pin', 'Confirm New PIN'),
    ('en-UK', 'user.locked', 'Locked'),
    ('en-UK', 'user.active', 'Active'),
    ('en-UK', 'user.blocked', 'Blocked'),
    ('en-UK', 'user.edit', 'Edit'),
    ('en-UK', 'user.block', 'Block'),
    ('en-UK', 'user.unblock', 'Unblock'),
    ('en-UK', 'user.delete', 'Delete'),
    ('en-UK', 'user.role', 'Role'),
    ('en-UK', 'user.status', 'Status'),
    ('en-UK', 'user.actions', 'Actions'),
    ('en-UK', 'user.edit_user', 'Edit User'),
    -- i18n admin
    ('en-UK', 'i18n.locale', 'Locale'),
    ('en-UK', 'i18n.add_edit', 'Add / Edit Translation'),
    ('en-UK', 'i18n.key', 'Key'),
    ('en-UK', 'i18n.value', 'Value'),
    ('en-UK', 'i18n.save', 'Save Translation'),
    ('en-UK', 'i18n.no_translations', 'No translations for this locale.'),
    -- Sprint 18 login
    ('en-UK', 'login.select_user', 'Select User'),
    ('en-UK', 'login.pin_label', 'PIN (4 digits)'),
    ('en-UK', 'login.back', 'Back'),
    -- Settings
    ('en-UK', 'settings.child_sees_meds', 'Children can see medication logs'),
    ('en-UK', 'settings.child_sees_meds_help', 'When disabled, the medication section is completely hidden for child accounts.'),
    -- Stats
    ('en-UK', 'stats.database_size', 'Database size'),
    ('en-UK', 'stats.schema_version', 'Schema version'),
    ('en-UK', 'stats.children', 'Children'),
    ('en-UK', 'stats.meal_logs', 'Meal logs'),
    ('en-UK', 'stats.weight_logs', 'Weight logs'),
    ('en-UK', 'stats.medication_logs', 'Medication logs'),
    ('en-UK', 'stats.last_backup', 'Last backup'),
    ('en-UK', 'stats.never', 'Never'),
    -- History
    ('en-UK', 'history.meals', 'meal(s)'),
    ('en-UK', 'history.meds', 'med(s)'),
    -- Catalog
    ('en-UK', 'catalog.no_foods', 'No foods in catalog.'),
    ('en-UK', 'catalog.no_medications', 'No medications in catalog. Add one to enable medication logging.'),
    ('en-UK', 'catalog.no_templates', 'No meal templates. Add one to enable meal logging.'),
    -- Form
    ('en-UK', 'form.select', 'Select...'),
    ('en-UK', 'form.select_medication', 'Select medication...'),
    -- Error messages
    ('en-UK', 'error.no_children', 'No children configured. Please add a child in Guardian Tools.'),
    ('en-UK', 'error.no_children_configured', 'No children configured'),
    ('en-UK', 'error.no_guardians_configured', 'No guardians configured'),
    ('en-UK', 'error.load_users', 'Failed to load users'),
    ('en-UK', 'error.login_failed', 'Login failed'),
    ('en-UK', 'error.load_food_catalog', 'Failed to load food catalog'),
    ('en-UK', 'error.no_child_selected', 'No child selected. Please add a child first.'),
    ('en-UK', 'error.no_foods_available', 'No foods available. Please add foods to the catalog first.'),
    ('en-UK', 'error.no_food_selected', 'Please add at least one food item'),
    ('en-UK', 'error.log_meal', 'Failed to log meal'),
    ('en-UK', 'error.review_meal', 'Failed to review meal'),
    ('en-UK', 'error.void_meal', 'Failed to void meal'),
    ('en-UK', 'error.load_history', 'Failed to load history'),
    ('en-UK', 'error.log_weight', 'Failed to log weight'),
    ('en-UK', 'error.load_medications', 'Failed to load medications'),
    ('en-UK', 'error.log_medication', 'Failed to log medication'),
    ('en-UK', 'error.invalid_selection', 'Invalid selection'),
    ('en-UK', 'error.create_token', 'Failed to create token'),
    ('en-UK', 'error.load_tokens', 'Failed to load tokens'),
    ('en-UK', 'error.revoke_token', 'Failed to revoke token'),
    ('en-UK', 'error.create_backup', 'Failed to create backup'),
    ('en-UK', 'error.update_setting', 'Failed to update setting'),
    ('en-UK', 'error.load_backups', 'Failed to load backups'),
    ('en-UK', 'error.restore_backup', 'Failed to restore backup'),
    ('en-UK', 'error.load_stats', 'Failed to load database stats'),
    ('en-UK', 'error.optimize_database', 'Failed to optimize database'),
    ('en-UK', 'error.pin_mismatch', 'PINs do not match'),
    ('en-UK', 'error.update_user', 'Failed to update user'),
    ('en-UK', 'error.reset_pin', 'Failed to reset PIN'),
    ('en-UK', 'error.block_user', 'Failed to block user'),
    ('en-UK', 'error.unblock_user', 'Failed to unblock user'),
    ('en-UK', 'error.delete_user', 'Failed to delete user'),
    ('en-UK', 'error.save_food', 'Failed to save food'),
    ('en-UK', 'error.block_food', 'Failed to block food'),
    ('en-UK', 'error.unblock_food', 'Failed to unblock food'),
    ('en-UK', 'error.delete_food', 'Failed to delete food'),
    ('en-UK', 'error.load_medication_catalog', 'Failed to load medication catalog'),
    ('en-UK', 'error.save_medication', 'Failed to save medication'),
    ('en-UK', 'error.block_medication', 'Failed to block medication'),
    ('en-UK', 'error.unblock_medication', 'Failed to unblock medication'),
    ('en-UK', 'error.delete_medication', 'Failed to delete medication'),
    ('en-UK', 'error.load_template_catalog', 'Failed to load template catalog'),
    ('en-UK', 'error.save_template', 'Failed to save template'),
    ('en-UK', 'error.block_template', 'Failed to block template'),
    ('en-UK', 'error.unblock_template', 'Failed to unblock template'),
    ('en-UK', 'error.delete_template', 'Failed to delete template'),
    ('en-UK', 'error.load_translations', 'Failed to load translations'),
    ('en-UK', 'error.save_translation', 'Failed to save translation'),
    ('en-UK', 'error.update_template_foods', 'Failed to update template foods'),
    -- Success messages
    ('en-UK', 'success.meal_logged', 'Meal logged successfully'),
    ('en-UK', 'success.meal_reviewed', 'Meal reviewed'),
    ('en-UK', 'success.meal_voided', 'Meal voided'),
    ('en-UK', 'success.weight_logged', 'Weight logged successfully'),
    ('en-UK', 'success.medication_logged', 'Medication logged successfully'),
    ('en-UK', 'success.token_created', 'Token created!'),
    ('en-UK', 'success.token_revoked', 'Token revoked'),
    ('en-UK', 'success.generating_report', 'Generating report...'),
    ('en-UK', 'success.backup_created', 'Backup created'),
    ('en-UK', 'success.child_can_see_meds', 'Children can now see medication logs'),
    ('en-UK', 'success.child_cannot_see_meds', 'Children can no longer see medication logs'),
    ('en-UK', 'success.backup_restored', 'Backup restored successfully. Please refresh the page.'),
    ('en-UK', 'success.database_optimized', 'Database optimized successfully'),
    ('en-UK', 'success.user_updated', 'User updated'),
    ('en-UK', 'success.pin_reset', 'PIN reset successfully'),
    ('en-UK', 'success.user_blocked', 'User blocked'),
    ('en-UK', 'success.user_unblocked', 'User unblocked'),
    ('en-UK', 'success.user_deleted', 'User deleted'),
    ('en-UK', 'success.food_added', 'Food added'),
    ('en-UK', 'success.food_updated', 'Food updated'),
    ('en-UK', 'success.food_blocked', 'Food blocked'),
    ('en-UK', 'success.food_unblocked', 'Food unblocked'),
    ('en-UK', 'success.food_deleted', 'Food deleted'),
    ('en-UK', 'success.medication_added', 'Medication added'),
    ('en-UK', 'success.medication_updated', 'Medication updated'),
    ('en-UK', 'success.medication_blocked', 'Medication blocked'),
    ('en-UK', 'success.medication_unblocked', 'Medication unblocked'),
    ('en-UK', 'success.medication_deleted', 'Medication deleted'),
    ('en-UK', 'success.template_added', 'Template added'),
    ('en-UK', 'success.template_updated', 'Template updated'),
    ('en-UK', 'success.template_blocked', 'Template blocked'),
    ('en-UK', 'success.template_unblocked', 'Template unblocked'),
    ('en-UK', 'success.template_deleted', 'Template deleted'),
    ('en-UK', 'success.translation_saved', 'Translation saved'),
    ('en-UK', 'success.template_foods_updated', 'Template foods updated'),
    -- Confirm messages
    ('en-UK', 'confirm.void_meal', 'Void this meal log? It will be hidden from daily view.'),
    ('en-UK', 'confirm.revoke_token', 'Revoke this token? Clinician will lose access immediately.'),
    ('en-UK', 'confirm.restore_backup', 'Restore backup "{filename}"? Current data will be backed up first.'),
    ('en-UK', 'confirm.vacuum', 'Optimise database? This may take a few seconds.'),
    ('en-UK', 'confirm.block_user', 'Block this user? They will not be able to log in.'),
    ('en-UK', 'confirm.delete_user', 'Delete this user permanently? This cannot be undone.'),
    ('en-UK', 'confirm.block_food', 'Block this food item? It will be hidden from meal logging.'),
    ('en-UK', 'confirm.delete_food', 'Delete this food item permanently? This cannot be undone.'),
    ('en-UK', 'confirm.block_medication', 'Block this medication? It will be hidden from logging.'),
    ('en-UK', 'confirm.delete_medication', 'Delete this medication permanently? This cannot be undone.'),
    ('en-UK', 'confirm.block_template', 'Block this meal template? It will be hidden from meal cards.'),
    ('en-UK', 'confirm.delete_template', 'Delete this meal template permanently? This cannot be undone.'),
    
    -- Portuguese Portugal - Core
    ('pt-PT', 'app.name', 'Come-Come'),
    ('pt-PT', 'login.title', 'Entrar'),
    ('pt-PT', 'login.select_role', 'Selecione o seu perfil'),
    ('pt-PT', 'login.child', 'Criança'),
    ('pt-PT', 'login.guardian', 'Responsável'),
    ('pt-PT', 'login.pin', 'PIN'),
    ('pt-PT', 'login.submit', 'Entrar'),
    ('pt-PT', 'login.error.invalid', 'PIN inválido'),
    ('pt-PT', 'login.error.locked', 'Conta bloqueada. Tente novamente em {minutes} minutos.'),
    ('pt-PT', 'logout', 'Sair'),
    ('pt-PT', 'date', 'Data'),
    ('pt-PT', 'today', 'Hoje'),
    -- Meal templates
    ('pt-PT', 'meal.breakfast', 'Pequeno-almoço'),
    ('pt-PT', 'meal.morning_snack', 'Lanche da manhã'),
    ('pt-PT', 'meal.lunch', 'Almoço'),
    ('pt-PT', 'meal.afternoon_snack', 'Lanche da tarde'),
    ('pt-PT', 'meal.dinner', 'Jantar'),
    ('pt-PT', 'meal.night_snack', 'Ceia'),
    ('pt-PT', 'meal.notes', 'Notas'),
    ('pt-PT', 'meal.save', 'Guardar'),
    ('pt-PT', 'meal.cancel', 'Cancelar'),
    ('pt-PT', 'meal.reviewed', 'Revisto'),
    ('pt-PT', 'meal.review', 'Rever'),
    ('pt-PT', 'meal.void', 'Anular'),
    ('pt-PT', 'meal.default', 'Refeição'),
    ('pt-PT', 'meal.pending', 'Aguarda revisão'),
    -- Logs
    ('pt-PT', 'logs.today', 'Registos de Hoje'),
    ('pt-PT', 'logs.history', 'Histórico (Últimos 7 Dias)'),
    ('pt-PT', 'logs.none', 'Sem registos para esta data'),
    ('pt-PT', 'logs.meals', 'Refeições'),
    ('pt-PT', 'logs.medications', 'Medicação'),
    ('pt-PT', 'logs.weight', 'Peso'),
    ('pt-PT', 'logs.no_medications', 'Sem registos de medicação.'),
    ('pt-PT', 'logs.no_weight', 'Sem peso registado.'),
    ('pt-PT', 'logs.for_date', 'Registos de'),
    ('pt-PT', 'logs.no_meals', 'Sem refeições registadas para esta data.'),
    ('pt-PT', 'logs.no_history', 'Sem histórico nos últimos 7 dias.'),
    -- Guardian tools
    ('pt-PT', 'guardian.tools', 'Ferramentas do Responsável'),
    ('pt-PT', 'guardian.medication', 'Registo de Medicação'),
    ('pt-PT', 'guardian.weight', 'Registo de Peso'),
    ('pt-PT', 'guardian.tokens', 'Tokens de Convidado (Acesso Clínico)'),
    ('pt-PT', 'guardian.export', 'Exportar Relatório'),
    ('pt-PT', 'guardian.food_catalog', 'Catálogo de Alimentos'),
    ('pt-PT', 'guardian.backup', 'Cópia de Segurança'),
    ('pt-PT', 'guardian.templates', 'Modelos de Refeição'),
    ('pt-PT', 'guardian.medication_catalog', 'Catálogo de Medicação'),
    ('pt-PT', 'guardian.users', 'Gestão de Utilizadores'),
    ('pt-PT', 'guardian.translations', 'Traduções (i18n)'),
    -- Medication
    ('pt-PT', 'medication.log', 'Registar Medicação'),
    ('pt-PT', 'medication.medication', 'Medicação'),
    ('pt-PT', 'medication.date', 'Data'),
    ('pt-PT', 'medication.time', 'Hora (opcional)'),
    ('pt-PT', 'medication.status', 'Estado'),
    ('pt-PT', 'medication.status.taken', 'Tomado'),
    ('pt-PT', 'medication.status.missed', 'Falhado'),
    ('pt-PT', 'medication.status.skipped', 'Saltado'),
    ('pt-PT', 'medication.notes', 'Notas'),
    ('pt-PT', 'medication.add', 'Adicionar Nova Medicação'),
    ('pt-PT', 'medication.name', 'Nome da Medicação'),
    ('pt-PT', 'medication.dose', 'Dose'),
    ('pt-PT', 'medication.notes_optional', 'Notas (opcional)'),
    ('pt-PT', 'medication.edit', 'Editar Medicação'),
    -- Weight
    ('pt-PT', 'weight.log', 'Registar Peso'),
    ('pt-PT', 'weight.kg', 'Peso (kg)'),
    -- Token
    ('pt-PT', 'token.create', 'Criar Novo Token'),
    ('pt-PT', 'token.none', 'Sem tokens ativos'),
    ('pt-PT', 'token.revoke', 'Revogar'),
    ('pt-PT', 'token.revoked', 'Revogado'),
    ('pt-PT', 'token.expires', 'Expira'),
    ('pt-PT', 'token.expiry_prompt', 'Expiração do token:\n1 = 30 minutos\n2 = 2 horas\n3 = 12 horas\n4 = 1 dia'),
    ('pt-PT', 'token.copy_url', 'URL de acesso de convidado (copie isto):'),
    ('pt-PT', 'token.expired', 'Expirado'),
    ('pt-PT', 'token.show_fewer', 'Mostrar menos'),
    ('pt-PT', 'token.show_all', 'Mostrar todos'),
    -- Report
    ('pt-PT', 'report.range', 'Intervalo do Relatório'),
    ('pt-PT', 'report.30days', 'Últimos 30 Dias'),
    ('pt-PT', 'report.all', 'Histórico Completo (máx. 365 dias)'),
    ('pt-PT', 'report.download', 'Transferir Relatório PDF'),
    -- Food
    ('pt-PT', 'food.add', 'Adicionar Novo Alimento'),
    ('pt-PT', 'food.name', 'Nome do Alimento'),
    ('pt-PT', 'food.category', 'Categoria'),
    ('pt-PT', 'food.category.starter', 'Entrada'),
    ('pt-PT', 'food.category.main', 'Principal'),
    ('pt-PT', 'food.category.dessert', 'Sobremesa'),
    ('pt-PT', 'food.category.drink', 'Bebida'),
    ('pt-PT', 'food.category.snack', 'Lanche'),
    ('pt-PT', 'food.edit', 'Editar Alimento'),
    ('pt-PT', 'food.soup', 'Sopa'),
    ('pt-PT', 'food.main', 'Principal'),
    ('pt-PT', 'food.dessert', 'Sobremesa'),
    ('pt-PT', 'food.drink', 'Bebida'),
    ('pt-PT', 'food.snack', 'Lanche'),
    -- General
    ('pt-PT', 'success', 'Sucesso'),
    ('pt-PT', 'error', 'Erro'),
    ('pt-PT', 'save', 'Guardar'),
    ('pt-PT', 'cancel', 'Cancelar'),
    ('pt-PT', 'settings', 'Definições'),
    ('pt-PT', 'at', 'às'),
    -- Backup
    ('pt-PT', 'backup.create', 'Criar Cópia de Segurança'),
    ('pt-PT', 'backup.restore', 'Restaurar Cópia'),
    ('pt-PT', 'backup.download', 'Transferir'),
    ('pt-PT', 'backup.none', 'Sem cópias disponíveis'),
    ('pt-PT', 'backup.create_now', 'Criar Cópia Agora'),
    ('pt-PT', 'backup.available', 'Cópias Disponíveis'),
    ('pt-PT', 'backup.stats', 'Estatísticas da Base de Dados'),
    ('pt-PT', 'backup.vacuum', 'Otimizar Base de Dados (VACUUM)'),
    ('pt-PT', 'backup.created', 'Criada'),
    ('pt-PT', 'backup.size', 'Tamanho'),
    -- Template
    ('pt-PT', 'template.add', 'Adicionar Novo Modelo'),
    ('pt-PT', 'template.name', 'Nome do Modelo'),
    ('pt-PT', 'template.icon', 'Ícone (emoji)'),
    ('pt-PT', 'template.sort', 'Ordem'),
    ('pt-PT', 'template.edit', 'Editar Modelo de Refeição'),
    -- User
    ('pt-PT', 'user.add_child', 'Adicionar Criança'),
    ('pt-PT', 'user.add_guardian', 'Adicionar Responsável'),
    ('pt-PT', 'user.add', 'Adicionar Utilizador'),
    ('pt-PT', 'user.name', 'Nome'),
    ('pt-PT', 'user.pin_confirm', 'Confirmar PIN'),
    ('pt-PT', 'user.reset_pin', 'Redefinir PIN'),
    ('pt-PT', 'user.new_pin', 'Novo PIN (4 dígitos)'),
    ('pt-PT', 'user.confirm_new_pin', 'Confirmar Novo PIN'),
    ('pt-PT', 'user.locked', 'Bloqueado'),
    ('pt-PT', 'user.active', 'Ativo'),
    ('pt-PT', 'user.blocked', 'Bloqueado'),
    ('pt-PT', 'user.edit', 'Editar'),
    ('pt-PT', 'user.block', 'Bloquear'),
    ('pt-PT', 'user.unblock', 'Desbloquear'),
    ('pt-PT', 'user.delete', 'Eliminar'),
    ('pt-PT', 'user.role', 'Função'),
    ('pt-PT', 'user.status', 'Estado'),
    ('pt-PT', 'user.actions', 'Ações'),
    ('pt-PT', 'user.edit_user', 'Editar Utilizador'),
    -- i18n admin
    ('pt-PT', 'i18n.locale', 'Idioma'),
    ('pt-PT', 'i18n.add_edit', 'Adicionar / Editar Tradução'),
    ('pt-PT', 'i18n.key', 'Chave'),
    ('pt-PT', 'i18n.value', 'Valor'),
    ('pt-PT', 'i18n.save', 'Guardar Tradução'),
    ('pt-PT', 'i18n.no_translations', 'Sem traduções para este idioma.'),
    -- Sprint 18 login
    ('pt-PT', 'login.select_user', 'Selecionar Utilizador'),
    ('pt-PT', 'login.pin_label', 'PIN (4 dígitos)'),
    ('pt-PT', 'login.back', 'Voltar'),
    -- Settings
    ('pt-PT', 'settings.child_sees_meds', 'Crianças podem ver registos de medicação'),
    ('pt-PT', 'settings.child_sees_meds_help', 'Quando desativado, a secção de medicação fica completamente oculta para contas de crianças.'),
    -- Stats
    ('pt-PT', 'stats.database_size', 'Tamanho da base de dados'),
    ('pt-PT', 'stats.schema_version', 'Versão do esquema'),
    ('pt-PT', 'stats.children', 'Crianças'),
    ('pt-PT', 'stats.meal_logs', 'Registos de refeições'),
    ('pt-PT', 'stats.weight_logs', 'Registos de peso'),
    ('pt-PT', 'stats.medication_logs', 'Registos de medicação'),
    ('pt-PT', 'stats.last_backup', 'Última cópia'),
    ('pt-PT', 'stats.never', 'Nunca'),
    -- History
    ('pt-PT', 'history.meals', 'refeição(ões)'),
    ('pt-PT', 'history.meds', 'medicação(ões)'),
    -- Catalog
    ('pt-PT', 'catalog.no_foods', 'Sem alimentos no catálogo.'),
    ('pt-PT', 'catalog.no_medications', 'Sem medicações no catálogo. Adicione uma para ativar o registo de medicação.'),
    ('pt-PT', 'catalog.no_templates', 'Sem modelos de refeição. Adicione um para ativar o registo de refeições.'),
    -- Form
    ('pt-PT', 'form.select', 'Selecionar...'),
    ('pt-PT', 'form.select_medication', 'Selecionar medicação...'),
    -- Error messages
    ('pt-PT', 'error.no_children', 'Sem crianças configuradas. Adicione uma criança em Ferramentas do Responsável.'),
    ('pt-PT', 'error.no_children_configured', 'Sem crianças configuradas'),
    ('pt-PT', 'error.no_guardians_configured', 'Sem responsáveis configurados'),
    ('pt-PT', 'error.load_users', 'Falha ao carregar utilizadores'),
    ('pt-PT', 'error.login_failed', 'Falha no login'),
    ('pt-PT', 'error.load_food_catalog', 'Falha ao carregar catálogo de alimentos'),
    ('pt-PT', 'error.no_child_selected', 'Sem criança selecionada. Adicione uma criança primeiro.'),
    ('pt-PT', 'error.no_foods_available', 'Sem alimentos disponíveis. Adicione alimentos ao catálogo primeiro.'),
    ('pt-PT', 'error.no_food_selected', 'Adicione pelo menos um alimento'),
    ('pt-PT', 'error.log_meal', 'Falha ao registar refeição'),
    ('pt-PT', 'error.review_meal', 'Falha ao rever refeição'),
    ('pt-PT', 'error.void_meal', 'Falha ao anular refeição'),
    ('pt-PT', 'error.load_history', 'Falha ao carregar histórico'),
    ('pt-PT', 'error.log_weight', 'Falha ao registar peso'),
    ('pt-PT', 'error.load_medications', 'Falha ao carregar medicações'),
    ('pt-PT', 'error.log_medication', 'Falha ao registar medicação'),
    ('pt-PT', 'error.invalid_selection', 'Seleção inválida'),
    ('pt-PT', 'error.create_token', 'Falha ao criar token'),
    ('pt-PT', 'error.load_tokens', 'Falha ao carregar tokens'),
    ('pt-PT', 'error.revoke_token', 'Falha ao revogar token'),
    ('pt-PT', 'error.create_backup', 'Falha ao criar cópia de segurança'),
    ('pt-PT', 'error.update_setting', 'Falha ao atualizar definição'),
    ('pt-PT', 'error.load_backups', 'Falha ao carregar cópias'),
    ('pt-PT', 'error.restore_backup', 'Falha ao restaurar cópia'),
    ('pt-PT', 'error.load_stats', 'Falha ao carregar estatísticas'),
    ('pt-PT', 'error.optimize_database', 'Falha ao otimizar base de dados'),
    ('pt-PT', 'error.pin_mismatch', 'Os PINs não correspondem'),
    ('pt-PT', 'error.update_user', 'Falha ao atualizar utilizador'),
    ('pt-PT', 'error.reset_pin', 'Falha ao redefinir PIN'),
    ('pt-PT', 'error.block_user', 'Falha ao bloquear utilizador'),
    ('pt-PT', 'error.unblock_user', 'Falha ao desbloquear utilizador'),
    ('pt-PT', 'error.delete_user', 'Falha ao eliminar utilizador'),
    ('pt-PT', 'error.save_food', 'Falha ao guardar alimento'),
    ('pt-PT', 'error.block_food', 'Falha ao bloquear alimento'),
    ('pt-PT', 'error.unblock_food', 'Falha ao desbloquear alimento'),
    ('pt-PT', 'error.delete_food', 'Falha ao eliminar alimento'),
    ('pt-PT', 'error.load_medication_catalog', 'Falha ao carregar catálogo de medicação'),
    ('pt-PT', 'error.save_medication', 'Falha ao guardar medicação'),
    ('pt-PT', 'error.block_medication', 'Falha ao bloquear medicação'),
    ('pt-PT', 'error.unblock_medication', 'Falha ao desbloquear medicação'),
    ('pt-PT', 'error.delete_medication', 'Falha ao eliminar medicação'),
    ('pt-PT', 'error.load_template_catalog', 'Falha ao carregar catálogo de modelos'),
    ('pt-PT', 'error.save_template', 'Falha ao guardar modelo'),
    ('pt-PT', 'error.block_template', 'Falha ao bloquear modelo'),
    ('pt-PT', 'error.unblock_template', 'Falha ao desbloquear modelo'),
    ('pt-PT', 'error.delete_template', 'Falha ao eliminar modelo'),
    ('pt-PT', 'error.load_translations', 'Falha ao carregar traduções'),
    ('pt-PT', 'error.save_translation', 'Falha ao guardar tradução'),
    ('pt-PT', 'error.update_template_foods', 'Falha ao atualizar alimentos do modelo'),
    -- Success messages
    ('pt-PT', 'success.meal_logged', 'Refeição registada com sucesso'),
    ('pt-PT', 'success.meal_reviewed', 'Refeição revista'),
    ('pt-PT', 'success.meal_voided', 'Refeição anulada'),
    ('pt-PT', 'success.weight_logged', 'Peso registado com sucesso'),
    ('pt-PT', 'success.medication_logged', 'Medicação registada com sucesso'),
    ('pt-PT', 'success.token_created', 'Token criado!'),
    ('pt-PT', 'success.token_revoked', 'Token revogado'),
    ('pt-PT', 'success.generating_report', 'A gerar relatório...'),
    ('pt-PT', 'success.backup_created', 'Cópia criada'),
    ('pt-PT', 'success.child_can_see_meds', 'As crianças podem agora ver registos de medicação'),
    ('pt-PT', 'success.child_cannot_see_meds', 'As crianças já não podem ver registos de medicação'),
    ('pt-PT', 'success.backup_restored', 'Cópia restaurada com sucesso. Atualize a página.'),
    ('pt-PT', 'success.database_optimized', 'Base de dados otimizada com sucesso'),
    ('pt-PT', 'success.user_updated', 'Utilizador atualizado'),
    ('pt-PT', 'success.pin_reset', 'PIN redefinido com sucesso'),
    ('pt-PT', 'success.user_blocked', 'Utilizador bloqueado'),
    ('pt-PT', 'success.user_unblocked', 'Utilizador desbloqueado'),
    ('pt-PT', 'success.user_deleted', 'Utilizador eliminado'),
    ('pt-PT', 'success.food_added', 'Alimento adicionado'),
    ('pt-PT', 'success.food_updated', 'Alimento atualizado'),
    ('pt-PT', 'success.food_blocked', 'Alimento bloqueado'),
    ('pt-PT', 'success.food_unblocked', 'Alimento desbloqueado'),
    ('pt-PT', 'success.food_deleted', 'Alimento eliminado'),
    ('pt-PT', 'success.medication_added', 'Medicação adicionada'),
    ('pt-PT', 'success.medication_updated', 'Medicação atualizada'),
    ('pt-PT', 'success.medication_blocked', 'Medicação bloqueada'),
    ('pt-PT', 'success.medication_unblocked', 'Medicação desbloqueada'),
    ('pt-PT', 'success.medication_deleted', 'Medicação eliminada'),
    ('pt-PT', 'success.template_added', 'Modelo adicionado'),
    ('pt-PT', 'success.template_updated', 'Modelo atualizado'),
    ('pt-PT', 'success.template_blocked', 'Modelo bloqueado'),
    ('pt-PT', 'success.template_unblocked', 'Modelo desbloqueado'),
    ('pt-PT', 'success.template_deleted', 'Modelo eliminado'),
    ('pt-PT', 'success.translation_saved', 'Tradução guardada'),
    ('pt-PT', 'success.template_foods_updated', 'Alimentos do modelo atualizados'),
    -- Confirm messages
    ('pt-PT', 'confirm.void_meal', 'Anular este registo de refeição? Ficará oculto da visualização diária.'),
    ('pt-PT', 'confirm.revoke_token', 'Revogar este token? O clínico perderá acesso imediatamente.'),
    ('pt-PT', 'confirm.restore_backup', 'Restaurar cópia "{filename}"? Os dados atuais serão guardados primeiro.'),
    ('pt-PT', 'confirm.vacuum', 'Otimizar base de dados? Pode demorar alguns segundos.'),
    ('pt-PT', 'confirm.block_user', 'Bloquear este utilizador? Não poderá iniciar sessão.'),
    ('pt-PT', 'confirm.delete_user', 'Eliminar este utilizador permanentemente? Esta ação não pode ser desfeita.'),
    ('pt-PT', 'confirm.block_food', 'Bloquear este alimento? Ficará oculto do registo de refeições.'),
    ('pt-PT', 'confirm.delete_food', 'Eliminar este alimento permanentemente? Esta ação não pode ser desfeita.'),
    ('pt-PT', 'confirm.block_medication', 'Bloquear esta medicação? Ficará oculta do registo.'),
    ('pt-PT', 'confirm.delete_medication', 'Eliminar esta medicação permanentemente? Esta ação não pode ser desfeita.'),
    ('pt-PT', 'confirm.block_template', 'Bloquear este modelo de refeição? Ficará oculto dos cartões de refeição.'),
    ('pt-PT', 'confirm.delete_template', 'Eliminar este modelo de refeição permanentemente? Esta ação não pode ser desfeita.');
