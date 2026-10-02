<?php
/**
 * Extra Food Sharing Network (EFSN)
 * One-Click Automated Notification Demonstration Portal
 */

require_once 'config.php';

// Clear any existing session to start fresh
$_SESSION = [];

try {
    // 1. Fetch a test Donor from the database (e.g., Anushka Uttam Gaikwad or other donor)
    $donorStmt = $pdo->query("SELECT * FROM users WHERE role = 'donor' ORDER BY id DESC LIMIT 1");
    $donor = $donorStmt->fetch();
    
    // 2. Fetch a test NGO from the database (e.g., Sanika Uttam Gaikwad or other NGO)
    $ngoStmt = $pdo->query("SELECT * FROM users WHERE role = 'ngo' ORDER BY id DESC LIMIT 1");
    $ngo = $ngoStmt->fetch();
    
    if (!$donor || !$ngo) {
        die("<h3>Demo Error</h3><p>Please make sure you have registered at least one Donor and one NGO in your database before running this demo.</p><a href='login.php'>Go to Registration</a>");
    }

    // 3. STEP 1: Simulate the Donor listing fresh surplus food
    $foodItemName = "Paneer Butter Masala & Roti (" . date('h:i A') . ")";
    $quantity = 40;
    $expiryTime = date('Y-m-d H:i:s', strtotime("+24 hours"));
    $location = "Jakhale Regional Area";
    
    // Insert Listing
    $stmt = $pdo->prepare("INSERT INTO food_listings (food_name, quantity, expiry_time, location, donor_id, status) VALUES (?, ?, ?, ?, ?, 'available')");
    $stmt->execute([$foodItemName, $quantity, $expiryTime, $location, $donor['id']]);
    
    // 4. STEP 2: Generate notifications for all NGOs
    $ngoQuery = $pdo->query("SELECT id FROM users WHERE role = 'ngo'");
    $allNgos = $ngoQuery->fetchAll();
    
    $notificationMsg = "🚨 NEW SURPLUS FOOD: Donor '{$donor['name']}' listed '{$foodItemName}' (Qty: {$quantity}) at '{$location}'. Responding NGOs can claim immediately!";
    
    foreach ($allNgos as $singleNgo) {
        notifyUser($singleNgo['id'], $notificationMsg);
    }
    
    // 5. STEP 3: Automatically log the user in as the Recipient NGO (Sanika Uttam Gaikwad)
    $_SESSION['user_id'] = $ngo['id'];
    $_SESSION['name'] = $ngo['name'];
    $_SESSION['email'] = $ngo['email'];
    $_SESSION['role'] = $ngo['role'];
    $_SESSION['location'] = $ngo['location'];
    
    $_SESSION['success_toast'] = "✨ One-Click Demo Activated: Logged in as NGO '{$ngo['name']}'. Watch your bell icon and bottom-right corner for the real-time broadcast alert!";
    
    // 6. Redirect instantly to the NGO's dashboard to display the real-time notification!
    header("Location: dashboard.php");
    exit;

} catch (PDOException $e) {
    die("Demo Setup Failed: " . $e->getMessage());
}
?>
