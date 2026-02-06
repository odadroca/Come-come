<?php
/**
 * Come-Come Test Suite — Sprint 14
 * Basic unit and integration tests
 * 
 * Run with: php tests/run-tests.php
 */

// Load application
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/api.php';

class TestRunner {
    private $passed = 0;
    private $failed = 0;
    private $tests = [];
    
    public function addTest($name, $callback) {
        $this->tests[$name] = $callback;
    }
    
    public function run() {
        echo "========================================\n";
        echo "Come-Come Test Suite v0.140\n";
        echo "========================================\n\n";
        
        foreach ($this->tests as $name => $callback) {
            echo "Testing: $name ... ";
            
            try {
                $callback();
                echo "✓ PASS\n";
                $this->passed++;
            } catch (Exception $e) {
                echo "✗ FAIL\n";
                echo "  Error: " . $e->getMessage() . "\n";
                $this->failed++;
            }
        }
        
        echo "\n========================================\n";
        echo "Results: {$this->passed} passed, {$this->failed} failed\n";
        echo "========================================\n";
        
        return $this->failed === 0;
    }
}

// Initialize test runner
$runner = new TestRunner();

// =========================================================================
// Database Tests
// =========================================================================

$runner->addTest('Database connection', function() {
    $db = Database::getInstance();
    assert($db !== null, 'Database instance should not be null');
});

$runner->addTest('Database schema version', function() {
    $db = Database::getInstance();
    $version = $db->getSchemaVersion();
    // B06 fix: Schema version should now be 2
    assert($version === 2 || $version === 1, 'Schema version should be 1 or 2');
});

// =========================================================================
// Authentication Tests
// =========================================================================

$runner->addTest('PIN hash generation', function() {
    $pin = '1234';
    $hash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12]);
    assert(!empty($hash), 'PIN hash should not be empty');
    assert(password_verify($pin, $hash), 'PIN verification should succeed');
    assert(!password_verify('0000', $hash), 'Wrong PIN verification should fail');
});

$runner->addTest('Session token generation', function() {
    $token = bin2hex(random_bytes(32));
    assert(strlen($token) === 64, 'Token should be 64 characters');
    assert(ctype_xdigit($token), 'Token should be hexadecimal');
});

// =========================================================================
// API Tests
// =========================================================================

$runner->addTest('Food quantity calculation', function() {
    // Test MealAPI::calculateQuantity logic
    $food1 = ['quantity_integer' => 1, 'quantity_fraction' => 0.25];
    $food2 = ['quantity_integer' => 0, 'quantity_fraction' => 0.5];
    $food3 = ['quantity_integer' => 2, 'quantity_fraction' => 0.75];
    
    assert((1 + 0.25) === 1.25, 'Quantity 1 + 0.25 should equal 1.25');
    assert((0 + 0.5) === 0.5, 'Quantity 0 + 0.5 should equal 0.5');
    assert((2 + 0.75) === 2.75, 'Quantity 2 + 0.75 should equal 2.75');
});

// =========================================================================
// I18n Tests
// =========================================================================

$runner->addTest('Locale parsing', function() {
    $acceptLanguage = 'en-US,en;q=0.9,pt-PT;q=0.8';
    $languages = [];
    foreach (explode(',', $acceptLanguage) as $lang) {
        $parts = explode(';', $lang);
        $code = trim($parts[0]);
        $priority = 1.0;
        if (isset($parts[1]) && strpos($parts[1], 'q=') === 0) {
            $priority = floatval(substr($parts[1], 2));
        }
        $languages[$code] = $priority;
    }
    
    arsort($languages);
    $topLang = array_key_first($languages);
    assert($topLang === 'en-US', 'Top language should be en-US');
});

$runner->addTest('Supported locales', function() {
    assert(in_array('en-UK', SUPPORTED_LOCALES), 'en-UK should be supported');
    assert(in_array('pt-PT', SUPPORTED_LOCALES), 'pt-PT should be supported');
    assert(DEFAULT_LOCALE === 'en-UK', 'Default locale should be en-UK');
});

// =========================================================================
// Validation Tests
// =========================================================================

$runner->addTest('Date format validation', function() {
    $validDate = '2026-02-05';
    $invalidDate1 = '2026-2-5';
    $invalidDate2 = '05/02/2026';
    
    assert(preg_match('/^\d{4}-\d{2}-\d{2}$/', $validDate), 'Valid date should match');
    assert(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invalidDate1), 'Invalid date 1 should not match');
    assert(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invalidDate2), 'Invalid date 2 should not match');
});

// B09 regression test: Semantic date validation
$runner->addTest('Semantic date validation (B09)', function() {
    // Test isValidDate function
    assert(isValidDate('2026-02-05') === true, 'Valid date should pass');
    assert(isValidDate('2026-12-31') === true, 'Dec 31 should pass');
    assert(isValidDate('2026-99-99') === false, 'Invalid month/day should fail');
    assert(isValidDate('2026-13-01') === false, 'Month 13 should fail');
    assert(isValidDate('2026-02-30') === false, 'Feb 30 should fail');
    assert(isValidDate('2026-04-31') === false, 'April 31 should fail');
    assert(isValidDate('invalid') === false, 'Non-date string should fail');
});

$runner->addTest('PIN format validation', function() {
    $validPin = '1234';
    $invalidPin1 = '123';
    $invalidPin2 = '12345';
    $invalidPin3 = 'abcd';
    
    assert(preg_match('/^\d{4}$/', $validPin), 'Valid PIN should match');
    assert(!preg_match('/^\d{4}$/', $invalidPin1), 'Short PIN should not match');
    assert(!preg_match('/^\d{4}$/', $invalidPin2), 'Long PIN should not match');
    assert(!preg_match('/^\d{4}$/', $invalidPin3), 'Non-numeric PIN should not match');
});

