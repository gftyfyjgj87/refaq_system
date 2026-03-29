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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// Ensure payment columns exist
$__has_col = function(string $col) use ($mysqli): bool {
    $res = $mysqli->query("SHOW COLUMNS FROM `students` LIKE '" . $mysqli->real_escape_string($col) . "'");
    return $res && $res->num_rows > 0;
};

if (!$__has_col('paid_amount')) {
    $mysqli->query("ALTER TABLE `students` ADD COLUMN `paid_amount` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'المبلغ المدفوع' AFTER `status`");
}
if (!$__has_col('remaining_amount')) {
    $mysqli->query("ALTER TABLE `students` ADD COLUMN `remaining_amount` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'المبلغ المتبقي' AFTER `paid_amount`");
}
if (!$__has_col('balance')) {
    $mysqli->query("ALTER TABLE `students` ADD COLUMN `balance` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'رصيد الطالب الإضافي' AFTER `remaining_amount`");
}

if (!$__has_col('used_sessions')) {
    $mysqli->query("ALTER TABLE `students` ADD COLUMN `used_sessions` INT NOT NULL DEFAULT 0 COMMENT 'الحصص المستنفذة' AFTER `remaining_sessions`");
}

if (!$__has_col('group_id')) {
    $mysqli->query("ALTER TABLE `students` ADD COLUMN `group_id` INT NULL AFTER `teacher_id`");
}

if (!$__has_col('department_id')) {
    $mysqli->query("ALTER TABLE `students` ADD COLUMN `department_id` INT UNSIGNED NULL COMMENT 'القسم المختار' AFTER `group_id`");
    $mysqli->query("ALTER TABLE `students` ADD KEY idx_student_department (department_id)");
}

if (!$__has_col('age')) {
    $mysqli->query("ALTER TABLE `students` ADD COLUMN `age` INT NULL COMMENT 'عمر الطالب' AFTER `name`");
}

if (!$__has_col('gender')) {
    $mysqli->query("ALTER TABLE `students` ADD COLUMN `gender` ENUM('male', 'female') NULL COMMENT 'النوع' AFTER `age`");
}

function map_student_row(array $row): array {
    return [
        'id' => isset($row['id']) ? (string)$row['id'] : '',
        'name' => $row['name'] ?? '',
        'age' => isset($row['age']) ? (int)$row['age'] : null,
        'gender' => $row['gender'] ?? null,
        'phone' => $row['phone'] ?? '',
        'parentPhone' => $row['parent_phone'] ?? null,
        'whatsapp' => $row['whatsapp'] ?? null,
        'systemType' => $row['system_type'] ?? 'quran',
        'teacherId' => $row['teacher_id'] ?? '',
        'groupId' => isset($row['group_id']) ? (string)$row['group_id'] : null,
        'branchId' => isset($row['branch_id']) ? (string)$row['branch_id'] : null,
        'scheduleTime' => $row['schedule_time'] ?? null,
        'packageId' => $row['package_id'] ?? '',
        'remainingSessions' => isset($row['remaining_sessions']) ? (int)$row['remaining_sessions'] : 0,
        'usedSessions' => isset($row['used_sessions']) ? (int)$row['used_sessions'] : 0,
        'status' => $row['status'] ?? 'active',
        'paidAmount' => isset($row['paid_amount']) ? (float)$row['paid_amount'] : 0,
        'remainingAmount' => isset($row['remaining_amount']) ? (float)$row['remaining_amount'] : 0,
        'balance' => isset($row['balance']) ? (float)$row['balance'] : 0,
    ];
}

