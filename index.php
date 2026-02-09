<?php
/**
 * Come-Come Entry Point
 * Routes all requests to appropriate handlers
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/api.php';
require_once __DIR__ . '/src/i18n.php';
require_once __DIR__ . '/src/pdf.php';
require_once __DIR__ . '/src/backup.php';

// Set JSON response headers
header('Content-Type: application/json');

// CORS (currently disabled for same-origin)
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
// header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');

// Get request body for POST/PATCH
$input = null;
if (in_array($method, ['POST', 'PATCH'])) {
    $input = json_decode(file_get_contents('php://input'), true);
}

// Get session token from cookie
$sessionToken = $_COOKIE[SESSION_COOKIE_NAME] ?? null;

/**
 * Send JSON response
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

/**
 * Send error response
 */
function errorResponse($message, $statusCode = 400, $details = []) {
    jsonResponse([
        'error' => [
            'code' => $statusCode,
            'message' => $message,
            'details' => $details
        ]
    ], $statusCode);
}

/**
 * Require authentication
 */
function requireAuth() {
    global $sessionToken;
    
    if (!$sessionToken) {
        errorResponse('Authentication required', 401);
    }
    
    $session = Auth::validateSession($sessionToken);
    if (!$session) {
        errorResponse('Invalid or expired session', 401);
    }
    
    return $session;
}

/**
 * Require guardian role
 */
function requireGuardian() {
    $session = requireAuth();
    
    if ($session['role'] !== 'guardian') {
        errorResponse('Guardian access required', 403);
    }
    
    return $session;
}

// Check if database is initialized (except for install endpoint)
if ($uri !== '/install.php' && !db()->isInitialized()) {
    errorResponse('Database not initialized. Please run install.php', 500);
}

