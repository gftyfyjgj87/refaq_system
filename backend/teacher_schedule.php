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

// تحديد طريقة الطلب والأكشن ومدخلات الـ JSON مبكرًا قبل الاستخدام
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { $input = $_POST; }

if ($method === 'POST' && $action === 'report') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $note = isset($input['note']) ? trim((string)$input['note']) : '';
    if ($id <= 0) { json_response(['success' => false, 'message' => 'معرّف غير صالح'], 400); }
    $sql = "UPDATE teacher_sessions SET note=" . ($note !== '' ? "'" . $mysqli->real_escape_string($note) . "'" : "NULL") . " WHERE id=$id";
    if (!$mysqli->query($sql)) {
        json_response(['success' => false, 'message' => 'فشل حفظ التقرير', 'details' => $mysqli->error], 500);
    }
    $rRes = $mysqli->query("SELECT id, teacher_id, group_id, session_date, session_time, hours, status, note FROM teacher_sessions WHERE id=$id LIMIT 1");
    $row = $rRes ? $rRes->fetch_assoc() : null;
    $today = date('Y-m-d');
    $data = $row ? map_session_row($row, $today) : ['id' => (string)$id];
    json_response(['success' => true, 'data' => $data]);
}

// Ensure teacher_sessions has a status column
$colRes = $mysqli->query("SHOW COLUMNS FROM teacher_sessions LIKE 'status'");
if ($colRes && $colRes->num_rows === 0) {
    $mysqli->query("ALTER TABLE teacher_sessions ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'scheduled' AFTER hours");
}
// Ensure note column exists
$colRes2 = $mysqli->query("SHOW COLUMNS FROM teacher_sessions LIKE 'note'");
if ($colRes2 && $colRes2->num_rows === 0) {
    $mysqli->query("ALTER TABLE teacher_sessions ADD COLUMN note TEXT NULL AFTER status");
}
// Ensure amount column exists in teacher_sessions
$colResAmount = $mysqli->query("SHOW COLUMNS FROM teacher_sessions LIKE 'amount'");
if ($colResAmount && $colResAmount->num_rows === 0) {
    $mysqli->query("ALTER TABLE teacher_sessions ADD COLUMN amount DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'المبلغ المستحق للحصة' AFTER hours");
}

// Ensure hourly_rate column exists in users table
$colRes3 = $mysqli->query("SHOW COLUMNS FROM users LIKE 'hourly_rate'");
if ($colRes3 && $colRes3->num_rows === 0) {
    $mysqli->query("ALTER TABLE users ADD COLUMN hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'السعر بالساعة للمعلم' AFTER salary");
}

function get_teacher_trial_sessions(mysqli $mysqli, int $teacherId, string $from, string $to): array {
    $stmt = $mysqli->prepare("SELECT id, student_name, parent_phone, teacher_id, `date`, `time`, status, notes, system_type FROM trial_sessions WHERE teacher_id = ? AND `date` BETWEEN ? AND ? ORDER BY `date` ASC, `time` ASC");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('iss', $teacherId, $from, $to);
    $stmt->execute();
    $result = $stmt->get_result();
    $sessions = [];
    while ($r = $result->fetch_assoc()) {
        $sessions[] = [
            'id' => 'trial_' . $r['id'], // prefix to distinguish from regular sessions
            'teacherId' => (string)$r['teacher_id'],
            'groupId' => null, // trial sessions don't have groups
            'date' => $r['date'],
            'time' => $r['time'],
            'duration' => 60, // default 60 minutes for trial sessions
            'status' => $r['status'] === 'pending' ? 'scheduled' : ($r['status'] === 'converted' ? 'completed' : $r['status']),
            'note' => $r['notes'] ?? null,
            'isTrial' => true,
            'studentName' => $r['student_name'],
            'parentPhone' => $r['parent_phone'],
            'systemType' => $r['system_type'],
        ];
    }
    $stmt->close();
    return $sessions;
}