if ($method === 'GET') {
    // فلترة اختيارية حسب الفرع إذا تم تمرير branch_id في الاستعلام
    $branchIdFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
    $statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

    $whereConditions = [];
    $params = [];
    $types = '';

    if ($branchIdFilter > 0) {
        $whereConditions[] = 'branch_id = ?';
        $params[] = $branchIdFilter;
        $types .= 'i';
    }

    if ($statusFilter !== '' && in_array($statusFilter, ['active', 'frozen', 'archived'])) {
        $whereConditions[] = 'status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    if (count($whereConditions) > 0) {
        $sql = 'SELECT * FROM students WHERE ' . implode(' AND ', $whereConditions);
        if (!$stmt = $mysqli->prepare($sql)) {
            json_response(['success' => false, 'message' => 'فشل في جلب الطلاب'], 500);
        }
        if (count($params) > 0) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $mysqli->query('SELECT * FROM students');
    }

    if (!$result) {
        json_response(['success' => false, 'message' => 'فشل في جلب الطلاب'], 500);
    }

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = map_student_row($row);
    }

    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    json_response(['success' => true, 'data' => $students]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST' && ($action === 'create' || $action === null)) {
    $name = trim($input['name'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $parentPhone = trim(($input['parentPhone'] ?? $input['parent_phone'] ?? ''));
    $whatsapp = trim($input['whatsapp'] ?? '');
    $systemTypeIn = $input['systemType'] ?? $input['system_type'] ?? '';
    $systemType = $systemTypeIn === 'educational' ? 'educational' : 'quran';
    $teacherId = trim(($input['teacherId'] ?? $input['teacher_id'] ?? ''));
    $groupIdRaw = $input['groupId'] ?? $input['group_id'] ?? null;
    $groupId = ($groupIdRaw === '' || $groupIdRaw === null) ? null : (int)$groupIdRaw;
    $branchIdInput = $input['branchId'] ?? $input['branch_id'] ?? ($_GET['branch_id'] ?? null);
    $branchId = $branchIdInput !== null ? (int)$branchIdInput : 0;
    $scheduleTime = trim(($input['scheduleTime'] ?? $input['schedule_time'] ?? ''));
    $packageId = trim(($input['packageId'] ?? $input['package_id'] ?? ''));
    $remainingSessions = isset($input['remainingSessions']) ? (int)$input['remainingSessions'] : (isset($input['remaining_sessions']) ? (int)$input['remaining_sessions'] : 0);
    $usedSessions = isset($input['usedSessions']) ? (int)$input['usedSessions'] : (isset($input['used_sessions']) ? (int)$input['used_sessions'] : 0);
    if ($usedSessions < 0) {
        $usedSessions = 0;
    }
    $paidAmount = isset($input['paidAmount']) ? (float)$input['paidAmount'] : (isset($input['paid_amount']) ? (float)$input['paid_amount'] : 0);
    $remainingAmount = isset($input['remainingAmount']) ? (float)$input['remainingAmount'] : (isset($input['remaining_amount']) ? (float)$input['remaining_amount'] : 0);
    $balance = isset($input['balance']) ? (float)$input['balance'] : 0;
    $age = isset($input['age']) ? (int)$input['age'] : null;
    $gender = $input['gender'] ?? null;

    if ($phone === '' && $whatsapp !== '') {
        $phone = $whatsapp;
    }

    if ($name === '' || $phone === '' || $systemType === '' || $packageId === '') {
        json_response(['success' => false, 'message' => 'البيانات الأساسية مطلوبة'], 400);
    }

    // منع حجز نفس الموعد لنفس المعلم لطالب فردي نشط آخر (يسمح بتعدد الطلاب في نفس الموعد داخل نفس المجموعة)
    if ($teacherId !== '' && $scheduleTime !== '' && $groupId === null) {
        if ($confStmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM students WHERE teacher_id = ? AND schedule_time = ? AND status = \'active\' AND (group_id IS NULL OR group_id = 0)')) {
            $confStmt->bind_param('is', $teacherId, $scheduleTime);
            $confStmt->execute();
            $confRes = $confStmt->get_result();
            $confRow = $confRes ? $confRes->fetch_assoc() : null;
            $confStmt->close();
            if (!empty($confRow['cnt']) && (int)$confRow['cnt'] > 0) {
                json_response(['success' => false, 'message' => 'هذا الموعد محجوز بالفعل لهذا المعلم'], 400);
            }
        }
    }

    $stmt = $mysqli->prepare('INSERT INTO students (name, age, gender, phone, parent_phone, whatsapp, system_type, teacher_id, group_id, branch_id, schedule_time, package_id, remaining_sessions, used_sessions, status, paid_amount, remaining_amount, balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?, ?)');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام'], 500);
    }

    $teacherIdInt = ($teacherId === '' || $teacherId === null) ? 0 : (int)$teacherId;
    $stmt->bind_param(
        'sisssssiiissiiddd',
        $name,
        $age,
        $gender,
        $phone,
        $parentPhone,
        $whatsapp,
        $systemType,
        $teacherIdInt,
        $groupId,
        $branchId,
        $scheduleTime,
        $packageId,
        $remainingSessions,
        $usedSessions,
        $paidAmount,
        $remainingAmount,
        $balance
    );

    if (!$stmt->execute()) {
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل في حفظ الطالب'], 500);
    }

    $id = $stmt->insert_id;
    $stmt->close();

    // إنشاء حصة تلقائية للطالب القرآني الفردي لتظهر ضمن جدول حصص المعلم
    if ($systemType === 'quran' && ($groupId === null || $groupId === 0) && $teacherIdInt > 0 && $scheduleTime !== '') {
        // Ensure teacher_sessions has required columns (student_id, status)
        $tsStudentCol = $mysqli->query("SHOW COLUMNS FROM teacher_sessions LIKE 'student_id'");
        if ($tsStudentCol && $tsStudentCol->num_rows === 0) {
            $mysqli->query("ALTER TABLE teacher_sessions ADD COLUMN student_id INT UNSIGNED NULL AFTER group_id");
            $mysqli->query("ALTER TABLE teacher_sessions ADD KEY idx_student (student_id)");
        }
        $tsStatusCol = $mysqli->query("SHOW COLUMNS FROM teacher_sessions LIKE 'status'");
        if ($tsStatusCol && $tsStatusCol->num_rows === 0) {
            $mysqli->query("ALTER TABLE teacher_sessions ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'scheduled' AFTER hours");
        }

        // Parse schedule_time to get day name and start/end times
        $parse_first_slot = function(string $schedule): ?array {
            $first = $schedule;
            if (strpos($schedule, '|') !== false) {
                $parts = explode('|', $schedule);
                $first = trim((string)($parts[0] ?? ''));
            }
            if ($first === '') {
                return null;
            }

            // expected format: "<day> <start> - <end>" (times may include Arabic AM/PM)
            $m = [];
            if (!preg_match('/^\s*(\S+)\s+(.+?)\s*-\s*(.+?)\s*$/u', $first, $m)) {
                return null;
            }
            $day = trim((string)$m[1]);
            $start = trim((string)$m[2]);
            $end = trim((string)$m[3]);
            if ($day === '' || $start === '' || $end === '') {
                return null;
            }
            return ['day' => $day, 'start' => $start, 'end' => $end];
        };

        $to_time_24 = function(string $t): ?string {
            $t = trim($t);
            if ($t === '') return null;
            // If already HH:MM:SS or HH:MM
            if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $mm)) {
                $h = (int)$mm[1];
                $mi = (int)$mm[2];
                $s = isset($mm[3]) ? (int)$mm[3] : 0;
                return sprintf('%02d:%02d:%02d', $h, $mi, $s);
            }
            // Arabic AM/PM formats like "1:30 ص" or "1:30م"
            if (strpos($t, 'ص') !== false || strpos($t, 'م') !== false) {
                $isPm = strpos($t, 'م') !== false;
                $clean = trim(str_replace(['ص', 'م'], '', $t));
                if (preg_match('/^(\d{1,2}):(\d{2})$/', $clean, $mm2)) {
                    $h = (int)$mm2[1];
                    $mi = (int)$mm2[2];
                    if ($isPm && $h !== 12) $h += 12;
                    if (!$isPm && $h === 12) $h = 0;
                    return sprintf('%02d:%02d:00', $h, $mi);
                }
            }
            return null;
        };

        $slot = $parse_first_slot($scheduleTime);
        if ($slot) {
            $start24 = $to_time_24((string)$slot['start']);
            $end24 = $to_time_24((string)$slot['end']);
            if ($start24 && $end24) {
                $dayNames = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];
                $dayIndexMap = array_flip($dayNames);
                $targetIndex = isset($dayIndexMap[$slot['day']]) ? (int)$dayIndexMap[$slot['day']] : null;

                if ($targetIndex !== null) {
                    $todayTs = strtotime(date('Y-m-d'));
                    $weekday = (int)date('w', $todayTs); // 0=Sunday..6=Saturday
                    $todayIndex = ($weekday + 1) % 7; // Saturday=0
                    $delta = ($targetIndex - $todayIndex + 7) % 7;
                    $sessionDate = date('Y-m-d', strtotime('+' . $delta . ' day', $todayTs));

                    $startTs = strtotime('1970-01-01 ' . $start24);
                    $endTs = strtotime('1970-01-01 ' . $end24);
                    if ($startTs !== false && $endTs !== false && $endTs > $startTs) {
                        $hours = round((($endTs - $startTs) / 3600), 2);

                        $checkDate = $mysqli->real_escape_string($sessionDate);
                        $checkTime = $mysqli->real_escape_string($start24);
                        $existsSql = "SELECT id FROM teacher_sessions WHERE teacher_id=" . (int)$teacherIdInt . " AND student_id=" . (int)$id . " AND session_date='$checkDate' AND session_time='$checkTime' LIMIT 1";
                        $existsRes = $mysqli->query($existsSql);
                        if (!$existsRes || $existsRes->num_rows === 0) {
                            $ins = $mysqli->prepare('INSERT INTO teacher_sessions (teacher_id, group_id, student_id, session_date, session_time, hours, status) VALUES (?, NULL, ?, ?, ?, ?, ?)');
                            if ($ins) {
                                $status = 'scheduled';
                                $ins->bind_param('iissds', $teacherIdInt, $id, $sessionDate, $start24, $hours, $status);
                                $ins->execute();
                                $ins->close();
                            }
                        }
                    }
                }
            }
        }
    }

    // تحديث عدد الطلاب في المجموعة المرتبطة بالطالب
    if (!empty($groupId)) {
        $updateGroupStmt = $mysqli->prepare('UPDATE groups SET student_count = student_count + 1 WHERE id = ?');
        if ($updateGroupStmt) {
            $updateGroupStmt->bind_param('i', $groupId);
            $updateGroupStmt->execute();
            $updateGroupStmt->close();
        }
    } elseif (!empty($teacherId)) {
        $updateGroupStmt = $mysqli->prepare('UPDATE groups SET student_count = student_count + 1 WHERE teacher_id = ?');
        if ($updateGroupStmt) {
            $updateGroupStmt->bind_param('i', $teacherId);
            $updateGroupStmt->execute();
            $updateGroupStmt->close();
        }
    }

    $result = $mysqli->query('SELECT * FROM students WHERE id = ' . (int)$id . ' LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم إضافة الطالب بنجاح',
        'data' => $row ? map_student_row($row) : null,
    ], 201);
}

