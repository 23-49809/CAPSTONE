-- MySQL dump 10.13  Distrib 26.7.0, for macos26.6 (arm64)
--
-- Host: 127.0.0.1    Database: smart_assess
-- ------------------------------------------------------
-- Server version	26.7.0

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;
SET @@SESSION.SQL_LOG_BIN= 0;

--
-- GTID state at the beginning of the backup 
--

SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ '5cf8e062-a817-11f1-8e5d-242c71b535ec:1-370';

--
-- Current Database: `smart_assess`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `smart_assess` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;

USE `smart_assess`;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `action` varchar(60) NOT NULL,
  `request_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `idx_user_time` (`user_id`,`created_at`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `activity_logs_ibfk_2` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (1,2,'viewed_request',1,'2026-10-07 21:26:59'),(2,2,'viewed_request',1,'2026-10-07 21:35:09'),(3,2,'viewed_request',1,'2026-10-07 21:36:44'),(4,2,'viewed_request',11,'2026-10-08 22:38:25'),(5,2,'viewed_request',12,'2026-10-08 22:39:50'),(6,2,'viewed_request',13,'2026-10-08 22:47:01'),(7,2,'viewed_request',11,'2026-10-08 23:29:03'),(8,2,'viewed_request',11,'2026-10-08 23:29:38'),(9,2,'viewed_request',11,'2026-10-08 23:29:49'),(10,2,'viewed_request',14,'2026-10-10 09:40:26'),(11,2,'viewed_request',14,'2026-10-10 09:55:42'),(12,2,'viewed_request',14,'2026-10-10 09:56:03'),(13,2,'viewed_request',15,'2026-10-10 09:58:28'),(14,2,'viewed_request',15,'2026-10-10 09:58:43');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `announcements`
--

DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `author` varchar(150) NOT NULL,
  `audience` enum('client','staff','both') NOT NULL DEFAULT 'both',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `status` enum('Draft','Scheduled','Published','Cancelled') NOT NULL DEFAULT 'Draft',
  `scheduled_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcements`
--

LOCK TABLES `announcements` WRITE;
/*!40000 ALTER TABLE `announcements` DISABLE KEYS */;
INSERT INTO `announcements` VALUES (1,'Office schedule for Rizal Day','The MAO will be closed on December 30. Document release for approved requests will resume the next business day.','Rodel H. Ortega','both','2026-09-01',NULL,NULL,'Published',NULL,'2026-09-01 18:23:27','2026-09-01 18:23:27'),(2,'Reminder: verify scanned uploads','Please confirm scans are legible before approving. Blurry or cropped IDs should be flagged for resubmission.','Rodel H. Ortega','both','2026-09-03',NULL,NULL,'Published',NULL,'2026-09-03 18:23:27','2026-09-03 18:23:27');
/*!40000 ALTER TABLE `announcements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `actor_type` enum('client','staff','admin','head','system') NOT NULL,
  `actor_id` int DEFAULT NULL,
  `actor_name` varchar(150) NOT NULL,
  `action` varchar(120) NOT NULL,
  `target` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
