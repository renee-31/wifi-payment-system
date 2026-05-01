<?php
// callback.php - Receives M-Pesa payment confirmation

$conn = new mysqli('localhost', 'root', '', 'wifi_payment_system');

// Get callback data
$callbackJSONData = file_get_contents('php://input');
$callbackData = json_decode($callbackJSONData, true);

// Log for debugging
file_put_contents('mpesa_log.txt', date('Y-m-d H:i:s') . ': ' . $callbackJSONData . PHP_EOL, FILE_APPEND);

if (isset($callbackData['Body']['stkCallback'])) {
    $stkCallback = $callbackData['Body']['stkCallback'];
    $resultCode = $stkCallback['ResultCode'];
    $checkoutRequestID = $stkCallback['CheckoutRequestID'];
    
    if ($resultCode == 0) {
        // Payment successful
        $metadata = $stkCallback['CallbackMetadata']['Item'];
        $mpesaReceiptNumber = '';
        
        foreach ($metadata as $item) {
            if ($item['Name'] == 'MpesaReceiptNumber') {
                $mpesaReceiptNumber = $item['Value'];
            }
        }
        
        // Update payment record
        $sql = "UPDATE payments SET status = 'completed', mpesa_code = '$mpesaReceiptNumber' 
                WHERE transaction_id = '$checkoutRequestID'";
        $conn->query($sql);
        
        // Activate user session
        $sql2 = "UPDATE active_sessions SET is_active = 1, start_time = NOW() 
                 WHERE mac_address IN (SELECT mac_address FROM payments WHERE transaction_id = '$checkoutRequestID')";
        $conn->query($sql2);
        
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
    } else {
        // Payment failed
        $sql = "UPDATE payments SET status = 'failed' WHERE transaction_id = '$checkoutRequestID'";
        $conn->query($sql);
        
        echo json_encode(['ResultCode' => $resultCode, 'ResultDesc' => 'Failed']);
    }
}

$conn->close();
?>