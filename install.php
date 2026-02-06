<?php
/**
 * Come-Come Installation Script
 * First-time setup: creates database, seeds data, creates first guardian
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Prevent reinstallation if already installed
if (file_exists(DB_PATH) && db()->isInitialized()) {
    die('Application already installed. Delete database file to reinstall.');
}

$error = null;
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate input
        $guardianName = trim($_POST['guardian_name'] ?? '');
        $pin = $_POST['pin'] ?? '';
        $pinConfirm = $_POST['pin_confirm'] ?? '';
        
        if (empty($guardianName)) {
            throw new Exception('Guardian name is required');
        }
        
        if (!preg_match('/^\d{4}$/', $pin)) {
            throw new Exception('PIN must be exactly 4 digits');
        }
        
        if ($pin !== $pinConfirm) {
            throw new Exception('PINs do not match');
        }
        
        // Initialize database
        db()->initialize();
        
        // Create first guardian user
        $pinHash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => PIN_HASH_COST]);
        
        $userId = db()->insert(
            "INSERT INTO users (role, pin_hash, locale) VALUES ('guardian', ?, ?)",
            [$pinHash, DEFAULT_LOCALE]
        );
        
        // Create guardian profile
        db()->insert(
            "INSERT INTO guardians (user_id, name) VALUES (?, ?)",
            [$userId, $guardianName]
        );
        
        // Log installation
        Auth::logAudit('INSTALL', 'system', null, $userId, [
            'version' => APP_VERSION,
            'guardian_name' => $guardianName
        ]);
        
        $success = true;
        
        // Delete this installation script for security
        @unlink(__FILE__);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Come-Come Installation</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        main { max-width: 600px; margin: 2rem auto; padding: 2rem; }
        .success { background: var(--pico-ins-color); padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; }
        .error { background: var(--pico-del-color); padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <main>
        <h1>🍽️ Come-Come Installation</h1>
        <p>Welcome! Let's set up your Come-Come meal tracking system.</p>
        
        <?php if ($success): ?>
            <div class="success">
                <h3>✅ Installation Complete!</h3>
                <p>Your Come-Come system is ready to use.</p>
                <p><strong>Guardian:</strong> <?php echo htmlspecialchars($guardianName); ?></p>
                <p>This installation script has been deleted for security.</p>
                <a href="/" role="button">Go to Login</a>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="error">
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <h2>First Guardian Account</h2>
                
                <label for="guardian_name">
                    Guardian Name
                    <input type="text" id="guardian_name" name="guardian_name" 
                           placeholder="Your name" required autofocus 
                           value="<?php echo htmlspecialchars($_POST['guardian_name'] ?? ''); ?>">
                </label>
                
                <label for="pin">
                    PIN (4 digits)
                    <input type="password" id="pin" name="pin" 
                           placeholder="1234" required pattern="\d{4}" 
                           inputmode="numeric" maxlength="4">
                    <small>This PIN will be used to log in. Remember it!</small>
                </label>
                
                <label for="pin_confirm">
                    Confirm PIN
                    <input type="password" id="pin_confirm" name="pin_confirm" 
                           placeholder="1234" required pattern="\d{4}" 
                           inputmode="numeric" maxlength="4">
                </label>
                
                <button type="submit">Install Come-Come</button>
            </form>
            
            <details>
                <summary>System Information</summary>
                <ul>
                    <li><strong>Version:</strong> <?php echo APP_VERSION; ?></li>
                    <li><strong>PHP:</strong> <?php echo PHP_VERSION; ?></li>
                    <li><strong>SQLite:</strong> <?php echo SQLite3::version()['versionString']; ?></li>
                    <li><strong>Database Path:</strong> <?php echo DB_PATH; ?></li>
                    <li><strong>HTTPS Required:</strong> <?php echo REQUIRE_HTTPS ? 'Yes' : 'No'; ?></li>
                </ul>
            </details>
            
            <details>
                <summary>Pre-Installation Checks</summary>
                <ul>
                    <?php
                    $checks = [
                        'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
                        'PDO SQLite' => extension_loaded('pdo_sqlite'),
                        'Data directory writable' => is_writable(DATA_PATH),
                        'SQLite 3.35+' => version_compare(SQLite3::version()['versionString'], '3.35.0', '>=')
                    ];
                    
                    foreach ($checks as $check => $passed) {
                        $icon = $passed ? '✅' : '❌';
                        echo "<li>{$icon} {$check}</li>";
                    }
                    ?>
                </ul>
            </details>
        <?php endif; ?>
    </main>
</body>
</html>
