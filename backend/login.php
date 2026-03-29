<?php

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$email = isset($input['email']) ? trim($input['email']) : '';
$password = isset($input['password']) ? trim($input['password']) : '';

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني وكلمة المرور مطلوبان']);
    exit;
}

try {
    // Try with supervisor_type column first
    $stmt = $mysqli->prepare('SELECT id, name, email, password, role, supervisor_type, branch_id, permissions FROM users WHERE email = ? LIMIT 1');
    
    // If prepare failed (column doesn't exist), try without supervisor_type
    if (!$stmt) {
        $stmt = $mysqli->prepare('SELECT id, name, email, password, role, branch_id, permissions FROM users WHERE email = ? LIMIT 1');
    }
    
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'فشل في إعداد الاستعلام: ' . $mysqli->error]);
        exit;
    }
    
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة']);
        exit;
    }

    // التحقق من كلمة المرور باستخدام password_verify
    if (!password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة']);
        exit;
    }

    unset($user['password']);

    // Decode permissions if it's a string
    if (isset($user['permissions']) && is_string($user['permissions'])) {
        $user['permissions'] = json_decode($user['permissions'], true) ?: [];
    }
    elseif (!isset($user['permissions'])) {
        $user['permissions'] = [];
    }

    // Rename supervisor_type to supervisorType for frontend consistency
    if (isset($user['supervisor_type'])) {
        $user['supervisorType'] = $user['supervisor_type'];
        unset($user['supervisor_type']);
    }

    echo json_encode([
        'success' => true,
        'message' => 'تم تسجيل الدخول بنجاح',
        'user' => $user,
    ]);

}
catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطأ في الخادم: ' . $e->getMessage()]);
}
