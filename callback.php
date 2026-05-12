<?php
// callback.php - Receives M-Pesa payment confirmation

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set header to return JSON
header('Content-Type: application/json');

$conn = new mysqli('localhost', 'root', '', 'wifi_payment_system');
if ($conn->connect_error) {
    file_put_contents('mpesa_log.txt', "DB Connection Failed: " . $conn->connect_error . PHP_EOL, FILE_APPEND);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Database connection failed']);
    exit;
}

// Get callback data
$callbackJSONData = file_get_contents('php://input');
$callbackData = json_decode($callbackJSONData, true);

// Log for debugging
file_put_contents('mpesa_log.txt', date('Y-m-d H:i:s') . ' Callback received: ' . $callbackJSONData . PHP_EOL, FILE_APPEND);

if (isset($callbackData['Body']['stkCallback'])) {
    $stkCallback = $callbackData['Body']['stkCallback'];
    $resultCode = $stkCallback['ResultCode'];
    $resultDesc = $stkCallback['ResultDesc'];
    $checkoutRequestID = $stkCallback['CheckoutRequestID'];
    
    file_put_contents('mpesa_log.txt', "Processing: CheckoutID=$checkoutRequestID, ResultCode=$resultCode, ResultDesc=$resultDesc" . PHP_EOL, FILE_APPEND);
    
    if ($resultCode == 0) {
        // Payment successful
        $metadata = $stkCallback['CallbackMetadata']['Item'];
        $mpesaReceiptNumber = '';
        $amount = '';
        $phoneNumber = '';
        
        foreach ($metadata as $item) {
            if ($item['Name'] == 'MpesaReceiptNumber') {
                $mpesaReceiptNumber = $item['Value'];
            }
            if ($item['Name'] == 'Amount') {
                $amount = $item['Value'];
            }
            if ($item['Name'] == 'PhoneNumber') {
                $phoneNumber = $item['Value'];
            }
        }
        
        // Update payment record
        $sql = "UPDATE payments SET status = 'completed', mpesa_code = ?, amount = ? 
                WHERE transaction_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $mpesaReceiptNumber, $amount, $checkoutRequestID);
        
        if ($stmt->execute()) {
            file_put_contents('mpesa_log.txt', "Payment updated successfully for: $checkoutRequestID" . PHP_EOL, FILE_APPEND);
            
            // Get the mac_address from payments
            $macQuery = "SELECT mac_address, package_id FROM payments WHERE transaction_id = ?";
            $stmt2 = $conn->prepare($macQuery);
            $stmt2->bind_param("s", $checkoutRequestID);
            $stmt2->execute();
            $result = $stmt2->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $mac_address = $row['mac_address'];
                $package_id = $row['package_id'];
                
                // Calculate end time based on package
                $end_time = calculateEndTime($package_id, $conn);
                
                // Activate user session
                $sql2 = "UPDATE active_sessions SET is_active = 1, start_time = NOW(), end_time = ? 
                         WHERE mac_address = ? AND is_active = 0";
                $stmt3 = $conn->prepare($sql2);
                $stmt3->bind_param("ss", $end_time, $mac_address);
                $stmt3->execute();
                
                file_put_contents('mpesa_log.txt', "Session activated for MAC: $mac_address, ends at: $end_time" . PHP_EOL, FILE_APPEND);
            }
        } else {
            file_put_contents('mpesa_log.txt', "Failed to update payment: " . $conn->error . PHP_EOL, FILE_APPEND);
        }
        
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
    } else {
        // Payment failed
        $sql = "UPDATE payments SET status = 'failed' WHERE transaction_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $checkoutRequestID);
        $stmt->execute();
        
        file_put_contents('mpesa_log.txt', "Payment failed for: $checkoutRequestID - $resultDesc" . PHP_EOL, FILE_APPEND);
        
        echo json_encode(['ResultCode' => $resultCode, 'ResultDesc' => $resultDesc]);
    }
} else {
    file_put_contents('mpesa_log.txt', "Invalid callback structure received" . PHP_EOL, FILE_APPEND);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback data']);
}

$conn->close();

// Helper function to calculate end time
function calculateEndTime($package_id, $conn) {
    $sql = "SELECT duration_hours, is_midnight_package FROM packages WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $package_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $package = $result->fetch_assoc();
    
    $now = new DateTime();
    $now->setTimezone(new DateTimeZone('Africa/Nairobi'));
    
    if ($package['is_midnight_package']) {
        $midnight = new DateTime('tomorrow midnight', new DateTimeZone('Africa/Nairobi'));
        $midnight->modify('-1 second');
        return $midnight->format('Y-m-d H:i:s');
    } else {
        $now->modify('+' . intval($package['duration_hours']) . ' hours');
        return $now->format('Y-m-d H:i:s');
    }
}
?>