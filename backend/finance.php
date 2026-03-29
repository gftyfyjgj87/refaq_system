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

function finance_has_column(mysqli $mysqli, string $table, string $column): bool {
    $dbRes = $mysqli->query('SELECT DATABASE() AS db');
    $dbRow = $dbRes ? $dbRes->fetch_assoc() : null;
    $db = $dbRow && isset($dbRow['db']) ? $dbRow['db'] : '';
    if ($db === '') { return false; }
    $t = $mysqli->real_escape_string($table);
    $c = $mysqli->real_escape_string($column);
    $d = $mysqli->real_escape_string($db);
    $res = $mysqli->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$d' AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1");
    return $res && $res->num_rows > 0;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$resource = $_GET['resource'] ?? null;

// Ensure income_categories table exists
$createIncomeCategories = "CREATE TABLE IF NOT EXISTS `income_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_income_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$mysqli->query($createIncomeCategories);

if (!finance_has_column($mysqli, 'teacher_payments', 'payment_type')) {
    $mysqli->query("ALTER TABLE teacher_payments ADD COLUMN payment_type ENUM('salary','advance') NOT NULL DEFAULT 'salary' AFTER amount");
}
if (!finance_has_column($mysqli, 'supervisor_payments', 'payment_type')) {
    $mysqli->query("ALTER TABLE supervisor_payments ADD COLUMN payment_type ENUM('salary','advance') NOT NULL DEFAULT 'salary' AFTER amount");
}

function map_vault_row(array $row): array {
    return [
        'id' => (string)($row['new_id'] ?? ($row['id'] ?? '')),
        'name' => $row['name'] ?? '',
        'balance' => isset($row['balance']) ? (float)$row['balance'] : 0,
        'description' => $row['description'] ?? '',
    ];
}

function map_transaction_row(array $row): array {
    return [
        'id' => (string)($row['id'] ?? ''),
        'type' => $row['type'] === 'expense' ? 'expense' : 'income',
        'category' => $row['category'] ?? '',
        'description' => $row['description'] ?? '',
        'amount' => isset($row['amount']) ? (float)$row['amount'] : 0,
        'date' => $row['date'] ?? '',
        'person' => $row['person'] ?? null,
        'vaultId' => isset($row['vault_id']) ? (string)$row['vault_id'] : null,
    ];
}

if ($method === 'GET') {
    if ($resource === 'vaults') {
        $result = $mysqli->query('SELECT * FROM `vaults` ORDER BY id ASC');
        if (!$result) {
            json_response(['success' => false, 'message' => 'فشل في جلب الخزنات', 'details' => $mysqli->error], 500);
        }
        $vaults = [];
        while ($row = $result->fetch_assoc()) {
            $vaults[] = map_vault_row($row);
        }
        json_response(['success' => true, 'data' => $vaults]);
    }

    if ($resource === 'income_categories') {
        $result = $mysqli->query('SELECT id, name FROM `income_categories` ORDER BY name ASC');
        if (!$result) {
            json_response(['success' => false, 'message' => 'فشل في جلب التصنيفات', 'details' => $mysqli->error], 500);
        }
        $cats = [];
        while ($row = $result->fetch_assoc()) {
            $cats[] = ['id' => (string)$row['id'], 'name' => $row['name'] ?? ''];
        }
        json_response(['success' => true, 'data' => $cats]);
    }

    if ($resource === 'transactions') {
        $result = $mysqli->query('SELECT * FROM `transactions` ORDER BY `date` DESC, id DESC LIMIT 500');
        if (!$result) {
            json_response(['success' => false, 'message' => 'فشل في جلب المعاملات', 'details' => $mysqli->error], 500);
        }
        $txs = [];
        while ($row = $result->fetch_assoc()) {
            $txs[] = map_transaction_row($row);
        }
        json_response(['success' => true, 'data' => $txs]);
    }

    if ($resource === 'stats') {
        $sql = "SELECT 
                    SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,
                    SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
                FROM `transactions`
                WHERE DATE_FORMAT(`date`, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')";
        $res = $mysqli->query($sql);
        if (!$res) {
            json_response(['success' => false, 'message' => 'فشل في حساب الإحصائيات', 'details' => $mysqli->error], 500);
        }
        $row = $res->fetch_assoc() ?: ['income' => 0, 'expense' => 0];
        $income = (float)($row['income'] ?? 0);
        $expense = (float)($row['expense'] ?? 0);
        $net = $income - $expense;

        // المتأخرات من اشتراكات الطلاب (amount - paid_amount) للحالات النشطة/المتأخرة
        $res2 = $mysqli->query("SELECT COALESCE(SUM(GREATEST(amount - paid_amount, 0)), 0) AS unpaid FROM `student_subscriptions` WHERE status IN ('active','overdue')");
        $row2 = $res2 ? $res2->fetch_assoc() : ['unpaid' => 0];
        $unpaid = (float)($row2['unpaid'] ?? 0);

        json_response(['success' => true, 'data' => [
            'monthlyRevenue' => $income,
            'monthlyExpenses' => $expense,
            'netProfit' => $net,
            'unpaidAmount' => $unpaid,
        ]]);
    }

    if ($resource === 'supervisor_balances') {
        $res = $mysqli->query("SELECT id, name, salary FROM `users` WHERE role = 'supervisor'");
        if (!$res) {
            json_response(['success' => false, 'message' => 'فشل في جلب المشرفين', 'details' => $mysqli->error], 500);
        }
        $list = [];
        while ($u = $res->fetch_assoc()) {
            $sid = (int)$u['id'];
            $salary = (float)($u['salary'] ?? 0);
            $paidRes = $mysqli->query("SELECT COALESCE(SUM(amount),0) AS paid FROM supervisor_payments WHERE supervisor_id = $sid AND DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')");
            $advRes = $mysqli->query("SELECT COALESCE(SUM(amount),0) AS adv FROM supervisor_payments WHERE supervisor_id = $sid AND payment_type='advance' AND DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')");
            $paid = $paidRes ? (float)($paidRes->fetch_assoc()['paid'] ?? 0) : 0;
            $adv = $advRes ? (float)($advRes->fetch_assoc()['adv'] ?? 0) : 0;
            $due = max($salary - $paid, 0);
            $list[] = [
                'id' => (string)$sid,
                'name' => $u['name'] ?? '',
                'salary' => $salary,
                'paidThisMonth' => $paid,
                'advancesThisMonth' => $adv,
                'dueThisMonth' => $due,
            ];
        }
        json_response(['success' => true, 'data' => $list]);
    }

    if ($resource === 'teacher_balances') {
        $res = $mysqli->query("SELECT id, name, hourly_rate FROM `users` WHERE role = 'teacher'");
        if (!$res) {
            json_response(['success' => false, 'message' => 'فشل في جلب المعلمين', 'details' => $mysqli->error], 500);
        }
        $list = [];
        while ($u = $res->fetch_assoc()) {
            $tid = (int)$u['id'];
            $earnedRes = $mysqli->query("SELECT COALESCE(SUM(amount),0) AS total FROM teacher_sessions WHERE teacher_id = $tid AND DATE_FORMAT(session_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')");
            $paidRes = $mysqli->query("SELECT COALESCE(SUM(amount),0) AS paid FROM teacher_payments WHERE teacher_id = $tid AND DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')");
            $advRes = $mysqli->query("SELECT COALESCE(SUM(amount),0) AS adv FROM teacher_payments WHERE teacher_id = $tid AND payment_type='advance' AND DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')");
            $hoursRes = $mysqli->query("SELECT COALESCE(SUM(hours),0) AS totalHours FROM teacher_sessions WHERE teacher_id = $tid AND DATE_FORMAT(session_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')");
            $earned = $earnedRes ? (float)($earnedRes->fetch_assoc()['total'] ?? 0) : 0;
            $paid = $paidRes ? (float)($paidRes->fetch_assoc()['paid'] ?? 0) : 0;
            $adv = $advRes ? (float)($advRes->fetch_assoc()['adv'] ?? 0) : 0;
            $hours = $hoursRes ? (float)($hoursRes->fetch_assoc()['totalHours'] ?? 0) : 0;
            $due = max($earned - $paid, 0);
            $list[] = [
                'id' => (string)$tid,
                'name' => $u['name'] ?? '',
                'hourlyRate' => isset($u['hourly_rate']) ? (float)$u['hourly_rate'] : null,
                'earnedThisMonth' => $earned,
                'paidThisMonth' => $paid,
                'advancesThisMonth' => $adv,
                'dueThisMonth' => $due,
                'hoursThisMonth' => $hours,
            ];
        }
        json_response(['success' => true, 'data' => $list]);
    }

    if ($resource === 'supervisor_payment_history') {
        $supervisor_id = isset($_GET['supervisor_id']) ? (int)$_GET['supervisor_id'] : 0;
        if ($supervisor_id <= 0) {
            json_response(['success' => false, 'message' => 'معرف المشرف مطلوب'], 400);
        }
        $res = $mysqli->query("SELECT amount, payment_type, paid_at, note FROM supervisor_payments WHERE supervisor_id = $supervisor_id ORDER BY paid_at DESC LIMIT 50");
        if (!$res) {
            json_response(['success' => false, 'message' => 'فشل في جلب السجل', 'details' => $mysqli->error], 500);
        }
        $history = [];
        while ($row = $res->fetch_assoc()) {
            $history[] = [
                'amount' => (float)($row['amount'] ?? 0),
                'payment_type' => $row['payment_type'] ?? 'salary',
                'paid_at' => $row['paid_at'] ?? '',
                'note' => $row['note'] ?? '',
            ];
        }
        json_response(['success' => true, 'data' => $history]);
    }

    if ($resource === 'teacher_payment_history') {
        $teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
        if ($teacher_id <= 0) {
            json_response(['success' => false, 'message' => 'معرف المعلم مطلوب'], 400);
        }
        $res = $mysqli->query("SELECT amount, payment_type, paid_at, note FROM teacher_payments WHERE teacher_id = $teacher_id ORDER BY paid_at DESC LIMIT 50");
        if (!$res) {
            json_response(['success' => false, 'message' => 'فشل في جلب السجل', 'details' => $mysqli->error], 500);
        }
        $history = [];
        while ($row = $res->fetch_assoc()) {
            $history[] = [
                'amount' => (float)($row['amount'] ?? 0),
                'payment_type' => $row['payment_type'] ?? 'salary',
                'paid_at' => $row['paid_at'] ?? '',
                'note' => $row['note'] ?? '',
            ];
        }
        json_response(['success' => true, 'data' => $history]);
    }

    // default: both
    $vResult = $mysqli->query('SELECT * FROM `vaults` ORDER BY id ASC');
    $tResult = $mysqli->query('SELECT * FROM `transactions` ORDER BY `date` DESC, id DESC LIMIT 500');
    $vaults = [];
    $txs = [];
    if ($vResult) { while ($row = $vResult->fetch_assoc()) { $vaults[] = map_vault_row($row); } }
    if ($tResult) { while ($row = $tResult->fetch_assoc()) { $txs[] = map_transaction_row($row); } }

    json_response(['success' => true, 'data' => ['vaults' => $vaults, 'transactions' => $txs]]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// إضافة تصنيف إيراد
if ($method === 'POST' && $action === 'add_income_category') {
    $name = trim($input['name'] ?? '');
    if ($name === '') {
        json_response(['success' => false, 'message' => 'اسم التصنيف مطلوب'], 400);
    }
    $stmt = $mysqli->prepare('INSERT INTO `income_categories` (`name`) VALUES (?)');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    $stmt->bind_param('s', $name);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        // Duplicate name
        if (str_contains(strtolower($error), 'duplicate')) {
            json_response(['success' => false, 'message' => 'التصنيف موجود بالفعل'], 409);
        }
        json_response(['success' => false, 'message' => 'فشل إضافة التصنيف', 'details' => $error], 500);
    }
    $id = (int)$stmt->insert_id;
    $stmt->close();
    json_response(['success' => true, 'message' => 'تم إضافة التصنيف', 'data' => ['id' => (string)$id, 'name' => $name]], 201);
}

// حذف تصنيف إيراد
if ($method === 'POST' && $action === 'delete_income_category') {
    $id = isset($input['id']) ? (int)$input['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف التصنيف مطلوب'], 400);
    }
    $stmt = $mysqli->prepare('DELETE FROM `income_categories` WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل حذف التصنيف', 'details' => $error], 500);
    }
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected <= 0) {
        json_response(['success' => false, 'message' => 'التصنيف غير موجود'], 404);
    }
    json_response(['success' => true, 'message' => 'تم حذف التصنيف بنجاح']);
}

