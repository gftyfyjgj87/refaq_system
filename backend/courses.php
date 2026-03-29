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

// Ensure required table/columns exist (idempotent guards)
// Create courses table if not exists
$mysqli->query(
    "CREATE TABLE IF NOT EXISTS `courses` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(255) NOT NULL,
      `description` TEXT NULL,
      `type` VARCHAR(50) NOT NULL DEFAULT 'educational',
      `total_lessons` INT UNSIGNED NOT NULL DEFAULT 0,
      `duration_weeks` INT UNSIGNED NULL,
      `level` VARCHAR(100) NULL,
      `prerequisites` TEXT NULL,
      `objectives` TEXT NULL,
      `status` VARCHAR(20) NOT NULL DEFAULT 'active',
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;"
);

// Create course_lessons table if not exists
$mysqli->query(
    "CREATE TABLE IF NOT EXISTS `course_lessons` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `course_id` INT UNSIGNED NOT NULL,
      `lesson_number` INT UNSIGNED NOT NULL,
      `title` VARCHAR(255) NOT NULL,
      `description` TEXT NULL,
      `content` TEXT NULL,
      `objectives` TEXT NULL,
      `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 60,
      `resources` TEXT NULL,
      `homework` TEXT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_course` (`course_id`),
      UNIQUE KEY `u_course_lesson` (`course_id`, `lesson_number`),
      CONSTRAINT `fk_lesson_course` FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;"
);

// Create group_progress table if not exists
$mysqli->query(
    "CREATE TABLE IF NOT EXISTS `group_progress` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `group_id` INT UNSIGNED NOT NULL,
      `lesson_id` INT UNSIGNED NOT NULL,
      `teacher_id` INT UNSIGNED NOT NULL,
      `attendance_count` INT UNSIGNED NOT NULL DEFAULT 0,
      `notes` TEXT NULL,
      `homework_assigned` TINYINT(1) NOT NULL DEFAULT 0,
      `next_lesson_date` DATE NULL,
      `completed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_group` (`group_id`),
      KEY `idx_lesson` (`lesson_id`),
      KEY `idx_teacher` (`teacher_id`),
      CONSTRAINT `fk_progress_group` FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_progress_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `course_lessons`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_progress_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;"
);

// Helper to check column existence
$__has_col = function(string $col) use ($mysqli): bool {
    $res = $mysqli->query("SHOW COLUMNS FROM `courses` LIKE '" . $mysqli->real_escape_string($col) . "'");
    return $res && $res->num_rows > 0;
};

