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

function map_slot_row(array $row): array {
    return [
        'id' => isset($row['id']) ? (int)$row['id'] : 0,
        'teacherId' => isset($row['teacher_id']) ? (int)$row['teacher_id'] : 0,
        'dayOfWeek' => $row['day_of_week'] ?? '',
        'startTime' => $row['start_time'] ?? '',
        'endTime' => $row['end_time'] ?? '',
        'isAvailable' => isset($row['is_available']) ? (bool)$row['is_available'] : true,
        'notes' => $row['notes'] ?? '',
        'createdAt' => $row['created_at'] ?? '',
        'updatedAt' => $row['updated_at'] ?? '',
    ];
}

// إنشاء جدول المواعيد المتاحة إذا لم يكن موجودًا
if (!$mysqli->query(
    "CREATE TABLE IF NOT EXISTS `teacher_available_slots` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `teacher_id` INT UNSIGNED NOT NULL,
      `day_of_week` ENUM('السبت','الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة') NOT NULL,
      `start_time` TIME NOT NULL,
      `end_time` TIME NOT NULL,
      `is_available` BOOLEAN NOT NULL DEFAULT TRUE,
      `notes` VARCHAR(255) DEFAULT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_teacher_slots` (`teacher_id`, `day_of_week`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;"
)) {
    json_response([
        'success' => false,
        'message' => 'DB init failed (teacher_available_slots)',
        'details' => $mysqli->error,
    ], 500);
}

// قراءة JSON مرة واحدة
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// GET: جلب المواعيد المتاحة لمعلم محدد
if ($method === 'GET') {
    $teacherId = isset($_GET['teacherId']) ? (int)$_GET['teacherId'] : 0;
    $includeBooked = isset($_GET['includeBooked']) && ($_GET['includeBooked'] === '1' || $_GET['includeBooked'] === 'true');

    if ($teacherId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المعلم مطلوب'], 400);
    }

    $stmt = $mysqli->prepare(
        "SELECT * FROM `teacher_available_slots`
         WHERE teacher_id = ? AND is_available = 1
         ORDER BY FIELD(day_of_week, 'السبت','الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة'), start_time ASC"
    );

    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    // إذا لم نطلب إرجاع المواعيد المحجوزة، قم بإخفاء المواعيد التي تم حجزها من قبل الطلاب النشطين
    if (!$includeBooked) {
        $bookedLabels = [];
        if ($studentsStmt = $mysqli->prepare('SELECT DISTINCT schedule_time FROM students WHERE teacher_id = ? AND schedule_time IS NOT NULL AND schedule_time <> "" AND status = "active"')) {
            $studentsStmt->bind_param('i', $teacherId);
            $studentsStmt->execute();
            $studentsResult = $studentsStmt->get_result();
            while ($srow = $studentsResult->fetch_assoc()) {
                if (!empty($srow['schedule_time'])) {
                    $bookedLabels[$srow['schedule_time']] = true;
                }
            }
            $studentsStmt->close();
        }

        $filtered = [];
        foreach ($rows as $row) {
            $label = ($row['day_of_week'] ?? '') . ' ' . ($row['start_time'] ?? '') . ' - ' . ($row['end_time'] ?? '');
            if (isset($bookedLabels[$label])) {
                continue;
            }
            $filtered[] = $row;
        }
        $rows = $filtered;
    }

    $slots = array_map('map_slot_row', $rows);

    json_response(['success' => true, 'data' => $slots]);
}

// POST: إضافة موعد متاح
if ($method === 'POST' && $action === 'create') {
    $teacherId = isset($input['teacherId']) ? (int)$input['teacherId'] : 0;
    $dayOfWeek = trim($input['dayOfWeek'] ?? '');
    $startTime = trim($input['startTime'] ?? '');
    $endTime = trim($input['endTime'] ?? '');
    $notes = trim($input['notes'] ?? '');

    if ($teacherId <= 0 || $dayOfWeek === '' || $startTime === '' || $endTime === '') {
        json_response(['success' => false, 'message' => 'جميع الحقول مطلوبة'], 400);
    }

    // التحقق من عدم وجود نفس الموعد بالضبط (نفس اليوم، نفس وقت البداية والنهاية)
    $checkSql = "SELECT COUNT(*) AS cnt
                 FROM `teacher_available_slots`
                 WHERE teacher_id = ?
                   AND day_of_week = ?
                   AND is_available = 1
                   AND start_time = ?
                   AND end_time = ?";

    $checkStmt = $mysqli->prepare($checkSql);
    if (!$checkStmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد فحص التداخل', 'details' => $mysqli->error], 500);
    }

    $checkStmt->bind_param('isss', $teacherId, $dayOfWeek, $startTime, $endTime);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $checkRow = $checkResult->fetch_assoc();
    $checkStmt->close();

    if (!empty($checkRow['cnt']) && (int)$checkRow['cnt'] > 0) {
        json_response(['success' => false, 'message' => 'يوجد تداخل مع موعد آخر في نفس اليوم'], 400);
    }

    $stmt = $mysqli->prepare('INSERT INTO `teacher_available_slots` (teacher_id, day_of_week, start_time, end_time, notes) VALUES (?, ?, ?, ?, ?)');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('issss', $teacherId, $dayOfWeek, $startTime, $endTime, $notes);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل في إضافة الموعد', 'details' => $error], 500);
    }

    $newId = $stmt->insert_id;
    $stmt->close();

    $res = $mysqli->query('SELECT * FROM `teacher_available_slots` WHERE id = ' . (int)$newId . ' LIMIT 1');
    $row = $res ? $res->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم إضافة الموعد بنجاح',
        'data' => $row ? map_slot_row($row) : null,
    ], 201);
}

// POST: حذف موعد متاح
if ($method === 'POST' && $action === 'delete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;

    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $stmt = $mysqli->prepare('DELETE FROM `teacher_available_slots` WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json_response(['success' => false, 'message' => 'لم يتم العثور على الموعد'], 404);
    }

    json_response(['success' => true, 'message' => 'تم حذف الموعد بنجاح']);
}

// POST: تبديل حالة الموعد (متاح/غير متاح)
if ($method === 'POST' && $action === 'toggle') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $stmt = $mysqli->prepare('UPDATE `teacher_available_slots` SET is_available = NOT is_available WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $res = $mysqli->query('SELECT * FROM `teacher_available_slots` WHERE id = ' . (int)$id . ' LIMIT 1');
    $row = $res ? $res->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم تحديث حالة الموعد بنجاح',
        'data' => $row ? map_slot_row($row) : null,
    ]);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