INSERT INTO `audit_log` VALUES (1,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-07 15:31:19'),(2,'admin',1,'Maricar D. Santos','Created staff account','test.copilot','2026-10-07 15:32:06'),(3,'admin',1,'Maricar D. Santos','Set role=staff status=Inactive position=Assessor Admin','user #4','2026-10-07 15:32:19'),(4,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-07 15:37:47'),(5,'head',3,'Rodel H. Ortega','Logged in to internal portal',NULL,'2026-10-07 15:37:55'),(6,'head',3,'Rodel H. Ortega','Logged in to internal portal',NULL,'2026-10-07 18:21:48'),(7,'head',3,'Rodel H. Ortega','Created announcement (Published)','Holiday Notice','2026-10-07 18:22:07'),(8,'head',3,'Rodel H. Ortega','Created announcement (Published)','System Update','2026-10-07 18:22:07'),(9,'head',3,'Rodel H. Ortega','Created announcement (Scheduled)','Office Advisory','2026-10-07 18:22:07'),(10,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-07 18:22:18'),(11,'head',3,'Rodel H. Ortega','Created announcement (Scheduled)','Cancel Test','2026-10-07 18:23:22'),(12,'head',3,'Rodel H. Ortega','Set announcement status=Cancelled','#6','2026-10-07 18:23:22'),(13,'head',3,'Rodel H. Ortega','Set announcement status=Draft','#3','2026-10-07 18:23:22'),(14,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-07 20:37:54'),(15,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-07 20:51:17'),(16,'staff',2,'Jessica P. Villanueva','Marked DR-2026-00004 as Processing','DR-2026-00004','2026-10-07 20:51:45'),(17,'head',3,'Rodel H. Ortega','Logged in to internal portal',NULL,'2026-10-07 20:54:56'),(18,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-07 20:55:07'),(19,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-07 21:26:59'),(20,'staff',2,'Jessica P. Villanueva','Logged out of internal portal',NULL,'2026-10-07 21:26:59'),(21,'client',1,'Ramon Villareal','Logged in to client portal',NULL,'2026-10-07 21:27:29'),(22,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-07 21:28:29'),(23,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-07 21:35:01'),(24,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-07 21:35:01'),(25,'head',3,'Rodel H. Ortega','Logged in to internal portal',NULL,'2026-10-07 21:35:02'),(26,'client',1,'Ramon Villareal','Logged in to client portal',NULL,'2026-10-07 21:35:18'),(27,'admin',1,'Maricar D. Santos','Logged out of internal portal',NULL,'2026-10-07 21:36:03'),(28,'staff',2,'Jessica P. Villanueva','Marked DR-2026-00001 as Out for Release','DR-2026-00001','2026-10-07 21:36:44'),(29,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-08 22:26:16'),(30,'admin',1,'Maricar D. Santos','Created staff account','archive.test','2026-10-08 22:26:17'),(31,'admin',1,'Maricar D. Santos','Archived staff account','user #5','2026-10-08 22:26:28'),(32,'admin',1,'Maricar D. Santos','Restored staff account','user #5','2026-10-08 22:26:39'),(33,'staff',5,'Archive Test','Logged in to internal portal',NULL,'2026-10-08 22:26:39'),(34,'admin',1,'Maricar D. Santos','Archived staff account','user #5','2026-10-08 22:26:52'),(35,'admin',1,'Maricar D. Santos','Permanently deleted staff account','user #5','2026-10-08 22:26:52'),(36,'client',2,'Audit Flowtest','Registered a client account',NULL,'2026-10-08 22:34:36'),(37,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-08 22:38:07'),(38,'staff',2,'Jessica P. Villanueva','Marked DR-2026-00004 as Processing','DR-2026-00004','2026-10-08 22:38:49'),(39,'staff',2,'Jessica P. Villanueva','Marked LT-2026-00003 as Processing','LT-2026-00003','2026-10-08 22:39:50'),(40,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-08 22:47:01'),(41,'staff',2,'Jessica P. Villanueva','Marked DR-2026-00005 as Processing','DR-2026-00005','2026-10-08 22:47:08'),(42,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-08 23:28:50'),(43,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-08 23:28:50'),(44,'head',3,'Rodel H. Ortega','Logged in to internal portal',NULL,'2026-10-08 23:28:51'),(45,'staff',2,'Jessica P. Villanueva','Logged out of internal portal',NULL,'2026-10-08 23:29:19'),(46,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-08 23:29:38'),(47,'staff',2,'Jessica P. Villanueva','Marked DR-2026-00004 as Rejected','DR-2026-00004','2026-10-08 23:29:49'),(48,'staff',2,'Jessica P. Villanueva','Sent manual notification (Courtesy Follow-up)','DR-2026-00004','2026-10-08 23:33:19'),(49,'head',3,'Rodel H. Ortega','Created announcement (Published)','Shell Port Test Announcement','2026-10-08 23:34:43'),(50,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-08 23:37:58'),(51,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-08 23:38:11'),(52,'head',3,'Rodel H. Ortega','Logged in to internal portal',NULL,'2026-10-08 23:38:11'),(53,'staff',2,'Jessica M. Santos','Updated profile',NULL,'2026-10-08 23:39:08'),(54,'staff',2,'Jessica M. Santos','Logged in to internal portal',NULL,'2026-10-08 23:39:21'),(55,'staff',2,'Jessica P. Villanueva','Updated profile',NULL,'2026-10-08 23:40:03'),(56,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-10 09:34:50'),(57,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-10 09:39:07'),(58,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-10 09:39:08'),(59,'head',3,'Rodel H. Ortega','Logged in to internal portal',NULL,'2026-10-10 09:39:08'),(60,'head',3,'Rodel H. Ortega','Deleted announcement','7','2026-10-10 09:39:33'),(61,'staff',2,'Jessica P. Villanueva','Marked DR-2026-00006 as Processing','DR-2026-00006','2026-10-10 09:40:26'),(62,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-10 09:50:55'),(63,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-10 09:50:56'),(64,'head',3,'Rodel H. Ortega','Logged in to internal portal',NULL,'2026-10-10 09:50:56'),(65,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-10 09:53:06'),(66,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-10 09:53:07'),(67,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-10 09:53:57'),(68,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-10 09:54:13'),(69,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-10 09:54:14'),(70,'admin',1,'Maricar D. Santos','Created staff account','audit.test.account','2026-10-10 09:54:33'),(71,'admin',1,'Maricar D. Santos','Archived staff account','user #6','2026-10-10 09:54:45'),(72,'admin',1,'Maricar D. Santos','Restored staff account','user #6','2026-10-10 09:55:19'),(73,'admin',1,'Maricar D. Santos','Archived staff account','user #6','2026-10-10 09:55:19'),(74,'admin',1,'Maricar D. Santos','Permanently deleted staff account','user #6','2026-10-10 09:55:19'),(75,'admin',1,'Maricar D. Santos','Logged out of internal portal',NULL,'2026-10-10 09:55:31'),(76,'staff',2,'Jessica P. Villanueva','Marked DR-2026-00006 as Rejected','DR-2026-00006','2026-10-10 09:56:03'),(77,'staff',2,'Jessica P. Villanueva','Logged out of internal portal',NULL,'2026-10-10 09:56:11'),(78,'head',3,'Rodel H. Ortega','Created announcement (Draft)','Audit Test Announcement','2026-10-10 09:56:22'),(79,'head',3,'Rodel H. Ortega','Updated announcement (Draft)','Audit Test Announcement (edited)','2026-10-10 09:56:42'),(80,'head',3,'Rodel H. Ortega','Logged out of internal portal',NULL,'2026-10-10 09:56:51'),(81,'head',3,'Rodel H. Ortega','Logged in to internal portal',NULL,'2026-10-10 09:56:51'),(82,'head',3,'Rodel H. Ortega','Deleted announcement','8','2026-10-10 09:56:52'),(83,'client',3,'AuditClient TestUser','Registered a client account',NULL,'2026-10-10 09:57:02'),(84,'staff',2,'Jessica P. Villanueva','Logged in to internal portal',NULL,'2026-10-10 09:57:49'),(85,'client',1,'Ramon Villareal','Logged in to client portal',NULL,'2026-10-10 09:58:15'),(86,'staff',2,'Jessica P. Villanueva','Marked DR-2026-00007 as Processing','DR-2026-00007','2026-10-10 09:58:28'),(87,'admin',1,'Maricar D. Santos','Logged in to internal portal',NULL,'2026-10-10 09:59:35');
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clients`
--

DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role_id` tinyint NOT NULL DEFAULT '1',
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) NOT NULL,
  `email` varchar(150) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `clients_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `chk_clients_role` CHECK ((`role_id` = 1))
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clients`
--

