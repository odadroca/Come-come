<?php
/**
 * Shared Child Footer Navigation
 * Reads feature toggle settings and renders only enabled buttons.
 *
 * Expects $currentPage to be set before including this file.
 */

$showFoodJournal = getSetting('show_food_journal', '1') == '1';
$showCheckin = getSetting('show_checkin', '1') == '1';
$showWeightTracking = getSetting('show_weight_tracking', '1') == '1';

// History is visible when food journal is enabled
$showHistory = $showFoodJournal;
?>

<footer class="child-footer">
    <?php if ($showFoodJournal): ?>
    <a href="?page=log-food" class="footer-btn <?php echo ($currentPage ?? '') === 'log-food' ? 'active' : ''; ?>">
        <span style="font-size:1.5rem;">🍽️</span>
        <span><?php echo t('log_food'); ?></span>
    </a>
    <?php endif; ?>

    <?php if ($showCheckin): ?>
    <a href="?page=check-in" class="footer-btn <?php echo ($currentPage ?? '') === 'check-in' ? 'active' : ''; ?>">
        <span style="font-size:1.5rem;">✅</span>
        <span><?php echo t('check_in'); ?></span>
    </a>
    <?php endif; ?>

    <?php if ($showWeightTracking): ?>
    <a href="?page=weight" class="footer-btn <?php echo ($currentPage ?? '') === 'weight' ? 'active' : ''; ?>">
        <span style="font-size:1.5rem;">⚖️</span>
        <span><?php echo t('my_weight'); ?></span>
    </a>
    <?php endif; ?>

    <?php if ($showHistory): ?>
    <a href="?page=history" class="footer-btn <?php echo ($currentPage ?? '') === 'history' ? 'active' : ''; ?>">
        <span style="font-size:1.5rem;">📖</span>
        <span><?php echo t('my_history'); ?></span>
    </a>
    <?php endif; ?>
</footer>