if ($method === 'POST' && $action === 'add_vault') {
    $name = trim($input['name'] ?? '');
    $balance = isset($input['balance']) ? (float)$input['balance'] : 0;
    $description = trim($input['description'] ?? '');

    if ($name === '') {
        json_response(['success' => false, 'message' => 'اسم الخزنة مطلوب'], 400);
    }

    $stmt = $mysqli->prepare('INSERT INTO `vaults` (name, balance, description) VALUES (?, ?, ?)');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    $stmt->bind_param('sds', $name, $balance, $description);
    if (!$stmt->execute()) {
        $error = $stmt->error; $stmt->close();
        json_response(['success' => false, 'message' => 'فشل في إنشاء الخزنة', 'details' => $error], 500);
    }
    $id = $stmt->insert_id;
    $stmt->close();

    $row = $mysqli->query('SELECT * FROM `vaults` WHERE id = ' . (int)$id . ' LIMIT 1')->fetch_assoc();
    json_response(['success' => true, 'message' => 'تم إضافة الخزنة', 'data' => map_vault_row($row)], 201);
}

// حذف خزنة (مع حذف المعاملات المرتبطة)
if ($method === 'POST' && $action === 'delete_vault') {
    $id = isset($input['id']) ? (int)$input['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف الخزنة مطلوب'], 400);
    }

    $vRes = $mysqli->query('SELECT * FROM `vaults` WHERE id = ' . (int)$id . ' LIMIT 1');
    if (!$vRes || $vRes->num_rows === 0) {
        json_response(['success' => false, 'message' => 'الخزنة غير موجودة'], 404);
    }
    $vault = $vRes->fetch_assoc();

    $mysqli->begin_transaction();
    try {
        // حذف المعاملات المرتبطة بهذه الخزنة (لا نعدل أرصدة لأن الخزنة نفسها سيتم حذفها)
        $stmtTx = $mysqli->prepare('DELETE FROM `transactions` WHERE vault_id = ?');
        if (!$stmtTx) {
            throw new Exception('فشل في إعداد استعلام حذف المعاملات');
        }
        $stmtTx->bind_param('i', $id);
        if (!$stmtTx->execute()) {
            $e = $stmtTx->error;
            $stmtTx->close();
            throw new Exception($e ?: 'فشل حذف المعاملات');
        }
        $stmtTx->close();

        // حذف الخزنة
        $stmtV = $mysqli->prepare('DELETE FROM `vaults` WHERE id = ?');
        if (!$stmtV) {
            throw new Exception('فشل في إعداد استعلام حذف الخزنة');
        }
        $stmtV->bind_param('i', $id);
        if (!$stmtV->execute()) {
            $e = $stmtV->error;
            $stmtV->close();
            throw new Exception($e ?: 'فشل حذف الخزنة');
        }
        $stmtV->close();

        $mysqli->commit();
        json_response(['success' => true, 'message' => 'تم حذف الخزنة بنجاح', 'data' => ['id' => (string)$id, 'name' => ($vault['name'] ?? '')]]);
    } catch (Exception $e) {
        $mysqli->rollback();
        json_response(['success' => false, 'message' => 'فشل حذف الخزنة', 'details' => $e->getMessage()], 500);
    }
}

