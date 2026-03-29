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

// Ensure table exists (lightweight bootstrap)
$createSql = "CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(200) NOT NULL,
  `entity` VARCHAR(200) NOT NULL,
  `entity_type` VARCHAR(50) NULL COMMENT 'نوع الكيان',
  `entity_id` VARCHAR(50) NULL COMMENT 'معرف الكيان',
  `user` VARCHAR(150) NOT NULL,
  `role` ENUM('admin','supervisor','teacher','student') NOT NULL,
  `type` ENUM('create','update','delete','finance','payment','other') NOT NULL DEFAULT 'other',
  `description` TEXT NULL COMMENT 'وصف العملية',
  `details` TEXT NULL COMMENT 'تفاصيل إضافية بصيغة JSON',
  `status` ENUM('pending','approved','rejected') DEFAULT 'approved' COMMENT 'حالة الموافقة',
  `approved_by` VARCHAR(150) NULL COMMENT 'من وافق',
  `approved_at` DATETIME NULL COMMENT 'تاريخ الموافقة',
  `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_type` (`type`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`),
  KEY `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$mysqli->query($createSql);

// تأكد من وجود الأعمدة حتى لو تم إنشاء الجدول قديماً بدونها
$columns = [];
$colRes = $mysqli->query("SHOW COLUMNS FROM `audit_logs`");
if ($colRes) {
    while ($row = $colRes->fetch_assoc()) {
        $columns[strtolower($row['Field'])] = true;
    }
}

if (!isset($columns['entity_type'])) {
    $mysqli->query("ALTER TABLE `audit_logs` ADD COLUMN `entity_type` VARCHAR(50) NULL COMMENT 'نوع الكيان' AFTER `entity`");
}
if (!isset($columns['entity_id'])) {
    $mysqli->query("ALTER TABLE `audit_logs` ADD COLUMN `entity_id` VARCHAR(50) NULL COMMENT 'معرف الكيان' AFTER `entity_type`");
}
if (!isset($columns['description'])) {
    $mysqli->query("ALTER TABLE `audit_logs` ADD COLUMN `description` TEXT NULL COMMENT 'وصف العملية' AFTER `type`");
}
if (!isset($columns['details'])) {
    $mysqli->query("ALTER TABLE `audit_logs` ADD COLUMN `details` TEXT NULL COMMENT 'تفاصيل إضافية بصيغة JSON' AFTER `description`");
}
if (!isset($columns['status'])) {
    $mysqli->query("ALTER TABLE `audit_logs` ADD COLUMN `status` ENUM('pending','approved','rejected') DEFAULT 'approved' COMMENT 'حالة الموافقة' AFTER `details`");
}
if (!isset($columns['approved_by'])) {
    $mysqli->query("ALTER TABLE `audit_logs` ADD COLUMN `approved_by` VARCHAR(150) NULL COMMENT 'من وافق' AFTER `status`");
}
if (!isset($columns['approved_at'])) {
    $mysqli->query("ALTER TABLE `audit_logs` ADD COLUMN `approved_at` DATETIME NULL COMMENT 'تاريخ الموافقة' AFTER `approved_by`");
}

$method = $_SERVER['REQUEST_METHOD'];
$action_param = $_GET['action'] ?? '';

if (($method === 'POST' || $method === 'GET') && $action_param === 'create') {
    $input = $method === 'GET' ? $_GET : json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) { $input = $_POST; }

    $action = trim($input['action'] ?? '');
    $entityType = trim($input['entityType'] ?? '');
    $entityId = trim($input['entityId'] ?? '');
    $description = trim($input['description'] ?? '');
    $details = trim($input['details'] ?? '');
    $status = trim($input['status'] ?? 'approved');
    $user = trim($input['user'] ?? 'System');
    $role = trim($input['role'] ?? 'admin');
    $type = trim($input['type'] ?? 'payment');

    $allowedTypes = ['create','update','delete','finance','payment','other'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'other';
    }
    if ($action === 'payment_added') {
        $type = 'payment';
    }

    if ($action === '' || $description === '') {
        if ($method === 'GET') {
            json_response([
                'success' => true,
                'message' => 'Audit logging skipped',
                'warning' => 'Missing required fields',
            ]);
        }
        json_response(['success' => false, 'message' => 'Missing required fields'], 400);
    }

    $stmt = $mysqli->prepare("INSERT INTO audit_logs (`action`, `entity`, `entity_type`, `entity_id`, `user`, `role`, `type`, `description`, `details`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        // لا نُسقط تدفق الواجهة بسبب سجل المراجعة
        json_response([
            'success' => true,
            'message' => 'Audit logging skipped',
            'warning' => 'Failed to prepare statement',
            'details' => $mysqli->error,
        ]);
    }
    
    $entity = $entityType . ':' . $entityId;
    $stmt->bind_param('ssssssssss', $action, $entity, $entityType, $entityId, $user, $role, $type, $description, $details, $status);
    
    if (!$stmt->execute()) {
        // لا نُسقط تدفق الواجهة بسبب سجل المراجعة
        json_response([
            'success' => true,
            'message' => 'Audit logging skipped',
            'warning' => 'Failed to insert log',
            'details' => $stmt->error,
        ]);
    }
    json_response(['success' => true, 'data' => ['id' => $stmt->insert_id]]);
}

