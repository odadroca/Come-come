<?php
/**
 * PDF Report Generation — Sprint 3
 * Clinician export: locked 1-page executive PDF
 */

class PDFReport {
    
    /**
     * Generate clinician report for child
     * 
     * @param int $childId Child ID
     * @param string $range Report range: '30' or 'all'
     * @param int|null $userId User generating report (for audit)
     * @return string PDF content (binary)
     */
    public static function generateReport($childId, $range = '30', $userId = null) {
        // Validate child exists
        $child = db()->queryOne("SELECT * FROM children WHERE id = ?", [$childId]);
        if (!$child) {
            throw new Exception('Child not found', 404);
        }
        
        // Calculate date range
        $endDate = date('Y-m-d');
        if ($range === 'all') {
            // Cap at 365 days
            $startDate = date('Y-m-d', strtotime('-365 days'));
        } else {
            // Default 30 days
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        
        // Gather data
        $data = [
            'child' => $child,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'generated_at' => date('Y-m-d H:i:s'),
            'generated_by' => $userId ? self::getGeneratorName($userId) : 'Guest',
            'weights' => self::getWeightData($childId, $startDate, $endDate),
            'medications' => self::getMedicationData($childId, $startDate, $endDate),
            'meal_counts' => self::getMealCountData($childId, $startDate, $endDate),
            'meals_by_template' => self::getMealsByTemplate($childId, $startDate, $endDate),
            'intake_by_category' => self::getIntakeByCategory($childId, $startDate, $endDate)
        ];
        
        // Generate PDF
        $pdf = self::createPDF($data);
        
        // Log audit
        if ($userId) {
            Auth::logAudit('REPORT_GENERATED', 'children', $childId, $userId, [
                'range' => $range,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
        }
        
        return $pdf;
    }
    
    /**
     * Create PDF document
     * 
     * @param array $data Report data
     * @return string HTML content with print-optimized styling
     */
    private static function createPDF($data) {
        // Generate print-optimized HTML report
        // This replaces the dummy PDF skeleton with a proper printable HTML page
        // Users can print to PDF from their browser (Ctrl+P / Cmd+P)
        
        return self::generateHTML($data);
    }
    
    /**
     * Generate HTML for PDF content
     * 
     * @param array $data Report data
     * @return string HTML content
     */
    private static function generateHTML($data) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Come-Come Report</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 9pt; }
                .header { text-align: center; margin-bottom: 20px; }
                .section { margin-bottom: 15px; }
                table { width: 100%; border-collapse: collapse; font-size: 8pt; }
                th, td { border: 1px solid #ddd; padding: 4px; text-align: left; }
                th { background: #f0f0f0; font-weight: bold; }
                .footer { text-align: center; font-size: 7pt; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Come-Come Report</h1>
                <p><strong><?php echo htmlspecialchars($data['child']['name']); ?></strong></p>
                <p><?php echo $data['start_date']; ?> to <?php echo $data['end_date']; ?></p>
                <p style="font-size: 7pt;">Generated: <?php echo $data['generated_at']; ?> by <?php echo htmlspecialchars($data['generated_by']); ?></p>
            </div>
            
            <div class="section">
                <h3>Weight Timeline</h3>
                <?php if (empty($data['weights'])): ?>
                    <p>No weight data for this period.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Weight (kg)</th>
                                <th>Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $prevWeight = null;
                            foreach ($data['weights'] as $weight): 
                                $change = $prevWeight ? round($weight['weight_kg'] - $prevWeight, 2) : 0;
                                $changeStr = $change > 0 ? "+{$change}" : ($change < 0 ? "{$change}" : "—");
                            ?>
                                <tr>
                                    <td><?php echo $weight['log_date']; ?></td>
                                    <td><?php echo $weight['weight_kg']; ?></td>
                                    <td><?php echo $changeStr; ?></td>
                                </tr>
                            <?php 
                                $prevWeight = $weight['weight_kg'];
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="section">
                <h3>Medication Adherence</h3>
                <?php if (empty($data['medications'])): ?>
                    <p>No medication data for this period.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Medication</th>
                                <th>Dose</th>
                                <th>Taken</th>
                                <th>Missed</th>
                                <th>Adherence %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['medications'] as $med): 
                                $total = $med['taken'] + $med['missed'];
                                $adherence = $total > 0 ? round(($med['taken'] / $total) * 100, 1) : 0;
                                $style = $adherence < 80 ? 'color: red; font-weight: bold;' : '';
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($med['name']); ?></td>
                                    <td><?php echo htmlspecialchars($med['dose']); ?></td>
                                    <td><?php echo $med['taken']; ?></td>
                                    <td><?php echo $med['missed']; ?></td>
                                    <td style="<?php echo $style; ?>"><?php echo $adherence; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="section">
                <h3>Daily Meal Count</h3>
                <?php 
                    $totalTemplates = db()->queryValue("SELECT COUNT(*) FROM meal_templates WHERE blocked = 0");
                    $totalTemplates = $totalTemplates ?: 6;
                ?>
                <p style="font-size: 8pt;">Number of meals logged per day (max <?php echo $totalTemplates; ?>).</p>
                <?php if (empty($data['meal_counts'])): ?>
                    <p>No meal data for this period.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Meals Logged</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['meal_counts'] as $day): ?>
                                <tr>
                                    <td><?php echo $day['log_date']; ?></td>
                                    <td><?php echo $day['meal_count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($data['meals_by_template'])): ?>
            <div class="section">
                <h3>Meals by Type</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Meal Type</th>
                            <th>Times Logged</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['meals_by_template'] as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['template_name']); ?></td>
                                <td><?php echo $row['count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <div class="section">
                <h3>Intake Quantity by Category</h3>
                <p style="font-size: 8pt;">Sum of food quantities consumed per category.</p>
                <?php if (empty($data['intake_by_category'])): ?>
                    <p>No intake data for this period.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Total Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['intake_by_category'] as $cat): ?>
                                <tr>
                                    <td><?php echo ucfirst($cat['category']); ?></td>
                                    <td><?php echo round($cat['total_quantity'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="footer">
                <p>Generated by Come-Come v<?php echo defined('APP_VERSION') ? APP_VERSION : '0.100'; ?> — <strong>Page 1 of 1</strong></p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get generator name from user ID
     */
    private static function getGeneratorName($userId) {
        $user = db()->queryOne(
            "SELECT u.role, g.name as guardian_name, c.name as child_name
             FROM users u
             LEFT JOIN guardians g ON u.id = g.user_id
             LEFT JOIN children c ON u.id = c.user_id
             WHERE u.id = ?",
            [$userId]
        );
        
        return $user ? ($user['guardian_name'] ?? $user['child_name'] ?? 'User') : 'Unknown';
    }
    
    /**
     * Get weight data for date range
     */
    private static function getWeightData($childId, $startDate, $endDate) {
        return db()->query(
            "SELECT log_date, weight_kg 
             FROM weight_logs 
             WHERE child_id = ? AND log_date BETWEEN ? AND ? AND voided_at IS NULL
             ORDER BY log_date ASC",
            [$childId, $startDate, $endDate]
        );
    }
    
    /**
     * Get medication adherence data
     */
    private static function getMedicationData($childId, $startDate, $endDate) {
        return db()->query(
            "SELECT m.name, m.dose,
                    SUM(CASE WHEN ml.status = 'taken' THEN 1 ELSE 0 END) as taken,
                    SUM(CASE WHEN ml.status = 'missed' THEN 1 ELSE 0 END) as missed
             FROM medication_logs ml
             JOIN medications m ON ml.medication_id = m.id
             WHERE ml.child_id = ? AND ml.log_date BETWEEN ? AND ?
             GROUP BY m.id, m.name, m.dose
             ORDER BY m.name",
            [$childId, $startDate, $endDate]
        );
    }
    
    /**
     * Get meal count per day
     */
    private static function getMealCountData($childId, $startDate, $endDate) {
        return db()->query(
            "SELECT log_date, COUNT(*) as meal_count
             FROM meal_logs
             WHERE child_id = ? AND log_date BETWEEN ? AND ? AND voided_at IS NULL
             GROUP BY log_date
             ORDER BY log_date ASC",
            [$childId, $startDate, $endDate]
        );
    }
    
    /**
     * Get intake by food category
     */
    private static function getIntakeByCategory($childId, $startDate, $endDate) {
        return db()->query(
            "SELECT fc.category, SUM(fq.quantity_decimal) as total_quantity
             FROM food_quantities fq
             JOIN meal_logs ml ON fq.meal_log_id = ml.id
             JOIN food_catalog fc ON fq.food_catalog_id = fc.id
             WHERE ml.child_id = ? AND ml.log_date BETWEEN ? AND ? AND ml.voided_at IS NULL
             GROUP BY fc.category
             ORDER BY fc.category",
            [$childId, $startDate, $endDate]
        );
    }
    
    /**
     * Get meal count grouped by template type
     */
    private static function getMealsByTemplate($childId, $startDate, $endDate) {
        return db()->query(
            "SELECT mt.name as template_name, COUNT(*) as count
             FROM meal_logs ml
             JOIN meal_templates mt ON ml.meal_template_id = mt.id
             WHERE ml.child_id = ? AND ml.log_date BETWEEN ? AND ? AND ml.voided_at IS NULL
             GROUP BY mt.id, mt.name
             ORDER BY mt.sort_order",
            [$childId, $startDate, $endDate]
        );
    }
}

class MedicationAPI {
    
    /**
     * Get medication logs for child on specific date
     */
    public static function getMedications($childId, $date) {
        // Validate date format and semantic validity (B09 fix)
        validateDate($date);
        
        $logs = db()->query(
            "SELECT ml.*, m.name, m.dose
             FROM medication_logs ml
             JOIN medications m ON ml.medication_id = m.id
             WHERE ml.child_id = ? AND ml.log_date = ?
             ORDER BY ml.log_time, ml.created_at",
            [$childId, $date]
        );
        
        return $logs;
    }
    
    /**
     * Log medication intake
     */
    public static function logMedication($data, $userId) {
        if (empty($data['child_id']) || empty($data['medication_id']) || 
            empty($data['log_date']) || empty($data['status'])) {
            throw new Exception('Missing required fields: child_id, medication_id, log_date, status', 400);
        }
        
        $childId = $data['child_id'];
        $medicationId = $data['medication_id'];
        $logDate = $data['log_date'];
        $logTime = $data['log_time'] ?? null;
        $status = $data['status'];
        $notes = $data['notes'] ?? '';
        
        // Validate date format and semantic validity (B09 fix)
        validateDate($logDate);
        
        // Validate status
        if (!in_array($status, ['taken', 'missed', 'skipped'])) {
            throw new Exception('Invalid status. Must be: taken, missed, or skipped', 400);
        }
        
        // Insert medication log
        $logId = db()->insert(
            "INSERT INTO medication_logs (child_id, medication_id, log_date, log_time, status, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$childId, $medicationId, $logDate, $logTime, $status, $notes, $userId]
        );
        
        Auth::logAudit('MEDICATION_LOGGED', 'medication_logs', $logId, $userId, [
            'child_id' => $childId,
            'medication_id' => $medicationId,
            'status' => $status
        ]);
        
        return ['medication_log_id' => $logId];
    }
    
    /**
     * Get available medications for child
     */
    public static function getAvailableMedications($childId) {
        return db()->query(
            "SELECT m.* 
             FROM medications m
             WHERE m.blocked = 0
             AND m.id NOT IN (
                 SELECT medication_id 
                 FROM child_medication_blocks 
                 WHERE child_id = ? AND blocked = 1
             )
             ORDER BY m.name",
            [$childId]
        );
    }
}

class MedicationCatalogAPI {
    
    /**
     * Get all medications (master data catalog)
     * 
     * @param bool $includeBlocked Include blocked medications
     * @return array Medications
     */
    public static function listMedications($includeBlocked = false) {
        if ($includeBlocked) {
            return db()->query(
                "SELECT * FROM medications ORDER BY name"
            );
        }
        return db()->query(
            "SELECT * FROM medications WHERE blocked = 0 ORDER BY name"
        );
    }
    
    /**
     * Get single medication by ID
     * 
     * @param int $medicationId
     * @return array Medication data
     */
    public static function getMedication($medicationId) {
        $med = db()->queryOne("SELECT * FROM medications WHERE id = ?", [$medicationId]);
        if (!$med) {
            throw new Exception('Medication not found', 404);
        }
        return $med;
    }
    
    /**
     * Create new medication
     * 
     * @param array $data {name, dose, notes?}
     * @param int $userId Guardian creating the medication
     * @return array Created medication with ID
     */
    public static function createMedication($data, $userId) {
        if (empty($data['name']) || empty($data['dose'])) {
            throw new Exception('Missing required fields: name, dose', 400);
        }
        
        $name = trim($data['name']);
        $dose = trim($data['dose']);
        $notes = isset($data['notes']) ? trim($data['notes']) : null;
        
        if (strlen($name) < 1 || strlen($name) > 200) {
            throw new Exception('Name must be between 1 and 200 characters', 400);
        }
        
        if (strlen($dose) < 1 || strlen($dose) > 100) {
            throw new Exception('Dose must be between 1 and 100 characters', 400);
        }
        
        // Check for duplicate name+dose
        $existing = db()->queryOne(
            "SELECT id FROM medications WHERE LOWER(name) = LOWER(?) AND LOWER(dose) = LOWER(?)",
            [$name, $dose]
        );
        if ($existing) {
            throw new Exception('A medication with this name and dose already exists', 409);
        }
        
        $medId = db()->insert(
            "INSERT INTO medications (name, dose, notes) VALUES (?, ?, ?)",
            [$name, $dose, $notes]
        );
        
        Auth::logAudit('MEDICATION_CREATED', 'medications', $medId, $userId, [
            'name' => $name,
            'dose' => $dose
        ]);
        
        return ['medication_id' => $medId];
    }
    
    /**
     * Update medication
     * 
     * @param int $medicationId
     * @param array $data {name?, dose?, notes?}
     * @param int $userId Guardian performing edit
     * @return bool Success
     */
    public static function updateMedication($medicationId, $data, $userId) {
        $med = db()->queryOne("SELECT * FROM medications WHERE id = ?", [$medicationId]);
        if (!$med) {
            throw new Exception('Medication not found', 404);
        }
        
        $name = isset($data['name']) ? trim($data['name']) : $med['name'];
        $dose = isset($data['dose']) ? trim($data['dose']) : $med['dose'];
        $notes = array_key_exists('notes', $data) ? ($data['notes'] !== null ? trim($data['notes']) : null) : $med['notes'];
        
        if (strlen($name) < 1 || strlen($name) > 200) {
            throw new Exception('Name must be between 1 and 200 characters', 400);
        }
        
        if (strlen($dose) < 1 || strlen($dose) > 100) {
            throw new Exception('Dose must be between 1 and 100 characters', 400);
        }
        
        db()->execute(
            "UPDATE medications SET name = ?, dose = ?, notes = ?, updated_at = datetime('now') WHERE id = ?",
            [$name, $dose, $notes, $medicationId]
        );
        
        Auth::logAudit('MEDICATION_EDITED', 'medications', $medicationId, $userId, [
            'name' => $name,
            'dose' => $dose
        ]);
        
        return true;
    }
    
    /**
     * Block medication (soft deactivate)
     * 
     * @param int $medicationId
     * @param int $userId Guardian performing block
     * @return bool Success
     */
    public static function blockMedication($medicationId, $userId) {
        $med = db()->queryOne("SELECT * FROM medications WHERE id = ?", [$medicationId]);
        if (!$med) {
            throw new Exception('Medication not found', 404);
        }
        
        db()->execute(
            "UPDATE medications SET blocked = 1, updated_at = datetime('now') WHERE id = ?",
            [$medicationId]
        );
        
        Auth::logAudit('MEDICATION_BLOCKED', 'medications', $medicationId, $userId, [
            'name' => $med['name']
        ]);
        
        return true;
    }
    
    /**
     * Unblock medication
     * 
     * @param int $medicationId
     * @param int $userId Guardian performing unblock
     * @return bool Success
     */
    public static function unblockMedication($medicationId, $userId) {
        $med = db()->queryOne("SELECT * FROM medications WHERE id = ?", [$medicationId]);
        if (!$med) {
            throw new Exception('Medication not found', 404);
        }
        
        db()->execute(
            "UPDATE medications SET blocked = 0, updated_at = datetime('now') WHERE id = ?",
            [$medicationId]
        );
        
        Auth::logAudit('MEDICATION_UNBLOCKED', 'medications', $medicationId, $userId, [
            'name' => $med['name']
        ]);
        
        return true;
    }
    
    /**
     * Delete medication (hard delete if no references)
     * 
     * @param int $medicationId
     * @param int $userId Guardian performing deletion
     * @return bool Success
     */
    public static function deleteMedication($medicationId, $userId) {
        $med = db()->queryOne("SELECT * FROM medications WHERE id = ?", [$medicationId]);
        if (!$med) {
            throw new Exception('Medication not found', 404);
        }
        
        // Check if medication is referenced in any logs
        $usageCount = db()->queryValue(
            "SELECT COUNT(*) FROM medication_logs WHERE medication_id = ?",
            [$medicationId]
        );
        
        if ($usageCount > 0) {
            throw new Exception("Cannot delete medication. It is used in {$usageCount} log(s). Use block instead.", 409);
        }
        
        // Check if medication has per-child blocks
        db()->execute("DELETE FROM child_medication_blocks WHERE medication_id = ?", [$medicationId]);
        
        db()->execute("DELETE FROM medications WHERE id = ?", [$medicationId]);
        
        Auth::logAudit('MEDICATION_DELETED', 'medications', $medicationId, $userId, [
            'name' => $med['name']
        ]);
        
        return true;
    }
}

class GuestAPI {
    
    /**
     * Create guest session token for clinician
     */
    public static function createToken($data, $guardianId) {
        if (empty($data['child_id']) || empty($data['expires_in'])) {
            throw new Exception('Missing required fields: child_id, expires_in', 400);
        }
        
        $childId = $data['child_id'];
        $expiresIn = $data['expires_in'];
        
        // Validate expires_in (seconds)
        $validDurations = [1800, 7200, 43200, 86400]; // 30min, 2h, 12h, 1day
        if (!in_array($expiresIn, $validDurations)) {
            throw new Exception('Invalid expires_in. Must be: 1800, 7200, 43200, or 86400', 400);
        }
        
        // Generate token
        $token = bin2hex(random_bytes(GUEST_TOKEN_LENGTH));
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        
        // Insert guest session
        db()->insert(
            "INSERT INTO guest_sessions (token, child_id, expires_at, created_by)
             VALUES (?, ?, ?, ?)",
            [$token, $childId, $expiresAt, $guardianId]
        );
        
        // Generate URL
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $url = "{$protocol}://{$host}/guest/{$token}";
        
        Auth::logAudit('TOKEN_CREATED', 'guest_sessions', null, $guardianId, [
            'child_id' => $childId,
            'expires_at' => $expiresAt
        ]);
        
        return [
            'token' => $token,
            'url' => $url,
            'expires_at' => $expiresAt
        ];
    }
    
    /**
     * Validate guest token
     */
    public static function validateToken($token) {
        $session = db()->queryOne(
            "SELECT * FROM guest_sessions 
             WHERE token = ? AND expires_at > datetime('now') AND revoked_at IS NULL",
            [$token]
        );
        
        return $session;
    }
    
    /**
     * Revoke guest token
     */
    public static function revokeToken($token, $guardianId) {
        $session = db()->queryOne("SELECT * FROM guest_sessions WHERE token = ?", [$token]);
        
        if (!$session) {
            throw new Exception('Token not found', 404);
        }
        
        db()->execute(
            "UPDATE guest_sessions SET revoked_at = datetime('now') WHERE token = ?",
            [$token]
        );
        
        Auth::logAudit('TOKEN_REVOKED', 'guest_sessions', null, $guardianId, [
            'token' => substr($token, 0, 8) . '...',
            'child_id' => $session['child_id']
        ]);
        
        return true;
    }
    
    /**
     * List active tokens for guardian
     */
    public static function listTokens($guardianId) {
        return db()->query(
            "SELECT gs.token, gs.child_id, c.name as child_name, gs.expires_at, gs.created_at,
                    gs.revoked_at IS NOT NULL as is_revoked
             FROM guest_sessions gs
             JOIN children c ON gs.child_id = c.id
             WHERE gs.created_by = ?
             ORDER BY gs.created_at DESC
             LIMIT 50",
            [$guardianId]
        );
    }
}
