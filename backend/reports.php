<?php

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Ensure $resource is defined before any checks
$resource = $_GET['resource'] ?? 'overview';

// تحليلات المدفوعات: مدفوع بالكامل / جزئي / متأخر
if ($resource === 'payment_analytics') {
    $rows = [
        'full' => ['count' => 0, 'amount' => 0],
        'partial' => ['count' => 0, 'amount' => 0],
        'overdue' => ['count' => 0, 'amount' => 0],
    ];
    // مدفوع بالكامل
    $q1 = "SELECT COUNT(*) c, COALESCE(SUM(amount),0) amt FROM student_subscriptions WHERE paid_amount >= amount AND amount > 0";
    if ($res = $mysqli->query($q1)) { $r = $res->fetch_assoc(); $rows['full'] = ['count'=>(int)($r['c']??0),'amount'=>(float)($r['amt']??0)]; }
    // جزئي
    $q2 = "SELECT COUNT(*) c, COALESCE(SUM(paid_amount),0) amt FROM student_subscriptions WHERE paid_amount > 0 AND paid_amount < amount";
    if ($res = $mysqli->query($q2)) { $r = $res->fetch_assoc(); $rows['partial'] = ['count'=>(int)($r['c']??0),'amount'=>(float)($r['amt']??0)]; }
    // متأخر (حسب الحالة أو عدم السداد)
    $q3 = "SELECT COUNT(*) c, COALESCE(SUM(amount - paid_amount),0) amt FROM student_subscriptions WHERE (status='overdue' OR paid_amount = 0) AND amount > paid_amount";
    if ($res = $mysqli->query($q3)) { $r = $res->fetch_assoc(); $rows['overdue'] = ['count'=>(int)($r['c']??0),'amount'=>(float)($r['amt']??0)]; }

    $out = [
      ['status' => 'مدفوع بالكامل', 'count' => $rows['full']['count'], 'amount' => $rows['full']['amount'], 'percentage' => 0],
      ['status' => 'دفعات جزئية', 'count' => $rows['partial']['count'], 'amount' => $rows['partial']['amount'], 'percentage' => 0],
      ['status' => 'متأخر', 'count' => $rows['overdue']['count'], 'amount' => $rows['overdue']['amount'], 'percentage' => 0],
    ];
    $total = array_sum(array_map(function($x){return (int)$x['count'];}, $out));
    if ($total > 0) {
        foreach ($out as &$x) { $x['percentage'] = round(($x['count']/$total)*100); }
    }
    echo json_encode(['success'=>true,'data'=>$out]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

function number_or_zero($v) { return $v !== null ? (float)$v : 0.0; }

if ($resource === 'overview') {
    // الطلاب
    $stuTotal = 0; $stuActive = 0; $stuFrozen = 0;
    if ($res = $mysqli->query("SELECT COUNT(*) AS c, status FROM students GROUP BY status")) {
        while ($row = $res->fetch_assoc()) {
            $c = (int)$row['c'];
            $status = $row['status'];
            $stuTotal += $c;
            if ($status === 'active') $stuActive = $c;
            if ($status === 'frozen') $stuFrozen = $c;
        }
    }

    // المعلمين
    $teaTotal = 0; $teaActive = 0;
    if ($res = $mysqli->query("SELECT COUNT(*) AS c, status FROM users WHERE role='teacher' GROUP BY status")) {
        while ($row = $res->fetch_assoc()) {
            $c = (int)$row['c'];
            $teaTotal += $c;
            if (($row['status'] ?? '') === 'active') $teaActive = $c;
        }
    }

    // حصص اليوم من teacher_sessions
    $todaySessions = 0; $monthSessions = 0;
    if ($res = $mysqli->query("SELECT COUNT(*) AS c FROM teacher_sessions WHERE session_date = CURRENT_DATE()")) {
        $todaySessions = (int)($res->fetch_assoc()['c'] ?? 0);
    }
    if ($res = $mysqli->query("SELECT COUNT(*) AS c FROM teacher_sessions WHERE DATE_FORMAT(session_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')")) {
        $monthSessions = (int)($res->fetch_assoc()['c'] ?? 0);
    }

    // إحصائيات مالية شهرية من transactions
    $income = 0.0; $expense = 0.0;
    if ($res = $mysqli->query("SELECT SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS inc, SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS exp FROM transactions WHERE DATE_FORMAT(`date`,'%Y-%m')=DATE_FORMAT(CURRENT_DATE(),'%Y-%m')")) {
        $row = $res->fetch_assoc();
        $income = number_or_zero($row['inc'] ?? 0);
        $expense = number_or_zero($row['exp'] ?? 0);
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'totalStudents' => $stuTotal,
            'activeStudents' => $stuActive,
            'frozenStudents' => $stuFrozen,
            'totalTeachers' => $teaTotal,
            'activeTeachers' => $teaActive,
            'todaySessions' => $todaySessions,
            'monthSessions' => $monthSessions,
            'monthlyRevenue' => $income,
            'monthlyExpenses' => $expense,
            'netProfit' => $income - $expense,
        ],
    ]);
    exit;
}

