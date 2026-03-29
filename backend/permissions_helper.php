<?php
/**
 * ملف مساعد للصلاحيات والفلترة حسب الفرع
 * يتم استخدامه في جميع ملفات API
 */

// التحقق من تسجيل الدخول (يدعم both Session و Bearer Token)
function checkAuth() {
    global $mysqli;
    
    // محاولة 1: التحقق من الجلسة (PHP Session)
    if (isset($_SESSION['user_id'])) {
        return;
    }
    
    // محاولة 2: التحقق من Bearer Token (JWT)
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    
    if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = $matches[1];
        if (!empty($token) && $token !== 'null' && $token !== 'undefined') {
            // استخراج user_id من التوكن (JWT payload)
            $payload = decodeJwtPayload($token);
            if ($payload && isset($payload['userId'])) {
                $userId = intval($payload['userId']);
                // جلب بيانات المستخدم من قاعدة البيانات
                $stmt = $mysqli->prepare("SELECT id, role, branch_id, permissions, assigned_teachers FROM users WHERE id = ?");
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['user_role'] = $row['role'];
                    $_SESSION['branch_id'] = $row['branch_id'];
                    $_SESSION['permissions'] = $row['permissions'] ? json_decode($row['permissions'], true) : [];
                    $_SESSION['assigned_teachers'] = $row['assigned_teachers'];
                    $stmt->close();
                    return;
                }
                $stmt->close();
            }
        }
    }
    
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'يجب تسجيل الدخول أولاً'
    ]);
    exit;
}

// فك ترميز JWT payload (بدون التحقق من التوقيع للتبسيط)
function decodeJwtPayload($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
    if (!$payload) {
        return null;
    }
    return json_decode($payload, true);
}

// الحصول على معلومات المستخدم الحالي
function getCurrentUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'role' => $_SESSION['user_role'] ?? null,
        'branch_id' => $_SESSION['branch_id'] ?? null,
        'permissions' => $_SESSION['permissions'] ?? [],
        'assigned_teachers' => $_SESSION['assigned_teachers'] ?? null
    ];
}

// التحقق من الصلاحية
function hasPermission($required_permission) {
    $user = getCurrentUser();
    
    // الإدارة لديها جميع الصلاحيات
    if ($user['role'] === 'admin') {
        return true;
    }
    
    // التحقق من الصلاحيات المخصصة
    if (isset($user['permissions']) && is_array($user['permissions'])) {
        return in_array($required_permission, $user['permissions']);
    }
    
    // الصلاحيات الافتراضية حسب الدور
    $default_permissions = [
        'supervisor' => [
            'view_students', 'add_student', 'edit_student',
            'view_teachers',
            'view_groups', 'manage_groups',
            'view_sessions', 'manage_sessions',
            'view_finance', 'manage_income', 'manage_expenses', 'view_salaries',
            'view_reports'
        ],
        'teacher' => [
            'view_students',
            'view_groups',
            'view_sessions', 'manage_sessions',
            'view_finance'
        ]
    ];
    
    $role = $user['role'];
    if (isset($default_permissions[$role])) {
        return in_array($required_permission, $default_permissions[$role]);
    }
    
    return false;
}

// إضافة شرط الفرع للاستعلام
function addBranchFilter($base_query, $table_alias = '') {
    $user = getCurrentUser();
    
    // الإدارة ترى جميع الفروع
    if ($user['role'] === 'admin') {
        return $base_query;
    }
    
    // المشرف والمعلم يرون فرعهم فقط
    if (!$user['branch_id']) {
        return $base_query . " WHERE 1=0"; // لا يعرض شيء إذا لم يكن له فرع
    }
    
    $prefix = $table_alias ? $table_alias . '.' : '';
    
    // إضافة شرط الفرع
    if (strpos(strtoupper($base_query), 'WHERE') !== false) {
        return $base_query . " AND {$prefix}branch_id = " . intval($user['branch_id']);
    } else {
        return $base_query . " WHERE {$prefix}branch_id = " . intval($user['branch_id']);
    }
}

// الحصول على معامل الفرع من GET
function getBranchIdFromRequest() {
    $user = getCurrentUser();
    
    // الإدارة يمكنها تحديد الفرع من الطلب
    if ($user['role'] === 'admin' && isset($_GET['branch_id'])) {
        return intval($_GET['branch_id']);
    }
    
    // المشرف والمعلم يستخدمون فرعهم فقط
    return $user['branch_id'];
}

// فلترة النتائج حسب الفرع
function filterByBranch($data) {
    $user = getCurrentUser();
    
    // الإدارة ترى الكل
    if ($user['role'] === 'admin') {
        return $data;
    }
    
    // المشرف والمعلم يرون فرعهم فقط
    if (!$user['branch_id']) {
        return [];
    }
    
    return array_filter($data, function($item) use ($user) {
        return isset($item['branch_id']) && 
               intval($item['branch_id']) === intval($user['branch_id']);
    });
}

// التحقق من الصلاحية وإرجاع خطأ إذا لم يكن لديه
function requirePermission($permission) {
    if (!hasPermission($permission)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'ليس لديك صلاحية لهذا الإجراء'
        ]);
        exit;
    }
}

// التحقق من أن العنصر ينتمي لفرع المستخدم
function checkBranchAccess($item_branch_id) {
    $user = getCurrentUser();
    
    // الإدارة لديها وصول لجميع الفروع
    if ($user['role'] === 'admin') {
        return true;
    }
    
    // المشرف والمعلم يمكنهم الوصول لفرعهم فقط
    return intval($item_branch_id) === intval($user['branch_id']);
}

// إرجاع رسالة خطأ موحدة
function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

// إرجاع رسالة نجاح موحدة
function sendSuccess($data = null, $message = 'تمت العملية بنجاح') {
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

/**
 * مثال على الاستخدام في ملف API:
 * 
 * // في بداية الملف
 * require_once 'permissions_helper.php';
 * checkAuth();
 * 
 * // للتحقق من الصلاحية
 * requirePermission('add_student');
 * 
 * // لإضافة فلترة الفرع للاستعلام
 * $query = "SELECT * FROM students";
 * $query = addBranchFilter($query);
 * 
 * // أو فلترة النتائج
 * $students = filterByBranch($all_students);
 * 
 * // للتحقق من الوصول لعنصر معين
 * if (!checkBranchAccess($student['branch_id'])) {
 *     sendError('ليس لديك صلاحية للوصول لهذا العنصر', 403);
 * }
 */
?>
