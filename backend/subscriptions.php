<?php

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

function map_package_row(array $row): array {
    return [
        'id' => isset($row['id']) ? (string)$row['id'] : '',
        'name' => $row['name'] ?? '',
        'type' => $row['type'] ?? 'quran',
        'duration' => isset($row['duration']) ? (int)$row['duration'] : 30,
        'price' => isset($row['price']) ? (float)$row['price'] : 0,
        'discount' => isset($row['discount']) ? (float)$row['discount'] : 0,
        'discountType' => isset($row['discount_type']) ? $row['discount_type'] : null,
        'sessionsCount' => isset($row['sessions_count']) ? (int)$row['sessions_count'] : 0,
        'sessionDuration' => isset($row['session_duration']) ? (int)$row['session_duration'] : 60,
        'studentsCount' => isset($row['students_count']) ? (int)$row['students_count'] : 0,
        'status' => $row['status'] ?? 'active',
    ];
}

// Ensure needed columns exist for backward compatibility
$__col_check = $mysqli->query("SHOW COLUMNS FROM `subscription_packages`");
if ($__col_check) {
    $existingCols = [];
    while ($c = $__col_check->fetch_assoc()) {
        $existingCols[strtolower($c['Field'])] = true;
    }

    if (!isset($existingCols['session_duration'])) {
        $mysqli->query("ALTER TABLE `subscription_packages` ADD COLUMN `session_duration` INT NOT NULL DEFAULT 60 COMMENT 'مدة الحصة بالدقائق' AFTER `sessions_count`");
    }
    if (!isset($existingCols['discount_type'])) {
        $mysqli->query("ALTER TABLE `subscription_packages` ADD COLUMN `discount_type` ENUM('whole','decimal') NULL COMMENT 'نوع الخصم (رقم صحيح او عشري)' AFTER `discount`");
    }
    
    // تحديث عمود الخصم ليكون DECIMAL بدلاً من INT لدعم الخصم بالجنيه المصري
    $discountCol = null;
    foreach ($existingCols as $colName => $exists) {
        if ($colName === 'discount') {
            $discountCol = $mysqli->query("SHOW COLUMNS FROM `subscription_packages` WHERE Field = 'discount'")->fetch_assoc();
            break;
        }
    }
    if ($discountCol && stripos($discountCol['Type'], 'int') !== false) {
        $mysqli->query("ALTER TABLE `subscription_packages` MODIFY COLUMN `discount` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'الخصم بالجنيه المصري'");
    }
}

if ($method === 'GET') {
    $result = $mysqli->query("SELECT * FROM `subscription_packages` ORDER BY id DESC");
    if (!$result) {
        json_response(['success' => false, 'message' => 'فشل في جلب الباقات', 'details' => $mysqli->error], 500);
    }

    $packages = [];
    while ($row = $result->fetch_assoc()) {
        $packages[] = map_package_row($row);
    }

    json_response(['success' => true, 'data' => $packages]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST' && $action === 'create') {
    $name = trim($input['name'] ?? '');
    $type = $input['type'] === 'educational' ? 'educational' : 'quran';
    $duration = isset($input['duration']) ? (int)$input['duration'] : 30;
    $price = isset($input['price']) ? (float)$input['price'] : 0;
    $discount = isset($input['discount']) ? (float)$input['discount'] : 0;
    $discountType = ($input['discountType'] ?? null) === 'decimal' ? 'decimal' : 'whole';
    $sessionsCount = isset($input['sessionsCount']) ? (int)$input['sessionsCount'] : 0;
    $sessionDuration = isset($input['sessionDuration']) ? (int)$input['sessionDuration'] : 60;

    if ($name === '' || $duration <= 0) {
        json_response(['success' => false, 'message' => 'الاسم والمدة مطلوبة'], 400);
    }

    $stmt = $mysqli->prepare('INSERT INTO `subscription_packages` (name, type, duration, price, discount, discount_type, sessions_count, session_duration, students_count, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, "active")');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('ssiddsii', $name, $type, $duration, $price, $discount, $discountType, $sessionsCount, $sessionDuration);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل في إنشاء الباقة', 'details' => $error], 500);
    }

    $id = $stmt->insert_id;
    $stmt->close();

    $result = $mysqli->query('SELECT * FROM `subscription_packages` WHERE id = ' . (int)$id . ' LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم إنشاء الباقة بنجاح',
        'data' => $row ? map_package_row($row) : null,
    ], 201);
}

if ($method === 'POST' && $action === 'update') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $name = trim($input['name'] ?? '');
    $type = $input['type'] === 'educational' ? 'educational' : 'quran';
    $duration = isset($input['duration']) ? (int)$input['duration'] : 30;
    $price = isset($input['price']) ? (float)$input['price'] : 0;
    $discount = isset($input['discount']) ? (float)$input['discount'] : 0;
    $discountType = ($input['discountType'] ?? null) === 'decimal' ? 'decimal' : 'whole';
    $sessionsCount = isset($input['sessionsCount']) ? (int)$input['sessionsCount'] : 0;
    $sessionDuration = isset($input['sessionDuration']) ? (int)$input['sessionDuration'] : 60;

    if ($name === '' || $duration <= 0) {
        json_response(['success' => false, 'message' => 'الاسم والمدة مطلوبة'], 400);
    }

    $stmt = $mysqli->prepare('UPDATE `subscription_packages` SET name = ?, type = ?, duration = ?, price = ?, discount = ?, discount_type = ?, sessions_count = ?, session_duration = ? WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('ssiddsiii', $name, $type, $duration, $price, $discount, $discountType, $sessionsCount, $sessionDuration, $id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل في تحديث الباقة', 'details' => $error], 500);
    }

    $stmt->close();

    $result = $mysqli->query('SELECT * FROM `subscription_packages` WHERE id = ' . (int)$id . ' LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم تحديث الباقة بنجاح',
        'data' => $row ? map_package_row($row) : null,
    ]);
}

if ($method === 'POST' && $action === 'toggle_status') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $newStatus = ($input['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

    $stmt = $mysqli->prepare('UPDATE `subscription_packages` SET status = ? WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('si', $newStatus, $id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل في تغيير الحالة', 'details' => $error], 500);
    }
    $stmt->close();

    $result = $mysqli->query('SELECT * FROM `subscription_packages` WHERE id = ' . (int)$id . ' LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم تغيير حالة الباقة بنجاح',
        'data' => $row ? map_package_row($row) : null,
    ]);
}

if ($method === 'POST' && $action === 'delete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $stmt = $mysqli->prepare('DELETE FROM `subscription_packages` WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json_response(['success' => false, 'message' => 'لم يتم العثور على الباقة'], 404);
    }

    json_response(['success' => true, 'message' => 'تم حذف الباقة بنجاح']);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