if ($resource === 'monthly_financial') {
    // آخر 6 شهور
    $sql = "SELECT DATE_FORMAT(`date`, '%Y-%m') AS ym,
                   SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS revenue,
                   SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expenses
            FROM transactions
            WHERE `date` >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
            GROUP BY ym
            ORDER BY ym ASC";
    $res = $mysqli->query($sql);
    if (!$res) {
        json_response(['success' => false, 'message' => 'فشل جلب التحليل المالي', 'details' => $mysqli->error], 500);
    }
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $ym = $row['ym'];
        $monthName = $ym; // يمكن لاحقاً تعريب الاسم
        $rev = number_or_zero($row['revenue']);
        $exp = number_or_zero($row['expenses']);
        $rows[] = [
            'month' => $monthName,
            'revenue' => $rev,
            'expenses' => $exp,
            'profit' => $rev - $exp,
        ];
    }
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if ($resource === 'teacher_performance') {
    $sql = "SELECT u.id, u.name, COUNT(ts.id) AS sessions
            FROM users u
            LEFT JOIN teacher_sessions ts ON ts.teacher_id = u.id
              AND DATE_FORMAT(ts.session_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')
            WHERE u.role = 'teacher'
            GROUP BY u.id, u.name
            ORDER BY sessions DESC";
    $res = $mysqli->query($sql);
    if (!$res) {
        json_response(['success' => false, 'message' => 'فشل جلب أداء المعلمين', 'details' => $mysqli->error], 500);
    }
    $list = [];
    while ($row = $res->fetch_assoc()) {
        $sessions = (int)$row['sessions'];
        $reports = $sessions; // حتى تتوفر جداول التقارير
        $rating = null; // يمكن لاحقاً
        $list[] = [
            'name' => $row['name'],
            'sessions' => $sessions,
            'reports' => $reports,
            'rating' => $rating,
        ];
    }
    echo json_encode(['success' => true, 'data' => $list]);
    exit;
}

// حضور أسبوعي فعلي من جدول session_attendance
if ($resource === 'attendance_weekly') {
    $period = $_GET['period'] ?? 'month';
 
    // تحديد عدد الأسابيع بناءً على الفترة
    $weeks = match($period) {
        'week' => 1,
        'month' => 4,
        'quarter' => 12,
        'year' => 52,
        default => 4
    };
 
    $days = (int)$weeks * 7;
 
    $sql = "SELECT
                YEARWEEK(ts.session_date, 1) AS yw,
                COUNT(*) AS total,
                SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN sa.status = 'late' THEN 1 ELSE 0 END) AS late
            FROM session_attendance sa
            JOIN teacher_sessions ts ON ts.id = sa.session_id
            WHERE ts.session_date >= DATE_SUB(CURRENT_DATE(), INTERVAL $days DAY)
            GROUP BY YEARWEEK(ts.session_date, 1)
            ORDER BY yw ASC";
 
    $res = $mysqli->query($sql);
    if (!$res) {
        echo json_encode(['success' => false, 'message' => 'فشل جلب تقرير الحضور', 'details' => $mysqli->error]);
        exit;
    }
 
    $out = [];
    $i = 1;
    while ($r = $res->fetch_assoc()) {
        $total = (int)($r['total'] ?? 0);
        $presentPct = $total > 0 ? (int)round(((int)($r['present'] ?? 0) / $total) * 100) : 0;
        $absentPct = $total > 0 ? (int)round(((int)($r['absent'] ?? 0) / $total) * 100) : 0;
        $latePct = $total > 0 ? (int)round(((int)($r['late'] ?? 0) / $total) * 100) : 0;
 
        $out[] = [
            'week' => 'الأسبوع ' . $i,
            'present' => $presentPct,
            'absent' => $absentPct,
            'late' => $latePct,
        ];
        $i++;
    }
 
    echo json_encode(['success' => true, 'data' => $out]);
    exit;
}

