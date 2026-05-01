<?php
// check_payment.php - Checks payment status

$data = json_decode(file_get_contents('php://input'), true);
$checkoutRequestID = $data['checkoutRequestID'];

$conn = new mysqli('localhost', 'root', '', 'wifi_payment_system');

$sql = "SELECT p.status, s.username, s.password, s.end_time 
        FROM payments p 
        LEFT JOIN active_sessions s ON p.mac_address = s.mac_address 
        WHERE p.transaction_id = ? 
        ORDER BY s.id DESC LIMIT 1";
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
    } else {
        echo json_encode(['status' => $row['status']]);
    }
} else {
    echo json_encode(['status' => 'pending']);
}

$conn->close();
?>