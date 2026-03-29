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

// Ensure table exists
$createSql = "CREATE TABLE IF NOT EXISTS `trial_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_name` VARCHAR(150) NOT NULL,
  `parent_phone` VARCHAR(30) NOT NULL,
  `teacher_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `time` VARCHAR(20) NOT NULL,
  `status` ENUM('pending','completed','cancelled','converted') NOT NULL DEFAULT 'pending',
  `notes` VARCHAR(255) DEFAULT NULL,
  `system_type` ENUM('quran','educational') NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$mysqli->query($createSql);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

if ($method === 'GET') {
    $status = $_GET['status'] ?? null;
    $teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : null;

    $where = [];
    if ($status) { $where[] = "status='" . $mysqli->real_escape_string($status) . "'"; }
    if ($teacherId) { $where[] = "teacher_id=" . (int)$teacherId; }
    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT * FROM trial_sessions $whereSql ORDER BY date DESC, time DESC, id DESC";
    $res = $mysqli->query($sql);
    if (!$res) {
        json_response(['success' => false, 'message' => 'فشل في جلب الحصص التجريبية', 'details' => $mysqli->error], 500);
    }
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (string)$r['id'],
            'studentName' => $r['student_name'],
            'parentPhone' => $r['parent_phone'],
            'teacherId' => (string)$r['teacher_id'],
            'date' => $r['date'],
            'time' => $r['time'],
            'status' => $r['status'],
            'notes' => $r['notes'] ?? '',
            'systemType' => $r['system_type'],
        ];
    }
    json_response(['success' => true, 'data' => $rows]);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { $input = $_POST; }

if ($method === 'POST' && $action === 'create') {
    $studentName = trim($input['studentName'] ?? '');
    $parentPhone = trim($input['parentPhone'] ?? '');
    $teacherId = isset($input['teacherId']) ? (int)$input['teacherId'] : 0;
    $date = trim($input['date'] ?? '');
    $time = trim($input['time'] ?? '');
    $notes = trim($input['notes'] ?? '');
    $systemType = trim($input['systemType'] ?? 'quran');

    if ($studentName === '' || $parentPhone === '' || $teacherId <= 0 || $date === '' || $time === '') {
        json_response(['success' => false, 'message' => 'الحقول مطلوبة'], 400);
    }

    $stmt = $mysqli->prepare("INSERT INTO trial_sessions (student_name,parent_phone,teacher_id,`date`,`time`,`status`,notes,system_type) VALUES (?,?,?,?,?,'pending',?,?)");
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    $stmt->bind_param('ssissss', $studentName, $parentPhone, $teacherId, $date, $time, $notes, $systemType);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'فشل في إضافة الحصة', 'details' => $stmt->error], 500);
    }
    json_response(['success' => true, 'data' => ['id' => $stmt->insert_id]], 201);
}

if ($method === 'POST' && $action === 'update') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) { json_response(['success' => false, 'message' => 'معرف غير صالح'], 400); }

    $studentName = trim($input['studentName'] ?? '');
    $parentPhone = trim($input['parentPhone'] ?? '');
    $teacherId = isset($input['teacherId']) ? (int)$input['teacherId'] : 0;
    $date = trim($input['date'] ?? '');
    $time = trim($input['time'] ?? '');
    $notes = trim($input['notes'] ?? '');
    $systemType = trim($input['systemType'] ?? 'quran');

    $stmt = $mysqli->prepare("UPDATE trial_sessions SET student_name=?, parent_phone=?, teacher_id=?, `date`=?, `time`=?, notes=?, system_type=? WHERE id=?");
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    $stmt->bind_param('ssissssi', $studentName, $parentPhone, $teacherId, $date, $time, $notes, $systemType, $id);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'فشل في تحديث الحصة', 'details' => $stmt->error], 500);
    }
    json_response(['success' => true]);
}

if ($method === 'POST' && $action === 'delete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) { json_response(['success' => false, 'message' => 'معرف غير صالح'], 400); }

    $stmt = $mysqli->prepare("DELETE FROM trial_sessions WHERE id=?");
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'فشل في حذف الحصة', 'details' => $stmt->error], 500);
    }
    json_response(['success' => true]);
}

if ($method === 'POST' && $action === 'change_status') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $newStatus = trim($input['status'] ?? '');
    if ($id <= 0 || !in_array($newStatus, ['pending','completed','cancelled','converted'], true)) {
        json_response(['success' => false, 'message' => 'مدخلات غير صالحة'], 400);
    }
    $stmt = $mysqli->prepare("UPDATE trial_sessions SET status=? WHERE id=?");
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    $stmt->bind_param('si', $newStatus, $id);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'فشل في تحديث الحالة', 'details' => $stmt->error], 500);
    }
    json_response(['success' => true]);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