// تحليلات عامة: توزيع أداء الطلاب وأنواع الاشتراكات (تقريبي)
if ($resource === 'analytics') {
    // توزيع أداء الطلاب غير متوفر؛ نعيد مصفوفة فارغة قابلة للتوسّع لاحقاً
    $performance = [];
    // أنواع الاشتراكات من student_subscriptions
    $subs = [];
    $sql = "SELECT sp.name AS pkg, COUNT(ss.id) AS cnt
            FROM student_subscriptions ss
            LEFT JOIN subscription_packages sp ON sp.id = ss.package_id
            GROUP BY pkg
            ORDER BY cnt DESC";
    if ($res = $mysqli->query($sql)) {
        while ($r = $res->fetch_assoc()) {
            $subs[] = ['name' => ($r['pkg'] ?? 'غير محدد'), 'value' => (int)$r['cnt']];
        }
    }
    echo json_encode(['success' => true, 'data' => ['studentPerformance' => $performance, 'subscriptionTypes' => $subs]]);
    exit;
}

// تقارير الطلاب الأساسية
if ($resource === 'student_reports') {
    // ملاحظة: جدول الطلاب الحالي لا يحتوي على عمود group_name
    // لذلك نعيد فقط اسم الطالب والمعلم مع إحصائيات الجلسات، ونجعل اسم المجموعة '-'
    $sql = "SELECT s.id, s.name, u.name AS teacher_name
            FROM students s
            LEFT JOIN users u ON u.id = s.teacher_id
            ORDER BY s.id ASC
            LIMIT 500";
    $res = $mysqli->query($sql);
    if (!$res) { echo json_encode(['success'=>true,'data'=>[]]); exit; }
    $list = [];
    while ($s = $res->fetch_assoc()) {
        $sid = (int)$s['id'];
        $lastRes = $mysqli->query("SELECT MAX(session_date) AS d, COUNT(*) AS c FROM teacher_sessions WHERE student_id = $sid");
        $lastRow = $lastRes ? $lastRes->fetch_assoc() : ['d' => null, 'c' => 0];
        $sessionsCompleted = (int)($lastRow['c'] ?? 0);
        $totalSessions = 20;
        $attendance = $totalSessions > 0 ? (int)round(($sessionsCompleted / $totalSessions) * 100) : 0;
        $list[] = [
            'id' => (string)$sid,
            'name' => $s['name'] ?? '',
            'group' => '-',
            'attendance' => $attendance,
            'performance' => '',
            'sessionsCompleted' => $sessionsCompleted,
            'totalSessions' => $totalSessions,
            'lastSession' => $lastRow['d'] ?? '',
            'teacher' => $s['teacher_name'] ?? '',
            'notes' => '',
        ];
    }
    echo json_encode(['success' => true, 'data' => $list]);
    exit;
}

echo json_encode(['success' => true, 'data' => []]);
