-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Oct 24, 2026 at 04:44 PM
-- Server version: 11.8.9-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u859404188_Stoplordc`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_profiles`
--

CREATE TABLE `admin_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `alternative_number` varchar(15) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `pin_code` varchar(10) DEFAULT NULL,
  `date_of_joining` date DEFAULT NULL,
  `bank_name` varchar(150) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `ifsc_code` varchar(20) DEFAULT NULL,
  `account_owner` varchar(150) DEFAULT NULL,
  `passbook_photo` varchar(255) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `aadhaar_number` varchar(20) DEFAULT NULL,
  `aadhaar_photo` varchar(255) DEFAULT NULL,
  `pan_number` varchar(20) DEFAULT NULL,
  `pan_photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `admin_profiles`
--

INSERT INTO `admin_profiles` (`id`, `user_id`, `alternative_number`, `dob`, `address`, `city`, `state`, `pin_code`, `date_of_joining`, `bank_name`, `account_number`, `ifsc_code`, `account_owner`, `passbook_photo`, `profile_photo`, `aadhaar_number`, `aadhaar_photo`, `pan_number`, `pan_photo`, `created_at`, `updated_at`) VALUES
(1, 5, '8759177098', '1997-02-04', 'Bhagwangola, Murshidabad', 'Murshidabad', 'West Bengal', '742135', '2026-07-30', 'State Bank Of India', '45159046687', 'SBIN0014040', 'MD TOUSIF ALI', '1788851077_passbook_banner.jpg', '1788851077_profile_tousif.JPG', '365346305425', '1788851077_aadhaar_AAFHAR FRONT .png', 'BVXPA6688R', '1788851077_pan_PAN CARD.jpg', '2026-09-08 07:04:37', '2026-09-08 07:04:37'),
(2, 6, '', '1996-01-01', 'Bhagwangola, Murshidabad', 'Murshidabad', 'West Bengal', '742135', '2026-07-30', 'Demo Bank', '45159046687', 'AIRT0123456', 'jhgytr', '1788851269_passbook_banner.jpg', NULL, '789854544444', '1788851269_aadhaar_banner.jpg', 'BXHCA6655S', '1788851269_pan_banner.jpg', '2026-09-08 07:07:49', '2026-09-08 07:07:49'),
(5, 12, '', '2016-09-05', '', '', '', '', '2026-09-13', 'Bdbx', '363636363636363', 'BKID8876536', 'Hxbxxbb', '1789296422_passbook_IMG-20260913-WA0001.jpg', '1789296422_profile_408.jpg', '111148484848', '1789296422_aadhaar_408.jpg', 'XXHHD5635H', '1789296422_pan_414.jpg', '2026-09-13 10:47:02', '2026-09-13 10:47:02'),
(6, 13, '7688837878', '2010-09-16', 'Biswa Bangla Sarani', 'Kolkata', 'West Bengal', '700107', '2026-09-14', 'Tyggjghh', 'Subhajit Poddar', 'FYJKI878867', 'Subhajit Poddar', '1789371867_passbook_IMG-20260914-WA0000.jpg', NULL, '657689878997', '1789371867_aadhaar_1000538038.jpg', 'YHJIH5656H', '1789371867_pan_1000538038.jpg', '2026-09-14 07:44:27', '2026-09-14 07:44:27');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(20) NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) NOT NULL,
  `target_id` int(11) DEFAULT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `role`, `action`, `target_type`, `target_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 5, 'admin', 'logout', 'user', 5, 'Admin logged out (Reason: inactivity)', '115.96.127.195', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-10 13:13:09'),
(2, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '106.202.90.136', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-12 13:13:34'),
(3, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:7cd0:3640:9910:a89b', 'Mozilla/5.0 (Linux; Android 10; K; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/149.0.0.0 Mobile Safari/537.36', '2026-09-13 07:50:38'),
(4, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:2253:80fd:7a4c:d449', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 08:47:26'),
(5, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b704:5787:2253:80fd:7a4c:d449', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 08:48:24'),
(6, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:2253:80fd:7a4c:d449', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 08:49:01'),
(7, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:7cd0:3640:9910:a89b', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13 08:52:26'),
(8, 1, 'super_admin', 'change_password', 'user', 1, 'Changed account password', '2401:4900:b704:5787:7cd0:3640:9910:a89b', NULL, '2026-09-13 08:53:43'),
(9, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:74eb:57a3::de:ff4c', 'Mozilla/5.0 (Linux; Android 13; IV2201 Build/TP1A.220905.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.202 Mobile Safari/537.36', '2026-09-13 08:57:52'),
(10, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b704:5787:7cd0:3640:9910:a89b', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13 09:01:21'),
(11, 5, 'admin', 'login', 'user', 5, 'Admin logged in', '2401:4900:b704:5787:7cd0:3640:9910:a89b', NULL, '2026-09-13 09:01:25'),
(12, 5, 'admin', 'leave_request', 'leave_2026-09-16', 5, 'Approved|Sick Leave|bhgfds', '2401:4900:b704:5787:7cd0:3640:9910:a89b', NULL, '2026-09-13 09:01:37'),
(13, 1, 'super_admin', 'delete_admin', 'user', 5, 'Soft-deleted Admin ID 5', '2401:4900:b704:5787:2253:80fd:7a4c:d449', NULL, '2026-09-13 09:04:38'),
(14, 5, 'admin', 'logout', 'user', 5, 'Admin logged out (Reason: manual)', '2401:4900:b704:5787:7cd0:3640:9910:a89b', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13 09:05:55'),
(15, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:7cd0:3640:9910:a89b', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13 09:13:26'),
(16, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '106.202.80.207', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 09:55:46'),
(17, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:ad1c:19b6:9df2:b08c', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 09:59:51'),
(18, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b704:5787:fe8:e97c:99d9:a072', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 10:41:25'),
(19, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:fe8:e97c:99d9:a072', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 10:43:33'),
(20, 1, 'super_admin', 'create_admin', 'user', 12, 'Created new Admin account for Md Tousif Ali', '2401:4900:b704:5787:fe8:e97c:99d9:a072', NULL, '2026-09-13 10:47:02'),
(21, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b704:5787:fe8:e97c:99d9:a072', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 10:47:21'),
(22, 12, 'admin', 'login', 'user', 12, 'Admin logged in', '2401:4900:b704:5787:fe8:e97c:99d9:a072', NULL, '2026-09-13 10:47:30'),
(23, 12, 'admin', 'login', 'user', 12, 'Admin logged in', '2401:4900:b704:5787:b539:4a98:a2e8:46a0', NULL, '2026-09-13 10:59:02'),
(24, 12, 'admin', 'login', 'user', 12, 'Admin logged in', '2401:4900:b704:5787:fe8:e97c:99d9:a072', NULL, '2026-09-13 11:00:27'),
(25, 12, 'admin', 'logout', 'user', 12, 'Admin logged out (Reason: manual)', '2401:4900:b704:5787:b539:4a98:a2e8:46a0', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.6 Mobile/15E148 Safari/604.1 Brave', '2026-09-13 11:03:56'),
(26, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:fe8:e97c:99d9:a072', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 11:10:31'),
(27, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b704:5787:fe8:e97c:99d9:a072', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 11:16:32'),
(28, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:fe8:e97c:99d9:a072', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 11:20:25'),
(29, 1, 'super_admin', 'create_client', 'client', 1, 'Created new client CL-000001 (Alok)', '2401:4900:b704:5787:fe8:e97c:99d9:a072', NULL, '2026-09-13 11:21:58'),
(30, 1, 'super_admin', 'update_client', 'client', 1, 'Updated client ID 1 (Alok)', '2401:4900:b704:5787:fe8:e97c:99d9:a072', NULL, '2026-09-13 11:23:34'),
(31, 1, 'super_admin', 'soft_delete_client', 'client', 1, 'Soft-deleted client CL-000001 (Alok)', '2401:4900:b704:5787:fe8:e97c:99d9:a072', NULL, '2026-09-13 11:24:00'),
(32, 1, 'super_admin', 'set_monthly_target', 'admin_target', 12, '500000', '2401:4900:b704:5787:fe8:e97c:99d9:a072', NULL, '2026-09-13 11:24:21'),
(33, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b704:5787:fe8:e97c:99d9:a072', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 11:37:06'),
(34, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:7cd0:3640:9910:a89b', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13 12:00:18'),
(35, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b704:5787:7cd0:3640:9910:a89b', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13 12:00:26'),
(36, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:fe8:e97c:99d9:a072', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 12:00:58'),
(37, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b704:5787:fe8:e97c:99d9:a072', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 12:03:16'),
(38, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2409:4061:2c18:6973:5802:e7d8:3a74:a1e0', 'Mozilla/5.0 (Linux; Android 14; SM-T735 Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/152.0.7977.87 Safari/537.36', '2026-09-13 12:04:23'),
(39, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:fe8:e97c:99d9:a072', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 12:04:26'),
(40, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b704:5787:7cd0:3640:9910:a89b', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13 12:05:36'),
(41, 1, 'super_admin', 'create_client', 'client', 2, 'Created new client CL-000002 (Jggjjj)', '2401:4900:b70b:2526:77de:85:3a27:ecd4', NULL, '2026-09-13 13:25:16'),
(42, 1, 'super_admin', 'create_client', 'client', 3, 'Created new client CL-000003 (Hjfhv)', '2401:4900:b70b:2526:77de:85:3a27:ecd4', NULL, '2026-09-13 13:34:16'),
(43, 1, 'super_admin', 'schedule_meeting', 'client', 3, 'Scheduled meeting', '2401:4900:b70b:2526:77de:85:3a27:ecd4', NULL, '2026-09-13 13:46:57'),
(44, 1, 'super_admin', 'update_client', 'client', 3, 'Updated client ID 3 (Hjfhv)', '2401:4900:b70b:2526:77de:85:3a27:ecd4', NULL, '2026-09-13 13:57:03'),
(45, 1, 'super_admin', 'create_expense', 'expense', 1, 'Logged expense \'Kgck g khgk\' (₹1,000.00) in category Marketing &amp; Ads', '2401:4900:b70b:2526:77de:85:3a27:ecd4', NULL, '2026-09-13 14:17:23'),
(46, 1, 'super_admin', 'payslip_issued', 'payslip_2026-08', 12, '25000|0|0|25000|', '2401:4900:b70b:2526:77de:85:3a27:ecd4', NULL, '2026-09-13 14:17:48'),
(47, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2409:40e0:0:a0cb:8000::', 'Mozilla/5.0 (Linux; Android 16; V2319 Build/BP2A.250605.031.A3; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/152.0.7977.87 Mobile Safari/537.36', '2026-09-13 14:48:50'),
(48, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2409:40e0:0:a0cb:8000::', 'Mozilla/5.0 (Linux; Android 16; V2319 Build/BP2A.250605.031.A3; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/152.0.7977.87 Mobile Safari/537.36', '2026-09-13 14:50:19'),
(49, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2402:3a80:414e:b956:681b:f6a4:e2d8:1293', 'Mozilla/5.0 (Linux; Android 14; SM-T735 Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/152.0.7977.87 Safari/537.36', '2026-09-13 16:00:58'),
(50, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b6db:4f77:5f88:6a4:a4d2:6607', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 16:52:49'),
(51, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b6db:4f77:5f88:6a4:a4d2:6607', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 16:53:27'),
(52, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b6db:4f77:38db:eee0:35d:a725', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13 17:36:12'),
(53, 12, 'admin', 'login', 'user', 12, 'Admin logged in', '2401:4900:b6db:4f77:38db:eee0:35d:a725', NULL, '2026-09-13 17:36:55'),
(54, 12, 'admin', 'leave_request', 'leave_2026-09-16', 12, 'Approved|Half Day|mbhvgfdsxfcgvbn', '2401:4900:b6db:4f77:38db:eee0:35d:a725', NULL, '2026-09-13 17:37:18'),
(55, 1, 'super_admin', 'payslip_issued', 'payslip_2026-06', 12, '36000|0|0|36000|', '2401:4900:b6db:4f77:5f88:6a4:a4d2:6607', NULL, '2026-09-13 17:44:01'),
(56, 12, 'admin', 'logout', 'user', 12, 'Admin logged out (Reason: inactivity)', '2401:4900:b6db:4f77:38db:eee0:35d:a725', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13 17:45:03'),
(57, 12, 'admin', 'login', 'user', 12, 'Admin logged in', '2401:4900:b6db:4f77:38db:eee0:35d:a725', NULL, '2026-09-13 17:45:10'),
(58, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b6db:4f77:5f88:6a4:a4d2:6607', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 17:45:42'),
(59, 12, 'admin', 'login', 'user', 12, 'Admin logged in', '2401:4900:b6db:4f77:5f88:6a4:a4d2:6607', NULL, '2026-09-13 17:45:55'),
(60, 12, 'admin', 'logout', 'user', 12, 'Admin logged out (Reason: manual)', '2401:4900:b6db:4f77:5f88:6a4:a4d2:6607', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 17:46:58'),
(61, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b6db:4f77:5f88:6a4:a4d2:6607', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 17:47:16'),
(62, 12, 'admin', 'logout', 'user', 12, 'Admin logged out (Reason: inactivity)', '2401:4900:b6db:4f77:38db:eee0:35d:a725', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13 17:55:12'),
(63, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b6db:4f77:38db:eee0:35d:a725', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13 17:56:18'),
(64, 1, 'super_admin', 'update_meeting_status', 'meeting', 1, 'Changed meeting ID 1 status to Scheduled', '2401:4900:b6db:4f77:5f88:6a4:a4d2:6607', NULL, '2026-09-13 18:21:39'),
(65, 1, 'super_admin', 'update_meeting_status', 'meeting', 1, 'Changed meeting ID 1 status to Completed', '2401:4900:b6db:4f77:5f88:6a4:a4d2:6607', NULL, '2026-09-13 18:23:44'),
(66, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b6db:4f77:5f88:6a4:a4d2:6607', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 19:03:57'),
(67, 1, 'super_admin', 'update_client', 'client', 3, 'Updated client ID 3 (Hjfhv)', '2401:4900:b6db:4f77:5f88:6a4:a4d2:6607', NULL, '2026-09-13 19:04:53'),
(68, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b705:fecb:c514:7beb:1dd1:660e', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 21:23:54'),
(69, 12, 'admin', 'login', 'user', 12, 'Admin logged in', '2401:4900:b705:fecb:c514:7beb:1dd1:660e', NULL, '2026-09-13 21:24:09'),
(70, 12, 'admin', 'logout', 'user', 12, 'Admin logged out (Reason: manual)', '2401:4900:b705:fecb:c514:7beb:1dd1:660e', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 21:27:29'),
(71, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b705:fecb:c514:7beb:1dd1:660e', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 21:27:46'),
(72, 1, 'super_admin', 'update_client', 'client', 2, 'Updated client ID 2 (Jggjjj)', '2401:4900:b705:fecb:c514:7beb:1dd1:660e', NULL, '2026-09-13 21:28:27'),
(73, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b705:fecb:c514:7beb:1dd1:660e', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 21:28:46'),
(74, 12, 'admin', 'login', 'user', 12, 'Admin logged in', '2401:4900:b705:fecb:c514:7beb:1dd1:660e', NULL, '2026-09-13 21:28:56'),
(75, 12, 'admin', 'logout', 'user', 12, 'Admin logged out (Reason: manual)', '2401:4900:b705:fecb:c514:7beb:1dd1:660e', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 21:29:12'),
(76, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b705:fecb:c514:7beb:1dd1:660e', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13 21:29:29'),
(77, 1, 'super_admin', 'update_client', 'client', 3, 'Updated client ID 3 (Hjfhv)', '2401:4900:b705:fecb:c514:7beb:1dd1:660e', NULL, '2026-09-13 21:31:19'),
(78, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2409:40e0:1e:85c2:8000::', 'Mozilla/5.0 (Linux; Android 16; V2319 Build/BP2A.250605.031.A3; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/152.0.7977.87 Mobile Safari/537.36', '2026-09-14 07:35:51'),
(79, 1, 'super_admin', 'create_admin', 'user', 13, 'Created new Admin account for Subhajit Poddar', '2409:40e0:43:e6ab:8000::', NULL, '2026-09-14 07:44:27'),
(80, 1, 'super_admin', 'update_working_time', 'daily_work_logs', 8, 'Updated working time to 0 hours for Admin ID 13 on 2026-09-14. Reason: Bjnr', '2409:40e0:43:e6ab:8000::', NULL, '2026-09-14 07:47:46'),
(81, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2409:40e0:43:e6ab:8000::', 'Mozilla/5.0 (Linux; Android 16; V2319 Build/BP2A.250605.031.A3; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/152.0.7977.87 Mobile Safari/537.36', '2026-09-14 07:49:12'),
(82, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '115.96.134.120', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-14 08:38:13'),
(83, 1, 'super_admin', 'create_task', 'task', 1, 'Assigned task \'jhgfd\' to User ID 1 with deadline 2026-09-17', '115.96.134.120', NULL, '2026-09-14 08:45:45'),
(84, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '115.96.134.120', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-14 08:47:28'),
(85, 12, 'admin', 'login', 'user', 12, 'Admin logged in', '115.96.134.120', NULL, '2026-09-14 08:47:33'),
(86, 12, 'admin', 'create_task', 'task', 2, 'Assigned task \'hgfd\' to User ID 12 with deadline 2026-09-18', '115.96.134.120', NULL, '2026-09-14 08:47:59'),
(87, 12, 'admin', 'logout', 'user', 12, 'Admin logged out (Reason: manual)', '115.96.134.120', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-14 08:48:26'),
(88, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '115.96.134.120', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-14 08:48:36'),
(89, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b70c:b26e:4c3f:2c2c:ed69:9213', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-15 15:16:37'),
(90, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2409:40e0:20:1ddc:8000::', 'Mozilla/5.0 (Linux; Android 16; V2319 Build/BP2A.250605.031.A3; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/152.0.7977.87 Mobile Safari/537.36', '2026-09-16 04:28:04'),
(91, 1, 'super_admin', 'create_client', 'client', 4, 'Created new client CL-000004 (Test)', '2409:40e0:49:8ce2:8000::', NULL, '2026-09-16 04:36:40'),
(92, 1, 'super_admin', 'soft_delete_client', 'client', 4, 'Soft-deleted client CL-000004 (Test)', '2409:40e0:49:8ce2:8000::', NULL, '2026-09-16 04:38:53'),
(93, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2409:40e0:49:8ce2:8000::', 'Mozilla/5.0 (Linux; Android 16; V2319 Build/BP2A.250605.031.A3; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/152.0.7977.87 Mobile Safari/537.36', '2026-09-16 04:39:38'),
(94, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '203.212.241.49', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-16 07:01:10'),
(95, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '203.212.241.49', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-16 09:19:33'),
(96, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b6f0:fe4e:b52d:f0b4:78bf:a0ee', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-16 20:18:33'),
(97, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b6d7:cde5:ef93:1fc:2a93:4170', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-17 14:10:29'),
(98, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b6d7:cde5:ef93:1fc:2a93:4170', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-17 14:14:10'),
(99, 12, 'admin', 'login', 'user', 12, 'Admin logged in', '2401:4900:b6d7:cde5:ef93:1fc:2a93:4170', NULL, '2026-09-17 14:14:27'),
(100, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b6d7:cde5:9934:79e:ca7f:28cd', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-17 14:22:40'),
(101, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b6d7:cde5:f60e:5c94:6489:9927', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-17 18:14:13'),
(102, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2401:4900:b6d7:cde5:f60e:5c94:6489:9927', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-17 18:33:34'),
(103, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b6d7:cde5:f60e:5c94:6489:9927', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-17 18:33:49'),
(104, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:b750:89db:b478:a582:3c23:3723', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-21 18:36:26'),
(105, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2409:40e1:405c:4ae0:35:9490:916:e70b', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-09-24 08:56:34'),
(106, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:3bd8:57b2:80a6:3460:fce1:6bc', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/153.0.8010.36 Mobile Safari/537.36', '2026-10-01 17:12:54'),
(107, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2409:40e1:4054:fb83:8000::', 'Mozilla/5.0 (Linux; Android 16; V2319 Build/BP2A.250605.031.A3; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/153.0.8010.36 Mobile Safari/537.36', '2026-10-03 10:31:58'),
(108, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '2409:40e1:4054:fb83:8000::', 'Mozilla/5.0 (Linux; Android 16; V2319 Build/BP2A.250605.031.A3; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/153.0.8010.36 Mobile Safari/537.36', '2026-10-03 10:32:47'),
(109, 12, 'admin', 'login', 'user', 12, 'Admin logged in', '2401:4900:3fce:6dd9:cd6f:681a:98b5:a759', NULL, '2026-10-04 21:47:46'),
(110, 12, 'admin', 'logout', 'user', 12, 'Admin logged out (Reason: manual)', '2401:4900:3fce:6dd9:cd6f:681a:98b5:a759', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/27.0 Mobile/15E148 Safari/604.1 Brave', '2026-10-04 21:47:54'),
(111, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2402:3a80:4320:3604:178:5634:1232:5476', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/154.0.8037.107 Mobile Safari/537.36', '2026-10-18 07:58:00'),
(112, 1, 'super_admin', 'logout', 'user', 1, 'Super Admin logged out', '42.105.192.87', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/155.0.8059.43 Mobile Safari/537.36', '2026-10-20 22:18:07'),
(113, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '42.105.192.87', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/155.0.8059.43 Mobile Safari/537.36', '2026-10-20 22:18:43'),
(114, 1, 'super_admin', 'login', 'user', 1, 'Super Admin logged in', '2401:4900:104b:9e0f:657e:b502:70a0:46e5', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-10-24 16:41:38');

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `client_code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `whatsapp` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `pin_code` varchar(10) DEFAULT NULL,
  `requirement` text DEFAULT NULL,
  `temperature` enum('HOT','COLD') DEFAULT 'COLD',
  `status` varchar(50) DEFAULT 'New',
  `deal_status` enum('Open','In Progress','Converted','Rejected','On Hold') DEFAULT 'Open',
  `quotation_amount` decimal(10,2) DEFAULT NULL,
  `final_price` decimal(10,2) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `assigned_admin_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`id`, `client_code`, `name`, `phone`, `whatsapp`, `email`, `address`, `city`, `state`, `pin_code`, `requirement`, `temperature`, `status`, `deal_status`, `quotation_amount`, `final_price`, `rejection_reason`, `assigned_admin_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'CL-000001', 'Alok', '8759177098', '8759177098', '', '', '', '', '', '', 'HOT', 'New', 'Open', NULL, NULL, '', 12, 1, 1, '2026-09-13 11:21:58', '2026-09-13 11:24:00', '2026-09-13 11:24:00'),
