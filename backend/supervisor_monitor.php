<?php

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$today = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

$column_exists = function(string $table, string $column) use ($mysqli): bool {
    $t = $mysqli->real_escape_string($table);
    $c = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return $res && $res->num_rows > 0;
};

// Teachers - عرض جميع المعلمين
$teachers = [];
$tSql = "SELECT id, name, email, phone, status, hourly_rate FROM users WHERE role='teacher'";
$tSql .= " ORDER BY name ASC";
$tRes = $mysqli->query($tSql);
if ($tRes) {
    while ($t = $tRes->fetch_assoc()) {
        $teachers[] = [
            'id' => (string)$t['id'],
            'name' => $t['name'] ?? '',
            'email' => $t['email'] ?? '',
            'phone' => $t['phone'] ?? '',
            'specialization' => '',
            'status' => $t['status'] ?? 'active',
            'totalHours' => 0,
        ];
    }
}

// Groups
$groups = [];
$gSql = "SELECT id, name, level, teacher_id, student_count, time FROM groups";
$gSql .= " ORDER BY id ASC";
$gRes = $mysqli->query($gSql);
if ($gRes) {
    while ($g = $gRes->fetch_assoc()) {
        $groups[] = [
            'id' => (string)$g['id'],
            'name' => $g['name'] ?? '',
            'level' => $g['level'] ?? '',
            'teacherId' => isset($g['teacher_id']) ? (string)$g['teacher_id'] : null,
            'studentCount' => isset($g['student_count']) ? (int)$g['student_count'] : 0,
            'time' => $g['time'] ?? null,
        ];
    }
}

// Students
$students = [];
$studentsHasUsedSessions = $column_exists('students', 'used_sessions');
$sSql = "SELECT id, name, phone, parent_phone, teacher_id, status, remaining_sessions";
if ($studentsHasUsedSessions) {
    $sSql .= ", used_sessions";
}
$sSql .= " FROM students";
$sSql .= " ORDER BY id ASC";
$sRes = $mysqli->query($sSql);
if ($sRes) {
    while ($s = $sRes->fetch_assoc()) {
        $students[] = [
            'id' => (string)$s['id'],
            'name' => $s['name'] ?? '',
            'phone' => $s['phone'] ?? '',
            'parentPhone' => $s['parent_phone'] ?? '',
            'teacherId' => isset($s['teacher_id']) ? (string)$s['teacher_id'] : null,
            'status' => $s['status'] ?? 'active',
            'remainingSessions' => isset($s['remaining_sessions']) ? (int)$s['remaining_sessions'] : 0,
            'usedSessions' => ($studentsHasUsedSessions && isset($s['used_sessions'])) ? (int)$s['used_sessions'] : 0,
        ];
    }
}

// Sessions window
$sessions = [];
$sesSql = sprintf(
    "SELECT ts.id, ts.teacher_id, ts.group_id, ts.session_date, ts.session_time, ts.hours, ts.note, ts.status
     FROM teacher_sessions ts
     WHERE ts.session_date BETWEEN DATE_SUB('%s', INTERVAL 7 DAY) AND DATE_ADD('%s', INTERVAL 7 DAY)",
    $mysqli->real_escape_string($today),
    $mysqli->real_escape_string($today)
);
$sesRes = $mysqli->query($sesSql);
if ($sesRes) {
    while ($r = $sesRes->fetch_assoc()) {
        $status = !empty($r['status']) ? (string)$r['status'] : 'scheduled';
        if ($status === 'scheduled' && !empty($r['session_date']) && $r['session_date'] < $today) { $status = 'completed'; }
        $reportSubmitted = false;
        if (isset($r['note']) && trim((string)$r['note']) !== '') { $reportSubmitted = true; }
        $sessions[] = [
            'id' => (string)$r['id'],
            'teacherId' => isset($r['teacher_id']) ? (string)$r['teacher_id'] : null,
            'groupId' => isset($r['group_id']) ? (string)$r['group_id'] : null,
            'date' => $r['session_date'],
            'time' => $r['session_time'] ?? null,
            'duration' => (int)round(((float)($r['hours'] ?? 0)) * 60),
            'status' => $status,
            'reportSubmitted' => $reportSubmitted,
            'zoomLink' => null,
        ];
    }
}

// Today sessions (from DB) - include names for direct rendering
$todaySessions = [];
$todaySesSql = sprintf(
    "SELECT ts.id, ts.session_date, ts.session_time, ts.hours, ts.status, ts.note,
            ts.teacher_id, u.name AS teacher_name,
            ts.group_id, g.name AS group_name
     FROM teacher_sessions ts
     LEFT JOIN users u ON u.id = ts.teacher_id
     LEFT JOIN groups g ON g.id = ts.group_id
     WHERE ts.session_date = '%s'",
    $mysqli->real_escape_string($today)
);
$todaySesSql .= " ORDER BY ts.session_time ASC";
$todaySesRes = $mysqli->query($todaySesSql);
if ($todaySesRes) {
    while ($r = $todaySesRes->fetch_assoc()) {
        $status = !empty($r['status']) ? (string)$r['status'] : 'scheduled';
        $reportSubmitted = (isset($r['note']) && trim((string)$r['note']) !== '');
        $todaySessions[] = [
            'id' => (string)$r['id'],
            'teacherId' => isset($r['teacher_id']) ? (string)$r['teacher_id'] : null,
            'teacherName' => $r['teacher_name'] ?? '',
            'groupId' => isset($r['group_id']) ? (string)$r['group_id'] : null,
            'groupName' => $r['group_name'] ?? '',
            'date' => $r['session_date'],
            'time' => $r['session_time'] ?? null,
            'duration' => (int)round(((float)($r['hours'] ?? 0)) * 60),
            'status' => $status,
            'reportSubmitted' => $reportSubmitted,
        ];
    }
}

json_response([
    'success' => true,
    'data' => [
        'today' => $today,
        'teachers' => $teachers,
        'groups' => $groups,
        'students' => $students,
        'sessions' => $sessions,
        'todaySessions' => $todaySessions,
    ]
]);
