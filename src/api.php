<?php
/**
 * API Handlers — Sprint 2–5
 * Meal logging, food catalog, weight logging, user management
 */

/**
 * B09 fix: Semantic date validation helper
 * Validates date format AND semantic validity (rejects 2025-99-99)
 * 
 * @param string $date Date string to validate
 * @return bool True if valid YYYY-MM-DD date
 */
function isValidDate($date) {
    // Check format first
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    
    // Check semantic validity using DateTime
    $dt = \DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

/**
 * B09 fix: Validate date with exception on failure
 * 
 * @param string $date Date string to validate
 * @throws Exception if date is invalid
 */
function validateDate($date) {
    if (!isValidDate($date)) {
        throw new Exception('Invalid date. Use YYYY-MM-DD with valid month/day.', 400);
    }
}

class MealAPI {
    
    /**
     * Get meals for child on specific date
     * 
     * @param int $childId Child ID
     * @param string $date Date in YYYY-MM-DD format
     * @return array Meal logs with food quantities
     */
    public static function getMeals($childId, $date) {
        // Validate date format and semantic validity (B09 fix)
        validateDate($date);
        
        // Get meal logs for the day
        $meals = db()->query(
            "SELECT ml.*, mt.name as meal_name, mt.icon as meal_icon, mt.translation_key as meal_translation_key,
                    ml.reviewed_by IS NOT NULL as is_reviewed
             FROM meal_logs ml
             JOIN meal_templates mt ON ml.meal_template_id = mt.id
             WHERE ml.child_id = ? AND ml.log_date = ? AND ml.voided_at IS NULL
             ORDER BY mt.sort_order",
            [$childId, $date]
        );
        
        // Get food quantities for each meal
        foreach ($meals as &$meal) {
            $meal['foods'] = db()->query(
                "SELECT fq.*, fc.name as food_name, fc.category
                 FROM food_quantities fq
                 JOIN food_catalog fc ON fq.food_catalog_id = fc.id
                 WHERE fq.meal_log_id = ?
                 ORDER BY fc.category, fc.name",
                [$meal['id']]
            );
        }
        
        return $meals;
    }
    
    /**
     * Create new meal log
     * 
     * @param array $data Meal data {child_id, meal_template_id, log_date, note, foods: [{food_id, quantity}]}
     * @param int $userId User creating the meal
     * @return array Created meal with ID
     */
    public static function createMeal($data, $userId) {
        // Validate required fields
        if (empty($data['child_id']) || empty($data['meal_template_id']) || empty($data['log_date'])) {
            throw new Exception('Missing required fields: child_id, meal_template_id, log_date', 400);
        }
        
        $childId = $data['child_id'];
        $mealTemplateId = $data['meal_template_id'];
        $logDate = $data['log_date'];
        $note = $data['note'] ?? '';
        $foods = $data['foods'] ?? [];
        
        // Validate date format and semantic validity (B09 fix)
        validateDate($logDate);
        
        // Check if meal already logged for this child + template + date
        $existing = db()->queryOne(
            "SELECT id FROM meal_logs 
             WHERE child_id = ? AND meal_template_id = ? AND log_date = ? AND voided_at IS NULL",
            [$childId, $mealTemplateId, $logDate]
        );
        
        if ($existing) {
            throw new Exception('Meal already logged for this child on this date', 409);
        }
        
        // Validate foods array
        if (empty($foods)) {
            throw new Exception('At least one food item required', 400);
        }
        
        // Begin transaction
        db()->beginTransaction();
        
        try {
            // Insert meal log
            $mealLogId = db()->insert(
                "INSERT INTO meal_logs (child_id, meal_template_id, log_date, note, created_by) 
                 VALUES (?, ?, ?, ?, ?)",
                [$childId, $mealTemplateId, $logDate, $note, $userId]
            );
            
            // Insert food quantities
            foreach ($foods as $food) {
                if (empty($food['food_id'])) {
                    throw new Exception('food_id required for each food item', 400);
                }
                
                $quantityDecimal = self::calculateQuantity($food);
                
                // Only insert if quantity > 0
                if ($quantityDecimal > 0) {
                    db()->insert(
                        "INSERT INTO food_quantities (meal_log_id, food_catalog_id, quantity_decimal) 
                         VALUES (?, ?, ?)",
                        [$mealLogId, $food['food_id'], $quantityDecimal]
                    );
                }
            }
            
            // Commit transaction
            db()->commit();
            
            // Log audit event
            Auth::logAudit('MEAL_CREATED', 'meal_logs', $mealLogId, $userId, [
                'child_id' => $childId,
                'meal_template_id' => $mealTemplateId,
                'log_date' => $logDate
            ]);
            
            return ['meal_log_id' => $mealLogId];
            
        } catch (Exception $e) {
            db()->rollback();
            throw $e;
        }
    }
    
    /**
     * Update existing meal log
     * 
     * @param int $mealLogId Meal log ID
     * @param array $data Update data {note, foods: [{food_id, quantity}]}
     * @param int $userId User updating the meal
     * @return bool Success
     */
    public static function updateMeal($mealLogId, $data, $userId) {
        // Get existing meal
        $meal = db()->queryOne("SELECT * FROM meal_logs WHERE id = ? AND voided_at IS NULL", [$mealLogId]);
        
        if (!$meal) {
            throw new Exception('Meal log not found', 404);
        }
        
        // Begin transaction
        db()->beginTransaction();
        
        try {
            // Update note if provided
            if (isset($data['note'])) {
                db()->execute(
                    "UPDATE meal_logs SET note = ?, updated_at = datetime('now') WHERE id = ?",
                    [$data['note'], $mealLogId]
                );
            }
            
            // Update food quantities if provided
            if (isset($data['foods'])) {
                // Delete existing food quantities
                db()->execute("DELETE FROM food_quantities WHERE meal_log_id = ?", [$mealLogId]);
                
                // Insert new food quantities
                foreach ($data['foods'] as $food) {
                    if (empty($food['food_id'])) {
                        throw new Exception('food_id required for each food item', 400);
                    }
                    
                    $quantityDecimal = self::calculateQuantity($food);
                    
                    // Only insert if quantity > 0
                    if ($quantityDecimal > 0) {
                        db()->insert(
                            "INSERT INTO food_quantities (meal_log_id, food_catalog_id, quantity_decimal) 
                             VALUES (?, ?, ?)",
                            [$mealLogId, $food['food_id'], $quantityDecimal]
                        );
                    }
                }
            }
            
            // Commit transaction
            db()->commit();
            
            // Log audit event
            Auth::logAudit('MEAL_EDITED', 'meal_logs', $mealLogId, $userId, [
                'child_id' => $meal['child_id'],
                'meal_template_id' => $meal['meal_template_id']
            ]);
            
            return true;
            
        } catch (Exception $e) {
            db()->rollback();
            throw $e;
        }
    }
    
    /**
     * Mark meal as guardian-reviewed
     * 
     * @param int $mealLogId Meal log ID
     * @param int $guardianId Guardian user ID
     * @return array Reviewed timestamp
     */
    public static function reviewMeal($mealLogId, $guardianId) {
        $meal = db()->queryOne("SELECT * FROM meal_logs WHERE id = ? AND voided_at IS NULL", [$mealLogId]);
        
        if (!$meal) {
            throw new Exception('Meal log not found', 404);
        }
        
        $reviewedAt = date('Y-m-d H:i:s');
        
        db()->execute(
            "UPDATE meal_logs 
             SET reviewed_by = ?, reviewed_at = ?, updated_at = datetime('now') 
             WHERE id = ?",
            [$guardianId, $reviewedAt, $mealLogId]
        );
        
        Auth::logAudit('MEAL_REVIEWED', 'meal_logs', $mealLogId, $guardianId, [
            'child_id' => $meal['child_id']
        ]);
        
        return ['reviewed_at' => $reviewedAt];
    }
    
    /**
     * Void meal log
     * 
     * @param int $mealLogId Meal log ID
     * @param int $guardianId Guardian user ID
     * @return array Voided timestamp
     */
    public static function voidMeal($mealLogId, $guardianId) {
        $meal = db()->queryOne("SELECT * FROM meal_logs WHERE id = ? AND voided_at IS NULL", [$mealLogId]);
        
        if (!$meal) {
            throw new Exception('Meal log not found or already voided', 404);
        }
        
        $voidedAt = date('Y-m-d H:i:s');
        
        db()->execute(
            "UPDATE meal_logs SET voided_at = ?, updated_at = datetime('now') WHERE id = ?",
            [$voidedAt, $mealLogId]
        );
        
        Auth::logAudit('MEAL_VOIDED', 'meal_logs', $mealLogId, $guardianId, [
            'child_id' => $meal['child_id']
        ]);
        
        return ['voided_at' => $voidedAt];
    }
    
    /**
     * Calculate quantity decimal from integer + fraction
     * 
     * @param array $food Food data with quantity_integer and quantity_fraction
     * @return float Quantity as decimal
     */
    private static function calculateQuantity($food) {
        $integer = $food['quantity_integer'] ?? 0;
        $fraction = $food['quantity_fraction'] ?? 0;
        
        // Validate integer
        if (!is_numeric($integer) || $integer < 0 || $integer > 99) {
            throw new Exception('quantity_integer must be between 0 and 99', 400);
        }
        
        // Validate fraction
        if (!in_array($fraction, [0, 0.25, 0.5, 0.75])) {
            throw new Exception('quantity_fraction must be 0, 0.25, 0.5, or 0.75', 400);
        }
        
        return floatval($integer) + floatval($fraction);
    }
}

class FoodAPI {
    
    /**
     * Get food catalog
     * 
     * @param int|null $childId Optional child ID to filter blocked foods
     * @return array Food items
     */
    public static function getFoods($childId = null, $includeBlocked = false) {
        if ($childId) {
            // Filter out blocked foods for this child
            $foods = db()->query(
                "SELECT fc.* 
                 FROM food_catalog fc
                 WHERE fc.blocked = 0
                 AND fc.id NOT IN (
                     SELECT food_catalog_id 
                     FROM child_meal_blocks cmb
                     JOIN meal_template_foods mtf ON cmb.meal_template_id = mtf.meal_template_id
                     WHERE cmb.child_id = ? AND cmb.blocked = 1
                 )
                 ORDER BY fc.category, fc.name",
                [$childId]
            );
        } elseif ($includeBlocked) {
            // Get all foods including blocked (for guardian management)
            $foods = db()->query(
                "SELECT * FROM food_catalog ORDER BY category, name"
            );
        } else {
            // Get all non-blocked foods
            $foods = db()->query(
                "SELECT * FROM food_catalog WHERE blocked = 0 ORDER BY category, name"
            );
        }
        
        return $foods;
    }
    
    /**
     * Create new food item
     * 
     * @param array $data Food data {name, category}
     * @param int $userId User creating the food
     * @return array Created food with ID
     */
    public static function createFood($data, $userId) {
        if (empty($data['name']) || empty($data['category'])) {
            throw new Exception('Missing required fields: name, category', 400);
        }
        
        $name = trim($data['name']);
        $category = $data['category'];
        
        // Validate category
        $validCategories = ['starter', 'main', 'dessert', 'drink', 'snack'];
        if (!in_array($category, $validCategories)) {
            throw new Exception('Invalid category. Must be: starter, main, dessert, drink, or snack', 400);
        }
        
        // Check for duplicate
        $existing = db()->queryOne(
            "SELECT id FROM food_catalog WHERE LOWER(name) = LOWER(?)",
            [$name]
        );
        
        if ($existing) {
            throw new Exception('Food item with this name already exists', 409);
        }
        
        $foodId = db()->insert(
            "INSERT INTO food_catalog (name, category, created_by) VALUES (?, ?, ?)",
            [$name, $category, $userId]
        );
        
        Auth::logAudit('FOOD_CREATED', 'food_catalog', $foodId, $userId, [
            'name' => $name,
            'category' => $category
        ]);
        
        return ['food_id' => $foodId];
    }
    
    /**
     * Update food item
     * 
     * @param int $foodId Food ID
     * @param array $data Update data {name, category}
     * @param int $userId User updating the food
     * @return bool Success
     */
    public static function updateFood($foodId, $data, $userId) {
        $food = db()->queryOne("SELECT * FROM food_catalog WHERE id = ?", [$foodId]);
        
        if (!$food) {
            throw new Exception('Food item not found', 404);
        }
        
        $name = isset($data['name']) ? trim($data['name']) : $food['name'];
        $category = $data['category'] ?? $food['category'];
        
        // Validate category
        $validCategories = ['starter', 'main', 'dessert', 'drink', 'snack'];
        if (!in_array($category, $validCategories)) {
            throw new Exception('Invalid category. Must be: starter, main, dessert, drink, or snack', 400);
        }
        
        db()->execute(
            "UPDATE food_catalog 
             SET name = ?, category = ?, updated_at = datetime('now') 
             WHERE id = ?",
            [$name, $category, $foodId]
        );
        
        Auth::logAudit('FOOD_EDITED', 'food_catalog', $foodId, $userId, [
            'name' => $name,
            'category' => $category
        ]);
        
        return true;
    }
    
    /**
     * Block food item (soft delete)
     * 
     * @param int $foodId Food ID
     * @param int $userId User blocking the food
     * @return bool Success
     */
    public static function blockFood($foodId, $userId) {
        $food = db()->queryOne("SELECT * FROM food_catalog WHERE id = ?", [$foodId]);
        
        if (!$food) {
            throw new Exception('Food item not found', 404);
        }
        
        db()->execute(
            "UPDATE food_catalog SET blocked = 1, updated_at = datetime('now') WHERE id = ?",
            [$foodId]
        );
        
        Auth::logAudit('FOOD_BLOCKED', 'food_catalog', $foodId, $userId, [
            'name' => $food['name']
        ]);
        
        return true;
    }
    
    /**
     * Unblock food item
     * 
     * @param int $foodId Food ID
     * @param int $userId User unblocking the food
     * @return bool Success
     */
    public static function unblockFood($foodId, $userId) {
        $food = db()->queryOne("SELECT * FROM food_catalog WHERE id = ?", [$foodId]);
        
        if (!$food) {
            throw new Exception('Food item not found', 404);
        }
        
        db()->execute(
            "UPDATE food_catalog SET blocked = 0, updated_at = datetime('now') WHERE id = ?",
            [$foodId]
        );
        
        Auth::logAudit('FOOD_UNBLOCKED', 'food_catalog', $foodId, $userId, [
            'name' => $food['name']
        ]);
        
        return true;
    }
    
    /**
     * Delete food item (hard delete)
     * 
     * @param int $foodId Food ID
     * @param int $userId User deleting the food
     * @return bool Success
     */
    public static function deleteFood($foodId, $userId) {
        $food = db()->queryOne("SELECT * FROM food_catalog WHERE id = ?", [$foodId]);
        
        if (!$food) {
            throw new Exception('Food item not found', 404);
        }
        
        // Check if food is referenced in any meal logs
        $usageCount = db()->queryValue(
            "SELECT COUNT(*) FROM food_quantities WHERE food_catalog_id = ?",
            [$foodId]
        );
        
        if ($usageCount > 0) {
            throw new Exception("Cannot delete food item. It is used in {$usageCount} meal log(s). Use block instead.", 409);
        }
        
        // Check if food is referenced in any meal templates
        $templateCount = db()->queryValue(
            "SELECT COUNT(*) FROM meal_template_foods WHERE food_catalog_id = ?",
            [$foodId]
        );
        
        if ($templateCount > 0) {
            throw new Exception("Cannot delete food item. It is used in {$templateCount} meal template(s). Use block instead.", 409);
        }
        
        db()->execute("DELETE FROM food_catalog WHERE id = ?", [$foodId]);
        
        Auth::logAudit('FOOD_DELETED', 'food_catalog', $foodId, $userId, [
            'name' => $food['name']
        ]);
        
        return true;
    }
}

class UserAPI {
    
    /**
     * List all users (guardians + children) with profiles
     * 
     * @return array Users grouped by role
     */
    public static function listUsers() {
        $guardians = db()->query(
            "SELECT u.id as user_id, u.role, u.locale, u.locked_until, u.created_at, u.updated_at,
                    g.id as profile_id, g.name
             FROM users u
             JOIN guardians g ON g.user_id = u.id
             WHERE u.role = 'guardian'
             ORDER BY g.name"
        );
        
        $children = db()->query(
            "SELECT u.id as user_id, u.role, u.locale, u.locked_until, u.created_at, u.updated_at,
                    c.id as profile_id, c.name, c.active
             FROM users u
             JOIN children c ON c.user_id = u.id
             WHERE u.role = 'child'
             ORDER BY c.name"
        );
        
        return [
            'guardians' => $guardians,
            'children' => $children
        ];
    }
    
    /**
     * Get single user by user_id
     * 
     * @param int $userId User ID (users table)
     * @return array User data with profile
     */
    public static function getUser($userId) {
        $user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            throw new Exception('User not found', 404);
        }
        
        $profile = null;
        if ($user['role'] === 'guardian') {
            $profile = db()->queryOne("SELECT * FROM guardians WHERE user_id = ?", [$userId]);
        } elseif ($user['role'] === 'child') {
            $profile = db()->queryOne("SELECT * FROM children WHERE user_id = ?", [$userId]);
        }
        
        return [
            'user_id' => $user['id'],
            'role' => $user['role'],
            'locale' => $user['locale'],
            'locked_until' => $user['locked_until'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at'],
            'profile' => $profile
        ];
    }
    
    /**
     * Create child account
     * 
     * @param array $data {name, pin, locale?}
     * @param int $actorId Guardian creating the child
     * @return array Created user and child IDs
     */
    public static function createChild($data, $actorId) {
        if (empty($data['name']) || empty($data['pin'])) {
            throw new Exception('Missing required fields: name, pin', 400);
        }
        
        $name = trim($data['name']);
        $pin = $data['pin'];
        $locale = $data['locale'] ?? DEFAULT_LOCALE;
        
        if (strlen($name) < 1 || strlen($name) > 100) {
            throw new Exception('Name must be between 1 and 100 characters', 400);
        }
        
        if (!preg_match('/^\d{4}$/', $pin)) {
            throw new Exception('PIN must be exactly 4 digits', 400);
        }
        
        if (!in_array($locale, SUPPORTED_LOCALES)) {
            throw new Exception('Unsupported locale', 400);
        }
        
        // Check for duplicate child name
        $existing = db()->queryOne(
            "SELECT id FROM children WHERE LOWER(name) = LOWER(?)",
            [$name]
        );
        if ($existing) {
            throw new Exception('A child with this name already exists', 409);
        }
        
        $pinHash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => PIN_HASH_COST]);
        
        db()->beginTransaction();
        try {
            $userId = db()->insert(
                "INSERT INTO users (role, pin_hash, locale) VALUES ('child', ?, ?)",
                [$pinHash, $locale]
            );
            
            $childId = db()->insert(
                "INSERT INTO children (user_id, name) VALUES (?, ?)",
                [$userId, $name]
            );
            
            db()->commit();
            
            Auth::logAudit('USER_CREATED', 'users', $userId, $actorId, [
                'role' => 'child',
                'name' => $name,
                'child_id' => $childId
            ]);
            
            return ['user_id' => $userId, 'child_id' => $childId];
            
        } catch (Exception $e) {
            db()->rollback();
            throw $e;
        }
    }
    
    /**
     * Create guardian account
     * 
     * @param array $data {name, pin, locale?}
     * @param int $actorId Guardian creating the account
     * @return array Created user and guardian IDs
     */
    public static function createGuardian($data, $actorId) {
        if (empty($data['name']) || empty($data['pin'])) {
            throw new Exception('Missing required fields: name, pin', 400);
        }
        
        $name = trim($data['name']);
        $pin = $data['pin'];
        $locale = $data['locale'] ?? DEFAULT_LOCALE;
        
        if (strlen($name) < 1 || strlen($name) > 100) {
            throw new Exception('Name must be between 1 and 100 characters', 400);
        }
        
        if (!preg_match('/^\d{4}$/', $pin)) {
            throw new Exception('PIN must be exactly 4 digits', 400);
        }
        
        if (!in_array($locale, SUPPORTED_LOCALES)) {
            throw new Exception('Unsupported locale', 400);
        }
        
        // Check for duplicate guardian name
        $existing = db()->queryOne(
            "SELECT id FROM guardians WHERE LOWER(name) = LOWER(?)",
            [$name]
        );
        if ($existing) {
            throw new Exception('A guardian with this name already exists', 409);
        }
        
        $pinHash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => PIN_HASH_COST]);
        
        db()->beginTransaction();
        try {
            $userId = db()->insert(
                "INSERT INTO users (role, pin_hash, locale) VALUES ('guardian', ?, ?)",
                [$pinHash, $locale]
            );
            
            $guardianId = db()->insert(
                "INSERT INTO guardians (user_id, name) VALUES (?, ?)",
                [$userId, $name]
            );
            
            db()->commit();
            
            Auth::logAudit('USER_CREATED', 'users', $userId, $actorId, [
                'role' => 'guardian',
                'name' => $name,
                'guardian_id' => $guardianId
            ]);
            
            return ['user_id' => $userId, 'guardian_id' => $guardianId];
            
        } catch (Exception $e) {
            db()->rollback();
            throw $e;
        }
    }
    
    /**
     * Edit user (name, locale)
     * 
     * @param int $userId User ID
     * @param array $data {name?, locale?}
     * @param int $actorId Guardian performing the edit
     * @return bool Success
     */
    public static function editUser($userId, $data, $actorId) {
        $user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            throw new Exception('User not found', 404);
        }
        
        // Update locale if provided
        if (isset($data['locale'])) {
            if (!in_array($data['locale'], SUPPORTED_LOCALES)) {
                throw new Exception('Unsupported locale', 400);
            }
            db()->execute(
                "UPDATE users SET locale = ?, updated_at = datetime('now') WHERE id = ?",
                [$data['locale'], $userId]
            );
        }
        
        // Update name in profile table if provided
        if (isset($data['name'])) {
            $name = trim($data['name']);
            if (strlen($name) < 1 || strlen($name) > 100) {
                throw new Exception('Name must be between 1 and 100 characters', 400);
            }
            
            if ($user['role'] === 'child') {
                db()->execute(
                    "UPDATE children SET name = ? WHERE user_id = ?",
                    [$name, $userId]
                );
            } elseif ($user['role'] === 'guardian') {
                db()->execute(
                    "UPDATE guardians SET name = ? WHERE user_id = ?",
                    [$name, $userId]
                );
            }
        }
        
        Auth::logAudit('USER_EDITED', 'users', $userId, $actorId, [
            'changes' => array_keys($data)
        ]);
        
        return true;
    }
    
    /**
     * Change PIN (requires current PIN)
     * 
     * @param int $userId User ID
     * @param array $data {current_pin, new_pin}
     * @param int $actorId Actor performing the change
     * @return bool Success
     */
    public static function changePinEndpoint($userId, $data, $actorId) {
        if (empty($data['current_pin']) || empty($data['new_pin'])) {
            throw new Exception('Missing required fields: current_pin, new_pin', 400);
        }
        
        return Auth::changePin($userId, $data['current_pin'], $data['new_pin']);
    }
    
    /**
     * Reset PIN (guardian override, no current PIN required)
     * 
     * @param int $userId Target user ID
     * @param array $data {new_pin}
     * @param int $actorId Guardian performing the reset
     * @return bool Success
     */
    public static function resetPinEndpoint($userId, $data, $actorId) {
        if (empty($data['new_pin'])) {
            throw new Exception('Missing required field: new_pin', 400);
        }
        
        // Only guardians can reset PINs, and cannot reset own PIN via this endpoint
        $target = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$target) {
            throw new Exception('User not found', 404);
        }
        
        return Auth::setPin($userId, $data['new_pin'], $actorId);
    }
    
    /**
     * Block user (deactivate)
     * 
     * @param int $userId User ID
     * @param int $actorId Guardian performing the block
     * @return bool Success
     */
    public static function blockUser($userId, $actorId) {
        $user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            throw new Exception('User not found', 404);
        }
        
        // Cannot block yourself
        if ($userId == $actorId) {
            throw new Exception('Cannot block your own account', 400);
        }
        
        if ($user['role'] === 'child') {
            db()->execute(
                "UPDATE children SET active = 0 WHERE user_id = ?",
                [$userId]
            );
        } elseif ($user['role'] === 'guardian') {
            // Lock guardian indefinitely (far future)
            Auth::lockUser($userId, 365 * 24 * 3600);
        }
        
        Auth::logAudit('USER_BLOCKED', 'users', $userId, $actorId, [
            'role' => $user['role']
        ]);
        
        return true;
    }
    
    /**
     * Unblock user (reactivate)
     * 
     * @param int $userId User ID
     * @param int $actorId Guardian performing the unblock
     * @return bool Success
     */
    public static function unblockUser($userId, $actorId) {
        $user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            throw new Exception('User not found', 404);
        }
        
        if ($user['role'] === 'child') {
            db()->execute(
                "UPDATE children SET active = 1 WHERE user_id = ?",
                [$userId]
            );
        } elseif ($user['role'] === 'guardian') {
            Auth::unlockUser($userId);
        }
        
        Auth::logAudit('USER_UNBLOCKED', 'users', $userId, $actorId, [
            'role' => $user['role']
        ]);
        
        return true;
    }
    
    /**
     * Delete user (hard delete if no references)
     * 
     * @param int $userId User ID
     * @param int $actorId Guardian performing the deletion
     * @return bool Success
     */
    public static function deleteUser($userId, $actorId) {
        $user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            throw new Exception('User not found', 404);
        }
        
        // Cannot delete yourself
        if ($userId == $actorId) {
            throw new Exception('Cannot delete your own account', 400);
        }
        
        if ($user['role'] === 'child') {
            $childProfile = db()->queryOne("SELECT id FROM children WHERE user_id = ?", [$userId]);
            if ($childProfile) {
                // Check for referenced data
                $mealCount = db()->queryValue(
                    "SELECT COUNT(*) FROM meal_logs WHERE child_id = ?",
                    [$childProfile['id']]
                );
                $weightCount = db()->queryValue(
                    "SELECT COUNT(*) FROM weight_logs WHERE child_id = ?",
                    [$childProfile['id']]
                );
                $medCount = db()->queryValue(
                    "SELECT COUNT(*) FROM medication_logs WHERE child_id = ?",
                    [$childProfile['id']]
                );
                
                $totalRefs = $mealCount + $weightCount + $medCount;
                if ($totalRefs > 0) {
                    throw new Exception(
                        "Cannot delete child. Has {$mealCount} meal(s), {$weightCount} weight(s), {$medCount} medication log(s). Use block instead.",
                        409
                    );
                }
                
                // Delete child profile, guest sessions, child_meal_blocks, child_medication_blocks
                db()->execute("DELETE FROM guest_sessions WHERE child_id = ?", [$childProfile['id']]);
                db()->execute("DELETE FROM child_meal_blocks WHERE child_id = ?", [$childProfile['id']]);
                db()->execute("DELETE FROM child_medication_blocks WHERE child_id = ?", [$childProfile['id']]);
                db()->execute("DELETE FROM children WHERE user_id = ?", [$userId]);
            }
        } elseif ($user['role'] === 'guardian') {
            // Check this isn't the last guardian
            $guardianCount = db()->queryValue(
                "SELECT COUNT(*) FROM users WHERE role = 'guardian'"
            );
            if ($guardianCount <= 1) {
                throw new Exception('Cannot delete the last guardian account', 400);
            }
            
            db()->execute("DELETE FROM guardians WHERE user_id = ?", [$userId]);
        }
        
        // Delete sessions
        db()->execute("DELETE FROM sessions WHERE user_id = ?", [$userId]);
        
        // Delete user
        db()->execute("DELETE FROM users WHERE id = ?", [$userId]);
        
        Auth::logAudit('USER_DELETED', 'users', $userId, $actorId, [
            'role' => $user['role']
        ]);
        
        return true;
    }
}

