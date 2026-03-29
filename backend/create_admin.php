<?php

require_once __DIR__ . '/db.php';

// بيانات الأدمن
$email = 'admin@test.com';
$password = '123456';
$name = 'إدارة';
$role = 'admin';

// تشفير كلمة المرور
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// حذف المستخدم القديم إن وجد
$mysqli->query("DELETE FROM users WHERE email = '$email'");

// إدخال المستخدم الجديد
$stmt = $mysqli->prepare('INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, ?)');
$status = 'active';
$stmt->bind_param('sssss', $name, $email, $hashedPassword, $role, $status);

if ($stmt->execute()) {
    echo "✅ تم إنشاء حساب الأدمن بنجاح!\n\n";
    echo "📧 البريد الإلكتروني: $email\n";
    echo "🔑 كلمة المرور: $password\n";
    echo "👤 الدور: $role\n\n";
    echo "يمكنك الآن تسجيل الدخول باستخدام هذه البيانات.\n";
} else {
    echo "❌ فشل إنشاء الحساب: " . $stmt->error . "\n";
}

$stmt->close();
$mysqli->close();
