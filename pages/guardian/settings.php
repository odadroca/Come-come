<?php
/**
 * Guardian - Settings
 */

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setSetting('show_food_journal', $_POST['show_food_journal'] ?? '0');
    setSetting('show_checkin', $_POST['show_checkin'] ?? '0');
    setSetting('show_weight_tracking', $_POST['show_weight_tracking'] ?? '0');
    setSetting('show_sleep_tracking', $_POST['show_sleep_tracking'] ?? '0');
    setSetting('show_medication_to_children', $_POST['show_medication'] ?? '0');
    setSetting('default_language', $_POST['default_language'] ?? 'pt');
    $success = true;
}

$showFoodJournal = getSetting('show_food_journal', '1');
$showCheckin = getSetting('show_checkin', '1');
$showWeightTracking = getSetting('show_weight_tracking', '1');
$showSleepTracking = getSetting('show_sleep_tracking', '1');
$showMedication = getSetting('show_medication_to_children', '1');
$defaultLanguage = getSetting('default_language', 'pt');

ob_start();
?>

<div class="guardian-interface">
    <?php include 'nav.php'; ?>

    <main class="container">
        <h1><?php echo t('system_settings'); ?></h1>

        <?php if (isset($success)): ?>
        <div class="alert alert-success">
            ✅ <?php echo t('changes_saved'); ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <section class="management-section">
                <h3><?php echo t('child_features'); ?></h3>
                <small style="opacity:0.7;display:block;margin-bottom:1rem;">
                    <?php echo t('child_features_hint'); ?>
                </small>

                <label>
                    <input type="checkbox" name="show_food_journal" value="1" <?php echo $showFoodJournal == '1' ? 'checked' : ''; ?>>
                    🍽️ <?php echo t('show_food_journal'); ?>
                </label>
                <small style="opacity:0.7;display:block;margin-top:0.25rem;margin-bottom:0.75rem;">
                    <?php echo t('show_food_journal_hint'); ?>
                </small>

                <label>
                    <input type="checkbox" name="show_checkin" value="1" <?php echo $showCheckin == '1' ? 'checked' : ''; ?>>
                    ✅ <?php echo t('show_checkin'); ?>
                </label>
                <small style="opacity:0.7;display:block;margin-top:0.25rem;margin-bottom:0.75rem;">
                    <?php echo t('show_checkin_hint'); ?>
                </small>

                <label>
                    <input type="checkbox" name="show_weight_tracking" value="1" <?php echo $showWeightTracking == '1' ? 'checked' : ''; ?>>
                    ⚖️ <?php echo t('show_weight_tracking'); ?>
                </label>
                <small style="opacity:0.7;display:block;margin-top:0.25rem;margin-bottom:0.75rem;">
                    <?php echo t('show_weight_tracking_hint'); ?>
                </small>

                <label>
                    <input type="checkbox" name="show_sleep_tracking" value="1" <?php echo $showSleepTracking == '1' ? 'checked' : ''; ?>>
                    😴 <?php echo t('show_sleep_tracking'); ?>
                </label>
                <small style="opacity:0.7;display:block;margin-top:0.25rem;margin-bottom:0.75rem;">
                    <?php echo t('show_sleep_tracking_hint'); ?>
                </small>

                <label>
                    <input type="checkbox" name="show_medication" value="1" <?php echo $showMedication == '1' ? 'checked' : ''; ?>>
                    💊 <?php echo t('show_medication_children'); ?>
                </label>
            </section>

            <section class="management-section">
                <label>
                    <?php echo t('default_language'); ?>
                    <select name="default_language">
                        <option value="pt" <?php echo $defaultLanguage === 'pt' ? 'selected' : ''; ?>><?php echo t('language_pt'); ?></option>
                        <option value="en" <?php echo $defaultLanguage === 'en' ? 'selected' : ''; ?>><?php echo t('language_en'); ?></option>
                    </select>
                </label>
            </section>

            <button type="submit" class="btn-primary"><?php echo t('save_changes'); ?></button>
        </form>
    </main>
</div>

<?php
$content = ob_get_clean();
renderLayout(t('settings'), $content);
?>
