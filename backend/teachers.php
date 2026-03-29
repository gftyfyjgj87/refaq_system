<?php

ini_set('display_errors', '1');
error_reporting(E_ALL);

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

ensure_teachers_schema($mysqli);

function map_teacher_row(array $row): array {
    $status = $row['status'] ?? 'active';
    $hourlyRate = $row['hourly_rate'] ?? null;
    $hourlyRateQuran = $row['hourly_rate_quran'] ?? null;
    $hourlyRateEducational = $row['hourly_rate_educational'] ?? null;
    $teacherType = $row['teacher_type'] ?? null;
    $gender = $row['gender'] ?? null;
    $branchId = $row['branch_id'] ?? null;
    $departmentId = $row['department_id'] ?? null;
    $zoomLink = $row['zoom_link'] ?? null;

    // Aggregated stats (may come from JOIN, default to 0)
    $totalHours = isset($row['total_hours']) ? (float)$row['total_hours'] : 0.0;
    $totalSessions = isset($row['total_sessions']) ? (int)$row['total_sessions'] : 0;

    // backward compatible display rate
    $displayRate = $hourlyRate !== null ? (float)$hourlyRate : 0.0;
    if (($teacherType ?: 'quran') === 'both') {
        if ($hourlyRateQuran !== null) {
            $displayRate = (float)$hourlyRateQuran;
        }
    }

    return [
        'id' => isset($row['id']) ? (string)$row['id'] : '',
        'name' => $row['name'] ?? '',
        'email' => $row['email'] ?? '',
        'phone' => $row['phone'] ?? '',
        'gender' => $gender !== null ? $gender : null,
        'departmentId' => $departmentId !== null ? (string)$departmentId : null,
        'zoomLink' => $zoomLink,
        'specialization' => '',
        'teacherType' => $teacherType ?: 'quran',
        'hourlyRate' => $displayRate,
        'hourlyRateQuran' => $hourlyRateQuran !== null ? (float)$hourlyRateQuran : null,
        'hourlyRateEducational' => $hourlyRateEducational !== null ? (float)$hourlyRateEducational : null,
        'totalHours' => $totalHours,
        'totalSessions' => $totalSessions,
        'walletBalance' => 0,
        // فرع المعلم بصيغتين: camelCase و snake_case لدعم جميع الواجهات
        'branchId' => $branchId !== null ? (string)$branchId : '',
        'branch_id' => $branchId !== null ? (int)$branchId : null,
        'status' => $status,
    ];
}

if ($method === 'GET') {
    // عرض جميع المعلمين مع إحصائيات الحصص المكتملة (الساعات وعدد الحصص)
    $sql = "
        SELECT 
            u.*, 
            COALESCE(ts_stats.total_hours, 0) AS total_hours,
            COALESCE(ts_stats.total_sessions, 0) AS total_sessions
        FROM users u
        LEFT JOIN (
            SELECT 
                teacher_id,
                SUM(hours) AS total_hours,
                COUNT(*) AS total_sessions
            FROM teacher_sessions
            WHERE status = 'completed'
            GROUP BY teacher_id
        ) AS ts_stats ON ts_stats.teacher_id = u.id
        WHERE u.role = 'teacher'
    ";

    $result = $mysqli->query($sql);
    if (!$result) {
        json_response(['success' => false, 'message' => 'فشل في جلب المعلمين', 'details' => $mysqli->error], 500);
    }

    $teachers = [];
    while ($row = $result->fetch_assoc()) {
        $teachers[] = map_teacher_row($row);
    }

    json_response(['success' => true, 'data' => $teachers]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST' && $action === 'create') {
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = trim($input['password'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $genderInput = $input['gender'] ?? null;
    $gender = ($genderInput === 'male' || $genderInput === 'female') ? $genderInput : null;
    $teacherType = $input['teacherType'] ?? 'quran';
    $hourlyRate = isset($input['hourlyRate']) ? (float)$input['hourlyRate'] : 0.0;
    $hourlyRateQuran = isset($input['hourlyRateQuran']) ? (float)$input['hourlyRateQuran'] : null;
    $hourlyRateEducational = isset($input['hourlyRateEducational']) ? (float)$input['hourlyRateEducational'] : null;
    $branchIdInput = $input['branchId'] ?? ($_GET['branch_id'] ?? null);
    $branchId = $branchIdInput !== null ? (int)$branchIdInput : 0;

    if ($name === '' || $email === '' || $password === '' || $phone === '') {
        json_response(['success' => false, 'message' => 'الاسم والبريد وكلمة المرور ورقم الهاتف مطلوبة'], 400);
    }

    // تشفير كلمة المرور
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $hourlyRateStored = $hourlyRate;
    if ($teacherType === 'both') {
        $hourlyRateStored = $hourlyRateQuran !== null ? (float)$hourlyRateQuran : $hourlyRate;
    }

    $status = 'active';
    $stmt = $mysqli->prepare("INSERT INTO users (name, email, password, role, status, phone, gender, branch_id, teacher_type, hourly_rate, hourly_rate_quran, hourly_rate_educational) VALUES (?, ?, ?, 'teacher', ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    // name(s), email(s), password(s), status(s), phone(s), gender(s), branch_id(i), teacher_type(s), hourly_rate(d), hourly_rate_quran(d), hourly_rate_educational(d)
    $stmt->bind_param('ssssssisddd', $name, $email, $hashedPassword, $status, $phone, $gender, $branchId, $teacherType, $hourlyRateStored, $hourlyRateQuran, $hourlyRateEducational);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        if (strpos($error, 'Duplicate') !== false) {
            json_response(['success' => false, 'message' => 'البريد الإلكتروني مستخدم بالفعل'], 400);
        }
        json_response(['success' => false, 'message' => 'فشل في حفظ المعلم', 'details' => $error], 500);
    }

    $id = $stmt->insert_id;
    $stmt->close();

    $result = $mysqli->query("SELECT * FROM users WHERE id = " . (int)$id . " LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم إضافة المعلم بنجاح',
        'data' => $row ? map_teacher_row($row) : null,
    ], 201);
}

if ($method === 'POST' && $action === 'update') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $genderInput = $input['gender'] ?? null;
    $gender = ($genderInput === 'male' || $genderInput === 'female') ? $genderInput : null;
    $branchIdInput = $input['branchId'] ?? ($_GET['branch_id'] ?? null);
    $branchId = $branchIdInput !== null ? (int)$branchIdInput : 0;
    $teacherType = $input['teacherType'] ?? 'quran';
    $hourlyRate = isset($input['hourlyRate']) ? (float)$input['hourlyRate'] : 0.0;
    $hourlyRateQuran = isset($input['hourlyRateQuran']) ? (float)$input['hourlyRateQuran'] : null;
    $hourlyRateEducational = isset($input['hourlyRateEducational']) ? (float)$input['hourlyRateEducational'] : null;
    $statusInput = $input['status'] ?? 'active';
    $status = ($statusInput === 'inactive') ? 'inactive' : 'active';

    $hourlyRateStored = $hourlyRate;
    if ($teacherType === 'both') {
        $hourlyRateStored = $hourlyRateQuran !== null ? (float)$hourlyRateQuran : $hourlyRate;
    }

    $stmt = $mysqli->prepare("UPDATE users SET name = ?, email = ?, phone = ?, gender = ?, branch_id = ?, teacher_type = ?, hourly_rate = ?, hourly_rate_quran = ?, hourly_rate_educational = ?, status = ? WHERE id = ? AND role = 'teacher'");
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('ssssisdddsi', $name, $email, $phone, $gender, $branchId, $teacherType, $hourlyRateStored, $hourlyRateQuran, $hourlyRateEducational, $status, $id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        if (strpos($error, 'Duplicate') !== false) {
            json_response(['success' => false, 'message' => 'البريد الإلكتروني مستخدم بالفعل'], 400);
        }
        json_response(['success' => false, 'message' => 'فشل في تحديث المعلم', 'details' => $error], 500);
    }

    $stmt->close();

    $result = $mysqli->query("SELECT * FROM users WHERE id = " . (int)$id . " LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم تحديث المعلم بنجاح',
        'data' => $row ? map_teacher_row($row) : null,
    ]);
}