class WeightAPI {
    
    /**
     * Get weight for child on specific date
     * 
     * @param int $childId Child ID
     * @param string $date Date in YYYY-MM-DD format
     * @return array|null Weight log or null if not found
     */
    public static function getWeight($childId, $date) {
        // Validate date format and semantic validity (B09 fix)
        validateDate($date);
        
        $weight = db()->queryOne(
            "SELECT * FROM weight_logs 
             WHERE child_id = ? AND log_date = ? AND voided_at IS NULL",
            [$childId, $date]
        );
        
        return $weight;
    }
    
    /**
     * Log weight (auto-voids previous same-day entry)
     * 
     * @param array $data Weight data {child_id, log_date, weight_kg, uom}
     * @param int $userId User logging the weight
     * @return array Created weight log with ID
     */
    public static function logWeight($data, $userId) {
        if (empty($data['child_id']) || empty($data['log_date']) || empty($data['weight_kg'])) {
            throw new Exception('Missing required fields: child_id, log_date, weight_kg', 400);
        }
        
        $childId = $data['child_id'];
        $logDate = $data['log_date'];
        $weightKg = $data['weight_kg'];
        $uom = $data['uom'] ?? 'kg';
        
        // Validate date format and semantic validity (B09 fix)
        validateDate($logDate);
        
        // Validate weight
        if (!is_numeric($weightKg) || $weightKg <= 0 || $weightKg > 999.99) {
            throw new Exception('weight_kg must be between 0.01 and 999.99', 400);
        }
        
        // Round to 2 decimals
        $weightKg = round($weightKg, 2);
        
        // Begin transaction
        db()->beginTransaction();
        
        try {
            // Check for existing weight on same date
            $existing = db()->queryOne(
                "SELECT id FROM weight_logs 
                 WHERE child_id = ? AND log_date = ? AND voided_at IS NULL",
                [$childId, $logDate]
            );
            
            // Void existing weight if found
            if ($existing) {
                $voidedAt = date('Y-m-d H:i:s');
                db()->execute(
                    "UPDATE weight_logs SET voided_at = ? WHERE id = ?",
                    [$voidedAt, $existing['id']]
                );
                
                Auth::logAudit('WEIGHT_VOIDED', 'weight_logs', $existing['id'], $userId, [
                    'reason' => 'auto_void_on_new_entry'
                ]);
            }
            
            // Insert new weight log
            $weightLogId = db()->insert(
                "INSERT INTO weight_logs (child_id, log_date, weight_kg, uom, created_by) 
                 VALUES (?, ?, ?, ?, ?)",
                [$childId, $logDate, $weightKg, $uom, $userId]
            );
            
            // Commit transaction
            db()->commit();
            
            // Log audit event
            Auth::logAudit('WEIGHT_LOGGED', 'weight_logs', $weightLogId, $userId, [
                'child_id' => $childId,
                'log_date' => $logDate,
                'weight_kg' => $weightKg,
                'voided_previous' => isset($existing)
            ]);
            
            return [
                'weight_log_id' => $weightLogId,
                'voided_previous_id' => $existing['id'] ?? null
            ];
            
        } catch (Exception $e) {
            db()->rollback();
            throw $e;
        }
    }
}

