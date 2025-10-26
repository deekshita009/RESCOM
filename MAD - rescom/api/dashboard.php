<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = getCurrentUser();

if (!$user) {
    sendResponse(['error' => 'Unauthorized'], 401);
}

if ($user['type'] !== 'volunteer') {
    sendResponse(['error' => 'Only volunteers can access dashboard stats'], 403);
}

switch ($method) {
    case 'GET':
        getDashboardStats();
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

function getDashboardStats() {
    $conn = getDBConnection();

    // Get report statistics
    $stmt = $conn->prepare("
        SELECT
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_reports,
            COUNT(CASE WHEN status = 'accepted' THEN 1 END) as accepted_reports,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_reports
        FROM reports
    ");
    $stmt->execute();
    $reportStats = $stmt->fetch();

    // Calculate response rate (completed / total * 100)
    $totalReports = $reportStats['pending_reports'] + $reportStats['accepted_reports'] + $reportStats['completed_reports'];
    $responseRate = $totalReports > 0 ? round(($reportStats['completed_reports'] / $totalReports) * 100) : 0;

    sendResponse([
        'success' => true,
        'stats' => [
            'pending_reports' => (int)$reportStats['pending_reports'],
            'accepted_reports' => (int)$reportStats['accepted_reports'],
            'completed_reports' => (int)$reportStats['completed_reports'],
            'response_rate' => $responseRate
        ]
    ]);
}
?>
