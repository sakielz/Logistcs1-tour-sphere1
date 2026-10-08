<?php

if (!function_exists('env')) {
    function env($key, $default = null) {
        static $env = null;

        if ($env === null) {
            $env = [];
            $envFile = dirname(__DIR__) . '/.env';
            if (is_file($envFile)) {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                        continue;
                    }

                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        $env[trim($parts[0])] = trim($parts[1]);
                    }
                }
            }
        }

        $value = getenv($key);
        if ($value !== false && $value !== null) {
            return $value;
        }

        if (array_key_exists($key, $env)) {
            return $env[$key];
        }

        return $default;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$rootDir = dirname(__DIR__);

$env = [];
$envFile = $rootDir . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $env[trim($parts[0])] = trim($parts[1]);
        }
    }
}

$driver = getenv('DB_CONNECTION') ?: ($env['DB_CONNECTION'] ?? 'sqlite');
$host = getenv('DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = getenv('DB_PORT') ?: ($env['DB_PORT'] ?? '3306');
$dbName = getenv('DB_DATABASE') ?: ($env['DB_DATABASE'] ?? $rootDir . '/database/database.sqlite');
$username = getenv('DB_USERNAME') ?: ($env['DB_USERNAME'] ?? 'root');
$password = getenv('DB_PASSWORD') ?: ($env['DB_PASSWORD'] ?? '');

// Support Supabase / cloud database connection URLs (DB_URL / DATABASE_URL)
$dbUrl = getenv('DB_URL') ?: (getenv('DATABASE_URL') ?: ($env['DB_URL'] ?? ($env['DATABASE_URL'] ?? null)));
if (!empty($dbUrl)) {
    $parsedUrl = parse_url($dbUrl);
    if ($parsedUrl) {
        $urlScheme = strtolower($parsedUrl['scheme'] ?? '');
        if (in_array($urlScheme, ['postgres', 'postgresql', 'pgsql'])) {
            $driver = 'pgsql';
            if (empty($port) || $port === '3306') $port = '5432';
        } elseif ($urlScheme === 'mysql') {
            $driver = 'mysql';
        }
        if (!empty($parsedUrl['host'])) $host = $parsedUrl['host'];
        if (!empty($parsedUrl['port'])) $port = (string)$parsedUrl['port'];
        if (!empty($parsedUrl['user'])) $username = urldecode($parsedUrl['user']);
        if (!empty($parsedUrl['pass'])) $password = urldecode($parsedUrl['pass']);
        if (!empty($parsedUrl['path'])) $dbName = ltrim($parsedUrl['path'], '/');
    }
}

if (!function_exists('initializeSqliteDatabase')) {
    function initializeSqliteDatabase($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT DEFAULT 'employer',
            full_name TEXT NOT NULL,
            is_active INTEGER DEFAULT 1,
            is_archived INTEGER DEFAULT 0,
            two_factor_secret TEXT,
            two_factor_enabled INTEGER DEFAULT 0,
            two_factor_confirmed_at TEXT,
            two_factor_recovery_codes_generated_at TEXT,
            two_factor_time_offset INTEGER DEFAULT 0,
            last_login TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_recovery_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            code_hash TEXT NOT NULL,
            used_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS two_factor_rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,
            attempt_time INTEGER NOT NULL
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key TEXT UNIQUE NOT NULL,
            setting_value TEXT,
            setting_group TEXT,
            description TEXT,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            module TEXT NOT NULL,
            description TEXT,
            ip_address TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_code TEXT UNIQUE,
            company_name TEXT,
            supplier_type TEXT NOT NULL DEFAULT 'general',
            contact_person TEXT,
            email TEXT,
            phone TEXT,
            address TEXT,
            tax_id TEXT,
            bank_details TEXT,
            payment_terms TEXT,
            status TEXT DEFAULT 'active',
            rating REAL DEFAULT 0,
            is_archived INTEGER DEFAULT 0,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_privacy_acknowledgements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INTEGER NOT NULL,
            acknowledged_by TEXT NOT NULL,
            acknowledgement_text TEXT NOT NULL,
            policy_version TEXT NOT NULL,
            recorded_by INTEGER,
            acknowledged_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_name TEXT NOT NULL UNIQUE,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT UNIQUE,
            product_name TEXT,
            description TEXT,
            brand TEXT,
            category TEXT,
            item_type TEXT NOT NULL DEFAULT 'general',
            unit_measure TEXT,
            unit_price REAL DEFAULT 0,
            reorder_point INTEGER DEFAULT 0,
            reorder_quantity INTEGER DEFAULT 0,
            current_stock INTEGER DEFAULT 0,
            min_stock INTEGER DEFAULT 0,
            max_stock INTEGER DEFAULT 0,
            barcode TEXT,
            serial_number_prefix TEXT,
            last_serial_number INTEGER DEFAULT 0,
            status TEXT DEFAULT 'active',
            is_archived INTEGER DEFAULT 0,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            po_number TEXT,
            supplier_id INTEGER,
            warehouse_id INTEGER,
            order_date TEXT,
            expected_delivery TEXT,
            shipping_address TEXT,
            payment_method TEXT,
            terms TEXT,
            notes TEXT,
            status TEXT DEFAULT 'pending',
            approval_status TEXT DEFAULT 'pending_review',
            total_amount REAL DEFAULT 0,
            created_by INTEGER,
            is_archived INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            po_id INTEGER,
            product_id INTEGER,
            quantity INTEGER,
            received_quantity INTEGER DEFAULT 0,
            unit_price REAL,
            total_price REAL,
            expected_date TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            receipt_number TEXT NOT NULL,
            po_id INTEGER NOT NULL,
            po_item_id INTEGER NOT NULL,
            origin_batch_id INTEGER,
            product_id INTEGER NOT NULL,
            supplier_id INTEGER,
            warehouse_id INTEGER NOT NULL,
            batch_number TEXT NOT NULL,
            received_quantity INTEGER NOT NULL,
            available_quantity INTEGER NOT NULL DEFAULT 0,
            expiry_date TEXT,
            brand_snapshot TEXT,
            quality_status TEXT NOT NULL DEFAULT 'rejected',
            quality_notes TEXT,
            received_by INTEGER,
            received_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            warehouse_code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            location TEXT,
            capacity INTEGER DEFAULT 0,
            current_utilization INTEGER DEFAULT 0,
            group_id INTEGER,
            type TEXT DEFAULT 'standard',
            status TEXT DEFAULT 'active',
            is_archived INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS warehouse_inventory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            warehouse_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(product_id, warehouse_id),
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS warehouse_zones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            warehouse_id INTEGER NOT NULL,
            zone_code TEXT NOT NULL,
            zone_name TEXT NOT NULL,
            zone_type TEXT DEFAULT 'standard',
            capacity INTEGER DEFAULT 0,
            is_hazmat INTEGER DEFAULT 0,
            is_cold_chain INTEGER DEFAULT 0,
            temperature REAL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS product_serial_numbers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            serial_number TEXT UNIQUE NOT NULL,
            lot_number TEXT,
            status TEXT DEFAULT 'available',
            location TEXT,
            warehouse_id INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            transaction_type TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            previous_balance INTEGER DEFAULT 0,
            new_balance INTEGER DEFAULT 0,
            reference_document TEXT,
            warehouse_id INTEGER,
            notes TEXT,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id)
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            incident_number TEXT UNIQUE NOT NULL,
            product_id INTEGER NOT NULL,
            warehouse_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            issue_type TEXT NOT NULL,
            description TEXT,
            status TEXT NOT NULL DEFAULT 'reported',
            resolution TEXT,
            created_by INTEGER,
            resolved_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            resolved_at TEXT,
            FOREIGN KEY (product_id) REFERENCES products(id),
            FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_requisitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pr_number TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            requested_by INTEGER,
            department TEXT,
            required_date TEXT,
            payment_method TEXT,
            priority TEXT DEFAULT 'medium',
            estimated_total REAL DEFAULT 0.00,
            status TEXT DEFAULT 'draft',
            review_notes TEXT,
            is_archived INTEGER DEFAULT 0,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS requisition_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            requisition_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            unit_price REAL,
            notes TEXT,
            FOREIGN KEY (requisition_id) REFERENCES purchase_requisitions(id) ON DELETE CASCADE
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS procurement_contracts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_number TEXT UNIQUE NOT NULL,
            supplier_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            start_date TEXT,
            end_date TEXT,
            total_value REAL DEFAULT 0.00,
            status TEXT DEFAULT 'draft',
            approval_status TEXT DEFAULT 'pending_review',
            document_path TEXT,
            is_archived INTEGER DEFAULT 0,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS shipments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            shipment_id TEXT UNIQUE NOT NULL,
            po_id INTEGER,
            carrier TEXT,
            tracking_number TEXT,
            mode TEXT DEFAULT 'road',
            origin TEXT,
            destination TEXT,
            departure_date TEXT,
            expected_arrival TEXT,
            actual_arrival TEXT,
            status TEXT DEFAULT 'pending',
            freight_cost REAL DEFAULT 0.00,
            notes TEXT,
            is_archived INTEGER DEFAULT 0,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (po_id) REFERENCES purchase_orders(id)
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_number TEXT UNIQUE NOT NULL,
            document_type TEXT NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            file_path TEXT,
            related_module TEXT,
            related_id INTEGER,
            status TEXT DEFAULT 'draft',
            is_archived INTEGER DEFAULT 0,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_performance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INTEGER NOT NULL,
            period TEXT NOT NULL,
            on_time_delivery REAL DEFAULT 0,
            quality_rate REAL DEFAULT 0,
            response_time REAL DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            ip_address TEXT,
            user_agent TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bidding_tenders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tender_code TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            tender_type TEXT NOT NULL DEFAULT 'spot_auction',
            transport_mode TEXT NOT NULL DEFAULT 'road',
            origin TEXT NOT NULL,
            destination TEXT NOT NULL,
            cargo_type TEXT DEFAULT 'standard_dry',
            estimated_volume TEXT,
            target_rate REAL DEFAULT 0.00,
            currency TEXT DEFAULT 'PHP',
            deadline TEXT,
            service_level_req TEXT,
            status TEXT DEFAULT 'open',
            awarded_bid_id INTEGER,
            awarded_carrier_id INTEGER,
            awarded_carrier_name TEXT,
            awarded_rate REAL,
            tms_shipment_id INTEGER,
            contract_id INTEGER,
            rate_sheet_specs TEXT,
            notes TEXT,
            is_archived INTEGER DEFAULT 0,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (awarded_carrier_id) REFERENCES suppliers(id),
            FOREIGN KEY (tms_shipment_id) REFERENCES shipments(id),
            FOREIGN KEY (contract_id) REFERENCES procurement_contracts(id)
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bidding_bids (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tender_id INTEGER NOT NULL,
            carrier_id INTEGER,
            carrier_name TEXT NOT NULL,
            bid_amount REAL NOT NULL,
            currency TEXT DEFAULT 'PHP',
            transit_time_days INTEGER DEFAULT 1,
            carrier_score REAL DEFAULT 85.0,
            cost_score REAL DEFAULT 0.0,
            composite_score REAL DEFAULT 0.0,
            service_level TEXT,
            notes TEXT,
            status TEXT DEFAULT 'submitted',
            submitted_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tender_id) REFERENCES bidding_tenders(id) ON DELETE CASCADE,
            FOREIGN KEY (carrier_id) REFERENCES suppliers(id)
        );");

        $defaultAdminHash = password_hash('admin@08', PASSWORD_DEFAULT);
        $pdo->exec("INSERT OR IGNORE INTO users (username, email, password, role, full_name, is_active, is_archived) VALUES ('admin', 'admin@globalscm.com', '$defaultAdminHash', 'admin', 'System Administrator', 1, 0);");

        $defaultSettings = [
            ['currency_symbol', '₱', 'general', 'Currency symbol used throughout the system'],
            ['currency_code', 'PHP', 'general', 'ISO currency code'],
            ['theme', 'light', 'ui', 'System theme (light/dark)'],
            ['barcode_format', 'CODE128', 'general', 'Default barcode format for products'],
            ['auto_serial_number', 'true', 'inventory', 'Automatically generate serial numbers'],
            ['report_ai_enabled', 'true', 'ai', 'Enable AI-powered daily reports'],
            ['show_warehousing', 'true', 'ui', 'Show/hide warehousing module'],
            ['show_inventory', 'true', 'ui', 'Show/hide inventory module'],
            ['show_procurement', 'true', 'ui', 'Show/hide procurement module'],
            ['show_suppliers', 'true', 'ui', 'Show/hide supplier module'],
            ['show_purchase_orders', 'true', 'ui', 'Show/hide purchase orders module'],
            ['show_logistics', 'true', 'ui', 'Show/hide logistics module']
        ];

        foreach ($defaultSettings as $setting) {
            [$key, $value, $group, $desc] = $setting;
            $pdo->exec("INSERT OR IGNORE INTO system_settings (setting_key, setting_value, setting_group, description) VALUES ('$key', '$value', '$group', '$desc');");
        }
    }
}

