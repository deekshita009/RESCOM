<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = getCurrentUser();

if (!$user) {
    sendResponse(['error' => 'Unauthorized'], 401);
}

switch ($method) {
    case 'GET':
        getUserProfile();
        break;

    case 'PUT':
        updateUserProfile();
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

function getUserProfile() {
    global $user;
    $conn = getDBConnection();

    if ($user['type'] === 'reporter') {
        $stmt = $conn->prepare("SELECT email, username, mobile, credit_points FROM users WHERE id = ?");
        $stmt->execute([$user['user_id']]);
        $profile = $stmt->fetch();

        if ($profile) {
            $profile['initial'] = strtoupper(substr($profile['username'], 0, 1));
        }
    } elseif ($user['type'] === 'volunteer') {
        $stmt = $conn->prepare("SELECT username, organization FROM volunteers WHERE id = ?");
        $stmt->execute([$user['user_id']]);
        $profile = $stmt->fetch();

        if ($profile) {
            $profile['initial'] = strtoupper(substr($profile['username'], 0, 1));
        }
    }

    if (!$profile) {
        sendResponse(['error' => 'User not found'], 404);
    }

    sendResponse(['success' => true, 'profile' => $profile]);
}

function updateUserProfile() {
    global $user;
    $data = getPostData();

    $conn = getDBConnection();

    if ($user['type'] === 'reporter') {
        $updateFields = [];
        $params = [];

        if (isset($data['username']) && !empty($data['username'])) {
            $updateFields[] = 'username = ?';
            $params[] = $data['username'];
        }

        if (isset($data['mobile']) && !empty($data['mobile'])) {
            $updateFields[] = 'mobile = ?';
            $params[] = $data['mobile'];
        }

        if (empty($updateFields)) {
            sendResponse(['error' => 'No valid fields to update'], 400);
        }

        $params[] = $user['user_id'];

        try {
            $stmt = $conn->prepare("UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?");
            $stmt->execute($params);

            sendResponse(['success' => true, 'message' => 'Profile updated successfully']);

        } catch (Exception $e) {
            sendResponse(['error' => 'Failed to update profile: ' . $e->getMessage()], 500);
        }

    } elseif ($user['type'] === 'volunteer') {
        // Volunteers can only update certain fields if needed
        sendResponse(['error' => 'Profile updates not allowed for volunteers'], 403);
    }
}
?>
