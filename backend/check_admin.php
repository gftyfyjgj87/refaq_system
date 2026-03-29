<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db.php';

try {
    // التحقق من وجود جدول الفروع
    $stmt = $pdo->query("SHOW TABLES LIKE 'branches'");
    $branchesTableExists = $stmt->rowCount() > 0;
    
    // التحقق من وجود عمود branch_id في جدول المستخدمين
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'branch_id'");
    $branchIdColumnExists = $stmt->rowCount() > 0;
    
    // جلب جميع المستخدمين admin
    $stmt = $pdo->query("SELECT id, name, email, role, branch_id FROM users WHERE role = 'admin'");
    $adminUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // جلب جميع الفروع
    $branches = [];
    if ($branchesTableExists) {
        $stmt = $pdo->query("SELECT * FROM branches");
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'branchesTableExists' => $branchesTableExists,
            'branchIdColumnExists' => $branchIdColumnExists,
            'adminUsers' => $adminUsers,
            'branches' => $branches,
            'totalUsers' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'message' => 'تم جلب البيانات بنجاح'
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>