<?php
/**
 * Authentication System
 * PIN validation, session management, rate limiting, lockout policy
 */

class Auth {
    
    /**
     * Authenticate user with PIN
     * 
     * @param string $role 'child' or 'guardian'
     * @param int $userId User ID
     * @param string $pin 4-digit PIN
     * @return array|false User data with session token or false on failure
     */
    public static function login($role, $userId, $pin) {
        // Rate limit check
        if (!self::checkRateLimit('/auth/login', RATE_LIMIT_AUTH, RATE_LIMIT_AUTH_WINDOW)) {
            throw new Exception('Rate limit exceeded. Try again in ' . RATE_LIMIT_AUTH_WINDOW . ' seconds.', 429);
        }
        
        // Validate role
        if (!in_array($role, ['child', 'guardian'])) {
            throw new Exception('Invalid role', 400);
        }
        
        // Validate PIN format (4 digits)
        if (!preg_match('/^\d{4}$/', $pin)) {
            throw new Exception('Invalid PIN format', 400);
        }
        
        // Get user
        $user = db()->queryOne(
            "SELECT * FROM users WHERE id = ? AND role = ?",
            [$userId, $role]
        );
        
        if (!$user) {
            self::logAudit('PIN_FAILED', 'users', $userId, null, ['reason' => 'user_not_found']);
            throw new Exception('Invalid credentials', 401);
        }
        
        // Check if account is locked
        if ($user['locked_until']) {
            $lockedUntil = strtotime($user['locked_until']);
            if ($lockedUntil > time()) {
                $minutesRemaining = ceil(($lockedUntil - time()) / 60);
                throw new Exception("Account locked. Try again in {$minutesRemaining} minutes.", 403);
            } else {
                // Lock expired, clear it
                self::unlockUser($userId);
                $user['locked_until'] = null;
            }
        }
        
        // Verify PIN
        if (!password_verify($pin, $user['pin_hash'])) {
            // Increment failed attempts (only for guardians)
            if ($role === 'guardian') {
                $failedAttempts = self::incrementFailedAttempts($userId);
                
                if ($failedAttempts >= PIN_MAX_ATTEMPTS) {
                    self::lockUser($userId, PIN_LOCKOUT_DURATION);
                    self::logAudit('PIN_LOCKED', 'users', $userId, null, ['reason' => 'max_attempts']);
                    throw new Exception('Account locked due to multiple failed attempts. Try again in 5 minutes.', 403);
                }
            }
            
            self::logAudit('PIN_FAILED', 'users', $userId, null, ['role' => $role]);
            throw new Exception('Invalid credentials', 401);
        }
        
        // Reset failed attempts on successful login
        if ($role === 'guardian') {
            self::resetFailedAttempts($userId);
        }
        
        // Create session
        $sessionToken = self::createSession($userId);
        
        // Get profile data
        $profile = null;
        if ($role === 'child') {
            $profile = db()->queryOne("SELECT * FROM children WHERE user_id = ?", [$userId]);
        } else {
            $profile = db()->queryOne("SELECT * FROM guardians WHERE user_id = ?", [$userId]);
        }
        
        // Get children list (for guardians and to identify which child logged in)
        $children = db()->query("SELECT id, name, active FROM children WHERE active = 1");
        
        self::logAudit('PIN_LOGIN', 'users', $userId, $userId, ['role' => $role]);
        
        return [
            'session_token' => $sessionToken,
            'user' => [
                'id' => $user['id'],
                'role' => $user['role'],
                'locale' => $user['locale'],
                'profile' => $profile
            ],
            'children' => $children
        ];
    }
    
    /**
     * Logout (invalidate session)
     */
    public static function logout($sessionToken) {
        db()->execute("DELETE FROM sessions WHERE token = ?", [$sessionToken]);
        self::logAudit('LOGOUT', 'sessions', null, self::getCurrentUserId(), ['token' => substr($sessionToken, 0, 8) . '...']);
        return true;
    }
    
    /**
     * Validate session token
     * 
     * @param string $token Session token
     * @return array|false User data or false
     */
    public static function validateSession($token) {
        $session = db()->queryOne(
            "SELECT s.*, u.role, u.locale 
             FROM sessions s 
             JOIN users u ON s.user_id = u.id 
             WHERE s.token = ? AND s.expires_at > datetime('now')",
            [$token]
        );
        
        if (!$session) {
            return false;
        }
        
        // Extend session (sliding window)
        $newExpiry = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        db()->execute(
            "UPDATE sessions SET expires_at = ? WHERE token = ?",
            [$newExpiry, $token]
        );
        
        return $session;
    }
    
    /**
     * Create session token
     */
    private static function createSession($userId) {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        
        db()->insert(
            "INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, ?)",
            [$token, $userId, $expiresAt]
        );
        
        return $token;
    }
    
    /**
     * Lock user account
     */
    public static function lockUser($userId, $duration) {
        $lockedUntil = date('Y-m-d H:i:s', time() + $duration);
        db()->execute(
            "UPDATE users SET locked_until = ? WHERE id = ?",
            [$lockedUntil, $userId]
        );
    }
    
    /**
     * Unlock user account
     */
    public static function unlockUser($userId) {
        db()->execute(
            "UPDATE users SET locked_until = NULL WHERE id = ?",
            [$userId]
        );
        self::resetFailedAttempts($userId);
        self::logAudit('PIN_UNLOCKED', 'users', $userId, self::getCurrentUserId());
    }
    
