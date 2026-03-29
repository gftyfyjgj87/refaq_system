<?php

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Create profile table if not exists
$createSql = "CREATE TABLE IF NOT EXISTS `teacher_profiles` (
  `user_id` INT UNSIGNED NOT NULL,
  `bio` TEXT NULL,
  `availability` TEXT NULL,
  `achievements` JSON NULL,
  `rating` DECIMAL(3,2) NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_tp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$mysqli->query($createSql);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

function arabic_specialization(?string $teacherType): string {
    switch ($teacherType) {
        case 'quran': return 'قرآن';
        case 'educational': return 'تربوي';
        case 'both': return 'قرآن وتربوي';
        default: return '';
    }
}

if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) { json_response(['success' => false, 'message' => 'معرّف غير صالح'], 400); }

    // Base user
    $resU = $mysqli->query("SELECT id, name, email, phone, teacher_type, hourly_rate, zoom_link FROM users WHERE id = $id LIMIT 1");
    if (!$resU || !$resU->num_rows) { 
        // Debug: Check if user exists at all
        $debugRes = $mysqli->query("SELECT COUNT(*) as count FROM users WHERE id = $id");
        $debugRow = $debugRes ? $debugRes->fetch_assoc() : ['count' => 0];
        json_response([
            'success' => false, 
            'message' => 'لم يتم العثور على المعلم',
            'debug' => [
                'requested_id' => $id,
                'users_with_id' => (int)$debugRow['count'],
                'query_error' => $mysqli->error
            ]
        ], 404); 
    }
    $u = $resU->fetch_assoc();

    // Extended profile
    $resP = $mysqli->query("SELECT bio, availability, achievements, rating FROM teacher_profiles WHERE user_id = $id LIMIT 1");
    $p = $resP ? $resP->fetch_assoc() : null;

    // Students count (active)
    $studentsCount = 0;
    if ($res = $mysqli->query("SELECT COUNT(*) AS c FROM students WHERE teacher_id = $id AND status = 'active'")) {
        $studentsCount = (int)($res->fetch_assoc()['c'] ?? 0);
    }

    // Completed sessions and total hours (فقط الحصص المكتملة)
    $completedSessions = 0; $totalHours = 0;
    if ($res = $mysqli->query("SELECT COUNT(*) AS c, COALESCE(SUM(hours),0) AS h FROM teacher_sessions WHERE teacher_id = $id AND status = 'completed'")) {
        $row = $res->fetch_assoc();
        $completedSessions = (int)($row['c'] ?? 0);
        $totalHours = (int)round((float)($row['h'] ?? 0));
    }

    // Wallet balance = total earned - total paid
    $earned = 0.0; $paid = 0.0;
    if ($res = $mysqli->query("SELECT COALESCE(SUM(amount),0) AS e FROM teacher_sessions WHERE teacher_id = $id")) {
        $earned = (float)($res->fetch_assoc()['e'] ?? 0);
    }
    if ($res = $mysqli->query("SELECT COALESCE(SUM(amount),0) AS p FROM teacher_payments WHERE teacher_id = $id")) {
        $paid = (float)($res->fetch_assoc()['p'] ?? 0);
    }
    $walletBalance = $earned - $paid;

    // Build response
    $achievements = [];
    if (!empty($p['achievements'])) {
        $decoded = json_decode($p['achievements'], true);
        if (is_array($decoded)) { $achievements = array_values(array_filter(array_map('strval', $decoded))); }
    }

    $data = [
        'id' => (string)$u['id'],
        'name' => (string)($u['name'] ?? ''),
        'email' => (string)($u['email'] ?? ''),
        'phone' => (string)($u['phone'] ?? ''),
        'specialization' => arabic_specialization($u['teacher_type'] ?? null),
        'hourlyRate' => isset($u['hourly_rate']) ? (float)$u['hourly_rate'] : 0.0,
        'totalHours' => $totalHours,
        'walletBalance' => $walletBalance,
        'rating' => isset($p['rating']) ? (float)$p['rating'] : null,
        'studentsCount' => $studentsCount,
        'completedSessions' => $completedSessions,
        'bio' => (string)($p['bio'] ?? ''),
        'availability' => (string)($p['availability'] ?? ''),
        'achievements' => $achievements,
        'zoomLink' => (string)($u['zoom_link'] ?? ''),
    ];

    json_response(['success' => true, 'data' => $data]);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { $input = $_POST; }

