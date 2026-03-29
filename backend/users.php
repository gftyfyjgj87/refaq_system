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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// Ensure supervisor_type column exists
$checkSupervisorType = $mysqli->query("SHOW COLUMNS FROM users LIKE 'supervisor_type'");
if ($checkSupervisorType->num_rows === 0) {
    $mysqli->query("ALTER TABLE users ADD COLUMN supervisor_type ENUM('quran', 'educational', 'both') NULL COMMENT 'نوع المشرف' AFTER role");
}

// Ensure branch_id column exists
$checkBranchId = $mysqli->query("SHOW COLUMNS FROM users LIKE 'branch_id'");
if ($checkBranchId && $checkBranchId->num_rows === 0) {
    $mysqli->query("ALTER TABLE users ADD COLUMN branch_id INT UNSIGNED NULL COMMENT 'الفرع' AFTER supervisor_type");
    $mysqli->query("ALTER TABLE users ADD KEY idx_users_branch (branch_id)");
}

function map_user_row(array $row): array {
    $status = $row['status'] ?? 'active';
    $salary = $row['salary'] ?? null;
    $hourlyRate = $row['hourly_rate'] ?? null;
    $permissionsRaw = $row['permissions'] ?? null;
    $assignedTeachersRaw = $row['assigned_teachers'] ?? null;
    $assignedStudentsRaw = $row['assigned_students'] ?? null;
    $teacherType = $row['teacher_type'] ?? null;
    $supervisorType = $row['supervisor_type'] ?? null;
    $branchId = $row['branch_id'] ?? null;

    return [
        'id' => isset($row['id']) ? (string)$row['id'] : '',
        'name' => $row['name'] ?? '',
        'email' => $row['email'] ?? '',
        'role' => $row['role'] ?? 'supervisor',
        'status' => $status,
        'salary' => $salary !== null ? (float)$salary : null,
        'hourlyRate' => $hourlyRate !== null ? (float)$hourlyRate : null,
        'permissions' => $permissionsRaw ? (json_decode($permissionsRaw, true) ?: []) : [],
        'assignedTeachers' => $assignedTeachersRaw ? (json_decode($assignedTeachersRaw, true) ?: []) : [],
        'assignedStudents' => $assignedStudentsRaw ? (json_decode($assignedStudentsRaw, true) ?: []) : [],
        'teacherType' => $teacherType ?: null,
        'supervisorType' => $supervisorType ?: null,
        'branchId' => $branchId !== null ? (string)$branchId : null,
    ];
}

