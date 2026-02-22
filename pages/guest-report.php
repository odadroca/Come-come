<?php
/**
 * Guest Report (for clinicians with token access)
 */

$token = $_GET['token'] ?? '';
$userId = validateGuestToken($token);

if (!$userId) {
    http_response_code(403);
    die('<h1>Link Expired or Invalid</h1><p>' . t('error_expired') . '</p>');
}

// Get last 30 days of data
$startDate = date('Y-m-d', strtotime('-30 days'));
$endDate = date('Y-m-d');
$reportData = getReportData($userId, $startDate, $endDate);

// Load the HTML export template
include 'guardian/export-html.php';
