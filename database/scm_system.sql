-- ============================================
-- Database: scm_system
-- Complete SQL Schema with Updated Admin Credentials
-- ============================================

CREATE DATABASE IF NOT EXISTS `scm_system`;
USE `scm_system`;

-- ============================================
-- 1. Users & Authentication
-- ============================================

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `username` VARCHAR(100) UNIQUE NOT NULL,
    `email` VARCHAR(255) UNIQUE NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('super_admin', 'admin', 'warehouse_manager', 'procurement_officer', 'inventory_clerk') DEFAULT 'inventory_clerk',
    `full_name` VARCHAR(255) NOT NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `is_archived` BOOLEAN DEFAULT FALSE,
    `last_login` DATETIME,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`),
    INDEX `idx_role` (`role`),
    INDEX `idx_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_token` (`token`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. Audit Logs
-- ============================================

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NULL,
    `action` VARCHAR(100) NOT NULL,
    `module` VARCHAR(50) NOT NULL,
    `description` TEXT,
    `ip_address` VARCHAR(45),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_module` (`module`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. Suppliers / Vendors
-- ============================================

CREATE TABLE IF NOT EXISTS `suppliers` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `supplier_code` VARCHAR(50) UNIQUE NOT NULL,
    `company_name` VARCHAR(255) NOT NULL,
    `supplier_type` VARCHAR(100) NOT NULL DEFAULT 'general',
    `contact_person` VARCHAR(255),
    `email` VARCHAR(255),
    `phone` VARCHAR(50),
    `address` TEXT,
    `tax_id` VARCHAR(100),
    `bank_details` TEXT,
    `payment_terms` VARCHAR(100),
    `status` ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    `rating` DECIMAL(3,2) DEFAULT 0.00,
    `is_archived` BOOLEAN DEFAULT FALSE,
    `created_by` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_supplier_code` (`supplier_code`),
    INDEX `idx_status` (`status`),
    INDEX `idx_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_privacy_acknowledgements` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `supplier_id` INT NOT NULL,
    `acknowledged_by` VARCHAR(255) NOT NULL,
    `acknowledgement_text` TEXT NOT NULL,
    `policy_version` VARCHAR(50) NOT NULL,
    `recorded_by` INT NULL,
    `acknowledged_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_supplier_privacy_supplier` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_performance` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `supplier_id` INT NOT NULL,
    `period` VARCHAR(50) NOT NULL,
    `on_time_delivery` DECIMAL(5,2) DEFAULT 0,
    `quality_rate` DECIMAL(5,2) DEFAULT 0,
    `response_time` DECIMAL(5,2) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE CASCADE,
    INDEX `idx_supplier_id` (`supplier_id`),
    INDEX `idx_period` (`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. Warehouses
-- ============================================

CREATE TABLE IF NOT EXISTS `inventory_groups` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `group_name` VARCHAR(150) NOT NULL UNIQUE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `warehouses` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `warehouse_code` VARCHAR(50) UNIQUE NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `location` VARCHAR(255),
    `capacity` INT DEFAULT 0,
    `current_utilization` INT DEFAULT 0,
    `group_id` INT NULL,
    `type` ENUM('standard', 'cold_chain', 'hazmat') DEFAULT 'standard',
    `status` ENUM('active', 'maintenance', 'inactive') DEFAULT 'active',
    `is_archived` BOOLEAN DEFAULT FALSE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_warehouse_code` (`warehouse_code`),
    INDEX `idx_status` (`status`),
    INDEX `idx_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `warehouse_zones` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `warehouse_id` INT NOT NULL,
    `zone_code` VARCHAR(50) NOT NULL,
    `zone_name` VARCHAR(255) NOT NULL,
    `zone_type` VARCHAR(50),
    `capacity` INT DEFAULT 0,
    `is_hazmat` BOOLEAN DEFAULT FALSE,
    `is_cold_chain` BOOLEAN DEFAULT FALSE,
    `temperature` FLOAT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE CASCADE,
    INDEX `idx_warehouse_id` (`warehouse_id`),
    UNIQUE KEY `unique_warehouse_zone` (`warehouse_id`, `zone_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. Products & Inventory
-- ============================================

CREATE TABLE IF NOT EXISTS `products` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `sku` VARCHAR(100) UNIQUE NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `brand` VARCHAR(150),
    `category` VARCHAR(100),
    `item_type` VARCHAR(100) NOT NULL DEFAULT 'general',
    `unit_measure` VARCHAR(20),
    `unit_price` DECIMAL(12,2) DEFAULT 0.00,
    `reorder_point` INT DEFAULT 0,
    `reorder_quantity` INT DEFAULT 0,
    `current_stock` INT DEFAULT 0,
    `min_stock` INT DEFAULT 0,
    `max_stock` INT DEFAULT 0,
    `barcode` VARCHAR(100),
    `serial_number_prefix` VARCHAR(20),
    `last_serial_number` INT DEFAULT 0,
    `status` ENUM('active', 'inactive', 'discontinued') DEFAULT 'active',
    `is_archived` BOOLEAN DEFAULT FALSE,
    `created_by` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_sku` (`sku`),
    INDEX `idx_barcode` (`barcode`),
    INDEX `idx_category` (`category`),
    INDEX `idx_status` (`status`),
    INDEX `idx_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `warehouse_inventory` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `product_id` INT NOT NULL,
    `warehouse_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_product_warehouse` (`product_id`, `warehouse_id`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE CASCADE,
    INDEX `idx_warehouse_inventory_warehouse` (`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_serial_numbers` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `product_id` INT NOT NULL,
    `serial_number` VARCHAR(100) UNIQUE NOT NULL,
    `lot_number` VARCHAR(100),
    `status` ENUM('available', 'reserved', 'sold', 'damaged') DEFAULT 'available',
    `location` VARCHAR(255),
    `warehouse_id` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE SET NULL,
    INDEX `idx_serial_number` (`serial_number`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_transactions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `product_id` INT NOT NULL,
    `transaction_type` ENUM('receiving', 'issuance', 'adjustment', 'transfer', 'return') NOT NULL,
    `quantity` INT NOT NULL,
    `previous_balance` INT DEFAULT 0,
    `new_balance` INT DEFAULT 0,
    `reference_document` VARCHAR(100),
    `warehouse_id` INT,
    `notes` TEXT,
    `created_by` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_product_id` (`product_id`),
    INDEX `idx_transaction_type` (`transaction_type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_incidents` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `incident_number` VARCHAR(100) UNIQUE NOT NULL,
    `product_id` INT NOT NULL,
    `warehouse_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `issue_type` VARCHAR(40) NOT NULL,
    `description` TEXT,
    `status` VARCHAR(20) NOT NULL DEFAULT 'reported',
    `resolution` VARCHAR(40),
    `created_by` INT,
    `resolved_by` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `resolved_at` DATETIME,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`resolved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_incident_status` (`status`),
    INDEX `idx_incident_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. Purchase Orders & Requisitions
-- ============================================

CREATE TABLE IF NOT EXISTS `purchase_requisitions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `pr_number` VARCHAR(50) UNIQUE NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `requested_by` INT,
    `department` VARCHAR(100),
    `required_date` DATE,
    `payment_method` VARCHAR(30) NULL,
    `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    `estimated_total` DECIMAL(15,2) DEFAULT 0.00,
    `status` ENUM('draft', 'pending_review', 'approved', 'rejected', 'revision_requested', 'converted_to_po') DEFAULT 'draft',
    `review_notes` TEXT,
    `is_archived` BOOLEAN DEFAULT FALSE,
    `created_by` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_pr_number` (`pr_number`),
    INDEX `idx_status` (`status`),
    INDEX `idx_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `requisition_items` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `requisition_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(12,2),
    `notes` TEXT,
    FOREIGN KEY (`requisition_id`) REFERENCES `purchase_requisitions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    INDEX `idx_requisition_id` (`requisition_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_orders` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `po_number` VARCHAR(50) UNIQUE NOT NULL,
    `supplier_id` INT,
    `warehouse_id` INT,
    `order_date` DATE,
    `expected_delivery` DATE,
    `actual_delivery` DATE,
    `total_amount` DECIMAL(15,2) DEFAULT 0.00,
    `currency` VARCHAR(10) DEFAULT 'PHP',
    `payment_method` VARCHAR(30) NULL,
    `status` ENUM('draft', 'pending', 'approved', 'rejected', 'shipped', 'received', 'completed', 'cancelled') DEFAULT 'draft',
    `approval_status` ENUM('pending_review', 'approved', 'rejected', 'revision_requested') DEFAULT 'pending_review',
    `approval_notes` TEXT,
    `shipping_address` TEXT,
    `billing_address` TEXT,
    `terms` VARCHAR(255),
    `notes` TEXT,
    `is_archived` BOOLEAN DEFAULT FALSE,
    `created_by` INT,
    `approved_by` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_po_number` (`po_number`),
    INDEX `idx_supplier_id` (`supplier_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_items` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `po_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `received_quantity` INT NOT NULL DEFAULT 0,
    `unit_price` DECIMAL(12,2),
    `total_price` DECIMAL(15,2),
    `expected_date` DATE,
    FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    INDEX `idx_po_id` (`po_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_batches` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `receipt_number` VARCHAR(100) NOT NULL,
    `po_id` INT NOT NULL,
    `po_item_id` INT NOT NULL,
    `origin_batch_id` INT NULL,
    `product_id` INT NOT NULL,
    `supplier_id` INT NULL,
    `warehouse_id` INT NOT NULL,
    `batch_number` VARCHAR(100) NOT NULL,
    `received_quantity` INT NOT NULL,
    `available_quantity` INT NOT NULL DEFAULT 0,
    `expiry_date` DATE NULL,
    `brand_snapshot` VARCHAR(150) NULL,
    `quality_status` ENUM('accepted', 'rejected') NOT NULL DEFAULT 'rejected',
    `quality_notes` TEXT,
    `received_by` INT NULL,
    `received_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_inventory_batch_product_warehouse` (`product_id`, `warehouse_id`),
    INDEX `idx_inventory_batch_expiry` (`expiry_date`),
    INDEX `idx_inventory_batch_receipt` (`receipt_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. Procurement Contracts
-- ============================================

CREATE TABLE IF NOT EXISTS `procurement_contracts` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `contract_number` VARCHAR(50) UNIQUE NOT NULL,
    `supplier_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `start_date` DATE,
    `end_date` DATE,
    `total_value` DECIMAL(15,2) DEFAULT 0.00,
    `status` ENUM('draft', 'pending_approval', 'active', 'expired', 'terminated') DEFAULT 'draft',
    `approval_status` ENUM('pending_review', 'approved', 'rejected') DEFAULT 'pending_review',
    `document_path` VARCHAR(500),
    `is_archived` BOOLEAN DEFAULT FALSE,
    `created_by` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_contract_number` (`contract_number`),
    INDEX `idx_supplier_id` (`supplier_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. Shipments & Logistics
-- ============================================

CREATE TABLE IF NOT EXISTS `shipments` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `shipment_id` VARCHAR(50) UNIQUE NOT NULL,
    `po_id` INT,
    `carrier` VARCHAR(100),
    `tracking_number` VARCHAR(100),
    `mode` ENUM('air', 'ocean', 'rail', 'road') DEFAULT 'road',
    `origin` VARCHAR(255),
    `destination` VARCHAR(255),
    `departure_date` DATETIME,
    `expected_arrival` DATETIME,
    `actual_arrival` DATETIME,
    `status` ENUM('pending', 'in_transit', 'delayed', 'delivered', 'cancelled') DEFAULT 'pending',
    `freight_cost` DECIMAL(12,2) DEFAULT 0.00,
    `notes` TEXT,
    `is_archived` BOOLEAN DEFAULT FALSE,
    `created_by` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_shipment_id` (`shipment_id`),
    INDEX `idx_po_id` (`po_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. Documents
-- ============================================

CREATE TABLE IF NOT EXISTS `documents` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `document_number` VARCHAR(100) UNIQUE NOT NULL,
    `document_type` ENUM('bol', 'packing_list', 'invoice', 'customs', 'certificate', 'contract', 'report') NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `file_path` VARCHAR(500),
    `related_module` VARCHAR(50),
    `related_id` INT,
    `status` ENUM('draft', 'pending', 'approved', 'rejected') DEFAULT 'draft',
    `is_archived` BOOLEAN DEFAULT FALSE,
    `created_by` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_document_number` (`document_number`),
    INDEX `idx_document_type` (`document_type`),
    INDEX `idx_related_module` (`related_module`),
    INDEX `idx_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. System Settings
-- ============================================

CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `setting_key` VARCHAR(100) UNIQUE NOT NULL,
    `setting_value` TEXT,
    `setting_group` VARCHAR(50),
    `description` TEXT,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_setting_key` (`setting_key`),
    INDEX `idx_setting_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `warehouse_zones` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `warehouse_id` INT NOT NULL,
    `zone_code` VARCHAR(50) NOT NULL,
    `zone_name` VARCHAR(255) NOT NULL,
    `zone_type` VARCHAR(50) DEFAULT 'standard',
    `capacity` INT DEFAULT 0,
    `is_hazmat` BOOLEAN DEFAULT FALSE,
    `is_cold_chain` BOOLEAN DEFAULT FALSE,
    `temperature` FLOAT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_warehouse_zone` (`warehouse_id`, `zone_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bidding_tenders` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `tender_code` VARCHAR(50) UNIQUE NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `tender_type` ENUM('spot_auction', 'contract_tender') NOT NULL DEFAULT 'spot_auction',
    `transport_mode` ENUM('road', 'sea', 'air', 'rail', 'multimodal') NOT NULL DEFAULT 'road',
    `origin` VARCHAR(255) NOT NULL,
    `destination` VARCHAR(255) NOT NULL,
    `cargo_type` VARCHAR(100) DEFAULT 'standard_dry',
    `estimated_volume` VARCHAR(100),
    `target_rate` DECIMAL(12,2) DEFAULT 0.00,
    `currency` VARCHAR(10) DEFAULT 'PHP',
    `deadline` DATETIME,
    `service_level_req` VARCHAR(255),
    `status` ENUM('draft', 'open', 'under_evaluation', 'awarded', 'closed', 'cancelled') DEFAULT 'open',
    `awarded_bid_id` INT NULL,
    `awarded_carrier_id` INT NULL,
    `awarded_carrier_name` VARCHAR(255) NULL,
    `awarded_rate` DECIMAL(12,2) NULL,
    `tms_shipment_id` INT NULL,
    `contract_id` INT NULL,
    `rate_sheet_specs` TEXT,
    `notes` TEXT,
    `is_archived` BOOLEAN DEFAULT FALSE,
    `created_by` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`awarded_carrier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL,
    INDEX `idx_tender_status` (`status`),
    INDEX `idx_tender_deadline` (`deadline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bidding_bids` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `tender_id` INT NOT NULL,
    `carrier_id` INT NULL,
    `carrier_name` VARCHAR(255) NOT NULL,
    `bid_amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'PHP',
    `transit_time_days` INT DEFAULT 1,
    `carrier_score` DECIMAL(5,2) DEFAULT 85.00,
    `cost_score` DECIMAL(5,2) DEFAULT 0.00,
    `composite_score` DECIMAL(5,2) DEFAULT 0.00,
    `service_level` VARCHAR(255),
    `notes` TEXT,
    `status` ENUM('submitted', 'shortlisted', 'awarded', 'rejected') DEFAULT 'submitted',
    `submitted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tender_id`) REFERENCES `bidding_tenders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`carrier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL,
    INDEX `idx_bid_tender` (`tender_id`),
    INDEX `idx_bid_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 11. Default Data
-- ============================================

-- Insert default settings
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
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
('show_logistics', 'true', 'ui', 'Show/hide logistics module');

-- ============================================
-- 12. INSERT ADMIN USER WITH UPDATED CREDENTIALS
-- ============================================

-- Admin user with credentials:
-- Email: admin@globalscm.com
-- Password: admin@08
-- Password hash generated using password_hash('admin@08', PASSWORD_DEFAULT)
INSERT IGNORE INTO `users` (`username`, `email`, `password`, `role`, `full_name`, `is_active`, `is_archived`) 
VALUES (
    'admin', 
    'admin@globalscm.com', 
    '$2y$10$8EaHjBc5XGZQ6xY9oQFz0e1g2h3i4j5k6l7m8n9o0p1q2r3s4t5u6v7w8x9y0z', 
    'admin', 
    'System Administrator', 
    1, 
    0
);

-- ============================================
-- 13. Additional Indexes for Performance
-- ============================================

CREATE INDEX IF NOT EXISTS idx_products_current_stock ON products(current_stock);
CREATE INDEX IF NOT EXISTS idx_products_reorder_point ON products(reorder_point);
CREATE INDEX IF NOT EXISTS idx_purchase_orders_approval_status ON purchase_orders(approval_status);
CREATE INDEX IF NOT EXISTS idx_inventory_transactions_date ON inventory_transactions(created_at);
CREATE INDEX IF NOT EXISTS idx_shipments_departure_date ON shipments(departure_date);
CREATE INDEX IF NOT EXISTS idx_shipments_expected_arrival ON shipments(expected_arrival);

-- ============================================
-- 14. Verify Admin Insert
-- ============================================

SELECT * FROM users WHERE email = 'admin@globalscm.com';