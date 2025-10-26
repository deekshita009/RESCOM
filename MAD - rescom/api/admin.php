<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = getCurrentUser();

if (!$user) {
    sendResponse(['error' => 'Unauthorized'], 401);
}
if ($user['type'] !== 'admin') {
    sendResponse(['error' => 'Forbidden'], 403);
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($method) {
    case 'GET':
        if ($action === 'organizations') {
            getOrganizations();
        } elseif ($action === 'users') {
            getUsers();
        } elseif ($action === 'volunteers') {
            getVolunteers();
        } else {
            sendResponse(['error' => 'Invalid action'], 400);
        }
        break;

    case 'POST':
        if ($action === 'organizations') {
            createOrganization();
        } elseif ($action === 'volunteers') {
            createVolunteer();
        } else {
            sendResponse(['error' => 'Invalid action'], 400);
        }
        break;

    case 'DELETE':
        if ($action === 'organizations') {
            deleteOrganization();
        } else {
            sendResponse(['error' => 'Invalid action'], 400);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

function getOrganizations() {
    $conn = getDBConnection();
    $stmt = $conn->query("SELECT id, name, description, address, latitude, longitude, contact_info, is_active FROM organizations ORDER BY is_active DESC, name");
    $orgs = $stmt->fetchAll();
    sendResponse(['success' => true, 'organizations' => $orgs]);
}

function getUsers() {
    $conn = getDBConnection();
    $stmt = $conn->query("SELECT id, email, username, mobile, credit_points FROM users ORDER BY created_at DESC, id DESC");
    $users = $stmt->fetchAll();
    sendResponse(['success' => true, 'users' => $users]);
}

function getVolunteers() {
    $conn = getDBConnection();
    $stmt = $conn->query("SELECT id, username, organization, is_active, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at FROM volunteers ORDER BY created_at DESC, id DESC");
    $rows = $stmt->fetchAll();
    sendResponse(['success' => true, 'volunteers' => $rows]);
}

function createOrganization() {
    $data = getPostData();
    $required = ['name', 'description', 'address', 'email', 'phone'];
    $missing = validateRequired($data, $required);
    if (!empty($missing)) {
        sendResponse(['error' => 'Missing required fields: ' . implode(', ', $missing)], 400);
    }

    $name = trim($data['name']);
    $description = isset($data['description']) ? trim($data['description']) : null;
    $address = isset($data['address']) ? trim($data['address']) : null;
    $lat = isset($data['latitude']) && $data['latitude'] !== '' ? (float)$data['latitude'] : null;
    $lng = isset($data['longitude']) && $data['longitude'] !== '' ? (float)$data['longitude'] : null;
    $isActive = isset($data['is_active']) ? (int)(!!$data['is_active']) : 1;

    $contactInfo = null;
    $email = isset($data['email']) ? trim($data['email']) : '';
    $phone = isset($data['phone']) ? trim($data['phone']) : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse(['error' => 'Invalid email format'], 400);
    }
    if (!preg_match('/^[0-9+\-\s]{7,20}$/', $phone)) {
        sendResponse(['error' => 'Invalid phone number'], 400);
    }
    $contactInfo = json_encode(['email' => $email, 'phone' => $phone]);

    $conn = getDBConnection();

    $chk = $conn->prepare('SELECT id FROM organizations WHERE name = ?');
    $chk->execute([$name]);
    if ($chk->fetch()) {
        sendResponse(['error' => 'Organization with this name already exists'], 409);
    }

    // Auto-geocode if address is provided and lat/lng are missing
    if ($address && ($lat === null || $lng === null)) {
        try {
            $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($address);
            $opts = [
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: RESCOM/1.0\r\nAccept: application/json\r\n",
                    'timeout' => 5
                ]
            ];
            $ctx = stream_context_create($opts);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp) {
                $arr = json_decode($resp, true);
                if (is_array($arr) && !empty($arr[0]) && isset($arr[0]['lat']) && isset($arr[0]['lon'])) {
                    $lat = (float)$arr[0]['lat'];
                    $lng = (float)$arr[0]['lon'];
                }
            }
        } catch (Exception $e) {
            // ignore geocoding failure; proceed without coords
        }
    }

    $stmt = $conn->prepare("INSERT INTO organizations (name, description, address, latitude, longitude, contact_info, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $description, $address, $lat, $lng, $contactInfo, $isActive]);

    sendResponse(['success' => true, 'id' => $conn->lastInsertId()]);
}

function deleteOrganization() {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        sendResponse(['error' => 'Invalid id'], 400);
    }
    $conn = getDBConnection();
    $stmt = $conn->prepare('UPDATE organizations SET is_active = 0 WHERE id = ?');
    $stmt->execute([$id]);
    sendResponse(['success' => true]);
}

function createVolunteer() {
    $data = getPostData();
    $required = ['username', 'password', 'organization'];
    $missing = validateRequired($data, $required);
    if (!empty($missing)) {
        sendResponse(['error' => 'Missing required fields: ' . implode(', ', $missing)], 400);
    }

    $username = trim($data['username']);
    $password = (string)$data['password'];
    $organization = trim($data['organization']);
    $isActive = isset($data['is_active']) ? (int)(!!$data['is_active']) : 1;

    if ($username === '' || $password === '' || $organization === '') {
        sendResponse(['error' => 'Username, password, and organization are required'], 400);
    }

    $conn = getDBConnection();

    // Ensure organization exists and is active (optional: allow inactive?)
    $chkOrg = $conn->prepare('SELECT id FROM organizations WHERE name = ?');
    $chkOrg->execute([$organization]);
    if (!$chkOrg->fetch()) {
        sendResponse(['error' => 'Organization not found'], 404);
    }

    // Ensure unique username in volunteers
    $chkUser = $conn->prepare('SELECT id FROM volunteers WHERE username = ?');
    $chkUser->execute([$username]);
    if ($chkUser->fetch()) {
        sendResponse(['error' => 'Volunteer username already exists'], 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare('INSERT INTO volunteers (username, password, organization, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$username, $hash, $organization, $isActive]);

    sendResponse(['success' => true, 'id' => $conn->lastInsertId()]);
}
