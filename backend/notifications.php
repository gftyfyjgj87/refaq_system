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
$createSql = "CREATE TABLE IF NOT EXISTS `notifications_sent` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient` VARCHAR(200) NOT NULL,
  `recipient_type` ENUM('student','teacher','parent') NOT NULL,
  `method` ENUM('internal','whatsapp','email') NOT NULL DEFAULT 'internal',
  `message` TEXT NOT NULL,
  `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'sent',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_error` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_method` (`method`),
  KEY `idx_recipient_type` (`recipient_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$mysqli->query($createSql);

// Ensure new columns exist if table was created previously without them
@$mysqli->query("ALTER TABLE `notifications_sent` ADD COLUMN IF NOT EXISTS `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'sent'");
@$mysqli->query("ALTER TABLE `notifications_sent` ADD COLUMN IF NOT EXISTS `attempts` INT UNSIGNED NOT NULL DEFAULT 1");
@$mysqli->query("ALTER TABLE `notifications_sent` ADD COLUMN IF NOT EXISTS `last_error` VARCHAR(255) NULL");
@$mysqli->query("CREATE INDEX IF NOT EXISTS `idx_status` ON `notifications_sent`(`status`)");

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

if ($method === 'GET') {
    // Optional filters
    $where = [];
    if (!empty($_GET['method'])) {
        $m = $mysqli->real_escape_string($_GET['method']);
        $where[] = "`method` = '$m'";
    }
    if (!empty($_GET['recipient_type'])) {
        $rt = $mysqli->real_escape_string($_GET['recipient_type']);
        $where[] = "`recipient_type` = '$rt'";
    }
    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT `id`, `recipient`, `recipient_type`, `method`, `message`, `status`, `attempts`, `last_error`, `created_at` FROM `notifications_sent` $whereSql ORDER BY `created_at` DESC, `id` DESC";
    $res = $mysqli->query($sql);
    if (!$res) {
        // If table missing, try to create and return empty list to avoid 500 on first run
        if (strpos($mysqli->error, 'doesn\'t exist') !== false || strpos($mysqli->error, 'does not exist') !== false) {
            $mysqli->query($createSql);
            json_response(['success' => true, 'data' => []]);
        }
        json_response(['success' => false, 'message' => 'فشل في جلب الإشعارات', 'details' => $mysqli->error], 500);
    }
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (string)$r['id'],
            'recipient' => $r['recipient'],
            'recipientType' => $r['recipient_type'],
            'type' => $r['method'],
            'message' => $r['message'],
            'status' => $r['status'],
            'attempts' => (int)$r['attempts'],
            'lastError' => $r['last_error'],
            'date' => $r['created_at'],
        ];
    }
    json_response(['success' => true, 'data' => $rows]);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { $input = $_POST; }

if ($method === 'POST' && ($action === null || $action === 'create')) {
    $recipient = trim($input['recipient'] ?? '');
    $recipientType = trim($input['recipientType'] ?? '');
    $sendMethod = trim($input['type'] ?? ($input['method'] ?? ''));
    $message = trim($input['message'] ?? '');

    $validRecipientTypes = ['student','teacher','parent'];
    $validMethods = ['internal','whatsapp','email'];

    if ($recipient === '' || $message === '' || !in_array($recipientType, $validRecipientTypes, true) || !in_array($sendMethod, $validMethods, true)) {
        json_response(['success' => false, 'message' => 'بيانات غير مكتملة أو غير صحيحة'], 400);
    }

    $stmt = $mysqli->prepare('INSERT INTO notifications_sent (recipient, recipient_type, method, message, status, attempts) VALUES (?,?,?,?,?,?)');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'تعذر إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    // حالياً نعتبر الإرسال ناجحاً مباشرةً، ويمكن لاحقاً ضبطه إلى pending ثم محاولة الإرسال الفعلي
    $initialStatus = 'sent';
    $initialAttempts = 1;
    $stmt->bind_param('sssssi', $recipient, $recipientType, $sendMethod, $message, $initialStatus, $initialAttempts);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'فشل في حفظ الإشعار', 'details' => $stmt->error], 500);
    }
    $newId = $stmt->insert_id;

    // Fetch created row
    $res = $mysqli->query("SELECT id, recipient, recipient_type, method, message, status, attempts, last_error, created_at FROM notifications_sent WHERE id = " . (int)$newId . " LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) {
        json_response(['success' => true, 'data' => [
            'id' => (string)$newId,
            'recipient' => $recipient,
            'recipientType' => $recipientType,
            'type' => $sendMethod,
            'message' => $message,
            'status' => $initialStatus,
            'attempts' => $initialAttempts,
            'lastError' => null,
            'date' => date('Y-m-d H:i:s'),
        ]]);
    } else {
        json_response(['success' => true, 'data' => [
            'id' => (string)$row['id'],
            'recipient' => $row['recipient'],
            'recipientType' => $row['recipient_type'],
            'type' => $row['method'],
            'message' => $row['message'],
            'status' => $row['status'],
            'attempts' => (int)$row['attempts'],
            'lastError' => $row['last_error'],
            'date' => $row['created_at'],
        ]]);
    }
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
