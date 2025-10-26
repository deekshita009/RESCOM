<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = getCurrentUser();

if (!$user) {
    sendResponse(['error' => 'Unauthorized'], 401);
}

// Compute nearest organizations and create report_notifications rows, then email them
function assignNearestOrganizations($conn, $reportId, $lat, $lng) {
    // Ensure orgs have coordinates; try to geocode missing ones once
    $orgs = $conn->query("SELECT id, address, latitude, longitude FROM organizations WHERE is_active = 1")->fetchAll();
    foreach ($orgs as $org) {
        if ($org['latitude'] === null || $org['longitude'] === null) {
            if (!empty($org['address'])) {
                $coords = serverSideGeocode($org['address']);
                if ($coords) {
                    $upd = $conn->prepare("UPDATE organizations SET latitude = ?, longitude = ? WHERE id = ?");
                    $upd->execute([$coords['lat'], $coords['lng'], $org['id']]);
                }
            }
        }
    }

    // Select 1 nearest using Haversine
    $stmt = $conn->prepare("\n        SELECT id, name, contact_info, latitude, longitude,\n               (6371 * ACOS(\n                   COS(RADIANS(?)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS(?)) +\n                   SIN(RADIANS(?)) * SIN(RADIANS(latitude))\n               )) AS distance_km\n        FROM organizations\n        WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL\n        ORDER BY distance_km ASC\n        LIMIT 1\n    ");
    $stmt->execute([$lat, $lng, $lat]);
    $nearest = $stmt->fetchAll();
    if ($nearest) {
        // Fetch report details once for email content
        $rstmt = $conn->prepare("SELECT type, category, location, description FROM reports WHERE id = ?");
        $rstmt->execute([$reportId]);
        $report = $rstmt->fetch();

        $ins = $conn->prepare("INSERT INTO report_notifications (report_id, organization_id, distance_km, status) VALUES (?, ?, ?, 'queued')");
        foreach ($nearest as $row) {
            $ins->execute([$reportId, $row['id'], $row['distance_km']]);

            // Attempt to email the organization if an email exists in contact_info JSON
            $email = null;
            if (!empty($row['contact_info'])) {
                $ci = json_decode($row['contact_info'], true);
                if (is_array($ci)) {
                    if (!empty($ci['email']) && filter_var($ci['email'], FILTER_VALIDATE_EMAIL)) {
                        $email = $ci['email'];
                    } elseif (!empty($ci['emails']) && is_array($ci['emails'])) {
                        foreach ($ci['emails'] as $em) {
                            if (filter_var($em, FILTER_VALIDATE_EMAIL)) { $email = $em; break; }
                        }
                    }
                }
            }

            if ($email && $report) {
                $subject = 'RESCOM: New Incident Near Your Organization';
                $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222">'
                    . '<p>Dear ' . htmlspecialchars($row['name']) . ' Team,</p>'
                    . '<p>A new incident has been reported near your location.</p>'
                    . '<ul>'
                    . '<li><strong>Type:</strong> ' . htmlspecialchars($report['type']) . '</li>'
                    . '<li><strong>Category:</strong> ' . htmlspecialchars($report['category']) . '</li>'
                    . '<li><strong>Location:</strong> ' . htmlspecialchars($report['location']) . '</li>'
                    . '<li><strong>Distance:</strong> ' . number_format((float)$row['distance_km'], 2) . ' km</li>'
                    . '</ul>'
                    . '<p><strong>Description:</strong><br>' . nl2br(htmlspecialchars($report['description'])) . '</p>'
                    . '<p>Kindly review and respond via your volunteer dashboard.</p>'
                    . '<p>Regards,<br>RESCOM System</p>'
                    . '</div>';
                // Fire-and-forget email; ignore result for now
                @sendEmail($email, $subject, $body);
            }
        }
    }
}

// Very simple server-side geocoder using OpenStreetMap Nominatim (no API key)
function serverSideGeocode($address) {
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $address,
        'format' => 'json',
        'limit' => 1
    ]);
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: RESCOM/1.0\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    try {
        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) return null;
        $json = json_decode($resp, true);
        if (!empty($json) && isset($json[0]['lat'], $json[0]['lon'])) {
            return ['lat' => (float)$json[0]['lat'], 'lng' => (float)$json[0]['lon']];
        }
    } catch (Exception $e) {
        // ignore
    }
    return null;
}

