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

$teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
if ($teacherId <= 0) {
    json_response(['success' => false, 'message' => 'teacher_id is required'], 400);
}

$today = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Fetch teacher hourly rate
$hourlyRate = 0.0;
$res = $mysqli->query("SELECT hourly_rate, teacher_type, hourly_rate_quran FROM users WHERE id = $teacherId AND role = 'teacher' LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    $teacherType = $row['teacher_type'] ?? null;
    if ($teacherType === 'both' && isset($row['hourly_rate_quran']) && $row['hourly_rate_quran'] !== null) {
        $hourlyRate = (float)$row['hourly_rate_quran'];
    } else {
        $hourlyRate = isset($row['hourly_rate']) ? (float)$row['hourly_rate'] : 0.0;
    }
}

// Fetch groups for this teacher
$groups = [];
$grpRes = $mysqli->query("SELECT id, name, level, student_count, time FROM groups WHERE teacher_id = $teacherId");
if ($grpRes) {
    while ($g = $grpRes->fetch_assoc()) {
        $groups[] = [
            'id' => (string)$g['id'],
            'name' => $g['name'] ?? '',
            'level' => $g['level'] ?? '',
            'studentCount' => isset($g['student_count']) ? (int)$g['student_count'] : 0,
            'time' => $g['time'] ?? null,
        ];
    }
}

// Fetch sessions recent window (last 14 days to next 14 days)
$sessions = [];
$sesSql = sprintf(
    "SELECT ts.id, ts.teacher_id, ts.group_id, ts.student_id, ts.session_date, ts.session_time, ts.hours, ts.rate, ts.amount
     FROM teacher_sessions ts
     WHERE ts.teacher_id = %d AND ts.session_date BETWEEN DATE_SUB('%s', INTERVAL 14 DAY) AND DATE_ADD('%s', INTERVAL 14 DAY)",
    $teacherId,
    $mysqli->real_escape_string($today),
    $mysqli->real_escape_string($today)
);
$sesRes = $mysqli->query($sesSql);
if ($sesRes) {
    while ($s = $sesRes->fetch_assoc()) {
        // استخدم group_id مباشرةً إن وجد
        $groupId = isset($s['group_id']) ? (string)$s['group_id'] : null;
        $time = $s['session_time'] ?? null;
        // استنتاج بسيط للحالة: الجلسات قبل اليوم تعتبر مكتملة، اليوم/بعده مجدولة
        $status = 'scheduled';
        if (!empty($s['session_date'])) {
            if ($s['session_date'] < $today) { $status = 'completed'; }
        }
        $sessions[] = [
            'id' => (string)$s['id'],
            'teacherId' => (string)$teacherId,
            'groupId' => $groupId,
            'date' => $s['session_date'],
            'time' => $time,
            'duration' => (int)round(((float)$s['hours']) * 60),
            'status' => $status,
            'reportSubmitted' => false,
            'zoomLink' => null,
        ];
    }
}

// If no sessions in the window, generate from groups for today +/- 14 days
if (empty($sessions)) {
    $from = date('Y-m-d', strtotime('-14 days', strtotime($today)));
    $to = date('Y-m-d', strtotime('+14 days', strtotime($today)));

    // Reuse the same generation logic from teacher_schedule.php
    $query = "SELECT id, teacher_id, days_json, time, schedule FROM `groups` WHERE teacher_id = $teacherId";
    $result = $mysqli->query($query);
    if ($result) {
        $scheduleByDay = [];
        while ($row = $result->fetch_assoc()) {
            $groupId = isset($row['id']) ? (int)$row['id'] : 0;
            $scheduleText = $row['schedule'] ?? '';

            if ($scheduleText && strpos($scheduleText, '|') !== false) {
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
                        $scheduleByDay[$day][] = ['groupId' => $groupId, 'time' => $time];
                    }
                }
            } else {
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
                        $scheduleByDay[$day][] = ['groupId' => $groupId, 'time' => $time];
                    }
                }
            }
        }

        $dayNames = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];
        $current = strtotime($from);
        $end = strtotime($to);
        if ($current !== false && $end !== false) {
            while ($current <= $end) {
                $date = date('Y-m-d', $current);
                $dayOfWeek = date('w', $current);
                $dayIndex = ((int)$dayOfWeek + 1) % 7;
                $dayName = $dayNames[$dayIndex] ?? null;

                if ($dayName && isset($scheduleByDay[$dayName])) {
                    foreach ($scheduleByDay[$dayName] as $slot) {
                        $groupId = (int)$slot['groupId'];
                        $time = trim((string)$slot['time']);
                        if ($time === '') continue;

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

                        $checkDate = $mysqli->real_escape_string($date);
                        $checkTime = $mysqli->real_escape_string($timeFormatted);
                        $existsSql = "SELECT id FROM teacher_sessions WHERE teacher_id=$teacherId AND group_id=$groupId AND session_date='$checkDate' AND session_time='$checkTime' LIMIT 1";
                        $existsRes = $mysqli->query($existsSql);
                        if ($existsRes && $existsRes->num_rows > 0) {
                            continue;
                        }

                        $insertStmt = $mysqli->prepare('INSERT INTO teacher_sessions (teacher_id, group_id, session_date, session_time, hours, status) VALUES (?, ?, ?, ?, ?, ?)');
                        if ($insertStmt) {
                            $hours = 1.0;
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
    }

    // Reload sessions after generation
    $sesRes = $mysqli->query($sesSql);
    if ($sesRes) {
        $sessions = [];
        while ($s = $sesRes->fetch_assoc()) {
            $groupId = isset($s['group_id']) ? (string)$s['group_id'] : null;
            $time = $s['session_time'] ?? null;
            $status = 'scheduled';
            if (!empty($s['session_date'])) {
                if ($s['session_date'] < $today) { $status = 'completed'; }
            }
            $sessions[] = [
                'id' => (string)$s['id'],
                'teacherId' => (string)$teacherId,
                'groupId' => $groupId,
                'date' => $s['session_date'],
                'time' => $time,
                'duration' => (int)round(((float)$s['hours']) * 60),
                'status' => $status,
                'reportSubmitted' => false,
                'zoomLink' => null,
            ];
        }
    }
}

// Compute dashboard stats
$todayCount = 0;
$completedCount = 0;
$monthlyHours = 0.0;
foreach ($sessions as $s) {
    if ($s['date'] === $today) {
        $todayCount++;
    }
    if ($s['status'] === 'completed') {
        $completedCount++;
        $monthlyHours += ((float)$s['duration']) / 60.0;
    }
}

// Wallet balance: sum of amounts in last 30 days
$walletBalance = 0.0;
$wbRes = $mysqli->query("SELECT COALESCE(SUM(amount),0) AS amt FROM teacher_sessions WHERE teacher_id = $teacherId AND session_date >= DATE_SUB('$today', INTERVAL 30 DAY)");
if ($wbRes && $w = $wbRes->fetch_assoc()) {
    $walletBalance = (float)$w['amt'];
}

json_response([
    'success' => true,
    'data' => [
        'sessions' => $sessions,
        'groups' => $groups,
        'hourlyRate' => $hourlyRate,
        'walletBalance' => $walletBalance,
        'today' => $today,
        'todayCount' => $todayCount,
        'completedCount' => $completedCount,
        'monthlyHours' => $monthlyHours,
    ]
]);
