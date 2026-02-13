<?php
/**
 * Guardian - Manage Medications (Simplified)
 */

requireGuardian();
$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO medications (name, dose) VALUES (?, ?)");
        $stmt->execute([$_POST['name'], $_POST['dose']]);
        header('Location: ?page=manage-medications');
        exit;
    }
}

$db = getDB();
$stmt = $db->query("SELECT * FROM medications WHERE active = 1");
$medications = $stmt->fetchAll();

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('manage_medications'); ?></h1>

        <section class="management-section">
            <h2><?php echo t('add_new'); ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <label>
                        <?php echo t('medication_name'); ?>
                        <input type="text" name="name" required placeholder="Ritalina">
                    </label>
                    <label>
                        <?php echo t('dose'); ?>
                        <input type="text" name="dose" required placeholder="20mg">
                    </label>
                </div>
                <button type="submit" class="btn-primary"><?php echo t('save'); ?></button>
            </form>
        </section>

        <section class="management-section">
            <h2><?php echo t('manage_medications'); ?></h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('medication_name'); ?></th>
                            <th><?php echo t('dose'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medications as $med): ?>
                        <tr>
                            <td><?php echo sanitize($med['name']); ?></td>
                            <td><?php echo sanitize($med['dose']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('manage_medications'), $content);
?>