/**
 * MealTemplateAPI — Meal template master data CRUD
 * Sprint 9
 */
class MealTemplateAPI {
    
    /**
     * List all meal templates
     * 
     * @param bool $includeBlocked Include blocked templates
     * @return array Templates ordered by sort_order
     */
    public static function listTemplates($includeBlocked = false) {
        if ($includeBlocked) {
            return db()->query("SELECT * FROM meal_templates ORDER BY sort_order, id");
        }
        return db()->query("SELECT * FROM meal_templates WHERE blocked = 0 ORDER BY sort_order, id");
    }
    
    /**
     * Get single meal template
     */
    public static function getTemplate($templateId) {
        $tpl = db()->queryOne("SELECT * FROM meal_templates WHERE id = ?", [$templateId]);
        if (!$tpl) {
            throw new Exception('Meal template not found', 404);
        }
        return $tpl;
    }
    
    /**
     * Create meal template
     * 
     * @param array $data {name, icon?, sort_order?}
     * @param int $userId Guardian creating
     * @return array Created template ID
     */
    public static function createTemplate($data, $userId) {
        if (empty($data['name'])) {
            throw new Exception('Missing required field: name', 400);
        }
        
        $name = trim($data['name']);
        $icon = isset($data['icon']) ? trim($data['icon']) : '🍽️';
        
        if (strlen($name) < 1 || strlen($name) > 100) {
            throw new Exception('Name must be between 1 and 100 characters', 400);
        }
        
        if (mb_strlen($icon) > 10) {
            throw new Exception('Icon must be 10 characters or fewer', 400);
        }
        
        // Duplicate name check
        $existing = db()->queryOne(
            "SELECT id FROM meal_templates WHERE LOWER(name) = LOWER(?)",
            [$name]
        );
        if ($existing) {
            throw new Exception('A meal template with this name already exists', 409);
        }
        
        // Auto sort_order: append after last
        if (isset($data['sort_order']) && is_numeric($data['sort_order'])) {
            $sortOrder = (int)$data['sort_order'];
        } else {
            $maxOrder = db()->queryValue("SELECT COALESCE(MAX(sort_order), 0) FROM meal_templates");
            $sortOrder = $maxOrder + 1;
        }
        
        $tplId = db()->insert(
            "INSERT INTO meal_templates (name, icon, sort_order) VALUES (?, ?, ?)",
            [$name, $icon, $sortOrder]
        );
        
        Auth::logAudit('TEMPLATE_CREATED', 'meal_templates', $tplId, $userId, [
            'name' => $name, 'icon' => $icon
        ]);
        
        return ['template_id' => $tplId];
    }
    
