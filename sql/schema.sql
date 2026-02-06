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
INSERT INTO meal_templates (id, name, icon, sort_order) VALUES
    (1, 'Breakfast', '🍞', 1),
    (2, 'Morning Snack', '🍎', 2),
    (3, 'Lunch', '🍝', 3),
    (4, 'Afternoon Snack', '🍪', 4),
    (5, 'Dinner', '🍛', 5),
    (6, 'Night Snack', '🥛', 6);

-- Seed data: Food catalog (20 starter foods - 4 per category)
INSERT INTO food_catalog (id, name, category, blocked) VALUES
    -- Starters (5)
    (1, 'Soup', 'starter', 0),
    (2, 'Salad', 'starter', 0),
    (3, 'Crackers', 'starter', 0),
    (4, 'Cheese', 'starter', 0),
    (5, 'Vegetable sticks', 'starter', 0),
    
    -- Main (5)
    (6, 'Chicken', 'main', 0),
    (7, 'Fish', 'main', 0),
    (8, 'Rice', 'main', 0),
    (9, 'Pasta', 'main', 0),
    (10, 'Bread', 'main', 0),
    
    -- Dessert (5)
    (11, 'Fruit', 'dessert', 0),
    (12, 'Yogurt', 'dessert', 0),
    (13, 'Pudding', 'dessert', 0),
    (14, 'Ice cream', 'dessert', 0),
    (15, 'Cookie', 'dessert', 0),
    
    -- Drink (5)
    (16, 'Water', 'drink', 0),
    (17, 'Milk', 'drink', 0),
    (18, 'Juice', 'drink', 0),
    (19, 'Tea', 'drink', 0),
    (20, 'Soda', 'drink', 0),
    
    -- Snack (5)
    (21, 'Nuts', 'snack', 0),
    (22, 'Chips', 'snack', 0),
    (23, 'Popcorn', 'snack', 0),
    (24, 'Granola bar', 'snack', 0),
    (25, 'Dried fruit', 'snack', 0);

-- Seed data: Default meal template foods (example combinations)
-- Breakfast
INSERT INTO meal_template_foods (meal_template_id, food_catalog_id, sort_order) VALUES
    (1, 10, 1), -- Bread (main)
    (1, 11, 2), -- Fruit (dessert)
    (1, 17, 3); -- Milk (drink)

-- Morning Snack
INSERT INTO meal_template_foods (meal_template_id, food_catalog_id, sort_order) VALUES
    (2, 24, 1), -- Granola bar (snack)
    (2, 16, 2); -- Water (drink)

-- Lunch
INSERT INTO meal_template_foods (meal_template_id, food_catalog_id, sort_order) VALUES
    (3, 2, 1),  -- Salad (starter)
    (3, 6, 2),  -- Chicken (main)
    (3, 11, 3), -- Fruit (dessert)
    (3, 16, 4); -- Water (drink)

-- Afternoon Snack
INSERT INTO meal_template_foods (meal_template_id, food_catalog_id, sort_order) VALUES
    (4, 15, 1), -- Cookie (dessert - used as snack)
    (4, 17, 2); -- Milk (drink)

-- Dinner
INSERT INTO meal_template_foods (meal_template_id, food_catalog_id, sort_order) VALUES
    (5, 1, 1),  -- Soup (starter)
    (5, 9, 2),  -- Pasta (main)
    (5, 12, 3), -- Yogurt (dessert)
    (5, 16, 4); -- Water (drink)

-- Night Snack
INSERT INTO meal_template_foods (meal_template_id, food_catalog_id, sort_order) VALUES
    (6, 3, 1),  -- Crackers (starter)
    (6, 17, 2); -- Milk (drink)

