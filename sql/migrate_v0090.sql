-- Migration: v0.080 → v0.090
-- Sprint 9: Add blocked column to meal_templates
-- Run this on existing databases that were created before v0.090

-- Add blocked column if it doesn't exist
-- SQLite doesn't support IF NOT EXISTS for ALTER TABLE, so this may error if already applied.
ALTER TABLE meal_templates ADD COLUMN blocked INTEGER DEFAULT 0;

-- Update schema version to 2
INSERT OR REPLACE INTO schema_version (version, applied_at) VALUES (2, datetime('now'));