    /**
     * Update meal template
     * 
     * @param int $templateId
     * @param array $data {name?, icon?, sort_order?}
     * @param int $userId Guardian editing
     * @return bool
     */
    public static function updateTemplate($templateId, $data, $userId) {
        $tpl = db()->queryOne("SELECT * FROM meal_templates WHERE id = ?", [$templateId]);
        if (!$tpl) {
            throw new Exception('Meal template not found', 404);
        }
        
        $name = isset($data['name']) ? trim($data['name']) : $tpl['name'];
        $icon = isset($data['icon']) ? trim($data['icon']) : $tpl['icon'];
        $sortOrder = isset($data['sort_order']) && is_numeric($data['sort_order']) ? (int)$data['sort_order'] : $tpl['sort_order'];
        
        if (strlen($name) < 1 || strlen($name) > 100) {
            throw new Exception('Name must be between 1 and 100 characters', 400);
        }
        
        // Duplicate name check (exclude self)
        $existing = db()->queryOne(
            "SELECT id FROM meal_templates WHERE LOWER(name) = LOWER(?) AND id != ?",
            [$name, $templateId]
        );
        if ($existing) {
            throw new Exception('A meal template with this name already exists', 409);
        }
        
        db()->execute(
            "UPDATE meal_templates SET name = ?, icon = ?, sort_order = ?, updated_at = datetime('now') WHERE id = ?",
            [$name, $icon, $sortOrder, $templateId]
        );
        
        Auth::logAudit('TEMPLATE_EDITED', 'meal_templates', $templateId, $userId, [
            'name' => $name, 'icon' => $icon, 'sort_order' => $sortOrder
        ]);
        
        return true;
    }
    
