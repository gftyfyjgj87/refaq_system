<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once 'db.php';

// التحقق من تسجيل الدخول - مبسط للتطوير
session_start();

// محاولة الحصول على المستخدم من session أو token
$user = null;

// 1. محاولة من session
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
}

// 2. محاولة من token
if (!$user) {
    $authHeader = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $authHeader = $v;
                break;
            }
        }
    }
    if (!$authHeader && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    $token = $authHeader ? preg_replace('/^Bearer\s+/i', '', $authHeader) : null;

    if ($token && preg_match('/^token_(\d+)_\d+$/', $token, $matches)) {
        $userId = (int)$matches[1];
        if ($userId > 0) {
            $stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
        }
    }
}

// 3. إذا لم يتم العثور على مستخدم، استخدم مستخدم افتراضي للتطوير
if (!$user) {
    // للتطوير فقط: استخدام أول مستخدم admin
    $result = $mysqli->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1");
    $user = $result->fetch_assoc();
    
    if (!$user) {
        json_response(['success' => false, 'message' => 'لا يوجد مستخدمين في النظام'], 401);
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

ensureBranchesSchema($mysqli);

try {
    switch ($method) {
        case 'GET':
            handleGet($mysqli, $user);
            break;
        case 'POST':
            handlePost($mysqli, $user, $action);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'طريقة غير مدعومة']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطأ في الخادم: ' . $e->getMessage()]);
}

function handleGet($mysqli, $user) {
    // جلب الفروع حسب صلاحية المستخدم
    if ($user['role'] === 'admin') {
        // الإدارة العامة ترى جميع الفروع
        $stmt = $mysqli->prepare("
            SELECT 
                b.*,
                (SELECT COUNT(*) FROM users WHERE branch_id = b.id AND role = 'teacher') as teachers_count,
                (
                    SELECT COUNT(DISTINCT s.id)
                    FROM students s
                    LEFT JOIN groups g ON s.group_id = g.id
                    LEFT JOIN users t ON s.teacher_id = t.id
                    WHERE s.branch_id = b.id
                       OR g.branch_id = b.id
                       OR t.branch_id = b.id
                ) as students_count,
                (SELECT COUNT(*) FROM groups WHERE branch_id = b.id) as groups_count,
                -- بيانات المستخدم المسؤول عن الفرع (أقرب Admin ثم Supervisor إن وجد)
                (
                    SELECT u.name
                    FROM users u
                    WHERE u.branch_id = b.id
                    ORDER BY FIELD(u.role, 'admin', 'supervisor') DESC, u.id DESC
                    LIMIT 1
                ) as admin_name,
                (
                    SELECT u.email
                    FROM users u
                    WHERE u.branch_id = b.id
                    ORDER BY FIELD(u.role, 'admin', 'supervisor') DESC, u.id DESC
                    LIMIT 1
                ) as admin_email
            FROM branches b 
            ORDER BY b.created_at DESC
        ");
        $stmt->execute();
    } else {
        // المشرف والمعلم يرون فرعهم فقط
        $stmt = $mysqli->prepare("
            SELECT 
                b.*,
                (SELECT COUNT(*) FROM users WHERE branch_id = b.id AND role = 'teacher') as teachers_count,
                (
                    SELECT COUNT(DISTINCT s.id)
                    FROM students s
                    LEFT JOIN groups g ON s.group_id = g.id
                    LEFT JOIN users t ON s.teacher_id = t.id
                    WHERE s.branch_id = b.id
                       OR g.branch_id = b.id
                       OR t.branch_id = b.id
                ) as students_count,
                (SELECT COUNT(*) FROM groups WHERE branch_id = b.id) as groups_count,
                -- بيانات المستخدم المسؤول عن الفرع (أقرب Admin ثم Supervisor إن وجد)
                (
                    SELECT u.name
                    FROM users u
                    WHERE u.branch_id = b.id
                    ORDER BY FIELD(u.role, 'admin', 'supervisor') DESC, u.id DESC
                    LIMIT 1
                ) as admin_name,
                (
                    SELECT u.email
                    FROM users u
                    WHERE u.branch_id = b.id
                    ORDER BY FIELD(u.role, 'admin', 'supervisor') DESC, u.id DESC
                    LIMIT 1
                ) as admin_email
            FROM branches b 
            WHERE b.id = ?
            ORDER BY b.created_at DESC
        ");
        $stmt->bind_param('i', $user['branch_id']);
        $stmt->execute();
    }
    
    $result = $stmt->get_result();
    $branches = [];
    while ($row = $result->fetch_assoc()) {
        $branches[] = $row;
    }
    
    // تحويل البيانات للتنسيق المطلوب
    $result = array_map(function($branch) {
        return [
            'id' => $branch['id'],
            'name' => $branch['name'],
            'code' => $branch['code'],
            'address' => $branch['address'],
            'manager' => $branch['manager'],
            'status' => $branch['status'],
            'logo' => $branch['logo'] ?? '',
            'createdAt' => $branch['created_at'],
            'teachersCount' => (int)$branch['teachers_count'],
            'studentsCount' => (int)$branch['students_count'],
            'groupsCount' => (int)$branch['groups_count'],
            // بيانات المستخدم المسؤول إن وُجد
            'adminName' => $branch['admin_name'] ?? null,
            'adminEmail' => $branch['admin_email'] ?? null,
        ];
    }, $branches);
    
    echo json_encode(['success' => true, 'data' => $result]);
}

function handlePost($mysqli, $user, $action) {
    // التحقق من صلاحية الإدارة العامة فقط
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'غير مصرح لك بهذا الإجراء']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'create':
            createBranch($mysqli, $input);
            break;
        case 'update':
            updateBranch($mysqli, $input);
            break;
        case 'delete':
            deleteBranch($mysqli, $input);
            break;
        case 'toggle_status':
            toggleBranchStatus($mysqli, $input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'إجراء غير صحيح']);
    }
}

function createBranch($mysqli, $input) {
    $name = trim($input['name'] ?? '');
    $code = trim($input['code'] ?? '');
    $address = trim($input['address'] ?? '');
    $manager = trim($input['manager'] ?? '');
    $status = $input['status'] ?? 'active';
    $logo = trim($input['logo'] ?? '');
    
    // بيانات المستخدم الافتراضي
    $adminName = trim($input['adminName'] ?? '');
    $adminEmail = trim($input['adminEmail'] ?? '');
    $adminPassword = trim($input['adminPassword'] ?? '123456');
    $adminRole = $input['adminRole'] ?? 'supervisor';
    
    if (empty($name) || empty($code) || empty($manager)) {
        echo json_encode(['success' => false, 'message' => 'جميع الحقول المطلوبة يجب ملؤها']);
        return;
    }
    
    // التحقق من عدم تكرار الكود
    $stmt = $mysqli->prepare("SELECT id FROM branches WHERE code = ?");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => 'كود الفرع موجود مسبقاً']);
        return;
    }
    
    // التحقق من عدم تكرار البريد الإلكتروني إذا تم إدخاله
    if (!empty($adminEmail)) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param('s', $adminEmail);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني موجود مسبقاً']);
            return;
        }
    }
    
    // بدء المعاملة
    $mysqli->begin_transaction();
    
    try {
        // إضافة الفرع
        $stmt = $mysqli->prepare("
            INSERT INTO branches (name, code, address, manager, status, logo, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('ssssss', $name, $code, $address, $manager, $status, $logo);
        
        if (!$stmt->execute()) {
            throw new Exception('فشل في إنشاء الفرع');
        }
        
        $branchId = $mysqli->insert_id;
        
        // إضافة المستخدم الافتراضي إذا تم إدخال بياناته
        if (!empty($adminName) && !empty($adminEmail)) {
            // تشفير كلمة المرور
            $hashedAdminPassword = password_hash($adminPassword, PASSWORD_DEFAULT);
            
            $stmt = $mysqli->prepare("
                INSERT INTO users (name, email, password, role, branch_id, status) 
                VALUES (?, ?, ?, ?, ?, 'active')
            ");
            $stmt->bind_param('ssssi', $adminName, $adminEmail, $hashedAdminPassword, $adminRole, $branchId);
            
            if (!$stmt->execute()) {
                throw new Exception('فشل في إنشاء المستخدم');
            }
        }
        
        // تأكيد المعاملة
        $mysqli->commit();
        
        // جلب الفرع المُنشأ
        $stmt = $mysqli->prepare("
            SELECT 
                b.*,
                (SELECT COUNT(*) FROM users WHERE branch_id = b.id AND role = 'teacher') as teachers_count,
                (
                    SELECT COUNT(DISTINCT s.id)
                    FROM students s
                    LEFT JOIN groups g ON s.group_id = g.id
                    LEFT JOIN users t ON s.teacher_id = t.id
                    WHERE s.branch_id = b.id
                       OR g.branch_id = b.id
                       OR t.branch_id = b.id
                ) as students_count,
                (SELECT COUNT(*) FROM groups WHERE branch_id = b.id) as groups_count
            FROM branches b 
            WHERE b.id = ?
        ");
        $stmt->bind_param('i', $branchId);
        $stmt->execute();
        $result = $stmt->get_result();
        $branch = $result->fetch_assoc();
        
        $result = [
            'id' => $branch['id'],
            'name' => $branch['name'],
            'code' => $branch['code'],
            'address' => $branch['address'],
            'manager' => $branch['manager'],
            'status' => $branch['status'],
            'logo' => $branch['logo'] ?? '',
            'createdAt' => $branch['created_at'],
            'teachersCount' => 0,
            'studentsCount' => 0,
            'groupsCount' => 0
        ];
        
        echo json_encode(['success' => true, 'data' => $result]);
    } catch (Exception $e) {
        // التراجع عن المعاملة في حالة الخطأ
        $mysqli->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function updateBranch($mysqli, $input) {
    $id = $input['id'] ?? '';
    $name = trim($input['name'] ?? '');
    $code = trim($input['code'] ?? '');
    $address = trim($input['address'] ?? '');
    $manager = trim($input['manager'] ?? '');
    $status = $input['status'] ?? 'active';
    $logo = trim($input['logo'] ?? '');
    // بيانات المستخدم المسؤول (اختيارية في التعديل)
    $adminName = trim($input['adminName'] ?? '');
    $adminEmail = trim($input['adminEmail'] ?? '');
    $adminPassword = trim($input['adminPassword'] ?? '');
    $adminRoleInput = $input['adminRole'] ?? null;
    
    if (empty($id) || empty($name) || empty($code) || empty($manager)) {
        echo json_encode(['success' => false, 'message' => 'جميع الحقول المطلوبة يجب ملؤها']);
        return;
    }
    
    // التحقق من عدم تكرار الكود (باستثناء الفرع الحالي)
    $stmt = $mysqli->prepare("SELECT id FROM branches WHERE code = ? AND id != ?");
    $stmt->bind_param('si', $code, $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => 'كود الفرع موجود مسبقاً']);
        return;
    }
    
    // لو تم تزويد بيانات مستخدم مسؤول، نقوم بتحديث/إنشاء المستخدم مع التأكد من عدم تكرار البريد
    $branchId = (int)$id;
    $shouldHandleAdminUser = ($adminName !== '' || $adminEmail !== '' || $adminPassword !== '' || $adminRoleInput !== null);

    if ($shouldHandleAdminUser) {
        // جلب المستخدم الحالي المسؤول عن الفرع (أقرب Admin ثم Supervisor)
        $stmtUser = $mysqli->prepare("SELECT id, name, email, role FROM users WHERE branch_id = ? ORDER BY FIELD(role, 'admin', 'supervisor') DESC, id DESC LIMIT 1");
        $stmtUser->bind_param('i', $branchId);
        $stmtUser->execute();
        $userRes = $stmtUser->get_result();
        $existingUser = $userRes->fetch_assoc();
        $stmtUser->close();

        $existingUserId = $existingUser ? (int)$existingUser['id'] : 0;

        // التحقق من عدم تكرار البريد الإلكتروني لمستخدم آخر
        if ($adminEmail !== '') {
            $stmtCheck = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmtCheck->bind_param('si', $adminEmail, $existingUserId);
            $stmtCheck->execute();
            $checkRes = $stmtCheck->get_result();
            if ($checkRes->fetch_assoc()) {
                echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني موجود مسبقاً لمستخدم آخر']);
                return;
            }
            $stmtCheck->close();
        }

        // تحديد القيم النهائية للاسم/البريد/الدور
        $finalName = $adminName !== '' ? $adminName : ($existingUser['name'] ?? '');
        $finalEmail = $adminEmail !== '' ? $adminEmail : ($existingUser['email'] ?? '');
        $finalRole = in_array($adminRoleInput, ['admin', 'supervisor'])
            ? $adminRoleInput
            : ($existingUser['role'] ?? 'supervisor');

        // لو يوجد مستخدم حالي للفرع
        if ($existingUserId > 0) {
            if ($adminPassword !== '') {
                // تحديث مع كلمة مرور جديدة - تشفير كلمة المرور
                $hashedAdminPassword = password_hash($adminPassword, PASSWORD_DEFAULT);
                $stmtUpdateUser = $mysqli->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ?, branch_id = ? WHERE id = ?");
                $stmtUpdateUser->bind_param('ssssii', $finalName, $finalEmail, $hashedAdminPassword, $finalRole, $branchId, $existingUserId);
            } else {
                // تحديث بدون تغيير كلمة المرور
                $stmtUpdateUser = $mysqli->prepare("UPDATE users SET name = ?, email = ?, role = ?, branch_id = ? WHERE id = ?");
                $stmtUpdateUser->bind_param('sssii', $finalName, $finalEmail, $finalRole, $branchId, $existingUserId);
            }
            $stmtUpdateUser->execute();
            $stmtUpdateUser->close();
        } else {
            // لا يوجد مستخدم مسؤول سابق، إنشاء مستخدم جديد إذا توفرت بيانات أساسية
            if ($finalName !== '' && $finalEmail !== '') {
                $passwordToUse = $adminPassword !== '' ? $adminPassword : '123456';
                // تشفير كلمة المرور
                $hashedPasswordToUse = password_hash($passwordToUse, PASSWORD_DEFAULT);
                $roleToUse = in_array($adminRoleInput, ['admin', 'supervisor']) ? $adminRoleInput : 'supervisor';
                $stmtCreateUser = $mysqli->prepare("INSERT INTO users (name, email, password, role, branch_id, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $stmtCreateUser->bind_param('ssssi', $finalName, $finalEmail, $hashedPasswordToUse, $roleToUse, $branchId);
                $stmtCreateUser->execute();
                $stmtCreateUser->close();
            }
        }
    }

    $stmt = $mysqli->prepare("
        UPDATE branches 
        SET name = ?, code = ?, address = ?, manager = ?, status = ?, logo = ?
        WHERE id = ?
    ");
    $stmt->bind_param('ssssssi', $name, $code, $address, $manager, $status, $logo, $id);
    
    if ($stmt->execute()) {
        // جلب الفرع المُحدث مع معلومات المستخدم المسؤول
        $stmt = $mysqli->prepare("
            SELECT 
                b.*,
                (SELECT COUNT(*) FROM users WHERE branch_id = b.id AND role = 'teacher') as teachers_count,
                (
                    SELECT COUNT(DISTINCT s.id)
                    FROM students s
                    LEFT JOIN groups g ON s.group_id = g.id
                    LEFT JOIN users t ON s.teacher_id = t.id
                    WHERE s.branch_id = b.id
                       OR g.branch_id = b.id
                       OR t.branch_id = b.id
                ) as students_count,
                (SELECT COUNT(*) FROM groups WHERE branch_id = b.id) as groups_count,
                (
                    SELECT u.name
                    FROM users u
                    WHERE u.branch_id = b.id
                    ORDER BY FIELD(u.role, 'admin', 'supervisor') DESC, u.id ASC
                    LIMIT 1
                ) as admin_name,
                (
                    SELECT u.email
                    FROM users u
                    WHERE u.branch_id = b.id
                    ORDER BY FIELD(u.role, 'admin', 'supervisor') DESC, u.id ASC
                    LIMIT 1
                ) as admin_email
            FROM branches b 
            WHERE b.id = ?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $branch = $result->fetch_assoc();
        
        $result = [
            'id' => $branch['id'],
            'name' => $branch['name'],
            'code' => $branch['code'],
            'address' => $branch['address'],
            'manager' => $branch['manager'],
            'status' => $branch['status'],
            'logo' => $branch['logo'] ?? '',
            'createdAt' => $branch['created_at'],
            'teachersCount' => (int)$branch['teachers_count'],
            'studentsCount' => (int)$branch['students_count'],
            'groupsCount' => (int)$branch['groups_count'],
            'adminName' => $branch['admin_name'] ?? null,
            'adminEmail' => $branch['admin_email'] ?? null,
        ];
        
        echo json_encode(['success' => true, 'data' => $result]);
    } else {
        echo json_encode(['success' => false, 'message' => 'فشل في تحديث الفرع']);
    }
}

function deleteBranch($mysqli, $input) {
    $id = $input['id'] ?? '';
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'معرف الفرع مطلوب']);
        return;
    }
    
    // حذف كل البيانات المرتبطة بالفرع داخل معاملة واحدة
    $branchId = (int)$id;
    $mysqli->begin_transaction();

    try {
        // حذف الطلاب المرتبطين بالفرع مباشرة
        $stmt = $mysqli->prepare("DELETE FROM students WHERE branch_id = ?");
        $stmt->bind_param('i', $branchId);
        $stmt->execute();

        // حذف المجموعات التابعة للفرع
        $stmt = $mysqli->prepare("DELETE FROM groups WHERE branch_id = ?");
        $stmt->bind_param('i', $branchId);
        $stmt->execute();

        // حذف المستخدمين (معلمين/مشرفين/إدارة) التابعين للفرع
        $stmt = $mysqli->prepare("DELETE FROM users WHERE branch_id = ?");
        $stmt->bind_param('i', $branchId);
        $stmt->execute();

        // في النهاية حذف الفرع نفسه
        $stmt = $mysqli->prepare("DELETE FROM branches WHERE id = ?");
        $stmt->bind_param('i', $branchId);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            $mysqli->rollback();
            echo json_encode(['success' => false, 'message' => 'لم يتم العثور على الفرع']);
            return;
        }

        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'تم حذف الفرع وجميع البيانات المرتبطة به بنجاح']);
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'message' => 'فشل في حذف الفرع: ' . $e->getMessage()]);
    }
}

function toggleBranchStatus($mysqli, $input) {
    $id = $input['id'] ?? '';
    $status = $input['status'] ?? '';
    
    if (empty($id) || empty($status)) {
        echo json_encode(['success' => false, 'message' => 'معرف الفرع والحالة مطلوبان']);
        return;
    }
    
    $stmt = $mysqli->prepare("UPDATE branches SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'تم تحديث حالة الفرع بنجاح']);
    } else {
        echo json_encode(['success' => false, 'message' => 'فشل في تحديث حالة الفرع']);
    }
}

function ensureBranchesSchema($mysqli) {
    $res = $mysqli->query("SHOW COLUMNS FROM branches LIKE 'logo'");
    if ($res && $res->num_rows == 0) {
        $mysqli->query("ALTER TABLE branches ADD COLUMN logo LONGTEXT NULL AFTER status");
    }
}
?>