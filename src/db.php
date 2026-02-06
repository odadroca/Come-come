<?php
/**
 * Database Layer
 * PDO wrapper with query helpers and error handling
 */

class Database {
    private static $instance = null;
    private $pdo = null;
    
    /**
     * Private constructor (singleton)
     */
    private function __construct() {
        $this->connect();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Establish database connection
     */
    private function connect() {
        if ($this->pdo !== null) {
            return;
        }
        
        try {
            $dsn = 'sqlite:' . DB_PATH;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => DB_TIMEOUT / 1000
            ];
            
            $this->pdo = new PDO($dsn, null, null, $options);
            
            // Enable foreign keys
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            
            // Set busy timeout
            $this->pdo->exec('PRAGMA busy_timeout = ' . DB_TIMEOUT);
            
            // Enable WAL mode for better concurrency
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            
        } catch (PDOException $e) {
            $this->logError('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed');
        }
    }
    
    /**
     * Get PDO instance
     */
    public function getPdo() {
        return $this->pdo;
    }
    
    /**
     * Execute query and return all rows
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->logError('Query failed: ' . $e->getMessage() . ' SQL: ' . $sql);
            throw new Exception('Query execution failed');
        }
    }
    
    /**
     * Execute query and return single row
     */
    public function queryOne($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (PDOException $e) {
            $this->logError('Query failed: ' . $e->getMessage() . ' SQL: ' . $sql);
            throw new Exception('Query execution failed');
        }
    }
    
    /**
     * Execute query and return single value
     */
    public function queryValue($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchColumn();
            return $result !== false ? $result : null;
        } catch (PDOException $e) {
            $this->logError('Query failed: ' . $e->getMessage() . ' SQL: ' . $sql);
            throw new Exception('Query execution failed');
        }
    }
    
    /**
     * Execute insert/update/delete and return affected rows
     */
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            // Check for constraint violations
            if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                throw new Exception('Duplicate entry', 409);
            }
            if (strpos($e->getMessage(), 'FOREIGN KEY constraint failed') !== false) {
                throw new Exception('Referenced entity does not exist or cannot be deleted', 409);
            }
            
            $this->logError('Execute failed: ' . $e->getMessage() . ' SQL: ' . $sql);
            throw new Exception('Query execution failed');
        }
    }
    
    /**
     * Execute insert and return last insert ID
     */
    public function insert($sql, $params = []) {
        $this->execute($sql, $params);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Start transaction
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->pdo->rollback();
    }
    
    /**
     * Check if database is initialized
     */
    public function isInitialized() {
        if (!file_exists(DB_PATH)) {
            return false;
        }
        
        try {
            $result = $this->queryValue("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='schema_version'");
            return $result > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get schema version
     */
    public function getSchemaVersion() {
        try {
            return $this->queryValue("SELECT MAX(version) FROM schema_version");
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Initialize database from schema file
     */
    public function initialize() {
        $schemaFile = ROOT_PATH . '/sql/schema.sql';
        
        if (!file_exists($schemaFile)) {
            throw new Exception('Schema file not found');
        }
        
        $schema = file_get_contents($schemaFile);
        
        try {
            // Split schema into individual statements
            // Remove comments and split by semicolons
            $statements = $this->parseSQL($schema);
            
            // Execute each statement
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    $this->pdo->exec($statement);
                }
            }
            
            // Set correct permissions on database file (640: owner rw, group r, no world)
            if (file_exists(DB_PATH)) {
                chmod(DB_PATH, 0640);
            }
            
            return true;
        } catch (PDOException $e) {
            $this->logError('Schema initialization failed: ' . $e->getMessage());
            throw new Exception('Database initialization failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Parse SQL file into individual statements
     * Handles multi-line statements, comments, and SQLite quote doubling
     */
    private function parseSQL($sql) {
        // Remove SQL comments (-- and /* */)
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        
        // Split by semicolons, but keep string literals intact
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = null;
        $len = strlen($sql);
        
        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            
            // Check for quote characters
            if ($char === "'" || $char === '"') {
                if (!$inString) {
                    // Starting a string
                    $inString = true;
                    $stringChar = $char;
                    $current .= $char;
                } elseif ($char === $stringChar) {
                    // Check if this is a doubled quote (SQLite escape: '' or "")
                    if ($i + 1 < $len && $sql[$i + 1] === $stringChar) {
                        // Doubled quote - add both and skip next
                        $current .= $char . $char;
                        $i++;
                    } else {
                        // Ending the string
                        $inString = false;
                        $stringChar = null;
                        $current .= $char;
                    }
                } else {
                    // Different quote type inside string
                    $current .= $char;
                }
            }
            // Split on semicolon only if not inside a string
            elseif ($char === ';' && !$inString) {
                $statements[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        
        // Add last statement if exists
        if (!empty(trim($current))) {
            $statements[] = $current;
        }
        
        return $statements;
    }
    
    /**
     * Vacuum database (reclaim space)
     */
    public function vacuum() {
        try {
            $this->pdo->exec('VACUUM');
            return true;
        } catch (PDOException $e) {
            $this->logError('Vacuum failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get database file size in bytes
     */
    public function getDatabaseSize() {
        if (file_exists(DB_PATH)) {
            return filesize(DB_PATH);
        }
        return 0;
    }
    
    /**
     * Log error to file
     */
    private function logError($message) {
        if (LOG_ERRORS) {
            $logFile = LOG_PATH . '/db_errors.log';
            $timestamp = date('Y-m-d H:i:s');
            $logMessage = "[{$timestamp}] {$message}\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * Helper function to get database instance
 */
function db() {
    return Database::getInstance();
}
