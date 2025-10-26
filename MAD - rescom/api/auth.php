<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        $data = getPostData();
        $action = isset($data['action']) ? $data['action'] : '';

        switch ($action) {
            case 'reporter_login':
                loginReporter($data);
                break;
            case 'volunteer_login':
                loginVolunteer($data);
                break;
            case 'admin_login':
                loginAdmin($data);
                break;
            default:
                sendResponse(['error' => 'Invalid action'], 400);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

function loginReporter($data) {
    $required = ['email', 'username', 'mobile'];
    $missing = validateRequired($data, $required);

    if (!empty($missing)) {
        sendResponse(['error' => 'Missing required fields: ' . implode(', ', $missing)], 400);
    }

    // Basic validations
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        sendResponse(['error' => 'Invalid email format'], 400);
    }
    $username = trim($data['username']);
    if (strlen($username) < 3 || strlen($username) > 100 || !preg_match('/^[A-Za-z0-9_\.\-]+$/', $username)) {
        sendResponse(['error' => 'Invalid username'], 400);
    }
    $mobile = preg_replace('/\s+/', '', $data['mobile']);
    if (!preg_match('/^[0-9]{7,15}$/', $mobile)) {
        sendResponse(['error' => 'Invalid mobile number'], 400);
    }

    $conn = getDBConnection();

    // Check if user exists, if not create new user
    $stmt = $conn->prepare("SELECT id, email, username, mobile, credit_points FROM users WHERE email = ?");
    $stmt->execute([$data['email']]);
    $user = $stmt->fetch();

    if (!$user) {
        // Ensure username is unique before insert
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            sendResponse(['error' => 'Username already taken'], 409);
        }

        try {
            // Create new user
            $stmt = $conn->prepare("INSERT INTO users (email, username, mobile) VALUES (?, ?, ?)");
            $stmt->execute([$data['email'], $username, $mobile]);
            $userId = $conn->lastInsertId();
        } catch (Exception $e) {
            sendResponse(['error' => 'Failed to create user'], 500);
        }

        $user = [
            'id' => $userId,
            'email' => $data['email'],
            'username' => $username,
            'mobile' => $mobile,
            'credit_points' => 0
        ];
    } else {
        // Update username and mobile if different
        if ($user['username'] !== $username || $user['mobile'] !== $mobile) {
            // If username changed, ensure uniqueness
            if ($user['username'] !== $username) {
                $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
                $check->execute([$username, $user['id']]);
                if ($check->fetch()) {
                    sendResponse(['error' => 'Username already taken'], 409);
                }
            }
            try {
                $stmt = $conn->prepare("UPDATE users SET username = ?, mobile = ? WHERE id = ?");
                $stmt->execute([$username, $mobile, $user['id']]);
                $user['username'] = $username;
                $user['mobile'] = $mobile;
            } catch (Exception $e) {
                sendResponse(['error' => 'Failed to update user profile'], 500);
            }
        }
    }

    $token = generateToken($user['id'], 'reporter');

    sendResponse([
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'username' => $user['username'],
            'mobile' => $user['mobile'],
            'credit_points' => $user['credit_points'],
            'initial' => strtoupper(substr($user['username'], 0, 1))
        ]
    ]);
}

function loginVolunteer($data) {
    $required = ['username', 'password'];
    $missing = validateRequired($data, $required);

    if (!empty($missing)) {
        sendResponse(['error' => 'Missing required fields: ' . implode(', ', $missing)], 400);
    }

    $conn = getDBConnection();
    $username = trim($data['username']);
    $password = (string)$data['password'];

    $stmt = $conn->prepare("SELECT id, username, password, organization FROM volunteers WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $volunteer = $stmt->fetch();

    if (!$volunteer) {
        sendResponse(['error' => 'Invalid username or password'], 401);
    }

    $stored = (string)$volunteer['password'];
    $valid = false;
    if (!empty($stored)) {
        // Prefer hashed verification
        if (password_get_info($stored)['algo'] !== 0) {
            $valid = verifyPassword($password, $stored);
        } else {
            // Fallback to plain text match if legacy storage
            $valid = hash_equals($stored, $password);
        }
    }

    if (!$valid) {
        sendResponse(['error' => 'Invalid username or password'], 401);
    }

    $token = generateToken($volunteer['id'], 'volunteer');

    sendResponse([
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => $volunteer['id'],
            'username' => $volunteer['username'],
            'organization' => $volunteer['organization'],
            'initial' => strtoupper(substr($volunteer['username'], 0, 1))
        ]
    ]);
}

function loginAdmin($data) {
    $required = ['username', 'password'];
    $missing = validateRequired($data, $required);

    if (!empty($missing)) {
        sendResponse(['error' => 'Missing required fields: ' . implode(', ', $missing)], 400);
    }

    $conn = getDBConnection();
    $username = trim($data['username']);
    $password = (string)$data['password'];

    $stmt = $conn->prepare("SELECT id, username, password FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin) {
        sendResponse(['error' => 'Invalid username or password'], 401);
    }

    $stored = (string)$admin['password'];
    $valid = false;
    if (!empty($stored)) {
        if (password_get_info($stored)['algo'] !== 0) {
            $valid = verifyPassword($password, $stored);
        } else {
            $valid = hash_equals($stored, $password);
        }
    }

    if (!$valid) {
        sendResponse(['error' => 'Invalid username or password'], 401);
    }

    $token = generateToken($admin['id'], 'admin');
    sendResponse([
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => $admin['id'],
            'username' => $admin['username'],
            'initial' => strtoupper(substr($admin['username'], 0, 1))
        ]
    ]);
}
?>
