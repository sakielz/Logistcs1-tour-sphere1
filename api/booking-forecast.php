<?php
/**
 * api/booking-forecast.php
 * Ingestion API for Forward-Booking Curves from Customer Booking Systems
 *
 * Supported Methods:
 *  - POST: Ingest forward booking curve records (JSON body)
 *  - GET: Query current forward booking summary & forecasted consumable requirements
 *
 * Authentication:
 *  - Session (logged in admin/manager) OR
 *  - Header `X-API-Key` matching `booking_ingest_api_key` in system_settings
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/tour_ops.php';

header('Content-Type: application/json; charset=utf-8');

if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

tourOpsEnsureSchema($pdo);

// Check Authentication
$authenticated = false;
if (!empty($_SESSION['user_id'])) {
    $authenticated = true;
} else {
    $keyHeader = $_SERVER['HTTP_X_API_KEY'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $keyHeader = trim(str_ireplace('Bearer ', '', $keyHeader));
    $storedKey = tsGetSetting($pdo, 'booking_ingest_api_key');
    if ($keyHeader !== '' && $storedKey !== null && hash_equals($storedKey, $keyHeader)) {
        $authenticated = true;
    }
}

if (!$authenticated) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Provide X-API-Key header or authenticate via session.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Return booking curves and consumable forecast
    $days = tsInt($_GET['days'] ?? 30, 7);
    $curves = $pdo->prepare("
        SELECT departure_date, tour_type, booked_pax, expected_pax, source, external_ref
        FROM forecast_booking_curve
        WHERE departure_date >= CURRENT_DATE AND departure_date <= CURRENT_DATE + (INTERVAL '1 day' * ?)
        ORDER BY departure_date ASC, tour_type ASC
    ");
    $curves->execute([$days]);
    $bookingData = $curves->fetchAll(PDO::FETCH_ASSOC);

    $consumables = $pdo->query("SELECT * FROM forecast_consumables WHERE is_archived = 0 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'horizon_days' => $days,
        'forward_bookings' => $bookingData,
        'consumables' => $consumables,
    ], JSON_PRETTY_PRINT);
    exit();
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit();
    }

    $records = isset($payload['bookings']) && is_array($payload['bookings']) ? $payload['bookings'] : [$payload];
    $inserted = 0;

    $stmt = $pdo->prepare("
        INSERT INTO forecast_booking_curve (departure_date, tour_type, booked_pax, expected_pax, source, external_ref, snapshot_date)
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_DATE)
    ");

    $pdo->beginTransaction();
    try {
        foreach ($records as $row) {
            $date = tsDate($row['departure_date'] ?? $row['date'] ?? '');
            $tour = tsStr($row['tour_type'] ?? 'General Tour', 100);
            $booked = tsInt($row['booked_pax'] ?? $row['pax'] ?? 0);
            $expected = tsInt($row['expected_pax'] ?? ($booked ? (int)ceil($booked * 1.15) : 0));
            $source = tsStr($row['source'] ?? 'Customer Booking API', 40);
            $ref = tsStr($row['external_ref'] ?? $row['booking_id'] ?? '', 120);

            if (!$date) continue;

            $stmt->execute([$date, $tour, $booked, $expected, $source, $ref]);
            $inserted++;
        }
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Successfully ingested $inserted forward-booking curve entries.",
            'records_processed' => $inserted,
        ]);
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
