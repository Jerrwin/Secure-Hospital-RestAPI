-- 1. ROLES
CREATE TABLE IF NOT EXISTS `roles` (
    `id` int NOT NULL AUTO_INCREMENT,
    `NAME` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `NAME` (`NAME`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. USERS (Admins, Providers, Receptionists)
CREATE TABLE IF NOT EXISTS `users` (
    `id` int NOT NULL AUTO_INCREMENT,
    `tenant_id` int NOT NULL DEFAULT 1,
    `NAME` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE,
    `PASSWORD` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `role_id` int NOT NULL,
    `STATUS` enum('active','inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `fk_users_role` (`role_id`),
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. STAFF (Extended profile for users)
CREATE TABLE IF NOT EXISTS `staff` (
    `id` int NOT NULL AUTO_INCREMENT,
    `tenant_id` int NOT NULL DEFAULT 1,
    `user_id` int NOT NULL,
    `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `gender` enum('male','female','other') COLLATE utf8mb4_unicode_ci NOT NULL,
    `address` text COLLATE utf8mb4_unicode_ci,
    `phone_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `is_active` tinyint(1) DEFAULT '1',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` datetime DEFAULT NULL,
    `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
    PRIMARY KEY (`id`),
    UNIQUE KEY `user_id` (`user_id`),
    CONSTRAINT `fk_staff_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. PATIENTS
CREATE TABLE IF NOT EXISTS `patients` (
    `id` int NOT NULL AUTO_INCREMENT,
    `tenant_id` int NOT NULL DEFAULT 1,
    `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `last_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL UNIQUE,
    `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `dob` date DEFAULT NULL,
    `gender` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `medical_history` text COLLATE utf8mb4_unicode_ci,
    `created_by` int DEFAULT NULL,
    `deleted_at` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_patient_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. APPOINTMENTS
CREATE TABLE IF NOT EXISTS `appointments` (
    `id` int NOT NULL AUTO_INCREMENT,
    `tenant_id` int NOT NULL DEFAULT 1,
    `patient_id` int NOT NULL,
    `provider_id` int NOT NULL,
    `created_by` int NOT NULL,
    `appointment_date` date DEFAULT NULL,
    `start_time` time DEFAULT NULL,
    `end_time` time DEFAULT NULL,
    `STATUS` enum('scheduled','completed','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'scheduled',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_app_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_app_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_app_provider` FOREIGN KEY (`provider_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. APPOINTMENT NOTES
CREATE TABLE IF NOT EXISTS `appointment_notes` (
    `id` int NOT NULL AUTO_INCREMENT,
    `appointment_id` int NOT NULL,
    `user_id` int NOT NULL,
    `note` text COLLATE utf8mb4_unicode_ci NOT NULL,
    `is_private` tinyint(1) DEFAULT '0',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. INVOICES
CREATE TABLE IF NOT EXISTS `invoices` (
    `id` int NOT NULL AUTO_INCREMENT,
    `tenant_id` int NOT NULL DEFAULT 1,
    `appointment_id` int NOT NULL,
    `patient_id` int NOT NULL,
    `amount` decimal(10,2) DEFAULT '0.00',
    `STATUS` enum('pending','paid') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_invoice_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_invoice_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. PAYMENTS
CREATE TABLE IF NOT EXISTS `payments` (
    `id` int NOT NULL AUTO_INCREMENT,
    `invoice_id` int NOT NULL,
    `amount` decimal(10,2) NOT NULL,
    `method` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
    `transaction_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `payment_date` date DEFAULT NULL,
    `STATUS` enum('success','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'success',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_payment_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. PRESCRIPTIONS
CREATE TABLE IF NOT EXISTS `prescriptions` (
    `id` int NOT NULL AUTO_INCREMENT,
    `tenant_id` int NOT NULL DEFAULT 1,
    `appointment_id` int NOT NULL,
    `provider_id` int DEFAULT NULL,
    `notes` text COLLATE utf8mb4_unicode_ci,
    `STATUS` enum('created','verified','dispensed') COLLATE utf8mb4_unicode_ci DEFAULT 'created',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_presc_app` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_presc_provider` FOREIGN KEY (`provider_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. REFRESH TOKENS
CREATE TABLE IF NOT EXISTS `refresh_tokens` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `token` text COLLATE utf8mb4_unicode_ci,
    `expiry_date` datetime DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `user_type` enum('system_admin','users') COLLATE utf8mb4_unicode_ci NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. INSERT DEFAULT ROLES
INSERT IGNORE INTO `roles` (`id`, `NAME`) VALUES
(1, 'Admin'),
(2, 'Provider'),
(3, 'Nurse'),
(4, 'Pharmacist'),
(5, 'Receptionist'),
(6, 'Patient');