if ($method === 'POST' && $action === 'update') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) { json_response(['success' => false, 'message' => 'معرّف غير صالح'], 400); }

    // Allowed user fields
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $hourlyRate = isset($input['hourlyRate']) ? (float)$input['hourlyRate'] : null; // optional

    // Extended fields
    $bio = trim($input['bio'] ?? '');
    $availability = trim($input['availability'] ?? '');
    $achievements = $input['achievements'] ?? [];
    if (!is_array($achievements)) { $achievements = []; }
    $achievements = array_values(array_filter(array_map('strval', $achievements)));
    $achJson = json_encode($achievements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Update users table
    $fields = [];
    if ($name !== '') { $fields[] = "name='" . $mysqli->real_escape_string($name) . "'"; }
    if ($email !== '') { $fields[] = "email='" . $mysqli->real_escape_string($email) . "'"; }
    if ($phone !== '') { $fields[] = "phone='" . $mysqli->real_escape_string($phone) . "'"; }
    if ($hourlyRate !== null) { $fields[] = "hourly_rate=" . (float)$hourlyRate; }
    if (!empty($fields)) {
        $sqlU = "UPDATE users SET " . implode(',', $fields) . " WHERE id = $id";
        if (!$mysqli->query($sqlU)) {
            json_response(['success' => false, 'message' => 'فشل تحديث بيانات المستخدم', 'details' => $mysqli->error], 500);
        }
    }

    // Upsert profile
    $stmt = $mysqli->prepare("INSERT INTO teacher_profiles (user_id, bio, availability, achievements) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE bio=VALUES(bio), availability=VALUES(availability), achievements=VALUES(achievements)");
    if (!$stmt) { json_response(['success' => false, 'message' => 'فشل إعداد الاستعلام', 'details' => $mysqli->error], 500); }
    $stmt->bind_param('isss', $id, $bio, $availability, $achJson);
    if (!$stmt->execute()) { json_response(['success' => false, 'message' => 'فشل حفظ الملف', 'details' => $stmt->error], 500); }

    // Return fresh data
    $_GET['id'] = (string)$id;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    // Re-run GET logic by including this file? Simpler: reconstruct data by calling ourselves not ideal.
    // Instead duplicate minimal fetch:
    $resU = $mysqli->query("SELECT id, name, email, phone, teacher_type, hourly_rate FROM users WHERE id = $id LIMIT 1");
    $u = $resU ? $resU->fetch_assoc() : null;
    $resP = $mysqli->query("SELECT bio, availability, achievements, rating FROM teacher_profiles WHERE user_id = $id LIMIT 1");
    $p = $resP ? $resP->fetch_assoc() : null;

    $studentsCount = 0;
    if ($res = $mysqli->query("SELECT COUNT(*) AS c FROM students WHERE teacher_id = $id AND status = 'active'")) {
        $studentsCount = (int)($res->fetch_assoc()['c'] ?? 0);
    }
    $completedSessions = 0; $totalHours = 0;
    if ($res = $mysqli->query("SELECT COUNT(*) AS c, COALESCE(SUM(hours),0) AS h FROM teacher_sessions WHERE teacher_id = $id AND status = 'completed'")) {
        $row = $res->fetch_assoc();
        $completedSessions = (int)($row['c'] ?? 0);
        $totalHours = (int)round((float)($row['h'] ?? 0));
    }
    $earned = 0.0; $paid = 0.0;
    if ($res = $mysqli->query("SELECT COALESCE(SUM(amount),0) AS e FROM teacher_sessions WHERE teacher_id = $id")) { $earned = (float)($res->fetch_assoc()['e'] ?? 0); }
    if ($res = $mysqli->query("SELECT COALESCE(SUM(amount),0) AS p FROM teacher_payments WHERE teacher_id = $id")) { $paid = (float)($res->fetch_assoc()['p'] ?? 0); }
    $walletBalance = $earned - $paid;

    $achievements = [];
    if (!empty($p['achievements'])) {
        $decoded = json_decode($p['achievements'], true);
        if (is_array($decoded)) { $achievements = array_values(array_filter(array_map('strval', $decoded))); }
    }

    $data = [
        'id' => (string)($u['id'] ?? $id),
        'name' => (string)($u['name'] ?? ''),
        'email' => (string)($u['email'] ?? ''),
        'phone' => (string)($u['phone'] ?? ''),
        'specialization' => arabic_specialization($u['teacher_type'] ?? null),
        'hourlyRate' => isset($u['hourly_rate']) ? (float)$u['hourly_rate'] : 0.0,
        'totalHours' => $totalHours,
        'walletBalance' => $walletBalance,
        'rating' => isset($p['rating']) ? (float)$p['rating'] : null,
        'studentsCount' => $studentsCount,
        'completedSessions' => $completedSessions,
        'bio' => (string)($p['bio'] ?? ''),
        'availability' => (string)($p['availability'] ?? ''),
        'achievements' => $achievements,
    ];

    json_response(['success' => true, 'data' => $data]);
}

// POST: تحديث رابط Zoom
if ($method === 'POST' && $action === 'update_zoom') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $zoomLink = trim($input['zoomLink'] ?? '');
    
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرّف غير صالح'], 400);
    }
    
    // تحديث رابط Zoom في جدول users
    $stmt = $mysqli->prepare("UPDATE users SET zoom_link = ? WHERE id = ?");
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    
    $stmt->bind_param('si', $zoomLink, $id);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'فشل حفظ رابط Zoom', 'details' => $stmt->error], 500);
    }
    
    $stmt->close();
    
    json_response([
        'success' => true,
        'message' => 'تم حفظ رابط Zoom بنجاح',
        'data' => ['zoomLink' => $zoomLink]
    ]);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
