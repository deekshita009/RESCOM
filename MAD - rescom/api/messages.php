<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = getCurrentUser();

if (!$user) {
    sendResponse(['error' => 'Unauthorized'], 401);
}

if ($user['type'] !== 'volunteer') {
    sendResponse(['error' => 'Only volunteers can access messaging'], 403);
}

switch ($method) {
    case 'GET':
        $action = isset($_GET['action']) ? $_GET['action'] : 'organizations';
        switch ($action) {
            case 'organizations':
                getOrganizations();
                break;
            case 'messages':
                getMessages();
                break;
            default:
                sendResponse(['error' => 'Invalid action'], 400);
        }
        break;

    case 'POST':
        sendMessage();
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

function getOrganizations() {
    $conn = getDBConnection();

    $stmt = $conn->prepare("SELECT id, name FROM organizations WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
    $organizations = $stmt->fetchAll();

    sendResponse(['success' => true, 'organizations' => $organizations]);
}

function getMessages() {
    global $user;
    $organizationId = isset($_GET['organization_id']) ? (int)$_GET['organization_id'] : 0;

    if (!$organizationId) {
        sendResponse(['error' => 'Organization ID required'], 400);
    }

    $conn = getDBConnection();

    // Get messages both directions between volunteer and organization using current schema
    $stmt = $conn->prepare("
        SELECT m.id, m.message, m.timestamp, m.is_read,
               CASE WHEN m.from_volunteer_id = ? THEN 'sent' ELSE 'received' END AS sender
        FROM messages m
        WHERE (m.from_volunteer_id = ? AND m.to_organization_id = ?)
           OR (m.from_volunteer_id = ? AND m.to_organization_id = ?)
        ORDER BY m.timestamp ASC
    ");
    $stmt->execute([$user['user_id'], $user['user_id'], $organizationId, $organizationId, $user['user_id']]);
    $messages = $stmt->fetchAll();

    // Format timestamps
    foreach ($messages as &$message) {
        $message['time'] = date('H:i A', strtotime($message['timestamp']));
    }

    sendResponse(['success' => true, 'messages' => $messages]);
}

function sendMessage() {
    global $user;
    $data = getPostData();
    $required = ['organization_id', 'message'];
    $missing = validateRequired($data, $required);

    if (!empty($missing)) {
        sendResponse(['error' => 'Missing required fields: ' . implode(', ', $missing)], 400);
    }

    if (empty(trim($data['message']))) {
        sendResponse(['error' => 'Message cannot be empty'], 400);
    }

    $conn = getDBConnection();

    // Verify organization exists
    $stmt = $conn->prepare("SELECT id FROM organizations WHERE id = ? AND is_active = 1");
    $stmt->execute([$data['organization_id']]);
    if (!$stmt->fetch()) {
        sendResponse(['error' => 'Organization not found'], 404);
    }

    try {
        $stmt = $conn->prepare("
            INSERT INTO messages (from_volunteer_id, to_organization_id, message)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$user['user_id'], $data['organization_id'], trim($data['message'])]);

        sendResponse([
            'success' => true,
            'message' => 'Message sent successfully',
            'message_id' => $conn->lastInsertId()
        ]);

    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to send message: ' . $e->getMessage()], 500);
    }
}
?>
