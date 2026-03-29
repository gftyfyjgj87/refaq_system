<?php
/**
 * إضافة مستخدم جديد لفرع معين
 */

require_once 'db.php';

// استقبال البيانات من POST أو استخدام قيم افتراضية للاختبار
$name = $_POST['name'] ?? 'مستخدم تجريبي';
$email = $_POST['email'] ?? 'test@refaq.com';
$password = $_POST['password'] ?? '123456';
$role = $_POST['role'] ?? 'supervisor'; // supervisor, teacher, admin
$branchId = $_POST['branch_id'] ?? 2; // الفرع الثاني
$teacherType = $_POST['teacher_type'] ?? null; // both, quran, educational (للمعلمين فقط)

echo "🚀 إضافة مستخدم جديد...\n\n";

try {
    // التحقق من وجود الفرع
    $stmt = $mysqli->prepare("SELECT * FROM branches WHERE id = ?");
    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $result = $stmt->get_result();
    $branch = $result->fetch_assoc();
    
    if (!$branch) {
        throw new Exception("الفرع غير موجود (ID: $branchId)");
    }
    
    echo "📋 بيانات المستخدم:\n";
    echo "  - الاسم: $name\n";
    echo "  - البريد: $email\n";
    echo "  - كلمة المرور: $password\n";
    echo "  - الدور: $role\n";
    echo "  - الفرع: {$branch['name']} ({$branch['code']})\n";
    if ($teacherType) {
        echo "  - نوع المعلم: $teacherType\n";
    }
    echo "\n";
    
    // التحقق من عدم تكرار البريد الإلكتروني
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->fetch_assoc()) {
        throw new Exception("البريد الإلكتروني موجود مسبقاً: $email");
    }
    
    // تشفير كلمة المرور
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // إضافة المستخدم
    if ($role === 'teacher' && $teacherType) {
        $stmt = $mysqli->prepare("
            INSERT INTO users (name, email, password, role, branch_id, status, teacher_type) 
            VALUES (?, ?, ?, ?, ?, 'active', ?)
        ");
        $stmt->bind_param('ssssis', $name, $email, $hashedPassword, $role, $branchId, $teacherType);
    } else {
        $stmt = $mysqli->prepare("
            INSERT INTO users (name, email, password, role, branch_id, status) 
            VALUES (?, ?, ?, ?, ?, 'active')
        ");
        $stmt->bind_param('ssssi', $name, $email, $hashedPassword, $role, $branchId);
    }
    
    if ($stmt->execute()) {
        $userId = $mysqli->insert_id;
        
        echo "✅ تم إضافة المستخدم بنجاح!\n\n";
        echo "📋 بيانات تسجيل الدخول:\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "🔹 البريد الإلكتروني: $email\n";
        echo "🔹 كلمة المرور: $password\n";
        echo "🔹 الدور: $role\n";
        echo "🔹 الفرع: {$branch['name']}\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        // عرض جميع مستخدمي الفرع
        echo "👥 جميع مستخدمي الفرع:\n";
        $stmt = $mysqli->prepare("
            SELECT id, name, email, role, status 
            FROM users 
            WHERE branch_id = ? 
            ORDER BY role, name
        ");
        $stmt->bind_param('i', $branchId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($user = $result->fetch_assoc()) {
            $roleAr = [
                'admin' => 'إدارة',
                'supervisor' => 'مشرف',
                'teacher' => 'معلم'
            ];
            echo "  • {$user['name']} ({$user['email']}) - {$roleAr[$user['role']]}\n";
        }
        
    } else {
        throw new Exception("فشل في إضافة المستخدم: " . $mysqli->error);
    }
    
} catch (Exception $e) {
    echo "❌ خطأ: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n🎉 تم بنجاح!\n";
?>