    /**
     * Reorder all templates
     * 
     * @param array $data {order: [{id, sort_order}, ...]}
     * @param int $userId Guardian reordering
     * @return bool
     */
    public static function reorderTemplates($data, $userId) {
        if (empty($data['order']) || !is_array($data['order'])) {
            throw new Exception('Missing required field: order (array of {id, sort_order})', 400);
        }
        
        db()->beginTransaction();
        try {
            foreach ($data['order'] as $item) {
                if (empty($item['id']) || !isset($item['sort_order'])) continue;
                db()->execute(
                    "UPDATE meal_templates SET sort_order = ?, updated_at = datetime('now') WHERE id = ?",
                    [(int)$item['sort_order'], (int)$item['id']]
                );
            }
            db()->commit();
        } catch (Exception $e) {
            db()->rollback();
            throw $e;
        }
        
        Auth::logAudit('TEMPLATES_REORDERED', 'meal_templates', null, $userId, [
            'count' => count($data['order'])
        ]);
        
        return true;
    }
    
    /**
     * Block meal template
     */
    public static function blockTemplate($templateId, $userId) {
        $tpl = db()->queryOne("SELECT * FROM meal_templates WHERE id = ?", [$templateId]);
        if (!$tpl) {
            throw new Exception('Meal template not found', 404);
        }
        
        db()->execute(
            "UPDATE meal_templates SET blocked = 1, updated_at = datetime('now') WHERE id = ?",
            [$templateId]
        );
        
        Auth::logAudit('TEMPLATE_BLOCKED', 'meal_templates', $templateId, $userId, [
            'name' => $tpl['name']
        ]);
        
        return true;
    }
    
