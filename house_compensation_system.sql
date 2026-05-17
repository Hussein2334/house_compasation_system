-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 17, 2026 at 06:11 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `house_compensation_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `approvals`
--

CREATE TABLE `approvals` (
  `id` int(11) NOT NULL,
  `claim_id` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approval_stage` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('approved','rejected','pending') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action_performed` text DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `claims`
--

CREATE TABLE `claims` (
  `id` int(11) NOT NULL,
  `claimant_id` int(11) NOT NULL,
  `claim_number` varchar(100) NOT NULL,
  `project_name` varchar(255) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `property_type` varchar(100) DEFAULT NULL,
  `property_size` varchar(100) DEFAULT NULL,
  `gps_coordinates` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('submitted','valuation','legal_review','approved','rejected','paid') DEFAULT 'submitted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `claims`
--

INSERT INTO `claims` (`id`, `claimant_id`, `claim_number`, `project_name`, `district`, `property_type`, `property_size`, `gps_coordinates`, `description`, `status`, `created_at`) VALUES
(1, 2, 'HCS-2024-001', 'SGR Railway Project', 'Morogoro', 'residential', NULL, NULL, NULL, 'approved', '2026-05-17 10:29:29'),
(2, 2, 'HCS-2024-002', 'Road Expansion', 'Pwani', 'commercial', NULL, NULL, NULL, 'submitted', '2026-05-17 10:29:29');

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `claim_id` int(11) NOT NULL,
  `document_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `help_requests`
--

CREATE TABLE `help_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `category` enum('registration','claim','valuation','payment','technical','other') DEFAULT 'other',
  `status` enum('pending','in_progress','resolved','closed') DEFAULT 'pending',
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `assigned_to` int(11) DEFAULT NULL,
  `response` text DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

--
-- Dumping data for table `help_requests`
--

INSERT INTO `help_requests` (`id`, `user_id`, `full_name`, `email`, `phone`, `subject`, `message`, `category`, `status`, `priority`, `assigned_to`, `response`, `responded_by`, `responded_at`, `created_at`, `updated_at`) VALUES
(1, NULL, 'Abdulnasir Issa', 'abdulnasirissa14@gmail.com', '0647322678', 'kujisajili', 'nashindwa kujisajili', 'registration', 'pending', 'medium', NULL, NULL, NULL, NULL, '2026-05-17 18:43:51', '2026-05-17 18:43:51');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `claim_id` int(11) NOT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `payment_method` varchar(100) DEFAULT NULL,
  `transaction_reference` varchar(255) DEFAULT NULL,
  `payment_status` enum('pending','processed','completed') DEFAULT 'pending',
  `paid_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `nin` varchar(30) DEFAULT NULL,
  `role` enum('super_admin','claimant','valuer','legal_officer','finance_officer','commissioner') DEFAULT 'claimant',
  `password` varchar(255) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `phone`, `nin`, `role`, `password`, `status`, `created_at`) VALUES
