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

$action = $_GET['action'] ?? null;

// Ensure settings table exists
$createSql = "CREATE TABLE IF NOT EXISTS `app_settings` (
  `id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `settings_json` JSON NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$mysqli->query($createSql);

$defaultSettings = [
  'institutionName' => '',
  'institutionEmail' => '',
  'institutionPhone' => '',
  'institutionAddress' => '',
  'currency' => 'EGP',
  'timezone' => 'Africa/Cairo',
  'emailNotifications' => false,
  'smsNotifications' => false,
  'pushNotifications' => false,
  'notifyOnNewStudent' => false,
  'notifyOnPayment' => false,
  'notifyOnAbsence' => false,
  'maintenanceMode' => false,
  'allowRegistration' => true,
  'requireEmailVerification' => false,
  'sessionTimeout' => 30,
  'maxLoginAttempts' => 5,
  'autoBackup' => false,
  'backupFrequency' => 'daily',
  'backupTime' => '02:00',
];

$method = $_SERVER['REQUEST_METHOD'];

function get_mysql_bin(string $name): string {
    $candidates = [
        __DIR__ . "\\..\\mysql\\bin\\{$name}.exe",
        __DIR__ . "\\..\\..\\mysql\\bin\\{$name}.exe",
        "C:\\xampp\\mysql\\bin\\{$name}.exe",
        $name,
    ];
    foreach ($candidates as $p) {
        if (file_exists($p)) return $p;
    }
    return $name;
}

// Download DB backup (SQL)
if ($method === 'GET' && $action === 'backup_db') {
    global $dbHost, $dbPort, $dbUser, $dbPass, $dbName;

    $mysqldump = get_mysql_bin('mysqldump');
    $host = $dbHost;
    $port = (string)$dbPort;
    $user = $dbUser;
    $pass = (string)$dbPass;
    $name = $dbName;

    $filename = 'refaq_backup_' . date('Y-m-d_H-i-s') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $cmd = '"' . $mysqldump . '" --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4'
        . ' --host=' . escapeshellarg($host)
        . ' --port=' . escapeshellarg($port)
        . ' --user=' . escapeshellarg($user);
    if ($pass !== '') {
        $cmd .= ' --password=' . escapeshellarg($pass);
    }
    $cmd .= ' ' . escapeshellarg($name);

    $descriptorspec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptorspec, $pipes);
    if (!is_resource($proc)) {
        http_response_code(500);
        echo "Failed to start mysqldump";
        exit;
    }
    $out = $pipes[1];
    $err = $pipes[2];
    while (!feof($out)) {
        $buf = fread($out, 8192);
        if ($buf === false) break;
        echo $buf;
        flush();
    }
    $stderr = stream_get_contents($err);
    fclose($out);
    fclose($err);
    $code = proc_close($proc);
    if ($code !== 0) {
        // can't send JSON now, but at least provide error at end
        echo "\n\n-- ERROR: mysqldump failed: " . $stderr;
    }
    exit;
}

// Restore DB from uploaded SQL
if ($method === 'POST' && $action === 'restore_db') {
    global $dbHost, $dbPort, $dbUser, $dbPass, $dbName;

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        json_response(['success' => false, 'message' => 'ملف النسخة مطلوب'], 400);
    }
    if (($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['success' => false, 'message' => 'فشل رفع الملف'], 400);
    }
    $tmp = $_FILES['file']['tmp_name'] ?? '';
    if ($tmp === '' || !file_exists($tmp)) {
        json_response(['success' => false, 'message' => 'ملف غير صالح'], 400);
    }
    $ext = strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext !== 'sql') {
        json_response(['success' => false, 'message' => 'الملف يجب أن يكون بصيغة .sql'], 400);
    }

    $mysql = get_mysql_bin('mysql');
    $host = $dbHost;
    $port = (string)$dbPort;
    $user = $dbUser;
    $pass = (string)$dbPass;
    $name = $dbName;

    $cmd = '"' . $mysql . '" --default-character-set=utf8mb4'
        . ' --host=' . escapeshellarg($host)
        . ' --port=' . escapeshellarg($port)
        . ' --user=' . escapeshellarg($user);
    if ($pass !== '') {
        $cmd .= ' --password=' . escapeshellarg($pass);
    }
    $cmd .= ' ' . escapeshellarg($name);

    $descriptorspec = [
        0 => ['pipe', 'r'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptorspec, $pipes);
    if (!is_resource($proc)) {
        json_response(['success' => false, 'message' => 'فشل تشغيل mysql'], 500);
    }
    $in = $pipes[0];
    $err = $pipes[2];
    $fh = fopen($tmp, 'rb');
    if ($fh === false) {
        fclose($in);
        fclose($err);
        proc_close($proc);
        json_response(['success' => false, 'message' => 'تعذر قراءة الملف'], 500);
    }
    while (!feof($fh)) {
        $buf = fread($fh, 8192);
        if ($buf === false) break;
        fwrite($in, $buf);
    }
    fclose($fh);
    fclose($in);
    $stderr = stream_get_contents($err);
    fclose($err);
    $code = proc_close($proc);
    if ($code !== 0) {
        json_response(['success' => false, 'message' => 'فشل استعادة النسخة', 'details' => $stderr], 500);
    }
    json_response(['success' => true, 'message' => 'تمت استعادة النسخة بنجاح']);
}

if ($method === 'GET') {
    $res = $mysqli->query("SELECT settings_json FROM app_settings WHERE id = 1");
    if ($res && $row = $res->fetch_assoc()) {
        $data = json_decode($row['settings_json'], true);
        if (!is_array($data)) { $data = []; }
        $merged = array_merge($defaultSettings, $data);
        json_response(['success' => true, 'data' => $merged]);
    } else {
        json_response(['success' => true, 'data' => $defaultSettings]);
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        json_response(['success' => false, 'message' => 'Invalid JSON'], 400);
    }
    // Merge with defaults to ensure all keys exist and types are sane
    $payload = array_merge($defaultSettings, $input);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        json_response(['success' => false, 'message' => 'Failed to encode settings'], 500);
    }
    $stmt = $mysqli->prepare("INSERT INTO app_settings (id, settings_json) VALUES (1, ?) ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json)");
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'Failed to prepare statement', 'details' => $mysqli->error], 500);
    }
    $stmt->bind_param('s', $json);
    if (!$stmt->execute()) {
        json_response(['success' => false, 'message' => 'Failed to save settings', 'details' => $stmt->error], 500);
    }
    json_response(['success' => true]);
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
