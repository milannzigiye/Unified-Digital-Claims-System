-- UDCS current schema-only export for GitHub/GitLab publishing.
-- Generated from the local `udcs` database with mysqldump --no-data.
-- Contains database/table/index/constraint/routine structure only.
-- Does not contain users, OTPs, claim records, activity logs, uploaded documents, or SMTP secrets.

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: udcs
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `udcs`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `udcs` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `udcs`;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `actor_id` int(11) DEFAULT NULL,
  `actor_role` varchar(32) DEFAULT NULL,
  `claim_id` int(11) DEFAULT NULL,
  `action_key` varchar(80) NOT NULL,
  `action_label` varchar(160) NOT NULL,
  `details` text DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activity_actor` (`actor_id`),
  KEY `idx_activity_claim` (`claim_id`),
  KEY `idx_activity_action` (`action_key`),
  KEY `idx_activity_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cm_room` (`room_id`),
  KEY `idx_cm_sender` (`sender_id`),
  KEY `idx_cm_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_participants`
--

DROP TABLE IF EXISTS `chat_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_room_user` (`room_id`,`user_id`),
  KEY `idx_cp_room` (`room_id`),
  KEY `idx_cp_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_rooms`
--

DROP TABLE IF EXISTS `chat_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_title` varchar(255) DEFAULT NULL,
  `chat_type` varchar(50) NOT NULL DEFAULT 'direct',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_message_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `claim_assets`
--

DROP TABLE IF EXISTS `claim_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `claim_assets` (
  `claim_asset_id` int(11) NOT NULL AUTO_INCREMENT,
  `claim_id` int(11) NOT NULL,
  `asset_class` varchar(80) NOT NULL,
  `currency_code` varchar(3) NOT NULL DEFAULT 'RWF',
  `account_reference` varchar(160) DEFAULT NULL,
  `estimated_value` decimal(15,2) DEFAULT NULL,
  `verified_value` decimal(15,2) DEFAULT NULL,
  `finance_status` varchar(80) DEFAULT NULL,
  `payout_preference_override` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`claim_asset_id`),
  KEY `idx_claim_assets_claim` (`claim_id`),
  KEY `idx_claim_assets_class` (`asset_class`),
  KEY `idx_claim_assets_currency` (`currency_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `claim_history`
--

DROP TABLE IF EXISTS `claim_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `claim_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `claim_id` int(11) NOT NULL,
  `actor_role` varchar(32) DEFAULT NULL,
  `status_label` varchar(120) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_claim_history_claim` (`claim_id`),
  KEY `idx_claim_history_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `claim_people`
--

DROP TABLE IF EXISTS `claim_people`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `claim_people` (
  `claim_person_id` int(11) NOT NULL AUTO_INCREMENT,
  `claim_id` int(11) NOT NULL,
  `person_id` int(11) NOT NULL,
  `role` varchar(50) NOT NULL,
  `relationship_type` varchar(60) DEFAULT NULL,
  `is_claimant` tinyint(1) NOT NULL DEFAULT 0,
  `is_co_heir` tinyint(1) NOT NULL DEFAULT 0,
  `represented_by_person_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`claim_person_id`),
  KEY `idx_claim_people_claim` (`claim_id`),
  KEY `idx_claim_people_person` (`person_id`),
  KEY `idx_claim_people_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `claims`
--

DROP TABLE IF EXISTS `claims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `claims` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `claimant_id` int(11) DEFAULT NULL,
  `alt_phone` varchar(20) DEFAULT NULL,
  `alt_email` varchar(30) DEFAULT NULL,
  `deceased_name` varchar(100) DEFAULT NULL,
  `deceased_national_id` varchar(16) DEFAULT NULL,
  `deceased_date` date DEFAULT NULL,
  `relationship` varchar(50) DEFAULT NULL,
  `accout_number` varchar(20) DEFAULT NULL,
  `claim_type` varchar(100) DEFAULT NULL,
  `claim_amount` decimal(15,2) DEFAULT NULL,
  `claim_description` text DEFAULT NULL,
  `claim_status` varchar(30) DEFAULT 'pending',
  `distribution_method` varchar(100) DEFAULT NULL,
  `distribution_details` text DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `assigned_legal_id` int(11) DEFAULT NULL,
  `assigned_finance_id` int(11) DEFAULT NULL,
  `finance_assessed_amount` decimal(15,2) DEFAULT NULL,
  `claimant_user_id` int(11) DEFAULT NULL,
  `deceased_full_name` varchar(190) DEFAULT NULL,
  `deceased_id_number` varchar(120) DEFAULT NULL,
  `date_of_death` date DEFAULT NULL,
  `marital_status` varchar(40) DEFAULT NULL,
  `spouse_status` varchar(40) DEFAULT NULL,
  `children_status` varchar(40) DEFAULT NULL,
  `will_exists` tinyint(1) DEFAULT NULL,
  `acting_on_behalf` tinyint(1) DEFAULT NULL,
  `preferred_payout_method` varchar(80) DEFAULT NULL,
  `manual_review_flag` tinyint(1) NOT NULL DEFAULT 0,
  `manual_review_reason` varchar(255) DEFAULT NULL,
  `status` varchar(80) DEFAULT NULL,
  `model_version` varchar(16) NOT NULL DEFAULT 'legacy',
  `legacy_read_only` tinyint(1) NOT NULL DEFAULT 0,
  `finance_return_reason` varchar(255) DEFAULT NULL,
  `finance_return_route` varchar(40) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `legal_reopen_scope` text DEFAULT NULL,
  `legal_reopen_note` text DEFAULT NULL,
  `legal_reopen_requested_at` datetime DEFAULT NULL,
  `account_number` varchar(160) DEFAULT NULL,
  `claim_currency_code` varchar(3) NOT NULL DEFAULT 'RWF',
  `finance_assessed_currency_code` varchar(3) NOT NULL DEFAULT 'RWF',
  PRIMARY KEY (`id`),
  KEY `claimant_id` (`claimant_id`),
  KEY `idx_claims_assigned_to` (`assigned_to`),
  KEY `idx_claims_assigned_legal` (`assigned_legal_id`),
  KEY `idx_claims_assigned_finance` (`assigned_finance_id`),
  KEY `idx_claims_status_v2` (`status`),
  KEY `idx_claims_model_version` (`model_version`),
  KEY `idx_claims_claimant_user` (`claimant_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `documents`
--

DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `claim_id` int(11) NOT NULL,
  `document_type` varchar(50) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `owner_person_id` int(11) DEFAULT NULL,
  `related_claim_person_id` int(11) DEFAULT NULL,
  `ocr_status` varchar(40) DEFAULT NULL,
  `ocr_extracted_name` varchar(190) DEFAULT NULL,
  `ocr_extracted_id` varchar(120) DEFAULT NULL,
  `ocr_extracted_date` varchar(80) DEFAULT NULL,
  `legal_review_status` varchar(40) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `claim_id` (`claim_id`),
  CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`claim_id`) REFERENCES `claims` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`),
  CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receiver` varchar(30) DEFAULT NULL,
  `receiver_user_id` int(11) DEFAULT NULL,
  `sender` varchar(30) NOT NULL,
  `sender_user_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` varchar(10) DEFAULT 'unread',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_receiver_user` (`receiver_user_id`),
  KEY `idx_notifications_sender_user` (`sender_user_id`),
  KEY `idx_notifications_created` (`created_at`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `people`
--

DROP TABLE IF EXISTS `people`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `people` (
  `person_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(190) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `id_number` varchar(120) DEFAULT NULL,
  `contact_phone` varchar(60) DEFAULT NULL,
  `contact_email` varchar(160) DEFAULT NULL,
  `alive_status` enum('YES','NO','UNKNOWN') NOT NULL DEFAULT 'YES',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`person_id`),
  KEY `idx_people_id_number` (`id_number`),
  KEY `idx_people_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('claimant','legal','finance','admin') DEFAULT 'claimant',
  `password` varchar(255) NOT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `acceptance` enum('Yes','No') DEFAULT 'No',
  `email_otp` varchar(6) DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `email_verified` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_notification_opened_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'udcs'
--

--
-- Dumping routines for database 'udcs'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-10 17:43:01

