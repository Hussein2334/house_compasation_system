-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 24, 2026 at 01:00 AM
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

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action_performed`, `ip_address`, `created_at`) VALUES
(1, 12, 'LOGOUT ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 11:58:11'),
(2, 12, 'LOGIN_SUCCESS ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 11:58:38'),
(3, 12, 'LOGOUT ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 11:59:36'),
(4, 15, 'REGISTER ON users (ID: 15) - {\"new\":{\"full_name\":\"Aisha Kombo\",\"email\":\"aisha@hcs.go.tz\",\"phone\":\"0765683256\",\"nin\":\"20010922010009009\",\"role\":\"claimant\"}}', '127.0.0.1', '2026-05-23 12:01:00'),
(5, 12, 'LOGIN_SUCCESS ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 12:01:06'),
(6, 12, 'CREATE_CLAIM ON claims (ID: 8) - {\"new\":{\"claim_number\":\"HCS-2026-4ECA7C\",\"claimant_id\":6,\"project_name\":\"Zanzibar Development\",\"has_valuation\":false}}', '127.0.0.1', '2026-05-23 12:03:01'),
(7, 12, 'CLEAR_OLD_AUDIT_LOGS ON audit_logs - {\"new\":{\"days\":90,\"deleted\":0}}', '127.0.0.1', '2026-05-23 12:04:01'),
(8, 12, 'UPDATE_HELP_REQUEST ON help_requests (ID: 1)', '127.0.0.1', '2026-05-23 12:21:43'),
(9, 12, 'UPDATE_HELP_REQUEST ON help_requests (ID: 1)', '127.0.0.1', '2026-05-23 12:22:13'),
(10, 12, 'UPDATE_SYSTEM_SETTINGS ON system_settings - {\"new\":{\"category\":\"general\"}}', '127.0.0.1', '2026-05-23 12:29:28'),
(11, 12, 'UPDATE_PROFILE ON users (ID: 12)', '127.0.0.1', '2026-05-23 12:36:07'),
(12, 12, 'UPDATE_NOTIFICATION_SETTINGS ON user_settings (ID: 12)', '127.0.0.1', '2026-05-23 12:36:50'),
(13, 12, 'LOGOUT ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 12:40:28'),
(14, 15, 'LOGIN_SUCCESS ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 12:40:51'),
(15, 15, 'CREATE_CLAIM ON claims (ID: 10) - {\"new\":{\"claim_number\":\"HCS-2026-48B417\",\"project_name\":\"Arusha Tourism\"}}', '127.0.0.1', '2026-05-23 13:07:16'),
(16, 15, 'CREATE_CLAIM ON claims (ID: 13) - {\"new\":{\"claim_number\":\"HCS-2026-310C1F\",\"project_name\":\"Arusha Tourism\"}}', '127.0.0.1', '2026-05-23 13:08:19'),
(17, 15, 'CREATE_CLAIM ON claims (ID: 14) - {\"new\":{\"claim_number\":\"HCS-2026-0A70D5\",\"project_name\":\"Arusha Tourism\"}}', '127.0.0.1', '2026-05-23 13:08:32'),
(18, 15, 'CREATE_CLAIM ON claims (ID: 15) - {\"new\":{\"claim_number\":\"HCS-2026-8B0F76\",\"project_name\":\"Arusha Tourism\"}}', '127.0.0.1', '2026-05-23 13:11:36'),
(19, 15, 'CREATE_CLAIM ON claims (ID: 16) - {\"new\":{\"claim_number\":\"HCS-2026-AD6DBA\",\"project_name\":\"Kigoma Port\"}}', '127.0.0.1', '2026-05-23 13:38:18'),
(20, 15, 'CREATE_CLAIM ON claims (ID: 17) - {\"new\":{\"claim_number\":\"HCS-2026-140EC5\",\"project_name\":\"Zanzibar Development\"}}', '127.0.0.1', '2026-05-23 20:13:21'),
(21, 15, 'CREATE_CLAIM ON claims (ID: 18) - {\"new\":{\"claim_number\":\"HCS-2026-FC9697\",\"project_name\":\"Zanzibar Development\"}}', '127.0.0.1', '2026-05-23 20:16:31'),
(22, 15, 'UPLOAD_DOCUMENT ON documents (ID: 18)', '127.0.0.1', '2026-05-23 20:49:15'),
(23, 15, 'DOWNLOAD_DOCUMENT ON documents (ID: 1)', '127.0.0.1', '2026-05-23 20:49:29'),
(24, 15, 'UPDATE_PROFILE ON users (ID: 15)', '127.0.0.1', '2026-05-23 20:54:50'),
(25, 15, 'UPDATE_PROFILE ON users (ID: 15)', '127.0.0.1', '2026-05-23 20:55:10'),
(26, 15, 'CREATE_CLAIM ON claims (ID: 19) - {\"new\":{\"claim_number\":\"HCS-2026-F12DBC\",\"project_name\":\"SGR Railway Project\"}}', '127.0.0.1', '2026-05-23 21:16:15'),
(27, 15, 'DELETE_DOCUMENT ON documents (ID: 1)', '127.0.0.1', '2026-05-23 21:39:37'),
(28, 15, 'LOGOUT ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 21:41:07'),
(29, 12, 'LOGIN_SUCCESS ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 21:42:13'),
(30, 12, 'DELETE_CLAIM ON claims (ID: 19)', '127.0.0.1', '2026-05-23 21:43:42'),
(31, 12, 'DELETE_CLAIM ON claims (ID: 10)', '127.0.0.1', '2026-05-23 21:43:56'),
(32, 12, 'DELETE_CLAIM ON claims (ID: 18)', '127.0.0.1', '2026-05-23 21:44:07'),
(33, 12, 'DELETE_CLAIM ON claims (ID: 11)', '127.0.0.1', '2026-05-23 21:44:21'),
(34, 12, 'DELETE_CLAIM ON claims (ID: 9)', '127.0.0.1', '2026-05-23 21:44:34'),
(35, 12, 'DELETE_CLAIM ON claims (ID: 17)', '127.0.0.1', '2026-05-23 21:44:47'),
(36, 12, 'DELETE_CLAIM ON claims (ID: 16)', '127.0.0.1', '2026-05-23 21:44:59'),
(37, 12, 'DELETE_CLAIM ON claims (ID: 15)', '127.0.0.1', '2026-05-23 21:45:13'),
(38, 12, 'DELETE_CLAIM ON claims (ID: 12)', '127.0.0.1', '2026-05-23 21:45:36'),
(39, 12, 'DELETE_CLAIM ON claims (ID: 13)', '127.0.0.1', '2026-05-23 21:45:48'),
(40, 12, 'UPDATE_CLAIM_STATUS ON claims (ID: 14) - {\"old\":{\"status\":\"submitted\"},\"new\":{\"status\":\"submitted\"}}', '127.0.0.1', '2026-05-23 21:46:10'),
(41, 12, 'UPDATE_CLAIM_STATUS ON claims (ID: 14) - {\"old\":{\"status\":\"submitted\"},\"new\":{\"status\":\"valuation\"}}', '127.0.0.1', '2026-05-23 21:46:24'),
(42, 12, 'LOGOUT ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 21:46:31'),
(43, 15, 'LOGIN_SUCCESS ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 21:47:21'),
(44, 15, 'LOGOUT ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 21:48:01'),
(45, 12, 'LOGIN_SUCCESS ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 21:48:24'),
(46, 12, 'UPDATE_CLAIM_WITH_VALUATION ON claims (ID: 14)', '127.0.0.1', '2026-05-23 21:49:20'),
(47, 12, 'UPDATE_CLAIM_STATUS ON claims (ID: 14) - {\"old\":{\"status\":\"legal_review\"},\"new\":{\"status\":\"approved\"}}', '127.0.0.1', '2026-05-23 21:49:36'),
(48, 12, 'LOGOUT ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 21:49:43'),
(49, 12, 'LOGIN_SUCCESS ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 21:50:15'),
(50, 12, 'LOGOUT ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 21:50:24'),
(51, 15, 'LOGIN_SUCCESS ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 21:50:54'),
(52, 15, 'UPLOAD_DOCUMENT ON documents (ID: 2)', '127.0.0.1', '2026-05-23 21:53:11'),
(53, 15, 'SUBMIT_HELP_REQUEST ON help_requests (ID: 2)', '127.0.0.1', '2026-05-23 22:03:13'),
(54, 15, 'LOGOUT ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 22:04:24'),
(55, 12, 'LOGIN_SUCCESS ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 22:04:40'),
(56, 12, 'LOGOUT ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 22:05:56'),
(57, 15, 'LOGIN_SUCCESS ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 22:06:16'),
(58, 15, 'LOGOUT ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 22:10:36'),
(59, 12, 'LOGIN_SUCCESS ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"192.168.100.5\"}}', '192.168.100.5', '2026-05-23 22:15:53'),
(60, 12, 'LOGOUT ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"192.168.100.5\"}}', '192.168.100.5', '2026-05-23 22:16:44'),
(61, 15, 'LOGIN_SUCCESS ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"192.168.100.5\"}}', '192.168.100.5', '2026-05-23 22:17:27'),
(62, 15, 'LOGOUT ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"192.168.100.5\"}}', '192.168.100.5', '2026-05-23 22:18:23'),
(63, 12, 'LOGIN_SUCCESS ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"192.168.100.5\"}}', '192.168.100.5', '2026-05-23 22:18:32'),
(64, 12, 'LOGOUT ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"192.168.100.5\"}}', '192.168.100.5', '2026-05-23 22:19:11'),
(65, 15, 'LOGIN_SUCCESS ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"192.168.100.5\"}}', '192.168.100.5', '2026-05-23 22:19:23'),
(66, 15, 'LOGIN_SUCCESS ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 22:21:45'),
(67, 15, 'LOGOUT ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"192.168.100.5\"}}', '192.168.100.5', '2026-05-23 22:21:57'),
(68, 15, 'LOGIN_SUCCESS ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"192.168.100.5\"}}', '192.168.100.5', '2026-05-23 22:22:08'),
(69, 15, 'LOGOUT ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"192.168.100.5\"}}', '192.168.100.5', '2026-05-23 22:22:53'),
(70, 12, 'LOGIN_SUCCESS ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"192.168.100.5\"}}', '192.168.100.5', '2026-05-23 22:23:04'),
(71, 15, 'LOGOUT ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 22:23:49'),
(72, 12, 'LOGIN_SUCCESS ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 22:24:04'),
(73, 12, 'UPDATE_CLAIM_WITH_VALUATION ON claims (ID: 8)', '127.0.0.1', '2026-05-23 22:33:33'),
(74, 12, 'UPDATE_CLAIM_STATUS ON claims (ID: 8) - {\"old\":{\"status\":\"submitted\"},\"new\":{\"status\":\"paid\"}}', '127.0.0.1', '2026-05-23 22:33:48'),
(75, 12, 'UPDATE_CLAIM_WITH_VALUATION ON claims (ID: 14)', '127.0.0.1', '2026-05-23 22:43:52'),
(76, 12, 'CREATE_PAYMENT ON payments (ID: 14)', '127.0.0.1', '2026-05-23 22:57:16'),
(77, 12, 'LOGOUT ON users (ID: 12) - {\"new\":{\"email\":\"admin@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 22:57:56'),
(78, 15, 'LOGIN_SUCCESS ON users (ID: 15) - {\"new\":{\"email\":\"aisha@hcs.go.tz\",\"ip\":\"127.0.0.1\"}}', '127.0.0.1', '2026-05-23 22:58:17');

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
  `ward` varchar(100) DEFAULT NULL,
  `village` varchar(100) DEFAULT NULL,
  `property_type` varchar(100) DEFAULT NULL,
  `property_size` varchar(100) DEFAULT NULL,
  `claim_amount` decimal(15,2) DEFAULT NULL,
  `valuation_amount` decimal(15,2) DEFAULT NULL,
  `approved_amount` decimal(15,2) DEFAULT NULL,
  `decision_date` date DEFAULT NULL,
  `gps_coordinates` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('submitted','valuation','legal_review','approved','rejected','paid') DEFAULT 'submitted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `claims`
--

INSERT INTO `claims` (`id`, `claimant_id`, `claim_number`, `project_name`, `district`, `ward`, `village`, `property_type`, `property_size`, `claim_amount`, `valuation_amount`, `approved_amount`, `decision_date`, `gps_coordinates`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 2, 'HCS-2024-001', 'Zanzibar Development', 'Mjini Magharibi', NULL, NULL, 'agricultural', '340', NULL, NULL, NULL, NULL, NULL, 'maelekezo ya mfano yameupdetiwa zaidi', 'approved', '2026-05-17 10:29:29', '2026-05-22 20:19:27'),
(3, 2, 'TEST-20260522223118', 'Zanzibar Development', 'Dar es Salaam', NULL, NULL, 'residential', '', NULL, NULL, NULL, NULL, NULL, '', 'approved', '2026-05-22 20:31:18', '2026-05-22 22:04:47'),
(5, 3, 'HCS-2026-A7A45B', 'SBR', 'MOTOTO', 'mjini', 'muwembe kokoto', 'residential', '500', 10.00, NULL, NULL, NULL, '', '', 'submitted', '2026-05-22 20:55:54', '2026-05-22 20:55:54'),
(6, 13, 'HCS-2026-30BE6E', 'Zanzibar Development', 'Unguja West', 'mjini', 'muwembe kokoto', 'residential', '500', NULL, NULL, NULL, NULL, '-6383920', '', 'submitted', '2026-05-22 20:57:07', '2026-05-22 20:57:07'),
(7, 11, 'HCS-2026-C85B0A', 'SGR Railway Project', 'Manyara', 'manyara', 'masaai', 'commercial', '500', NULL, NULL, NULL, NULL, '', '', 'submitted', '2026-05-22 22:12:12', '2026-05-22 22:12:12'),
(8, 6, 'HCS-2026-4ECA7C', 'Zanzibar Development', 'Unguja West', 'manyara', 'masaai', 'other', '500', 1000000.00, NULL, NULL, NULL, '', '', 'paid', '2026-05-23 12:03:00', '2026-05-23 22:33:48'),
(14, 15, 'HCS-2026-0A70D5', 'Arusha Tourism', 'Kilimanjaro', 'mjini', 'masaai', 'residential', '500', NULL, NULL, NULL, NULL, '', '', 'paid', '2026-05-23 13:08:32', '2026-05-23 22:57:16');

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

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`id`, `claim_id`, `document_name`, `file_path`, `uploaded_at`) VALUES
(2, 14, 'Hati ya nyumba', '1779573191_15_Hati_ya_nyumba.pdf', '2026-05-23 21:53:11');

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
(1, NULL, 'Abdulnasir Issa', 'abdulnasirissa14@gmail.com', '0647322678', 'kujisajili', 'nashindwa kujisajili', 'registration', 'pending', 'urgent', 12, 'inashulikiwa account', 12, '2026-05-23 15:22:13', '2026-05-17 18:43:51', '2026-05-23 15:22:13'),
(2, 15, 'Aisha Kombo', 'aisha@hcs.go.tz', '0756464274', 'Swali', 'maelekezo ya swali kwa ajili ya test', 'payment', 'pending', 'medium', NULL, NULL, NULL, NULL, '2026-05-24 01:03:13', '2026-05-24 01:03:13');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('claim','valuation','payment','system','general') DEFAULT 'general',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`, `created_at`) VALUES
(2, 10, 'Dai Jipya Limewasilishwa', 'Mwombaji Aisha Kombo amewasilisha dai jipya: HCS-2026-48B417', 'claim', 0, '2026-05-23 13:07:16'),
(3, 15, 'Dai Limewasilishwa Kikamilifu', 'Dai lako namba HCS-2026-48B417 limepokelewa na linachakatwa. Tafadhali subiri maendeleo zaidi.', 'claim', 0, '2026-05-23 13:07:16'),
(6, 10, 'Dai Jipya Limewasilishwa', 'Mwombaji Aisha Kombo amewasilisha dai jipya: HCS-2026-310C1F', 'claim', 0, '2026-05-23 13:08:19'),
(7, 15, 'Dai Limewasilishwa Kikamilifu', 'Dai lako namba HCS-2026-310C1F limepokelewa na linachakatwa. Tafadhali subiri maendeleo zaidi.', 'claim', 0, '2026-05-23 13:08:19'),
(8, 10, 'Dai Jipya Limewasilishwa', 'Mwombaji Aisha Kombo amewasilisha dai jipya: HCS-2026-0A70D5', 'claim', 0, '2026-05-23 13:08:32'),
(9, 15, 'Dai Limewasilishwa Kikamilifu', 'Dai lako namba HCS-2026-0A70D5 limepokelewa na linachakatwa. Tafadhali subiri maendeleo zaidi.', 'claim', 0, '2026-05-23 13:08:32'),
(10, 10, 'Dai Jipya Limewasilishwa', 'Mwombaji Aisha Kombo amewasilisha dai jipya: HCS-2026-8B0F76', 'claim', 0, '2026-05-23 13:11:36'),
(11, 15, 'Dai Limewasilishwa Kikamilifu', 'Dai lako namba HCS-2026-8B0F76 limepokelewa na linachakatwa. Tafadhali subiri maendeleo zaidi.', 'claim', 0, '2026-05-23 13:11:36'),
(12, 10, 'Dai Jipya Limewasilishwa', 'Mwombaji Aisha Kombo amewasilisha dai jipya: HCS-2026-AD6DBA', 'claim', 0, '2026-05-23 13:38:18'),
(13, 15, 'Dai Limewasilishwa Kikamilifu', 'Dai lako namba HCS-2026-AD6DBA limepokelewa na linachakatwa. Tafadhali subiri maendeleo zaidi.', 'claim', 0, '2026-05-23 13:38:18'),
(14, 10, 'Dai Jipya Limewasilishwa', 'Mwombaji Aisha Kombo amewasilisha dai jipya: HCS-2026-140EC5', 'claim', 0, '2026-05-23 20:13:21'),
(15, 15, 'Dai Limewasilishwa Kikamilifu', 'Dai lako namba HCS-2026-140EC5 limepokelewa na linachakatwa. Tafadhali subiri maendeleo zaidi.', 'claim', 0, '2026-05-23 20:13:21'),
(16, 10, 'Dai Jipya Limewasilishwa', 'Mwombaji Aisha Kombo amewasilisha dai jipya: HCS-2026-FC9697', 'claim', 0, '2026-05-23 20:16:31'),
(17, 15, 'Dai Limewasilishwa Kikamilifu', 'Dai lako namba HCS-2026-FC9697 limepokelewa na linachakatwa. Tafadhali subiri maendeleo zaidi.', 'claim', 0, '2026-05-23 20:16:31'),
(18, 15, 'Hati Imepakiwa', 'Umepakia hati: - kwa dai namba HCS-2026-140EC5', 'system', 0, '2026-05-23 20:49:15'),
(19, 10, 'Dai Jipya Limewasilishwa', 'Mwombaji Aisha Kombo amewasilisha dai jipya: HCS-2026-F12DBC', 'claim', 0, '2026-05-23 21:16:15'),
(20, 15, 'Dai Limewasilishwa Kikamilifu', 'Dai lako namba HCS-2026-F12DBC limepokelewa na linachakatwa. Tafadhali subiri maendeleo zaidi.', 'claim', 0, '2026-05-23 21:16:15'),
(21, 15, 'Hati Imepakiwa', 'Umepakia hati: Hati ya nyumba', 'system', 0, '2026-05-23 21:53:11'),
(22, 10, 'Ombi Jipya la Msaada', 'Mwombaji Aisha Kombo ametuma ombi la msaada: Swali', 'system', 0, '2026-05-23 22:03:13'),
(23, 15, 'Malipo Yamefanywa', 'Malipo yako ya TZS 125,000 yamefanywa kikamilifu.', 'payment', 0, '2026-05-23 22:57:16');

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
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `claim_id`, `amount`, `payment_method`, `transaction_reference`, `payment_status`, `paid_at`, `created_by`, `updated_by`, `notes`) VALUES
(1, 14, 125000.00, 'bank_transfer', '', 'completed', '2026-05-23 21:57:16', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('text','number','boolean','json') DEFAULT 'text',
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT 'general',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `category`, `created_at`, `updated_at`) VALUES
(1, 'site_name', 'House Compensation System', 'text', 'Jina la mfumo', 'general', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(2, 'site_logo', '', 'text', 'URL ya logo ya mfumo', 'general', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(3, 'timezone', 'Africa/Dar_es_Salaam', 'text', 'Majira ya saa', 'general', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(4, 'date_format', 'd/m/Y', 'text', 'Muundo wa tarehe', 'general', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(5, 'items_per_page', '15', 'number', 'Idadi ya rekodi kwa kila ukurasa', 'general', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(6, 'email_notifications', '1', 'boolean', 'Tuma arifa kwa barua pepe', 'notifications', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(7, 'admin_email', 'admin@hcs.go.tz', 'text', 'Barua pepe ya msimamizi', 'notifications', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(8, 'smtp_host', '', 'text', 'SMTP server host', 'notifications', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(9, 'smtp_port', '587', 'number', 'SMTP port', 'notifications', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(10, 'smtp_username', '', 'text', 'SMTP username', 'notifications', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(11, 'smtp_password', '', 'text', 'SMTP password', 'notifications', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(12, 'claim_deadline_days', '30', 'number', 'Muda wa kuwasilisha dai (siku)', 'claims', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(13, 'valuation_deadline_days', '14', 'number', 'Muda wa kukamilisha tathmini (siku)', 'claims', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(14, 'auto_approve_valuation', '0', 'boolean', 'Kubali tathmini moja kwa moja', 'claims', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(15, 'payment_methods', '[\"bank_transfer\",\"mobile_money\",\"cash\",\"cheque\"]', 'json', 'Njia za malipo zinazokubalika', 'payments', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(16, 'min_payment_amount', '1000', 'number', 'Kiwango cha chini cha malipo (TZS)', 'payments', '2026-05-23 12:28:42', '2026-05-23 12:28:42'),
(17, 'max_payment_amount', '1000000000', 'number', 'Kiwango cha juu cha malipo (TZS)', 'payments', '2026-05-23 12:28:43', '2026-05-23 12:28:43'),
(18, 'session_timeout', '3600', 'number', 'Muda wa kukaa kwenye mfumo (sekunde)', 'security', '2026-05-23 12:28:43', '2026-05-23 12:28:43'),
(19, 'max_login_attempts', '5', 'number', 'Idadi ya majaribio ya kuingia', 'security', '2026-05-23 12:28:43', '2026-05-23 12:28:43'),
(20, 'password_expiry_days', '90', 'number', 'Nenosiri linabadilishwa baada ya siku', 'security', '2026-05-23 12:28:43', '2026-05-23 12:28:43'),
(21, 'two_factor_auth', '0', 'boolean', 'Washa uthibitishaji wa hatua mbili', 'security', '2026-05-23 12:28:43', '2026-05-23 12:28:43'),
(22, 'maintenance_mode', '0', 'boolean', 'Weka mfumo katika hali ya matengenezo', 'maintenance', '2026-05-23 12:28:43', '2026-05-23 12:28:43'),
(23, 'maintenance_message', 'Mfumo uko kwenye matengenezo. Tafadhali jaribu tena baadaye.', 'text', 'Ujumbe wa matengenezo', 'maintenance', '2026-05-23 12:28:43', '2026-05-23 12:28:43'),
(24, 'backup_enabled', '1', 'boolean', 'Washa backup otomatiki', 'maintenance', '2026-05-23 12:28:43', '2026-05-23 12:28:43'),
(25, 'backup_frequency', 'daily', 'text', 'Mara ngapi backup inafanywa (daily, weekly, monthly)', 'maintenance', '2026-05-23 12:28:43', '2026-05-23 12:28:43');

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
(10, 'swaleh', 'swaleh@gmail.com', '0647322678', '2002061271170001', 'super_admin', '$2y$10$D2cceoS7ulfmSqg0F5ptDes/IAarS5CUwvEDqabeC/YPmNROJgwQK', 'active', '2026-05-17 14:02:18'),
(11, 'shee', 'shee@gmail.com', '0647322456', '2001061271170001', 'claimant', '$2y$10$Dworw3EvBAoOehtjWw8/ZOgUfAgnFm.EaBKSSKSJz3hgAyMYrV4m.', 'active', '2026-05-17 14:13:09'),
(12, 'Hussein Abdulrahman', 'admin@hcs.go.tz', '0712345678', '20010922010009001', 'super_admin', '$2a$12$Nsgd7pSsb0Rt89e77a1bJ.klWYiW/rQ1C90w48Ab955KAflAkcpKu', 'active', '2026-05-17 15:57:07'),
(13, 'Hussein Abdulrahman', 'husseinali234@gmail.com', '0658216356', '20010922010009002', 'valuer', '$2y$10$.9hJdUnYc2QISPUpxvnFAutb7mV6PXhtHLdxSIm8BQ1xj9IoF3itq', 'active', '2026-05-22 17:56:48'),
(14, 'salim juma salim', 'salim@gmail.com', '0658216348', '20010922010009008', 'claimant', '$2y$10$9RTshJsJ57.1HIMx7RHo8uONBlA76Pmco8d/p0Jth/MBE1SkEaJzS', 'active', '2026-05-22 21:23:01'),
(15, 'Aisha Kombo', 'aisha@hcs.go.tz', '0765683256', '20020922010009009', 'claimant', '$2y$10$taGv/TWVoZGhEn/uee4I2.7jsiKNz8iy66xFigoakCbxyusVeSxTy', 'active', '2026-05-23 11:01:00');

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_settings`
--

INSERT INTO `user_settings` (`id`, `user_id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES
(1, 12, 'email_notifications', '1', '2026-05-23 12:36:50', '2026-05-23 12:36:50'),
(2, 12, 'sms_notifications', '1', '2026-05-23 12:36:50', '2026-05-23 12:36:50');

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
(1, 1, NULL, 25000000.00, 5000000.00, 1000000.00, 31000000.00, NULL, '2026-05-17 10:29:29'),
(2, 3, 12, 45000000.00, 1000000.00, 10000.00, 46010000.00, '', '2026-05-22 22:04:24'),
(3, 14, 12, 100000.00, 5000.00, 20000.00, 125000.00, '', '2026-05-23 21:49:20'),
(4, 8, 12, 42300000.00, 45000000.00, 3000000000000.00, 3000087300000.00, '', '2026-05-23 22:33:33');

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
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `claim_id` (`claim_id`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_updated_by` (`updated_by`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_setting` (`user_id`,`setting_key`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT for table `claims`
--
ALTER TABLE `claims`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `help_requests`
--
ALTER TABLE `help_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `user_settings`
--
ALTER TABLE `user_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `valuations`
--
ALTER TABLE `valuations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

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
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`claim_id`) REFERENCES `claims` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD CONSTRAINT `user_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