    /**
     * Unblock meal template
     */
    public static function unblockTemplate($templateId, $userId) {
        $tpl = db()->queryOne("SELECT * FROM meal_templates WHERE id = ?", [$templateId]);
        if (!$tpl) {
            throw new Exception('Meal template not found', 404);
        }
        
        db()->execute(
            "UPDATE meal_templates SET blocked = 0, updated_at = datetime('now') WHERE id = ?",
            [$templateId]
        );
        
        Auth::logAudit('TEMPLATE_UNBLOCKED', 'meal_templates', $templateId, $userId, [
            'name' => $tpl['name']
        ]);
        
        return true;
    }
    
    /**
     * Delete meal template (hard delete if no logs reference it)
     */
    public static function deleteTemplate($templateId, $userId) {
        $tpl = db()->queryOne("SELECT * FROM meal_templates WHERE id = ?", [$templateId]);
        if (!$tpl) {
            throw new Exception('Meal template not found', 404);
        }
        
        $logCount = db()->queryValue(
            "SELECT COUNT(*) FROM meal_logs WHERE meal_template_id = ?",
            [$templateId]
        );
        
        if ($logCount > 0) {
            throw new Exception("Cannot delete template. It is used in {$logCount} meal log(s). Use block instead.", 409);
        }
        
        // Clean up template foods
        db()->execute("DELETE FROM meal_template_foods WHERE meal_template_id = ?", [$templateId]);
        db()->execute("DELETE FROM meal_templates WHERE id = ?", [$templateId]);
        
        Auth::logAudit('TEMPLATE_DELETED', 'meal_templates', $templateId, $userId, [
            'name' => $tpl['name']
        ]);
        
        return true;
    }
}