if ($method === 'POST' && $action === 'delete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ? AND role = 'teacher'");
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json_response(['success' => false, 'message' => 'لم يتم العثور على المعلم'], 404);
    }

    json_response(['success' => true, 'message' => 'تم حذف المعلم بنجاح']);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);

function ensure_teachers_schema(mysqli $mysqli): void {
    $columns = [];
    $res = $mysqli->query("SHOW COLUMNS FROM users");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columns[strtolower($row['Field'])] = true;
        }
    }

    if (!isset($columns['phone'])) {
        $mysqli->query("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL");
    }
    if (!isset($columns['gender'])) {
        $mysqli->query("ALTER TABLE users ADD COLUMN gender ENUM('male','female') NULL AFTER phone");
    }
    if (!isset($columns['branch_id'])) {
        $mysqli->query("ALTER TABLE users ADD COLUMN branch_id INT(11) DEFAULT 1 AFTER role");
        $mysqli->query("ALTER TABLE users ADD KEY idx_branch_id (branch_id)");
    }
    if (!isset($columns['teacher_type'])) {
        $mysqli->query("ALTER TABLE users ADD COLUMN teacher_type VARCHAR(20) NULL");
    }
    if (!isset($columns['hourly_rate'])) {
        $mysqli->query("ALTER TABLE users ADD COLUMN hourly_rate DECIMAL(10,2) NULL");
    }
    if (!isset($columns['hourly_rate_quran'])) {
        $mysqli->query("ALTER TABLE users ADD COLUMN hourly_rate_quran DECIMAL(10,2) NULL");
    }
    if (!isset($columns['hourly_rate_educational'])) {
        $mysqli->query("ALTER TABLE users ADD COLUMN hourly_rate_educational DECIMAL(10,2) NULL");
    }
    if (!isset($columns['status'])) {
        $mysqli->query("ALTER TABLE users ADD COLUMN status ENUM('active','inactive') DEFAULT 'active'");
    }
    if (!isset($columns['department_id'])) {
        $mysqli->query("ALTER TABLE users ADD COLUMN department_id INT UNSIGNED NULL COMMENT 'القسم التابع له المعلم' AFTER branch_id");
        $mysqli->query("ALTER TABLE users ADD KEY idx_department_id (department_id)");
    }
    if (!isset($columns['zoom_link'])) {
        $mysqli->query("ALTER TABLE users ADD COLUMN zoom_link VARCHAR(500) NULL COMMENT 'رابط Zoom للمعلم' AFTER department_id");
    }
}
