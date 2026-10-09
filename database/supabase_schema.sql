-- ==============================================================================
-- 2GO Travel / GlobalSCM — Supabase PostgreSQL Production Schema
-- Designed for PostgreSQL 14+ / Supabase
-- Fully compatible with all admin modules and Laravel framework components
-- ==============================================================================

-- Enable UUID extension if needed
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ==============================================================================
-- 1. Authentication & Users
-- ==============================================================================

CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'inventory_clerk',
    full_name VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    is_archived BOOLEAN DEFAULT FALSE,
    two_factor_secret TEXT NULL,
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    two_factor_confirmed_at TIMESTAMPTZ NULL,
    two_factor_recovery_codes_generated_at TIMESTAMPTZ NULL,
    two_factor_time_offset INTEGER DEFAULT 0,
    last_login TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_archived ON users(is_archived);

CREATE TABLE IF NOT EXISTS user_recovery_codes (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash VARCHAR(255) NOT NULL,
    used_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_user_recovery_user ON user_recovery_codes(user_id);

CREATE TABLE IF NOT EXISTS two_factor_rate_limits (
    id BIGSERIAL PRIMARY KEY,
    identifier VARCHAR(150) NOT NULL,
    attempt_time BIGINT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_2fa_rate_limit ON two_factor_rate_limits(identifier, attempt_time);

CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_user_sessions_token ON user_sessions(token);
CREATE INDEX IF NOT EXISTS idx_user_sessions_user_id ON user_sessions(user_id);

-- ==============================================================================
-- 2. System Settings & Audit Logs
-- ==============================================================================

CREATE TABLE IF NOT EXISTS system_settings (
    id BIGSERIAL PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_group VARCHAR(50),
    description TEXT,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_user_id ON audit_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_audit_module ON audit_logs(module);
CREATE INDEX IF NOT EXISTS idx_audit_created_at ON audit_logs(created_at);

-- ==============================================================================
-- 3. Suppliers & Vendor Management
-- ==============================================================================

CREATE TABLE IF NOT EXISTS suppliers (
    id BIGSERIAL PRIMARY KEY,
    supplier_code VARCHAR(50) UNIQUE,
    company_name VARCHAR(255) NOT NULL,
    supplier_type VARCHAR(100) NOT NULL DEFAULT 'general',
    contact_person VARCHAR(255),
    email VARCHAR(255),
    phone VARCHAR(50),
    address TEXT,
    tax_id VARCHAR(100),
    bank_details TEXT,
    payment_terms VARCHAR(100),
    status VARCHAR(50) DEFAULT 'active',
    rating NUMERIC(3, 2) DEFAULT 0.00,
    is_archived BOOLEAN DEFAULT FALSE,
    created_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_suppliers_code ON suppliers(supplier_code);
CREATE INDEX IF NOT EXISTS idx_suppliers_status ON suppliers(status);

CREATE TABLE IF NOT EXISTS supplier_privacy_acknowledgements (
    id BIGSERIAL PRIMARY KEY,
    supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    acknowledged_by VARCHAR(255) NOT NULL,
    acknowledgement_text TEXT NOT NULL,
    policy_version VARCHAR(50) NOT NULL,
    recorded_by BIGINT,
    acknowledged_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS supplier_performance (
    id BIGSERIAL PRIMARY KEY,
    supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    period VARCHAR(50) NOT NULL,
    on_time_delivery NUMERIC(5, 2) DEFAULT 0.00,
    quality_rate NUMERIC(5, 2) DEFAULT 0.00,
    response_time NUMERIC(5, 2) DEFAULT 0.00,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ==============================================================================
-- 4. Inventory, Products & Catalog
-- ==============================================================================

CREATE TABLE IF NOT EXISTS inventory_groups (
    id BIGSERIAL PRIMARY KEY,
    group_name VARCHAR(100) UNIQUE NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id BIGSERIAL PRIMARY KEY,
    sku VARCHAR(100) UNIQUE,
    product_name VARCHAR(255) NOT NULL,
    description TEXT,
    brand VARCHAR(150),
    category VARCHAR(100),
    item_type VARCHAR(100) NOT NULL DEFAULT 'general',
    unit_measure VARCHAR(50),
    unit_price NUMERIC(15, 2) DEFAULT 0.00,
    reorder_point INTEGER DEFAULT 0,
    reorder_quantity INTEGER DEFAULT 0,
    current_stock INTEGER DEFAULT 0,
    min_stock INTEGER DEFAULT 0,
    max_stock INTEGER DEFAULT 0,
    barcode VARCHAR(100),
    serial_number_prefix VARCHAR(50),
    last_serial_number INTEGER DEFAULT 0,
    status VARCHAR(50) DEFAULT 'active',
    is_archived BOOLEAN DEFAULT FALSE,
    created_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_products_sku ON products(sku);
CREATE INDEX IF NOT EXISTS idx_products_category ON products(category);
CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode);

-- ==============================================================================
-- 5. Smart Warehousing & Zone Management
-- ==============================================================================

CREATE TABLE IF NOT EXISTS warehouses (
    id BIGSERIAL PRIMARY KEY,
    warehouse_code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    location TEXT,
    capacity INTEGER DEFAULT 0,
    current_utilization INTEGER DEFAULT 0,
    group_id BIGINT,
    type VARCHAR(50) DEFAULT 'standard',
    status VARCHAR(50) DEFAULT 'active',
    is_archived BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS warehouse_inventory (
    id BIGSERIAL PRIMARY KEY,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    warehouse_id BIGINT NOT NULL REFERENCES warehouses(id) ON DELETE CASCADE,
    quantity INTEGER NOT NULL DEFAULT 0,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_product_warehouse UNIQUE (product_id, warehouse_id)
);

CREATE TABLE IF NOT EXISTS warehouse_zones (
    id BIGSERIAL PRIMARY KEY,
    warehouse_id BIGINT NOT NULL REFERENCES warehouses(id) ON DELETE CASCADE,
    zone_code VARCHAR(50) NOT NULL,
    zone_name VARCHAR(100) NOT NULL,
    zone_type VARCHAR(50) DEFAULT 'standard',
    capacity INTEGER DEFAULT 0,
    is_hazmat BOOLEAN DEFAULT FALSE,
    is_cold_chain BOOLEAN DEFAULT FALSE,
    temperature NUMERIC(5, 2),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS product_serial_numbers (
    id BIGSERIAL PRIMARY KEY,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    serial_number VARCHAR(100) UNIQUE NOT NULL,
    lot_number VARCHAR(100),
    status VARCHAR(50) DEFAULT 'available',
    location VARCHAR(255),
    warehouse_id BIGINT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_transactions (
    id BIGSERIAL PRIMARY KEY,
    product_id BIGINT NOT NULL REFERENCES products(id),
    transaction_type VARCHAR(50) NOT NULL,
    quantity INTEGER NOT NULL,
    previous_balance INTEGER DEFAULT 0,
    new_balance INTEGER DEFAULT 0,
    reference_document VARCHAR(100),
    warehouse_id BIGINT,
    notes TEXT,
    created_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_incidents (
    id BIGSERIAL PRIMARY KEY,
    incident_number VARCHAR(100) UNIQUE NOT NULL,
    product_id BIGINT NOT NULL REFERENCES products(id),
    warehouse_id BIGINT NOT NULL REFERENCES warehouses(id),
    quantity INTEGER NOT NULL,
    issue_type VARCHAR(100) NOT NULL,
    description TEXT,
    status VARCHAR(50) NOT NULL DEFAULT 'reported',
    resolution TEXT,
    created_by BIGINT,
    resolved_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMPTZ
);

-- ==============================================================================
-- 6. Procurement, Requisitions & Contracts
-- ==============================================================================

CREATE TABLE IF NOT EXISTS purchase_requisitions (
    id BIGSERIAL PRIMARY KEY,
    pr_number VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    requested_by BIGINT,
    department VARCHAR(100),
    required_date DATE,
    payment_method VARCHAR(50),
    priority VARCHAR(50) DEFAULT 'medium',
    estimated_total NUMERIC(15, 2) DEFAULT 0.00,
    status VARCHAR(50) DEFAULT 'draft',
    review_notes TEXT,
    is_archived BOOLEAN DEFAULT FALSE,
    created_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS requisition_items (
    id BIGSERIAL PRIMARY KEY,
    requisition_id BIGINT NOT NULL REFERENCES purchase_requisitions(id) ON DELETE CASCADE,
    product_id BIGINT NOT NULL REFERENCES products(id),
    quantity INTEGER NOT NULL,
    unit_price NUMERIC(15, 2),
    notes TEXT
);

CREATE TABLE IF NOT EXISTS purchase_orders (
    id BIGSERIAL PRIMARY KEY,
    po_number VARCHAR(100) UNIQUE NOT NULL,
    supplier_id BIGINT REFERENCES suppliers(id),
    warehouse_id BIGINT REFERENCES warehouses(id),
    order_date TIMESTAMPTZ,
    expected_delivery TIMESTAMPTZ,
    shipping_address TEXT,
    payment_method VARCHAR(50),
    terms TEXT,
    notes TEXT,
    status VARCHAR(50) DEFAULT 'pending',
    approval_status VARCHAR(50) DEFAULT 'pending_review',
    total_amount NUMERIC(15, 2) DEFAULT 0.00,
    created_by BIGINT,
    is_archived BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id BIGSERIAL PRIMARY KEY,
    po_id BIGINT NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    product_id BIGINT NOT NULL REFERENCES products(id),
    quantity INTEGER NOT NULL,
    received_quantity INTEGER DEFAULT 0,
    unit_price NUMERIC(15, 2),
    total_price NUMERIC(15, 2),
    expected_date DATE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_batches (
    id BIGSERIAL PRIMARY KEY,
    receipt_number VARCHAR(100) NOT NULL,
    po_id BIGINT NOT NULL,
    po_item_id BIGINT NOT NULL,
    origin_batch_id BIGINT,
    product_id BIGINT NOT NULL REFERENCES products(id),
    supplier_id BIGINT REFERENCES suppliers(id),
    warehouse_id BIGINT NOT NULL REFERENCES warehouses(id),
    batch_number VARCHAR(100) NOT NULL,
    received_quantity INTEGER NOT NULL,
    available_quantity INTEGER NOT NULL DEFAULT 0,
    expiry_date DATE,
    brand_snapshot VARCHAR(150),
    quality_status VARCHAR(50) NOT NULL DEFAULT 'rejected',
    quality_notes TEXT,
    received_by BIGINT,
    received_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS procurement_contracts (
    id BIGSERIAL PRIMARY KEY,
    contract_number VARCHAR(100) UNIQUE NOT NULL,
    supplier_id BIGINT NOT NULL REFERENCES suppliers(id),
    title VARCHAR(255) NOT NULL,
    description TEXT,
    start_date DATE,
    end_date DATE,
    total_value NUMERIC(15, 2) DEFAULT 0.00,
    status VARCHAR(50) DEFAULT 'draft',
    approval_status VARCHAR(50) DEFAULT 'pending_review',
    document_path TEXT,
    is_archived BOOLEAN DEFAULT FALSE,
    created_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ==============================================================================
-- 7. Logistics, Shipments & Documents
-- ==============================================================================

CREATE TABLE IF NOT EXISTS shipments (
    id BIGSERIAL PRIMARY KEY,
    shipment_id VARCHAR(100) UNIQUE NOT NULL,
    po_id BIGINT REFERENCES purchase_orders(id),
    carrier VARCHAR(150),
    tracking_number VARCHAR(100),
    mode VARCHAR(50) DEFAULT 'road',
    origin VARCHAR(255),
    destination VARCHAR(255),
    departure_date TIMESTAMPTZ,
    expected_arrival TIMESTAMPTZ,
    actual_arrival TIMESTAMPTZ,
    status VARCHAR(50) DEFAULT 'pending',
    freight_cost NUMERIC(15, 2) DEFAULT 0.00,
    notes TEXT,
    is_archived BOOLEAN DEFAULT FALSE,
    created_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS documents (
    id BIGSERIAL PRIMARY KEY,
    document_number VARCHAR(100) UNIQUE NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_path TEXT,
    related_module VARCHAR(50),
    related_id BIGINT,
    status VARCHAR(50) DEFAULT 'draft',
    is_archived BOOLEAN DEFAULT FALSE,
    created_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ==============================================================================
-- 8. Bidding & Tender Management
-- ==============================================================================

CREATE TABLE IF NOT EXISTS bidding_tenders (
    id BIGSERIAL PRIMARY KEY,
    tender_code VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    tender_type VARCHAR(50) NOT NULL DEFAULT 'spot_auction',
    transport_mode VARCHAR(50) NOT NULL DEFAULT 'road',
    origin VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    cargo_type VARCHAR(100) DEFAULT 'standard_dry',
    estimated_volume VARCHAR(100),
    target_rate NUMERIC(15, 2) DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'PHP',
    deadline TIMESTAMPTZ,
    service_level_req TEXT,
    status VARCHAR(50) DEFAULT 'open',
    awarded_bid_id BIGINT,
    awarded_carrier_id BIGINT REFERENCES suppliers(id),
    awarded_carrier_name VARCHAR(255),
    awarded_rate NUMERIC(15, 2),
    tms_shipment_id BIGINT REFERENCES shipments(id),
    contract_id BIGINT REFERENCES procurement_contracts(id),
    rate_sheet_specs TEXT,
    notes TEXT,
    is_archived BOOLEAN DEFAULT FALSE,
    created_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bidding_bids (
    id BIGSERIAL PRIMARY KEY,
    tender_id BIGINT NOT NULL REFERENCES bidding_tenders(id) ON DELETE CASCADE,
    carrier_id BIGINT REFERENCES suppliers(id),
    carrier_name VARCHAR(255) NOT NULL,
    bid_amount NUMERIC(15, 2) NOT NULL,
    currency VARCHAR(10) DEFAULT 'PHP',
    transit_time_days INTEGER DEFAULT 1,
    carrier_score NUMERIC(5, 2) DEFAULT 85.00,
    cost_score NUMERIC(5, 2) DEFAULT 0.00,
    composite_score NUMERIC(5, 2) DEFAULT 0.00,
    service_level VARCHAR(100),
    notes TEXT,
    status VARCHAR(50) DEFAULT 'submitted',
    submitted_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ==============================================================================
-- 9. Laravel System Tables (Session, Cache, Queue)
-- ==============================================================================

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(255) PRIMARY KEY,
    user_id BIGINT NULL REFERENCES users(id) ON DELETE CASCADE,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload TEXT NOT NULL,
    last_activity INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_last_activity ON sessions(last_activity);

CREATE TABLE IF NOT EXISTS cache (
    key VARCHAR(255) PRIMARY KEY,
    value TEXT NOT NULL,
    expiration INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS cache_locks (
    key VARCHAR(255) PRIMARY KEY,
    owner VARCHAR(255) NOT NULL,
    expiration INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS jobs (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts SMALLINT NOT NULL,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_jobs_queue ON jobs(queue);

CREATE TABLE IF NOT EXISTS job_batches (
    id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    total_jobs INTEGER NOT NULL,
    pending_jobs INTEGER NOT NULL,
    failed_jobs INTEGER NOT NULL,
    failed_job_ids TEXT NOT NULL,
    options TEXT NULL,
    cancelled_at INTEGER NULL,
    created_at INTEGER NOT NULL,
    finished_at INTEGER NULL
);

CREATE TABLE IF NOT EXISTS failed_jobs (
    id BIGSERIAL PRIMARY KEY,
    uuid VARCHAR(255) UNIQUE NOT NULL,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ==============================================================================
-- 9b. Logistics Freight Bidding & Tenders Module
-- ==============================================================================

CREATE TABLE IF NOT EXISTS bidding_tenders (
    id BIGSERIAL PRIMARY KEY,
    tender_code VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    tender_type VARCHAR(50) NOT NULL DEFAULT 'spot_auction',
    transport_mode VARCHAR(50) NOT NULL DEFAULT 'road',
    origin VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    cargo_type VARCHAR(100) DEFAULT 'standard_dry',
    estimated_volume VARCHAR(100),
    target_rate NUMERIC(15, 2) DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'PHP',
    deadline TIMESTAMPTZ,
    service_level_req TEXT,
    status VARCHAR(50) DEFAULT 'open',
    awarded_bid_id BIGINT,
    awarded_carrier_id BIGINT,
    awarded_carrier_name VARCHAR(255),
    awarded_rate NUMERIC(15, 2),
    tms_shipment_id BIGINT,
    contract_id BIGINT,
    rate_sheet_specs TEXT,
    notes TEXT,
    is_archived BOOLEAN DEFAULT FALSE,
    created_by BIGINT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bidding_bids (
    id BIGSERIAL PRIMARY KEY,
    tender_id BIGINT NOT NULL REFERENCES bidding_tenders(id) ON DELETE CASCADE,
    carrier_id BIGINT,
    carrier_name VARCHAR(255) NOT NULL,
    bid_amount NUMERIC(15, 2) NOT NULL,
    currency VARCHAR(10) DEFAULT 'PHP',
    transit_time_days INTEGER DEFAULT 1,
    carrier_score NUMERIC(5, 2) DEFAULT 85.0,
    cost_score NUMERIC(5, 2) DEFAULT 0.0,
    composite_score NUMERIC(5, 2) DEFAULT 0.0,
    service_level TEXT,
    notes TEXT,
    status VARCHAR(50) DEFAULT 'submitted',
    submitted_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_bidding_tenders_code ON bidding_tenders(tender_code);
CREATE INDEX IF NOT EXISTS idx_bidding_tenders_status ON bidding_tenders(status);
CREATE INDEX IF NOT EXISTS idx_bidding_bids_tender ON bidding_bids(tender_id);

-- ==============================================================================
-- 10. Default Seed Data
-- ==============================================================================

-- Default Administrator (password: admin@08 or admin123)
INSERT INTO users (username, email, password, role, full_name, is_active, is_archived)
VALUES (
    'admin',
    'admin@globalscm.com',
    '$2y$10$3c07H9kH7p6V8iA6U5AbeOS808nN2b3.c45a6B89cD0e1F2g3H4i5',
    'admin',
    'System Administrator',
    TRUE,
    FALSE
)
ON CONFLICT (username) DO NOTHING;

-- Default System Settings
INSERT INTO system_settings (setting_key, setting_value, setting_group, description)
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
ON CONFLICT (setting_key) DO NOTHING;

-- ==============================================================================
-- 11. Cross-Database Compatibility Operators (Boolean <-> Integer)
-- ==============================================================================
CREATE OR REPLACE FUNCTION public.bool_eq_int(b boolean, i integer) RETURNS boolean AS $$
    SELECT b = (i <> 0);
$$ LANGUAGE SQL IMMUTABLE;

CREATE OR REPLACE FUNCTION public.int_eq_bool(i integer, b boolean) RETURNS boolean AS $$
    SELECT (i <> 0) = b;
$$ LANGUAGE SQL IMMUTABLE;

CREATE OR REPLACE FUNCTION public.bool_neq_int(b boolean, i integer) RETURNS boolean AS $$
    SELECT b <> (i <> 0);
$$ LANGUAGE SQL IMMUTABLE;

CREATE OR REPLACE FUNCTION public.int_neq_bool(i integer, b boolean) RETURNS boolean AS $$
    SELECT (i <> 0) <> b;
$$ LANGUAGE SQL IMMUTABLE;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_operator o
        JOIN pg_namespace n ON o.oprnamespace = n.oid
        WHERE oprname = '=' AND oprleft = 'boolean'::regtype AND oprright = 'integer'::regtype
    ) THEN
        CREATE OPERATOR public.= (
            LEFTARG = boolean,
            RIGHTARG = integer,
            PROCEDURE = public.bool_eq_int,
            COMMUTATOR = =
        );
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_operator o
        JOIN pg_namespace n ON o.oprnamespace = n.oid
        WHERE oprname = '=' AND oprleft = 'integer'::regtype AND oprright = 'boolean'::regtype
    ) THEN
        CREATE OPERATOR public.= (
            LEFTARG = integer,
            RIGHTARG = boolean,
            PROCEDURE = public.int_eq_bool,
            COMMUTATOR = =
        );
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_operator o
        JOIN pg_namespace n ON o.oprnamespace = n.oid
        WHERE oprname = '<>' AND oprleft = 'boolean'::regtype AND oprright = 'integer'::regtype
    ) THEN
        CREATE OPERATOR public.<> (
            LEFTARG = boolean,
            RIGHTARG = integer,
            PROCEDURE = public.bool_neq_int,
            COMMUTATOR = <>
        );
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_operator o
        JOIN pg_namespace n ON o.oprnamespace = n.oid
        WHERE oprname = '<>' AND oprleft = 'integer'::regtype AND oprright = 'boolean'::regtype
    ) THEN
        CREATE OPERATOR public.<> (
            LEFTARG = integer,
            RIGHTARG = boolean,
            PROCEDURE = public.int_neq_bool,
            COMMUTATOR = <>
        );
    END IF;
END $$;