    /**
     * Unlock with emergency code
     */
    public static function unlockWithCode($userId, $pin, $unlockCode) {
        if ($unlockCode !== UNLOCK_CODE) {
            throw new Exception('Invalid unlock code', 401);
        }
        
        $user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            throw new Exception('User not found', 404);
        }
        
        if (!password_verify($pin, $user['pin_hash'])) {
            throw new Exception('Invalid PIN', 401);
        }
        
        self::unlockUser($userId);
        self::logAudit('UNLOCK_CODE_USED', 'users', $userId, $userId);
        
        return true;
    }
    
    /**
     * Increment failed login attempts
     */
    private static function incrementFailedAttempts($userId) {
        // Store in audit log (last 5 minutes)
        $cutoff = date('Y-m-d H:i:s', time() - PIN_LOCKOUT_DURATION);
        $count = db()->queryValue(
            "SELECT COUNT(*) FROM audit_log 
             WHERE entity_type = 'users' 
             AND entity_id = ? 
             AND action = 'PIN_FAILED' 
             AND timestamp > ?",
            [$userId, $cutoff]
        );
        
        return $count + 1;
    }
    
    /**
     * Reset failed login attempts
     */
    private static function resetFailedAttempts($userId) {
        // Clear recent failed attempts from audit log
        db()->execute(
            "DELETE FROM audit_log 
             WHERE entity_type = 'users' 
             AND entity_id = ? 
             AND action = 'PIN_FAILED'",
            [$userId]
        );
    }
    
    /**
     * Change PIN
     */
    public static function changePin($userId, $currentPin, $newPin) {
        // Verify current PIN
        $user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            throw new Exception('User not found', 404);
        }
        
        if (!password_verify($currentPin, $user['pin_hash'])) {
            throw new Exception('Invalid current PIN', 401);
        }
        
        // Validate new PIN format
        if (!preg_match('/^\d{4}$/', $newPin)) {
            throw new Exception('New PIN must be exactly 4 digits', 400);
        }
        
        // Hash new PIN
        $newHash = password_hash($newPin, PASSWORD_BCRYPT, ['cost' => PIN_HASH_COST]);
        
        // Update
        db()->execute(
            "UPDATE users SET pin_hash = ?, updated_at = datetime('now') WHERE id = ?",
            [$newHash, $userId]
        );
        
        self::logAudit('PIN_CHANGED', 'users', $userId, self::getCurrentUserId());
        
        return true;
    }
    
    /**
     * Set PIN (for new users or guardian override)
     */
    public static function setPin($userId, $newPin, $actorId = null) {
        // Validate new PIN format
        if (!preg_match('/^\d{4}$/', $newPin)) {
            throw new Exception('PIN must be exactly 4 digits', 400);
        }
        
        // Hash new PIN
        $newHash = password_hash($newPin, PASSWORD_BCRYPT, ['cost' => PIN_HASH_COST]);
        
        // Update
        db()->execute(
            "UPDATE users SET pin_hash = ?, updated_at = datetime('now') WHERE id = ?",
            [$newHash, $userId]
        );
        
        self::logAudit('PIN_SET', 'users', $userId, $actorId ?: self::getCurrentUserId());
        
        return true;
    }
    
    /**
     * Check rate limit
     */
    public static function checkRateLimit($endpoint, $limit, $window) {
        $ip = self::getClientIp();
        $windowStart = floor(time() / $window) * $window;
        
        $current = db()->queryOne(
            "SELECT request_count FROM rate_limits 
             WHERE ip_address = ? AND endpoint = ? AND window_start = ?",
            [$ip, $endpoint, $windowStart]
        );
        
        if ($current) {
            if ($current['request_count'] >= $limit) {
                return false;
            }
            
            db()->execute(
                "UPDATE rate_limits SET request_count = request_count + 1 
                 WHERE ip_address = ? AND endpoint = ? AND window_start = ?",
                [$ip, $endpoint, $windowStart]
            );
        } else {
            db()->insert(
                "INSERT INTO rate_limits (ip_address, endpoint, window_start, request_count) 
                 VALUES (?, ?, ?, 1)",
                [$ip, $endpoint, $windowStart]
            );
        }
        
        return true;
    }
    
    /**
     * Get client IP address
     */
    private static function getClientIp() {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Get current user ID from session
     */
    public static function getCurrentUserId() {
        $token = $_COOKIE[SESSION_COOKIE_NAME] ?? null;
        if (!$token) {
            return null;
        }
        
        $session = self::validateSession($token);
        return $session ? $session['user_id'] : null;
    }
    
    /**
     * Log audit event
     */
    public static function logAudit($action, $entityType, $entityId, $actorId, $details = []) {
        if (!LOG_AUDIT) {
            return;
        }
        
        db()->insert(
            "INSERT INTO audit_log (entity_type, entity_id, action, actor_id, details_json) 
             VALUES (?, ?, ?, ?, ?)",
            [
                $entityType,
                $entityId,
                $action,
                $actorId,
                json_encode($details)
            ]
        );
    }
    
    /**
     * Clean up expired sessions
     */
    public static function cleanupSessions() {
        return db()->execute("DELETE FROM sessions WHERE expires_at < datetime('now')");
    }
    
    /**
     * Clean up old rate limits
     */
    public static function cleanupRateLimits() {
        $cutoff = time() - 3600; // Keep last hour
        return db()->execute("DELETE FROM rate_limits WHERE window_start < ?", [$cutoff]);
    }
}
