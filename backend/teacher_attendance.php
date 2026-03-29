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

// إنشاء جدول حضور المعلمين
$createTableSql = "CREATE TABLE IF NOT EXISTS `teacher_attendance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teacher_id` INT UNSIGNED NOT NULL,
  `session_id` INT UNSIGNED NULL COMMENT 'معرف الحصة',
  `date` DATE NOT NULL,
  `status` ENUM('present','absent') NOT NULL,
  `reason` TEXT NULL COMMENT 'سبب الغياب',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_teacher_session_date` (`teacher_id`, `session_id`, `date`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_date` (`date`),
  CONSTRAINT `fk_ta_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ta_session` FOREIGN KEY (`session_id`) REFERENCES `teacher_sessions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$mysqli->query($createTableSql);

function map_attendance_row(array $row): array {
    return [
        'id' => (string)$row['id'],
        'teacherId' => (string)$row['teacher_id'],
        'sessionId' => isset($row['session_id']) ? (string)$row['session_id'] : null,
        'date' => $row['date'],
        'status' => $row['status'],
        'reason' => $row['reason'] ?? null,
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'],
    ];
}

// GET: جلب سجل حضور معلم
if ($method === 'GET') {
    $teacherId = isset($_GET['teacherId']) ? (int)$_GET['teacherId'] : 0;
    $startDate = $_GET['startDate'] ?? null;
    $endDate = $_GET['endDate'] ?? null;
    
    if ($teacherId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المعلم مطلوب'], 400);
    }
    
    $whereClause = "teacher_id = $teacherId";
    if ($startDate) {
        $whereClause .= " AND date >= '" . $mysqli->real_escape_string($startDate) . "'";
    }
    if ($endDate) {
        $whereClause .= " AND date <= '" . $mysqli->real_escape_string($endDate) . "'";
    }
    
    $result = $mysqli->query("SELECT * FROM teacher_attendance WHERE $whereClause ORDER BY date DESC");
    if (!$result) {
        json_response(['success' => false, 'message' => 'فشل في جلب السجل'], 500);
    }
    
    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = map_attendance_row($row);
    }
    
    json_response(['success' => true, 'data' => $records]);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

// POST: تسجيل حضور/غياب
if ($method === 'POST' && $action === 'record') {
    $teacherId = isset($input['teacherId']) ? (int)$input['teacherId'] : 0;
    $sessionId = isset($input['sessionId']) ? (int)$input['sessionId'] : null;
    $date = trim($input['date'] ?? '');
    $status = trim($input['status'] ?? '');
    $reason = trim($input['reason'] ?? '');
    
    if ($teacherId <= 0 || $date === '' || $status === '') {
        json_response(['success' => false, 'message' => 'البيانات المطلوبة ناقصة'], 400);
    }
    
    if (!in_array($status, ['present', 'absent'])) {
        json_response(['success' => false, 'message' => 'حالة غير صحيحة'], 400);
    }
    
    if ($status === 'absent' && $reason === '') {
        json_response(['success' => false, 'message' => 'سبب الغياب مطلوب'], 400);
    }
    
    // التحقق من عدم وجود تسجيل سابق لنفس الحصة
    if ($sessionId) {
        $checkStmt = $mysqli->prepare("SELECT id FROM teacher_attendance WHERE teacher_id = ? AND session_id = ? AND date = ?");
        $checkStmt->bind_param('iis', $teacherId, $sessionId, $date);
    } else {
        $checkStmt = $mysqli->prepare("SELECT id FROM teacher_attendance WHERE teacher_id = ? AND date = ? AND session_id IS NULL");
        $checkStmt->bind_param('is', $teacherId, $date);
    }
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        // تحديث التسجيل الموجود
        if ($sessionId) {
            $stmt = $mysqli->prepare("UPDATE teacher_attendance SET status = ?, reason = ? WHERE teacher_id = ? AND session_id = ? AND date = ?");
            $stmt->bind_param('ssiis', $status, $reason, $teacherId, $sessionId, $date);
        } else {
            $stmt = $mysqli->prepare("UPDATE teacher_attendance SET status = ?, reason = ? WHERE teacher_id = ? AND date = ? AND session_id IS NULL");
            $stmt->bind_param('ssis', $status, $reason, $teacherId, $date);
        }
    } else {
        // إضافة تسجيل جديد
        $stmt = $mysqli->prepare("INSERT INTO teacher_attendance (teacher_id, session_id, date, status, reason) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('iisss', $teacherId, $sessionId, $date, $status, $reason);
    }
    
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'فشل في تسجيل الحضور/الغياب'], 500);
    }
    
    $stmt->close();
    
    // جلب بيانات المعلم والحصة
    $teacherQuery = $mysqli->query("SELECT name FROM users WHERE id = $teacherId");
    $teacherData = $teacherQuery->fetch_assoc();
    $teacherName = $teacherData['name'] ?? 'معلم';
    
    $sessionInfo = '';
    if ($sessionId) {
        $sessionQuery = $mysqli->query("
            SELECT ts.session_time, g.name as group_name 
            FROM teacher_sessions ts 
            LEFT JOIN `groups` g ON ts.group_id = g.id 
            WHERE ts.id = $sessionId
        ");
        if ($sessionQuery && $sessionQuery->num_rows > 0) {
            $sessionData = $sessionQuery->fetch_assoc();
            $sessionInfo = ' - الحصة: ' . ($sessionData['group_name'] ?? 'حصة فردية') . ' (' . ($sessionData['session_time'] ?? '') . ')';
        }
    }
    
    // إرسال إشعار للإدارة
    $notificationTitle = $status === 'present' ? '✅ تسجيل حضور معلم' : '❌ تسجيل غياب معلم';
    $notificationMessage = $status === 'present' 
        ? "قام المعلم $teacherName بتسجيل حضوره لتاريخ $date$sessionInfo"
        : "قام المعلم $teacherName بتسجيل غيابه لتاريخ $date$sessionInfo. السبب: $reason";
    
    $notificationStmt = $mysqli->prepare("INSERT INTO notifications (title, message, type, priority, target_role, related_id, related_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $notificationType = 'teacher_attendance';
    $priority = $status === 'absent' ? 'high' : 'normal';
    $targetRole = 'admin';
    $relatedType = 'teacher_attendance';
    $notificationStmt->bind_param('sssssss', $notificationTitle, $notificationMessage, $notificationType, $priority, $targetRole, $teacherId, $relatedType);
    $notificationStmt->execute();
    $notificationStmt->close();
    
    json_response([
        'success' => true,
        'message' => $status === 'present' ? 'تم تسجيل الحضور بنجاح' : 'تم تسجيل الغياب بنجاح',
    ]);
}

// GET: إحصائيات حضور معلم
if ($method === 'GET' && $action === 'stats') {
    $teacherId = isset($_GET['teacherId']) ? (int)$_GET['teacherId'] : 0;
    $month = $_GET['month'] ?? date('Y-m');
    
    if ($teacherId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المعلم مطلوب'], 400);
    }
    
    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));
    
    $presentQuery = $mysqli->query("SELECT COUNT(*) as count FROM teacher_attendance WHERE teacher_id = $teacherId AND date BETWEEN '$startDate' AND '$endDate' AND status = 'present'");
    $presentCount = $presentQuery->fetch_assoc()['count'];
    
    $absentQuery = $mysqli->query("SELECT COUNT(*) as count FROM teacher_attendance WHERE teacher_id = $teacherId AND date BETWEEN '$startDate' AND '$endDate' AND status = 'absent'");
    $absentCount = $absentQuery->fetch_assoc()['count'];
    
    $totalDays = (int)date('t', strtotime($startDate));
    $workingDays = $totalDays - 4; // افتراض 4 أيام إجازة في الشهر
    
    json_response([
        'success' => true,
        'data' => [
            'presentDays' => (int)$presentCount,
            'absentDays' => (int)$absentCount,
            'totalDays' => $totalDays,
            'workingDays' => $workingDays,
            'attendanceRate' => $workingDays > 0 ? round(($presentCount / $workingDays) * 100, 2) : 0,
        ]
    ]);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
