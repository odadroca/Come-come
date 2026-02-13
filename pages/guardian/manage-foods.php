<?php
/**
 * Guardian - Manage Foods (Simplified)
 */

requireGuardian();
$user = getCurrentUser();

$db = getDB();
$stmt = $db->query("SELECT f.*, fc.name_key as category_name FROM foods f JOIN food_categories fc ON f.category_id = fc.id ORDER BY fc.sort_order, f.sort_order");
$foods = $stmt->fetchAll();

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('manage_foods'); ?></h1>

        <section class="management-section">
            <p style="margin-bottom:1rem;">Total de alimentos: <?php echo count($foods); ?></p>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Emoji</th>
                            <th><?php echo t('food_name'); ?></th>
                            <th><?php echo t('category'); ?></th>
                            <th><?php echo t('active'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($foods as $food): ?>
                        <tr>
                            <td style="font-size:1.5rem;"><?php echo $food['emoji']; ?></td>
                            <td><?php echo t($food['name_key']); ?></td>
                            <td><?php echo t($food['category_name']); ?></td>
                            <td><?php echo $food['active'] ? '✅' : '❌'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="opacity:0.7;margin-top:1rem;">Use a página de traduções para alterar os nomes dos alimentos.</p>
        </section>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('manage_foods'), $content);
?>
