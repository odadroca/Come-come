<?php
/**
 * Guardian - Manage Meals (Simplified)
 */

requireGuardian();
$user = getCurrentUser();

$db = getDB();
$stmt = $db->query("SELECT * FROM meals ORDER BY sort_order");
$meals = $stmt->fetchAll();

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('manage_meals'); ?></h1>

        <section class="management-section">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('meal_name'); ?></th>
                            <th><?php echo t('time_range'); ?></th>
                            <th><?php echo t('active'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($meals as $meal): ?>
                        <tr>
                            <td><?php echo t($meal['name_key']); ?></td>
                            <td><?php echo $meal['time_start'] . ' - ' . $meal['time_end']; ?></td>
                            <td><?php echo $meal['active'] ? '✅' : '❌'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="opacity:0.7;margin-top:1rem;">As refeições estão pré-configuradas. Use a página de traduções para alterar os nomes.</p>
        </section>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('manage_meals'), $content);
?>