(2, 'John Claimant', 'john@example.com', '0712345679', NULL, 'claimant', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active', '2026-05-17 10:29:29'),
(3, 'ABDALLA ABRAHMANI ABDALLA', 'hussein@gmail.com', '0775892103', NULL, 'claimant', '$2y$10$Zl9STKn3fiKnhWBAjSxT0OXR1Ggh4MqkVfirggv90k1J7.jWpwV/C', 'active', '2026-05-17 13:10:06'),
(4, 'Test User', 'test@example.com', '0712345678', NULL, 'claimant', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active', '2026-05-17 13:20:38'),
(5, 'Abdulnasir Issa', 'abdulnasirissa14@gmail.com', '0712345678', NULL, 'claimant', '$2y$10$hW/vbN83ETkYCJYPE9YJMudWDc06KS5wJ7GFAAdglZgNx.qE/o3xO', 'active', '2026-05-17 14:03:37'),
(6, 'Abdul Wakas', 'wakas@gmail.com', '6473226787', NULL, 'claimant', '$2y$10$zWzd1lM7pYggXOzFHjsSx.J.3ZU65O.8tmAZZgXH6ZqjrKQWsSjUq', 'active', '2026-05-17 14:13:13'),
(7, 'Abuu Mzimbili', 'mzimbili@gamil.com', '6473226787', NULL, 'claimant', '$2y$10$rynciHVegxQB.lyzaFkHA.rmVlR/XKBPAU.gZxS8Iu3lXjNNoTlsm', 'active', '2026-05-17 14:21:34'),
(8, 'KHAMIS ABDALLA ABRAHMANI', 'khamis@gmail.com', '+255658216348', NULL, 'claimant', '$2y$10$xNOnejmPOXsIGlNnXjVgbeJ8fG3OMfImBqzG9SCCa7P8dU12LWGsu', 'active', '2026-05-17 14:30:05'),
(9, 'form', 'form@gmail.com', '0712345678', NULL, 'claimant', '$2y$10$tOwl2cLu9Bbl/gvooR5Qs.UqFPg0wlo9cBT6XYqkdXQZTX.ZGAkkC', 'active', '2026-05-17 14:41:55'),
(10, 'swaleh', 'swaleh@gmail.com', '0647322678', '2002061271170001', 'claimant', '$2y$10$D2cceoS7ulfmSqg0F5ptDes/IAarS5CUwvEDqabeC/YPmNROJgwQK', 'active', '2026-05-17 14:02:18'),
(11, 'shee', 'shee@gmail.com', '0647322456', '2001061271170001', 'claimant', '$2y$10$Dworw3EvBAoOehtjWw8/ZOgUfAgnFm.EaBKSSKSJz3hgAyMYrV4m.', 'active', '2026-05-17 14:13:09'),
(12, 'Admin User', 'admin@hcs.go.tz', '0712345678', NULL, 'super_admin', '$2a$12$xZd/zvjcDhhxP5YZUXmAQ.bdl/mmjmJ6wpChE8/I8lEgnKfuEMpK.', 'active', '2026-05-17 15:57:07');

-- --------------------------------------------------------

--
-- Table structure for table `valuations`
--

CREATE TABLE `valuations` (
  `id` int(11) NOT NULL,
  `claim_id` int(11) NOT NULL,
  `valuer_id` int(11) DEFAULT NULL,
  `property_value` decimal(15,2) DEFAULT NULL,
  `disturbance_allowance` decimal(15,2) DEFAULT NULL,
  `transport_allowance` decimal(15,2) DEFAULT NULL,
  `total_compensation` decimal(15,2) DEFAULT NULL,
  `valuation_report` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `valuations`
--

INSERT INTO `valuations` (`id`, `claim_id`, `valuer_id`, `property_value`, `disturbance_allowance`, `transport_allowance`, `total_compensation`, `valuation_report`, `created_at`) VALUES
(1, 1, NULL, 25000000.00, 5000000.00, 1000000.00, 31000000.00, NULL, '2026-05-17 10:29:29');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `approvals`
--
ALTER TABLE `approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `claim_id` (`claim_id`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `claims`
--
ALTER TABLE `claims`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `claim_number` (`claim_number`),
  ADD KEY `claimant_id` (`claimant_id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `claim_id` (`claim_id`);

--
-- Indexes for table `help_requests`
--
ALTER TABLE `help_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `responded_by` (`responded_by`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `claim_id` (`claim_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `valuations`
--
ALTER TABLE `valuations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `claim_id` (`claim_id`),
  ADD KEY `valuer_id` (`valuer_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `approvals`
--
ALTER TABLE `approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `claims`
--
ALTER TABLE `claims`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `help_requests`
--
ALTER TABLE `help_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `valuations`
--
ALTER TABLE `valuations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `approvals`
--
ALTER TABLE `approvals`
  ADD CONSTRAINT `approvals_ibfk_1` FOREIGN KEY (`claim_id`) REFERENCES `claims` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `approvals_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `claims`
--
ALTER TABLE `claims`
  ADD CONSTRAINT `claims_ibfk_1` FOREIGN KEY (`claimant_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`claim_id`) REFERENCES `claims` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `help_requests`
--
ALTER TABLE `help_requests`
  ADD CONSTRAINT `help_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `help_requests_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `help_requests_ibfk_3` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`claim_id`) REFERENCES `claims` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `valuations`
--
ALTER TABLE `valuations`
  ADD CONSTRAINT `valuations_ibfk_1` FOREIGN KEY (`claim_id`) REFERENCES `claims` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `valuations_ibfk_2` FOREIGN KEY (`valuer_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
