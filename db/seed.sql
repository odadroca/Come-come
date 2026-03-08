-- Seed data for ComeCome
-- Portuguese meals and initial food items

-- Insert default meals (Portuguese)
INSERT OR IGNORE INTO meals (id, name_key, sort_order, time_start, time_end) VALUES
(1, 'meal_breakfast', 1, '07:00', '10:00'),
(2, 'meal_morning_snack', 2, '10:00', '12:00'),
(3, 'meal_lunch', 3, '12:00', '15:00'),
(4, 'meal_afternoon_snack', 4, '15:00', '18:00'),
(5, 'meal_dinner', 5, '18:00', '21:00'),
(6, 'meal_night_snack', 6, '21:00', '23:59');

-- Insert food categories
INSERT OR IGNORE INTO food_categories (id, name_key, sort_order) VALUES
(1, 'category_fruits', 1),
(2, 'category_vegetables', 2),
(3, 'category_proteins', 3),
(4, 'category_grains', 4),
(5, 'category_dairy', 5),
(6, 'category_snacks', 6),
(7, 'category_drinks', 7),
(8, 'category_sweets', 8);

-- Map categories to meals (many-to-many)
-- Breakfast: fruits, grains, dairy, drinks
INSERT OR IGNORE INTO meal_categories (meal_id, category_id) VALUES
(1, 1), (1, 4), (1, 5), (1, 7);

-- Morning snack: fruits, snacks, dairy, drinks
INSERT OR IGNORE INTO meal_categories (meal_id, category_id) VALUES
(2, 1), (2, 6), (2, 5), (2, 7);

-- Lunch: vegetables, proteins, grains, drinks
INSERT OR IGNORE INTO meal_categories (meal_id, category_id) VALUES
(3, 2), (3, 3), (3, 4), (3, 7), (3, 8);

-- Afternoon snack: fruits, snacks, dairy, drinks, sweets
INSERT OR IGNORE INTO meal_categories (meal_id, category_id) VALUES
(4, 1), (4, 6), (4, 5), (4, 7), (4, 8);

-- Dinner: vegetables, proteins, grains, drinks
INSERT OR IGNORE INTO meal_categories (meal_id, category_id) VALUES
(5, 2), (5, 3), (5, 4), (5, 7);

-- Night snack: fruits, snacks, dairy, drinks
INSERT OR IGNORE INTO meal_categories (meal_id, category_id) VALUES
(6, 1), (6, 6), (6, 5), (6, 7);

-- Insert sample foods
-- Fruits
INSERT OR IGNORE INTO foods (name_key, emoji, category_id, sort_order) VALUES
('food_apple', '🍎', 1, 1),
('food_banana', '🍌', 1, 2),
('food_orange', '🍊', 1, 3),
('food_strawberry', '🍓', 1, 4),
('food_grapes', '🍇', 1, 5),
('food_watermelon', '🍉', 1, 6),
('food_pear', '🍐', 1, 7),
('food_peach', '🍑', 1, 8);

-- Vegetables
INSERT OR IGNORE INTO foods (name_key, emoji, category_id, sort_order) VALUES
('food_carrot', '🥕', 2, 1),
('food_broccoli', '🥦', 2, 2),
('food_tomato', '🍅', 2, 3),
('food_lettuce', '🥬', 2, 4),
('food_cucumber', '🥒', 2, 5),
('food_potato', '🥔', 2, 6),
('food_corn', '🌽', 2, 7);

-- Proteins
INSERT OR IGNORE INTO foods (name_key, emoji, category_id, sort_order) VALUES
('food_chicken', '🍗', 3, 1),
('food_fish', '🐟', 3, 2),
('food_egg', '🥚', 3, 3),
('food_meat', '🥩', 3, 4),
('food_bacon', '🥓', 3, 5),
('food_shrimp', '🦐', 3, 6);

-- Grains
INSERT OR IGNORE INTO foods (name_key, emoji, category_id, sort_order) VALUES
('food_bread', '🍞', 4, 1),
('food_rice', '🍚', 4, 2),
('food_pasta', '🍝', 4, 3),
('food_cereal', '🥣', 4, 4),
('food_toast', '🍞', 4, 5),
('food_pizza', '🍕', 4, 6);

-- Dairy
INSERT OR IGNORE INTO foods (name_key, emoji, category_id, sort_order) VALUES
('food_milk', '🥛', 5, 1),
('food_cheese', '🧀', 5, 2),
('food_yogurt', '🍶', 5, 3),
('food_butter', '🧈', 5, 4);

-- Snacks
INSERT OR IGNORE INTO foods (name_key, emoji, category_id, sort_order) VALUES
('food_cookie', '🍪', 6, 1),
('food_chips', '🥔', 6, 2),
('food_popcorn', '🍿', 6, 3),
('food_cracker', '🧈', 6, 4),
('food_nuts', '🥜', 6, 5);

-- Drinks
INSERT OR IGNORE INTO foods (name_key, emoji, category_id, sort_order) VALUES
('food_water', '💧', 7, 1),
('food_juice', '🧃', 7, 2),
('food_soda', '🥤', 7, 3),
('food_tea', '🍵', 7, 4),
('food_chocolate_milk', '🥛', 7, 5);

-- Sweets
INSERT OR IGNORE INTO foods (name_key, emoji, category_id, sort_order) VALUES
('food_ice_cream', '🍦', 8, 1),
('food_cake', '🍰', 8, 2),
('food_chocolate', '🍫', 8, 3),
('food_candy', '🍬', 8, 4),
('food_donut', '🍩', 8, 5);

-- Insert default settings
INSERT OR IGNORE INTO settings (key, value) VALUES
('show_medication_to_children', '1'),
('show_sleep_tracking', '1'),
('default_language', 'pt'),
('app_name', 'ComeCome');