LOCK TABLES `clients` WRITE;
/*!40000 ALTER TABLE `clients` DISABLE KEYS */;
INSERT INTO `clients` VALUES (1,1,'Ramon','Villareal','r.villareal@example.com','0917-224-5510','$2y$12$.kZ2.nAJztNKxIcAHX7q3uM8NzT7zLZWFEIaH/EusUQEEY5jYpt6e','Active','2026-09-04 18:23:27'),(2,1,'Audit','Flowtest','audit.flowtest@example.com','0917-888-9999','$2y$12$BzRgbwdxLoZEy9R7HfgP5.Os3S6AWwL2e.8rZlt9JGv9PH7GjzulW','Active','2026-10-08 22:34:36'),(3,1,'AuditClient','TestUser','auditclient.testuser@example.com','0917-999-8888','$2y$12$c0/m5D98Y.QAp9fRsuCgieaTN.vc5ladkSV88z8tRZ4LHCltregSS','Active','2026-10-10 09:57:02');
/*!40000 ALTER TABLE `clients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_types`
--

DROP TABLE IF EXISTS `document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_types` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_types`
--

LOCK TABLES `document_types` WRITE;
/*!40000 ALTER TABLE `document_types` DISABLE KEYS */;
INSERT INTO `document_types` VALUES (1,'Certified True Copy of Tax Declaration (CTC-TD)',NULL,1,'2026-10-07 21:23:17','2026-10-07 21:23:17'),(2,'Certification of No/With Existing Improvement',NULL,1,'2026-10-07 21:23:17','2026-10-07 21:23:17'),(3,'Certification of Property/No Property Holdings',NULL,1,'2026-10-07 21:23:17','2026-10-07 21:23:17'),(4,'Certification of No Liens and Encumbrances',NULL,1,'2026-10-07 21:23:17','2026-10-07 21:23:17'),(5,'Certification of Assessment',NULL,1,'2026-10-07 21:23:17','2026-10-07 21:23:17');
/*!40000 ALTER TABLE `document_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_validation`
--

DROP TABLE IF EXISTS `document_validation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_validation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `request_document_id` int NOT NULL,
  `validation_status` enum('pending','valid','invalid','needs_review') NOT NULL DEFAULT 'pending',
  `remarks` varchar(255) DEFAULT NULL,
  `validated_by` int DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_request_document` (`request_document_id`),
  KEY `validated_by` (`validated_by`),
  CONSTRAINT `document_validation_ibfk_1` FOREIGN KEY (`request_document_id`) REFERENCES `request_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_validation_ibfk_2` FOREIGN KEY (`validated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_validation`
--

LOCK TABLES `document_validation` WRITE;
/*!40000 ALTER TABLE `document_validation` DISABLE KEYS */;
INSERT INTO `document_validation` VALUES (2,1,'valid','Passed automatic format/size check.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(3,2,'valid','Passed automatic format/size check.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(4,3,'pending','No file uploaded for this requirement.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(5,4,'pending','No file uploaded for this requirement.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(6,5,'valid','Passed automatic format/size check.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(7,6,'valid','Passed automatic format/size check.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(8,7,'valid','Passed automatic format/size check.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(9,8,'valid','Passed automatic format/size check.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(10,9,'valid','Passed automatic format/size check.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(11,10,'valid','Passed automatic format/size check.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(12,11,'pending','No file uploaded for this requirement.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(13,12,'valid','Passed automatic format/size check.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(14,13,'valid','Passed automatic format/size check.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(15,14,'pending','No file uploaded for this requirement.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(16,15,'pending','No file uploaded for this requirement.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(17,16,'pending','No file uploaded for this requirement.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(18,17,'pending','No file uploaded for this requirement.',NULL,'2026-10-07 21:37:54','2026-10-07 21:37:54'),(33,27,'pending','No file uploaded for this requirement.',NULL,'2026-10-08 22:34:44','2026-10-08 22:34:44'),(34,28,'pending','No file uploaded for this requirement.',NULL,'2026-10-08 22:39:42','2026-10-08 22:39:42'),(35,29,'pending','No file uploaded for this requirement.',NULL,'2026-10-08 22:39:42','2026-10-08 22:39:42'),(36,30,'pending','No file uploaded for this requirement.',NULL,'2026-10-08 22:39:42','2026-10-08 22:39:42'),(37,31,'pending','No file uploaded for this requirement.',NULL,'2026-10-08 22:39:42','2026-10-08 22:39:42'),(38,32,'pending','No file uploaded for this requirement.',NULL,'2026-10-08 22:39:42','2026-10-08 22:39:42'),(39,33,'pending','No file uploaded for this requirement.',NULL,'2026-10-08 22:39:42','2026-10-08 22:39:42'),(40,34,'pending','No file uploaded for this requirement.',NULL,'2026-10-08 22:45:58','2026-10-08 22:45:58'),(41,35,'pending','No file uploaded for this requirement.',NULL,'2026-10-10 09:40:17','2026-10-10 09:40:17'),(42,36,'pending','No file uploaded for this requirement.',NULL,'2026-10-10 09:57:12','2026-10-10 09:57:12'),(43,37,'pending','No file uploaded for this requirement.',NULL,'2026-10-10 09:57:12','2026-10-10 09:57:12'),(44,38,'pending','No file uploaded for this requirement.',NULL,'2026-10-10 09:57:12','2026-10-10 09:57:12'),(45,39,'pending','No file uploaded for this requirement.',NULL,'2026-10-10 09:57:12','2026-10-10 09:57:12'),(46,40,'pending','No file uploaded for this requirement.',NULL,'2026-10-10 09:57:12','2026-10-10 09:57:12'),(47,41,'pending','No file uploaded for this requirement.',NULL,'2026-10-10 09:57:12','2026-10-10 09:57:12'),(48,42,'pending','No file uploaded for this requirement.',NULL,'2026-10-10 09:57:12','2026-10-10 09:57:12');
/*!40000 ALTER TABLE `document_validation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `help_messages`
--

DROP TABLE IF EXISTS `help_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `help_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` int DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `status` enum('Open','Resolved') NOT NULL DEFAULT 'Open',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `help_messages_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `help_messages`
--

LOCK TABLES `help_messages` WRITE;
/*!40000 ALTER TABLE `help_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `help_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` int DEFAULT NULL,
  `request_id` int DEFAULT NULL,
  `type` varchar(40) NOT NULL,
  `message` text NOT NULL,
  `channel` enum('system','sms','email') NOT NULL DEFAULT 'system',
  `delivery_status` enum('pending','sent','failed') NOT NULL DEFAULT 'sent',
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `idx_client_unread` (`client_id`,`read_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,1,1,'status_change','SMART ASSESS: Your request DR-2026-00001 has been received by the Mabini Assessor Office.','system','sent',NULL,'2026-08-29 10:23:27'),(2,1,1,'status_change','SMART ASSESS: Good news! Your request DR-2026-00001 has been approved.','system','sent',NULL,'2026-08-29 11:23:27'),(6,2,11,'status_change','SMART ASSESS: Your request DR-2026-00004 has been received by the Mabini Assessor Office.','system','sent',NULL,'2026-10-08 22:34:44'),(7,2,11,'status_change','SMART ASSESS: Your request DR-2026-00004 is now under review.','system','sent',NULL,'2026-10-08 22:38:49'),(8,2,12,'status_change','SMART ASSESS: Your request LT-2026-00003 has been received by the Mabini Assessor Office.','system','sent',NULL,'2026-10-08 22:39:42'),(9,2,12,'status_change','SMART ASSESS: Your request LT-2026-00003 is now under review.','system','sent',NULL,'2026-10-08 22:39:50'),(10,2,11,'status_change','SMART ASSESS: Your request DR-2026-00004 needs corrections. Missing/invalid: Valid Government-Issued ID.','system','sent',NULL,'2026-10-08 23:29:49'),(11,2,11,'manual','[Courtesy Follow-up] Please drop by to pick up your documents.','system','sent',NULL,'2026-10-08 23:33:19'),(12,3,15,'status_change','SMART ASSESS: Your request DR-2026-00007 has been received by the Mabini Assessor Office.','system','sent',NULL,'2026-10-10 09:57:12'),(13,3,16,'status_change','SMART ASSESS: Your request LT-2026-00004 has been received by the Mabini Assessor Office.','system','sent',NULL,'2026-10-10 09:57:12'),(14,3,15,'status_change','SMART ASSESS: Your request DR-2026-00007 is now under review.','system','sent',NULL,'2026-10-10 09:58:28');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(60) NOT NULL,
  `description` varchar(200) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'manage_staff_accounts','Create, edit, activate/deactivate, archive, and restore internal accounts'),(2,'view_roles','View the roles reference page'),(3,'manage_settings','Edit office settings and processing-time thresholds'),(4,'view_audit_logs','View the system audit log'),(5,'view_admin_dashboard','View the Admin dashboard'),(6,'manage_announcements','Create, edit, schedule, publish, and cancel announcements'),(7,'view_reports','View generated reports'),(8,'view_head_dashboard','View the Department Head dashboard'),(9,'process_requests','View, review, and update the status of document/land-transfer requests'),(10,'run_ai_checker','View AI Rule-Based Requirement Checker results'),(11,'send_notifications','Send notifications to clients about their requests'),(12,'view_staff_dashboard','View the Staff dashboard');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `request_documents`
--

DROP TABLE IF EXISTS `request_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `request_documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `request_id` int NOT NULL,
  `doc_key` varchar(40) NOT NULL,
  `label` varchar(150) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `stored_path` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `file_status` enum('ok','flagged','missing') NOT NULL DEFAULT 'missing',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_request_doc` (`request_id`,`doc_key`),
  CONSTRAINT `request_documents_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `request_documents`
--

LOCK TABLES `request_documents` WRITE;
/*!40000 ALTER TABLE `request_documents` DISABLE KEYS */;
INSERT INTO `request_documents` VALUES (1,1,'ownerId','Valid Government-Issued ID','ownerId.png','uploads/DR-2026-00001_ownerId_93cadaf2.png','image/png',69,'ok'),(2,2,'ownerId','Valid Government-Issued ID','ownerId.png','uploads/DR-2026-00002_ownerId_b4727769.png','image/png',69,'ok'),(3,2,'requesterId','Valid ID of Requester',NULL,NULL,NULL,NULL,'missing'),(4,2,'authLetter','Authorization Letter',NULL,NULL,NULL,NULL,'missing'),(5,3,'ownerId','Valid ID of Property Owner','ownerId.png','uploads/LT-2026-00001_ownerId_14b5f148.png','image/png',69,'ok'),(6,3,'ctcTdOrTitle','Certified True Copy of Tax Declaration or Title','ctcTdOrTitle.pdf','uploads/LT-2026-00001_ctcTdOrTitle_ccd1e870.pdf','application/pdf',68,'ok'),(7,3,'notarialDeed','Notarial Deed of Sale or Donation','notarialDeed.pdf','uploads/LT-2026-00001_notarialDeed_2eb081aa.pdf','application/pdf',68,'ok'),(8,3,'vicinityMap','Vicinity Map','vicinityMap.png','uploads/LT-2026-00001_vicinityMap_c18e8d9e.png','image/png',69,'ok'),(9,3,'certNoImprovement','Certification of No Improvement','certNoImprovement.pdf','uploads/LT-2026-00001_certNoImprovement_de855a6e.pdf','application/pdf',68,'ok'),(10,3,'taxClearance','Tax Clearance','taxClearance.pdf','uploads/LT-2026-00001_taxClearance_5f8bc3ba.pdf','application/pdf',68,'ok'),(11,4,'ownerId','Valid Government-Issued ID',NULL,NULL,NULL,NULL,'missing'),(12,5,'ownerId','Valid ID of Property Owner','ownerId.png','uploads/LT-2026-00002_ownerId_5f2bf889.png','image/png',69,'ok'),(13,5,'ctcTdOrTitle','Certified True Copy of Tax Declaration or Title','ctcTdOrTitle.pdf','uploads/LT-2026-00002_ctcTdOrTitle_79d855ee.pdf','application/pdf',68,'ok'),(14,5,'notarialDeed','Notarial Deed of Sale or Donation',NULL,NULL,NULL,NULL,'missing'),(15,5,'vicinityMap','Vicinity Map',NULL,NULL,NULL,NULL,'missing'),(16,5,'certNoImprovement','Certification of No Improvement',NULL,NULL,NULL,NULL,'missing'),(17,5,'taxClearance','Tax Clearance',NULL,NULL,NULL,NULL,'missing'),(27,11,'ownerId','Valid Government-Issued ID',NULL,NULL,NULL,NULL,'missing'),(28,12,'ctcTdOrTitle','Certified True Copy of Tax Declaration or Title',NULL,NULL,NULL,NULL,'missing'),(29,12,'notarialDeed','Notarial Deed of Sale or Donation',NULL,NULL,NULL,NULL,'missing'),(30,12,'vicinityMap','Vicinity Map',NULL,NULL,NULL,NULL,'missing'),(31,12,'certNoImprovement','Certification of No Improvement',NULL,NULL,NULL,NULL,'missing'),(32,12,'taxClearance','Tax Clearance',NULL,NULL,NULL,NULL,'missing'),(33,12,'ownerId','Valid ID of Property Owner',NULL,NULL,NULL,NULL,'missing'),(34,13,'ownerId','Valid Government-Issued ID',NULL,NULL,NULL,NULL,'missing'),(35,14,'ownerId','Valid Government-Issued ID',NULL,NULL,NULL,NULL,'missing'),(36,15,'ownerId','Valid Government-Issued ID',NULL,NULL,NULL,NULL,'missing'),(37,16,'ctcTdOrTitle','Certified True Copy of Tax Declaration or Title',NULL,NULL,NULL,NULL,'missing'),(38,16,'notarialDeed','Notarial Deed of Sale or Donation',NULL,NULL,NULL,NULL,'missing'),(39,16,'vicinityMap','Vicinity Map',NULL,NULL,NULL,NULL,'missing'),(40,16,'certNoImprovement','Certification of No Improvement',NULL,NULL,NULL,NULL,'missing'),(41,16,'taxClearance','Tax Clearance',NULL,NULL,NULL,NULL,'missing'),(42,16,'ownerId','Valid ID of Property Owner',NULL,NULL,NULL,NULL,'missing');
/*!40000 ALTER TABLE `request_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `request_status_log`
--

DROP TABLE IF EXISTS `request_status_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `request_status_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `request_id` int NOT NULL,
  `status` varchar(30) NOT NULL,
  `actor` varchar(10) NOT NULL DEFAULT 'staff',
  `sms_body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `request_status_log_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `request_status_log`
--

LOCK TABLES `request_status_log` WRITE;
/*!40000 ALTER TABLE `request_status_log` DISABLE KEYS */;
INSERT INTO `request_status_log` VALUES (1,1,'Received','client','SMART ASSESS: Your request DR-2026-00001 has been received by the Mabini Assessor Office.','2026-08-29 10:23:27'),(2,1,'Approved','staff','SMART ASSESS: Good news! Your request DR-2026-00001 has been approved.','2026-08-29 11:23:27'),(3,2,'Received','client','SMART ASSESS: Your request DR-2026-00002 has been received by the Mabini Assessor Office.','2026-09-02 10:23:27'),(4,2,'Rejected','staff','SMART ASSESS: Your request DR-2026-00002 needs corrections. Missing/invalid: Valid ID of Requester, Authorization Letter.','2026-09-02 11:23:27'),(5,3,'Received','client','SMART ASSESS: Your request LT-2026-00001 has been received by the Mabini Assessor Office.','2026-09-03 10:23:27'),(6,3,'Processing','staff','SMART ASSESS: Your request LT-2026-00001 is now under review.','2026-09-03 11:23:27'),(7,4,'Received','client','SMART ASSESS: Your request DR-2026-00003 has been received by the Mabini Assessor Office.','2026-09-03 22:23:27'),(8,5,'Received','client','SMART ASSESS: Your request LT-2026-00002 has been received by the Mabini Assessor Office.','2026-09-04 07:23:27'),(12,4,'Timed Out','system','SMART ASSESS: Your request DR-2026-00003 has exceeded our expected processing time. We apologize for the delay — our office is prioritizing it now.','2026-10-07 20:51:17'),(14,3,'Timed Out','system','SMART ASSESS: Your request LT-2026-00001 has exceeded our expected processing time. We apologize for the delay — our office is prioritizing it now.','2026-10-07 20:51:17'),(15,5,'Timed Out','system','SMART ASSESS: Your request LT-2026-00002 has exceeded our expected processing time. We apologize for the delay — our office is prioritizing it now.','2026-10-07 20:51:17'),(22,11,'Received','client','SMART ASSESS: Your request DR-2026-00004 has been received by the Mabini Assessor Office.','2026-10-08 22:34:44'),(23,11,'Processing','staff','SMART ASSESS: Your request DR-2026-00004 is now under review.','2026-10-08 22:38:49'),(24,12,'Received','client','SMART ASSESS: Your request LT-2026-00003 has been received by the Mabini Assessor Office.','2026-10-08 22:39:42'),(25,12,'Processing','staff','SMART ASSESS: Your request LT-2026-00003 is now under review.','2026-10-08 22:39:50'),(26,13,'Received','client','SMART ASSESS: Your request DR-2026-00005 has been received by the Mabini Assessor Office.','2026-10-08 22:45:58'),(27,13,'Processing','staff','SMART ASSESS: Your request DR-2026-00005 is now under review.','2026-10-08 22:47:08'),(28,11,'Rejected','staff','SMART ASSESS: Your request DR-2026-00004 needs corrections. Missing/invalid: Valid Government-Issued ID.','2026-10-08 23:29:49'),(29,11,'Rejected','staff','[Courtesy Follow-up] Please drop by to pick up your documents.','2026-10-08 23:33:19'),(30,14,'Received','client','SMART ASSESS: Your request DR-2026-00006 has been received by the Mabini Assessor Office.','2026-10-10 09:40:17'),(31,14,'Processing','staff','SMART ASSESS: Your request DR-2026-00006 is now under review.','2026-10-10 09:40:26'),(32,14,'Rejected','staff','SMART ASSESS: Your request DR-2026-00006 needs corrections. Missing/invalid: Valid Government-Issued ID.','2026-10-10 09:56:03'),(33,15,'Received','client','SMART ASSESS: Your request DR-2026-00007 has been received by the Mabini Assessor Office.','2026-10-10 09:57:12'),(34,16,'Received','client','SMART ASSESS: Your request LT-2026-00004 has been received by the Mabini Assessor Office.','2026-10-10 09:57:12'),(35,15,'Processing','staff','SMART ASSESS: Your request DR-2026-00007 is now under review.','2026-10-10 09:58:28');
/*!40000 ALTER TABLE `request_status_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `request_statuses`
--

DROP TABLE IF EXISTS `request_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `request_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(30) NOT NULL,
  `sort_order` tinyint unsigned NOT NULL,
  `is_terminal` tinyint(1) NOT NULL DEFAULT '0',
  `badge_class` varchar(20) NOT NULL DEFAULT 'slate',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `request_statuses`
--

LOCK TABLES `request_statuses` WRITE;
/*!40000 ALTER TABLE `request_statuses` DISABLE KEYS */;
INSERT INTO `request_statuses` VALUES (1,'Received',1,0,'slate'),(2,'Processing',2,0,'amber'),(3,'Approved',3,1,'green'),(4,'Rejected',4,1,'red'),(5,'Out for Release',5,1,'blue'),(6,'Timed Out',6,0,'orange');
/*!40000 ALTER TABLE `request_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `requests`
--

DROP TABLE IF EXISTS `requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` int DEFAULT NULL,
  `reference_no` varchar(20) NOT NULL,
  `flow` enum('docreq','landtransfer') NOT NULL,
  `first_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `email` varchar(150) NOT NULL,
  `address_line` varchar(255) NOT NULL,
  `province` varchar(80) NOT NULL DEFAULT 'Batangas',
  `city` varchar(80) NOT NULL DEFAULT 'Mabini',
  `zip_code` varchar(10) NOT NULL,
  `document_type` varchar(120) DEFAULT NULL,
  `transfer_type` varchar(40) DEFAULT NULL,
  `purpose` varchar(80) NOT NULL,
  `arp_number` varchar(60) NOT NULL,
  `property_address` varchar(255) NOT NULL,
  `barangay` varchar(100) NOT NULL,
  `is_owner` tinyint(1) NOT NULL DEFAULT '1',
  `status` enum('Received','Processing','Approved','Rejected','Out for Release','Timed Out') NOT NULL DEFAULT 'Received',
  `requirement_complete` tinyint(1) NOT NULL DEFAULT '0',
  `advisory` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `idx_status` (`status`),
  KEY `idx_flow` (`flow`),
  KEY `idx_barangay` (`barangay`),
  KEY `idx_client` (`client_id`),
  CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `requests`
--

LOCK TABLES `requests` WRITE;
/*!40000 ALTER TABLE `requests` DISABLE KEYS */;
INSERT INTO `requests` VALUES (1,1,'DR-2026-00001','docreq','Ramon',NULL,'Villareal','0917-224-5510','r.villareal@example.com','12 Rizal St.','Batangas','Mabini','4202','Certified True Copy of Tax Declaration (CTC-TD)',NULL,'For Titling','024-01-0032','Brgy. Poblacion, Mabini','Poblacion',1,'Approved',1,NULL,'2026-08-29 10:23:27'),(2,NULL,'DR-2026-00002','docreq','Josefina',NULL,'Dimaano','0928-771-2093','j.dimaano@example.com','Purok 3','Batangas','Mabini','4202','Certification of No/With Existing Improvement',NULL,'For Building Permit','024-03-1187','Brgy. Bagalangit, Mabini','Bagalangit',0,'Rejected',0,NULL,'2026-09-02 10:23:27'),(3,NULL,'LT-2026-00001','landtransfer','Antonio',NULL,'Reyes','0939-402-8815','a.reyes@example.com','45 Mabini St.','Batangas','Mabini','4202',NULL,'Sale','For Transfer','024-02-0765','Brgy. Solo, Mabini','Solo',1,'Timed Out',1,NULL,'2026-09-03 10:23:27'),(4,NULL,'DR-2026-00003','docreq','Corazon',NULL,'Manalo','0905-118-4471','c.manalo@example.com','Sitio Ilaya','Batangas','Mabini','4202','Certification of No Liens and Encumbrances',NULL,'Personal Copy','024-05-2290','Brgy. Sto. Tomas, Mabini','Sto. Tomas',1,'Timed Out',0,NULL,'2026-09-03 22:23:27'),(5,NULL,'LT-2026-00002','landtransfer','Bienvenido',NULL,'Cruz','0918-330-2244','b.cruz@example.com','Zone 2','Batangas','Mabini','4202',NULL,'Inheritance','For Titling','024-04-0518','Brgy. Poblacion, Mabini','Poblacion',1,'Timed Out',0,NULL,'2026-09-04 07:23:27'),(11,2,'DR-2026-00004','docreq','Audit',NULL,'Flowtest','0917-888-9999','audit.flowtest@example.com','1 Workflow St','Batangas','Mabini','4116','Certified True Copy of Tax Declaration (CTC-TD)',NULL,'Personal Copy','14-0008-00001','2 Prop Rd','Poblacion',1,'Rejected',0,NULL,'2026-10-08 22:34:44'),(12,2,'LT-2026-00003','landtransfer','Audit',NULL,'Flowtest','0917-888-9999','audit.flowtest@example.com','1 Workflow St','Batangas','Mabini','4116',NULL,'Sale','','14-0008-00002','','Poblacion',1,'Processing',0,NULL,'2026-10-08 22:39:42'),(13,NULL,'DR-2026-00005','docreq','Trace',NULL,'Verify','0917-222-3333','trace.verify@example.com','99 Diagnostic Ave','Batangas','Mabini','4116','Certified True Copy of Tax Declaration (CTC-TD)',NULL,'Personal Copy','14-0009-00001','100 Trace Rd','Poblacion',1,'Processing',0,NULL,'2026-10-08 22:45:58'),(14,NULL,'DR-2026-00006','docreq','Env',NULL,'Recheck','0917-555-1234','env.recheck@example.com','1 Verify St','Batangas','Mabini','4116','Certified True Copy of Tax Declaration (CTC-TD)',NULL,'Personal Copy','14-0009-00002','2 Verify Rd','Poblacion',1,'Rejected',0,NULL,'2026-10-10 09:40:17'),(15,3,'DR-2026-00007','docreq','AuditClient',NULL,'TestUser','0917-999-8888','auditclient.testuser@example.com','99 Audit Section 3 St','Batangas','Mabini','4116','Certified True Copy of Tax Declaration (CTC-TD)',NULL,'Personal Copy','14-0010-00001','100 Audit Prop Rd','Poblacion',1,'Processing',0,NULL,'2026-10-10 09:57:12'),(16,3,'LT-2026-00004','landtransfer','AuditClient',NULL,'TestUser','0917-999-8888','auditclient.testuser@example.com','99 Audit Section 3 St','Batangas','Mabini','4116',NULL,'Sale','','14-0010-00002','','Poblacion',1,'Received',0,NULL,'2026-10-10 09:57:12');
/*!40000 ALTER TABLE `requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `role_id` tinyint NOT NULL,
  `permission_id` tinyint unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (3,1),(3,2),(3,3),(3,4),(3,5),(4,6),(4,7),(4,8),(2,9),(2,10),(2,11),(2,12);
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` tinyint NOT NULL,
  `name` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (3,'ADMIN'),(1,'CLIENT'),(4,'DEPARTMENT_HEAD'),(2,'STAFF');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES ('docreq_processing_days','5'),('landtransfer_processing_days','10'),('office_email','assessor@mabini.gov.ph'),('office_hours','Monday to Friday, 8:00 AM - 5:00 PM'),('office_name','Mabini Assessor Office'),('office_phone','(043) 487-0123');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transfer_types`
--

DROP TABLE IF EXISTS `transfer_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transfer_types` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transfer_types`
--

LOCK TABLES `transfer_types` WRITE;
/*!40000 ALTER TABLE `transfer_types` DISABLE KEYS */;
INSERT INTO `transfer_types` VALUES (1,'Sale',NULL,1,'2026-10-07 21:23:17','2026-10-07 21:23:17'),(2,'Donation',NULL,1,'2026-10-07 21:23:17','2026-10-07 21:23:17'),(3,'Estate',NULL,1,'2026-10-07 21:23:17','2026-10-07 21:23:17');
/*!40000 ALTER TABLE `transfer_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_sessions`
--

DROP TABLE IF EXISTS `user_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_type` enum('client','staff','admin','head') NOT NULL,
  `user_id` int NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `login_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `logout_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_type`,`user_id`),
  KEY `idx_active` (`logout_at`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_sessions`
--

LOCK TABLES `user_sessions` WRITE;
/*!40000 ALTER TABLE `user_sessions` DISABLE KEYS */;
INSERT INTO `user_sessions` VALUES (1,'staff',2,'127.0.0.1','curl/8.7.1','2026-10-07 21:26:59','2026-10-07 21:26:59'),(2,'client',1,'127.0.0.1','curl/8.7.1','2026-10-07 21:27:29',NULL),(3,'admin',1,'127.0.0.1','curl/8.7.1','2026-10-07 21:28:29',NULL),(4,'admin',1,'127.0.0.1','curl/8.7.1','2026-10-07 21:35:01','2026-10-07 21:36:03'),(5,'staff',2,'127.0.0.1','curl/8.7.1','2026-10-07 21:35:01',NULL),(6,'head',3,'127.0.0.1','curl/8.7.1','2026-10-07 21:35:02',NULL),(7,'client',1,'127.0.0.1','curl/8.7.1','2026-10-07 21:35:18',NULL),(8,'admin',1,'127.0.0.1','curl/8.7.1','2026-10-08 22:26:16',NULL),(9,'staff',5,'127.0.0.1','curl/8.7.1','2026-10-08 22:26:39',NULL),(10,'client',2,'127.0.0.1','curl/8.7.1','2026-10-08 22:34:36',NULL),(11,'staff',2,'127.0.0.1','curl/8.7.1','2026-10-08 22:38:07',NULL),(12,'admin',1,'127.0.0.1','curl/8.7.1','2026-10-08 22:47:01',NULL),(13,'admin',1,'127.0.0.1','curl/8.7.1','2026-10-08 23:28:50',NULL),(14,'staff',2,'127.0.0.1','curl/8.7.1','2026-10-08 23:28:50','2026-10-08 23:29:19'),(15,'head',3,'127.0.0.1','curl/8.7.1','2026-10-08 23:28:51',NULL),(16,'staff',2,'127.0.0.1','curl/8.7.1','2026-10-08 23:29:38',NULL),(17,'staff',2,'127.0.0.1','curl/8.7.1','2026-10-08 23:37:58',NULL),(18,'admin',1,'127.0.0.1','curl/8.7.1','2026-10-08 23:38:11',NULL),(19,'head',3,'127.0.0.1','curl/8.7.1','2026-10-08 23:38:11',NULL),(20,'staff',2,'127.0.0.1','curl/8.7.1','2026-10-08 23:39:21',NULL),(21,'admin',1,'127.0.0.1','curl/8.7.1','2026-10-10 09:34:50',NULL),(22,'admin',1,'127.0.0.1','curl/8.7.1','2026-10-10 09:39:07',NULL),(23,'staff',2,'127.0.0.1','curl/8.7.1','2026-10-10 09:39:08',NULL),(24,'head',3,'127.0.0.1','curl/8.7.1','2026-10-10 09:39:08',NULL),(25,'admin',1,'127.0.0.1','curl/8.7.1','2026-10-10 09:50:55','2026-10-10 09:55:31'),(26,'staff',2,'127.0.0.1','curl/8.7.1','2026-10-10 09:50:56','2026-10-10 09:56:11'),(27,'head',3,'127.0.0.1','curl/8.7.1','2026-10-10 09:50:56','2026-10-10 09:56:51'),(28,'admin',1,'127.0.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/153.0.8010.12 Safari/537.36','2026-10-10 09:53:06',NULL),(29,'admin',1,'127.0.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/153.0.8010.12 Safari/537.36','2026-10-10 09:53:07',NULL),(30,'admin',1,'127.0.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/153.0.8010.12 Safari/537.36','2026-10-10 09:53:57',NULL),(31,'admin',1,'127.0.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/153.0.8010.12 Safari/537.36','2026-10-10 09:54:13',NULL),(32,'admin',1,'127.0.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/153.0.8010.12 Safari/537.36','2026-10-10 09:54:14',NULL),(33,'head',3,'127.0.0.1','curl/8.7.1','2026-10-10 09:56:51',NULL),(34,'client',3,'127.0.0.1','curl/8.7.1','2026-10-10 09:57:02',NULL),(35,'staff',2,'127.0.0.1','curl/8.7.1','2026-10-10 09:57:49',NULL),(36,'client',1,'127.0.0.1','curl/8.7.1','2026-10-10 09:58:15',NULL),(37,'admin',1,'127.0.0.1','curl/8.7.1','2026-10-10 09:59:35',NULL);
/*!40000 ALTER TABLE `user_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role_id` tinyint NOT NULL,
  `name` varchar(150) NOT NULL,
  `username` varchar(80) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `position_title` varchar(80) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int DEFAULT NULL,
  `restored_at` datetime DEFAULT NULL,
  `restored_by` int DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `role_id` (`role_id`),
  KEY `fk_users_archived_by` (`archived_by`),
  KEY `fk_users_restored_by` (`restored_by`),
  KEY `fk_users_deleted_by` (`deleted_by`),
  KEY `idx_archived_at` (`archived_at`),
  KEY `idx_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_users_archived_by` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_restored_by` FOREIGN KEY (`restored_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `chk_users_role` CHECK ((`role_id` in (2,3,4)))
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,3,'Maricar D. Santos','maricar.admin',NULL,NULL,'$2y$12$.kZ2.nAJztNKxIcAHX7q3uM8NzT7zLZWFEIaH/EusUQEEY5jYpt6e','Active',NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-04 18:23:27'),(2,2,'Jessica P. Villanueva','jessica.staff',NULL,NULL,'$2y$12$.kZ2.nAJztNKxIcAHX7q3uM8NzT7zLZWFEIaH/EusUQEEY5jYpt6e','Active',NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-04 18:23:27'),(3,4,'Rodel H. Ortega','rodel.head',NULL,NULL,'$2y$12$.kZ2.nAJztNKxIcAHX7q3uM8NzT7zLZWFEIaH/EusUQEEY5jYpt6e','Active',NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-04 18:23:27'),(5,2,'Archive Test','archive.test','+639171234567','Assessment Clerk','$2y$12$A3rFVzQzV3m0Bq3R2R8/POsiWG..mSQQ9gyzKiC2GObiWlPFtLbw6','Inactive','2026-10-08 22:26:52',1,'2026-10-08 22:26:39',1,'2026-10-08 22:26:52',1,'2026-10-08 22:26:17'),(6,2,'Audit Test Account','audit.test.account','+639170001111','Assessment Clerk','$2y$12$VFXSk5ivK9NFOxrRXxlllO9MMDtMqJ50PR76qMNN5pI3tzZNLMyEG','Inactive','2026-10-10 09:55:19',1,'2026-10-10 09:55:19',1,'2026-10-10 09:55:19',1,'2026-10-10 09:54:33');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'smart_assess'
--

--
-- Dumping routines for database 'smart_assess'
--
SET @@SESSION.SQL_LOG_BIN = @MYSQLDUMP_TEMP_LOG_BIN;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-10 10:30:58