switch ($method) {
    case 'GET':
        if ($user['type'] === 'reporter') {
            getUserReports($user['user_id']);
        } elseif ($user['type'] === 'volunteer') {
            getVolunteerReports();
        }
        break;

    case 'POST':
        if ($user['type'] === 'reporter') {
            createReport($user['user_id']);
        } else {
            sendResponse(['error' => 'Only reporters can create reports'], 403);
        }
        break;

    case 'PUT':
        if ($user['type'] === 'volunteer') {
            updateReportStatus();
        } else {
            sendResponse(['error' => 'Only volunteers can update report status'], 403);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

function getUserReports($userId) {
    $conn = getDBConnection();

    $stmt = $conn->prepare("
        SELECT id, type, category, location, DATE_FORMAT(incident_datetime, '%Y-%m-%d') as date,
               status, description
        FROM reports
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$userId]);
    $reports = $stmt->fetchAll();

    sendResponse(['success' => true, 'reports' => $reports]);
}

function getVolunteerReports() {
    global $user;
    $conn = getDBConnection();

    // Resolve volunteer's organization -> organizations.id
    $stmt = $conn->prepare("SELECT organization FROM volunteers WHERE id = ? AND is_active = 1");
    $stmt->execute([$user['user_id']]);
    $vol = $stmt->fetch();

    if (!$vol || empty($vol['organization'])) {
        sendResponse(['success' => true, 'reports' => []]);
    }

    $stmt = $conn->prepare("SELECT id FROM organizations WHERE name = ? AND is_active = 1");
    $stmt->execute([$vol['organization']]);
    $org = $stmt->fetch();

    if (!$org) {
        sendResponse(['success' => true, 'reports' => []]);
    }

    // Fetch only reports that were notified to this organization
    $stmt = $conn->prepare("\n        SELECT r.id, r.type, r.category, r.location, r.description,\n               DATE_FORMAT(r.incident_datetime, '%Y-%m-%d') as date,\n               r.status, u.email as reporter_email, u.username as reporter_username,\n               rn.distance_km\n        FROM reports r\n        JOIN users u ON r.user_id = u.id\n        JOIN report_notifications rn ON rn.report_id = r.id\n        WHERE rn.organization_id = ?\n        ORDER BY r.created_at DESC\n    ");
    $stmt->execute([$org['id']]);
    $reports = $stmt->fetchAll();

    sendResponse(['success' => true, 'reports' => $reports]);
}

function createReport($userId) {
    $data = getPostData();
    $required = ['type', 'category', 'location', 'description', 'incident_datetime'];
    $missing = validateRequired($data, $required);

    if (!empty($missing)) {
        sendResponse(['error' => 'Missing required fields: ' . implode(', ', $missing)], 400);
    }

    // Validate type and category
    $validTypes = ['human', 'animal'];
    $validCategories = ['abandoned', 'injured', 'mentally_ill', 'others'];

    if (!in_array($data['type'], $validTypes)) {
        sendResponse(['error' => 'Invalid incident type'], 400);
    }

    if (!in_array($data['category'], $validCategories)) {
        sendResponse(['error' => 'Invalid category'], 400);
    }

    $conn = getDBConnection();

    // Handle file upload if present
    $imagePath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $imagePath = uploadFile($_FILES['image'], '../uploads/reports/');
        if (!$imagePath) {
            sendResponse(['error' => 'Failed to upload image'], 500);
        }
    }

    try {
        $conn->beginTransaction();

        // Insert report
        $stmt = $conn->prepare("\n            INSERT INTO reports (user_id, type, category, location, latitude, longitude, description, incident_datetime, image_path)\n            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\n        ");
        $stmt->execute([
            $userId,
            $data['type'],
            $data['category'],
            $data['location'],
            isset($data['latitude']) ? $data['latitude'] : null,
            isset($data['longitude']) ? $data['longitude'] : null,
            $data['description'],
            $data['incident_datetime'],
            $imagePath
        ]);

        $reportId = $conn->lastInsertId();

        // Award credit points (20 points per report)
        $stmt = $conn->prepare("UPDATE users SET credit_points = credit_points + 20 WHERE id = ?");
        $stmt->execute([$userId]);

        // If coordinates provided, assign to 3 nearest organizations
        $lat = isset($data['latitude']) ? (float)$data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float)$data['longitude'] : null;
        if ($lat && $lng) {
            assignNearestOrganizations($conn, $reportId, $lat, $lng);
        }

        $conn->commit();

        sendResponse([
            'success' => true,
            'message' => 'Incident reported successfully',
            'report_id' => $reportId
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        sendResponse(['error' => 'Failed to create report: ' . $e->getMessage()], 500);
    }
}

function updateReportStatus() {
    global $user;
    $data = getPostData();
    $required = ['report_id', 'status'];
    $missing = validateRequired($data, $required);

    if (!empty($missing)) {
        sendResponse(['error' => 'Missing required fields: ' . implode(', ', $missing)], 400);
    }

    // Extend valid statuses per requirements
    $validStatuses = ['pending', 'accepted', 'processing', 'completed', 'rejected', 'forwarded', 'reached', 'rescued'];
    if (!in_array($data['status'], $validStatuses)) {
        sendResponse(['error' => 'Invalid status'], 400);
    }

    $conn = getDBConnection();

    try {
        $conn->beginTransaction();

        // Get current status
        $stmt = $conn->prepare("SELECT status FROM reports WHERE id = ?");
        $stmt->execute([$data['report_id']]);
        $currentReport = $stmt->fetch();

        if (!$currentReport) {
            sendResponse(['error' => 'Report not found'], 404);
        }

        // If forwarded, optionally add/report to target organization
        $forwardOrgName = null;
        if ($data['status'] === 'forwarded') {
            $orgId = isset($data['organization_id']) ? (int)$data['organization_id'] : 0;
            if ($orgId > 0) {
                // Verify organization exists and is active
                $chk = $conn->prepare("SELECT id, name, contact_info FROM organizations WHERE id = ? AND is_active = 1");
                $chk->execute([$orgId]);
                $org = $chk->fetch();
                if (!$org) {
                    sendResponse(['error' => 'Target organization not found'], 404);
                }
                $forwardOrgName = $org['name'];
                // Insert mapping if not already present
                $exists = $conn->prepare("SELECT id FROM report_notifications WHERE report_id = ? AND organization_id = ?");
                $exists->execute([$data['report_id'], $orgId]);
                if (!$exists->fetch()) {
                    $ins = $conn->prepare("INSERT INTO report_notifications (report_id, organization_id, distance_km, status) VALUES (?, ?, NULL, 'queued')");
                    $ins->execute([$data['report_id'], $orgId]);
                }
                // Send email to the forwarded organization if email present
                $rstmt = $conn->prepare("SELECT type, category, location, description FROM reports WHERE id = ?");
                $rstmt->execute([$data['report_id']]);
                $report = $rstmt->fetch();
                if ($report && !empty($org['contact_info'])) {
                    $email = null; $ci = json_decode($org['contact_info'], true);
                    if (is_array($ci)) {
                        if (!empty($ci['email']) && filter_var($ci['email'], FILTER_VALIDATE_EMAIL)) { $email = $ci['email']; }
                        elseif (!empty($ci['emails']) && is_array($ci['emails'])) {
                            foreach ($ci['emails'] as $em) { if (filter_var($em, FILTER_VALIDATE_EMAIL)) { $email = $em; break; } }
                        }
                    }
                    if ($email) {
                        $subject = 'RESCOM: Incident Forwarded To Your Organization';
                        $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222">'
                            . '<p>Dear ' . htmlspecialchars($org['name']) . ' Team,</p>'
                            . '<p>An incident has been forwarded to your organization.</p>'
                            . '<ul>'
                            . '<li><strong>Type:</strong> ' . htmlspecialchars($report['type']) . '</li>'
                            . '<li><strong>Category:</strong> ' . htmlspecialchars($report['category']) . '</li>'
                            . '<li><strong>Location:</strong> ' . htmlspecialchars($report['location']) . '</li>'
                            . '</ul>'
                            . '<p><strong>Description:</strong><br>' . nl2br(htmlspecialchars($report['description'])) . '</p>'
                            . '<p>Please review it in your volunteer dashboard.</p>'
                            . '<p>Regards,<br>RESCOM System</p>'
                            . '</div>';
                        @sendEmail($email, $subject, $body);
                    }
                }
            }
        }

        // Update report status
        $stmt = $conn->prepare("\n            UPDATE reports SET status = ?, assigned_volunteer_id = ?, updated_at = CURRENT_TIMESTAMP\n            WHERE id = ?\n        ");
        $stmt->execute([$data['status'], $user['user_id'], $data['report_id']]);

        // Insert status change history
        $stmt = $conn->prepare("
            INSERT INTO report_status_history (report_id, old_status, new_status, changed_by, notes)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['report_id'],
            $currentReport['status'],
            $data['status'],
            $user['user_id'],
            isset($data['notes']) ? $data['notes'] : null
        ]);

        // Notify reporter via email about status update
        $info = $conn->prepare("SELECT u.email, u.username, r.type, r.category, r.location FROM reports r JOIN users u ON u.id = r.user_id WHERE r.id = ?");
        $info->execute([$data['report_id']]);
        $row = $info->fetch();
        if ($row && !empty($row['email'])) {
            $niceStatus = ucfirst($data['status']);
            $subject = 'RESCOM: Your report status updated to ' . $niceStatus;
            $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222">'
                  . '<p>Hi ' . htmlspecialchars($row['username']) . ',</p>'
                  . '<p>The status of your incident report has been updated.</p>'
                  . '<ul>'
                  . '<li><strong>Status:</strong> ' . htmlspecialchars($niceStatus) . '</li>'
                  . '<li><strong>Type:</strong> ' . htmlspecialchars($row['type']) . '</li>'
                  . '<li><strong>Category:</strong> ' . htmlspecialchars($row['category']) . '</li>'
                  . '<li><strong>Location:</strong> ' . htmlspecialchars($row['location']) . '</li>'
                  . '</ul>';
            if ($forwardOrgName) {
                $body .= '<p>This report was forwarded to: <strong>' . htmlspecialchars($forwardOrgName) . '</strong></p>';
            }
            $body .= '<p>Thank you for using RESCOM.</p>'
                   . '<p>Regards,<br>RESCOM Team</p>'
                   . '</div>';
            @sendEmail($row['email'], $subject, $body);
        }

        $conn->commit();

        sendResponse(['success' => true, 'message' => 'Report status updated successfully']);

    } catch (Exception $e) {
        $conn->rollBack();
        sendResponse(['error' => 'Failed to update report status: ' . $e->getMessage()], 500);
    }
}
?>
