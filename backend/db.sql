
CREATE DATABASE IF NOT EXISTS `refaq` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `refaq`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `phone` VARCHAR(30) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'supervisor', 'teacher', 'student') NOT NULL DEFAULT 'student',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `salary` DECIMAL(10,2) DEFAULT NULL,
  `hourly_rate` DECIMAL(10,2) DEFAULT NULL,
  `hourly_rate_quran` DECIMAL(10,2) DEFAULT NULL,
  `hourly_rate_educational` DECIMAL(10,2) DEFAULT NULL,
  `permissions` TEXT DEFAULT NULL,
  `assigned_teachers` TEXT DEFAULT NULL,
  `assigned_students` TEXT DEFAULT NULL,
  `teacher_type` ENUM('quran', 'educational', 'both') DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `hourly_rate_quran` DECIMAL(10,2) DEFAULT NULL AFTER `hourly_rate`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `hourly_rate_educational` DECIMAL(10,2) DEFAULT NULL AFTER `hourly_rate_quran`;

-- جدول باقات الاشتراك
CREATE TABLE IF NOT EXISTS `subscription_packages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `type` ENUM('quran','educational') NOT NULL,
  `duration` INT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `discount` INT NOT NULL DEFAULT 0,
  `sessions_count` INT NOT NULL DEFAULT 0,
  `session_duration` INT NOT NULL DEFAULT 60 COMMENT 'مدة الحصة بالدقائق',
  `students_count` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- إضافة حقل session_duration للجداول الموجودة
ALTER TABLE `subscription_packages` 
  ADD COLUMN IF NOT EXISTS `session_duration` INT NOT NULL DEFAULT 60 COMMENT 'مدة الحصة بالدقائق' AFTER `sessions_count`;

-- جدول المجموعات الدراسية
CREATE TABLE IF NOT EXISTS `groups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `level` VARCHAR(150) NOT NULL,
  `teacher_id` INT UNSIGNED NOT NULL,
  `days_json` TEXT NOT NULL,
  `time` VARCHAR(50) NOT NULL,
  `schedule` VARCHAR(255) NOT NULL,
  `student_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `max_students` INT UNSIGNED NOT NULL DEFAULT 15,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_teacher_id` (`teacher_id`),
  CONSTRAINT `fk_groups_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `students` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(30) NOT NULL,
  `parent_phone` VARCHAR(30) DEFAULT NULL,
  `whatsapp` VARCHAR(30) DEFAULT NULL,
  `system_type` ENUM('quran', 'educational') NOT NULL,
  `teacher_id` VARCHAR(50) NOT NULL,
  `schedule_time` VARCHAR(100) DEFAULT NULL,
  `package_id` VARCHAR(50) NOT NULL,
  `remaining_sessions` INT NOT NULL DEFAULT 0,
  `status` ENUM('active', 'frozen', 'archived') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` (`name`, `email`, `password`, `role`)
VALUES ('إدارة', 'admin@test.com', '123456', 'admin')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `password` = VALUES(`password`),
  `role` = VALUES(`role`);


-- جداول الإدارة المالية
CREATE TABLE IF NOT EXISTS `vaults` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('income','expense') NOT NULL,
  `category` VARCHAR(150) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `person` VARCHAR(150) DEFAULT NULL,
  `vault_id` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_date` (`date`),
  KEY `idx_vault` (`vault_id`),
  CONSTRAINT `fk_transactions_vault` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `supervisor_payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supervisor_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `paid_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_supervisor` (`supervisor_id`),
  KEY `idx_paid_at` (`paid_at`),
  CONSTRAINT `fk_supervisor_user` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- اشتراكات الطلاب والمدفوعات
