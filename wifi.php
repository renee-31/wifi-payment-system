<?php
// ============================================
// COMPLETE PAID WiFi SYSTEM - SpeedTon B6 WiFi
// Single file solution with all features
// ============================================

session_start();

// ========== CONFIGURATION ==========
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'wifi_payment_system');

// Create database and tables if they don't exist
function initDatabase() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Create database
    $conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
    $conn->select_db(DB_NAME);
    
    // Create packages table
    $conn->query("
        CREATE TABLE IF NOT EXISTS packages (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(50),
            duration_hours INT,
            price DECIMAL(10,2),
            is_midnight_package BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Create active sessions table
    $conn->query("
        CREATE TABLE IF NOT EXISTS active_sessions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            mac_address VARCHAR(17) NOT NULL,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(100) NOT NULL,
            package_id INT,
            start_time DATETIME,
            end_time DATETIME NOT NULL,
            is_active BOOLEAN DEFAULT FALSE,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (package_id) REFERENCES packages(id),
            INDEX idx_mac (mac_address),
            INDEX idx_active (is_active)
        )
    ");
    
    // Create payments table
    $conn->query("
        CREATE TABLE IF NOT EXISTS payments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            transaction_id VARCHAR(100) UNIQUE,
            mpesa_code VARCHAR(50),
            mac_address VARCHAR(17),
            package_id INT,
            amount DECIMAL(10,2),
            phone_number VARCHAR(15),
            status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (package_id) REFERENCES packages(id)
        )
    ");
    
    // Create payment_sessions table for tracking STK pushes
    $conn->query("
        CREATE TABLE IF NOT EXISTS payment_sessions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            checkout_request_id VARCHAR(100) UNIQUE,
            merchant_request_id VARCHAR(100),
            session_id INT,
            mac_address VARCHAR(17),
            username VARCHAR(50),
            password VARCHAR(100),
            package_id INT,
            amount DECIMAL(10,2),
            phone_number VARCHAR(15),
            end_time DATETIME,
            status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_checkout (checkout_request_id),
            INDEX idx_status (status)
        )
    ");
    
    // Insert default packages if not exists
    $check = $conn->query("SELECT COUNT(*) as count FROM packages");
    $row = $check->fetch_assoc();
    
    if ($row['count'] == 0) {
        $conn->query("INSERT INTO packages (name, duration_hours, price) VALUES 
            ('1 Hour', 1, 10.00),
            ('3 Hours', 3, 15.00),
            ('24 Hours', 24, 30.00),
            ('3 Days', 72, 55.00),
            ('7 Days', 168, 150.00)
        ");
        $conn->query("INSERT INTO packages (name, duration_hours, price, is_midnight_package) VALUES 
            ('Till Midnight', 0, 150.00, TRUE)
        ");
    }
    
    $conn->close();
}

// Get database connection
function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

// Generate random password
function generatePassword($length = 8) {
    return substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, $length);
}

// Calculate end time based on package
function calculateEndTime($package_id) {
    $conn = getDB();
    $sql = "SELECT * FROM packages WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $package_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $package = $result->fetch_assoc();
    $conn->close();
    
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

// Initialize database
initDatabase();

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Get packages
    if ($action === 'get_packages' || (isset($data['action']) && $data['action'] === 'get_packages')) {
        $conn = getDB();
        $result = $conn->query("SELECT * FROM packages ORDER BY price ASC");
        $packages = [];
        while ($row = $result->fetch_assoc()) {
            $packages[] = $row;
        }
        $conn->close();
        echo json_encode($packages);
        exit;
    }
    
    // Get package details
    if ($action === 'get_package' || (isset($data['action']) && $data['action'] === 'get_package')) {
        $package_id = $data['package_id'] ?? $_POST['package_id'] ?? 0;
        $conn = getDB();
        $stmt = $conn->prepare("SELECT * FROM packages WHERE id = ?");
        $stmt->bind_param("i", $package_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $package = $result->fetch_assoc();
        $conn->close();
        echo json_encode($package);
        exit;
    }
    
    // Process payment (simulated - for fallback)
    if ($action === 'process_payment' || (isset($data['action']) && $data['action'] === 'process_payment')) {
        $package_id = $data['package_id'];
        $mac_address = $data['mac_address'];
        $phone = $data['phone'];
        $amount = $data['amount'];
        
        $conn = getDB();
        
        // Generate unique credentials
        $username = 'USER_' . strtoupper(substr(md5(uniqid()), 0, 8));
        $password = generatePassword(10);
        $end_time = calculateEndTime($package_id);
        
        // Check if MAC already has active session
        $check_sql = "SELECT * FROM active_sessions WHERE mac_address = ? AND is_active = TRUE";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("s", $mac_address);
        $stmt->execute();
        $existing = $stmt->get_result();
        
        if ($existing->num_rows > 0) {
            // Deactivate old session
            $update_sql = "UPDATE active_sessions SET is_active = FALSE WHERE mac_address = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("s", $mac_address);
            $stmt->execute();
        }
        
        // Generate transaction ID
        $transaction_id = 'TXN_' . time() . '_' . rand(1000, 9999);
        $mpesa_code = 'MP' . rand(100000, 999999);
        
        // Record payment
        $payment_sql = "INSERT INTO payments (transaction_id, mpesa_code, mac_address, package_id, amount, phone_number, status) 
                        VALUES (?, ?, ?, ?, ?, ?, 'completed')";
        $stmt = $conn->prepare($payment_sql);
        $stmt->bind_param("ssssds", $transaction_id, $mpesa_code, $mac_address, $package_id, $amount, $phone);
        $stmt->execute();
        
        // Create active session
        $session_sql = "INSERT INTO active_sessions (mac_address, username, password, package_id, start_time, end_time, is_active, ip_address) 
                        VALUES (?, ?, ?, ?, NOW(), ?, TRUE, ?)";
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $stmt = $conn->prepare($session_sql);
        $stmt->bind_param("ssssss", $mac_address, $username, $password, $package_id, $end_time, $ip_address);
        $stmt->execute();
        
        $conn->close();
        
        echo json_encode([
            'success' => true,
            'username' => $username,
            'password' => $password,
            'end_time' => $end_time
        ]);
        exit;
    }
    
    // Check session
    if ($action === 'check_session' || (isset($data['action']) && $data['action'] === 'check_session')) {
        $mac_address = $data['mac_address'] ?? $_POST['mac_address'] ?? '';
        $conn = getDB();
        
        // Expire old sessions
        $conn->query("UPDATE active_sessions SET is_active = FALSE WHERE end_time <= NOW() AND is_active = TRUE");
        
        $sql = "SELECT s.*, p.name as package_name 
                FROM active_sessions s 
                JOIN packages p ON s.package_id = p.id 
                WHERE s.mac_address = ? AND s.is_active = TRUE AND s.end_time > NOW()";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $mac_address);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            echo json_encode([
                'has_active_session' => true,
                'username' => $row['username'],
                'password' => $row['password'],
                'end_time' => $row['end_time']
            ]);
        } else {
            echo json_encode(['has_active_session' => false]);
        }
        $conn->close();
        exit;
    }
    
    // Check session status (for auto-logout)
    if ($action === 'check_status' || (isset($data['action']) && $data['action'] === 'check_status')) {
        $mac_address = $data['mac_address'] ?? '';
        $conn = getDB();
        
        // Expire old sessions
        $conn->query("UPDATE active_sessions SET is_active = FALSE WHERE end_time <= NOW() AND is_active = TRUE");
        
        $sql = "SELECT * FROM active_sessions WHERE mac_address = ? AND is_active = TRUE AND end_time > NOW()";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $mac_address);
        $stmt->execute();
        $result = $stmt->get_result();
        
        echo json_encode(['is_active' => $result->num_rows > 0]);
        $conn->close();
        exit;
    }
    
    exit;
}