if (!function_exists('initializePostgresDatabase')) {
    function initializePostgresDatabase(PDO $pdo) {
        try {
            $check = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users' LIMIT 1");
            if ($check && $check->fetchColumn()) {
                return; // Tables already initialized
            }

            $schemaFile = dirname(__DIR__) . '/database/supabase_schema.sql';
            if (is_file($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                if (!empty($sql)) {
                    $pdo->exec($sql);
                }
            }
        } catch (Throwable $e) {
            error_log("[POSTGRES INIT NOTICE] Automatic schema bootstrap: " . $e->getMessage());
        }
    }
}

$pdo = null;
try {
    if (strtolower($driver) === 'sqlite') {
        $sqlitePath = $dbName;
        if (!is_file($sqlitePath)) {
            $sqlitePath = $rootDir . '/database/database.sqlite';
        }

        if (!file_exists(dirname($sqlitePath))) {
            mkdir(dirname($sqlitePath), 0777, true);
        }

        if (!file_exists($sqlitePath)) {
            touch($sqlitePath);
        }

        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Enable WAL mode for reliable concurrent writes (fixes data-not-saving on Windows)
        $pdo->exec('PRAGMA journal_mode=WAL;');
        // Enforce foreign key constraints
        $pdo->exec('PRAGMA foreign_keys=ON;');
        // Reduce lock contention
        $pdo->exec('PRAGMA busy_timeout=5000;');

        initializeSqliteDatabase($pdo);
    } elseif (in_array(strtolower($driver), ['pgsql', 'postgres', 'postgresql'])) {
        $dsnPort = !empty($port) ? $port : '5432';
        $dsn = "pgsql:host={$host};port={$dsnPort};dbname={$dbName}";
        if (strpos($host, 'supabase.co') !== false || getenv('DB_SSLMODE') === 'require') {
            $dsn .= ";sslmode=require";
        }
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        initializePostgresDatabase($pdo);
    } else {
        $dsnPort = !empty($port) ? $port : '3306';
        $dsn = 'mysql:host=' . $host . ';port=' . $dsnPort . ';dbname=' . $dbName . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
} catch (Throwable $e) {
    error_log("[DATABASE CONNECTION NOTICE] Primary connection failed ({$driver}): " . $e->getMessage() . ". Falling back to local SQLite.");
    $fallbackSqlite = $rootDir . '/database/database.sqlite';
    if (is_file($fallbackSqlite)) {
        try {
            $pdo = new PDO('sqlite:' . $fallbackSqlite, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            initializeSqliteDatabase($pdo);
        } catch (Throwable $ignored) {
            $pdo = null;
        }
    }
}

if ($pdo instanceof PDO) {
    $databaseDriver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    $tableExists = function(PDO $p, string $tbl) use ($databaseDriver): bool {
        try {
            if ($databaseDriver === 'sqlite') {
                $st = $p->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
                $st->execute([$tbl]);
                return (bool)$st->fetchColumn();
            } elseif (in_array($databaseDriver, ['pgsql', 'postgres', 'postgresql'], true)) {
                $st = $p->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?");
                $st->execute([$tbl]);
                return (bool)$st->fetchColumn();
            } else {
                $st = $p->prepare("SHOW TABLES LIKE ?");
                $st->execute([$tbl]);
                return (bool)$st->fetchColumn();
            }
        } catch (Throwable $e) {
            return false;
        }
    };

    foreach ([
        ['products', 'brand', 'VARCHAR(150)'],
        ['suppliers', 'supplier_type', "VARCHAR(100) NOT NULL DEFAULT 'general'"],
        ['purchase_orders', 'payment_method', 'VARCHAR(30)'],
        ['purchase_requisitions', 'payment_method', 'VARCHAR(30)'],
        ['warehouses', 'group_id', 'INTEGER'],
        ['purchase_order_items', 'received_quantity', 'INTEGER DEFAULT 0'],
        ['users', 'two_factor_secret', 'TEXT'],
        ['users', 'two_factor_enabled', 'INTEGER DEFAULT 0'],
        ['users', 'two_factor_confirmed_at', in_array($databaseDriver, ['pgsql', 'postgres', 'postgresql'], true) ? 'TIMESTAMPTZ' : 'DATETIME'],
        ['users', 'two_factor_recovery_codes_generated_at', in_array($databaseDriver, ['pgsql', 'postgres', 'postgresql'], true) ? 'TIMESTAMPTZ' : 'DATETIME'],
        ['users', 'two_factor_time_offset', 'INTEGER DEFAULT 0'],
    ] as [$table, $column, $definition]) {
        if (!$tableExists($pdo, $table)) {
            continue; // Table does not exist yet (cleanly skip to avoid aborting transactions)
        }
        try {
            if (in_array($databaseDriver, ['pgsql', 'postgres', 'postgresql'], true)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN IF NOT EXISTS $column $definition");
            } else {
                $pdo->query("SELECT $column FROM $table WHERE 1 = 0");
            }
        } catch (Throwable $e) {
            try {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            } catch (Throwable $ignored) {}
        }
    }

    if (in_array($databaseDriver, ['mysql', 'mariadb'], true)) {
        $recoveryCodesDDL = "CREATE TABLE IF NOT EXISTS user_recovery_codes (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            used_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_recovery_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $rateLimitsDDL = "CREATE TABLE IF NOT EXISTS two_factor_rate_limits (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(150) NOT NULL,
            attempt_time INT NOT NULL,
            INDEX idx_2fa_rate_limit (identifier, attempt_time)
        )";
    } elseif (in_array($databaseDriver, ['pgsql', 'postgres', 'postgresql'], true)) {
        $recoveryCodesDDL = "CREATE TABLE IF NOT EXISTS user_recovery_codes (
            id BIGSERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            used_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $rateLimitsDDL = "CREATE TABLE IF NOT EXISTS two_factor_rate_limits (
            id BIGSERIAL PRIMARY KEY,
            identifier VARCHAR(150) NOT NULL,
            attempt_time INTEGER NOT NULL
        )";
    } else {
        $recoveryCodesDDL = "CREATE TABLE IF NOT EXISTS user_recovery_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            code_hash TEXT NOT NULL,
            used_at TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $rateLimitsDDL = "CREATE TABLE IF NOT EXISTS two_factor_rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,
            attempt_time INTEGER NOT NULL
        )";
    }
    $pdo->exec($recoveryCodesDDL);
    $pdo->exec($rateLimitsDDL);

    if (in_array($databaseDriver, ['mysql', 'mariadb'], true)) {
        $privacyAcknowledgementDDL = "CREATE TABLE IF NOT EXISTS supplier_privacy_acknowledgements (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT NOT NULL,
            acknowledged_by VARCHAR(255) NOT NULL,
            acknowledgement_text TEXT NOT NULL,
            policy_version VARCHAR(50) NOT NULL,
            recorded_by INT NULL,
            acknowledged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_supplier_privacy_supplier (supplier_id)
        )";
    } elseif (in_array($databaseDriver, ['pgsql', 'postgres', 'postgresql'], true)) {
        $privacyAcknowledgementDDL = "CREATE TABLE IF NOT EXISTS supplier_privacy_acknowledgements (
            id BIGSERIAL PRIMARY KEY,
            supplier_id INTEGER NOT NULL,
            acknowledged_by VARCHAR(255) NOT NULL,
            acknowledgement_text TEXT NOT NULL,
            policy_version VARCHAR(50) NOT NULL,
            recorded_by INTEGER,
            acknowledged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
    } else {
        $privacyAcknowledgementDDL = "CREATE TABLE IF NOT EXISTS supplier_privacy_acknowledgements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_id INTEGER NOT NULL,
            acknowledged_by TEXT NOT NULL,
            acknowledgement_text TEXT NOT NULL,
            policy_version TEXT NOT NULL,
            recorded_by INTEGER,
            acknowledged_at TEXT DEFAULT CURRENT_TIMESTAMP
        )";
    }
    $pdo->exec($privacyAcknowledgementDDL);

    if (in_array($databaseDriver, ['mysql', 'mariadb'], true)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_groups (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            group_name VARCHAR(150) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $batchDDL = "CREATE TABLE IF NOT EXISTS inventory_batches (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            receipt_number VARCHAR(100) NOT NULL,
            po_id INT NOT NULL,
            po_item_id INT NOT NULL,
            origin_batch_id INT NULL,
            product_id INT NOT NULL,
            supplier_id INT NULL,
            warehouse_id INT NOT NULL,
            batch_number VARCHAR(100) NOT NULL,
            received_quantity INT NOT NULL,
            available_quantity INT NOT NULL DEFAULT 0,
            expiry_date DATE NULL,
            brand_snapshot VARCHAR(150) NULL,
            quality_status VARCHAR(20) NOT NULL DEFAULT 'rejected',
            quality_notes TEXT NULL,
            received_by INT NULL,
            received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_inventory_batch_product_warehouse (product_id, warehouse_id),
            INDEX idx_inventory_batch_expiry (expiry_date),
            INDEX idx_inventory_batch_receipt (receipt_number)
        )";
    } elseif (in_array($databaseDriver, ['pgsql', 'postgres', 'postgresql'], true)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_groups (
            id BIGSERIAL PRIMARY KEY,
            group_name VARCHAR(150) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $batchDDL = "CREATE TABLE IF NOT EXISTS inventory_batches (
            id BIGSERIAL PRIMARY KEY,
            receipt_number VARCHAR(100) NOT NULL,
            po_id INTEGER NOT NULL,
            po_item_id INTEGER NOT NULL,
            origin_batch_id INTEGER NULL,
            product_id INTEGER NOT NULL,
            supplier_id INTEGER NULL,
            warehouse_id INTEGER NOT NULL,
            batch_number VARCHAR(100) NOT NULL,
            received_quantity INTEGER NOT NULL,
            available_quantity INTEGER NOT NULL DEFAULT 0,
            expiry_date DATE NULL,
            brand_snapshot VARCHAR(150) NULL,
            quality_status VARCHAR(20) NOT NULL DEFAULT 'rejected',
            quality_notes TEXT NULL,
            received_by INTEGER NULL,
            received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_name VARCHAR(150) NOT NULL UNIQUE,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $batchDDL = "CREATE TABLE IF NOT EXISTS inventory_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            receipt_number TEXT NOT NULL,
            po_id INTEGER NOT NULL,
            po_item_id INTEGER NOT NULL,
            origin_batch_id INTEGER,
            product_id INTEGER NOT NULL,
            supplier_id INTEGER,
            warehouse_id INTEGER NOT NULL,
            batch_number TEXT NOT NULL,
            received_quantity INTEGER NOT NULL,
            available_quantity INTEGER NOT NULL DEFAULT 0,
            expiry_date TEXT,
            brand_snapshot TEXT,
            quality_status TEXT NOT NULL DEFAULT 'rejected',
            quality_notes TEXT,
            received_by INTEGER,
            received_at TEXT DEFAULT CURRENT_TIMESTAMP
        )";
    }
    $pdo->exec($batchDDL);
    try {
        $pdo->query('SELECT origin_batch_id FROM inventory_batches WHERE 1 = 0');
    } catch (PDOException $e) {
        $pdo->exec('ALTER TABLE inventory_batches ADD COLUMN origin_batch_id INTEGER');
    }

    try {
        $pdo->query('SELECT item_type FROM products WHERE 1 = 0');
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE products ADD COLUMN item_type VARCHAR(100) NOT NULL DEFAULT 'general'");
    }

    try {
        $pdo->query('SELECT warehouse_id FROM purchase_orders WHERE 1 = 0');
    } catch (PDOException $e) {
        $pdo->exec('ALTER TABLE purchase_orders ADD COLUMN warehouse_id INTEGER');
    }

    $databaseDriver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if (in_array($databaseDriver, ['mysql', 'mariadb'], true)) {
        $warehouseInventoryDDL = "CREATE TABLE IF NOT EXISTS warehouse_inventory (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id INTEGER NOT NULL,
        warehouse_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_product_warehouse (product_id, warehouse_id),
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
        )";
    } elseif (in_array($databaseDriver, ['pgsql', 'postgres', 'postgresql'], true)) {
        $warehouseInventoryDDL = "CREATE TABLE IF NOT EXISTS warehouse_inventory (
        id BIGSERIAL PRIMARY KEY,
        product_id INTEGER NOT NULL,
        warehouse_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(product_id, warehouse_id),
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
        )";
    } else {
        $warehouseInventoryDDL = "CREATE TABLE IF NOT EXISTS warehouse_inventory (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        warehouse_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(product_id, warehouse_id),
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
        )";
    }
    $pdo->exec($warehouseInventoryDDL);

    if (in_array($databaseDriver, ['mysql', 'mariadb'], true)) {
        $incidentDDL = "CREATE TABLE IF NOT EXISTS inventory_incidents (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            incident_number VARCHAR(100) NOT NULL UNIQUE,
            product_id INT NOT NULL,
            warehouse_id INT NOT NULL,
            quantity INT NOT NULL,
            issue_type VARCHAR(40) NOT NULL,
            description TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'reported',
            resolution VARCHAR(40),
            created_by INT,
            resolved_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL,
            FOREIGN KEY (product_id) REFERENCES products(id),
            FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
        )";
    } elseif (in_array($databaseDriver, ['pgsql', 'postgres', 'postgresql'], true)) {
        $incidentDDL = "CREATE TABLE IF NOT EXISTS inventory_incidents (
            id BIGSERIAL PRIMARY KEY,
            incident_number VARCHAR(100) NOT NULL UNIQUE,
            product_id INTEGER NOT NULL,
            warehouse_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            issue_type VARCHAR(40) NOT NULL,
            description TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'reported',
            resolution VARCHAR(40),
            created_by INTEGER,
            resolved_by INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL,
            FOREIGN KEY (product_id) REFERENCES products(id),
            FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
        )";
    } else {
        $incidentDDL = "CREATE TABLE IF NOT EXISTS inventory_incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            incident_number TEXT NOT NULL UNIQUE,
            product_id INTEGER NOT NULL,
            warehouse_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            issue_type TEXT NOT NULL,
            description TEXT,
            status TEXT NOT NULL DEFAULT 'reported',
            resolution TEXT,
            created_by INTEGER,
            resolved_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            resolved_at TEXT,
            FOREIGN KEY (product_id) REFERENCES products(id),
            FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
        )";
    }
    $pdo->exec($incidentDDL);

    if (in_array($databaseDriver, ['mysql', 'mariadb'], true)) {
        $migrationCheck = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'report_document_type_migrated'");
        $migrationCheck->execute();
        if (!$migrationCheck->fetchColumn()) {
            $pdo->exec("ALTER TABLE documents MODIFY document_type ENUM('bol', 'packing_list', 'invoice', 'customs', 'certificate', 'contract', 'report') NOT NULL");
            $migrationWrite = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, description) VALUES ('report_document_type_migrated', 'true', 'system', 'Allows generated reports in document tracking')");
            $migrationWrite->execute();
        }
    }

    $pdo->exec("UPDATE warehouses SET status = 'active', is_archived = false
        WHERE warehouse_code = 'MAIN'
        AND NOT EXISTS (SELECT 1 FROM warehouses WHERE is_archived = false AND status = 'active')");

    $pdo->exec("INSERT INTO warehouses (warehouse_code, name, location, capacity, type, status, is_archived)
        SELECT 'MAIN', 'Main Warehouse', 'Primary inventory location', 0, 'standard', 'active', false
        WHERE NOT EXISTS (SELECT 1 FROM warehouses WHERE is_archived = false AND status = 'active')
        AND NOT EXISTS (SELECT 1 FROM warehouses WHERE warehouse_code = 'MAIN')");

    $pdo->exec("INSERT INTO warehouse_inventory (product_id, warehouse_id, quantity)
        SELECT p.id, (SELECT id FROM warehouses WHERE is_archived = false ORDER BY id LIMIT 1), p.current_stock
        FROM products p
        WHERE NOT EXISTS (SELECT 1 FROM warehouse_inventory wi WHERE wi.product_id = p.id)");
}

require_once $rootDir . '/includes/function.php';

$GLOBALS['pdo'] = $pdo;

$config = [
    'default' => $driver,
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => getenv('DB_URL') ?: ($env['DB_URL'] ?? null),
            'database' => $dbName,
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],
        'mysql' => [
            'driver' => 'mysql',
            'url' => getenv('DB_URL') ?: ($env['DB_URL'] ?? null),
            'host' => $host,
            'port' => $port,
            'database' => $dbName,
            'username' => $username,
            'password' => $password,
            'unix_socket' => getenv('DB_SOCKET') ?: ($env['DB_SOCKET'] ?? ''),
            'charset' => getenv('DB_CHARSET') ?: ($env['DB_CHARSET'] ?? 'utf8mb4'),
            'collation' => getenv('DB_COLLATION') ?: ($env['DB_COLLATION'] ?? 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [],
        ],
        'mariadb' => [
            'driver' => 'mariadb',
            'url' => getenv('DB_URL') ?: ($env['DB_URL'] ?? null),
            'host' => $host,
            'port' => $port,
            'database' => $dbName,
            'username' => $username,
            'password' => $password,
            'unix_socket' => getenv('DB_SOCKET') ?: ($env['DB_SOCKET'] ?? ''),
            'charset' => getenv('DB_CHARSET') ?: ($env['DB_CHARSET'] ?? 'utf8mb4'),
            'collation' => getenv('DB_COLLATION') ?: ($env['DB_COLLATION'] ?? 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [],
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => getenv('DB_URL') ?: ($env['DB_URL'] ?? null),
            'host' => $host,
            'port' => getenv('DB_PORT') ?: ($env['DB_PORT'] ?? '5432'),
            'database' => $dbName,
            'username' => $username,
            'password' => $password,
            'charset' => getenv('DB_CHARSET') ?: ($env['DB_CHARSET'] ?? 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => getenv('DB_SSLMODE') ?: ($env['DB_SSLMODE'] ?? 'prefer'),
        ],
        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => getenv('DB_URL') ?: ($env['DB_URL'] ?? null),
            'host' => $host,
            'port' => getenv('DB_PORT') ?: ($env['DB_PORT'] ?? '1433'),
            'database' => $dbName,
            'username' => $username,
            'password' => $password,
            'charset' => getenv('DB_CHARSET') ?: ($env['DB_CHARSET'] ?? 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
        ],
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
    'redis' => [
        'client' => getenv('REDIS_CLIENT') ?: ($env['REDIS_CLIENT'] ?? 'phpredis'),
        'options' => [
            'cluster' => getenv('REDIS_CLUSTER') ?: ($env['REDIS_CLUSTER'] ?? 'redis'),
            'prefix' => getenv('REDIS_PREFIX') ?: ($env['REDIS_PREFIX'] ?? 'laravel_database_'),
            'persistent' => false,
        ],
        'default' => [
            'url' => getenv('REDIS_URL') ?: ($env['REDIS_URL'] ?? null),
            'host' => getenv('REDIS_HOST') ?: ($env['REDIS_HOST'] ?? '127.0.0.1'),
            'username' => getenv('REDIS_USERNAME') ?: ($env['REDIS_USERNAME'] ?? null),
            'password' => getenv('REDIS_PASSWORD') ?: ($env['REDIS_PASSWORD'] ?? null),
            'port' => getenv('REDIS_PORT') ?: ($env['REDIS_PORT'] ?? '6379'),
            'database' => getenv('REDIS_DB') ?: ($env['REDIS_DB'] ?? '0'),
            'max_retries' => getenv('REDIS_MAX_RETRIES') ?: ($env['REDIS_MAX_RETRIES'] ?? 3),
            'backoff_algorithm' => getenv('REDIS_BACKOFF_ALGORITHM') ?: ($env['REDIS_BACKOFF_ALGORITHM'] ?? 'decorrelated_jitter'),
            'backoff_base' => getenv('REDIS_BACKOFF_BASE') ?: ($env['REDIS_BACKOFF_BASE'] ?? 100),
            'backoff_cap' => getenv('REDIS_BACKOFF_CAP') ?: ($env['REDIS_BACKOFF_CAP'] ?? 1000),
        ],
        'cache' => [
            'url' => getenv('REDIS_URL') ?: ($env['REDIS_URL'] ?? null),
            'host' => getenv('REDIS_HOST') ?: ($env['REDIS_HOST'] ?? '127.0.0.1'),
            'username' => getenv('REDIS_USERNAME') ?: ($env['REDIS_USERNAME'] ?? null),
            'password' => getenv('REDIS_PASSWORD') ?: ($env['REDIS_PASSWORD'] ?? null),
            'port' => getenv('REDIS_PORT') ?: ($env['REDIS_PORT'] ?? '6379'),
            'database' => getenv('REDIS_CACHE_DB') ?: ($env['REDIS_CACHE_DB'] ?? '1'),
            'max_retries' => getenv('REDIS_MAX_RETRIES') ?: ($env['REDIS_MAX_RETRIES'] ?? 3),
            'backoff_algorithm' => getenv('REDIS_BACKOFF_ALGORITHM') ?: ($env['REDIS_BACKOFF_ALGORITHM'] ?? 'decorrelated_jitter'),
            'backoff_base' => getenv('REDIS_BACKOFF_BASE') ?: ($env['REDIS_BACKOFF_BASE'] ?? 100),
            'backoff_cap' => getenv('REDIS_BACKOFF_CAP') ?: ($env['REDIS_BACKOFF_CAP'] ?? 1000),
        ],
    ],
];

if (function_exists('env')) {
    return $config;
}

return $config;