// Route handling
try {
    // Handle /index.php - redirect to root
    if ($uri === '/index.php' || $uri === '') {
        // Serve app.html for root path
        header('Content-Type: text/html');
        readfile(__DIR__ . '/app.html');
        exit;
    }
    
    // Authentication endpoints
    if ($uri === '/auth/login' && $method === 'POST') {
        $role = $input['role'] ?? null;
        $userId = $input['user_id'] ?? null;
        $pin = $input['pin'] ?? null;
        
        if (!$role || !$userId || !$pin) {
            errorResponse('Missing required fields: role, user_id, pin', 400);
        }
        
        $result = Auth::login($role, $userId, $pin);
        
        // Set session cookie
        setcookie(
            SESSION_COOKIE_NAME,
            $result['session_token'],
            [
                'expires' => time() + SESSION_LIFETIME,
                'path' => '/',
                'secure' => REQUIRE_HTTPS,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
        
        jsonResponse($result);
    }
    
    if ($uri === '/auth/logout' && $method === 'POST') {
        $session = requireAuth();
        Auth::logout($sessionToken);
        
        // Clear session cookie
        setcookie(SESSION_COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/'
        ]);
        
        jsonResponse(['success' => true]);
    }
    
    // Health check endpoint
    // B17/B18 fix: Probabilistically trigger cleanup on health checks (1% of requests)
    if ($uri === '/health' && $method === 'GET') {
        // Probabilistic cleanup (1% chance per request)
        if (mt_rand(1, 100) === 1) {
            try {
                Auth::cleanupSessions();
                Auth::cleanupRateLimits();
            } catch (Exception $e) {
                // Cleanup failures are non-fatal, log but continue
                error_log('Cleanup error: ' . $e->getMessage());
            }
        }
        
        jsonResponse([
            'status' => 'ok',
            'version' => APP_VERSION,
            'database' => db()->isInitialized(),
            'schema_version' => db()->getSchemaVersion()
        ]);
    }
    
    // Manual cleanup endpoint (guardian only) - B17/B18 fix
    if ($uri === '/maintenance/cleanup' && $method === 'POST') {
        $session = requireGuardian();
        
        $sessionsDeleted = Auth::cleanupSessions();
        $rateLimitsDeleted = Auth::cleanupRateLimits();
        
        // Also cleanup expired guest sessions
        $guestSessionsDeleted = db()->execute(
            "DELETE FROM guest_sessions WHERE expires_at < datetime('now') AND revoked_at IS NULL"
        );
        
        Auth::logAudit('MAINTENANCE_CLEANUP', 'system', null, $session['user_id'], [
            'sessions_deleted' => $sessionsDeleted,
            'rate_limits_deleted' => $rateLimitsDeleted,
            'guest_sessions_deleted' => $guestSessionsDeleted
        ]);
        
        jsonResponse([
            'success' => true,
            'cleaned' => [
                'expired_sessions' => $sessionsDeleted,
                'old_rate_limits' => $rateLimitsDeleted,
                'expired_guest_sessions' => $guestSessionsDeleted
            ]
        ]);
    }
    
    // =========================================================================
    // Settings API (Sprint 16)
    // =========================================================================
    
    // Get child_sees_medications setting
    if ($uri === '/settings/child-sees-medications' && $method === 'GET') {
        $session = requireAuth();
        
        $setting = db()->queryOne("SELECT value FROM settings WHERE key = 'child_sees_medications'");
        $value = $setting ? ($setting['value'] === 'true') : false;
        
        jsonResponse(['value' => $value]);
    }
    
    // Update child_sees_medications setting (guardian only)
    if ($uri === '/settings/child-sees-medications' && $method === 'POST') {
        $session = requireGuardian();
        
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['value']) || !is_bool($data['value'])) {
            errorResponse('value must be a boolean', 400);
        }
        
        $newValue = $data['value'] ? 'true' : 'false';
        
        // Upsert the setting
        db()->execute(
            "INSERT INTO settings (key, value) VALUES ('child_sees_medications', ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [$newValue]
        );
        
        Auth::logAudit('SETTING_UPDATED', 'settings', null, $session['user_id'], [
            'key' => 'child_sees_medications',
            'value' => $newValue
        ]);
        
        jsonResponse(['success' => true, 'value' => $data['value']]);
    }
    
    // Session info endpoint (B10 fix: lightweight role detection for any auth)
    if ($uri === '/auth/whoami' && $method === 'GET') {
        $session = requireAuth();
        
        // Get user details
        $user = db()->queryOne("SELECT id, role, locale FROM users WHERE id = ?", [$session['user_id']]);
        
        if (!$user) {
            errorResponse('User not found', 404);
        }
        
        $response = [
            'user_id' => $user['id'],
            'role' => $user['role'],
            'locale' => $user['locale'] ?? DEFAULT_LOCALE
        ];
        
        // If child role, include child_id for convenience
        if ($user['role'] === 'child') {
            $child = db()->queryOne("SELECT id FROM children WHERE user_id = ?", [$user['id']]);
            if ($child) {
                $response['child_id'] = $child['id'];
            }
            
            // Include child_sees_medications setting for child sessions (Sprint 16)
            $setting = db()->queryOne("SELECT value FROM settings WHERE key = 'child_sees_medications'");
            $response['child_sees_medications'] = $setting && $setting['value'] === 'true';
        }
        
        jsonResponse($response);
    }
    
    // Public users list for login (no auth required, rate-limited)
    if ($uri === '/auth/users' && $method === 'GET') {
        // Rate limit to prevent user enumeration
        if (!Auth::checkRateLimit('/auth/users', RATE_LIMIT_AUTH, RATE_LIMIT_AUTH_WINDOW)) {
            errorResponse('Rate limit exceeded', 429);
        }
        
        // Get children with their user_id for login
        $children = db()->query(
            "SELECT c.user_id, c.name, 'child' as role 
             FROM children c 
             JOIN users u ON c.user_id = u.id 
             WHERE c.active = 1 
             ORDER BY c.name"
        );
        
        // Get guardians with their user_id for login
        $guardians = db()->query(
            "SELECT g.user_id, g.name, 'guardian' as role 
             FROM guardians g 
             JOIN users u ON g.user_id = u.id 
             ORDER BY g.name"
        );
        
        jsonResponse([
            'children' => $children,
            'guardians' => $guardians
        ]);
    }
    
    // Children list endpoint
    if ($uri === '/children' && $method === 'GET') {
        requireGuardian();
        
        $children = db()->query(
            "SELECT c.id, c.name, c.active, c.created_at, u.locale 
             FROM children c 
             JOIN users u ON c.user_id = u.id 
             WHERE c.active = 1 
             ORDER BY c.name"
        );
        
        jsonResponse($children);
    }
    
    // Meal endpoints
    if (preg_match('#^/meals/(\d+)/(\d{4}-\d{2}-\d{2})$#', $uri, $matches) && $method === 'GET') {
        requireAuth();
        $childId = $matches[1];
        $date = $matches[2];
        $meals = MealAPI::getMeals($childId, $date);
        jsonResponse($meals);
    }
    
    if ($uri === '/meals' && $method === 'POST') {
        $session = requireAuth();
        $result = MealAPI::createMeal($input, $session['user_id']);
        jsonResponse($result, 201);
    }
    
    if (preg_match('#^/meals/(\d+)$#', $uri, $matches) && $method === 'PATCH') {
        $session = requireAuth();
        $mealId = $matches[1];
        MealAPI::updateMeal($mealId, $input, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/meals/(\d+)/review$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $mealId = $matches[1];
        $result = MealAPI::reviewMeal($mealId, $session['user_id']);
        jsonResponse($result);
    }
    
    if (preg_match('#^/meals/(\d+)/void$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $mealId = $matches[1];
        $result = MealAPI::voidMeal($mealId, $session['user_id']);
        jsonResponse($result);
    }
    
    // Food catalog endpoints
    if ($uri === '/catalog/foods' && $method === 'GET') {
        requireAuth();
        $childId = $_GET['child_id'] ?? null;
        $foods = FoodAPI::getFoods($childId);
        jsonResponse($foods);
    }
    
    if ($uri === '/catalog/foods' && $method === 'POST') {
        $session = requireGuardian();
        $result = FoodAPI::createFood($input, $session['user_id']);
        jsonResponse($result, 201);
    }
    
    if (preg_match('#^/catalog/foods/(\d+)$#', $uri, $matches) && $method === 'PATCH') {
        $session = requireGuardian();
        $foodId = $matches[1];
        FoodAPI::updateFood($foodId, $input, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/catalog/foods/(\d+)/block$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $foodId = $matches[1];
        FoodAPI::blockFood($foodId, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/catalog/foods/(\d+)$#', $uri, $matches) && $method === 'DELETE') {
        $session = requireGuardian();
        $foodId = $matches[1];
        FoodAPI::deleteFood($foodId, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    // Weight endpoints
    if (preg_match('#^/weights/(\d+)/(\d{4}-\d{2}-\d{2})$#', $uri, $matches) && $method === 'GET') {
        requireAuth();
        $childId = $matches[1];
        $date = $matches[2];
        $weight = WeightAPI::getWeight($childId, $date);
        if ($weight) {
            jsonResponse($weight);
        } else {
            errorResponse('No weight logged for this date', 404);
        }
    }
    
    if ($uri === '/weights' && $method === 'POST') {
        $session = requireAuth();
        $result = WeightAPI::logWeight($input, $session['user_id']);
        jsonResponse($result, 201);
    }
    
    // History endpoint (Sprint 8)
    if (preg_match('#^/history/(\d+)/(\d{4}-\d{2}-\d{2})/(\d{4}-\d{2}-\d{2})$#', $uri, $matches) && $method === 'GET') {
        requireAuth();
        $childId = $matches[1];
        $startDate = $matches[2];
        $endDate = $matches[3];
        $history = HistoryAPI::getHistory($childId, $startDate, $endDate);
        jsonResponse($history);
    }
    
    // Medication endpoints
    if (preg_match('#^/medications/(\d+)/(\d{4}-\d{2}-\d{2})$#', $uri, $matches) && $method === 'GET') {
        requireGuardian();
        $childId = $matches[1];
        $date = $matches[2];
        $medications = MedicationAPI::getMedications($childId, $date);
        jsonResponse($medications);
    }
    
    if ($uri === '/medications' && $method === 'POST') {
        $session = requireGuardian();
        $result = MedicationAPI::logMedication($input, $session['user_id']);
        jsonResponse($result, 201);
    }
    
    if (preg_match('#^/medications/available/(\d+)$#', $uri, $matches) && $method === 'GET') {
        requireGuardian();
        $childId = $matches[1];
        $medications = MedicationAPI::getAvailableMedications($childId);
        jsonResponse($medications);
    }
    
    // Medication catalog endpoints (master data management)
    
    // Meal template catalog endpoints (Sprint 9)
    if ($uri === '/catalog/templates' && $method === 'GET') {
        requireAuth();
        $templates = MealTemplateAPI::listTemplates(false);
        jsonResponse($templates);
    }
    
    if ($uri === '/catalog/templates/all' && $method === 'GET') {
        requireGuardian();
        $templates = MealTemplateAPI::listTemplates(true);
        jsonResponse($templates);
    }
    
    if (preg_match('#^/catalog/templates/(\d+)$#', $uri, $matches) && $method === 'GET') {
        requireAuth();
        $templateId = $matches[1];
        $template = MealTemplateAPI::getTemplate($templateId);
        jsonResponse($template);
    }
    
    if ($uri === '/catalog/templates' && $method === 'POST') {
        $session = requireGuardian();
        $result = MealTemplateAPI::createTemplate($input, $session['user_id']);
        jsonResponse($result, 201);
    }
    
    if (preg_match('#^/catalog/templates/(\d+)$#', $uri, $matches) && $method === 'PATCH') {
        $session = requireGuardian();
        $templateId = $matches[1];
        MealTemplateAPI::updateTemplate($templateId, $input, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if ($uri === '/catalog/templates/reorder' && $method === 'POST') {
        $session = requireGuardian();
        MealTemplateAPI::reorderTemplates($input, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/catalog/templates/(\d+)/block$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $templateId = $matches[1];
        MealTemplateAPI::blockTemplate($templateId, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/catalog/templates/(\d+)/unblock$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $templateId = $matches[1];
        MealTemplateAPI::unblockTemplate($templateId, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/catalog/templates/(\d+)$#', $uri, $matches) && $method === 'DELETE') {
        $session = requireGuardian();
        $templateId = $matches[1];
        MealTemplateAPI::deleteTemplate($templateId, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    // Sprint 24: Meal template foods management endpoints
    if (preg_match('#^/catalog/templates/(\d+)/foods$#', $uri, $matches) && $method === 'GET') {
        requireAuth();
        $templateId = $matches[1];
        $foods = MealTemplateAPI::getTemplateFoods($templateId);
        jsonResponse($foods);
    }
    
    if (preg_match('#^/catalog/templates/(\d+)/foods$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $templateId = $matches[1];
        MealTemplateAPI::setTemplateFoods($templateId, $input, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    // Medication catalog endpoints
    if ($uri === '/catalog/medications' && $method === 'GET') {
        requireGuardian();
        $medications = MedicationCatalogAPI::listMedications(false);
        jsonResponse($medications);
    }
    
    if ($uri === '/catalog/medications/all' && $method === 'GET') {
        requireGuardian();
        $medications = MedicationCatalogAPI::listMedications(true);
        jsonResponse($medications);
    }
    
    if (preg_match('#^/catalog/medications/(\d+)$#', $uri, $matches) && $method === 'GET') {
        requireGuardian();
        $medicationId = $matches[1];
        $medication = MedicationCatalogAPI::getMedication($medicationId);
        jsonResponse($medication);
    }
    
    if ($uri === '/catalog/medications' && $method === 'POST') {
        $session = requireGuardian();
        $result = MedicationCatalogAPI::createMedication($input, $session['user_id']);
        jsonResponse($result, 201);
    }
    
    if (preg_match('#^/catalog/medications/(\d+)$#', $uri, $matches) && $method === 'PATCH') {
        $session = requireGuardian();
        $medicationId = $matches[1];
        MedicationCatalogAPI::updateMedication($medicationId, $input, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/catalog/medications/(\d+)/block$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $medicationId = $matches[1];
        MedicationCatalogAPI::blockMedication($medicationId, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/catalog/medications/(\d+)/unblock$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $medicationId = $matches[1];
        MedicationCatalogAPI::unblockMedication($medicationId, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/catalog/medications/(\d+)$#', $uri, $matches) && $method === 'DELETE') {
        $session = requireGuardian();
        $medicationId = $matches[1];
        MedicationCatalogAPI::deleteMedication($medicationId, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    // i18n & Locale endpoints (Sprint 10)
    if ($uri === '/i18n/locales' && $method === 'GET') {
        requireAuth();
        jsonResponse([
            'supported' => SUPPORTED_LOCALES,
            'default' => DEFAULT_LOCALE
        ]);
    }
    
    if (preg_match('#^/i18n/translations/([a-z]{2}-[A-Z]{2})$#', $uri, $matches) && $method === 'GET') {
        requireAuth();
        $locale = $matches[1];
        $translations = I18n::getAllTranslations($locale);
        jsonResponse($translations);
    }
    
    if ($uri === '/i18n/translations' && $method === 'POST') {
        $session = requireGuardian();
        if (empty($input['locale']) || empty($input['key']) || !isset($input['value'])) {
            errorResponse('Missing required fields: locale, key, value', 400);
        }
        I18n::addTranslation($input['locale'], $input['key'], $input['value']);
        Auth::logAudit('TRANSLATION_UPDATED', 'i18n', null, $session['user_id'], [
            'locale' => $input['locale'], 'key' => $input['key']
        ]);
        jsonResponse(['success' => true]);
    }
    
    if ($uri === '/i18n/locale' && $method === 'POST') {
        $session = requireAuth();
        if (empty($input['locale'])) {
            errorResponse('Missing required field: locale', 400);
        }
        I18n::setLocale($input['locale']);
        jsonResponse(['success' => true, 'locale' => $input['locale']]);
    }
    
    // Guest landing route — validates token and redirects to report
    if (preg_match('#^/guest/([a-f0-9]+)$#', $uri, $matches) && $method === 'GET') {
        $token = $matches[1];
        
        // Validate guest token
        $guestSession = GuestAPI::validateToken($token);
        
        if (!$guestSession) {
            // Token invalid or expired — show error page
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Invalid Token</title></head>';
            echo '<body style="font-family:system-ui;max-width:600px;margin:50px auto;padding:20px;text-align:center;">';
            echo '<h1>🔒 Invalid or Expired Token</h1>';
            echo '<p>This guest access link is no longer valid. It may have expired or been revoked.</p>';
            echo '<p>Please request a new link from the guardian.</p>';
            echo '</body></html>';
            exit;
        }
        
        // Redirect to report with token
        $childId = $guestSession['child_id'];
        header('Location: /report/' . $childId . '?token=' . urlencode($token), true, 302);
        exit;
    }
    
    // Guest token endpoints
    if ($uri === '/guest/token' && $method === 'POST') {
        $session = requireGuardian();
        $result = GuestAPI::createToken($input, $session['user_id']);
        jsonResponse($result, 201);
    }
    
    if ($uri === '/guest/tokens' && $method === 'GET') {
        $session = requireGuardian();
        $tokens = GuestAPI::listTokens($session['user_id']);
        jsonResponse($tokens);
    }
    
    if (preg_match('#^/guest/token/([a-f0-9]+)$#', $uri, $matches) && $method === 'DELETE') {
        $session = requireGuardian();
        $token = $matches[1];
        GuestAPI::revokeToken($token, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    // PDF report endpoint
    if (preg_match('#^/report/(\d+)$#', $uri, $matches) && $method === 'GET') {
        $childId = $matches[1];
        $range = $_GET['range'] ?? '30';
        
        // Validate range parameter
        if (!in_array($range, ['30', 'all'], true)) {
            errorResponse('Invalid range parameter. Must be "30" or "all"', 400);
        }
        
        // Check authentication (guardian or valid guest token)
        $userId = null;
        $sessionToken = $_COOKIE[SESSION_COOKIE_NAME] ?? null;
        
        if ($sessionToken) {
            $session = Auth::validateSession($sessionToken);
            if ($session && $session['role'] === 'guardian') {
                $userId = $session['user_id'];
            }
        } else {
            // Check for guest token in URL
            $guestToken = $_GET['token'] ?? null;
            if ($guestToken) {
                $guestSession = GuestAPI::validateToken($guestToken);
                if ($guestSession && $guestSession['child_id'] == $childId) {
                    // Valid guest token
                } else {
                    errorResponse('Invalid or expired guest token', 401);
                }
            } else {
                errorResponse('Authentication required', 401);
            }
        }
        
        // Generate PDF
        $html = PDFReport::generateReport($childId, $range, $userId);
        
        // Send HTML response with print-optimized headers
        // Users can print to PDF from their browser (Ctrl+P / Cmd+P)
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: inline; filename="comecome-report-' . date('Y-m-d') . '.html"');
        echo $html;
        exit;
    }
    
    // Backup endpoints
    if ($uri === '/backup/create' && $method === 'POST') {
        $session = requireGuardian();
        $result = BackupAPI::createBackup($session['user_id']);
        jsonResponse($result, 201);
    }
    
    if ($uri === '/backup/list' && $method === 'GET') {
        requireGuardian();
        $backups = BackupAPI::listBackups();
        jsonResponse($backups);
    }
    
    if (preg_match('#^/backup/download/(.+\.db)$#', $uri, $matches) && $method === 'GET') {
        requireGuardian();
        $filename = $matches[1];
        BackupAPI::downloadBackup($filename);
    }
    
    if ($uri === '/backup/restore' && $method === 'POST') {
        $session = requireGuardian();
        $filename = $input['filename'] ?? null;
        
        if (!$filename) {
            errorResponse('Missing filename', 400);
        }
        
        BackupAPI::restoreBackup($filename, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if ($uri === '/backup/stats' && $method === 'GET') {
        requireGuardian();
        $stats = BackupAPI::getDatabaseStats();
        jsonResponse($stats);
    }
    
    if ($uri === '/backup/vacuum' && $method === 'POST') {
        $session = requireGuardian();
        BackupAPI::vacuumDatabase($session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    // Food catalog - unblock endpoint
    if (preg_match('#^/catalog/foods/(\d+)/unblock$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $foodId = $matches[1];
        FoodAPI::unblockFood($foodId, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    // Food catalog - list all (including blocked) for guardian management
    if ($uri === '/catalog/foods/all' && $method === 'GET') {
        requireGuardian();
        $foods = FoodAPI::getFoods(null, true);
        jsonResponse($foods);
    }
    
    // User management endpoints (guardian only)
    if ($uri === '/users' && $method === 'GET') {
        requireGuardian();
        $users = UserAPI::listUsers();
        jsonResponse($users);
    }
    
    if (preg_match('#^/users/(\d+)$#', $uri, $matches) && $method === 'GET') {
        requireGuardian();
        $userId = $matches[1];
        $user = UserAPI::getUser($userId);
        jsonResponse($user);
    }
    
    if ($uri === '/users/child' && $method === 'POST') {
        $session = requireGuardian();
        $result = UserAPI::createChild($input, $session['user_id']);
        jsonResponse($result, 201);
    }
    
    if ($uri === '/users/guardian' && $method === 'POST') {
        $session = requireGuardian();
        $result = UserAPI::createGuardian($input, $session['user_id']);
        jsonResponse($result, 201);
    }
    
    if (preg_match('#^/users/(\d+)$#', $uri, $matches) && $method === 'PATCH') {
        $session = requireGuardian();
        $userId = $matches[1];
        UserAPI::editUser($userId, $input, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/users/(\d+)/pin$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $userId = $matches[1];
        UserAPI::changePinEndpoint($userId, $input, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/users/(\d+)/pin/reset$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $userId = $matches[1];
        UserAPI::resetPinEndpoint($userId, $input, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/users/(\d+)/block$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $userId = $matches[1];
        UserAPI::blockUser($userId, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/users/(\d+)/unblock$#', $uri, $matches) && $method === 'POST') {
        $session = requireGuardian();
        $userId = $matches[1];
        UserAPI::unblockUser($userId, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    if (preg_match('#^/users/(\d+)$#', $uri, $matches) && $method === 'DELETE') {
        $session = requireGuardian();
        $userId = $matches[1];
        UserAPI::deleteUser($userId, $session['user_id']);
        jsonResponse(['success' => true]);
    }
    
    // 404 Not Found
    errorResponse('Endpoint not found', 404);
    
} catch (Exception $e) {
    $statusCode = $e->getCode() ?: 500;
    
    // Map exception codes to HTTP status codes
    if (!in_array($statusCode, [400, 401, 403, 404, 409, 429, 500, 501])) {
        $statusCode = 500;
    }
    
    errorResponse($e->getMessage(), $statusCode);
}
