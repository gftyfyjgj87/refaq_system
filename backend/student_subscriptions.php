<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

function fmt_date($d) {
    if (!$d) return '';
    $ts = strtotime($d);
    if ($ts === false) return $d;
    return date('d/m/Y', $ts);
}

function map_row($row) {
    return [
        'id' => isset($row['id']) ? (string)$row['id'] : '',
        'studentName' => $row['student_name'] ?? '',
        'packageName' => $row['package_name'] ?? '',
        'packageType' => ($row['package_type'] ?? 'quran'),
        'startDate' => fmt_date($row['start_date'] ?? null),
        'endDate' => fmt_date($row['end_date'] ?? null),
        'amount' => isset($row['amount']) ? (float)$row['amount'] : 0,
        'status' => $row['status'] ?? 'active',
    ];
}

if ($method === 'GET') {
    $sql = "SELECT ss.id,
                   s.name AS student_name,
                   sp.name AS package_name,
                   sp.type AS package_type,
                   ss.start_date,
                   ss.end_date,
                   ss.amount,
                   ss.status
            FROM student_subscriptions ss
            LEFT JOIN students s ON s.id = ss.student_id
            LEFT JOIN subscription_packages sp ON sp.id = ss.package_id
            ORDER BY ss.id DESC
            LIMIT 500";
    $res = $mysqli->query($sql);
    if (!$res) {
        echo json_encode(['success' => false, 'message' => 'فشل جلب الاشتراكات', 'details' => $mysqli->error]);
        exit;
    }
    $list = [];
    while ($row = $res->fetch_assoc()) { $list[] = map_row($row); }
    echo json_encode(['success' => true, 'data' => $list]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST' && $action === 'toggle_status') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $status = $input['status'] ?? '';
    if ($id <= 0 || !in_array($status, ['active','frozen','inactive'], true)) {
        echo json_encode(['success' => false, 'message' => 'معرف أو حالة غير صالحة']);
        exit;
    }
    $stmt = $mysqli->prepare('UPDATE student_subscriptions SET status = ? WHERE id = ?');
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'فشل إعداد الاستعلام', 'details' => $mysqli->error]);
        exit;
    }
    $stmt->bind_param('si', $status, $id);
    if (!$stmt->execute()) {
        $err = $stmt->error; $stmt->close();
        echo json_encode(['success' => false, 'message' => 'فشل تحديث الحالة', 'details' => $err]);
        exit;
    }
    $stmt->close();
    // ارجاع الصف بعد التحديث
    $res = $mysqli->query('SELECT ss.id, s.name AS student_name, sp.name AS package_name, sp.type AS package_type, ss.start_date, ss.end_date, ss.amount, ss.status FROM student_subscriptions ss LEFT JOIN students s ON s.id=ss.student_id LEFT JOIN subscription_packages sp ON sp.id=ss.package_id WHERE ss.id='.(int)$id.' LIMIT 1');
    $row = $res ? $res->fetch_assoc() : null;
    echo json_encode(['success' => true, 'data' => $row ? map_row($row) : null]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'غير مدعوم'], 400);
