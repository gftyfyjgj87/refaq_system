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

function map_group_row(array $row): array {
    return [
        'id' => isset($row['id']) ? (string)$row['id'] : '',
        'name' => $row['name'] ?? '',
        'level' => $row['level'] ?? '',
        'systemType' => $row['system_type'] ?? 'educational',
        'courseId' => isset($row['course_id']) ? (string)$row['course_id'] : null,
        'currentLesson' => isset($row['current_lesson']) ? (int)$row['current_lesson'] : 1,
        'teacherId' => isset($row['teacher_id']) ? (string)$row['teacher_id'] : '',
        'schedule' => $row['schedule'] ?? '',
        'studentCount' => isset($row['student_count']) ? (int)$row['student_count'] : 0,
        'maxStudents' => isset($row['max_students']) ? (int)$row['max_students'] : 15,
        'ageFrom' => isset($row['age_from']) ? (int)$row['age_from'] : null,
        'ageTo' => isset($row['age_to']) ? (int)$row['age_to'] : null,
        'gender' => $row['gender'] ?? 'mixed',
        'groupType' => $row['group_type'] ?? 'group',
        'sessionDuration' => isset($row['session_duration']) ? (float)$row['session_duration'] : 1.0,
    ];
}

// Ensure required columns exist on `groups` table (idempotent guards)
// Helper to check column existence
$__has_col = function(string $col) use ($mysqli): bool {
    $res = $mysqli->query("SHOW COLUMNS FROM `groups` LIKE '" . $mysqli->real_escape_string($col) . "'");
    return $res && $res->num_rows > 0;
};

// Add columns if missing
if (!$__has_col('system_type')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `system_type` ENUM('quran','educational') NOT NULL DEFAULT 'educational' COMMENT 'نوع النظام' AFTER `level`");
}

