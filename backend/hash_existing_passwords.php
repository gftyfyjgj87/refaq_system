<?php
/**
 * سكريبت لتشفير كلمات المرور الموجودة في قاعدة البيانات
 * 
 * تحذير: هذا السكريبت يجب تشغيله مرة واحدة فقط بعد تحديث نظام المصادقة
 * 
 * الاستخدام:
 * 1. افتح المتصفح على: http://localhost/refaq/backend/hash_existing_passwords.php
 * 2. أو شغله من سطر الأوامر: php hash_existing_passwords.php
 */

require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html><html dir='rtl'><head><meta charset='utf-8'><title>تشفير كلمات المرور</title>";
echo "<style>body{font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;}";
echo ".success{color:green;}.error{color:red;}.info{color:blue;}</style></head><body>";

echo "<h1>🔐 تشفير كلمات المرور الموجودة</h1>";
echo "<hr>";

try {
    // جلب جميع المستخدمين
    $result = $mysqli->query("SELECT id, name, email, password FROM users");
    
    if (!$result) {
        throw new Exception("فشل في جلب المستخدمين: " . $mysqli->error);
    }
    
    $totalUsers = 0;
    $updatedUsers = 0;
    $skippedUsers = 0;
    
    echo "<h2>📊 بدء عملية التشفير...</h2>";
    echo "<ul>";
    
    while ($user = $result->fetch_assoc()) {
        $totalUsers++;
        $userId = (int)$user['id'];
        $userName = $user['name'];
        $userEmail = $user['email'];
        $currentPassword = $user['password'];
        
        // التحقق إذا كانت كلمة المرور مشفرة بالفعل
        // كلمات المرور المشفرة بـ password_hash تبدأ بـ $2y$ أو $2a$ أو $2b$
        if (preg_match('/^\$2[ayb]\$.{56}$/', $currentPassword)) {
            echo "<li class='info'>⏭️ تم تخطي: <strong>$userName</strong> ($userEmail) - كلمة المرور مشفرة بالفعل</li>";
            $skippedUsers++;
            continue;
        }
        
        // تشفير كلمة المرور
        $hashedPassword = password_hash($currentPassword, PASSWORD_DEFAULT);
        
        // تحديث قاعدة البيانات
        $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
        if (!$stmt) {
            echo "<li class='error'>❌ فشل في تحديث: <strong>$userName</strong> ($userEmail) - " . $mysqli->error . "</li>";
            continue;
        }
        
        $stmt->bind_param('si', $hashedPassword, $userId);
        
        if ($stmt->execute()) {
            echo "<li class='success'>✅ تم تشفير: <strong>$userName</strong> ($userEmail)</li>";
            $updatedUsers++;
        } else {
            echo "<li class='error'>❌ فشل في تحديث: <strong>$userName</strong> ($userEmail) - " . $stmt->error . "</li>";
        }
        
        $stmt->close();
    }
    
    echo "</ul>";
    echo "<hr>";
    echo "<h2>📈 النتائج النهائية:</h2>";
    echo "<ul>";
    echo "<li><strong>إجمالي المستخدمين:</strong> $totalUsers</li>";
    echo "<li class='success'><strong>تم التشفير:</strong> $updatedUsers</li>";
    echo "<li class='info'><strong>تم التخطي (مشفرة مسبقاً):</strong> $skippedUsers</li>";
    echo "</ul>";
    
    if ($updatedUsers > 0) {
        echo "<div class='success'>";
        echo "<h3>✅ تم تشفير كلمات المرور بنجاح!</h3>";
        echo "<p>يمكنك الآن حذف هذا الملف (hash_existing_passwords.php) لأسباب أمنية.</p>";
        echo "</div>";
    } else {
        echo "<div class='info'>";
        echo "<h3>ℹ️ جميع كلمات المرور مشفرة بالفعل</h3>";
        echo "<p>لا حاجة لأي إجراء إضافي.</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h3>❌ حدث خطأ:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "</body></html>";