if (($method === 'POST' || $method === 'GET') && $action === 'add_transaction') {
    $payload = $method === 'GET' ? $_GET : $input;
    $type = ($payload['type'] ?? 'income') === 'expense' ? 'expense' : 'income';
    $category = trim($payload['category'] ?? '');
    $description = trim($payload['description'] ?? '');
    $amount = isset($payload['amount']) ? (float)$payload['amount'] : 0;
    $person = isset($payload['person']) ? trim((string)$payload['person']) : null;
    $vaultId = isset($payload['vaultId']) ? (int)$payload['vaultId'] : (isset($payload['vault_id']) ? (int)$payload['vault_id'] : null);
    if ($vaultId !== null && $vaultId <= 0) {
        $vaultId = null;
    }

    if ($category === '' || $amount <= 0) {
        json_response(['success' => false, 'message' => 'التصنيف والمبلغ مطلوبان'], 400);
    }

    // Adjust vault balance if provided
    if ($vaultId !== null) {
        $op = $type === 'income' ? '+' : '-';
        if (!$mysqli->query("UPDATE `vaults` SET balance = balance $op " . $amount . " WHERE id = " . (int)$vaultId)) {
            json_response(['success' => false, 'message' => 'فشل تحديث رصيد الخزنة', 'details' => $mysqli->error], 500);
        }
    }

    // استخدام استعلام مُؤمَّن مع تهريب القيم بدلاً من bind_param الديناميكي لتفادي أخطاء الأنواع

    // Simpler: build query without prepared for optional fields but escape
    $typeEsc = $mysqli->real_escape_string($type);
    $catEsc = $mysqli->real_escape_string($category);
    $descEsc = $mysqli->real_escape_string($description);
    $personEsc = $person !== null ? ("'" . $mysqli->real_escape_string($person) . "'") : 'NULL';
    $vaultEsc = $vaultId !== null ? (int)$vaultId : 'NULL';
    $amountNum = $amount + 0; // ensure numeric
    $q = "INSERT INTO `transactions` (type, category, description, amount, person, vault_id) VALUES ('{$typeEsc}', '{$catEsc}', '{$descEsc}', {$amountNum}, {$personEsc}, {$vaultEsc})";
    if (!$mysqli->query($q)) {
        json_response(['success' => false, 'message' => 'فشل حفظ المعاملة', 'details' => $mysqli->error], 500);
    }
    $id = $mysqli->insert_id;

    $row = $mysqli->query('SELECT * FROM `transactions` WHERE id = ' . (int)$id . ' LIMIT 1')->fetch_assoc();
    json_response(['success' => true, 'message' => 'تم إضافة المعاملة', 'data' => map_transaction_row($row)], 201);
}

