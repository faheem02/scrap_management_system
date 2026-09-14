-- ==============================================================================
-- SAFE DATABASE UPDATE SCRIPT FOR CLIENT (NO DATA WILL BE DELETED OR WIPED)
-- Run this in phpMyAdmin -> SQL tab on the client's laptop.
-- ==============================================================================

USE `scrap_management_system`;

-- 1. Create table `fund_transfers` if not exists
CREATE TABLE IF NOT EXISTS `fund_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_no` varchar(50) NOT NULL,
  `transfer_type` varchar(50) NOT NULL,
  `from_type` varchar(50) NOT NULL,
  `from_bank_id` int(11) DEFAULT NULL,
  `to_type` varchar(50) NOT NULL,
  `to_bank_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `person_name` varchar(150) DEFAULT NULL,
  `person_phone` varchar(50) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `transfer_date` date NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher_no` (`voucher_no`),
  KEY `idx_voucher` (`voucher_no`),
  KEY `idx_transfer_date` (`transfer_date`),
  KEY `idx_transfer_type` (`transfer_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create table `plant_areas` if not exists
CREATE TABLE IF NOT EXISTS `plant_areas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Add columns to `customer_receipts` if not exists
ALTER TABLE `customer_receipts` ADD COLUMN IF NOT EXISTS `sales_id` INT DEFAULT NULL AFTER `customer_id`;
ALTER TABLE `customer_receipts` ADD COLUMN IF NOT EXISTS `is_split` TINYINT(1) NOT NULL DEFAULT 0 AFTER `created_at`;

-- 4. Add columns to `purchases` if not exists
ALTER TABLE `purchases` ADD COLUMN IF NOT EXISTS `vehicle_no` VARCHAR(50) DEFAULT NULL AFTER `supplier_id`;
ALTER TABLE `purchases` ADD COLUMN IF NOT EXISTS `party_name` VARCHAR(100) DEFAULT NULL AFTER `vehicle_no`;
ALTER TABLE `purchases` ADD COLUMN IF NOT EXISTS `plant_area` VARCHAR(100) DEFAULT NULL;
ALTER TABLE `purchases` ADD COLUMN IF NOT EXISTS `plant_name` VARCHAR(100) DEFAULT NULL;
ALTER TABLE `purchases` ADD COLUMN IF NOT EXISTS `plant_category` VARCHAR(100) DEFAULT NULL;

-- 5. Add columns to `purchase_items` if not exists
ALTER TABLE `purchase_items` ADD COLUMN IF NOT EXISTS `weight1` DECIMAL(12,2) DEFAULT NULL;
ALTER TABLE `purchase_items` ADD COLUMN IF NOT EXISTS `weight2` DECIMAL(12,2) DEFAULT NULL;

-- 6. Add columns to `suppliers` if not exists
ALTER TABLE `suppliers` ADD COLUMN IF NOT EXISTS `adjustment` DECIMAL(12,2) DEFAULT 0.00 AFTER `opening_balance`;

-- 7. Ensure at least one Branch exists (prevents foreign key constraint errors)
INSERT IGNORE INTO `branches` (`id`, `name`, `address`, `phone`, `status`, `created_at`) 
VALUES (1, 'Main Branch', 'Head Office', '—', 1, CURDATE());
