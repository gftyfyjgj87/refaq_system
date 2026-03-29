<?php

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

function has_column(mysqli $mysqli, string $table, string $column): bool {
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

// Convert PHP warnings/notices into JSON 500 (instead of blank HTML 500)
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) { return false; }
    json_response([
        'success' => false,
        'message' => 'خطأ داخلي (PHP)',
        'details' => $message,
        'file' => basename($file),
        'line' => (int)$line,
    ], 500);
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// Ensure table exists
$createSql = "CREATE TABLE IF NOT EXISTS `session_attendance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` INT UNSIGNED NOT NULL,
  `student_id` INT UNSIGNED NOT NULL,
  `status` ENUM('present','absent','late','excused') NOT NULL,
  `absence_type` ENUM('before_time','after_time') NULL COMMENT 'نوع الغياب: قبل الموعد أو بعد بدء الحصة',
  `is_first_absence` TINYINT(1) DEFAULT 0 COMMENT 'هل هو أول غياب في الشهر',
  `deduct_from_student` TINYINT(1) DEFAULT 0 COMMENT 'هل يُخصم من الطالب',
  `count_for_teacher` TINYINT(1) DEFAULT 0 COMMENT 'هل يُحسب للمعلم',
  `note` TEXT NULL,
  `late_minutes` INT UNSIGNED NULL,
  `rating` INT UNSIGNED NULL,
  `tasmee3_rating` TINYINT UNSIGNED NULL COMMENT 'تقييم التسميع (0-10)',
  `tajweed_rating` TINYINT UNSIGNED NULL COMMENT 'تقييم التجويد (0-10)',
  `focus_rating` TINYINT UNSIGNED NULL COMMENT 'تقييم التركيز (0-10)',
  `behavior_rating` TINYINT UNSIGNED NULL COMMENT 'تقييم السلوك (0-10)',
  `review_rating` TINYINT UNSIGNED NULL COMMENT 'تقييم المراجعة (0-10)',
  `new_memorization_text` TEXT NULL COMMENT 'الحفظ الجديد (نص)',
  `review_text` TEXT NULL COMMENT 'المراجعة (نص)',
  `notes_text` TEXT NULL COMMENT 'ملاحظات (نص)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_session_student` (`session_id`,`student_id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_student_date` (`student_id`, `created_at`),
  CONSTRAINT `fk_sa_session` FOREIGN KEY (`session_id`) REFERENCES `teacher_sessions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sa_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
$mysqli->query($createSql);

// Add columns if table existed before
$alterStatements = [
    'tasmee3_rating' => "ALTER TABLE session_attendance ADD COLUMN tasmee3_rating TINYINT UNSIGNED NULL COMMENT 'تقييم التسميع (0-10)'",
    'tajweed_rating' => "ALTER TABLE session_attendance ADD COLUMN tajweed_rating TINYINT UNSIGNED NULL COMMENT 'تقييم التجويد (0-10)'",
    'focus_rating' => "ALTER TABLE session_attendance ADD COLUMN focus_rating TINYINT UNSIGNED NULL COMMENT 'تقييم التركيز (0-10)'",
    'behavior_rating' => "ALTER TABLE session_attendance ADD COLUMN behavior_rating TINYINT UNSIGNED NULL COMMENT 'تقييم السلوك (0-10)'",
    'review_rating' => "ALTER TABLE session_attendance ADD COLUMN review_rating TINYINT UNSIGNED NULL COMMENT 'تقييم المراجعة (0-10)'",
    'new_memorization_text' => "ALTER TABLE session_attendance ADD COLUMN new_memorization_text TEXT NULL COMMENT 'الحفظ الجديد (نص)'",
    'review_text' => "ALTER TABLE session_attendance ADD COLUMN review_text TEXT NULL COMMENT 'المراجعة (نص)'",
    'notes_text' => "ALTER TABLE session_attendance ADD COLUMN notes_text TEXT NULL COMMENT 'ملاحظات (نص)'",
];
foreach ($alterStatements as $col => $sql) {
    if (!has_column($mysqli, 'session_attendance', $col)) {
        $mysqli->query($sql);
    }
}

function map_row(array $r): array {
    return [
        'id' => (string)$r['id'],
        'sessionId' => (string)$r['session_id'],
        'studentId' => (string)$r['student_id'],
        'status' => $r['status'],
        'absenceType' => $r['absence_type'] ?? null,
        'isFirstAbsence' => (bool)($r['is_first_absence'] ?? false),
        'deductFromStudent' => (bool)($r['deduct_from_student'] ?? false),
        'countForTeacher' => (bool)($r['count_for_teacher'] ?? false),
        'note' => $r['note'],
        'lateMinutes' => isset($r['late_minutes']) ? (int)$r['late_minutes'] : null,
        'rating' => isset($r['rating']) ? (int)$r['rating'] : null,
        'tasmee3Rating' => isset($r['tasmee3_rating']) ? (int)$r['tasmee3_rating'] : null,
        'tajweedRating' => isset($r['tajweed_rating']) ? (int)$r['tajweed_rating'] : null,
        'focusRating' => isset($r['focus_rating']) ? (int)$r['focus_rating'] : null,
        'behaviorRating' => isset($r['behavior_rating']) ? (int)$r['behavior_rating'] : null,
        'reviewRating' => isset($r['review_rating']) ? (int)$r['review_rating'] : null,
        'newMemorizationText' => $r['new_memorization_text'] ?? null,
        'reviewText' => $r['review_text'] ?? null,
        'notesText' => $r['notes_text'] ?? null,
        'updatedAt' => $r['updated_at'] ?? null,
    ];
}

if ($method === 'GET') {
    if ($action === 'report') {
        $dateFrom = isset($_GET['dateFrom']) ? trim((string)$_GET['dateFrom']) : '';
        $dateTo = isset($_GET['dateTo']) ? trim((string)$_GET['dateTo']) : '';
        $teacherId = isset($_GET['teacherId']) ? (int)$_GET['teacherId'] : 0;
        $groupId = isset($_GET['groupId']) ? (int)$_GET['groupId'] : 0;
        $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';

        $allowedStatus = ['present','absent','late','excused'];
        $colDate = has_column($mysqli, 'teacher_sessions', 'date') ? 'date' : (has_column($mysqli, 'teacher_sessions', 'session_date') ? 'session_date' : 'date');
        $colTime = has_column($mysqli, 'teacher_sessions', 'time') ? 'time' : (has_column($mysqli, 'teacher_sessions', 'session_time') ? 'session_time' : 'time');
        $colTeacherId = has_column($mysqli, 'teacher_sessions', 'teacher_id') ? 'teacher_id' : (has_column($mysqli, 'teacher_sessions', 'teacherId') ? 'teacherId' : 'teacher_id');
        $colGroupId = has_column($mysqli, 'teacher_sessions', 'group_id') ? 'group_id' : (has_column($mysqli, 'teacher_sessions', 'groupId') ? 'groupId' : 'group_id');

        // Rebuild where with actual column names for date/teacher/group
        $where = [];
        if ($dateFrom !== '') { $where[] = "ts.$colDate >= '" . $mysqli->real_escape_string($dateFrom) . "'"; }
        if ($dateTo !== '') { $where[] = "ts.$colDate <= '" . $mysqli->real_escape_string($dateTo) . "'"; }
        if ($teacherId > 0) { $where[] = "ts.$colTeacherId = " . (int)$teacherId; }
        if ($groupId > 0) { $where[] = "ts.$colGroupId = " . (int)$groupId; }
        if ($status !== '' && in_array($status, $allowedStatus, true)) {
            $where[] = "sa.status = '" . $mysqli->real_escape_string($status) . "'";
        }
        $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT 
            sa.*, 
            ts.$colDate AS session_date,
            ts.$colTime AS session_time,
            ts.$colGroupId AS group_id,
            ts.$colTeacherId AS teacher_id
          FROM session_attendance sa
          JOIN teacher_sessions ts ON sa.session_id = ts.id
          $whereSql
          ORDER BY ts.$colDate DESC, ts.$colTime DESC, sa.id DESC";

        $res = $mysqli->query($sql);
        if (!$res) {
            json_response(['success' => false, 'message' => 'فشل في جلب التقرير', 'details' => $mysqli->error], 500);
        }
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $mapped = map_row($r);
            $mapped['sessionDate'] = $r['session_date'] ?? '';
            $mapped['sessionTime'] = $r['session_time'] ?? '';
            $mapped['groupId'] = isset($r['group_id']) ? (string)$r['group_id'] : null;
            $mapped['teacherId'] = isset($r['teacher_id']) ? (string)$r['teacher_id'] : null;
            $rows[] = $mapped;
        }
        json_response(['success' => true, 'data' => $rows]);
    }

    $sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    if ($sessionId <= 0) { json_response(['success'=>false,'message'=>'session_id مطلوب'], 400); }
    $res = $mysqli->query("SELECT * FROM session_attendance WHERE session_id = $sessionId ORDER BY id ASC");
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) { $rows[] = map_row($r); }
    }
    json_response(['success'=>true,'data'=>$rows]);
}


$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { $input = $_POST; }

if ($method === 'POST' && $action === 'delete') {
    try {
        $id = isset($input['id']) ? (int)$input['id'] : 0;
        $sessionId = isset($input['sessionId']) ? (int)$input['sessionId'] : 0;
        $studentId = isset($input['studentId']) ? (int)$input['studentId'] : 0;

        if ($id > 0) {
            $stmt = $mysqli->prepare('DELETE FROM session_attendance WHERE id = ?');
            if (!$stmt) { json_response(['success'=>false,'message'=>'فشل إعداد الحذف', 'details'=>$mysqli->error], 500); }
            $stmt->bind_param('i', $id);
        } else {
            if ($sessionId <= 0 || $studentId <= 0) {
                json_response(['success'=>false,'message'=>'id أو (sessionId و studentId) مطلوبين للحذف'], 400);
            }
            $stmt = $mysqli->prepare('DELETE FROM session_attendance WHERE session_id = ? AND student_id = ?');
            if (!$stmt) { json_response(['success'=>false,'message'=>'فشل إعداد الحذف', 'details'=>$mysqli->error], 500); }
            $stmt->bind_param('ii', $sessionId, $studentId);
        }

        if (!$stmt->execute()) {
            $e = $stmt->error;
            $stmt->close();
            json_response(['success'=>false,'message'=>'فشل حذف السجل', 'details'=>$e], 500);
        }
        $affected = $stmt->affected_rows;
        $stmt->close();

        json_response(['success'=>true,'message'=>'تم حذف سجل الحضور', 'data'=>['affected'=>(int)$affected]]);
    } catch (Throwable $e) {
        json_response(['success'=>false,'message'=>'خطأ داخلي', 'details'=>$e->getMessage()], 500);
    }
}

if ($method === 'POST' && $action === 'save') {
    try {
        $sessionId = isset($input['sessionId']) ? (int)$input['sessionId'] : 0;
        $records = isset($input['records']) && is_array($input['records']) ? $input['records'] : [];
        if ($sessionId <= 0) { json_response(['success'=>false,'message'=>'sessionId مطلوب'], 400); }
        if (!is_array($records) || count($records) === 0) {
            json_response(['success'=>false,'message'=>'records مطلوبة'], 400);
        }

    // الحصول على تاريخ ووقت الحصة ونوعها (فردي/جماعي)
    // ملاحظة: الحصص الفردية قد تكون group_id = NULL لذلك نستخدم LEFT JOIN
    $colDate = has_column($mysqli, 'teacher_sessions', 'date') ? 'date' : (has_column($mysqli, 'teacher_sessions', 'session_date') ? 'session_date' : 'date');
    $colTime = has_column($mysqli, 'teacher_sessions', 'time') ? 'time' : (has_column($mysqli, 'teacher_sessions', 'session_time') ? 'session_time' : 'time');
    $colGroupId = has_column($mysqli, 'teacher_sessions', 'group_id') ? 'group_id' : (has_column($mysqli, 'teacher_sessions', 'groupId') ? 'groupId' : 'group_id');

    $sessionQuery = $mysqli->query("
        SELECT ts.$colDate AS date, ts.$colTime AS time, COALESCE(g.group_type, 'individual') AS group_type
        FROM teacher_sessions ts
        LEFT JOIN `groups` g ON ts.$colGroupId = g.id
        WHERE ts.id = $sessionId
        LIMIT 1
    ");
    if (!$sessionQuery || $sessionQuery->num_rows === 0) {
        json_response(['success'=>false,'message'=>'الحصة غير موجودة'], 404);
    }
    $sessionData = $sessionQuery->fetch_assoc();
    $sessionDate = $sessionData['date'];
    $sessionTime = $sessionData['time'];
    $groupType = $sessionData['group_type'] ?? 'individual';

        $stmt = $mysqli->prepare("INSERT INTO session_attendance 
      (session_id, student_id, status, absence_type, is_first_absence, deduct_from_student, count_for_teacher, note, late_minutes, rating,
       tasmee3_rating, tajweed_rating, focus_rating, behavior_rating, review_rating,
       new_memorization_text, review_text, notes_text)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE 
        status=VALUES(status), 
        absence_type=VALUES(absence_type),
        is_first_absence=VALUES(is_first_absence),
        deduct_from_student=VALUES(deduct_from_student),
        count_for_teacher=VALUES(count_for_teacher),
        note=VALUES(note), 
        late_minutes=VALUES(late_minutes), 
        rating=VALUES(rating),
        tasmee3_rating=VALUES(tasmee3_rating),
        tajweed_rating=VALUES(tajweed_rating),
        focus_rating=VALUES(focus_rating),
        behavior_rating=VALUES(behavior_rating),
        review_rating=VALUES(review_rating),
        new_memorization_text=VALUES(new_memorization_text),
        review_text=VALUES(review_text),
        notes_text=VALUES(notes_text)");
        if (!$stmt) { json_response(['success'=>false,'message'=>'فشل إعداد الاستعلام', 'details'=>$mysqli->error], 500); }

        foreach ($records as $rec) {
            $studentId = (int)($rec['studentId'] ?? 0);
            if ($studentId <= 0) {
                $stmt->close();
                json_response(['success'=>false,'message'=>'studentId مطلوب داخل records'], 400);
            }
        $status = in_array($rec['status'] ?? '', ['present','absent','late','excused'], true) ? $rec['status'] : 'absent';
        $absenceType = isset($rec['absenceType']) && in_array($rec['absenceType'], ['before_time','after_time']) ? $rec['absenceType'] : null;
        $note = isset($rec['note']) ? (string)$rec['note'] : null;
        $late = isset($rec['lateMinutes']) ? (int)$rec['lateMinutes'] : null;
        $rating = isset($rec['rating']) ? (int)$rec['rating'] : null;

        $tasmee3Rating = isset($rec['tasmee3Rating']) ? (int)$rec['tasmee3Rating'] : null;
        $tajweedRating = isset($rec['tajweedRating']) ? (int)$rec['tajweedRating'] : null;
        $focusRating = isset($rec['focusRating']) ? (int)$rec['focusRating'] : null;
        $behaviorRating = isset($rec['behaviorRating']) ? (int)$rec['behaviorRating'] : null;
        $reviewRating = isset($rec['reviewRating']) ? (int)$rec['reviewRating'] : null;
        $newMemText = isset($rec['newMemorizationText']) ? (string)$rec['newMemorizationText'] : null;
        $reviewText = isset($rec['reviewText']) ? (string)$rec['reviewText'] : null;
        $notesText = isset($rec['notesText']) ? (string)$rec['notesText'] : null;
        
        // التحقق من وجود سجل سابق قبل الحفظ
        $oldRecordQuery = $mysqli->query("
            SELECT deduct_from_student 
            FROM session_attendance 
            WHERE session_id = $sessionId AND student_id = $studentId
            LIMIT 1
        ");
        $oldDeduct = null;
        if ($oldRecordQuery && $oldRecordQuery->num_rows > 0) {
            $oldData = $oldRecordQuery->fetch_assoc();
            $oldDeduct = (bool)$oldData['deduct_from_student'];
        }
        
        // حساب منطق الغياب
        $isFirstAbsence = 0;
        $deductFromStudent = 0;
        $countForTeacher = 0;
        
        if ($groupType === 'individual') {
            // قواعد الحلقات الفردية
            if ($status === 'present') {
                // حاضر: يُخصم من الطالب ويُحسب للمعلم
                $deductFromStudent = 1;
                $countForTeacher = 1;
            } else if ($status === 'absent' && $absenceType) {
                // التحقق من عدد الغيابات في الشهر الحالي
                $currentMonth = date('Y-m', strtotime($sessionDate));
                $colDate = has_column($mysqli, 'teacher_sessions', 'date') ? 'date' : (has_column($mysqli, 'teacher_sessions', 'session_date') ? 'session_date' : 'date');
                $absenceCountQuery = $mysqli->query("
                    SELECT COUNT(*) as count 
                    FROM session_attendance sa
                    JOIN teacher_sessions ts ON sa.session_id = ts.id
                    WHERE sa.student_id = $studentId 
                    AND sa.status = 'absent'
                    AND DATE_FORMAT(ts.$colDate, '%Y-%m') = '$currentMonth'
                    AND sa.session_id != $sessionId
                ");
                $absenceCount = 0;
                if ($absenceCountQuery) {
                    $absenceCountData = $absenceCountQuery->fetch_assoc();
                    $absenceCount = (int)$absenceCountData['count'];
                }
                
                // تحديد إذا كان أول غياب
                $isFirstAbsence = ($absenceCount === 0) ? 1 : 0;
                
                // تطبيق القواعد: أول غياب لا يُخصم، الغيابات التالية تُخصم
                if ($isFirstAbsence) {
                    // أول غياب: لا يُخصم من الطالب
                    $deductFromStudent = 0;
                } else {
                    // تكرار الغياب: يُخصم من الطالب
                    $deductFromStudent = 1;
                }
                
                // المعلم لا يُحسب له في حالة الغياب
                $countForTeacher = 0;
            }
        } else {
            // قواعد الحلقات الجماعية
            // يُخصم من الطالب سواء حضر أو غاب
            $deductFromStudent = 1;
            // تُحسب للمعلم بمجرد دخوله الحصة
            $countForTeacher = 1;
        }
        
            // Preserve nullable values as-is (store NULL in DB when not provided)
        $absenceTypeParam = $absenceType;
        $noteParam = $note;
        $lateParam = $late;
        $ratingParam = $rating;

        $tasmee3Param = $tasmee3Rating;
        $tajweedParam = $tajweedRating;
        $focusParam = $focusRating;
        $behaviorParam = $behaviorRating;
        $reviewRatingParam = $reviewRating;
        $newMemParam = $newMemText;
        $reviewTextParam = $reviewText;
        $notesTextParam = $notesText;

            $stmt->bind_param('iissiiisiiiiiiisss', 
                $sessionId, 
                $studentId, 
                $status, 
                $absenceTypeParam,
                $isFirstAbsence,
                $deductFromStudent,
                $countForTeacher,
                $noteParam, 
                $lateParam, 
                $ratingParam,
                $tasmee3Param,
                $tajweedParam,
                $focusParam,
                $behaviorParam,
                $reviewRatingParam,
                $newMemParam,
                $reviewTextParam,
                $notesTextParam
            );
        if (!$stmt->execute()) {
            $stmt->close();
            json_response(['success'=>false,'message'=>'فشل حفظ الحضور','details'=>$stmt->error], 500);
        }
        
        // تحديث رصيد الطالب بناءً على التغيير
        if ($oldDeduct !== null && $oldDeduct !== (bool)$deductFromStudent) {
            // تغيرت حالة الخصم
            $studentQuery = $mysqli->query("
                SELECT remaining_sessions, used_sessions 
                FROM students 
                WHERE id = $studentId
                LIMIT 1
            ");
            
            if ($studentQuery && $studentQuery->num_rows > 0) {
                $studentData = $studentQuery->fetch_assoc();
                $remainingSessions = (int)($studentData['remaining_sessions'] ?? 0);
                $usedSessions = (int)($studentData['used_sessions'] ?? 0);
                
                if ($deductFromStudent && !$oldDeduct) {
                    // خصم حصة جديدة
                    $newRemaining = max(0, $remainingSessions - 1);
                    $newUsed = $usedSessions + 1;
                    
                    $mysqli->query("
                        UPDATE students 
                        SET remaining_sessions = $newRemaining, 
                            used_sessions = $newUsed 
                        WHERE id = $studentId
                    ");
                } else if (!$deductFromStudent && $oldDeduct) {
                    // إعادة حصة تم خصمها سابقاً
                    $newRemaining = $remainingSessions + 1;
                    $newUsed = max(0, $usedSessions - 1);
                    
                    $mysqli->query("
                        UPDATE students 
                        SET remaining_sessions = $newRemaining, 
                            used_sessions = $newUsed 
                        WHERE id = $studentId
                    ");
                }
            }
        } else if ($oldDeduct === null && $deductFromStudent) {
            // سجل جديد ويحتاج خصم
            $studentQuery = $mysqli->query("
                SELECT remaining_sessions, used_sessions 
                FROM students 
                WHERE id = $studentId
                LIMIT 1
            ");
            
            if ($studentQuery && $studentQuery->num_rows > 0) {
                $studentData = $studentQuery->fetch_assoc();
                $remainingSessions = (int)($studentData['remaining_sessions'] ?? 0);
                $usedSessions = (int)($studentData['used_sessions'] ?? 0);
                
                $newRemaining = max(0, $remainingSessions - 1);
                $newUsed = $usedSessions + 1;
                
                $mysqli->query("
                    UPDATE students 
                    SET remaining_sessions = $newRemaining, 
                        used_sessions = $newUsed 
                    WHERE id = $studentId
                ");
            }
        }
        }
        $stmt->close();

    // Return saved
    $res = $mysqli->query("SELECT * FROM session_attendance WHERE session_id = $sessionId ORDER BY id ASC");
    $rows = [];
    if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = map_row($r); } }
        json_response(['success'=>true,'message'=>'تم حفظ الحضور', 'data'=>$rows]);
    } catch (Throwable $e) {
        json_response(['success'=>false,'message'=>'خطأ داخلي', 'details'=>$e->getMessage()], 500);
    }
}

json_response(['success'=>false,'message'=>'طلب غير مدعوم'], 405);