// دفع راتب معلم
if ($method === 'POST' && $action === 'pay_teacher') {
    $teacherId = isset($input['teacherId']) ? (int)$input['teacherId'] : 0;
    $amount = isset($input['amount']) ? (float)$input['amount'] : 0;
    $note = trim($input['note'] ?? '');
    $vaultId = isset($input['vaultId']) ? (int)$input['vaultId'] : null;
    $paymentType = ($input['paymentType'] ?? 'salary') === 'advance' ? 'advance' : 'salary';
    if ($teacherId <= 0 || $amount <= 0) {
        json_response(['success' => false, 'message' => 'معرف المعلم والمبلغ مطلوبان'], 400);
    }
    $stmt = $mysqli->prepare('INSERT INTO `teacher_payments` (teacher_id, amount, payment_type, note) VALUES (?, ?, ?, ?)');
    if (!$stmt) { json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500); }
    $stmt->bind_param('idss', $teacherId, $amount, $paymentType, $note);
    if (!$stmt->execute()) { $e=$stmt->error; $stmt->close(); json_response(['success'=>false,'message'=>'فشل تسجيل الدفع','details'=>$e],500);} 
    $stmt->close();
    if ($vaultId) {
        if (!$mysqli->query('UPDATE `vaults` SET balance = balance - ' . $amount . ' WHERE id = ' . (int)$vaultId)) {
            json_response(['success' => false, 'message' => 'فشل تحديث رصيد الخزنة', 'details' => $mysqli->error], 500);
        }
    }
    $userRes = $mysqli->query('SELECT name FROM `users` WHERE id = ' . (int)$teacherId . ' LIMIT 1');
    $nameRow = $userRes ? $userRes->fetch_assoc() : ['name' => 'معلم'];
    $person = $nameRow['name'] ?? 'معلم';
    $title = $paymentType === 'advance' ? 'صرف سلفة' : 'صرف راتب';
    $q = "INSERT INTO `transactions` (type, category, description, amount, person, vault_id) VALUES ('expense','رواتب المعلمين','" . $mysqli->real_escape_string($title . ' ' . $person) . "'," . ($amount+0) . ", '" . $mysqli->real_escape_string($person) . "'," . ($vaultId!==null?(int)$vaultId:'NULL') . ")";
    if (!$mysqli->query($q)) { json_response(['success'=>false,'message'=>'فشل تسجيل المعاملة','details'=>$mysqli->error],500);} 
    json_response(['success'=>true,'message'=>'تم دفع راتب المعلم وتحديث السجلات']);
}

