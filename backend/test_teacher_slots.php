<?php

require_once __DIR__ . '/db.php';

// اختبار جلب المواعيد لمعلم معين
$teacherId = 1; // استبدل برقم معلم حقيقي

echo "Testing teacher slots API...\n";
echo "Teacher ID: $teacherId\n\n";

// جلب المواعيد
$result = $mysqli->query("SELECT * FROM `teacher_available_slots` WHERE teacher_id = $teacherId AND is_available = 1");

if (!$result) {
    echo "Query error: " . $mysqli->error . "\n";
    exit;
}

echo "Found " . $result->num_rows . " slots\n\n";

while ($row = $result->fetch_assoc()) {
    echo "Day: " . $row['day_of_week'] . "\n";
    echo "Start: " . $row['start_time'] . "\n";
    echo "End: " . $row['end_time'] . "\n";
    echo "---\n";
}

// اختبار الـ API
echo "\n\nTesting API endpoint...\n";
$url = "http://localhost/refaq/backend/teacher_slots.php?teacherId=$teacherId";
$response = file_get_contents($url);
$data = json_decode($response, true);

echo "API Response:\n";
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