if ($method === 'GET') {
    $role = $_GET['role'] ?? 'supervisor';
    $allowedRoles = ['admin', 'supervisor', 'teacher'];
    if (!in_array($role, $allowedRoles)) {
        json_response(['success' => false, 'message' => 'دور غير مدعوم'], 400);
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM users WHERE role = ? AND id = ? LIMIT 1");
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
        }
        $stmt->bind_param('si', $role, $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        $data = $row ? [map_user_row($row)] : [];
        json_response(['success' => true, 'data' => $data]);
    } else {
        $stmt = $mysqli->prepare("SELECT * FROM users WHERE role = ?");
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
        }
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $result = $stmt->get_result();
        $list = [];
        while ($row = $result->fetch_assoc()) {
            $list[] = map_user_row($row);
        }
        $stmt->close();
        json_response(['success' => true, 'data' => $list]);
    }
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST' && $action === 'create') {
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $email = preg_replace('/\s+/', '', $email);
    $password = trim($input['password'] ?? '');
    $salary = isset($input['salary']) ? (float)$input['salary'] : null;
    $permissions = $input['permissions'] ?? [];
    $assignedTeachers = $input['assignedTeachers'] ?? [];
    $assignedStudents = $input['assignedStudents'] ?? [];
    $supervisorType = $input['supervisorType'] ?? $input['supervisor_type'] ?? null;
    $branchIdRaw = $input['branchId'] ?? $input['branch_id'] ?? null;
    $branchId = ($branchIdRaw === '' || $branchIdRaw === null) ? null : (int)$branchIdRaw;

    if ($name === '' || $email === '' || $password === '') {
        json_response(['success' => false, 'message' => 'الاسم والبريد وكلمة المرور مطلوبة'], 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'message' => 'صيغة البريد الإلكتروني غير صحيحة'], 400);
    }

    // تشفير كلمة المرور
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // تحقق صريح من تكرار البريد داخل المشرفين فقط
    $dupStmt = $mysqli->prepare("SELECT id, email FROM users WHERE role = 'supervisor' AND LOWER(TRIM(email)) = ? LIMIT 1");
    if ($dupStmt) {
        $dupStmt->bind_param('s', $email);
        $dupStmt->execute();
        $dupRes = $dupStmt->get_result();
        $dupRow = ($dupRes && $dupRes->num_rows > 0) ? $dupRes->fetch_assoc() : null;
        $dupStmt->close();
        if ($dupRow) {
            json_response([
                'success' => false,
                'message' => 'البريد الإلكتروني مستخدم بالفعل',
                'details' => [
                    'normalizedEmail' => $email,
                    'conflictId' => isset($dupRow['id']) ? (string)$dupRow['id'] : null,
                    'conflictEmail' => $dupRow['email'] ?? null,
                ],
            ], 400);
        }
    }

    $stmt = $mysqli->prepare('INSERT INTO users (name, email, password, role, supervisor_type, branch_id, status, salary, permissions, assigned_teachers, assigned_students) VALUES (?, ?, ?, \'supervisor\', ?, ?, \'active\', ?, ?, ?, ?)');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $permissionsJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);
    $assignedTeachersJson = json_encode($assignedTeachers, JSON_UNESCAPED_UNICODE);
    $assignedStudentsJson = json_encode($assignedStudents, JSON_UNESCAPED_UNICODE);

    // name(s), email(s), password(s), supervisor_type(s), branch_id(i), salary(d), permissions(s), assigned_teachers(s), assigned_students(s)
    $stmt->bind_param('ssssidsss', $name, $email, $hashedPassword, $supervisorType, $branchId, $salary, $permissionsJson, $assignedTeachersJson, $assignedStudentsJson);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        // خطأ البريد المكرر
        if (strpos($error, 'Duplicate') !== false) {
            json_response([
                'success' => false,
                'message' => 'البريد الإلكتروني مستخدم بالفعل',
                'details' => $error,
            ], 400);
        }
        json_response(['success' => false, 'message' => 'فشل في حفظ المشرف', 'details' => $error], 500);
    }

    $id = $stmt->insert_id;
    $stmt->close();

    $result = $mysqli->query("SELECT * FROM users WHERE id = " . (int)$id . " LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم إضافة المشرف بنجاح',
        'data' => $row ? map_user_row($row) : null,
    ], 201);
}