// Add columns if missing
if (!$__has_col('total_lessons')) {
    $mysqli->query("ALTER TABLE `courses` ADD COLUMN `total_lessons` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `type`");
}
if (!$__has_col('duration_weeks')) {
    $mysqli->query("ALTER TABLE `courses` ADD COLUMN `duration_weeks` INT UNSIGNED NULL AFTER `total_lessons`");
}
if (!$__has_col('status')) {
    $mysqli->query("ALTER TABLE `courses` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active' AFTER `objectives`");
}
if (!$__has_col('created_at')) {
    $mysqli->query("ALTER TABLE `courses` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
}
if (!$__has_col('updated_at')) {
    $mysqli->query("ALTER TABLE `courses` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
}

function map_course_row(array $row): array {
    return [
        'id' => isset($row['id']) ? (string)$row['id'] : '',
        'name' => $row['name'] ?? '',
        'description' => $row['description'] ?? '',
        'type' => $row['type'] ?? 'educational',
        'totalLessons' => isset($row['total_lessons']) ? (int)$row['total_lessons'] : 0,
        'durationWeeks' => isset($row['duration_weeks']) ? (int)$row['duration_weeks'] : null,
        'level' => $row['level'] ?? '',
        'prerequisites' => $row['prerequisites'] ?? '',
        'objectives' => $row['objectives'] ?? '',
        'status' => $row['status'] ?? 'active',
        'createdAt' => $row['created_at'] ?? '',
        'updatedAt' => $row['updated_at'] ?? '',
    ];
}

function map_lesson_row(array $row): array {
    return [
        'id' => isset($row['id']) ? (string)$row['id'] : '',
        'title' => $row['title'] ?? '',
        'description' => $row['description'] ?? '',
        'duration' => isset($row['duration_minutes']) ? (int)$row['duration_minutes'] : 60,
        'order' => isset($row['lesson_number']) ? (int)$row['lesson_number'] : 0,
    ];
}

// GET: جلب جميع الكورسات أو كورس محدد
if ($method === 'GET') {
    $courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($courseId > 0) {
        // جلب كورس محدد مع دروسه
        $result = $mysqli->query("SELECT * FROM `courses` WHERE id = $courseId LIMIT 1");
        if (!$result) {
            json_response(['success' => false, 'message' => 'فشل في جلب الكورس', 'details' => $mysqli->error], 500);
        }
        
        $course = $result->fetch_assoc();
        if (!$course) {
            json_response(['success' => false, 'message' => 'الكورس غير موجود'], 404);
        }
        
        // جلب دروس الكورس
        $lessonsResult = $mysqli->query("SELECT * FROM `course_lessons` WHERE course_id = $courseId ORDER BY lesson_number ASC");
        $lessons = [];
        if ($lessonsResult) {
            while ($lesson = $lessonsResult->fetch_assoc()) {
                $lessons[] = map_lesson_row($lesson);
            }
        }
        
        $courseData = map_course_row($course);
        $courseData['lessons'] = $lessons;
        
        json_response(['success' => true, 'data' => $courseData]);
    } else {
        // جلب جميع الكورسات
        $result = $mysqli->query("SELECT * FROM `courses` ORDER BY created_at DESC");
        if (!$result) {
            json_response(['success' => false, 'message' => 'فشل في جلب الكورسات', 'details' => $mysqli->error], 500);
        }

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $courses[] = map_course_row($row);
        }

        json_response(['success' => true, 'data' => $courses]);
    }
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// POST: إنشاء كورس جديد
if ($method === 'POST' && $action === 'create') {
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $type = trim($input['type'] ?? 'educational');
    $totalLessons = isset($input['totalLessons']) ? (int)$input['totalLessons'] : 0;
    $durationWeeks = isset($input['durationWeeks']) && $input['durationWeeks'] !== null && $input['durationWeeks'] !== '' ? (int)$input['durationWeeks'] : null;
    $level = trim($input['level'] ?? '');
    $prerequisites = trim($input['prerequisites'] ?? '');
    $objectives = trim($input['objectives'] ?? '');
    $status = trim($input['status'] ?? 'active');

    if ($name === '' || $totalLessons <= 0) {
        json_response(['success' => false, 'message' => 'اسم الكورس وعدد الحصص مطلوبان'], 400);
    }

    $stmt = $mysqli->prepare('INSERT INTO `courses` (name, description, type, total_lessons, duration_weeks, level, prerequisites, objectives, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('sssiissss', $name, $description, $type, $totalLessons, $durationWeeks, $level, $prerequisites, $objectives, $status);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل في إنشاء الكورس', 'details' => $error], 500);
    }

    $id = $stmt->insert_id;
    $stmt->close();

    $result = $mysqli->query('SELECT * FROM `courses` WHERE id = ' . (int)$id . ' LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم إنشاء الكورس بنجاح',
        'data' => $row ? map_course_row($row) : null,
    ], 201);
}

// POST: تحديث كورس
if ($method === 'POST' && $action === 'update') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $type = trim($input['type'] ?? 'educational');
    $totalLessons = isset($input['totalLessons']) ? (int)$input['totalLessons'] : 0;
    $durationWeeks = isset($input['durationWeeks']) && $input['durationWeeks'] !== null && $input['durationWeeks'] !== '' ? (int)$input['durationWeeks'] : null;
    $level = trim($input['level'] ?? '');
    $prerequisites = trim($input['prerequisites'] ?? '');
    $objectives = trim($input['objectives'] ?? '');
    $status = trim($input['status'] ?? 'active');

    if ($name === '' || $totalLessons <= 0) {
        json_response(['success' => false, 'message' => 'اسم الكورس وعدد الحصص مطلوبان'], 400);
    }

    $stmt = $mysqli->prepare('UPDATE `courses` SET name = ?, description = ?, type = ?, total_lessons = ?, duration_weeks = ?, level = ?, prerequisites = ?, objectives = ?, status = ? WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('sssiissssi', $name, $description, $type, $totalLessons, $durationWeeks, $level, $prerequisites, $objectives, $status, $id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل في تحديث الكورس', 'details' => $error], 500);
    }

    $stmt->close();

    $result = $mysqli->query('SELECT * FROM `courses` WHERE id = ' . (int)$id . ' LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم تحديث الكورس بنجاح',
        'data' => $row ? map_course_row($row) : null,
    ]);
}

// POST: حذف كورس
if ($method === 'POST' && $action === 'delete') {
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $stmt = $mysqli->prepare('DELETE FROM `courses` WHERE id = ?');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        json_response(['success' => false, 'message' => 'لم يتم العثور على الكورس'], 404);
    }

    json_response(['success' => true, 'message' => 'تم حذف الكورس بنجاح']);
}