function map_session_row($r, $today) {
    $status = $r['status'] ?? 'scheduled';
    if (empty($status)) { $status = 'scheduled'; }
    // If no explicit status, infer by date
    if (!isset($r['status']) || $r['status'] === null || $r['status'] === '') {
        if (!empty($r['session_date']) && $r['session_date'] < $today) { $status = 'completed'; }
    }
    return [
        'id' => (string)$r['id'],
        'teacherId' => isset($r['teacher_id']) ? (string)$r['teacher_id'] : null,
        'groupId' => isset($r['group_id']) ? (string)$r['group_id'] : null,
        'studentId' => isset($r['student_id']) ? (string)$r['student_id'] : null,
        'date' => $r['session_date'],
        'time' => $r['session_time'] ?? null,
        'duration' => (int)round(((float)($r['hours'] ?? 0)) * 60),
        'status' => $status,
        'note' => $r['note'] ?? null,
        'isTrial' => false,
    ];
}

/**
 * توليد الحصص تلقائياً لمعلم معيّن ضمن فترة محددة اعتماداً على مواعيد المجموعات
 */
function ensure_teacher_sessions_for_range(mysqli $mysqli, int $teacherId, string $from, string $to): void {
    // قراءة مواعيد المجموعات الخاصة بالمعلم مع مدة الحصة
    $query = "SELECT id, teacher_id, days_json, time, schedule, session_duration FROM `groups` WHERE teacher_id = $teacherId";
    $result = $mysqli->query($query);
    if (!$result) {
        return;
    }

    // بناء خريطة اليوم -> قائمة (groupId, time, duration)
    $scheduleByDay = [];
    $groupDurations = []; // خريطة groupId -> duration

    while ($row = $result->fetch_assoc()) {
        $groupId = isset($row['id']) ? (int)$row['id'] : 0;
        $scheduleText = $row['schedule'] ?? '';
        $duration = isset($row['session_duration']) ? (float)$row['session_duration'] : 1.0;
        $groupDurations[$groupId] = $duration;

        if ($scheduleText && strpos($scheduleText, '|') !== false) {
            // النظام الجديد: "day time | day time"
            $slots = explode(' | ', $scheduleText);
            foreach ($slots as $slot) {
                $parts = explode(' ', trim($slot), 2);
                if (count($parts) >= 2) {
                    $day = trim($parts[0]);
                    $time = trim($parts[1]);
                    if ($day === '' || $time === '') continue;
                    if (!isset($scheduleByDay[$day])) {
                        $scheduleByDay[$day] = [];
                    }
                    $scheduleByDay[$day][] = ['groupId' => $groupId, 'time' => $time, 'duration' => $duration];
                }
            }
        } else {
            // النظام القديم: days_json + time واحد
            $days = [];
            if (!empty($row['days_json'])) {
                $decoded = json_decode($row['days_json'], true);
                if (is_array($decoded)) {
                    $days = $decoded;
                }
            }
            $time = isset($row['time']) ? trim((string)$row['time']) : '';
            if ($time !== '' && !empty($days)) {
                foreach ($days as $day) {
                    $day = trim((string)$day);
                    if ($day === '') continue;
                    if (!isset($scheduleByDay[$day])) {
                        $scheduleByDay[$day] = [];
                    }
                    $scheduleByDay[$day][] = ['groupId' => $groupId, 'time' => $time, 'duration' => $duration];
                }
            }
        }
    }

    if (empty($scheduleByDay)) {
        return;
    }

    $dayNames = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];

    // حلقة على كل يوم في الفترة [from, to]
    $current = strtotime($from);
    $end = strtotime($to);
    if ($current === false || $end === false) {
        return;
    }

    while ($current <= $end) {
        $date = date('Y-m-d', $current);
        $dayOfWeek = date('w', $current); // 0 = الأحد، 6 = السبت
        $dayIndex = ((int)$dayOfWeek + 1) % 7; // تحويل إلى نظام السبت = 0
        $dayName = $dayNames[$dayIndex] ?? null;

        if ($dayName && isset($scheduleByDay[$dayName])) {
            foreach ($scheduleByDay[$dayName] as $slot) {
                $groupId = (int)$slot['groupId'];
                $time = trim((string)$slot['time']);
                $duration = isset($slot['duration']) ? (float)$slot['duration'] : 1.0;
                if ($time === '') continue;

                // توحيد صيغة الوقت (نفس منطق groups.php إن لزم)
                $timeFormatted = $time;
                if (strpos($time, 'ص') !== false || strpos($time, 'م') !== false) {
                    $timeParts = explode(':', str_replace(['ص', 'م'], '', $time));
                    if (count($timeParts) >= 2) {
                        $hour = (int)$timeParts[0];
                        $minute = (int)$timeParts[1];
                        if (strpos($time, 'م') !== false && $hour !== 12) {
                            $hour += 12;
                        } elseif (strpos($time, 'ص') !== false && $hour === 12) {
                            $hour = 0;
                        }
                        $timeFormatted = sprintf('%02d:%02d:00', $hour, $minute);
                    }
                }

                // تجنب التكرار: تحقق إن كانت الحصة موجودة بالفعل
                $checkDate = $mysqli->real_escape_string($date);
                $checkTime = $mysqli->real_escape_string($timeFormatted);
                $existsSql = "SELECT id FROM teacher_sessions WHERE teacher_id=$teacherId AND group_id=$groupId AND session_date='$checkDate' AND session_time='$checkTime' LIMIT 1";
                $existsRes = $mysqli->query($existsSql);
                if ($existsRes && $existsRes->num_rows > 0) {
                    continue;
                }

                $insertStmt = $mysqli->prepare('INSERT INTO teacher_sessions (teacher_id, group_id, session_date, session_time, hours, status) VALUES (?, ?, ?, ?, ?, ?)');
                if ($insertStmt) {
                    $hours = $duration; // استخدام مدة الحصة من المجموعة
                    $status = 'scheduled';
                    $insertStmt->bind_param('iissds', $teacherId, $groupId, $date, $timeFormatted, $hours, $status);
                    $insertStmt->execute();
                    $insertStmt->close();
                }
            }
        }

        $current = strtotime('+1 day', $current);
    }
}