if (!$__has_col('course_id')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `course_id` INT UNSIGNED NULL AFTER `level`");
}
if (!$__has_col('current_lesson')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `current_lesson` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `course_id`");
}
if (!$__has_col('days_json')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `days_json` JSON NULL AFTER `teacher_id`");
}
if (!$__has_col('time')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `time` VARCHAR(20) NULL AFTER `days_json`");
}
if (!$__has_col('schedule')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `schedule` VARCHAR(255) NULL AFTER `time`");
}
if (!$__has_col('student_count')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `student_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `schedule`");
}
if (!$__has_col('max_students')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `max_students` INT UNSIGNED NOT NULL DEFAULT 15 AFTER `student_count`");
}
if (!$__has_col('age_from')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `age_from` INT UNSIGNED NULL COMMENT 'السن من' AFTER `max_students`");
}
if (!$__has_col('age_to')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `age_to` INT UNSIGNED NULL COMMENT 'السن إلى' AFTER `age_from`");
}
if (!$__has_col('gender')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `gender` ENUM('male','female','mixed') NOT NULL DEFAULT 'mixed' COMMENT 'نوع المجموعة (بنين/بنات/مشترك)' AFTER `age_to`");
}
if (!$__has_col('group_type')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `group_type` ENUM('individual','group') NOT NULL DEFAULT 'group' COMMENT 'نوع الحلقة: فردي أو جماعي' AFTER `gender`");
}
if (!$__has_col('session_duration')) {
    $mysqli->query("ALTER TABLE `groups` ADD COLUMN `session_duration` DECIMAL(4,2) NOT NULL DEFAULT 1.00 COMMENT 'مدة الحصة بالساعات' AFTER `group_type`");
}

if ($method === 'GET') {
    $result = $mysqli->query("SELECT * FROM `groups` ORDER BY id DESC");
    if (!$result) {
        json_response(['success' => false, 'message' => 'فشل في جلب المجموعات', 'details' => $mysqli->error], 500);
    }

    $groups = [];
    while ($row = $result->fetch_assoc()) {
        $groups[] = map_group_row($row);
    }

    json_response(['success' => true, 'data' => $groups]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST' && $action === 'create') {
    $name = trim($input['name'] ?? '');
    $level = trim($input['level'] ?? '');
    // اجعل قيم الأعداد الصحيحة صفراً عند عدم التحديد لتتوافق مع bind_param من نوع i
    $courseId = isset($input['courseId']) && $input['courseId'] !== '' ? (int)$input['courseId'] : 0;
    $teacherId = isset($input['teacherId']) && $input['teacherId'] !== '' && $input['teacherId'] !== null ? (int)$input['teacherId'] : 0;
    $days = isset($input['days']) && is_array($input['days']) ? $input['days'] : [];
    $dayTimeSlots = isset($input['dayTimeSlots']) && is_array($input['dayTimeSlots']) ? $input['dayTimeSlots'] : [];
    $schedule = trim($input['schedule'] ?? '');
    $maxStudents = isset($input['maxStudents']) ? (int)$input['maxStudents'] : 15;
    $ageFrom = isset($input['ageFrom']) && $input['ageFrom'] !== '' ? (int)$input['ageFrom'] : 0;
    $ageTo = isset($input['ageTo']) && $input['ageTo'] !== '' ? (int)$input['ageTo'] : 0;
    $genderIn = $input['gender'] ?? 'mixed';
    $gender = in_array($genderIn, ['male','female','mixed'], true) ? $genderIn : 'mixed';
    $sessionDuration = isset($input['sessionDuration']) ? (float)$input['sessionDuration'] : 1.0;

    if ($name === '' || $level === '' || $teacherId <= 0 || empty($days)) {
        json_response(['success' => false, 'message' => 'الاسم والمستوى والمعلم والأيام مطلوبة'], 400);
    }

    // التحقق من عدم وجود تعارض في مواعيد المعلم (نفس اليوم+الوقت في مجموعة أخرى)
    if (!empty($dayTimeSlots) && $teacherId > 0) {
        $conflictSlots = [];
        foreach ($dayTimeSlots as $day => $times) {
            if (!is_array($times)) {
                $times = [$times];
            }
            foreach ($times as $time) {
                $time = trim((string)$time);
                if ($time === '') { continue; }
                $slot = $day . ' ' . $time;

                $like = '%' . $mysqli->real_escape_string($slot) . '%';
                $checkStmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM `groups` WHERE teacher_id = ? AND schedule LIKE ?');
                if ($checkStmt) {
                    $checkStmt->bind_param('is', $teacherId, $like);
                    $checkStmt->execute();
                    $res = $checkStmt->get_result();
                    $row = $res ? $res->fetch_assoc() : null;
                    $checkStmt->close();
                    if ($row && (int)$row['cnt'] > 0) {
                        $conflictSlots[] = $slot;
                    }
                }
            }
        }

        if (!empty($conflictSlots)) {
            json_response([
                'success' => false,
                'message' => 'هذا المعلم لديه مجموعات أخرى في نفس المواعيد: ' . implode(' ، ', $conflictSlots)
            ], 400);
        }
    }

    // إنشاء الجدول إذا لم يتم تمريره
    if (empty($schedule) && !empty($dayTimeSlots)) {
        $scheduleArray = [];
        foreach ($dayTimeSlots as $day => $times) {
            if (is_array($times)) {
                foreach ($times as $time) {
                    $time = trim((string)$time);
                    if ($time !== '') { $scheduleArray[] = $day . ' ' . $time; }
                }
            } else {
                $time = trim((string)$times);
                if ($time !== '') { $scheduleArray[] = $day . ' ' . $time; }
            }
        }
        $schedule = implode(' | ', $scheduleArray);
    }

    $stmt = $mysqli->prepare('INSERT INTO `groups` (name, level, course_id, teacher_id, days_json, time, schedule, student_count, max_students, current_lesson, age_from, age_to, gender, session_duration) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 1, ?, ?, ?, ?)');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $daysJson = json_encode($days, JSON_UNESCAPED_UNICODE);
    $dayTimeSlotsJson = json_encode($dayTimeSlots, JSON_UNESCAPED_UNICODE);
    $sessionDuration = isset($input['sessionDuration']) ? (float)$input['sessionDuration'] : 1.0;
    // 8 نصوص (name, level, days_json, time, schedule, gender) + 2 أعداد صحيحة (course_id, teacher_id) + 2 أعداد صحيحة (max_students, age_from, age_to) + عدد عشري لمدة الحصة
    // الترتيب: s (name), s (level), i (courseId), i (teacherId), s (daysJson), s (dayTimeSlotsJson), s (schedule), i (maxStudents), i (ageFrom), i (ageTo), s (gender), d (sessionDuration)
    $stmt->bind_param('ssiisssiiisd', $name, $level, $courseId, $teacherId, $daysJson, $dayTimeSlotsJson, $schedule, $maxStudents, $ageFrom, $ageTo, $gender, $sessionDuration);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل في إنشاء المجموعة', 'details' => $error], 500);
    }

    $id = $stmt->insert_id;
    $stmt->close();

    // إنشاء الحصص تلقائياً للمجموعة الجديدة
    if (!empty($dayTimeSlots) && $teacherId > 0) {
        $today = date('Y-m-d');
        $dayNames = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];
        $dayMap = array_flip($dayNames);
        
        // إنشاء حصص لمدة 3 أشهر من اليوم
        for ($i = 0; $i < 90; $i++) {
            $date = date('Y-m-d', strtotime("+$i days", strtotime($today)));
            $dayOfWeek = date('w', strtotime($date)); // 0 = الأحد، 6 = السبت
            
            // تحويل من رقم اليوم إلى اسم اليوم العربي
            $dayIndex = ($dayOfWeek + 1) % 7; // تحويل إلى نظام السبت = 0
            $dayName = $dayNames[$dayIndex] ?? null;
            
            if ($dayName && isset($dayTimeSlots[$dayName])) {
                foreach ($dayTimeSlots[$dayName] as $time) {
                    $time = trim((string)$time);
                    if ($time !== '') {
                        // تحويل الوقت من صيغة عربية إلى صيغة 24 ساعة
                        $timeFormatted = $time;
                        if (strpos($time, 'ص') !== false || strpos($time, 'م') !== false) {
                            // تحويل من صيغة عربية
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
                        
                        // إدراج الحصة
                        $insertStmt = $mysqli->prepare('INSERT INTO teacher_sessions (teacher_id, group_id, session_date, session_time, hours, status) VALUES (?, ?, ?, ?, ?, ?)');
                        if ($insertStmt) {
                            $hours = $sessionDuration; // استخدام مدة الحصة من المجموعة
                            $status = 'scheduled';
                            $insertStmt->bind_param('iissds', $teacherId, $id, $date, $timeFormatted, $hours, $status);
                            $insertStmt->execute();
                            $insertStmt->close();
                        }
                    }
                }
            }
        }
    }

    $result = $mysqli->query('SELECT * FROM `groups` WHERE id = ' . (int)$id . ' LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم إنشاء المجموعة بنجاح',
        'data' => $row ? map_group_row($row) : null,
    ], 201);
}

if ($method === 'POST' && $action === 'update') {
    // قراءة id بشكل صحيح - تحويل string إلى int
    $id = 0;
    if (isset($input['id']) && is_numeric($input['id']) && $input['id'] !== '') {
        $id = (int)$input['id'];
    }
    
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $name = trim($input['name'] ?? '');
    $level = trim($input['level'] ?? '');
    $courseId = isset($input['courseId']) && $input['courseId'] !== '' ? (int)$input['courseId'] : null;
    $currentLesson = isset($input['currentLesson']) ? (int)$input['currentLesson'] : null;
    
    // قراءة teacherId بشكل صحيح - تحويل string إلى int
    $teacherId = null;
    if (isset($input['teacherId'])) {
        if (is_numeric($input['teacherId']) && $input['teacherId'] !== '') {
            $teacherId = (int)$input['teacherId'];
        }
    }
    
    $days = isset($input['days']) && is_array($input['days']) ? $input['days'] : [];
    $dayTimeSlots = isset($input['dayTimeSlots']) && is_array($input['dayTimeSlots']) ? $input['dayTimeSlots'] : [];
    $schedule = trim($input['schedule'] ?? '');
    $maxStudents = isset($input['maxStudents']) ? (int)$input['maxStudents'] : 15;
    $studentCount = isset($input['studentCount']) ? (int)$input['studentCount'] : null; // اختياري
    $ageFrom = isset($input['ageFrom']) && $input['ageFrom'] !== '' ? (int)$input['ageFrom'] : null;
    $ageTo = isset($input['ageTo']) && $input['ageTo'] !== '' ? (int)$input['ageTo'] : null;
    $genderIn = $input['gender'] ?? 'mixed';
    $gender = in_array($genderIn, ['male','female','mixed'], true) ? $genderIn : 'mixed';

    if ($name === '' || $level === '' || $teacherId === null || $teacherId <= 0 || empty($days)) {
        json_response(['success' => false, 'message' => 'الاسم والمستوى والمعلم والأيام مطلوبة'], 400);
    }

    // التحقق من عدم وجود تعارض في مواعيد المعلم (نفس اليوم+الوقت في مجموعة أخرى)، مع استثناء هذه المجموعة
    if (!empty($dayTimeSlots) && $teacherId > 0) {
        $conflictSlots = [];
        foreach ($dayTimeSlots as $day => $times) {
            if (!is_array($times)) {
                $times = [$times];
            }
            foreach ($times as $time) {
                $time = trim((string)$time);
                if ($time === '') { continue; }
                $slot = $day . ' ' . $time;

                $like = '%' . $mysqli->real_escape_string($slot) . '%';
                $checkStmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM `groups` WHERE teacher_id = ? AND id != ? AND schedule LIKE ?');
                if ($checkStmt) {
                    $checkStmt->bind_param('iis', $teacherId, $id, $like);
                    $checkStmt->execute();
                    $res = $checkStmt->get_result();
                    $row = $res ? $res->fetch_assoc() : null;
                    $checkStmt->close();
                    if ($row && (int)$row['cnt'] > 0) {
                        $conflictSlots[] = $slot;
                    }
                }
            }
        }

        if (!empty($conflictSlots)) {
            json_response([
                'success' => false,
                'message' => 'هذا المعلم لديه مجموعات أخرى في نفس المواعيد: ' . implode(' ، ', $conflictSlots)
            ], 400);
        }
    }

    // إنشاء الجدول إذا لم يتم تمريره
    if (empty($schedule) && !empty($dayTimeSlots)) {
        $scheduleArray = [];
        foreach ($dayTimeSlots as $day => $times) {
            if (is_array($times)) {
                foreach ($times as $time) {
                    $time = trim((string)$time);
                    if ($time !== '') { $scheduleArray[] = $day . ' ' . $time; }
                }
            } else {
                $time = trim((string)$times);
                if ($time !== '') { $scheduleArray[] = $day . ' ' . $time; }
            }
        }
        $schedule = implode(' | ', $scheduleArray);
    }

    $daysJson = json_encode($days, JSON_UNESCAPED_UNICODE);
    $dayTimeSlotsJson = json_encode($dayTimeSlots, JSON_UNESCAPED_UNICODE);

    if ($studentCount === null && $currentLesson === null) {
        $stmt = $mysqli->prepare('UPDATE `groups` SET name = ?, level = ?, course_id = ?, teacher_id = ?, days_json = ?, time = ?, schedule = ?, max_students = ?, age_from = ?, age_to = ?, gender = ?, session_duration = ? WHERE id = ?');
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
        }
        // name(s), level(s), course_id(i), teacher_id(i), days_json(s), time(s), schedule(s), max_students(i), age_from(i), age_to(i), gender(s), session_duration(d), id(i)
        $stmt->bind_param('ssiisssiiisdi', $name, $level, $courseId, $teacherId, $daysJson, $dayTimeSlotsJson, $schedule, $maxStudents, $ageFrom, $ageTo, $gender, $sessionDuration, $id);
    } elseif ($currentLesson !== null && $studentCount === null) {
        $stmt = $mysqli->prepare('UPDATE `groups` SET name = ?, level = ?, course_id = ?, current_lesson = ?, teacher_id = ?, days_json = ?, time = ?, schedule = ?, max_students = ?, age_from = ?, age_to = ?, gender = ?, session_duration = ? WHERE id = ?');
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
        }
        // name(s), level(s), course_id(i), current_lesson(i), teacher_id(i), days_json(s), time(s), schedule(s), max_students(i), age_from(i), age_to(i), gender(s), session_duration(d), id(i)
        $stmt->bind_param('ssiiisssiiisdi', $name, $level, $courseId, $currentLesson, $teacherId, $daysJson, $dayTimeSlotsJson, $schedule, $maxStudents, $ageFrom, $ageTo, $gender, $sessionDuration, $id);
    } elseif ($currentLesson === null && $studentCount !== null) {
        $stmt = $mysqli->prepare('UPDATE `groups` SET name = ?, level = ?, course_id = ?, teacher_id = ?, days_json = ?, time = ?, schedule = ?, max_students = ?, student_count = ?, age_from = ?, age_to = ?, gender = ?, session_duration = ? WHERE id = ?');
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
        }
        // name(s), level(s), course_id(i), teacher_id(i), days_json(s), time(s), schedule(s), max_students(i), student_count(i), age_from(i), age_to(i), gender(s), id(i)
        $stmt->bind_param('ssiisssiiiisdi', $name, $level, $courseId, $teacherId, $daysJson, $dayTimeSlotsJson, $schedule, $maxStudents, $studentCount, $ageFrom, $ageTo, $gender, $sessionDuration, $id);
    } else {
        $stmt = $mysqli->prepare('UPDATE `groups` SET name = ?, level = ?, course_id = ?, current_lesson = ?, teacher_id = ?, days_json = ?, time = ?, schedule = ?, max_students = ?, student_count = ?, age_from = ?, age_to = ?, gender = ?, session_duration = ? WHERE id = ?');
        if (!$stmt) {
            json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
        }
        // name(s), level(s), course_id(i), current_lesson(i), teacher_id(i), days_json(s), time(s), schedule(s), max_students(i), student_count(i), age_from(i), age_to(i), gender(s), id(i)
        $stmt->bind_param('ssiiisssiiiisdi', $name, $level, $courseId, $currentLesson, $teacherId, $daysJson, $dayTimeSlotsJson, $schedule, $maxStudents, $studentCount, $ageFrom, $ageTo, $gender, $sessionDuration, $id);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل في تحديث المجموعة', 'details' => $error], 500);
    }

    $stmt->close();

    $result = $mysqli->query('SELECT * FROM `groups` WHERE id = ' . (int)$id . ' LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم تحديث المجموعة بنجاح',
        'data' => $row ? map_group_row($row) : null,
    ]);
}

if ($method === 'POST' && $action === 'delete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $stmt = $mysqli->prepare('DELETE FROM `groups` WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json_response(['success' => false, 'message' => 'لم يتم العثور على المجموعة'], 404);
    }

    json_response(['success' => true, 'message' => 'تم حذف المجموعة بنجاح']);
}

// GET: جلب جدول المعلم المحجوز
if ($method === 'GET' && $action === 'teacher_schedule') {
    $teacherId = isset($_GET['teacherId']) ? (int)$_GET['teacherId'] : 0;
    
    if ($teacherId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المعلم مطلوب'], 400);
    }

    // جلب جميع المجموعات المرتبطة بالمعلم
    $query = "SELECT days_json, time, schedule FROM `groups` WHERE teacher_id = $teacherId";
    $result = $mysqli->query($query);
    
    if (!$result) {
        json_response(['success' => false, 'message' => 'فشل في جلب جدول المعلم', 'details' => $mysqli->error], 500);
    }

    $schedule = [];
    while ($row = $result->fetch_assoc()) {
        $schedule_text = $row['schedule'];
        
        if (strpos($schedule_text, '|') !== false) {
            // النظام الجديد: day time | day time
            $slots = explode(' | ', $schedule_text);
            foreach ($slots as $slot) {
                $parts = explode(' ', trim($slot), 2);
                if (count($parts) >= 2) {
                    $day = $parts[0];
                    $time = $parts[1];
                    
                    if (!isset($schedule[$day])) {
                        $schedule[$day] = [];
                    }
                    if (!in_array($time, $schedule[$day])) {
                        $schedule[$day][] = $time;
                    }
                }
            }
        } else {
            // النظام القديم: day و day time
            $days = json_decode($row['days_json'], true) ?: [];
            $time = $row['time'];
            
            foreach ($days as $day) {
                if (!isset($schedule[$day])) {
                    $schedule[$day] = [];
                }
                if (!in_array($time, $schedule[$day])) {
                    $schedule[$day][] = $time;
                }
            }
        }
    }

    json_response(['success' => true, 'data' => $schedule]);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);