(2, 'CL-000002', 'Jggjjj', '2525365236', '', '', '', '', '', '', '', 'HOT', 'New', 'Open', NULL, NULL, '', 12, 1, 1, '2026-09-13 13:25:16', '2026-09-13 21:28:27', NULL),
(3, 'CL-000003', 'Hjfhv', '2536514785', '', 'njdjxjfjdj@gmail.com', '', '', '', '', '', 'HOT', 'Converted', 'Converted', 15000.00, 12000.00, '', 12, 1, 1, '2026-09-13 13:34:16', '2026-09-13 21:31:19', NULL),
(4, 'CL-000004', 'Test', '2134646646', '316464646646464', 'subhajitpoddar786@gmail.com', 'Test', '', '', '', '', 'COLD', 'New', 'Open', NULL, 500000.00, '23333', 13, 1, NULL, '2026-09-16 04:36:40', '2026-09-16 04:38:53', '2026-09-16 04:38:53');

-- --------------------------------------------------------

--
-- Table structure for table `client_activities`
--

CREATE TABLE `client_activities` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `activity_date` date NOT NULL,
  `activity_time` time NOT NULL,
  `notes` text DEFAULT NULL,
  `next_followup_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `client_activities`
--

INSERT INTO `client_activities` (`id`, `client_id`, `admin_id`, `activity_type`, `activity_date`, `activity_time`, `notes`, `next_followup_date`, `created_at`) VALUES
(1, 1, 1, 'Other', '2026-09-13', '11:21:58', 'Client record created in system with status: New', NULL, '2026-09-13 11:21:58'),
(2, 2, 1, 'Other', '2026-09-13', '13:25:16', 'Client record created in system with status: New', NULL, '2026-09-13 13:25:16'),
(3, 3, 1, 'Other', '2026-09-13', '13:34:16', 'Client record created in system with status: New', NULL, '2026-09-13 13:34:16'),
(4, 3, 1, 'Meeting', '2026-09-15', '12:16:00', 'Meeting Scheduled: ', NULL, '2026-09-13 13:46:57'),
(5, 3, 1, 'Payment Received', '2026-09-13', '00:03:00', '[Amount: 8000] [Method: Cash] [Ref: Demmmo] Igivjvvi', NULL, '2026-09-13 21:30:13'),
(6, 4, 1, 'Other', '2026-09-16', '04:36:40', 'Client record created in system with status: New', NULL, '2026-09-16 04:36:40');

-- --------------------------------------------------------

--
-- Table structure for table `daily_work_logs`
--

CREATE TABLE `daily_work_logs` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `total_seconds` int(11) DEFAULT 0,
  `target_seconds` int(11) DEFAULT 21600,
  `status` varchar(20) DEFAULT 'Low',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `daily_work_logs`
--

INSERT INTO `daily_work_logs` (`id`, `admin_id`, `work_date`, `total_seconds`, `target_seconds`, `status`, `created_at`, `updated_at`) VALUES
(1, 5, '2026-09-13', 268, 21600, 'Low', '2026-09-13 09:05:55', '2026-09-13 09:05:55'),
(2, 12, '2026-09-13', 1269, 21600, 'Low', '2026-09-13 11:03:56', '2026-09-13 17:55:12'),
(6, 12, '2026-09-14', 126, 21600, 'Low', '2026-09-13 21:27:29', '2026-09-13 21:27:29'),
(8, 13, '2026-09-14', 0, 21600, 'Low', '2026-09-14 07:47:46', '2026-09-14 07:47:46'),
(10, 12, '2026-10-05', 0, 21600, 'Low', '2026-10-04 21:47:54', '2026-10-04 21:47:54');

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'Operational',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `expense_date` date NOT NULL,
  `expense_time` time NOT NULL,
  `location_vendor` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `title`, `category`, `amount`, `expense_date`, `expense_time`, `location_vendor`, `description`, `document_path`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Kgck g khgk', 'Marketing &amp; Ads', 1000.00, '2026-09-12', '19:46:00', 'Jcckhk h', 'Icgkgcgo', NULL, 1, '2026-09-13 14:17:23', '2026-09-13 14:17:23', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `meetings`
--

CREATE TABLE `meetings` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `meeting_date` date NOT NULL,
  `meeting_time` time NOT NULL,
  `meeting_type` varchar(50) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `meeting_link` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('Scheduled','Completed','Cancelled','Rescheduled') DEFAULT 'Scheduled',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `meetings`
--

INSERT INTO `meetings` (`id`, `client_id`, `admin_id`, `meeting_date`, `meeting_time`, `meeting_type`, `location`, `meeting_link`, `notes`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 3, 1, '2026-09-15', '12:16:00', 'In-Person', '', '', '', 'Completed', '2026-09-13 13:46:57', '2026-09-13 18:23:44', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `assigned_to` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `priority` enum('Low','Medium','High','Urgent') DEFAULT 'Medium',
  `status` enum('Pending','In Progress','Under Review','Completed','Cancelled') DEFAULT 'Pending',
  `progress` int(11) NOT NULL DEFAULT 0,
  `deadline_date` date NOT NULL,
  `deadline_time` time DEFAULT '18:00:00',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `tasks`
--

INSERT INTO `tasks` (`id`, `title`, `description`, `assigned_to`, `assigned_by`, `priority`, `status`, `progress`, `deadline_date`, `deadline_time`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'jhgfd', 'gfds', 1, 1, 'High', 'Completed', 100, '2026-09-17', '20:00:00', '2026-09-14 08:45:45', '2026-09-14 09:09:23', NULL),
(2, 'hgfd', 'jhgfd', 12, 12, 'High', 'Pending', 0, '2026-09-18', '18:45:00', '2026-09-14 08:47:59', '2026-09-14 08:47:59', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `role` enum('super_admin','admin','developer') NOT NULL DEFAULT 'admin',
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `mobile` varchar(15) NOT NULL,
  `password` varchar(255) NOT NULL,
  `pin_hash` varchar(255) DEFAULT NULL COMMENT 'Only for Super Admin 2FA',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `role`, `name`, `email`, `mobile`, `password`, `pin_hash`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'super_admin', 'StodLord', 'admin@stoplord.in', '8001234056', '$2y$10$RTmkS0XFHizJ3B/dzSBa5eEOKOwascIjTD638YUhrW7Uu..kh6z.m', '$2y$10$3mEnXRaH2cYaEs4nJtgOUOmw7ZKRse8Sp/XJNfJeY2enNRQwGCq12', 'active', '2026-09-07 10:50:53', '2026-09-13 08:57:49', NULL),
(12, 'admin', 'Md Tousif Ali', 'beingmdtousif@gmail.com', '9748337898', '$2y$10$7FrAeDlMz0NPwyrZowAVGunGQiI5zL9lvx8iZBT4WCClLJjuG95zK', NULL, 'active', '2026-09-13 10:47:02', '2026-09-13 10:47:02', NULL),
(13, 'admin', 'Subhajit Poddar', 'demo@gmail.com', '2580963125', '$2y$10$ZWJQt4Xo7Nh0aNbarCdNUOcqu0i50sy6Htfm27n..9MzTT9sqf5Vy', NULL, 'active', '2026-09-14 07:44:27', '2026-09-14 07:44:27', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `work_sessions`
--

CREATE TABLE `work_sessions` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `login_time` datetime NOT NULL,
  `last_activity` datetime NOT NULL,
  `logout_time` datetime DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT 0,
  `logout_reason` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `session_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `work_sessions`