if ($method === 'GET') {
    $teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

    $today = date('Y-m-d');
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-t');

    $fromEsc = $mysqli->real_escape_string($from);
    $toEsc = $mysqli->real_escape_string($to);

    // Admin overview: when teacher_id is not provided, return sessions for all teachers (no auto-generation)
    if ($teacherId <= 0) {
        $sql = "SELECT id, teacher_id, group_id, student_id, session_date, session_time, hours, status, note
                FROM teacher_sessions
                WHERE session_date BETWEEN '$fromEsc' AND '$toEsc'
                ORDER BY session_date ASC, session_time ASC, id ASC";
        $res = $mysqli->query($sql);
        if (!$res) {
            json_response(['success' => false, 'message' => 'فشل جلب الحصص', 'details' => $mysqli->error], 500);
        }
        $sessions = [];
        $groupIds = [];
        while ($r = $res->fetch_assoc()) {
            $sessions[] = map_session_row($r, $today);
            if (!empty($r['group_id'])) { $groupIds[(string)$r['group_id']] = true; }
        }

        $groups = [];
        if (!empty($groupIds)) {
            $ids = implode(',', array_map('intval', array_keys($groupIds)));
            $gRes = $mysqli->query("SELECT id, name, level, student_count, time FROM groups WHERE id IN ($ids)");
            if ($gRes) {
                while ($g = $gRes->fetch_assoc()) {
                    $groups[] = [
                        'id' => (string)$g['id'],
                        'name' => $g['name'] ?? '',
                        'level' => $g['level'] ?? '',
                        'studentCount' => isset($g['student_count']) ? (int)$g['student_count'] : 0,
                        'time' => $g['time'] ?? null,
                    ];
                }
            }
        }

        json_response(['success' => true, 'data' => ['sessions' => $sessions, 'groups' => $groups]]);
    }

    // الاستعلام الأول عن الحصص الموجودة (بما في ذلك student_id إن وجد)
    $sql = "SELECT id, teacher_id, group_id, student_id, session_date, session_time, hours, status, note
            FROM teacher_sessions
            WHERE teacher_id = $teacherId AND session_date BETWEEN '$fromEsc' AND '$toEsc'
            ORDER BY session_date ASC, session_time ASC, id ASC";
    $res = $mysqli->query($sql);
    if (!$res) {
        json_response(['success' => false, 'message' => 'فشل جلب الحصص', 'details' => $mysqli->error], 500);
    }
    $sessions = [];
    $groupIds = [];
    while ($r = $res->fetch_assoc()) {
        $sessions[] = map_session_row($r, $today);
        if (!empty($r['group_id'])) { $groupIds[(string)$r['group_id']] = true; }
    }

    // إذا لم توجد أي حصص في الفترة المطلوبة، ننشئها من مواعيد المجموعات ثم نعيد التحميل
    if (empty($sessions)) {
        ensure_teacher_sessions_for_range($mysqli, $teacherId, $fromEsc, $toEsc);

        // إعادة الاستعلام بعد التوليد
        $res = $mysqli->query($sql);
        if (!$res) {
            json_response(['success' => false, 'message' => 'فشل جلب الحصص بعد التوليد', 'details' => $mysqli->error], 500);
        }
        $sessions = [];
        $groupIds = [];
        while ($r = $res->fetch_assoc()) {
            $sessions[] = map_session_row($r, $today);
            if (!empty($r['group_id'])) { $groupIds[(string)$r['group_id']] = true; }
        }
    }

    // groups info (only those referenced)
    $groups = [];
    if (!empty($groupIds)) {
        $ids = implode(',', array_map('intval', array_keys($groupIds)));
        $gRes = $mysqli->query("SELECT id, name, level, student_count, time FROM groups WHERE id IN ($ids)");
        if ($gRes) {
            while ($g = $gRes->fetch_assoc()) {
                $groups[] = [
                    'id' => (string)$g['id'],
                    'name' => $g['name'] ?? '',
                    'level' => $g['level'] ?? '',
                    'studentCount' => isset($g['student_count']) ? (int)$g['student_count'] : 0,
                    'time' => $g['time'] ?? null,
                ];
            }
        }
    }

    // جلب أسماء الطلاب للحصص الفردية (student_id موجود) بدون التأثير على الجلسات التجريبية
    $studentIds = [];
    foreach ($sessions as $s) {
        if (!empty($s['studentId']) && empty($s['isTrial'])) {
            $studentIds[(int)$s['studentId']] = true;
        }
    }
    $studentNames = [];
    if (!empty($studentIds)) {
        $ids = implode(',', array_map('intval', array_keys($studentIds)));
        $stuRes = $mysqli->query("SELECT id, name FROM students WHERE id IN ($ids)");
        if ($stuRes) {
            while ($row = $stuRes->fetch_assoc()) {
                $sid = isset($row['id']) ? (int)$row['id'] : 0;
                if ($sid > 0) {
                    $studentNames[(string)$sid] = $row['name'] ?? '';
                }
            }
        }
    }
    if (!empty($studentNames)) {
        foreach ($sessions as &$s) {
            if (!empty($s['studentId']) && empty($s['isTrial'])) {
                $sid = (string)$s['studentId'];
                if (isset($studentNames[$sid])) {
                    $s['studentName'] = $studentNames[$sid];
                }
            }
        }
        unset($s);
    }

    // جلب الجلسات التجريبية للمعلم وإضافتها للحصص
    $trialSessions = get_teacher_trial_sessions($mysqli, $teacherId, $fromEsc, $toEsc);
    if (!empty($trialSessions)) {
        // دمج الحصص العادية مع الجلسات التجريبية
        $sessions = array_merge($sessions, $trialSessions);
        // ترتيب الحصص حسب التاريخ والوقت
        usort($sessions, function($a, $b) {
            $dateCmp = strcmp($a['date'], $b['date']);
            if ($dateCmp !== 0) return $dateCmp;
            return strcmp($a['time'], $b['time']);
        });
    }

    json_response(['success' => true, 'data' => ['sessions' => $sessions, 'groups' => $groups]]);
}