if ($method === 'POST' && $action_param === '') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) { $input = $_POST; }

    $action = trim($input['action'] ?? '');
    $entity = trim($input['entity'] ?? '');
    $user   = trim($input['user'] ?? '');
    $role   = trim($input['role'] ?? '');
    $type   = trim($input['type'] ?? 'other');
    $ts     = trim($input['timestamp'] ?? '');

    if ($action === '' || $entity === '' || $user === '' || $role === '') {
        json_response(['success' => false, 'message' => 'Missing required fields'], 400);
    }

    $stmt = $mysqli->prepare("INSERT INTO audit_logs (`action`,`entity`,`user`,`role`,`type`,`timestamp`) VALUES (?,?,?,?,?,IF(?='', NOW(), ?))");
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'Failed to prepare statement', 'details' => $mysqli->error], 500);
    }
    $stmt->bind_param('sssssss', $action, $entity, $user, $role, $type, $ts, $ts);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'Failed to insert log', 'details' => $stmt->error], 500);
    }
    json_response(['success' => true, 'data' => ['id' => $stmt->insert_id]]);
}

if ($method === 'POST' && $action_param === 'approve') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    $approved_by = trim($input['approved_by'] ?? '');
    
    if ($id <= 0 || $approved_by === '') {
        json_response(['success' => false, 'message' => 'Invalid input'], 400);
    }
    
    $stmt = $mysqli->prepare("UPDATE audit_logs SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'Failed to prepare statement'], 500);
    }
    
    $stmt->bind_param('si', $approved_by, $id);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'Failed to approve'], 500);
    }
    
    // Get the updated log for response
    $result = $mysqli->query("SELECT * FROM audit_logs WHERE id = $id LIMIT 1");
    $log = $result ? $result->fetch_assoc() : null;
    
    json_response(['success' => true, 'message' => 'Approved successfully', 'log' => $log]);
}

if ($method === 'POST' && $action_param === 'reject') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    $approved_by = trim($input['approved_by'] ?? '');
    
    if ($id <= 0 || $approved_by === '') {
        json_response(['success' => false, 'message' => 'Invalid input'], 400);
    }
    
    $stmt = $mysqli->prepare("UPDATE audit_logs SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'Failed to prepare statement'], 500);
    }
    
    $stmt->bind_param('si', $approved_by, $id);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'Failed to reject'], 500);
    }
    
    json_response(['success' => true, 'message' => 'Rejected successfully']);
}

if ($method === 'GET') {
    $q = trim($_GET['q'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $limit = (int)($_GET['limit'] ?? 200);
    if ($limit < 1 || $limit > 1000) { $limit = 200; }

    $where = [];
    if ($q !== '') {
        $like = '%' . $mysqli->real_escape_string($q) . '%';
        $where[] = "(`action` LIKE '$like' OR `entity` LIKE '$like' OR `user` LIKE '$like' OR `description` LIKE '$like')";
    }
    if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected'])) {
        $where[] = "`status` = '$status'";
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "SELECT id, `action`, `entity`, `entity_type`, `entity_id`, `user`, `role`, `type`, `description`, `details`, `status`, `approved_by`, `approved_at`, `timestamp`
            FROM audit_logs
            $whereClause
            ORDER BY `timestamp` DESC, `id` DESC
            LIMIT $limit";

    $res = $mysqli->query($sql);
    if (!$res) {
        json_response(['success' => false, 'message' => 'Failed to fetch audit logs', 'details' => $mysqli->error], 500);
    }

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (string)$r['id'],
            'action' => $r['action'],
            'entity' => $r['entity'],
            'entityType' => $r['entity_type'],
            'entityId' => $r['entity_id'],
            'user' => $r['user'],
            'role' => $r['role'],
            'type' => $r['type'],
            'description' => $r['description'],
            'details' => $r['details'],
            'status' => $r['status'],
            'approvedBy' => $r['approved_by'],
            'approvedAt' => $r['approved_at'],
            'timestamp' => $r['timestamp'],
        ];
    }

    json_response(['success' => true, 'data' => $rows]);
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
