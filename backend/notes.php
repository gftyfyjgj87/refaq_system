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

// Create table if not exists
$createSql = "CREATE TABLE IF NOT EXISTS `notes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` ENUM('teacher','student') NOT NULL,
  `entity_id` INT UNSIGNED NULL,
  `entity_name` VARCHAR(200) NOT NULL,
  `priority` ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  `content` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_entity` (`entity_type`, `entity_id`),
  KEY `idx_priority` (`priority`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$mysqli->query($createSql);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

if ($method === 'GET') {
    // Optional filters: entity_type, entity_id
    $where = [];
    if (!empty($_GET['entity_type'])) {
        $et = $mysqli->real_escape_string($_GET['entity_type']);
        $where[] = "`entity_type` = '$et'";
    }
    if (!empty($_GET['entity_id'])) {
        $eid = (int)$_GET['entity_id'];
        $where[] = "`entity_id` = $eid";
    }
    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT `id`, `entity_type`, `entity_id`, `entity_name`, `priority`, `content`, `created_at` FROM `notes` $whereSql ORDER BY `created_at` DESC, `id` DESC";
    $res = $mysqli->query($sql);
    if (!$res) {
        // If table missing, try to create and return empty list to avoid 500 on first run
        if (strpos($mysqli->error, 'doesn\'t exist') !== false || strpos($mysqli->error, 'does not exist') !== false) {
            $mysqli->query($createSql);
            json_response(['success' => true, 'data' => []]);
        }
        json_response(['success' => false, 'message' => 'فشل في جلب الملاحظات', 'details' => $mysqli->error], 500);
    }
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (string)$r['id'],
            'entityType' => $r['entity_type'],
            'entityId' => $r['entity_id'] !== null ? (string)$r['entity_id'] : null,
            'entityName' => $r['entity_name'],
            'priority' => $r['priority'],
            'content' => $r['content'],
            'date' => $r['created_at'],
        ];
    }
    json_response(['success' => true, 'data' => $rows]);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { $input = $_POST; }

if ($method === 'POST' && $action === 'create') {
    $entityType = in_array($input['entityType'] ?? '', ['teacher','student'], true) ? $input['entityType'] : '';
    $entityId = isset($input['entityId']) && $input['entityId'] !== '' ? (int)$input['entityId'] : null;
    $entityName = trim($input['entityName'] ?? '');
    $priority = in_array($input['priority'] ?? 'medium', ['high','medium','low'], true) ? $input['priority'] : 'medium';
    $content = trim($input['content'] ?? '');

    if ($entityType === '' || $entityName === '' || $content === '') {
        json_response(['success' => false, 'message' => 'بيانات غير مكتملة'], 400);
    }

    $stmt = $mysqli->prepare('INSERT INTO notes (entity_type, entity_id, entity_name, priority, content) VALUES (?,?,?,?,?)');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'تعذر إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    $eid = $entityId; // can be null
    $stmt->bind_param('sisss', $entityType, $eid, $entityName, $priority, $content);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'فشل في حفظ الملاحظة', 'details' => $stmt->error], 500);
    }

    $newId = $stmt->insert_id;
    $res = $mysqli->query('SELECT `id`, `entity_type`, `entity_id`, `entity_name`, `priority`, `content`, `created_at` FROM `notes` WHERE id = ' . (int)$newId . ' LIMIT 1');
    $row = $res ? $res->fetch_assoc() : null;
    $data = $row ? [
        'id' => (string)$row['id'],
        'entityType' => $row['entity_type'],
        'entityId' => $row['entity_id'] !== null ? (string)$row['entity_id'] : null,
        'entityName' => $row['entity_name'],
        'priority' => $row['priority'],
        'content' => $row['content'],
        'date' => $row['created_at'],
    ] : [
        'id' => (string)$newId,
        'entityType' => $entityType,
        'entityId' => $entityId !== null ? (string)$entityId : null,
        'entityName' => $entityName,
        'priority' => $priority,
        'content' => $content,
        'date' => date('Y-m-d H:i:s'),
    ];

    json_response(['success' => true, 'data' => $data]);
}

if ($method === 'POST' && $action === 'update') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $content = trim($input['content'] ?? '');
    $priority = $input['priority'] ?? '';
    $validPriorities = ['high','medium','low'];

    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرّف غير صالح'], 400);
    }
    if ($content === '' && $priority === '') {
        json_response(['success' => false, 'message' => 'لا توجد بيانات لتحديثها'], 400);
    }

    $fields = [];
    $types = '';
    $values = [];

    if ($content !== '') {
        $fields[] = '`content` = ?';
        $types .= 's';
        $values[] = $content;
    }
    if ($priority !== '' && in_array($priority, $validPriorities, true)) {
        $fields[] = '`priority` = ?';
        $types .= 's';
        $values[] = $priority;
    }

    if (empty($fields)) {
        json_response(['success' => false, 'message' => 'قيم غير صالحة للتحديث'], 400);
    }

    $sql = 'UPDATE `notes` SET ' . implode(', ', $fields) . ' WHERE `id` = ?';
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'تعذر إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    $types .= 'i';
    $values[] = $id;
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'فشل في تحديث الملاحظة', 'details' => $stmt->error], 500);
    }

    // Return updated row
    $res = $mysqli->query('SELECT `id`, `entity_type`, `entity_id`, `entity_name`, `priority`, `content`, `created_at` FROM `notes` WHERE id = ' . (int)$id . ' LIMIT 1');
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) {
        json_response(['success' => false, 'message' => 'لم يتم العثور على الملاحظة بعد التحديث'], 404);
    }
    $data = [
        'id' => (string)$row['id'],
        'entityType' => $row['entity_type'],
        'entityId' => $row['entity_id'] !== null ? (string)$row['entity_id'] : null,
        'entityName' => $row['entity_name'],
        'priority' => $row['priority'],
        'content' => $row['content'],
        'date' => $row['created_at'],
    ];
    json_response(['success' => true, 'data' => $data]);
}

if ($method === 'POST' && $action === 'delete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرّف غير صالح'], 400);
    }
    $stmt = $mysqli->prepare('DELETE FROM notes WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'تعذر إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'فشل في حذف الملاحظة', 'details' => $stmt->error], 500);
    }
    json_response(['success' => true]);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
