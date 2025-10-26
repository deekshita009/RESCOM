<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = getCurrentUser();

if (!$user) {
    sendResponse(['error' => 'Unauthorized'], 401);
}

switch ($method) {
    case 'GET':
        if ($user['type'] === 'volunteer') {
            getAllDonations();
        } else {
            sendResponse(['error' => 'Only volunteers can view donations'], 403);
        }
        break;

    case 'POST':
        createDonation();
        break;

    case 'PUT':
        if ($user['type'] === 'volunteer') {
            updateDonationStatus();
        } else {
            sendResponse(['error' => 'Only volunteers can update donation status'], 403);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

function getAllDonations() {
    $conn = getDBConnection();

    $stmt = $conn->prepare("
        SELECT id, name, email, mobile, blood_group, address, status,
               DATE_FORMAT(submitted_at, '%Y-%m-%d') as date,
               aadhar_files, medical_files
        FROM donations
        ORDER BY submitted_at DESC
    ");
    $stmt->execute();
    $donations = $stmt->fetchAll();

    sendResponse(['success' => true, 'donations' => $donations]);
}

function createDonation() {
    $data = getPostData();
    $required = ['name', 'mobile', 'email', 'blood_group', 'address'];
    $missing = validateRequired($data, $required);

    if (!empty($missing)) {
        sendResponse(['error' => 'Missing required fields: ' . implode(', ', $missing)], 400);
    }

    $validBloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    if (!in_array($data['blood_group'], $validBloodGroups)) {
        sendResponse(['error' => 'Invalid blood group'], 400);
    }

    $conn = getDBConnection();

    // Handle file uploads
    $aadharFiles = [];
    $medicalFiles = [];

    // Handle Aadhar files
    if (isset($_FILES['aadhar_files'])) {
        foreach ($_FILES['aadhar_files']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['aadhar_files']['error'][$key] === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $_FILES['aadhar_files']['name'][$key],
                    'type' => $_FILES['aadhar_files']['type'][$key],
                    'tmp_name' => $_FILES['aadhar_files']['tmp_name'][$key],
                    'error' => $_FILES['aadhar_files']['error'][$key],
                    'size' => $_FILES['aadhar_files']['size'][$key]
                ];
                $uploadedPath = uploadFile($file, '../uploads/donations/aadhar/');
                if ($uploadedPath) {
                    $aadharFiles[] = $uploadedPath;
                }
            }
        }
    }

    // Medical files are no longer collected via UI; persist as empty array
    $medicalFiles = [];

    try {
        $stmt = $conn->prepare("
            INSERT INTO donations (name, mobile, email, blood_group, address, aadhar_files, medical_files)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['mobile'],
            $data['email'],
            $data['blood_group'],
            $data['address'],
            json_encode($aadharFiles),
            json_encode($medicalFiles)
        ]);

        sendResponse([
            'success' => true,
            'message' => 'Donation form submitted successfully',
            'donation_id' => $conn->lastInsertId()
        ]);

    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to submit donation: ' . $e->getMessage()], 500);
    }
}

function updateDonationStatus() {
    global $user;
    $data = getPostData();
    $required = ['donation_id', 'status'];
    $missing = validateRequired($data, $required);

    if (!empty($missing)) {
        sendResponse(['error' => 'Missing required fields: ' . implode(', ', $missing)], 400);
    }

    $validStatuses = ['pending_review', 'approved', 'rejected'];
    if (!in_array($data['status'], $validStatuses)) {
        sendResponse(['error' => 'Invalid status'], 400);
    }

    $conn = getDBConnection();

    try {
        $stmt = $conn->prepare("
            UPDATE donations
            SET status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$data['status'], $user['user_id'], $data['donation_id']]);

        sendResponse(['success' => true, 'message' => 'Donation status updated successfully']);

    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to update donation status: ' . $e->getMessage()], 500);
    }
}
?>
