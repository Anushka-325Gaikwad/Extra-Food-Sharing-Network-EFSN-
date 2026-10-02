<?php
/**
 * Extra Food Sharing Network (EFSN)
 * Add Surplus Food Listing Portal (Donor Exclusive)
 */
require_once 'config.php';

// Auth Guard: Only Donors allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'donor') {
    header("Location: login.php");
    exit;
}

$errorMsg = '';
$successMsg = '';

// Handle surplus submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $foodName = trim($_POST['food_name']);
    $quantity = intval($_POST['quantity']);
    $location = trim($_POST['location']);
    $expiryHours = intval($_POST['expiry_hours']);
    $expiryMinutes = intval($_POST['expiry_minutes']);
    
    if (empty($foodName) || $quantity <= 0 || empty($location)) {
        $errorMsg = "Please fill in all food details with valid quantities.";
    } else {
        try {
            // Compute MySQL datetime based on current time + user selection
            $totalMinutes = ($expiryHours * 60) + $expiryMinutes;
            if ($totalMinutes <= 0) {
                $errorMsg = "Please specify a valid expiry duration in the future.";
            } else {
                $expiryTime = date('Y-m-d H:i:s', strtotime("+{$totalMinutes} minutes"));
                $donorId = $_SESSION['user_id'];
                $donorName = $_SESSION['name'];
                
                // Insert into food_listings
                $stmt = $pdo->prepare("INSERT INTO food_listings (food_name, quantity, expiry_time, location, donor_id, status) VALUES (?, ?, ?, ?, ?, 'available')");
                $stmt->execute([$foodName, $quantity, $expiryTime, $location, $donorId]);
                $listingId = $pdo->lastInsertId();
                
                // NOTIFICATION: Notify nearby/all NGOs
                // Select all NGO users
                $ngoQuery = $pdo->query("SELECT id, location FROM users WHERE role = 'ngo'");
                $ngos = $ngoQuery->fetchAll();
                
                $notificationMsg = "🚨 NEW SURPLUS FOOD: Donor '{$donorName}' listed '{$foodName}' (Qty: {$quantity}) at '{$location}'. Responding NGOs can claim immediately!";
                foreach ($ngos as $ngo) {
                    // Match location or notify all registered NGOs by default to ensure food doesn't go to waste!
                    $isNearby = empty($ngo['location']) || empty($location) 
                                || (stripos($ngo['location'], $location) !== false) 
                                || (stripos($location, $ngo['location']) !== false);
                    
                    if ($isNearby || true) { 
                        notifyUser($ngo['id'], $notificationMsg);
                    }
                }
                $_SESSION['success_toast'] = "🎉 Food listing '{$foodName}' created successfully! NGOs in your area have been notified.";
                header("Location: dashboard.php");
                exit;
            }
        } catch (PDOException $e) {
            $errorMsg = "Error listing food: " . $e->getMessage();
        }
    }
}
?>

<?php require_once 'header.php'; ?>

<main class="main-content">
    
    <div style="max-width: 600px; margin: 40px auto;">
        
        <div class="panel-header" style="margin-bottom: 25px;">
            <h2 class="panel-title"><i class="fa-solid fa-circle-plus"></i> List Surplus Food</h2>
            <a href="dashboard.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
        </div>

        <div class="glass-container" style="padding: 40px;">
            
            <?php if (!empty($errorMsg)): ?>
                <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 20px; font-size: 0.9rem; color: #fca5a5; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-circle-exclamation" style="color: var(--danger);"></i>
                    <div><?php echo htmlspecialchars($errorMsg); ?></div>
                </div>
            <?php endif; ?>

            <form action="add_food.php" method="POST">
                
                <!-- Food Name -->
                <div class="form-group">
                    <label class="form-label" for="foodName">Food / Dish Item Name</label>
                    <input type="text" name="food_name" id="foodName" class="form-control" placeholder="e.g. Mixed Veg Curry & Roti Packets" required>
                </div>
                
                <!-- Quantity & Location in two columns -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label class="form-label" for="quantity">Quantity (Servings / Packets)</label>
                        <input type="number" name="quantity" id="quantity" class="form-control" placeholder="e.g. 25" min="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="location">Pickup Location Area</label>
                        <input type="text" name="location" id="location" class="form-control" placeholder="e.g. Sector-15, Rohini" value="<?php echo htmlspecialchars(isset($_SESSION['location']) ? $_SESSION['location'] : ''); ?>" required>
                    </div>
                </div>
                
                <!-- Expiration Timer (Hours and Minutes) -->
                <div class="form-group">
                    <label class="form-label">Safe Consumable Duration (Expires In)</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <select name="expiry_hours" class="form-control" required>
                                <option value="0">0 Hours</option>
                                <option value="1">1 Hour</option>
                                <option value="2">2 Hours</option>
                                <option value="3">3 Hours</option>
                                <option value="4">4 Hours</option>
                                <option value="6">6 Hours</option>
                                <option value="12">12 Hours</option>
                                <option value="24" selected>24 Hours</option>
                                <option value="48">48 Hours</option>
                            </select>
                            <span style="font-size: 0.75rem; color: var(--text-muted);">Hours</span>
                        </div>
                        <div>
                            <select name="expiry_minutes" class="form-control" required>
                                <option value="0" selected>0 Minutes</option>
                                <option value="15">15 Minutes</option>
                                <option value="30">30 Minutes</option>
                                <option value="45">45 Minutes</option>
                            </select>
                            <span style="font-size: 0.75rem; color: var(--text-muted);">Minutes</span>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 30px;">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fa-solid fa-paper-plane"></i> Broadcast Surplus & Notify Nearby NGOs
                    </button>
                </div>
                
            </form>
        </div>
    </div>

</main>

</body>
</html>