CREATE TABLE IF NOT EXISTS `student_subscriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` INT UNSIGNED NOT NULL,
  `package_id` INT UNSIGNED DEFAULT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status` ENUM('active','frozen','completed','overdue') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_ss_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ss_package` FOREIGN KEY (`package_id`) REFERENCES `subscription_packages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `paid_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `method` VARCHAR(50) DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_subscription` (`subscription_id`),
  KEY `idx_paid_at_sp` (`paid_at`),
  CONSTRAINT `fk_sp_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `student_subscriptions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ساعات المعلمين ومدفوعاتهم
CREATE TABLE IF NOT EXISTS `teacher_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teacher_id` INT UNSIGNED NOT NULL,
  `group_id` INT UNSIGNED NULL,
  `student_id` INT UNSIGNED DEFAULT NULL,
  `session_date` DATE NOT NULL,
  `session_time` VARCHAR(50) NULL,
  `hours` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `rate` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `note` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_group` (`group_id`),
  KEY `idx_session_date` (`session_date`),
  CONSTRAINT `fk_ts_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ts_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ts_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `teacher_payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teacher_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `paid_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_teacher_tp` (`teacher_id`),
  KEY `idx_paid_at_tp` (`paid_at`),
  CONSTRAINT `fk_tp_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- جدول الكورسات/المسارات التعليمية
CREATE TABLE IF NOT EXISTS `courses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `type` ENUM('quran','educational') NOT NULL,
  `total_lessons` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_weeks` INT UNSIGNED DEFAULT NULL,
  `level` VARCHAR(100) DEFAULT NULL,
  `prerequisites` TEXT DEFAULT NULL,
  `objectives` TEXT DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- جدول دروس الكورس
CREATE TABLE IF NOT EXISTS `course_lessons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_id` INT UNSIGNED NOT NULL,
  `lesson_number` INT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `content` TEXT DEFAULT NULL,
  `objectives` TEXT DEFAULT NULL,
  `duration_minutes` INT UNSIGNED DEFAULT 60,
  `resources` TEXT DEFAULT NULL,
  `homework` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_course_lesson` (`course_id`, `lesson_number`),
  CONSTRAINT `fk_lesson_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ربط المجموعات بالكورسات
ALTER TABLE `groups` 
  ADD COLUMN IF NOT EXISTS `course_id` INT UNSIGNED NULL AFTER `level`,
  ADD COLUMN IF NOT EXISTS `current_lesson` INT UNSIGNED DEFAULT 1 AFTER `course_id`;

-- إضافة مفتاح خارجي للكورس في المجموعات
ALTER TABLE `groups` 
  ADD CONSTRAINT `fk_groups_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- جدول تتبع تقدم المجموعات في الكورس
CREATE TABLE IF NOT EXISTS `group_progress` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` INT UNSIGNED NOT NULL,
  `lesson_id` INT UNSIGNED NOT NULL,
  `completed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `teacher_id` INT UNSIGNED NOT NULL,
  `attendance_count` INT UNSIGNED DEFAULT 0,
  `notes` TEXT DEFAULT NULL,
  `homework_assigned` BOOLEAN DEFAULT FALSE,
  `next_lesson_date` DATE DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_group_progress` (`group_id`, `lesson_id`),
  KEY `idx_teacher_progress` (`teacher_id`),
  CONSTRAINT `fk_progress_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_progress_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `course_lessons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_progress_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- جدول تقييم الطلاب في الدروس
CREATE TABLE IF NOT EXISTS `student_lesson_progress` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` INT UNSIGNED NOT NULL,
  `group_progress_id` INT UNSIGNED NOT NULL,
  `attendance` ENUM('present','absent','late') NOT NULL DEFAULT 'present',
  `participation_score` INT DEFAULT NULL,
  `homework_score` INT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_student_lesson` (`student_id`, `group_progress_id`),
  CONSTRAINT `fk_slp_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_slp_progress` FOREIGN KEY (`group_progress_id`) REFERENCES `group_progress` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- جدول الإعدادات العامة
CREATE TABLE IF NOT EXISTS `app_settings` (
  `id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `settings_json` JSON NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
 
-- إدخال قيمة افتراضية إذا لم تكن موجودة
INSERT INTO `app_settings` (`id`, `settings_json`) VALUES
(1, JSON_OBJECT(
  'institutionName', '',
  'institutionEmail', '',
  'institutionPhone', '',
  'institutionAddress', '',
  'currency', 'EGP',
  'timezone', 'Africa/Cairo',
  'emailNotifications', false,
  'smsNotifications', false,
  'pushNotifications', false,
  'notifyOnNewStudent', false,
  'notifyOnPayment', false,
  'notifyOnAbsence', false,
  'maintenanceMode', false,
  'allowRegistration', true,
  'requireEmailVerification', false,
  'sessionTimeout', 30,
  'maxLoginAttempts', 5,
  'autoBackup', false,
  'backupFrequency', 'daily',
  'backupTime', '02:00'
))
ON DUPLICATE KEY UPDATE settings_json = settings_json;
 
-- جدول سجلات المراجعة
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(200) NOT NULL,
  `entity` VARCHAR(200) NOT NULL,
  `user` VARCHAR(150) NOT NULL,
  `role` ENUM('admin','supervisor','teacher','student') NOT NULL,
  `type` ENUM('create','update','delete','finance','other') NOT NULL DEFAULT 'other',
  `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_type` (`type`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
 

-- جدول المواعيد المتاحة للمعلمين
CREATE TABLE IF NOT EXISTS `teacher_available_slots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teacher_id` INT UNSIGNED NOT NULL,
  `day_of_week` ENUM('السبت','الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة') NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `is_available` BOOLEAN NOT NULL DEFAULT TRUE,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_teacher_slots` (`teacher_id`, `day_of_week`),
  CONSTRAINT `fk_slots_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
