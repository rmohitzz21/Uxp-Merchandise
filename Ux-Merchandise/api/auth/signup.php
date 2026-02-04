<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../../includes/config.php';

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

// Support both JSON input and form data
if (!$data && !empty($_POST)) {
    $data = $_POST;
}

// Basic Fields
$fullName = isset($data['fullName']) ? trim($data['fullName']) : '';
$firstName = isset($data['firstName']) ? trim($data['firstName']) : '';
$lastName = isset($data['lastName']) ? trim($data['lastName']) : '';
$email = isset($data['email']) ? trim($data['email']) : '';
$password = isset($data['password']) ? $data['password'] : '';
$phone = isset($data['phone']) ? trim($data['phone']) : '';

// Parse Full Name if separate fields are missing
if (!empty($fullName) && (empty($firstName) || empty($lastName))) {
    $parts = explode(' ', $fullName, 2);
    $firstName = isset($parts[0]) ? trim($parts[0]) : '';
    $lastName = isset($parts[1]) ? trim($parts[1]) : '';
}

// Validation
if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Please fill in all required fields"]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid email format"]);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Password must be at least 6 characters"]);
    exit;
}

// Check Email uniqueness
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    http_response_code(409); // Conflict
    echo json_encode(["status" => "error", "message" => "Email already registered"]);
    $stmt->close();
    exit;
}
$stmt->close();

// Check Phone uniqueness (if provided)
if (!empty($phone)) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ?");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        http_response_code(409); // Conflict
        echo json_encode(["status" => "error", "message" => "Phone number already registered"]);
        $stmt->close();
        exit;
    }
    $stmt->close();
}

// Use plain text to maintain compatibility with legacy 'admin123' check if needed, 
// OR switch to password_hash if new system fully supports it. 
// For "Proper" PHP, we should hash.
// $passwordHash = password_hash($password, PASSWORD_DEFAULT);
$passwordHash = $password; // Keeping plain text request from previous turns for compatibility

// Insert User
$role = 'customer';
$isBlocked = 0;
$createdAt = date('Y-m-d H:i:s');

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, phone, password_hash, role, is_blocked, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssis", $firstName, $lastName, $email, $phone, $passwordHash, $role, $isBlocked, $createdAt);
    
    if (!$stmt->execute()) {
        throw new Exception("Registration failed: " . $stmt->error);
    }
    
    $userId = $stmt->insert_id;
    $stmt->close();

    // --- Generate Tokens ---
    // 1. Access Token (Short-lived, e.g., 1 hour) - Not stored in DB, strictly client-side
    $accessToken = bin2hex(random_bytes(32)); 
    
    // 2. Refresh Token (Long-lived, e.g., 30 days) - Stored in DB
    $refreshToken = bin2hex(random_bytes(64));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    // Insert Refresh Token
    $stmtToken = $conn->prepare("INSERT INTO user_tokens (user_id, refresh_token, expires_at) VALUES (?, ?, ?)");
    $stmtToken->bind_param("iss", $userId, $refreshToken, $expiresAt);
    
    if (!$stmtToken->execute()) {
        throw new Exception("Token generation failed: " . $stmtToken->error);
    }
    $stmtToken->close();
    
    $conn->commit();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "User registered successfully",
        "user" => [
            "id" => $userId,
            "email" => $email,
            "firstName" => $firstName,
            "lastName" => $lastName,
            "role" => $role
        ],
        "tokens" => [
            "access_token" => $accessToken,
            "refresh_token" => $refreshToken,
            "expires_in" => 3600 // 1 hour seconds
        ]
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}

$conn->close();
?>