-- Seed data: Basic i18n strings (EN-UK and PT-PT)
INSERT INTO i18n (locale, key, value) VALUES
    -- English UK
    ('en-UK', 'app.name', 'Come-Come'),
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
    ('en-UK', 'logs.today', 'Today''s Logs'),
    ('en-UK', 'logs.history', 'History'),
    ('en-UK', 'logs.none', 'No logs for this date'),
    ('en-UK', 'guardian.tools', 'Guardian Tools'),
    ('en-UK', 'guardian.medication', 'Medication Log'),
    ('en-UK', 'guardian.weight', 'Weight Log'),
    ('en-UK', 'guardian.tokens', 'Guest Tokens (Clinician Access)'),
    ('en-UK', 'guardian.export', 'Export Report'),
    ('en-UK', 'guardian.food_catalog', 'Food Catalog'),
    ('en-UK', 'medication.log', 'Log Medication'),
    ('en-UK', 'medication.medication', 'Medication'),
    ('en-UK', 'medication.date', 'Date'),
    ('en-UK', 'medication.time', 'Time (optional)'),
    ('en-UK', 'medication.status', 'Status'),
    ('en-UK', 'medication.status.taken', 'Taken'),
    ('en-UK', 'medication.status.missed', 'Missed'),
    ('en-UK', 'medication.status.skipped', 'Skipped'),
    ('en-UK', 'medication.notes', 'Notes'),
    ('en-UK', 'weight.log', 'Log Weight'),
    ('en-UK', 'weight.kg', 'Weight (kg)'),
    ('en-UK', 'token.create', 'Create New Token'),
    ('en-UK', 'token.none', 'No active tokens'),
    ('en-UK', 'token.revoke', 'Revoke'),
    ('en-UK', 'token.revoked', 'Revoked'),
    ('en-UK', 'token.expires', 'Expires'),
    ('en-UK', 'report.range', 'Report Range'),
    ('en-UK', 'report.30days', 'Last 30 Days'),
    ('en-UK', 'report.all', 'Whole History (max 365 days)'),
    ('en-UK', 'report.download', 'Download PDF Report'),
    ('en-UK', 'food.add', 'Add New Food'),
    ('en-UK', 'food.name', 'Food Name'),
    ('en-UK', 'food.category', 'Category'),
    ('en-UK', 'food.category.starter', 'Starter'),
    ('en-UK', 'food.category.main', 'Main'),
    ('en-UK', 'food.category.dessert', 'Dessert'),
    ('en-UK', 'food.category.drink', 'Drink'),
    ('en-UK', 'food.category.snack', 'Snack'),
    ('en-UK', 'success', 'Success'),
    ('en-UK', 'error', 'Error'),
    ('en-UK', 'backup.create', 'Create Backup'),
    ('en-UK', 'backup.restore', 'Restore Backup'),
    ('en-UK', 'backup.download', 'Download'),
    ('en-UK', 'backup.none', 'No backups available'),
    ('en-UK', 'settings', 'Settings'),
    
    -- Portuguese Portugal
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
    ('pt-PT', 'logs.today', 'Registos de Hoje'),
    ('pt-PT', 'logs.history', 'Histórico'),
    ('pt-PT', 'logs.none', 'Sem registos para esta data'),
    ('pt-PT', 'guardian.tools', 'Ferramentas do Responsável'),
    ('pt-PT', 'guardian.medication', 'Registo de Medicação'),
    ('pt-PT', 'guardian.weight', 'Registo de Peso'),
    ('pt-PT', 'guardian.tokens', 'Tokens de Convidado (Acesso Clínico)'),
    ('pt-PT', 'guardian.export', 'Exportar Relatório'),
    ('pt-PT', 'guardian.food_catalog', 'Catálogo de Alimentos'),
    ('pt-PT', 'medication.log', 'Registar Medicação'),
    ('pt-PT', 'medication.medication', 'Medicação'),
    ('pt-PT', 'medication.date', 'Data'),
    ('pt-PT', 'medication.time', 'Hora (opcional)'),
    ('pt-PT', 'medication.status', 'Estado'),
    ('pt-PT', 'medication.status.taken', 'Tomado'),
    ('pt-PT', 'medication.status.missed', 'Falhado'),
    ('pt-PT', 'medication.status.skipped', 'Saltado'),
    ('pt-PT', 'medication.notes', 'Notas'),
    ('pt-PT', 'weight.log', 'Registar Peso'),
    ('pt-PT', 'weight.kg', 'Peso (kg)'),
    ('pt-PT', 'token.create', 'Criar Novo Token'),
    ('pt-PT', 'token.none', 'Sem tokens ativos'),
    ('pt-PT', 'token.revoke', 'Revogar'),
    ('pt-PT', 'token.revoked', 'Revogado'),
    ('pt-PT', 'token.expires', 'Expira'),
    ('pt-PT', 'report.range', 'Intervalo do Relatório'),
    ('pt-PT', 'report.30days', 'Últimos 30 Dias'),
    ('pt-PT', 'report.all', 'Histórico Completo (máx. 365 dias)'),
    ('pt-PT', 'report.download', 'Transferir Relatório PDF'),
    ('pt-PT', 'food.add', 'Adicionar Novo Alimento'),
    ('pt-PT', 'food.name', 'Nome do Alimento'),
    ('pt-PT', 'food.category', 'Categoria'),
    ('pt-PT', 'food.category.starter', 'Entrada'),
    ('pt-PT', 'food.category.main', 'Principal'),
    ('pt-PT', 'food.category.dessert', 'Sobremesa'),
    ('pt-PT', 'food.category.drink', 'Bebida'),
    ('pt-PT', 'food.category.snack', 'Lanche'),
    ('pt-PT', 'success', 'Sucesso'),
    ('pt-PT', 'error', 'Erro'),
    ('pt-PT', 'backup.create', 'Criar Cópia de Segurança'),
    ('pt-PT', 'backup.restore', 'Restaurar Cópia'),
    ('pt-PT', 'backup.download', 'Transferir'),
    ('pt-PT', 'backup.none', 'Sem cópias disponíveis'),
    ('pt-PT', 'settings', 'Definições');
