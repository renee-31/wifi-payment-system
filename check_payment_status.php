<?php
// check_payment_status.php - Check if payment is completed
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$checkoutRequestID = $data['checkoutRequestID'] ?? '';

if (empty($checkoutRequestID)) {
    echo json_encode(['status' => 'failed', 'message' => 'No transaction ID provided']);
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'wifi_payment_system');
if ($conn->connect_error) {
    echo json_encode(['status' => 'failed', 'message' => 'Database connection failed']);
    exit;
}

// Check payment status
$sql = "SELECT p.status, p.mac_address, s.username, s.password, s.end_time 
        FROM payments p 
        LEFT JOIN active_sessions s ON p.mac_address = s.mac_address 
        WHERE p.transaction_id = ? 
        ORDER BY p.id DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $checkoutRequestID);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    if ($row['status'] == 'completed') {
        echo json_encode([
            'status' => 'completed',
            'username' => $row['username'],
            'password' => $row['password'],
            'end_time' => $row['end_time']
        ]);
    } else if ($row['status'] == 'failed') {
        echo json_encode(['status' => 'failed']);
    } else {
        echo json_encode(['status' => 'pending']);
    }
} else {
    echo json_encode(['status' => 'pending']);
}

$conn->close();
?>