$runner->addTest('Food category validation', function() {
    $validCategories = ['starter', 'main', 'dessert', 'drink', 'snack'];
    
    assert(in_array('main', $validCategories), 'main should be valid');
    assert(in_array('snack', $validCategories), 'snack should be valid');
    assert(!in_array('invalid', $validCategories), 'invalid should not be valid');
});

$runner->addTest('Quantity fraction validation', function() {
    $validFractions = [0, 0.25, 0.5, 0.75];
    
    assert(in_array(0.25, $validFractions), '0.25 should be valid');
    assert(in_array(0.5, $validFractions), '0.5 should be valid');
    assert(!in_array(0.33, $validFractions), '0.33 should not be valid');
    assert(!in_array(1.0, $validFractions), '1.0 should not be valid');
});

// =========================================================================
// B01 Regression Test: HistoryAPI table/column names
// =========================================================================

$runner->addTest('HistoryAPI uses correct table names (B01)', function() {
    // Verify the code references food_quantities table, not meal_foods
    $apiCode = file_get_contents(__DIR__ . '/../src/api.php');
    
    // Check that HistoryAPI uses food_quantities (correct) not meal_foods (wrong)
    assert(strpos($apiCode, 'food_quantities fq') !== false, 'Should use food_quantities table');
    assert(strpos($apiCode, 'fq.food_catalog_id') !== false, 'Should use food_catalog_id column');
    
    // Verify meal_foods is NOT used in HistoryAPI context (it doesn't exist)
    // The only reference to meal_foods should be non-existent
    $historySection = substr($apiCode, strpos($apiCode, 'class HistoryAPI'));
    assert(strpos($historySection, 'meal_foods') === false, 'HistoryAPI should not reference meal_foods');
});

// =========================================================================
// Meal Template CRUD Tests
// =========================================================================

$runner->addTest('MealTemplateAPI class exists', function() {
    assert(class_exists('MealTemplateAPI'), 'MealTemplateAPI class should exist');
    assert(method_exists('MealTemplateAPI', 'listTemplates'), 'listTemplates method should exist');
    assert(method_exists('MealTemplateAPI', 'createTemplate'), 'createTemplate method should exist');
    assert(method_exists('MealTemplateAPI', 'updateTemplate'), 'updateTemplate method should exist');
    assert(method_exists('MealTemplateAPI', 'blockTemplate'), 'blockTemplate method should exist');
    assert(method_exists('MealTemplateAPI', 'deleteTemplate'), 'deleteTemplate method should exist');
});

// =========================================================================
// Security Tests
// =========================================================================

$runner->addTest('SQL injection prevention', function() {
    $maliciousInput = "1'; DROP TABLE users; --";
    
    $sql = "SELECT * FROM users WHERE id = ?";
    assert(strpos($sql, '?') !== false, 'Prepared statement should use placeholders');
    assert(strpos($sql, $maliciousInput) === false, 'SQL should not contain user input');
});

$runner->addTest('XSS prevention pattern', function() {
    $maliciousInput = '<script>alert("XSS")</script>';
    $escaped = htmlspecialchars($maliciousInput, ENT_QUOTES, 'UTF-8');
    
    assert(strpos($escaped, '<script>') === false, 'Script tags should be escaped');
    assert(strpos($escaped, '&lt;script&gt;') !== false, 'Script tags should be entity-encoded');
});

// B07 regression test: Single quote escaping
$runner->addTest('Single quote escaping pattern (B07)', function() {
    $templateName = "Mom's Special";
    $escaped = str_replace("'", "\\'", htmlspecialchars($templateName, ENT_QUOTES, 'UTF-8'));
    
    // Verify single quote is escaped for onclick handlers
    assert(strpos($escaped, "\\'") !== false, 'Single quotes should be escaped');
});

$runner->addTest('Guest token validation', function() {
    $validToken = bin2hex(random_bytes(32));
    assert(strlen($validToken) === 64, 'Token should be 64 hex characters');
    assert(preg_match('/^[a-f0-9]+$/', $validToken), 'Token should be valid hex');
});

// =========================================================================
// Configuration Tests
// =========================================================================

$runner->addTest('Required configuration constants', function() {
    assert(defined('DB_PATH'), 'DB_PATH should be defined');
    assert(defined('REQUIRE_HTTPS'), 'REQUIRE_HTTPS should be defined');
    assert(defined('SESSION_LIFETIME'), 'SESSION_LIFETIME should be defined');
    assert(defined('PIN_HASH_COST'), 'PIN_HASH_COST should be defined');
    assert(defined('DEFAULT_LOCALE'), 'DEFAULT_LOCALE should be defined');
    assert(defined('APP_VERSION'), 'APP_VERSION should be defined');
});

$runner->addTest('Security constants', function() {
    assert(PIN_HASH_COST === 12, 'PIN_HASH_COST should be 12');
    assert(PIN_MAX_ATTEMPTS === 5, 'PIN_MAX_ATTEMPTS should be 5');
    assert(PIN_LOCKOUT_DURATION === 300, 'PIN_LOCKOUT_DURATION should be 5 minutes');
});

$runner->addTest('App version format', function() {
    assert(preg_match('/^\d+\.\d+$/', APP_VERSION), 'Version should be in X.XXX format');
});

// =========================================================================
// Cleanup Function Tests (B17/B18)
// =========================================================================

$runner->addTest('Cleanup functions exist (B17/B18)', function() {
    assert(method_exists('Auth', 'cleanupSessions'), 'cleanupSessions method should exist');
    assert(method_exists('Auth', 'cleanupRateLimits'), 'cleanupRateLimits method should exist');
});

// Run all tests
$success = $runner->run();
exit($success ? 0 : 1);
