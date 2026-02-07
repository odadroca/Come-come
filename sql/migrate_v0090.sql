-- Migration: v0.190 → v0.200
-- Sprint 20: Amend i18n
-- Run this on existing databases that were created before v0.0200

ALTER TABLE meal_templates ADD COLUMN translation_key TEXT;
UPDATE meal_templates SET translation_key = 'meal.breakfast' WHERE id = 1;
UPDATE meal_templates SET translation_key = 'meal.morning_snack' WHERE id = 2;
UPDATE meal_templates SET translation_key = 'meal.lunch' WHERE id = 3;
UPDATE meal_templates SET translation_key = 'meal.afternoon_snack' WHERE id = 4;
UPDATE meal_templates SET translation_key = 'meal.dinner' WHERE id = 5;
UPDATE meal_templates SET translation_key = 'meal.night_snack' WHERE id = 6;