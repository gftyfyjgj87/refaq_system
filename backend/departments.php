<?php

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// إنشاء جدول الأقسام
$createTableSql = "CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL COMMENT 'اسم القسم',
  `type` ENUM('boys','girls_and_kids') NOT NULL COMMENT 'نوع القسم',
  `description` TEXT NULL COMMENT 'وصف القسم',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$mysqli->query($createTableSql);

// إضافة الأقسام الافتراضية إذا لم تكن موجودة
$checkDepts = $mysqli->query("SELECT COUNT(*) as count FROM departments");
if ($checkDepts) {
    $row = $checkDepts->fetch_assoc();
    if ($row['count'] == 0) {
        $mysqli->query("INSERT INTO departments (name, type, description) VALUES 
            ('قسم البنين', 'boys', 'قسم خاص بالطلاب الذكور - معلمين فقط'),
            ('قسم البنات والأطفال', 'girls_and_kids', 'قسم خاص بالطالبات والأطفال حتى 8 سنوات - معلمات فقط')
        ");
    }
}

function map_department(array $row): array {
    return [
        'id' => (string)$row['id'],
        'name' => $row['name'],
        'type' => $row['type'],
        'description' => $row['description'] ?? '',
        'isActive' => (bool)$row['is_active'],
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'],
    ];
}

// GET: جلب جميع الأقسام
if ($method === 'GET' && !$action) {
    $result = $mysqli->query("SELECT * FROM departments ORDER BY id ASC");
    if (!$result) {
        json_response(['success' => false, 'message' => 'فشل في جلب الأقسام'], 500);
    }
    
    $departments = [];
    while ($row = $result->fetch_assoc()) {
        $departments[] = map_department($row);
    }
    
    json_response(['success' => true, 'data' => $departments]);
}

// GET: جلب قسم واحد
if ($method === 'GET' && $action === 'get') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف القسم مطلوب'], 400);
    }
    
    $result = $mysqli->query("SELECT * FROM departments WHERE id = $id");
    if (!$result || $result->num_rows === 0) {
        json_response(['success' => false, 'message' => 'القسم غير موجود'], 404);
    }
    
    $department = map_department($result->fetch_assoc());
    json_response(['success' => true, 'data' => $department]);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

// POST: إنشاء قسم جديد
if ($method === 'POST' && $action === 'create') {
    $name = trim($input['name'] ?? '');
    $type = trim($input['type'] ?? '');
    $description = trim($input['description'] ?? '');
    
    if ($name === '' || $type === '') {
        json_response(['success' => false, 'message' => 'الاسم والنوع مطلوبان'], 400);
    }
    
    if (!in_array($type, ['boys', 'girls_and_kids'])) {
        json_response(['success' => false, 'message' => 'نوع القسم غير صحيح'], 400);
    }
    
    $stmt = $mysqli->prepare("INSERT INTO departments (name, type, description) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $name, $type, $description);
    
    if ($stmt->execute()) {
        $newId = $mysqli->insert_id;
        $result = $mysqli->query("SELECT * FROM departments WHERE id = $newId");
        $department = map_department($result->fetch_assoc());
        json_response(['success' => true, 'message' => 'تم إنشاء القسم بنجاح', 'data' => $department]);
    } else {
        json_response(['success' => false, 'message' => 'فشل في إنشاء القسم'], 500);
    }
}

// PUT: تحديث قسم
if ($method === 'POST' && $action === 'update') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $isActive = isset($input['isActive']) ? (int)$input['isActive'] : 1;
    
    if ($id <= 0 || $name === '') {
        json_response(['success' => false, 'message' => 'المعرف والاسم مطلوبان'], 400);
    }
    
    $stmt = $mysqli->prepare("UPDATE departments SET name = ?, description = ?, is_active = ? WHERE id = ?");
    $stmt->bind_param('ssii', $name, $description, $isActive, $id);
    
    if ($stmt->execute()) {
        $result = $mysqli->query("SELECT * FROM departments WHERE id = $id");
        $department = map_department($result->fetch_assoc());
        json_response(['success' => true, 'message' => 'تم تحديث القسم بنجاح', 'data' => $department]);
    } else {
        json_response(['success' => false, 'message' => 'فشل في تحديث القسم'], 500);
    }
}

// DELETE: حذف قسم
if ($method === 'POST' && $action === 'delete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف القسم مطلوب'], 400);
    }
    
    // التحقق من عدم وجود معلمين مرتبطين بالقسم
    $checkTeachers = $mysqli->query("SELECT COUNT(*) as count FROM teachers WHERE department_id = $id");
    if ($checkTeachers) {
        $row = $checkTeachers->fetch_assoc();
        if ($row['count'] > 0) {
            json_response(['success' => false, 'message' => 'لا يمكن حذف القسم لوجود معلمين مرتبطين به'], 400);
        }
    }
    
    if ($mysqli->query("DELETE FROM departments WHERE id = $id")) {
        json_response(['success' => true, 'message' => 'تم حذف القسم بنجاح']);
    } else {
        json_response(['success' => false, 'message' => 'فشل في حذف القسم'], 500);
    }
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
