<?php
/**
 * Backup and Restore System — Sprint 4
 * Database backup, restore, and maintenance utilities
 */

class BackupAPI {
    
    /**
     * Create database backup
     * 
     * @param int $userId User creating backup
     * @return array Backup info with download path
     */
    public static function createBackup($userId) {
        // Ensure backup directory exists
        if (!file_exists(BACKUP_PATH)) {
            mkdir(BACKUP_PATH, 0750, true);
        }
        
        // Generate backup filename
        $timestamp = date('Y-m-d_His');
        $filename = "comecome_backup_{$timestamp}.db";
        $backupPath = BACKUP_PATH . '/' . $filename;
        
        // Copy database file
        if (!copy(DB_PATH, $backupPath)) {
            throw new Exception('Failed to create backup', 500);
        }
        
        // Set permissions
        chmod($backupPath, 0640);
        
        // Get file size
        $size = filesize($backupPath);
        
        // Log audit
        Auth::logAudit('BACKUP_CREATED', 'system', null, $userId, [
            'filename' => $filename,
            'size_bytes' => $size
        ]);
        
        return [
            'filename' => $filename,
            'size_bytes' => $size,
            'created_at' => date('Y-m-d H:i:s'),
            'download_path' => '/backup/download/' . $filename
        ];
    }
    
    /**
     * List available backups
     * 
     * @return array List of backup files
     */
    public static function listBackups() {
        if (!file_exists(BACKUP_PATH)) {
            return [];
        }
        
        $files = glob(BACKUP_PATH . '/comecome_backup_*.db');
        $backups = [];
        
        foreach ($files as $file) {
            $filename = basename($file);
            $backups[] = [
                'filename' => $filename,
                'size_bytes' => filesize($file),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                'download_path' => '/backup/download/' . $filename
            ];
        }
        
        // Sort by creation time (newest first)
        usort($backups, function($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });
        
        return $backups;
    }
    
    /**
     * Download backup file
     * 
     * @param string $filename Backup filename
     */
    public static function downloadBackup($filename) {
        // Validate filename (security)
        if (!preg_match('/^comecome_backup_\d{4}-\d{2}-\d{2}_\d{6}\.db$/', $filename)) {
            throw new Exception('Invalid backup filename', 400);
        }
        
        $filePath = BACKUP_PATH . '/' . $filename;
        
        if (!file_exists($filePath)) {
            throw new Exception('Backup file not found', 404);
        }
        
        // Send file
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
    
    /**
     * Restore database from backup
     * 
     * @param string $filename Backup filename to restore
     * @param int $userId User performing restore
     * @return bool Success
     */
    public static function restoreBackup($filename, $userId) {
        // Validate filename
        if (!preg_match('/^comecome_backup_\d{4}-\d{2}-\d{2}_\d{6}\.db$/', $filename)) {
            throw new Exception('Invalid backup filename', 400);
        }
        
        $backupPath = BACKUP_PATH . '/' . $filename;
        
        if (!file_exists($backupPath)) {
            throw new Exception('Backup file not found', 404);
        }
        
        // Create backup of current database before restore
        $currentBackup = "comecome_backup_before_restore_" . date('Y-m-d_His') . ".db";
        copy(DB_PATH, BACKUP_PATH . '/' . $currentBackup);
        
        // Restore backup
        if (!copy($backupPath, DB_PATH)) {
            throw new Exception('Failed to restore backup', 500);
        }
        
        // Verify restored database
        try {
            $db = Database::getInstance();
            $version = $db->getSchemaVersion();
            
            if (!$version) {
                // Restore failed, rollback
                copy(BACKUP_PATH . '/' . $currentBackup, DB_PATH);
                throw new Exception('Restored database is invalid', 500);
            }
        } catch (Exception $e) {
            // Restore failed, rollback
            copy(BACKUP_PATH . '/' . $currentBackup, DB_PATH);
            throw new Exception('Restored database verification failed: ' . $e->getMessage(), 500);
        }
        
        // Log audit
        Auth::logAudit('BACKUP_RESTORED', 'system', null, $userId, [
            'filename' => $filename,
            'rollback_backup' => $currentBackup
        ]);
        
        return true;
    }
    
    /**
     * Delete old backups
     * 
     * @param int $keepDays Number of days to keep (default: 30)
     * @param int $userId User performing cleanup
     * @return int Number of deleted backups
     */
    public static function cleanupBackups($keepDays = 30, $userId = null) {
        if (!file_exists(BACKUP_PATH)) {
            return 0;
        }
        
        $cutoffTime = time() - ($keepDays * 86400);
        $files = glob(BACKUP_PATH . '/comecome_backup_*.db');
        $deleted = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
                $deleted++;
            }
        }
        
        if ($deleted > 0 && $userId) {
            Auth::logAudit('BACKUP_CLEANUP', 'system', null, $userId, [
                'deleted_count' => $deleted,
                'keep_days' => $keepDays
            ]);
        }
        
        return $deleted;
    }
    
    /**
     * Get database statistics
     * 
     * @return array Database stats
     */
    public static function getDatabaseStats() {
        $db = Database::getInstance();
        
        return [
            'size_bytes' => $db->getDatabaseSize(),
            'size_mb' => round($db->getDatabaseSize() / 1048576, 2),
            'schema_version' => $db->getSchemaVersion(),
            'tables' => [
                'children' => $db->queryValue("SELECT COUNT(*) FROM children"),
                'guardians' => $db->queryValue("SELECT COUNT(*) FROM guardians"),
                'meal_logs' => $db->queryValue("SELECT COUNT(*) FROM meal_logs WHERE voided_at IS NULL"),
                'food_catalog' => $db->queryValue("SELECT COUNT(*) FROM food_catalog WHERE blocked = 0"),
                'weight_logs' => $db->queryValue("SELECT COUNT(*) FROM weight_logs WHERE voided_at IS NULL"),
                'medication_logs' => $db->queryValue("SELECT COUNT(*) FROM medication_logs"),
                'audit_log' => $db->queryValue("SELECT COUNT(*) FROM audit_log"),
                'sessions' => $db->queryValue("SELECT COUNT(*) FROM sessions WHERE expires_at > datetime('now')")
            ],
            'last_backup' => self::getLastBackupTime()
        ];
    }
    
    /**
     * Get last backup time
     */
    private static function getLastBackupTime() {
        $backups = self::listBackups();
        return !empty($backups) ? $backups[0]['created_at'] : null;
    }
    
    /**
     * Vacuum database (reclaim space)
     * 
     * @param int $userId User performing vacuum
     * @return bool Success
     */
    public static function vacuumDatabase($userId) {
        $db = Database::getInstance();
        
        $sizeBefore = $db->getDatabaseSize();
        $result = $db->vacuum();
        $sizeAfter = $db->getDatabaseSize();
        
        if ($result) {
            Auth::logAudit('DATABASE_VACUUM', 'system', null, $userId, [
                'size_before_bytes' => $sizeBefore,
                'size_after_bytes' => $sizeAfter,
                'reclaimed_bytes' => $sizeBefore - $sizeAfter
            ]);
        }
        
        return $result;
    }
}
