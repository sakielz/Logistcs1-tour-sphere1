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
            last_login TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT UNIQUE,
            product_name TEXT,
            description TEXT,
            category TEXT,
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
            order_date TEXT,
            expected_delivery TEXT,
            shipping_address TEXT,
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
            unit_price REAL,
            total_price REAL,
            expected_date TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            warehouse_code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            location TEXT,
            capacity INTEGER DEFAULT 0,
            current_utilization INTEGER DEFAULT 0,
            type TEXT DEFAULT 'standard',
            status TEXT DEFAULT 'active',
            is_archived INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_requisitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pr_number TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            requested_by INTEGER,
            department TEXT,
            required_date TEXT,
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
