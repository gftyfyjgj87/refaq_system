<?php

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Bootstrap table
$createSql = "CREATE TABLE IF NOT EXISTS `alerts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('payment','report','attendance','subscription','system') NOT NULL DEFAULT 'system',
  `severity` ENUM('low','medium','high') NOT NULL DEFAULT 'low',
  `message` VARCHAR(255) NOT NULL,
  `target_role` SET('admin','supervisor','teacher','student') NOT NULL DEFAULT 'admin',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$mysqli->query($createSql);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

if ($method === 'GET') {
    $role = $_GET['role'] ?? null;
    $where = '';
    if ($role) {
        $role = $mysqli->real_escape_string($role);
        $where = "WHERE FIND_IN_SET('$role', REPLACE(target_role, ' ', ''))";
    }
    $sql = "SELECT id, type, severity, message, target_role, created_at, is_read FROM alerts $where ORDER BY is_read ASC, created_at DESC";
    $res = $mysqli->query($sql);
    if (!$res) {
        json_response(['success' => false, 'message' => 'فشل في جلب التنبيهات', 'details' => $mysqli->error], 500);
    }
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (string)$r['id'],
            'type' => $r['type'],
            'severity' => $r['severity'],
            'message' => $r['message'],
            'targetRole' => explode(',', str_replace(' ', '', $r['target_role'])),
            'createdAt' => $r['created_at'],
            'read' => (bool)$r['is_read'],
        ];
    }
    json_response(['success' => true, 'data' => $rows]);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { $input = $_POST; }

if ($method === 'POST' && $action === 'mark_read') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) { json_response(['success' => false, 'message' => 'معرّف غير صالح'], 400); }
    $stmt = $mysqli->prepare('UPDATE alerts SET is_read = 1 WHERE id = ?');
    if (!$stmt) { json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500); }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) { json_response(['success' => false, 'message' => 'فشل في تحديث التنبيه', 'details' => $stmt->error], 500); }
    json_response(['success' => true]);
}

if ($method === 'POST' && $action === 'mark_all') {
    $role = $input['role'] ?? '';
    if ($role) {
        $role = $mysqli->real_escape_string($role);
        $sql = "UPDATE alerts SET is_read = 1 WHERE FIND_IN_SET('$role', REPLACE(target_role, ' ', ''))";
    } else {
        $sql = "UPDATE alerts SET is_read = 1";
    }
    if (!$mysqli->query($sql)) {
        json_response(['success' => false, 'message' => 'فشل في تحديث التنبيهات', 'details' => $mysqli->error], 500);
    }
    json_response(['success' => true]);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
