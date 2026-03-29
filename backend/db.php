<?php

// ملف اتصال قاعدة البيانات ودالة json_response

// إعدادات XAMPP
$dbHost = '127.0.0.1';  // استخدام IP بدلاً من localhost لتجنب مشاكل IPv6
$dbPort = 3306;          // المنفذ الافتراضي لـ MySQL في XAMPP
$dbUser = 'root';
$dbPass = '';
$dbName = 'refaq';

// الاتصال بقاعدة البيانات
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);

// التحقق من الاتصال
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode([
        'error' => 'فشل الاتصال بقاعدة البيانات',
        'details' => $mysqli->connect_error,
        'errno' => $mysqli->connect_errno
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تعيين الترميز
$mysqli->set_charset('utf8mb4');

// دالة الاستجابة بصيغة JSON
if (!function_exists('json_response')) {
    function json_response($data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