// تسجيل دفعة لاشتراك طالب وتحديث المتأخرات
if ($method === 'POST' && $action === 'student_payment') {
    $subscriptionId = isset($input['subscriptionId']) ? (int)$input['subscriptionId'] : 0;
    $amount = isset($input['amount']) ? (float)$input['amount'] : 0;
    $methodPay = trim($input['method'] ?? '');
    $note = trim($input['note'] ?? '');
    $vaultId = isset($input['vaultId']) ? (int)$input['vaultId'] : null;
    if ($subscriptionId <= 0 || $amount <= 0) {
        json_response(['success' => false, 'message' => 'الاشتراك والمبلغ مطلوبان'], 400);
    }
    $stmt = $mysqli->prepare('INSERT INTO `student_payments` (subscription_id, amount, method, note) VALUES (?, ?, ?, ?)');
    if (!$stmt) { json_response(['success'=>false,'message'=>'فشل في إعداد الاستعلام','details'=>$mysqli->error],500);} 
    $stmt->bind_param('idss', $subscriptionId, $amount, $methodPay, $note);
    if (!$stmt->execute()) { $e=$stmt->error; $stmt->close(); json_response(['success'=>false,'message'=>'فشل تسجيل الدفعة','details'=>$e],500);} 
    $stmt->close();
    // تحديث paid_amount
    if (!$mysqli->query('UPDATE `student_subscriptions` SET paid_amount = paid_amount + ' . ($amount+0) . ' WHERE id = ' . (int)$subscriptionId)) {
        json_response(['success'=>false,'message'=>'فشل تحديث مبلغ المدفوع','details'=>$mysqli->error],500);
    }
    if ($vaultId) {
        if (!$mysqli->query('UPDATE `vaults` SET balance = balance + ' . ($amount+0) . ' WHERE id = ' . (int)$vaultId)) {
            json_response(['success'=>false,'message'=>'فشل تحديث رصيد الخزنة','details'=>$mysqli->error],500);
        }
    }
    // سجل معاملة إيراد
    $q = "INSERT INTO `transactions` (type, category, description, amount, person, vault_id) VALUES ('income','تحصيل اشتراك','دفعة اشتراك طالب'," . ($amount+0) . ", NULL," . ($vaultId!==null?(int)$vaultId:'NULL') . ")";
    if (!$mysqli->query($q)) { json_response(['success'=>false,'message'=>'فشل تسجيل المعاملة','details'=>$mysqli->error],500);} 
    json_response(['success'=>true,'message'=>'تم تسجيل الدفعة وتحديث الاشتراك']);
}