if ($method === 'POST' && $action === 'update') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $name = trim($input['name'] ?? '');
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $email = preg_replace('/\s+/', '', $email);
    $password = isset($input['password']) ? trim($input['password']) : null; // كلمة المرور اختيارية عند التحديث
    $salary = isset($input['salary']) ? (float)$input['salary'] : null;
    $permissions = $input['permissions'] ?? [];
    $assignedTeachers = $input['assignedTeachers'] ?? [];
    $assignedStudents = $input['assignedStudents'] ?? [];
    $supervisorType = $input['supervisorType'] ?? $input['supervisor_type'] ?? null;
    $branchIdRaw = $input['branchId'] ?? $input['branch_id'] ?? null;
    $branchId = ($branchIdRaw === '' || $branchIdRaw === null) ? null : (int)$branchIdRaw;
    // تأمين قراءة الحالة في حال لم تُرسل من الواجهة
    $statusInput = $input['status'] ?? 'active';
    $status = ($statusInput === 'inactive') ? 'inactive' : 'active';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'message' => 'صيغة البريد الإلكتروني غير صحيحة'], 400);
    }

    // تحقق صريح من تكرار البريد داخل المشرفين فقط (استثناء نفس المستخدم)
    $dupStmt = $mysqli->prepare("SELECT id, email FROM users WHERE role = 'supervisor' AND LOWER(TRIM(email)) = ? AND id <> ? LIMIT 1");
    if ($dupStmt) {
        $dupStmt->bind_param('si', $email, $id);
        $dupStmt->execute();
        $dupRes = $dupStmt->get_result();
        $dupRow = ($dupRes && $dupRes->num_rows > 0) ? $dupRes->fetch_assoc() : null;
        $dupStmt->close();
        if ($dupRow) {
            json_response([
                'success' => false,
                'message' => 'البريد الإلكتروني مستخدم بالفعل',
                'details' => [
                    'normalizedEmail' => $email,
                    'conflictId' => isset($dupRow['id']) ? (string)$dupRow['id'] : null,
                    'conflictEmail' => $dupRow['email'] ?? null,
                ],
            ], 400);
        }
    }

    // إذا تم إرسال كلمة مرور جديدة، نقوم بتحديثها
    if ($password !== null && $password !== '') {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare('UPDATE users SET name = ?, email = ?, password = ?, supervisor_type = ?, branch_id = ?, salary = ?, permissions = ?, assigned_teachers = ?, assigned_students = ?, status = ? WHERE id = ? AND role = \'supervisor\'');
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
        }
        $permissionsJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);
        $assignedTeachersJson = json_encode($assignedTeachers, JSON_UNESCAPED_UNICODE);
        $assignedStudentsJson = json_encode($assignedStudents, JSON_UNESCAPED_UNICODE);
        // name(s), email(s), password(s), supervisor_type(s), branch_id(i), salary(d), permissions(s), assigned_teachers(s), assigned_students(s), status(s), id(i)
        $stmt->bind_param('ssssidssssi', $name, $email, $hashedPassword, $supervisorType, $branchId, $salary, $permissionsJson, $assignedTeachersJson, $assignedStudentsJson, $status, $id);
    } else {
        // تحديث بدون تغيير كلمة المرور
        $stmt = $mysqli->prepare('UPDATE users SET name = ?, email = ?, supervisor_type = ?, branch_id = ?, salary = ?, permissions = ?, assigned_teachers = ?, assigned_students = ?, status = ? WHERE id = ? AND role = \'supervisor\'');
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
        }
        $permissionsJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);
        $assignedTeachersJson = json_encode($assignedTeachers, JSON_UNESCAPED_UNICODE);
        $assignedStudentsJson = json_encode($assignedStudents, JSON_UNESCAPED_UNICODE);
        // name(s), email(s), supervisor_type(s), branch_id(i), salary(d), permissions(s), assigned_teachers(s), assigned_students(s), status(s), id(i)
        $stmt->bind_param('sssidssssi', $name, $email, $supervisorType, $branchId, $salary, $permissionsJson, $assignedTeachersJson, $assignedStudentsJson, $status, $id);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        if (strpos($error, 'Duplicate') !== false) {
            json_response([
                'success' => false,
                'message' => 'البريد الإلكتروني مستخدم بالفعل',
                'details' => $error,
            ], 400);
        }
        json_response(['success' => false, 'message' => 'فشل في تحديث المشرف', 'details' => $error], 500);
    }

    $stmt->close();

    $result = $mysqli->query("SELECT * FROM users WHERE id = " . (int)$id . " LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم تحديث المشرف بنجاح',
        'data' => $row ? map_user_row($row) : null,
    ]);
}

if ($method === 'POST' && $action === 'delete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $stmt = $mysqli->prepare('DELETE FROM users WHERE id = ? AND role = \'supervisor\'');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json_response(['success' => false, 'message' => 'لم يتم العثور على المشرف'], 404);
    }

    json_response(['success' => true, 'message' => 'تم حذف المشرف بنجاح']);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
