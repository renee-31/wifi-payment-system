<?php
// mpesa.php - M-Pesa STK Push Functions for SpeedTon WiFi

class MpesaAPI {
    private $consumerKey;
    private $consumerSecret;
    private $shortcode;
    private $passkey;
    private $environment; // 'sandbox' or 'production'
    
    public function __construct() {
        // FROM YOUR DARAJA APP DASHBOARD
        // Replace with your actual Consumer Key and Secret
        $this->consumerKey = 'bdMm88NFuTmQ1xGLtyfr214PUjf51shZngYVpjmSY3EPs91x';      // ← PASTE YOUR CONSUMER KEY
        $this->consumerSecret = 'r3GtNQI9qmIU9LTGvFybt0A6bsTOQE33HpR51bAGIr4YMMRkw19LkiWmYBRcAjnM'; // ← PASTE YOUR CONSUMER SECRET
        
        // FIXED SANDBOX VALUES (DO NOT CHANGE FOR TESTING)
        $this->shortcode = '174379';
        $this->passkey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
        $this->environment = 'sandbox'; // Change to 'production' when live
    }
    
    // Generate Access Token
    public function generateAccessToken() {
        $url = $this->environment === 'sandbox' 
            ? 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
            : 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        
        $credentials = base64_encode($this->consumerKey . ':' . $this->consumerSecret);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response);
        return $result->access_token;
    }
    
    // Generate STK Push Password
    private function generatePassword() {
        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);
        return ['password' => $password, 'timestamp' => $timestamp];
    }
    
    // Initiate STK Push Payment
    public function stkPush($phoneNumber, $amount, $accountReference, $callbackUrl) {
        $accessToken = $this->generateAccessToken();
        $passwordData = $this->generatePassword();
        
        // Format phone number to 254XXXXXXXXX
        $phoneNumber = $this->formatPhoneNumber($phoneNumber);
        
        $url = $this->environment === 'sandbox'
            ? 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
            : 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
        
        $curl_post_data = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $passwordData['password'],
            'Timestamp' => $passwordData['timestamp'],
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int)$amount,
            'PartyA' => $phoneNumber,
            'PartyB' => $this->shortcode,
            'PhoneNumber' => $phoneNumber,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => $accountReference,
            'TransactionDesc' => 'SpeedTon WiFi Payment'
        ];
        
        $data_string = json_encode($curl_post_data);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
    
    // Format phone number to international format
    private function formatPhoneNumber($phone) {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Remove leading 0 or +254 and replace with 254
        if (substr($phone, 0, 1) == '0') {
            $phone = '254' . substr($phone, 1);
        } elseif (substr($phone, 0, 3) == '254') {
            $phone = $phone;
        } elseif (substr($phone, 0, 4) == '+254') {
            $phone = '254' . substr($phone, 4);
        }
        
        return $phone;
    }
}
?>