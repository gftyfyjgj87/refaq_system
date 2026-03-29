<?php
/**
 * تشغيل migration للفروع المتعددة
 */

require_once 'db.php';

echo "🚀 بدء تشغيل migration للفروع المتعددة...\n\n";

// قراءة ملف SQL
$sqlFile = __DIR__ . '/multi_branch_migration.sql';
if (!file_exists($sqlFile)) {
    die("❌ ملف migration غير موجود: $sqlFile\n");
}

$sql = file_get_contents($sqlFile);

// تقسيم الاستعلامات
$queries = explode(';', $sql);

$successCount = 0;
$errorCount = 0;
$errors = [];

foreach ($queries as $query) {
    $query = trim($query);
    
    // تجاهل التعليقات والأسطر الفارغة
    if (empty($query) || 
        strpos($query, '--') === 0 || 
        strpos($query, '/*') === 0 ||
        strtoupper(substr($query, 0, 9)) === 'DELIMITER') {
        continue;
    }
    
    // تنفيذ الاستعلام
    if ($mysqli->query($query)) {
        $successCount++;
        echo "✅ تم تنفيذ استعلام بنجاح\n";
    } else {
        $errorCount++;
        $error = $mysqli->error;
        
        // تجاهل بعض الأخطاء المتوقعة
        if (strpos($error, 'Duplicate column') !== false ||
            strpos($error, 'already exists') !== false ||
            strpos($error, 'Duplicate key') !== false) {
            echo "⚠️  تحذير (متوقع): $error\n";
        } else {
            echo "❌ خطأ: $error\n";
            $errors[] = $error;
        }
    }
}

echo "\n📊 النتائج:\n";
echo "- استعلامات ناجحة: $successCount\n";
echo "- أخطاء: $errorCount\n";

if (!empty($errors)) {
    echo "\n⚠️  الأخطاء:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
}

// التحقق من نجاح العملية
$result = $mysqli->query("SHOW TABLES LIKE 'branches'");
if ($result && $result->num_rows > 0) {
    echo "\n✅ تم إنشاء جدول الفروع بنجاح!\n";
    
    // عرض الفروع الموجودة
    $result = $mysqli->query("SELECT * FROM branches");
    if ($result) {
        echo "\n📋 الفروع الموجودة:\n";
        while ($row = $result->fetch_assoc()) {
            echo "  - {$row['name']} ({$row['code']}) - {$row['status']}\n";
        }
    }
} else {
    echo "\n❌ فشل في إنشاء جدول الفروع\n";
}

echo "\n✨ انتهى تشغيل migration\n";
?>