if ($method === 'POST' && $action === 'add_expense') {
    $input['type'] = 'expense';
    $_GET['action'] = 'add_transaction';
    // delegate
}

if ($method === 'POST' && $action === 'add_deposit') {
    $input['type'] = 'income';
    $_GET['action'] = 'add_transaction';
    // delegate
}

if ($method === 'POST' && $action === 'pay_supervisor') {
    $supervisorId = isset($input['supervisorId']) ? (int)$input['supervisorId'] : 0;
    $amount = isset($input['amount']) ? (float)$input['amount'] : 0;
    $note = trim($input['note'] ?? '');
    $vaultId = isset($input['vaultId']) ? (int)$input['vaultId'] : null;
    $paymentType = ($input['paymentType'] ?? 'salary') === 'advance' ? 'advance' : 'salary';

    if ($supervisorId <= 0 || $amount <= 0) {
        json_response(['success' => false, 'message' => 'معرف المشرف والمبلغ مطلوبان'], 400);
    }

    $stmt = $mysqli->prepare('INSERT INTO `supervisor_payments` (supervisor_id, amount, payment_type, note) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    $stmt->bind_param('idss', $supervisorId, $amount, $paymentType, $note);
    if (!$stmt->execute()) {
        $error = $stmt->error; $stmt->close();
        json_response(['success' => false, 'message' => 'فشل تسجيل صرف الراتب', 'details' => $error], 500);
    }
    $stmt->close();

    // Also log as expense transaction and update vault
    if ($vaultId) {
        if (!$mysqli->query('UPDATE `vaults` SET balance = balance - ' . $amount . ' WHERE id = ' . (int)$vaultId)) {
            json_response(['success' => false, 'message' => 'فشل تحديث رصيد الخزنة', 'details' => $mysqli->error], 500);
        }
    }

    $userRes = $mysqli->query('SELECT name FROM `users` WHERE id = ' . (int)$supervisorId . ' LIMIT 1');
    $supNameRow = $userRes ? $userRes->fetch_assoc() : ['name' => 'مشرف'];
    $person = $supNameRow['name'] ?? 'مشرف';

    $typeEsc = 'expense';
    $catEsc = $mysqli->real_escape_string('رواتب المشرفين');
    $title = $paymentType === 'advance' ? 'صرف سلفة' : 'صرف راتب';
    $descEsc = $mysqli->real_escape_string($title . ' ' . $person);
    $personEsc = "'" . $mysqli->real_escape_string($person) . "'";
    $vaultEsc = $vaultId !== null ? (int)$vaultId : 'NULL';
    $amountNum = $amount + 0;
    $q = "INSERT INTO `transactions` (type, category, description, amount, person, vault_id) VALUES ('{$typeEsc}', '{$catEsc}', '{$descEsc}', {$amountNum}, {$personEsc}, {$vaultEsc})";
    if (!$mysqli->query($q)) {
        json_response(['success' => false, 'message' => 'فشل تسجيل المعاملة', 'details' => $mysqli->error], 500);
    }

    json_response(['success' => true, 'message' => 'تم صرف الراتب وتحديث السجلات']);
}

// If delegated to add_transaction
if ($method === 'POST' && ($_GET['action'] ?? null) === 'add_transaction') {
    // Re-read input body
    $raw = file_get_contents('php://input');
    $input2 = json_decode($raw, true) ?? [];
    $type = ($input2['type'] ?? 'income') === 'expense' ? 'expense' : 'income';
    $category = trim($input2['category'] ?? '');
    $description = trim($input2['description'] ?? '');
    $amount = isset($input2['amount']) ? (float)$input2['amount'] : 0;
    $person = isset($input2['person']) ? trim((string)$input2['person']) : null;
    $vaultId = isset($input2['vaultId']) ? (int)$input2['vaultId'] : null;

    if ($category === '' || $amount <= 0) {
        json_response(['success' => false, 'message' => 'التصنيف والمبلغ مطلوبان'], 400);
    }

    if ($vaultId) {
        $op = $type === 'income' ? '+' : '-';
        if (!$mysqli->query("UPDATE `vaults` SET balance = balance $op " . $amount . " WHERE id = " . (int)$vaultId)) {
            json_response(['success' => false, 'message' => 'فشل تحديث رصيد الخزنة', 'details' => $mysqli->error], 500);
        }
    }

    $typeEsc = $mysqli->real_escape_string($type);
    $catEsc = $mysqli->real_escape_string($category);
    $descEsc = $mysqli->real_escape_string($description);
    $personEsc = $person !== null ? ("'" . $mysqli->real_escape_string($person) . "'") : 'NULL';
    $vaultEsc = $vaultId !== null ? (int)$vaultId : 'NULL';
    $amountNum = $amount + 0;
    $q = "INSERT INTO `transactions` (type, category, description, amount, person, vault_id) VALUES ('{$typeEsc}', '{$catEsc}', '{$descEsc}', {$amountNum}, {$personEsc}, {$vaultEsc})";
    if (!$mysqli->query($q)) {
        json_response(['success' => false, 'message' => 'فشل حفظ المعاملة', 'details' => $mysqli->error], 500);
    }
    $id = $mysqli->insert_id;

    $row = $mysqli->query('SELECT * FROM `transactions` WHERE id = ' . (int)$id . ' LIMIT 1')->fetch_assoc();
    json_response(['success' => true, 'message' => 'تم إضافة المعاملة', 'data' => map_transaction_row($row)], 201);
}

// إرجاع مبلغ (إنشاء معاملة عكسية)
if ($method === 'POST' && $action === 'refund') {
    // دعم أكثر من اسم للحقل لزيادة التوافق مع الواجهة أو الطلبات الخارجية
    $originalId = 0;
    if (isset($input['originalId'])) {
        $originalId = (int)$input['originalId'];
    } elseif (isset($input['id'])) {
        $originalId = (int)$input['id'];
    } elseif (isset($_GET['originalId'])) {
        $originalId = (int)$_GET['originalId'];
    } elseif (isset($_GET['id'])) {
        $originalId = (int)$_GET['id'];
    }

    $amount = 0.0;
    if (isset($input['amount'])) {
        $amount = (float)$input['amount'];
    } elseif (isset($input['refundAmount'])) {
        $amount = (float)$input['refundAmount'];
    } elseif (isset($_GET['amount'])) {
        $amount = (float)$_GET['amount'];
    } elseif (isset($_GET['refundAmount'])) {
        $amount = (float)$_GET['refundAmount'];
    }
    $note = trim($input['note'] ?? '');
    
    if ($originalId <= 0 || $amount <= 0) {
        json_response(['success' => false, 'message' => 'معرف المعاملة والمبلغ مطلوبان'], 400);
    }
    
    // جلب المعاملة الأصلية
    $origRes = $mysqli->query('SELECT * FROM `transactions` WHERE id = ' . (int)$originalId . ' LIMIT 1');
    if (!$origRes || $origRes->num_rows === 0) {
        json_response(['success' => false, 'message' => 'المعاملة الأصلية غير موجودة'], 404);
    }
    $original = $origRes->fetch_assoc();

    // إذا كانت معاملة راتب، نحتاج أيضاً لتعديل سجل الرواتب حتى تنعكس في رصيد الموظف
    // ملاحظة: لا يوجد ربط مباشر في جدول transactions بين المعاملة والموظف، لذلك نعتمد على اسم الموظف في حقل person كحل افتراضي.
    $origCategory = trim((string)($original['category'] ?? ''));
    $origPerson = trim((string)($original['person'] ?? ''));
    if ($origPerson !== '') {
        if ($origCategory === 'رواتب المشرفين') {
            $nameEsc = $mysqli->real_escape_string($origPerson);
            $supRes = $mysqli->query("SELECT id FROM users WHERE role='supervisor' AND name = '$nameEsc' LIMIT 1");
            if ($supRes && $supRes->num_rows > 0) {
                $supRow = $supRes->fetch_assoc();
                $supervisorId = (int)($supRow['id'] ?? 0);
                if ($supervisorId > 0) {
                    // سجل عكسي في supervisor_payments ليتم خصمه من المدفوع هذا الشهر
                    $note2 = 'إرجاع/عكس راتب: ' . ($original['description'] ?? '');
                    if ($note !== '') { $note2 .= ' - ' . $note; }
                    $stmtPay = $mysqli->prepare('INSERT INTO supervisor_payments (supervisor_id, amount, note) VALUES (?, ?, ?)');
                    if ($stmtPay) {
                        $negAmount = -abs($amount);
                        $stmtPay->bind_param('ids', $supervisorId, $negAmount, $note2);
                        $stmtPay->execute();
                        $stmtPay->close();
                    }
                }
            }
        }

        if ($origCategory === 'رواتب المعلمين') {
            $nameEsc = $mysqli->real_escape_string($origPerson);
            $tRes = $mysqli->query("SELECT id FROM users WHERE role='teacher' AND name = '$nameEsc' LIMIT 1");
            if ($tRes && $tRes->num_rows > 0) {
                $tRow = $tRes->fetch_assoc();
                $teacherId = (int)($tRow['id'] ?? 0);
                if ($teacherId > 0) {
                    $note2 = 'إرجاع/عكس راتب: ' . ($original['description'] ?? '');
                    if ($note !== '') { $note2 .= ' - ' . $note; }
                    $stmtPay = $mysqli->prepare('INSERT INTO teacher_payments (teacher_id, amount, note) VALUES (?, ?, ?)');
                    if ($stmtPay) {
                        $negAmount = -abs($amount);
                        $stmtPay->bind_param('ids', $teacherId, $negAmount, $note2);
                        $stmtPay->execute();
                        $stmtPay->close();
                    }
                }
            }
        }
    }
    
    // نوع المعاملة العكسية
    $refundType = $original['type'] === 'income' ? 'expense' : 'income';
    $vaultId = isset($original['vault_id']) ? (int)$original['vault_id'] : null;
    
    // تحديث رصيد الخزنة (عكس العملية)
    if ($vaultId) {
        $op = $refundType === 'income' ? '+' : '-';
        if (!$mysqli->query("UPDATE `vaults` SET balance = balance $op " . $amount . " WHERE id = " . (int)$vaultId)) {
            json_response(['success' => false, 'message' => 'فشل تحديث رصيد الخزنة', 'details' => $mysqli->error], 500);
        }
    }
    
    // حذف المعاملة الأصلية حتى تختفي من قائمة المعاملات (حسب طلب الواجهة)
    $stmtDel = $mysqli->prepare('DELETE FROM `transactions` WHERE id = ?');
    if (!$stmtDel) {
        json_response(['success' => false, 'message' => 'فشل في إعداد حذف المعاملة', 'details' => $mysqli->error], 500);
    }
    $stmtDel->bind_param('i', $originalId);
    if (!$stmtDel->execute()) {
        $e = $stmtDel->error;
        $stmtDel->close();
        json_response(['success' => false, 'message' => 'فشل حذف المعاملة الأصلية', 'details' => $e], 500);
    }
    $stmtDel->close();

    json_response([
        'success' => true,
        'message' => 'تم إرجاع المبلغ وحذف المعاملة من السجل',
        'data' => ['deletedId' => (string)$originalId],
    ]);
}

// حذف معاملة
if ($method === 'POST' && $action === 'delete_transaction') {
    // نحاول أولاً قراءة المعرف من جسم الطلب، وإن لم يوجد نلجأ لمعامل GET
    $id = isset($input['id']) ? (int)$input['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف المعاملة مطلوب'], 400);
    }
    
    // جلب المعاملة قبل الحذف
    $txRes = $mysqli->query('SELECT * FROM `transactions` WHERE id = ' . (int)$id . ' LIMIT 1');
    if (!$txRes || $txRes->num_rows === 0) {
        json_response(['success' => false, 'message' => 'المعاملة غير موجودة'], 404);
    }
    $transaction = $txRes->fetch_assoc();
    $vaultId = isset($transaction['vault_id']) ? (int)$transaction['vault_id'] : null;
    $amount = (float)($transaction['amount'] ?? 0);
    $type = $transaction['type'] ?? 'income';
    
    // إرجاع الرصيد للخزنة (عكس العملية)
    if ($vaultId && $amount > 0) {
        $op = $type === 'income' ? '-' : '+';
        if (!$mysqli->query("UPDATE `vaults` SET balance = balance $op " . $amount . " WHERE id = " . (int)$vaultId)) {
            json_response(['success' => false, 'message' => 'فشل تحديث رصيد الخزنة', 'details' => $mysqli->error], 500);
        }
    }
    
    // حذف المعاملة
    $stmt = $mysqli->prepare('DELETE FROM `transactions` WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل حذف المعاملة', 'details' => $error], 500);
    }
    $stmt->close();
    
    json_response(['success' => true, 'message' => 'تم حذف المعاملة بنجاح']);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
