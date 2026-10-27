-- ==============================================================================
-- Toursphere - Supply Chain & Logistics Portal
-- MySQL / MariaDB Production Schema (HostForge / cPanel / CloudPanel Compatible)
-- Character Set: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ------------------------------------------------------------------------------
-- 1. Authentication & Users
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` VARCHAR(50) DEFAULT 'admin',
    `full_name` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `is_archived` TINYINT(1) DEFAULT 0,
    `two_factor_secret` TEXT NULL,
    `two_factor_enabled` TINYINT(1) DEFAULT 0,
    `two_factor_confirmed_at` DATETIME NULL,
    `two_factor_recovery_codes_generated_at` DATETIME NULL,
    `two_factor_time_offset` INT DEFAULT 0,
    `last_login` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_email` (`email`),
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_recovery_codes` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT NOT NULL,
    `code_hash` VARCHAR(255) NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_recovery_user` (`user_id`),
    CONSTRAINT `fk_user_recovery_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `two_factor_rate_limits` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(150) NOT NULL,
    `attempt_time` BIGINT NOT NULL,
    INDEX `idx_2fa_rate_limit` (`identifier`, `attempt_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NULL,
    INDEX `idx_user_sessions_token` (`token`),
    INDEX `idx_user_sessions_user_id` (`user_id`),
    CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. System Settings & Audit Logs
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT NULL,
    `setting_group` VARCHAR(50) NULL,
    `description` TEXT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT NULL,
    `action` VARCHAR(100) NOT NULL,
    `module` VARCHAR(50) NOT NULL,
    `description` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_user_id` (`user_id`),
    INDEX `idx_audit_module` (`module`),
    INDEX `idx_audit_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. Suppliers & Vendor Management
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `suppliers` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `supplier_code` VARCHAR(50) UNIQUE,
    `company_name` VARCHAR(255) NOT NULL,
    `supplier_type` VARCHAR(100) NOT NULL DEFAULT 'general',
    `contact_person` VARCHAR(255) NULL,
    `email` VARCHAR(255) NULL,
    `phone` VARCHAR(50) NULL,
    `address` TEXT NULL,
    `tax_id` VARCHAR(100) NULL,
    `bank_details` TEXT NULL,
    `payment_terms` VARCHAR(100) NULL,
    `status` VARCHAR(50) DEFAULT 'active',
    `rating` DECIMAL(3, 2) DEFAULT 0.00,
    `is_archived` TINYINT(1) DEFAULT 0,
    `created_by` BIGINT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_suppliers_code` (`supplier_code`),
    INDEX `idx_suppliers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_privacy_acknowledgements` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `supplier_id` BIGINT NOT NULL,
    `acknowledged_by` VARCHAR(255) NOT NULL,
    `acknowledgement_text` TEXT NOT NULL,
    `policy_version` VARCHAR(50) NOT NULL,
    `recorded_by` BIGINT NULL,
    `acknowledged_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_supp_ack_supp` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_performance` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `supplier_id` BIGINT NOT NULL,
    `period` VARCHAR(50) NOT NULL,
    `on_time_delivery` DECIMAL(5, 2) DEFAULT 0.00,
    `quality_rate` DECIMAL(5, 2) DEFAULT 0.00,
    `response_time` DECIMAL(5, 2) DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_supp_perf_supp` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. Inventory, Products & Catalog
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_groups` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `group_name` VARCHAR(100) UNIQUE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `sku` VARCHAR(100) UNIQUE NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `brand` VARCHAR(150) NULL,
    `category` VARCHAR(100) NULL,
    `item_type` VARCHAR(100) NOT NULL DEFAULT 'general',
    `unit_measure` VARCHAR(50) NULL,
    `unit_price` DECIMAL(15, 2) DEFAULT 0.00,
    `reorder_point` INT DEFAULT 0,
    `reorder_quantity` INT DEFAULT 0,
    `current_stock` INT DEFAULT 0,
    `min_stock` INT DEFAULT 0,
    `max_stock` INT DEFAULT 0,
    `barcode` VARCHAR(100) NULL,
    `serial_number_prefix` VARCHAR(50) NULL,
    `last_serial_number` INT DEFAULT 0,
    `status` VARCHAR(50) DEFAULT 'active',
    `is_archived` TINYINT(1) DEFAULT 0,
    `created_by` BIGINT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_products_sku` (`sku`),
    INDEX `idx_products_category` (`category`),
    INDEX `idx_products_barcode` (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. Smart Warehousing & Zone Management
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `warehouses` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `warehouse_code` VARCHAR(50) UNIQUE NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `location` TEXT NULL,
    `capacity` INT DEFAULT 0,
    `current_utilization` INT DEFAULT 0,
    `group_id` BIGINT NULL,
    `type` VARCHAR(50) DEFAULT 'standard',
    `status` VARCHAR(50) DEFAULT 'active',
    `is_archived` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `warehouse_inventory` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT NOT NULL,
    `warehouse_id` BIGINT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_product_warehouse` (`product_id`, `warehouse_id`),
    CONSTRAINT `fk_wh_inv_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wh_inv_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `warehouse_zones` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `warehouse_id` BIGINT NOT NULL,
    `zone_code` VARCHAR(50) NOT NULL,
    `zone_name` VARCHAR(100) NOT NULL,
    `zone_type` VARCHAR(50) DEFAULT 'standard',
    `capacity` INT DEFAULT 0,
    `is_hazmat` TINYINT(1) DEFAULT 0,
    `is_cold_chain` TINYINT(1) DEFAULT 0,
    `temperature` DECIMAL(5, 2) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_wh_zone_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_serial_numbers` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT NOT NULL,
    `serial_number` VARCHAR(100) UNIQUE NOT NULL,
    `lot_number` VARCHAR(100) NULL,
    `status` VARCHAR(50) DEFAULT 'available',
    `location` VARCHAR(255) NULL,
    `warehouse_id` BIGINT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_psn_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_transactions` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT NOT NULL,
    `transaction_type` VARCHAR(50) NOT NULL,
    `quantity` INT NOT NULL,
    `previous_balance` INT DEFAULT 0,
    `new_balance` INT DEFAULT 0,
    `reference_document` VARCHAR(100) NULL,
    `warehouse_id` BIGINT NULL,
    `notes` TEXT NULL,
    `created_by` BIGINT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_inv_trans_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_incidents` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `incident_number` VARCHAR(100) UNIQUE NOT NULL,
    `product_id` BIGINT NOT NULL,
    `warehouse_id` BIGINT NOT NULL,
    `quantity` INT NOT NULL,
    `issue_type` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'reported',
    `resolution` TEXT NULL,
    `created_by` BIGINT NULL,
    `resolved_by` BIGINT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `resolved_at` DATETIME NULL,
    CONSTRAINT `fk_incidents_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
    CONSTRAINT `fk_incidents_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. Procurement, Requisitions & Contracts
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_requisitions` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `pr_number` VARCHAR(100) UNIQUE NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `requested_by` BIGINT NULL,
    `department` VARCHAR(100) NULL,
    `required_date` DATE NULL,
    `payment_method` VARCHAR(50) NULL,
    `priority` VARCHAR(50) DEFAULT 'medium',
    `estimated_total` DECIMAL(15, 2) DEFAULT 0.00,
    `status` VARCHAR(50) DEFAULT 'draft',
    `review_notes` TEXT NULL,
    `is_archived` TINYINT(1) DEFAULT 0,
    `created_by` BIGINT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `requisition_items` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `requisition_id` BIGINT NOT NULL,
    `product_id` BIGINT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(15, 2) NULL,
    `notes` TEXT NULL,
    CONSTRAINT `fk_req_items_requisition` FOREIGN KEY (`requisition_id`) REFERENCES `purchase_requisitions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_req_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_orders` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `po_number` VARCHAR(100) UNIQUE NOT NULL,
    `supplier_id` BIGINT NULL,
    `warehouse_id` BIGINT NULL,
    `order_date` DATETIME NULL,
    `expected_delivery` DATETIME NULL,
    `shipping_address` TEXT NULL,
    `payment_method` VARCHAR(50) NULL,
    `terms` TEXT NULL,
    `notes` TEXT NULL,
    `status` VARCHAR(50) DEFAULT 'pending',
    `approval_status` VARCHAR(50) DEFAULT 'pending_review',
    `total_amount` DECIMAL(15, 2) DEFAULT 0.00,
    `created_by` BIGINT NULL,
    `is_archived` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_po_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_items` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `po_id` BIGINT NOT NULL,
    `product_id` BIGINT NOT NULL,
    `quantity` INT NOT NULL,
    `received_quantity` INT DEFAULT 0,
    `unit_price` DECIMAL(15, 2) NULL,
    `total_price` DECIMAL(15, 2) NULL,
    `expected_date` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_po_items_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_po_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_batches` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `receipt_number` VARCHAR(100) NOT NULL,
    `po_id` BIGINT NOT NULL,
    `po_item_id` BIGINT NOT NULL,
    `origin_batch_id` BIGINT NULL,
    `product_id` BIGINT NOT NULL,
    `supplier_id` BIGINT NULL,
    `warehouse_id` BIGINT NOT NULL,
    `batch_number` VARCHAR(100) NOT NULL,
    `received_quantity` INT NOT NULL,
    `available_quantity` INT NOT NULL DEFAULT 0,
    `expiry_date` DATE NULL,
    `brand_snapshot` VARCHAR(150) NULL,
    `quality_status` VARCHAR(50) NOT NULL DEFAULT 'rejected',
    `quality_notes` TEXT NULL,
    `received_by` BIGINT NULL,
    `received_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_batch_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
    CONSTRAINT `fk_batch_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_batch_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `procurement_contracts` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `contract_number` VARCHAR(100) UNIQUE NOT NULL,
    `supplier_id` BIGINT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `total_value` DECIMAL(15, 2) DEFAULT 0.00,
    `status` VARCHAR(50) DEFAULT 'draft',
    `approval_status` VARCHAR(50) DEFAULT 'pending_review',
    `document_path` TEXT NULL,
    `is_archived` TINYINT(1) DEFAULT 0,
    `created_by` BIGINT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_contract_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. Logistics, Shipments & Documents
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shipments` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `shipment_id` VARCHAR(100) UNIQUE NOT NULL,
    `po_id` BIGINT NULL,
    `carrier` VARCHAR(150) NULL,
    `tracking_number` VARCHAR(100) NULL,
    `mode` VARCHAR(50) DEFAULT 'road',
    `origin` VARCHAR(255) NULL,
    `destination` VARCHAR(255) NULL,
    `departure_date` DATETIME NULL,
    `expected_arrival` DATETIME NULL,
    `actual_arrival` DATETIME NULL,
    `status` VARCHAR(50) DEFAULT 'pending',
    `freight_cost` DECIMAL(15, 2) DEFAULT 0.00,
    `notes` TEXT NULL,
    `is_archived` TINYINT(1) DEFAULT 0,
    `created_by` BIGINT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_shipment_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `documents` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `document_number` VARCHAR(100) UNIQUE NOT NULL,
    `document_type` VARCHAR(100) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `file_path` TEXT NULL,
    `related_module` VARCHAR(50) NULL,
    `related_id` BIGINT NULL,
    `status` VARCHAR(50) DEFAULT 'draft',
    `is_archived` TINYINT(1) DEFAULT 0,
    `created_by` BIGINT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8. Bidding & Tender Management
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bidding_tenders` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `tender_code` VARCHAR(100) UNIQUE NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `tender_type` VARCHAR(50) NOT NULL DEFAULT 'spot_auction',
    `transport_mode` VARCHAR(50) NOT NULL DEFAULT 'road',
    `origin` VARCHAR(255) NOT NULL,
    `destination` VARCHAR(255) NOT NULL,
    `cargo_type` VARCHAR(100) DEFAULT 'standard_dry',
    `estimated_volume` VARCHAR(100) NULL,
    `target_rate` DECIMAL(15, 2) DEFAULT 0.00,
    `currency` VARCHAR(10) DEFAULT 'PHP',
    `deadline` DATETIME NULL,
    `service_level_req` TEXT NULL,
    `status` VARCHAR(50) DEFAULT 'open',
    `awarded_bid_id` BIGINT NULL,
    `awarded_carrier_id` BIGINT NULL,
    `awarded_carrier_name` VARCHAR(255) NULL,
    `awarded_rate` DECIMAL(15, 2) NULL,
    `tms_shipment_id` BIGINT NULL,
    `contract_id` BIGINT NULL,
    `rate_sheet_specs` TEXT NULL,
    `notes` TEXT NULL,
    `is_archived` TINYINT(1) DEFAULT 0,
    `created_by` BIGINT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_tender_carrier` FOREIGN KEY (`awarded_carrier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tender_shipment` FOREIGN KEY (`tms_shipment_id`) REFERENCES `shipments` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tender_contract` FOREIGN KEY (`contract_id`) REFERENCES `procurement_contracts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bidding_bids` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `tender_id` BIGINT NOT NULL,
    `carrier_id` BIGINT NULL,
    `carrier_name` VARCHAR(255) NOT NULL,
    `bid_amount` DECIMAL(15, 2) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'PHP',
    `transit_time_days` INT DEFAULT 1,
    `carrier_score` DECIMAL(5, 2) DEFAULT 85.00,
    `cost_score` DECIMAL(5, 2) DEFAULT 0.00,
    `composite_score` DECIMAL(5, 2) DEFAULT 0.00,
    `service_level` VARCHAR(100) NULL,
    `notes` TEXT NULL,
    `status` VARCHAR(50) DEFAULT 'submitted',
    `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_bid_tender` FOREIGN KEY (`tender_id`) REFERENCES `bidding_tenders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bid_carrier` FOREIGN KEY (`carrier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. Framework & Background Jobs
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` VARCHAR(255) PRIMARY KEY,
    `user_id` BIGINT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `payload` LONGTEXT NOT NULL,
    `last_activity` INT NOT NULL,
    INDEX `idx_sessions_user_id` (`user_id`),
    INDEX `idx_sessions_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cache` (
    `key` VARCHAR(255) PRIMARY KEY,
    `value` MEDIUMTEXT NOT NULL,
    `expiration` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cache_locks` (
    `key` VARCHAR(255) PRIMARY KEY,
    `owner` VARCHAR(255) NOT NULL,
    `expiration` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jobs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `queue` VARCHAR(255) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL,
    `reserved_at` INT UNSIGNED NULL,
    `available_at` INT UNSIGNED NOT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    INDEX `idx_jobs_queue` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_batches` (
    `id` VARCHAR(255) PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `total_jobs` INT NOT NULL,
    `pending_jobs` INT NOT NULL,
    `failed_jobs` INT NOT NULL,
    `failed_job_ids` LONGTEXT NOT NULL,
    `options` MEDIUMTEXT NULL,
    `cancelled_at` INT NULL,
    `created_at` INT NOT NULL,
    `finished_at` INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `uuid` VARCHAR(255) UNIQUE NOT NULL,
    `connection` TEXT NOT NULL,
    `queue` TEXT NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `exception` LONGTEXT NOT NULL,
    `failed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 10. Default Seed Data (Idempotent for MySQL)
-- ------------------------------------------------------------------------------

-- Default System Administrator (password: admin@08)
INSERT INTO `users` (
    `username`, 
    `email`, 
    `password`, 
    `role`, 
    `full_name`, 
    `is_active`, 
    `is_archived`, 
    `two_factor_enabled`
) VALUES (
    'admin',
    'admin@globalscm.com',
    '$2y$10$WcY/9xLuH2YbFNcIa0vY4uSVJNclz85LYJwPuAfiU/0pRAzS1x3LS',
    'admin',
    'System Administrator',
    1,
    0,
    0
) ON DUPLICATE KEY UPDATE 
    `password` = VALUES(`password`),
    `is_active` = 1,
    `is_archived` = 0,
    `two_factor_enabled` = 0;

-- Additional Core Logistics Team
INSERT INTO `users` (`username`, `email`, `password`, `role`, `full_name`, `is_active`, `is_archived`, `two_factor_enabled`)
VALUES 
    ('admin3', 'admin3@globalscm.com', '$2y$10$Fq5dIZyuCWj/DWfmLbrAAej7hDeXBxgoXn8n4vKQZjR7dMtwzUf.e', 'admin', 'Logistics Admin 3', 1, 0, 0),
    ('al', 'johnphaulbaytamo@gmail.com', '$2y$10$JhNiWzilRjUHkxvY92JixOXGO0QG..mu4RX.uABGq5mYvKwkWCg2.', 'admin', 'John Phaul Baytamo', 1, 0, 0),
    ('JayC', 'jayclhord8@gmail.com', '$2y$10$Fq5dIZyuCWj/DWfmLbrAAej7hDeXBxgoXn8n4vKQZjR7dMtwzUf.e', 'admin', 'JayC Staff', 1, 0, 0),
    ('Lenzy', 'lenzy@globalscm.com', '$2y$10$Fq5dIZyuCWj/DWfmLbrAAej7hDeXBxgoXn8n4vKQZjR7dMtwzUf.e', 'procurement_specialist', 'Lenzy Specialist', 1, 0, 0),
    ('Luis', 'luis@globalscm.com', '$2y$10$Fq5dIZyuCWj/DWfmLbrAAej7hDeXBxgoXn8n4vKQZjR7dMtwzUf.e', 'warehouse_manager', 'Luis Manager', 1, 0, 0),
    ('Ibarra', 'ibarra@globalscm.com', '$2y$10$Fq5dIZyuCWj/DWfmLbrAAej7hDeXBxgoXn8n4vKQZjR7dMtwzUf.e', 'inventory_controller', 'Ibarra Controller', 1, 0, 0),
    ('Maria', 'maria@globalscm.com', '$2y$10$Fq5dIZyuCWj/DWfmLbrAAej7hDeXBxgoXn8n4vKQZjR7dMtwzUf.e', 'logistics_coordinator', 'Maria Coordinator', 1, 0, 0),
    ('Nechol', 'nechol@globalscm.com', '$2y$10$Fq5dIZyuCWj/DWfmLbrAAej7hDeXBxgoXn8n4vKQZjR7dMtwzUf.e', 'logistics_coordinator', 'Nechol Coordinator', 1, 0, 0),
    ('Peter', 'peter@globalscm.com', '$2y$10$Fq5dIZyuCWj/DWfmLbrAAej7hDeXBxgoXn8n4vKQZjR7dMtwzUf.e', 'logistics_coordinator', 'Peter Coordinator', 1, 0, 0),
    ('podnum', 'podnum!@email.com', '$2y$10$geeAeExDI9nN3uE947Yygep3wUuI9mE4WqY73vV1kH7mN1p4ZqZeq', 'admin', 'Podnum Admin', 1, 0, 0),
    ('podnumadmin', 'podnum@email.com', '$2y$10$geeAeExDI9nN3uE947Yygep3wUuI9mE4WqY73vV1kH7mN1p4ZqZeq', 'admin', 'Podnum Admin', 1, 0, 0)
ON DUPLICATE KEY UPDATE 
    `is_active` = 1,
    `is_archived` = 0;

-- Default System Settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`, `description`)
VALUES 
    ('currency_symbol', '₱', 'general', 'Currency symbol used throughout the system'),
    ('currency_code', 'PHP', 'general', 'ISO currency code'),
    ('theme', 'light', 'ui', 'System theme (light/dark)'),
    ('barcode_format', 'CODE128', 'general', 'Default barcode format for products'),
    ('auto_serial_number', 'true', 'inventory', 'Automatically generate serial numbers'),
    ('report_ai_enabled', 'true', 'ai', 'Enable AI-powered daily reports'),
    ('show_warehousing', 'true', 'ui', 'Show/hide warehousing module'),
    ('show_inventory', 'true', 'ui', 'Show/hide inventory module'),
    ('show_procurement', 'true', 'ui', 'Show/hide procurement module'),
    ('show_suppliers', 'true', 'ui', 'Show/hide supplier module'),
    ('show_purchase_orders', 'true', 'ui', 'Show/hide purchase orders module'),
    ('show_logistics', 'true', 'ui', 'Show/hide logistics module')
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

SET FOREIGN_KEY_CHECKS = 1;
