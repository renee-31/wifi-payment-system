<?php
// initiate_payment.php - Initiates M-Pesa STK push

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once __DIR__ . '/mpesa.php';

define('CALLBACK_BASE_URL', 'https://dice-premium-landlord.ngrok-free.app');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($input)) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
        exit;
    }

    $package_id = (int)($input['package_id'] ?? 0);
    $mac_address = trim((string)($input['mac_address'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $amount = (float)($input['amount'] ?? 0);

    if ($package_id <= 0 || $mac_address === '' || $phone === '' || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing required payment details']);
        exit;
    }

    $conn = new mysqli('localhost', 'root', '', 'wifi_payment_system');
    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }

    // Get package details
    $stmt = $conn->prepare("SELECT duration_hours, is_midnight_package FROM packages WHERE id = ?");
    $stmt->bind_param("i", $package_id);
    $stmt->execute();
    $packageResult = $stmt->get_result();
    $package = $packageResult->fetch_assoc();

    if (!$package) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Invalid package selected']);
        exit;
    }

    // Calculate end time
    $now = new DateTime('now', new DateTimeZone('Africa/Nairobi'));
    if ($package['is_midnight_package']) {
        $midnight = new DateTime('tomorrow midnight', new DateTimeZone('Africa/Nairobi'));
        $midnight->modify('-1 second');
        $end_time = $midnight->format('Y-m-d H:i:s');
    } else {
        $now->modify('+' . (int)$package['duration_hours'] . ' hours');
        $end_time = $now->format('Y-m-d H:i:s');
    }

    // Generate credentials
    $username = 'USER_' . strtoupper(substr(md5(uniqid()), 0, 8));
    $password = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 10);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

    // Insert pending payment
    $transaction_id = 'TXN_' . time() . '_' . rand(1000, 9999);
    $stmt = $conn->prepare("INSERT INTO payments (transaction_id, mac_address, package_id, amount, phone_number, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->bind_param("ssids", $transaction_id, $mac_address, $package_id, $amount, $phone);
    $stmt->execute();

    // Insert inactive session
    $stmt = $conn->prepare("INSERT INTO active_sessions (mac_address, username, password, package_id, end_time, is_active, ip_address) VALUES (?, ?, ?, ?, ?, 0, ?)");
    $stmt->bind_param("sssiss", $mac_address, $username, $password, $package_id, $end_time, $ip_address);
    $stmt->execute();
    $session_id = $conn->insert_id;

    // Insert payment session record
    $stmt = $conn->prepare("INSERT INTO payment_sessions (checkout_request_id, session_id, mac_address, username, password, package_id, amount, phone_number, end_time, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->bind_param("sisssdsss", $transaction_id, $session_id, $mac_address, $username, $password, $package_id, $amount, $phone, $end_time);
    $stmt->execute();

    // Initiate STK Push
    $mpesa = new MpesaAPI();
    $callbackUrl = CALLBACK_BASE_URL . '/callback.php';
    
    // Log the callback URL being used
    file_put_contents('mpesa_log.txt', date('Y-m-d H:i:s') . " Using callback URL: " . $callbackUrl . PHP_EOL, FILE_APPEND);
    
    $stkResponse = $mpesa->stkPush($phone, $amount, "WIFI_PAY", $callbackUrl);

    // Log response
    file_put_contents('mpesa_log.txt', date('Y-m-d H:i:s') . " STK Response: " . json_encode($stkResponse) . PHP_EOL, FILE_APPEND);

    if (isset($stkResponse['ResponseCode']) && $stkResponse['ResponseCode'] == '0') {
        $checkoutRequestID = $stkResponse['CheckoutRequestID'];
        
        // Update records with real checkout ID
        $stmt = $conn->prepare("UPDATE payments SET transaction_id = ? WHERE transaction_id = ?");
        $stmt->bind_param("ss", $checkoutRequestID, $transaction_id);
        $stmt->execute();
        
        $stmt = $conn->prepare("UPDATE payment_sessions SET checkout_request_id = ? WHERE session_id = ?");
        $stmt->bind_param("si", $checkoutRequestID, $session_id);
        $stmt->execute();
        
        $conn->close();
        
        echo json_encode([
            'success' => true,
            'checkoutRequestID' => $checkoutRequestID,
            'message' => 'Please enter your M-Pesa PIN on your phone'
        ]);
    } else {
        $errorMsg = $stkResponse['errorMessage'] ?? ($stkResponse['ResponseDescription'] ?? 'STK Push failed');
        
        // Mark as failed
        $stmt = $conn->prepare("UPDATE payments SET status = 'failed' WHERE transaction_id = ?");
        $stmt->bind_param("s", $transaction_id);
        $stmt->execute();
        
        $conn->close();
        
        echo json_encode([
            'success' => false,
            'message' => $errorMsg
        ]);
    }
    
} catch (Exception $e) {
    file_put_contents('mpesa_log.txt', date('Y-m-d H:i:s') . " Exception: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>