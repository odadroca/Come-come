<?php
/**
 * Guardian - Database Management
 */

$user = getCurrentUser();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'backup') {
        $backupPath = backupDatabase();
        if ($backupPath) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($backupPath) . '"');
            readfile($backupPath);
            exit;
        }
    } elseif ($action === 'delete' && $_POST['confirm'] === 'ELIMINAR') {
        resetDatabase();
        $message = t('all_data_deleted');
    }
}

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('database_management'); ?></h1>

        <?php if ($message): ?>
        <div style="background:#ffebee;padding:1rem;border-radius:0.5rem;margin-bottom:1rem;">
            ⚠️ <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <section class="management-section">
            <h2><?php echo t('backup_database'); ?></h2>
            <p>Criar uma cópia de segurança de todos os dados.</p>
            <form method="POST">
                <input type="hidden" name="action" value="backup">
                <button type="submit" class="btn-primary">💾 <?php echo t('backup_database'); ?></button>
            </form>
        </section>

        <section class="management-section">
            <h2><?php echo t('delete_all_data'); ?></h2>
            <p style="color:#f44336;font-weight:600;"><?php echo t('delete_warning'); ?></p>
            <form method="POST" onsubmit="return confirm('Tem ABSOLUTA certeza? Esta ação NÃO PODE ser desfeita!')">
                <input type="hidden" name="action" value="delete">
                <label>
                    <?php echo t('type_delete_confirm'); ?>
                    <input type="text" name="confirm" placeholder="ELIMINAR" required>
                </label>
                <button type="submit" class="btn-secondary" style="background:#f44336;">
                    🗑️ <?php echo t('delete_all_data'); ?>
                </button>
            </form>
        </section>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('database_management'), $content);
?>
