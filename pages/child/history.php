<?php
/**
 * Child - History Page
 */

$user = getCurrentUser();
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$foodLog = getFoodLogByDate($user['id'], $selectedDate);
$checkIn = getCheckIn($user['id'], $selectedDate);

ob_start();
?>

<div class="child-interface">
    <nav class="child-nav">
        <a href="index.php" class="btn-back">← <?php echo t('back'); ?></a>
        <h1><?php echo t('my_history'); ?></h1>
        <a href="index.php?page=logout" class="btn-logout">🚪</a>
    </nav>

    <main class="container">
        <!-- Date Selector -->
        <section class="date-selector">
            <input type="date" id="dateInput" value="<?php echo $selectedDate; ?>" max="<?php echo date('Y-m-d'); ?>">
        </section>

        <!-- Check-in Summary -->
        <?php if ($checkIn): ?>
        <section class="checkin-summary">
            <h3><?php echo t('daily_checkin'); ?></h3>
            <div class="summary-grid">
                <div class="summary-item">
                    <span class="summary-label"><?php echo t('appetite'); ?></span>
                    <span class="summary-value">
                        <?php
                        $appetiteEmojis = ['😫', '😕', '😐', '🙂', '😋'];
                        echo $appetiteEmojis[$checkIn['appetite_level'] - 1];
                        ?>
                    </span>
                </div>
                <div class="summary-item">
                    <span class="summary-label"><?php echo t('mood'); ?></span>
                    <span class="summary-value">
                        <?php
                        $moodEmojis = ['😢', '🙁', '😐', '😊', '🤩'];
                        echo $moodEmojis[$checkIn['mood_level'] - 1];
                        ?>
                    </span>
                </div>
                <?php if (getSetting('show_medication_to_children', '1') == '1'): ?>
                <div class="summary-item">
                    <span class="summary-label"><?php echo t('medication'); ?></span>
                    <span class="summary-value">
                        <?php echo $checkIn['medication_taken'] ? '✅' : '❌'; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($checkIn['notes']): ?>
            <div class="checkin-notes">
                <strong><?php echo t('notes'); ?>:</strong>
                <p><?php echo nl2br(sanitize($checkIn['notes'])); ?></p>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- Food Log -->
        <section class="food-log-section">
            <h3><?php echo t('log_food'); ?></h3>

            <?php if (count($foodLog) > 0): ?>
            <div class="food-log-list">
                <?php
                $groupedByMeal = [];
                foreach ($foodLog as $entry) {
                    $mealKey = $entry['meal_name_key'];
                    if (!isset($groupedByMeal[$mealKey])) {
                        $groupedByMeal[$mealKey] = [];
                    }
                    $groupedByMeal[$mealKey][] = $entry;
                }

                foreach ($groupedByMeal as $mealKey => $entries):
                ?>
                <div class="meal-group">
                    <h4><?php echo t($mealKey); ?></h4>
                    <div class="food-entries">
                        <?php foreach ($entries as $entry): ?>
                        <div class="food-entry">
                            <span class="food-emoji"><?php echo $entry['emoji']; ?></span>
                            <span class="food-details">
                                <strong><?php echo t($entry['food_name_key']); ?></strong>
                                <small><?php echo t('portion_' . $entry['portion']); ?> • <?php echo date('H:i', strtotime($entry['log_time'])); ?></small>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="text-align:center;opacity:0.6;padding:2rem;">
                <?php echo t('no_logs_today'); ?>
            </p>
            <?php endif; ?>
        </section>
    </main>

    <footer class="child-footer">
        <a href="?page=log-food" class="footer-btn">
            <span style="font-size:1.5rem;">🍽️</span>
            <span><?php echo t('log_food'); ?></span>
        </a>
        <a href="?page=check-in" class="footer-btn">
            <span style="font-size:1.5rem;">✅</span>
            <span><?php echo t('check_in'); ?></span>
        </a>
        <a href="?page=weight" class="footer-btn">
            <span style="font-size:1.5rem;">⚖️</span>
            <span><?php echo t('my_weight'); ?></span>
        </a>
        <a href="?page=history" class="footer-btn active">
            <span style="font-size:1.5rem;">📖</span>
            <span><?php echo t('my_history'); ?></span>
        </a>
    </footer>
</div>

<script>
document.getElementById('dateInput').addEventListener('change', function() {
    window.location = '?page=history&date=' + this.value;
});
</script>

<?php
$content = ob_get_clean();
renderLayout(t('my_history'), $content);
?>