// Get MAC address (in production, get from router/CoovaChilli)
$mac_address = isset($_GET['mac']) ? $_GET['mac'] : (isset($_COOKIE['device_mac']) ? $_COOKIE['device_mac'] : '00:00:00:00:00:00');
setcookie('device_mac', $mac_address, time() + (86400 * 30), "/");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpeedTon B6 WiFi - High Speed Internet Access</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .navbar {
            background: rgba(0,0,0,0.95) !important;
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 20px rgba(0,0,0,0.3);
        }

        .navbar-brand {
            font-size: 1.8rem;
            font-weight: bold;
            background: linear-gradient(135deg, #ff6b6b, #ff8e53);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-section {
            text-align: center;
            padding: 60px 20px;
            color: white;
        }

        .hero-section h1 {
            font-size: 3.5rem;
            margin-bottom: 20px;
            animation: fadeInDown 0.8s ease;
            font-weight: bold;
        }

        .hero-section p {
            font-size: 1.2rem;
            opacity: 0.9;
        }

        .stats {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 40px;
            flex-wrap: wrap;
        }

        .stat-item {
            background: rgba(255,255,255,0.1);
            padding: 15px 25px;
            border-radius: 50px;
            backdrop-filter: blur(10px);
        }

        .stat-item i {
            margin-right: 10px;
            font-size: 1.2rem;
        }

        .package-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin: 20px 0;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .package-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        .package-card.popular {
            border: 3px solid #ff6b6b;
            transform: scale(1.02);
        }

        .popular-badge {
            position: absolute;
            top: 20px;
            right: -30px;
            background: #ff6b6b;
            color: white;
            padding: 5px 40px;
            transform: rotate(45deg);
            font-size: 12px;
            font-weight: bold;
        }

        .package-price {
            font-size: 2.5rem;
            font-weight: bold;
            color: #667eea;
            margin: 20px 0;
        }

        .package-price small {
            font-size: 1rem;
            color: #999;
        }

        .package-name {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 15px;
        }

        .package-duration {
            color: #666;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .btn-buy {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: bold;
            width: 100%;
            transition: all 0.3s ease;
        }

        .btn-buy:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .modal-content {
            border-radius: 20px;
        }

        .payment-method {
            border: 2px solid #e0e0e0;
            border-radius: 15px;
            padding: 15px;
            margin: 10px 0;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .payment-method:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }

        .payment-method.selected {
            border-color: #667eea;
            background: #f8f9ff;
        }

        .alert {
            border-radius: 15px;
            animation: slideIn 0.5s ease;
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .credentials-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 30px;
            color: white;
            text-align: center;
        }

        .credentials-code {
            background: rgba(255,255,255,0.2);
            padding: 15px;
            border-radius: 10px;
            font-family: monospace;
            font-size: 1.3rem;
            letter-spacing: 2px;
            margin: 15px 0;
        }

        .btn-copy {
            background: white;
            color: #667eea;
            border: none;
            padding: 10px 20px;
            border-radius: 50px;
            font-weight: bold;
        }

        footer {
            background: rgba(0,0,0,0.95);
            color: white;
            padding: 30px;
            margin-top: 50px;
        }

        .session-timer {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(0,0,0,0.9);
            color: white;
            padding: 15px 20px;
            border-radius: 50px;
            z-index: 1000;
            display: none;
            font-size: 14px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        @media (max-width: 768px) {
            .hero-section h1 {
                font-size: 2rem;
            }
            .package-card {
                margin: 10px 0;
            }
            .stats {
                gap: 10px;
            }
            .stat-item {
                padding: 8px 15px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-wifi"></i> SpeedTon B6 WiFi
            </a>
            <div>
                <span class="text-white">
                    <i class="fas fa-map-marker-alt"></i> High Speed Internet
                </span>
            </div>
        </div>
    </nav>

    <div class="hero-section">
        <div class="container">
            <h1>Welcome to High-Speed WiFi</h1>
            <p>Fast, reliable, and secure internet access at affordable prices</p>
            <div class="stats">
                <div class="stat-item"><i class="fas fa-tachometer-alt"></i> Up to 100Mbps</div>
                <div class="stat-item"><i class="fas fa-shield-alt"></i> Secure Connection</div>
                <div class="stat-item"><i class="fas fa-clock"></i> 24/7 Support</div>
                <div class="stat-item"><i class="fas fa-mobile-alt"></i> M-Pesa Accepted</div>
            </div>
        </div>
    </div>

    <div class="container">
        <div id="packages-container" class="row">
            <!-- Packages will be loaded dynamically here -->
        </div>
    </div>

    <!-- Payment Modal -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Complete Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="selected-package-info"></div>
                    
                    <h6 class="mt-4">Select Payment Method</h6>
                    <div class="payment-method" onclick="selectPaymentMethod('mpesa')">
                        <div class="row align-items-center">
                            <div class="col-2">
                                <i class="fas fa-mobile-alt fa-2x" style="color: #4CAF50;"></i>
                            </div>
                            <div class="col-10">
                                <strong>M-Pesa</strong><br>
                                <small>Pay using Safaricom M-Pesa - Fast & Secure</small>
                            </div>
                        </div>
                    </div>

                    <div id="mpesa-form" style="display: none;" class="mt-3">
                        <div class="form-group">
                            <label>M-Pesa Phone Number</label>
                            <input type="tel" id="phone" class="form-control" placeholder="0712345678">
                            <small class="text-muted">You will receive a prompt on your phone to complete payment</small>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button class="btn btn-success w-100" onclick="processPayment()">
                            <i class="fas fa-check-circle"></i> Complete Purchase
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Payment Successful!</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="credentials-card">
                        <i class="fas fa-check-circle fa-3x mb-3"></i>
                        <h4>Access Granted!</h4>
                        <p>Use these credentials to connect:</p>
                        <div class="credentials-code" id="credentials-display">
                            <!-- Credentials will be shown here -->
                        </div>
                        <p class="mt-3">
                            <i class="fas fa-hourglass-half"></i> 
                            <span id="expiry-time"></span>
                        </p>
                        <button class="btn-copy" onclick="copyCredentials()">
                            <i class="fas fa-copy"></i> Copy Credentials
                        </button>
                    </div>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Important:</strong> These credentials are valid only on this device. 
                        Please save them for future reference.
                    </div>
                    <div class="text-center mt-3">
                        <button class="btn btn-primary" onclick="window.location.reload()">
                            <i class="fas fa-wifi"></i> Connect Now
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Waiting Modal -->
    <div class="modal fade" id="waitingModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Processing Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="spinner-border text-info mb-3" role="status" style="width: 3rem; height: 3rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <h5>Waiting for M-Pesa payment</h5>
                    <p>Please check your phone and enter your M-Pesa PIN to complete the payment.</p>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-mobile-alt"></i> 
                        You will receive a prompt on your phone
                    </div>
                    <div id="paymentStatusMessage" class="mt-3"></div>
                    <button class="btn btn-secondary mt-3" onclick="cancelPayment()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Session Timer -->
    <div class="session-timer" id="sessionTimer">
        <i class="fas fa-clock"></i> Session expires in: <span id="timerDisplay">--:--:--</span>
    </div>

    <div class="loading" id="loading">
        <div class="spinner"></div>
    </div>

    <footer>
        <div class="container text-center">
            <p>&copy; 2024 SpeedTon B6 WiFi. All rights reserved.</p>
            <small>Terms & Conditions Apply | 24/7 Customer Support: 0115777875/0111207434</small>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedPackage = null;
        let selectedMethod = null;
        let macAddress = '<?php echo $mac_address; ?>';
        let sessionCheckInterval = null;
        let pollInterval = null;

        // Load packages on page load
        $(document).ready(function() {
            loadPackages();
            checkActiveSession();
        });

        function loadPackages() {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: JSON.stringify({action: 'get_packages'}),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    console.log('Packages loaded:', response);
                    let packages = response;
                    
                    if (typeof packages === 'string') {
                        packages = JSON.parse(packages);
                    }
                    
                    let html = '';
                    packages.forEach((pkg, index) => {
                        let popularClass = '';
                        let popularBadge = '';
                        if (pkg.name === '24 Hours') {
                            popularClass = 'popular';
                            popularBadge = '<div class="popular-badge">Most Popular</div>';
                        }
                        
                        let priceDisplay = `KES ${parseFloat(pkg.price).toLocaleString()}`;
                        let durationText = pkg.is_midnight_package ? 'Valid Until Midnight (11:59 PM)' : 
                                         (pkg.duration_hours >= 24 ? `${pkg.duration_hours/24} Day${pkg.duration_hours/24 > 1 ? 's' : ''}` : 
                                         `${pkg.duration_hours} Hour${pkg.duration_hours > 1 ? 's' : ''}`);
                        
                        html += `
                            <div class="col-md-4 col-lg-4">
                                <div class="package-card ${popularClass}" onclick="selectPackage(${pkg.id})">
                                    ${popularBadge}
                                    <div class="package-name">${pkg.name}</div>
                                    <div class="package-duration">
                                        <i class="far fa-clock"></i> ${durationText}
                                    </div>
                                    <div class="package-price">
                                        ${priceDisplay}
                                        <small>KES</small>
                                    </div>
                                    <button class="btn-buy">
                                        <i class="fas fa-shopping-cart"></i> Buy Now
                                    </button>
                                </div>
                            </div>
                        `;
                    });
                    $('#packages-container').html(html);
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load packages:', error);
                    showStaticPackages();
                }
            });
        }

        function showStaticPackages() {
            const packages = [
                {id: 2, name: '3 Hours', duration_hours: 3, price: 15, is_midnight_package: false},
                {id: 3, name: '24 Hours', duration_hours: 24, price: 30, is_midnight_package: false},
                {id: 4, name: '3 Days', duration_hours: 72, price: 55, is_midnight_package: false},
                {id: 5, name: '7 Days', duration_hours: 168, price: 150, is_midnight_package: false},
                {id: 6, name: 'Till Midnight', duration_hours: 0, price: 150, is_midnight_package: true}
            ];
            
            let html = '';
            packages.forEach((pkg) => {
                let popularClass = pkg.name === '24 Hours' ? 'popular' : '';
                let popularBadge = pkg.name === '24 Hours' ? '<div class="popular-badge">Most Popular</div>' : '';
                let durationText = pkg.is_midnight_package ? 'Valid Until Midnight (11:59 PM)' : 
                                 `${pkg.duration_hours} Hour${pkg.duration_hours > 1 ? 's' : ''}`;
                
                html += `
                    <div class="col-md-4 col-lg-4">
                        <div class="package-card ${popularClass}" onclick="selectPackageStatic(${pkg.id}, '${pkg.name}', ${pkg.duration_hours}, ${pkg.price})">
                            ${popularBadge}
                            <div class="package-name">${pkg.name}</div>
                            <div class="package-duration">
                                <i class="far fa-clock"></i> ${durationText}
                            </div>
                            <div class="package-price">
                                KES ${pkg.price}<small>KES</small>
                            </div>
                            <button class="btn-buy">
                                <i class="fas fa-shopping-cart"></i> Buy Now
                            </button>
                        </div>
                    </div>
                `;
            });
            $('#packages-container').html(html);
        }

        function selectPackage(packageId) {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: JSON.stringify({action: 'get_package', package_id: packageId}),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    selectedPackage = response;
                    if (typeof selectedPackage === 'string') {
                        selectedPackage = JSON.parse(selectedPackage);
                    }
                    
                    let durationText = selectedPackage.is_midnight_package ? 'Valid Until Midnight' : 
                                     `${selectedPackage.duration_hours} Hour${selectedPackage.duration_hours > 1 ? 's' : ''}`;
                    
                    $('#selected-package-info').html(`
                        <div class="alert alert-info">
                            <strong><i class="fas fa-gem"></i> ${selectedPackage.name}</strong><br>
                            <i class="fas fa-tag"></i> Price: <strong>KES ${parseFloat(selectedPackage.price).toLocaleString()}</strong><br>
                            <i class="fas fa-hourglass-half"></i> Duration: ${durationText}
                        </div>
                    `);
                    $('#paymentModal').modal('show');
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load package:', error);
                    alert('Error loading package details. Please try again.');
                }
            });
        }

        function selectPackageStatic(id, name, hours, price) {
            selectedPackage = {
                id: id,
                name: name,
                duration_hours: hours,
                price: price,
                is_midnight_package: (name === 'Till Midnight')
            };
            
            let durationText = selectedPackage.is_midnight_package ? 'Valid Until Midnight' : 
                             `${selectedPackage.duration_hours} Hour${selectedPackage.duration_hours > 1 ? 's' : ''}`;
            
            $('#selected-package-info').html(`
                <div class="alert alert-info">
                    <strong><i class="fas fa-gem"></i> ${selectedPackage.name}</strong><br>
                    <i class="fas fa-tag"></i> Price: <strong>KES ${price.toLocaleString()}</strong><br>
                    <i class="fas fa-hourglass-half"></i> Duration: ${durationText}
                </div>
            `);
            $('#paymentModal').modal('show');
        }
        
        function selectPaymentMethod(method) {
            selectedMethod = method;
            $('.payment-method').removeClass('selected');
            $(`.payment-method:has(i.fa-mobile-alt)`).addClass('selected');
            
            if (method === 'mpesa') {
                $('#mpesa-form').show();
            }
        }

        function processPayment() {
            if (!selectedPackage) {
                alert('Please select a package first');
                return;
            }

            if (!selectedMethod) {
                alert('Please select payment method');
                return;
            }

            const phone = $('#phone').val();
            if (!phone || phone.length < 10) {
                alert('Please enter a valid M-Pesa phone number');
                return;
            }

            $('#loading').css('display', 'flex');

            const paymentData = {
                package_id: selectedPackage.id,
                mac_address: macAddress,
                phone: phone,
                amount: selectedPackage.price
            };

            $.ajax({
                url: 'initiate_payment.php',
                method: 'POST',
                data: JSON.stringify(paymentData),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    $('#loading').hide();
                    
                    if (response.success) {
                        $('#paymentModal').modal('hide');
                        $('#waitingModal').modal('show');
                        startPollingPaymentStatus(response.checkoutRequestID);
                    } else {
                        alert('Payment initiation failed: ' + (response.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    $('#loading').hide();
                    console.error('AJAX Error:', error);
                    alert('Failed to initiate payment. Please try again.\nError: ' + error);
                }
            });
        }

        function startPollingPaymentStatus(checkoutRequestID) {
            let attempts = 0;
            const maxAttempts = 60;
            
            if (pollInterval) {
                clearInterval(pollInterval);
            }
            
            pollInterval = setInterval(function() {
                attempts++;
                
                $.ajax({
                    url: 'check_payment_status.php',
                    method: 'POST',
                    data: JSON.stringify({checkoutRequestID: checkoutRequestID}),
                    contentType: 'application/json',
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'completed') {
                            clearInterval(pollInterval);
                            $('#waitingModal').modal('hide');
                            showCredentials(response.username, response.password, response.end_time);
                            startSessionMonitoring(response.end_time);
                        } else if (response.status === 'failed') {
                            clearInterval(pollInterval);
                            $('#waitingModal').modal('hide');
                            alert('Payment failed. Please try again.');
                        } else {
                            $('#paymentStatusMessage').html(`
                                <small class="text-muted">
                                    Waiting for payment confirmation... (${attempts}/${maxAttempts})
                                </small>
                            `);
                        }
                    },
                    error: function() {
                        if (attempts >= maxAttempts) {
                            clearInterval(pollInterval);
                            $('#waitingModal').modal('hide');
                            alert('Payment confirmation timeout. Please check your payment status.');
                        }
                    }
                });
                
                if (attempts >= maxAttempts) {
                    clearInterval(pollInterval);
                }
            }, 2000);
        }

        function cancelPayment() {
            if (pollInterval) {
                clearInterval(pollInterval);
            }
            $('#waitingModal').modal('hide');
            alert('Payment cancelled. You can try again.');
        }

        function showCredentials(username, password, endTime) {
            $('#credentials-display').html(`
                <strong>Username:</strong> ${username}<br>
                <strong>Password:</strong> ${password}
            `);
            $('#expiry-time').text('Valid until: ' + new Date(endTime).toLocaleString());
            $('#successModal').modal('show');
            
            localStorage.setItem('wifi_username', username);
            localStorage.setItem('wifi_password', password);
            localStorage.setItem('wifi_expiry', endTime);
        }

        function copyCredentials() {
            const text = $('#credentials-display').text();
            navigator.clipboard.writeText(text).then(() => {
                alert('Credentials copied to clipboard!');
            }).catch(() => {
                alert('Please copy manually: ' + text);
            });
        }

        function checkActiveSession() {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: JSON.stringify({action: 'check_session', mac_address: macAddress}),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    let result = response;
                    if (typeof result === 'string') {
                        result = JSON.parse(result);
                    }
                    
                    if (result.has_active_session) {
                        showCredentials(result.username, result.password, result.end_time);
                        startSessionMonitoring(result.end_time);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Session check failed:', error);
                }
            });
        }

        function startSessionMonitoring(endTime) {
            const expiryDate = new Date(endTime);
            
            function updateTimer() {
                const now = new Date();
                const diff = expiryDate - now;
                
                if (diff <= 0) {
                    clearInterval(sessionCheckInterval);
                    $('#sessionTimer').fadeOut();
                    alert('Your WiFi session has expired. Please purchase a new package to continue.');
                    location.reload();
                    return;
                }
                
                const hours = Math.floor(diff / (1000 * 60 * 60));
                const minutes = Math.floor((diff % (3600000)) / 60000);
                const seconds = Math.floor((diff % 60000) / 1000);
                
                $('#timerDisplay').text(
                    `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
                );
                $('#sessionTimer').fadeIn();
            }
            
            updateTimer();
            sessionCheckInterval = setInterval(updateTimer, 1000);
            
            setInterval(() => {
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: JSON.stringify({action: 'check_status', mac_address: macAddress}),
                    contentType: 'application/json',
                    dataType: 'json',
                    success: function(response) {
                        let result = response;
                        if (typeof result === 'string') {
                            result = JSON.parse(result);
                        }
                        
                        if (!result.is_active) {
                            clearInterval(sessionCheckInterval);
                            alert('Your session has expired. Please purchase a new package.');
                            location.reload();
                        }
                    }
                });
            }, 30000);
        }

        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                checkActiveSession();
            }
        });
    </script>
</body>
</html>