// POST: إضافة درس لكورس
if ($method === 'POST' && $action === 'add-lesson') {
    $courseId = isset($input['courseId']) ? (int)$input['courseId'] : 0;
    $lessonNumber = isset($input['lessonNumber']) ? (int)$input['lessonNumber'] : 0;
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $content = trim($input['content'] ?? '');
    $objectives = trim($input['objectives'] ?? '');
    $durationMinutes = isset($input['durationMinutes']) ? (int)$input['durationMinutes'] : 60;
    $resources = trim($input['resources'] ?? '');
    $homework = trim($input['homework'] ?? '');

    if ($courseId <= 0 || $lessonNumber <= 0 || $title === '') {
        json_response(['success' => false, 'message' => 'معرف الكورس ورقم الدرس والعنوان مطلوبة'], 400);
    }

    $stmt = $mysqli->prepare('INSERT INTO `course_lessons` (course_id, lesson_number, title, description, content, objectives, duration_minutes, resources, homework) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('iissssiss', $courseId, $lessonNumber, $title, $description, $content, $objectives, $durationMinutes, $resources, $homework);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل في إضافة الدرس', 'details' => $error], 500);
    }

    $id = $stmt->insert_id;
    $stmt->close();

    $result = $mysqli->query('SELECT * FROM `course_lessons` WHERE id = ' . (int)$id . ' LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;

    json_response([
        'success' => true,
        'message' => 'تم إضافة الدرس بنجاح',
        'data' => $row ? map_lesson_row($row) : null,
    ], 201);
}

// POST: تحديث تقدم المجموعة في الكورس
if ($method === 'POST' && $action === 'update-progress') {
    $groupId = isset($input['groupId']) ? (int)$input['groupId'] : 0;
    $lessonId = isset($input['lessonId']) ? (int)$input['lessonId'] : 0;
    $teacherId = isset($input['teacherId']) ? (int)$input['teacherId'] : 0;
    $attendanceCount = isset($input['attendanceCount']) ? (int)$input['attendanceCount'] : 0;
    $notes = trim($input['notes'] ?? '');
    $homeworkAssigned = isset($input['homeworkAssigned']) ? (bool)$input['homeworkAssigned'] : false;
    $nextLessonDate = trim($input['nextLessonDate'] ?? '');

    if ($groupId <= 0 || $lessonId <= 0 || $teacherId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المجموعة والدرس والمعلم مطلوبة'], 400);
    }

    $homeworkInt = $homeworkAssigned ? 1 : 0;
    $nextLessonDateValue = $nextLessonDate !== '' ? $nextLessonDate : null;

    $stmt = $mysqli->prepare('INSERT INTO `group_progress` (group_id, lesson_id, teacher_id, attendance_count, notes, homework_assigned, next_lesson_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        json_response(['success' => false, 'message' => 'فشل في إعداد الاستعلام', 'details' => $mysqli->error], 500);
    }

    $stmt->bind_param('iiiisis', $groupId, $lessonId, $teacherId, $attendanceCount, $notes, $homeworkInt, $nextLessonDateValue);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_response(['success' => false, 'message' => 'فشل في تسجيل التقدم', 'details' => $error], 500);
    }

    $stmt->close();

    // تحديث الدرس الحالي للمجموعة
    $mysqli->query("UPDATE `groups` SET current_lesson = current_lesson + 1 WHERE id = $groupId");

    json_response([
        'success' => true,
        'message' => 'تم تسجيل التقدم بنجاح',
    ], 201);
}

// GET: جلب تقدم مجموعة في كورس
if ($method === 'GET' && $action === 'group-progress') {
    $groupId = isset($_GET['groupId']) ? (int)$_GET['groupId'] : 0;
    
    if ($groupId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المجموعة مطلوب'], 400);
    }

    $query = "
        SELECT gp.*, cl.lesson_number, cl.title as lesson_title, u.name as teacher_name
        FROM `group_progress` gp
        JOIN `course_lessons` cl ON gp.lesson_id = cl.id
        JOIN `users` u ON gp.teacher_id = u.id
        WHERE gp.group_id = $groupId
        ORDER BY gp.completed_at DESC
    ";
    
    $result = $mysqli->query($query);
    if (!$result) {
        json_response(['success' => false, 'message' => 'فشل في جلب التقدم', 'details' => $mysqli->error], 500);
    }

    $progress = [];
    while ($row = $result->fetch_assoc()) {
        $progress[] = [
            'id' => (string)$row['id'],
            'groupId' => (string)$row['group_id'],
            'lessonId' => (string)$row['lesson_id'],
            'lessonNumber' => (int)$row['lesson_number'],
            'lessonTitle' => $row['lesson_title'],
            'completedAt' => $row['completed_at'],
            'teacherId' => (string)$row['teacher_id'],
            'teacherName' => $row['teacher_name'],
            'attendanceCount' => (int)$row['attendance_count'],
            'notes' => $row['notes'] ?? '',
            'homeworkAssigned' => (bool)$row['homework_assigned'],
            'nextLessonDate' => $row['next_lesson_date'] ?? '',
        ];
    }

    json_response(['success' => true, 'data' => $progress]);
}

json_response(['success' => false, 'message' => 'طلب غير مدعوم'], 405);