if ($method === 'POST' && $action === 'update') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $name = trim($input['name'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $parentPhone = trim(($input['parentPhone'] ?? $input['parent_phone'] ?? ''));
    $whatsapp = trim($input['whatsapp'] ?? '');
    $systemTypeIn = $input['systemType'] ?? $input['system_type'] ?? '';
    $systemType = $systemTypeIn === 'educational' ? 'educational' : 'quran';
    $teacherId = trim(($input['teacherId'] ?? $input['teacher_id'] ?? ''));
    $groupIdRaw = $input['groupId'] ?? $input['group_id'] ?? null;
    $groupId = ($groupIdRaw === '' || $groupIdRaw === null) ? null : (int)$groupIdRaw;
    $departmentIdRaw = $input['departmentId'] ?? $input['department_id'] ?? null;
    $departmentId = ($departmentIdRaw === '' || $departmentIdRaw === null) ? null : (int)$departmentIdRaw;
    $scheduleTime = trim(($input['scheduleTime'] ?? $input['schedule_time'] ?? ''));
    $packageId = trim(($input['packageId'] ?? $input['package_id'] ?? ''));
    $remainingSessions = isset($input['remainingSessions']) ? (int)$input['remainingSessions'] : (isset($input['remaining_sessions']) ? (int)$input['remaining_sessions'] : 0);
    $usedSessionsProvided = array_key_exists('usedSessions', $input) || array_key_exists('used_sessions', $input);
    $usedSessions = $usedSessionsProvided
        ? (isset($input['usedSessions']) ? (int)$input['usedSessions'] : (int)$input['used_sessions'])
        : 0;
    if (!$usedSessionsProvided) {
        if ($curStmt = $mysqli->prepare('SELECT used_sessions FROM students WHERE id = ? LIMIT 1')) {
            $curStmt->bind_param('i', $id);
            $curStmt->execute();
            $curRes = $curStmt->get_result();
            if ($curRes && ($curRow = $curRes->fetch_assoc())) {
                $usedSessions = isset($curRow['used_sessions']) ? (int)$curRow['used_sessions'] : 0;
            }
            $curStmt->close();
        }
    }
    if ($usedSessions < 0) {
        $usedSessions = 0;
    }
    $status = in_array($input['status'] ?? 'active', ['active', 'frozen', 'archived'], true)
        ? $input['status']
        : 'active';
    $paidAmount = isset($input['paidAmount']) ? (float)$input['paidAmount'] : (isset($input['paid_amount']) ? (float)$input['paid_amount'] : 0);
    $remainingAmount = isset($input['remainingAmount']) ? (float)$input['remainingAmount'] : (isset($input['remaining_amount']) ? (float)$input['remaining_amount'] : 0);

    if ($phone === '' && $whatsapp !== '') {
        $phone = $whatsapp;
    }

    // منع حجز نفس الموعد لنفس المعلم لطالب فردي نشط آخر (مع استثناء الطالب الحالي)
    if ($teacherId !== '' && $scheduleTime !== '' && $groupId === null) {
        if ($confStmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM students WHERE teacher_id = ? AND schedule_time = ? AND status = \'active\' AND (group_id IS NULL OR group_id = 0) AND id <> ?')) {
            $confStmt->bind_param('isi', $teacherId, $scheduleTime, $id);
            $confStmt->execute();
            $confRes = $confStmt->get_result();
            $confRow = $confRes ? $confRes->fetch_assoc() : null;
            $confStmt->close();
            if (!empty($confRow['cnt']) && (int)$confRow['cnt'] > 0) {
                json_response(['success' => false, 'message' => 'هذا الموعد محجوز بالفعل لهذا المعلم'], 400);
            }
        }
    }

    $stmt = $mysqli->prepare('UPDATE students SET name = ?, age = ?, gender = ?, phone = ?, parent_phone = ?, whatsapp = ?, system_type = ?, teacher_id = ?, group_id = ?, department_id = ?, branch_id = ?, schedule_time = ?, package_id = ?, remaining_sessions = ?, used_sessions = ?, status = ?, paid_amount = ?, remaining_amount = ?, balance = ? WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام'], 500);
    }

    // أنواع الحقول: name(s), phone(s), parent_phone(s), whatsapp(s), system_type(s),
    // teacher_id(i), group_id(i), branch_id(i), schedule_time(s), package_id(i),
    // remaining_sessions(i), status(s), paid_amount(d), remaining_amount(d), id(i)
    $age = isset($input['age']) ? (int)$input['age'] : null;
    $gender = $input['gender'] ?? null;
    $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : (isset($input['branchId']) ? (int)$input['branchId'] : 0);
    $balance = isset($input['balance']) ? (float)$input['balance'] : 0;

    $teacherIdInt = ($teacherId === '' || $teacherId === null) ? 0 : (int)$teacherId;
    $stmt->bind_param(
        'sisssssiiiissiisdddi',
        $name,
        $age,
        $gender,
        $phone,
        $parentPhone,
        $whatsapp,
        $systemType,
        $teacherIdInt,
        $groupId,
        $departmentId,
        $branchId,
        $scheduleTime,
        $packageId,
        $remainingSessions,
        $usedSessions,
        $status,
        $paidAmount,
        $remainingAmount,
        $balance,
        $id
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response([
            'success' => false,
            'message' => 'فشل في تحديث الطالب',
            'details' => $error,
        ], 500);
    }

    $stmt->close();

    $result = $mysqli->query('SELECT * FROM students WHERE id = ' . (int)$id . ' LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم تحديث الطالب بنجاح',
        'data' => $row ? map_student_row($row) : null,
    ]);
}

// Delete student
if ($method === 'POST' && $action === 'delete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $stmt = $mysqli->prepare('DELETE FROM students WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام'], 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json_response(['success' => false, 'message' => 'الطالب غير موجود'], 404);
    }

    json_response(['success' => true, 'message' => 'تم حذف الطالب بنجاح']);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);