if ($method === 'POST' && $action === 'complete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) { json_response(['success' => false, 'message' => 'معرّف غير صالح'], 400); }
    
    $note = isset($input['note']) ? trim((string)$input['note']) : '';
    
    // جلب بيانات الحصة لحساب المبلغ
    $sessionQuery = $mysqli->query("SELECT teacher_id, group_id, hours FROM teacher_sessions WHERE id=$id LIMIT 1");
    if (!$sessionQuery || $sessionQuery->num_rows === 0) {
        json_response(['success' => false, 'message' => 'الحصة غير موجودة'], 404);
    }
    $sessionData = $sessionQuery->fetch_assoc();
    $teacherId = (int)$sessionData['teacher_id'];
    $groupId = isset($sessionData['group_id']) ? (int)$sessionData['group_id'] : null;
    $hours = (float)$sessionData['hours'];
    
    // جلب السعر بالساعة للمعلم
    $teacherQuery = $mysqli->query("SELECT hourly_rate FROM users WHERE id=$teacherId LIMIT 1");
    $hourlyRate = 0;
    if ($teacherQuery && $teacherQuery->num_rows > 0) {
        $teacherData = $teacherQuery->fetch_assoc();
        $hourlyRate = isset($teacherData['hourly_rate']) ? (float)$teacherData['hourly_rate'] : 0;
    }
    
    // حساب المبلغ بناءً على مدة الحصة والسعر بالساعة
    $calculatedAmount = $hourlyRate * $hours;
    
    // تحديث حالة الحصة وتخزين المبلغ المحسوب
    $sql = "UPDATE teacher_sessions SET status='completed', amount=" . (float)$calculatedAmount . ($note !== '' ? ", note='" . $mysqli->real_escape_string($note) . "'" : '') . " WHERE id=$id";
    if (!$mysqli->query($sql)) {
        json_response(['success' => false, 'message' => 'فشل تحديث الحصة', 'details' => $mysqli->error], 500);
    }
    
    // تسجيل المبلغ في جدول المدفوعات أو السجل المالي
    // يمكن إضافة كود هنا لتسجيل المبلغ في جدول teacher_payments أو finance
    
    // إرسال إشعار للإدارة
    $groupName = 'حصة فردية';
    if ($groupId) {
        $groupQuery = $mysqli->query("SELECT name FROM `groups` WHERE id=$groupId LIMIT 1");
        if ($groupQuery && $groupQuery->num_rows > 0) {
            $groupData = $groupQuery->fetch_assoc();
            $groupName = $groupData['name'];
        }
    }
    
    $teacherNameQuery = $mysqli->query("SELECT name FROM users WHERE id=$teacherId LIMIT 1");
    $teacherName = 'معلم';
    if ($teacherNameQuery && $teacherNameQuery->num_rows > 0) {
        $teacherNameData = $teacherNameQuery->fetch_assoc();
        $teacherName = $teacherNameData['name'];
    }
    
    $notificationTitle = '✅ إكمال حصة';
    $durationText = $hours == 0.5 ? 'نصف ساعة' : ($hours == 1 ? 'ساعة' : "$hours ساعة");
    $notificationMessage = "قام المعلم $teacherName بإكمال حصة $groupName. المبلغ المستحق: " . number_format($calculatedAmount, 2) . " ج.م (مدة الحصة: $durationText)";
    
    $notificationStmt = $mysqli->prepare("INSERT INTO notifications (title, message, type, priority, target_role, related_id, related_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $notificationType = 'session_completed';
    $priority = 'normal';
    $targetRole = 'admin';
    $relatedType = 'teacher_session';
    $notificationStmt->bind_param('sssssss', $notificationTitle, $notificationMessage, $notificationType, $priority, $targetRole, $id, $relatedType);
    $notificationStmt->execute();
    $notificationStmt->close();
    
    // Return updated row
    $rRes = $mysqli->query("SELECT id, teacher_id, group_id, session_date, session_time, hours, status, note FROM teacher_sessions WHERE id=$id LIMIT 1");
    $row = $rRes ? $rRes->fetch_assoc() : null;
    $today = date('Y-m-d');
    $data = $row ? map_session_row($row, $today) : ['id' => (string)$id, 'status' => 'completed'];
    $data['amount'] = $calculatedAmount;
    $data['hours'] = $hours;
    
    json_response(['success' => true, 'data' => $data]);
}

if ($method === 'POST' && $action === 'start') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) { json_response(['success' => false, 'message' => 'معرّف غير صالح'], 400); }
    // For now, ensure status is at least 'scheduled'
    $sql = "UPDATE teacher_sessions SET status='scheduled' WHERE id=$id";
    $mysqli->query($sql);
    $rRes = $mysqli->query("SELECT id, teacher_id, group_id, session_date, session_time, hours, status FROM teacher_sessions WHERE id=$id LIMIT 1");
    $row = $rRes ? $rRes->fetch_assoc() : null;
    $today = date('Y-m-d');
    $data = $row ? map_session_row($row, $today) : ['id' => (string)$id, 'status' => 'scheduled'];
    json_response(['success' => true, 'data' => $data]);
}