/**
 * HistoryAPI — Aggregated history view for meals, medications, weights
 * Sprint 8
 */
class HistoryAPI {
    
    /**
     * Get combined history for a child over a date range
     * 
     * @param int $childId
     * @param string $startDate YYYY-MM-DD
     * @param string $endDate YYYY-MM-DD
     * @return array {meals: [], medications: [], weights: []}
     */
    public static function getHistory($childId, $startDate, $endDate) {
        // Validate date format and semantic validity (B09 fix)
        validateDate($startDate);
        validateDate($endDate);
        
        // Cap range to 90 days
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $diff = $start->diff($end)->days;
        if ($diff > 90) {
            throw new Exception('Date range cannot exceed 90 days', 400);
        }
        
        // Meals with foods
        $meals = db()->query(
            "SELECT ml.id, ml.log_date, ml.meal_template_id, ml.note,
                    ml.reviewed_by, ml.reviewed_at, ml.voided_at,
                    mt.name as meal_name, mt.icon as meal_icon, mt.translation_key as meal_translation_key
             FROM meal_logs ml
             LEFT JOIN meal_templates mt ON ml.meal_template_id = mt.id
             WHERE ml.child_id = ? AND ml.log_date BETWEEN ? AND ? AND ml.voided_at IS NULL
             ORDER BY ml.log_date DESC, ml.created_at DESC",
            [$childId, $startDate, $endDate]
        );
        
        // Attach foods to each meal
        foreach ($meals as &$meal) {
            $meal['foods'] = db()->query(
                "SELECT fq.food_catalog_id, fq.quantity_decimal, fc.name as food_name
                 FROM food_quantities fq
                 LEFT JOIN food_catalog fc ON fq.food_catalog_id = fc.id
                 WHERE fq.meal_log_id = ?",
                [$meal['id']]
            );
            $meal['is_reviewed'] = !empty($meal['reviewed_by']);
        }
        unset($meal);
        
        // Medications
        $medications = db()->query(
            "SELECT ml.id, ml.log_date, ml.log_time, ml.status, ml.notes,
                    m.name as medication_name, m.dose as medication_dose
             FROM medication_logs ml
             JOIN medications m ON ml.medication_id = m.id
             WHERE ml.child_id = ? AND ml.log_date BETWEEN ? AND ?
             ORDER BY ml.log_date DESC, ml.log_time DESC",
            [$childId, $startDate, $endDate]
        );
        
        // Weights
        $weights = db()->query(
            "SELECT id, log_date, weight_kg, uom, voided_at
             FROM weight_logs
             WHERE child_id = ? AND log_date BETWEEN ? AND ? AND voided_at IS NULL
             ORDER BY log_date DESC",
            [$childId, $startDate, $endDate]
        );
        
        return [
            'meals' => $meals,
            'medications' => $medications,
            'weights' => $weights,
            'range' => ['start' => $startDate, 'end' => $endDate]
        ];
    }
}