--

INSERT INTO `work_sessions` (`id`, `admin_id`, `login_time`, `last_activity`, `logout_time`, `duration_seconds`, `logout_reason`, `ip_address`, `user_agent`, `session_date`, `created_at`) VALUES
(1, 5, '2026-09-13 14:31:25', '2026-09-13 14:35:53', '2026-09-13 14:35:55', 268, 'manual', '2401:4900:b704:5787:7cd0:3640:9910:a89b', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13', '2026-09-13 09:01:25'),
(2, 12, '2026-09-13 16:17:30', '2026-09-13 16:17:30', NULL, 0, NULL, '2401:4900:b704:5787:fe8:e97c:99d9:a072', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13', '2026-09-13 10:47:30'),
(3, 12, '2026-09-13 16:29:02', '2026-09-13 16:33:52', '2026-09-13 16:33:56', 290, 'manual', '2401:4900:b704:5787:b539:4a98:a2e8:46a0', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.6 Mobile/15E148 Safari/604.1 Brave', '2026-09-13', '2026-09-13 10:59:02'),
(4, 12, '2026-09-13 16:30:27', '2026-09-13 16:30:27', NULL, 0, NULL, '2401:4900:b704:5787:fe8:e97c:99d9:a072', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13', '2026-09-13 11:00:27'),
(5, 12, '2026-09-13 23:06:55', '2026-09-13 23:14:12', '2026-09-13 23:15:03', 437, 'inactivity', '2401:4900:b6db:4f77:38db:eee0:35d:a725', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13', '2026-09-13 17:36:55'),
(6, 12, '2026-09-13 23:15:10', '2026-09-13 23:24:12', '2026-09-13 23:25:12', 542, 'inactivity', '2401:4900:b6db:4f77:38db:eee0:35d:a725', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-13', '2026-09-13 17:45:10'),
(7, 12, '2026-09-13 23:15:55', '2026-09-13 23:15:55', '2026-09-13 23:16:58', 0, 'manual', '2401:4900:b6db:4f77:5f88:6a4:a4d2:6607', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-13', '2026-09-13 17:45:55'),
(8, 12, '2026-09-14 02:54:09', '2026-09-14 02:56:15', '2026-09-14 02:57:29', 126, 'manual', '2401:4900:b705:fecb:c514:7beb:1dd1:660e', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-14', '2026-09-13 21:24:09'),
(9, 12, '2026-09-14 02:58:56', '2026-09-14 02:58:56', '2026-09-14 02:59:12', 0, 'manual', '2401:4900:b705:fecb:c514:7beb:1dd1:660e', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-14', '2026-09-13 21:28:56'),
(10, 12, '2026-09-14 14:17:33', '2026-09-14 14:17:33', '2026-09-14 14:18:26', 0, 'manual', '115.96.134.120', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-14', '2026-09-14 08:47:33'),
(11, 12, '2026-09-17 19:44:27', '2026-09-17 19:44:27', NULL, 0, NULL, '2401:4900:b6d7:cde5:ef93:1fc:2a93:4170', 'Mozilla/5.0 (Linux; Android 16; SM-M346B Build/BP4A.251205.006; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 Mobile Safari/537.36', '2026-09-17', '2026-09-17 14:14:27'),
(12, 12, '2026-10-05 03:17:46', '2026-10-05 03:17:46', '2026-10-05 03:17:54', 0, 'manual', '2401:4900:3fce:6dd9:cd6f:681a:98b5:a759', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/27.0 Mobile/15E148 Safari/604.1 Brave', '2026-10-05', '2026-10-04 21:47:46');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_profiles`
--
ALTER TABLE `admin_profiles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `client_code` (`client_code`),
  ADD KEY `assigned_admin_id` (`assigned_admin_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_client_code` (`client_code`),
  ADD KEY `idx_client_status` (`status`,`deal_status`),
  ADD KEY `idx_temperature` (`temperature`);

--
-- Indexes for table `client_activities`
--
ALTER TABLE `client_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `daily_work_logs`
--
ALTER TABLE `daily_work_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_admin_date` (`admin_id`,`work_date`),
  ADD KEY `idx_work_date` (`work_date`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `expense_date` (`expense_date`),
  ADD KEY `category` (`category`),
  ADD KEY `deleted_at` (`deleted_at`);

--
-- Indexes for table `meetings`
--
ALTER TABLE `meetings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `status` (`status`),
  ADD KEY `deadline_date` (`deadline_date`),
  ADD KEY `deleted_at` (`deleted_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `mobile` (`mobile`);

--
-- Indexes for table `work_sessions`
--
ALTER TABLE `work_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_profiles`
--
ALTER TABLE `admin_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=115;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `client_activities`
--
ALTER TABLE `client_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `daily_work_logs`
--
ALTER TABLE `daily_work_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `meetings`
--
ALTER TABLE `meetings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `work_sessions`
--
ALTER TABLE `work_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_profiles`
--
ALTER TABLE `admin_profiles`
  ADD CONSTRAINT `admin_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `clients_ibfk_1` FOREIGN KEY (`assigned_admin_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `clients_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `client_activities`
--
ALTER TABLE `client_activities`
  ADD CONSTRAINT `client_activities_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  ADD CONSTRAINT `client_activities_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `daily_work_logs`
--
ALTER TABLE `daily_work_logs`
  ADD CONSTRAINT `daily_work_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `meetings`
--
ALTER TABLE `meetings`
  ADD CONSTRAINT `meetings_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  ADD CONSTRAINT `meetings_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `work_sessions`
--
ALTER TABLE `work_sessions`
  ADD CONSTRAINT `work_sessions_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