if ($method === 'POST' && $action === 'cancel') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) { json_response(['success' => false, 'message' => 'معرّف غير صالح'], 400); }
    $note = isset($input['note']) ? trim((string)$input['note']) : '';
    $sql = "UPDATE teacher_sessions SET status='cancelled', amount=0" . ($note !== '' ? ", note='" . $mysqli->real_escape_string($note) . "'" : '') . " WHERE id=$id";
    if (!$mysqli->query($sql)) {
        json_response(['success' => false, 'message' => 'فشل إلغاء الحصة', 'details' => $mysqli->error], 500);
    }
    $rRes = $mysqli->query("SELECT id, teacher_id, group_id, session_date, session_time, hours, status FROM teacher_sessions WHERE id=$id LIMIT 1");
    $row = $rRes ? $rRes->fetch_assoc() : null;
    $today = date('Y-m-d');
    $data = $row ? map_session_row($row, $today) : ['id' => (string)$id, 'status' => 'cancelled'];
    json_response(['success' => true, 'data' => $data]);
}

if ($method === 'POST' && $action === 'incomplete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) { json_response(['success' => false, 'message' => 'معرّف غير صالح'], 400); }
    $note = isset($input['note']) ? trim((string)$input['note']) : '';
    
    // تحديث حالة الحصة إلى "غير مكتملة" بدون إضافة مبلغ
    $sql = "UPDATE teacher_sessions SET status='incomplete', amount=0" . ($note !== '' ? ", note='" . $mysqli->real_escape_string($note) . "'" : '') . " WHERE id=$id";
    if (!$mysqli->query($sql)) {
        json_response(['success' => false, 'message' => 'فشل تحديث الحصة', 'details' => $mysqli->error], 500);
    }
    
    // إرسال إشعار للإدارة
    $sessionQuery = $mysqli->query("SELECT teacher_id, group_id FROM teacher_sessions WHERE id=$id LIMIT 1");
    if ($sessionQuery && $sessionQuery->num_rows > 0) {
        $sessionData = $sessionQuery->fetch_assoc();
        $teacherId = (int)$sessionData['teacher_id'];
        $groupId = isset($sessionData['group_id']) ? (int)$sessionData['group_id'] : null;
        
        $groupName = 'حصة فردية';
        if ($groupId) {
            $groupQuery = $mysqli->query("SELECT name FROM `groups` WHERE id=$groupId LIMIT 1");
            if ($groupQuery && $groupQuery->num_rows > 0) {
                $groupData = $groupQuery->fetch_assoc();
                $groupName = $groupData['name'];
            }
        }
        
        $teacherNameQuery = $mysqli->query("SELECT name FROM users WHERE id=$teacherId LIMIT 1");
        $teacherName = 'معلم';
        if ($teacherNameQuery && $teacherNameQuery->num_rows > 0) {
            $teacherNameData = $teacherNameQuery->fetch_assoc();
            $teacherName = $teacherNameData['name'];
        }
        
        $notificationTitle = '⚠️ حصة غير مكتملة';
        $notificationMessage = "قام المعلم $teacherName بتسجيل حصة $groupName كـ \"غير مكتملة\". لم يتم إضافة مبلغ لرصيد المعلم.";
        
        $notificationStmt = $mysqli->prepare("INSERT INTO notifications (title, message, type, priority, target_role, related_id, related_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $notificationType = 'session_incomplete';
        $priority = 'normal';
        $targetRole = 'admin';
        $relatedType = 'teacher_session';
        $notificationStmt->bind_param('sssssss', $notificationTitle, $notificationMessage, $notificationType, $priority, $targetRole, $id, $relatedType);
        $notificationStmt->execute();
        $notificationStmt->close();
    }
    
    $rRes = $mysqli->query("SELECT id, teacher_id, group_id, session_date, session_time, hours, status FROM teacher_sessions WHERE id=$id LIMIT 1");
    $row = $rRes ? $rRes->fetch_assoc() : null;
    $today = date('Y-m-d');
    $data = $row ? map_session_row($row, $today) : ['id' => (string)$id, 'status' => 'incomplete'];
    json_response(['success' => true, 'data' => $data]);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
