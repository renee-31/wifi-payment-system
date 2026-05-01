<?php
// initiate_payment.php - Starts M-Pesa STK Push

require_once 'mpesa.php';

// Get payment data from wifi.php
$data = json_decode(file_get_contents('php://input'), true);

$package_id = $data['package_id'];
$mac_address = $data['mac_address'];
$phone = $data['phone'];
$amount = $data['amount'];
$package_name = $data['package_name'];

// Connect to database
$conn = new mysqli('localhost', 'root', '', 'wifi_payment_system');
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

// Generate unique transaction ID
$checkoutRequestID = 'STK_' . time() . '_' . rand(1000, 9999);

// Create pending payment record
$sql = "INSERT INTO payments (transaction_id, mac_address, package_id, amount, phone_number, status) 
        VALUES (?, ?, ?, ?, ?, 'pending')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssids", $checkoutRequestID, $mac_address, $package_id, $amount, $phone);
$stmt->execute();

// Generate temporary credentials
$username = 'USER_' . strtoupper(substr(md5(uniqid()), 0, 8));
$password = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 10);

// Calculate expiry time (1 hour for testing)
$end_time = date('Y-m-d H:i:s', strtotime('+1 hour'));

// Create inactive session (will be activated after payment)
$session_sql = "INSERT INTO active_sessions (mac_address, username, password, package_id, end_time, is_active, ip_address) 
                VALUES (?, ?, ?, ?, ?, 0, ?)";
$ip_address = $_SERVER['REMOTE_ADDR'];
$stmt = $conn->prepare($session_sql);
$stmt->bind_param("sssiss", $mac_address, $username, $password, $package_id, $end_time, $ip_address);
$stmt->execute();

$conn->close();

// Callback URL (need ngrok for local testing)
$callbackUrl =  "https://YOUR_NEW_URL.ngrok-free.app/wifi/callback.php";
// Initiate STK Push
$mpesa = new MpesaAPI();
$response = $mpesa->stkPush($phone, $amount, $checkoutRequestID, $callbackUrl);

if ($response['ResponseCode'] == '0') {
    echo json_encode([
        'success' => true,
        'checkoutRequestID' => $checkoutRequestID,
        'message' => 'STK Push sent successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $response['errorMessage'] ?? 'STK Push failed'
    ]);
}
?>