<?php
require_once __DIR__ . '/vendor/phpmailer/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// SMTP configuration for PHPMailer (fill with your provider values)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587); // 587 for TLS, 465 for SSL
define('SMTP_USER', 'rescomteam05@gmail.com');
define('SMTP_PASS', 'rvdv ecjy jxqt rfmg');
define('SMTP_SECURE', 'tls'); // 'tls' or 'ssl'
define('MAIL_FROM_EMAIL', 'rescomteam05@gmail.com');
define('MAIL_FROM_NAME', 'RESCOM');

// Helper function to send an email (HTML) using PHPMailer SMTP
function sendEmail($to, $subject, $htmlBody, $from = MAIL_FROM_EMAIL, $fromName = MAIL_FROM_NAME) {
    if (empty($to)) return false;
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE; // 'tls' or 'ssl'
        $mail->Port       = SMTP_PORT;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        return $mail->send();
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $e->getMessage());
        return false;
    }
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'rescom_database');
define('DB_CHARSET', 'utf8mb4');

// JWT Secret (move to env/secure storage for production)
define('JWT_SECRET', 'change-this-secret');

// Create database connection
function getDBConnection() {
    try {
        $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $conn;
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
        exit();
    }
}

// Helper function to send JSON response
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
}

// Helper function to get POST/PUT data supporting JSON and form-data
function getPostData() {
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    // Handle JSON
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    // Handle form-data and x-www-form-urlencoded
    if ($_POST && is_array($_POST)) {
        return $_POST;
    }
    // Fallback for PUT with x-www-form-urlencoded
    $raw = file_get_contents('php://input');
    if ($raw) {
        $parsed = [];
        parse_str($raw, $parsed);
        if (is_array($parsed)) return $parsed;
    }
    return [];
}

// Helper function to validate required fields
function validateRequired($data, $requiredFields) {
    $missing = [];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            $missing[] = $field;
        }
    }
    return $missing;
}

// Helper function to hash password
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Helper function to verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Helper function to base64url encode/decode
function b64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function b64url_decode($data) {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $padlen = 4 - $remainder;
        $data .= str_repeat('=', $padlen);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

// Helper function to generate JWT-like token (HS256)
function generateToken($userId, $type) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode([
        'user_id' => $userId,
        'type' => $type,
        'iat' => time(),
        'exp' => time() + (24 * 60 * 60)
    ]);

    $headerEncoded = b64url_encode($header);
    $payloadEncoded = b64url_encode($payload);
    $signature = hash_hmac('sha256', $headerEncoded . "." . $payloadEncoded, JWT_SECRET, true);
    $signatureEncoded = b64url_encode($signature);

    return $headerEncoded . "." . $payloadEncoded . "." . $signatureEncoded;
}

// Helper function to validate token (verify signature and expiry)
function validateToken($token) {
    if (!$token) return false;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    list($h, $p, $s) = $parts;
    // Verify signature
    $expected = b64url_encode(hash_hmac('sha256', $h . '.' . $p, JWT_SECRET, true));
    if (!hash_equals($expected, $s)) {
        return false;
    }
    $payload = json_decode(b64url_decode($p), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
        return false;
    }
    return $payload;
}

// Helper function to get current user from token
function getCurrentUser() {
    if (!function_exists('getallheaders')) {
        // Fallback for non-Apache environments
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
    } else {
        $headers = getallheaders();
    }
    $authHeader = '';
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
    } elseif (isset($headers['authorization'])) {
        $authHeader = $headers['authorization'];
    }

    if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return false;
    }

    $token = $matches[1];
    return validateToken($token);
}

// Helper function to upload file
function uploadFile($file, $uploadDir = 'uploads/') {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileName = uniqid() . '_' . basename($file['name']);
    $filePath = $uploadDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        return $filePath;
    }

    return false;
}
?>
