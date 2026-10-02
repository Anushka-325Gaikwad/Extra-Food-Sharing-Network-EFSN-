<?php

require_once 'config.php';

// Auth Guard: Force login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$userName = $_SESSION['name'];
$userLoc = isset($_SESSION['location']) ? $_SESSION['location'] : 'Operating Location';

$errorMsg = '';
$successMsg = '';

// Handle Donor deleting a food listing
if ($userRole === 'donor' && isset($_GET['action']) && $_GET['action'] === 'delete_food') {
    $foodId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($foodId > 0) {
        try {
            // Verify ownership
            $stmt = $pdo->prepare("SELECT donor_id, food_name FROM food_listings WHERE id = ?");
            $stmt->execute([$foodId]);
            $listing = $stmt->fetch();
            
            if ($listing && $listing['donor_id'] == $userId) {
                // Delete listing
                $delStmt = $pdo->prepare("DELETE FROM food_listings WHERE id = ?");
                $delStmt->execute([$foodId]);
                $successMsg = "🗑️ Listing '{$listing['food_name']}' successfully removed.";
            } else {
                $errorMsg = "Unauthorized action. You do not own this listing.";
            }
        } catch (PDOException $e) {
            $errorMsg = "Failed to delete listing: " . $e->getMessage();
        }
    }
}

// Fetch user reward points dynamically
$userPoints = 0;
$userContact = '';
try {
    $stmt = $pdo->prepare("SELECT points FROM rewards WHERE user_id = ?");
    $stmt->execute([$userId]);
    $reward = $stmt->fetch();
    if ($reward) {
        $userPoints = $reward['points'];
    }

    $stmtContact = $pdo->prepare("SELECT contact FROM users WHERE id = ?");
    $stmtContact->execute([$userId]);
    $userContact = $stmtContact->fetchColumn();
} catch (PDOException $e) {
    //
}

require_once 'header.php';
?>

<main class="main-content">
    
    <!-- Toast notifications check from session redirection -->
    <?php if (isset($_SESSION['success_toast'])): ?>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                showToast("<?php echo $_SESSION['success_toast']; ?>", "success");
            });
        </script>
        <?php unset($_SESSION['success_toast']); ?>
    <?php endif; ?>
    
    <?php if (!empty($successMsg)): ?>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                showToast("<?php echo $successMsg; ?>", "success");
            });
        </script>
    <?php endif; ?>
    
    <?php if (!empty($errorMsg)): ?>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                showToast("<?php echo $errorMsg; ?>", "danger");
            });
        </script>
    <?php endif; ?>

    <div class="dashboard-grid">
        
        <!-- ================= LEFT COLUMN: PROFILE CARD ================= -->
        <aside class="profile-card glass-container">
            <div class="avatar-circle">
                <?php echo strtoupper(substr($userName, 0, 1)); ?>
            </div>
            
            <h3 class="profile-name"><?php echo htmlspecialchars($userName); ?></h3>
            <span class="role-badge role-<?php echo $userRole; ?>">
                <?php echo ucfirst($userRole); ?> Role
            </span>
            
            <div style="margin-top: 15px; font-size: 0.9rem; color: var(--text-secondary);">
                <i class="fa-solid fa-location-dot" style="color: var(--primary);"></i> 
                <?php echo htmlspecialchars($userLoc); ?>
            </div>

            <?php if (!empty($userContact)): ?>
                <div style="margin-top: 8px; font-size: 0.9rem; color: var(--text-secondary);">
                    <i class="fa-solid fa-phone" style="color: var(--accent);"></i> 
                    <?php echo htmlspecialchars($userContact); ?>
                </div>
            <?php endif; ?>
            <?php if ($userRole !== 'ngo'): ?>
            <div class="profile-points-display">
                <i class="fa-solid fa-trophy" style="color: var(--accent); font-size: 1.2rem;"></i>
                <div>
                    <span style="font-size: 0.78rem; display: block; color: var(--text-secondary); text-transform: uppercase;">Impact Score</span>
                    <strong class="points-text" style="font-size: 1.1rem;"><?php echo $userPoints; ?> Points</strong>
                </div>
            </div>
            <?php endif; ?>

            <!-- Contextual Quick Action buttons -->
            <div style="margin-top: 25px; display: flex; flex-direction: column; gap: 10px;">
                <?php if ($userRole === 'donor'): ?>
                    <a href="add_food.php" class="btn btn-primary" style="width: 100%;">
                        <i class="fa-solid fa-plus"></i> List Surplus Food
                    </a>
                <?php elseif ($userRole === 'ngo'): ?>
                    <a href="search.php" class="btn btn-primary" style="width: 100%;">
                        <i class="fa-solid fa-magnifying-glass"></i> Search Surplus
                    </a>
                <?php endif; ?>
                <a href="index.php" class="btn btn-outline" style="width: 100%;">
                    <i class="fa-solid fa-crown"></i> View Leaderboard
                </a>
            </div>

            <!-- E-Certificate of Recognition Badge -->
            <?php if (($userRole === 'donor' || $userRole === 'volunteer') && $userPoints > 0): ?>
                <div style="margin-top: 20px; padding: 15px; background: rgba(251, 191, 36, 0.08); border: 1px dashed var(--accent); border-radius: var(--radius-sm); text-align: center; animation: border-flash 2s infinite;">
                    <i class="fa-solid fa-certificate" style="color: var(--accent); font-size: 1.6rem; margin-bottom: 5px; display: inline-block; text-shadow: 0 0 10px var(--accent-glow);"></i>
                    <div style="font-size: 0.85rem; font-weight: 700; color: #ffffff; margin-bottom: 4px; font-family: var(--font-display);">E-Certificate Unlocked!</div>
                    <div style="font-size: 0.72rem; color: var(--text-secondary); margin-bottom: 12px;">Thank you for your active impact in our network!</div>
                    <a href="certificate.php" class="btn btn-accent btn-sm" style="width: 100%;" target="_blank">
                        <i class="fa-solid fa-award"></i> Claim Certificate
                    </a>
                </div>
            <?php endif; ?>
        </aside>

        <!-- ================= RIGHT COLUMN: INTERACTIVE PANELS ================= -->
        <section class="dashboard-panel">

            <!-- ----------------- 1. DONOR DASHBOARD VIEW ----------------- -->
            <?php if ($userRole === 'donor'): 
                // Fetch donor's active and historical listings
                try {
                    $activeStmt = $pdo->prepare("SELECT * FROM food_listings WHERE donor_id = ? AND status = 'available' ORDER BY expiry_time ASC");
                    $activeStmt->execute([$userId]);
                    $activeListings = $activeStmt->fetchAll();

                    $historyStmt = $pdo->prepare("
                        SELECT f.*, c.id as claim_id, c.status as claim_status, c.quantity as claim_qty, u.name as ngo_name,
                               (SELECT rating FROM bhandi_ratings WHERE claim_id = c.id LIMIT 1) as donor_rating
                        FROM food_listings f
                        LEFT JOIN claims c ON c.food_listing_id = f.id
                        LEFT JOIN users u ON c.ngo_id = u.id
                        WHERE f.donor_id = ? AND f.status != 'available' 
                        ORDER BY f.created_at DESC, c.id ASC
                    ");
                    $historyStmt->execute([$userId]);
                    $rawHistory = $historyStmt->fetchAll();

                    // Group by food_listing_id to compute running sums
                    $grouped = [];
                    foreach ($rawHistory as $row) {
                        $fid = $row['id'];
                        if (!isset($grouped[$fid])) {
                            $grouped[$fid] = [
                                'listing_qty' => intval($row['quantity']),
                                'rows' => []
                            ];
                        }
                        $grouped[$fid]['rows'][] = $row;
                    }

                    // Compute available_before for each row in the group
                    $historyListings = [];
                    foreach ($grouped as $fid => $group) {
                        $runningSum = $group['listing_qty'];
                        $rowsCount = count($group['rows']);
                        // Run in reverse to compute running sum
                        for ($i = $rowsCount - 1; $i >= 0; $i--) {
                            if ($group['rows'][$i]['claim_qty'] !== null) {
                                $runningSum += intval($group['rows'][$i]['claim_qty']);
                                $group['rows'][$i]['available_before'] = $runningSum;
                            } else {
                                $group['rows'][$i]['available_before'] = $group['listing_qty'];
                            }
                        }
                        
                        // Append to flat history list
                        foreach ($group['rows'] as $row) {
                            $historyListings[] = $row;
                        }
                    }
                } catch (PDOException $e) {
                    $activeListings = [];
                    $historyListings = [];
                }
            ?>
                <!-- Active Surplus panel -->
                <div class="glass-container" style="padding: 30px;">
                    <div class="panel-header" style="margin-bottom: 20px;">
                        <h3 class="panel-title">Active Surplus Listings</h3>
                        <span style="font-size: 0.85rem; color: var(--text-secondary);"><?php echo count($activeListings); ?> Active Items</span>
                    </div>

                    <?php if (count($activeListings) === 0): ?>
                        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fa-solid fa-utensils" style="font-size: 3rem; margin-bottom: 12px; opacity: 0.25;"></i>
                            <p>You have no active surplus food listings at the moment.</p>
                            <a href="add_food.php" class="btn btn-primary btn-sm" style="margin-top: 15px;">List Food Now</a>
                        </div>
                    <?php else: ?>
                        <div class="grid-cards">
                            <?php foreach ($activeListings as $listing): 
                                // Compute seconds until expiry
                                $diffSec = strtotime($listing['expiry_time']) - time();
                                $hLeft = max(0, floor($diffSec / 3600));
                                $mLeft = max(0, floor(($diffSec % 3600) / 60));
                                $isExpiringSoon = ($diffSec <= 7200);
                            ?>
                                <div class="glass-card food-card <?php echo $isExpiringSoon ? 'expiring-soon' : ''; ?>">
                                    <div class="card-header">
                                        <h4 class="food-title"><?php echo htmlspecialchars($listing['food_name']); ?></h4>
                                        <span class="food-qty"><?php echo $listing['quantity']; ?> Servings</span>
                                    </div>

                                    <div class="food-info-item">
                                        <i class="fa-solid fa-location-dot"></i>
                                        <span><?php echo htmlspecialchars($listing['location']); ?></span>
                                    </div>

                                    <div class="food-info-item <?php echo $isExpiringSoon ? 'expiry-warn' : ''; ?>">
                                        <i class="fa-solid fa-clock"></i>
                                        <span>
                                            <?php if ($isExpiringSoon): ?>
                                                ⚠️ Expiry Alert: <?php echo "{$hLeft}h {$mLeft}m"; ?> left!
                                            <?php else: ?>
                                                Expires in: <?php echo "{$hLeft}h {$mLeft}m"; ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>

                                    <div class="card-actions">
                                        <a href="dashboard.php?action=delete_food&id=<?php echo $listing['id']; ?>" 
                                           onclick="return confirm('Are you sure you want to permanently delete this surplus food listing?')" 
                                           class="btn btn-danger btn-sm" style="width: 100%;">
                                            <i class="fa-solid fa-trash-can"></i> Delete Listing
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Donation History -->
                <div class="glass-container" style="padding: 30px;">
                    <h3 class="panel-title" style="margin-bottom: 20px;">Donation & Rescue History</h3>
                    
                    <?php if (count($historyListings) === 0): ?>
                        <p style="text-align: center; color: var(--text-muted); padding: 20px;">No historical listings found.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="leaderboard-table">
                                <thead>
                                    <tr>
                                        <th>Food Item</th>
                                        <th>Quantity</th>
                                        <th>Status</th>
                                        <th>Recipient NGO</th>
                                        <th>Action Date</th>
                                        <th>Rating</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($historyListings as $listing): 
                                        if (!empty($listing['claim_qty'])) {
                                            $displayQty = $listing['claim_qty'] . " / " . $listing['available_before'];
                                        } else {
                                            $displayQty = $listing['quantity'] . " / " . $listing['quantity'];
                                        }
                                        $displayStatus = !empty($listing['claim_status']) ? $listing['claim_status'] : $listing['status'];
                                    ?>
                                        <tr>
                                            <td style="font-weight: 600;"><?php echo htmlspecialchars($listing['food_name']); ?></td>
                                            <td><?php echo $displayQty; ?> Servings</td>
                                            <td>
                                                <span class="status-badge status-<?php echo $displayStatus; ?>">
                                                    <?php echo $displayStatus; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo !empty($listing['ngo_name']) ? htmlspecialchars($listing['ngo_name']) : '<span style="color: var(--text-muted);">Unclaimed</span>'; ?>
                                            </td>
                                            <td style="color: var(--text-muted); font-size: 0.85rem;"><?php echo $listing['created_at']; ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($listing['donor_rating'])) {
                                                    $r = intval($listing['donor_rating']);
                                                    echo '<span style="color: var(--accent); font-weight: bold;"><i class="fa-solid fa-star"></i> ' . $r . ' ★</span>';
                                                } elseif (!empty($listing['claim_id']) && $listing['claim_status'] === 'delivered') {
                                                    echo '<span style="color: var(--text-muted); font-size: 0.85rem;">Pending Feedback</span>';
                                                } else {
                                                    echo '<span style="color: var(--text-muted); font-size: 0.85rem;">-</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>


            <!-- ----------------- 2. NGO DASHBOARD VIEW ----------------- -->
            <?php if ($userRole === 'ngo'): 
                // Fetch NGO's claimed and active food lists
                try {
                    $claimsStmt = $pdo->prepare("
                        SELECT c.id as claim_id, c.status as delivery_status, c.quantity as quantity, f.food_name, f.location as pickup_loc, u.name as volunteer_name
                        FROM claims c
                        JOIN food_listings f ON c.food_listing_id = f.id
                        LEFT JOIN users u ON c.volunteer_id = u.id
                        WHERE c.ngo_id = ? AND c.status != 'delivered'
                        ORDER BY c.created_at DESC
                    ");
                    $claimsStmt->execute([$userId]);
                    $activeClaims = $claimsStmt->fetchAll();

                    $ngoHistoryStmt = $pdo->prepare("
                        SELECT c.status as delivery_status, c.quantity as quantity, f.food_name, f.location as pickup_loc, c.created_at, u.name as volunteer_name, d.name as donor_name
                        FROM claims c
                        JOIN food_listings f ON c.food_listing_id = f.id
                        LEFT JOIN users u ON c.volunteer_id = u.id
                        LEFT JOIN users d ON f.donor_id = d.id
                        WHERE c.ngo_id = ? AND c.status = 'delivered'
                        ORDER BY c.created_at DESC LIMIT 10
                    ");
                    $ngoHistoryStmt->execute([$userId]);
                    $ngoHistory = $ngoHistoryStmt->fetchAll();
                } catch (PDOException $e) {
                    $activeClaims = [];
                    $ngoHistory = [];
                }
            ?>
                <!-- Active Claims -->
                <div class="glass-container" style="padding: 30px;">
                    <div class="panel-header" style="margin-bottom: 20px;">
                        <h3 class="panel-title">Active Claims & Delivery Status</h3>
                        <a href="search.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Search Surplus Food</a>
                    </div>

                    <?php if (count($activeClaims) === 0): ?>
                        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fa-solid fa-truck" style="font-size: 3rem; margin-bottom: 12px; opacity: 0.25;"></i>
                            <p>You have no active claims or deliveries currently in progress.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="leaderboard-table">
                                <thead>
                                    <tr>
                                        <th>Food Item</th>
                                        <th>Quantity</th>
                                        <th>Pickup Location</th>
                                        <th>Rescuer / Volunteer</th>
                                        <th style="text-align: right;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeClaims as $claim): ?>
                                        <tr>
                                            <td style="font-weight: 600;"><?php echo htmlspecialchars($claim['food_name']); ?></td>
                                            <td><?php echo $claim['quantity']; ?> Servings</td>
                                            <td><?php echo htmlspecialchars($claim['pickup_loc']); ?></td>
                                            <td>
                                                <i class="fa-solid fa-circle-user" style="color: var(--accent); margin-right: 5px;"></i>
                                                <?php echo !empty($claim['volunteer_name']) ? htmlspecialchars($claim['volunteer_name']) : '<span style="color: var(--text-muted);">Awaiting Rescuer</span>'; ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <span class="status-badge status-<?php echo $claim['delivery_status']; ?>">
                                                    <?php echo $claim['delivery_status']; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Claim History -->
                <div class="glass-container" style="padding: 30px;">
                    <h3 class="panel-title" style="margin-bottom: 20px;">Completed Rescue History</h3>
                    
                    <?php if (count($ngoHistory) === 0): ?>
                        <p style="text-align: center; color: var(--text-muted); padding: 20px;">No delivery logs found.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="leaderboard-table">
                                <thead>
                                    <tr>
                                        <th>Food Item</th>
                                        <th>Donor</th>
                                        <th>Quantity Saved</th>
                                        <th>Pickup Location</th>
                                        <th>Volunteer Partner</th>
                                        <th>Completed Date</th>
                                        <th style="text-align: right;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ngoHistory as $history): ?>
                                        <tr>
                                            <td style="font-weight: 600;"><?php echo htmlspecialchars($history['food_name']); ?></td>
                                            <td>
                                                <i class="fa-solid fa-circle-user" style="color: var(--accent); margin-right: 5px;"></i>
                                                <?php echo htmlspecialchars($history['donor_name'] ?? 'Unknown'); ?>
                                            </td>
                                            <td><?php echo $history['quantity']; ?> Servings</td>
                                            <td><?php echo htmlspecialchars($history['pickup_loc']); ?></td>
                                            <td><?php echo !empty($history['volunteer_name']) ? htmlspecialchars($history['volunteer_name']) : '<span style="color: var(--text-muted);">None</span>'; ?></td>
                                            <td style="color: var(--text-muted); font-size: 0.85rem;"><?php echo $history['created_at']; ?></td>
                                            <td style="text-align: right;">
                                                <span class="status-badge status-delivered">
                                                    Delivered
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>


            <!-- ----------------- 3. VOLUNTEER DASHBOARD VIEW ----------------- -->
            <?php if ($userRole === 'volunteer'): 
                // Fetch Available delivery tasks, currently active tasks, and history
                try {
                    // Available tasks: Claims with 'claimed' status and no volunteer_id
                    $availStmt = $pdo->query("
                        SELECT c.id as claim_id, f.food_name, c.quantity as quantity, f.location as pickup_loc, n.location as dest_loc, n.name as ngo_name, d.name as donor_name, n.contact as ngo_contact, d.contact as donor_contact
                        FROM claims c
                        JOIN food_listings f ON c.food_listing_id = f.id
                        JOIN users n ON c.ngo_id = n.id
                        JOIN users d ON f.donor_id = d.id
                        WHERE c.status = 'claimed' AND c.volunteer_id IS NULL
                        ORDER BY c.created_at ASC
                    ");
                    $availableTasks = $availStmt->fetchAll();

                    // Current Active Task assigned to this volunteer (limit 1 for sequential focus)
                    $activeStmt = $pdo->prepare("
                        SELECT c.id as claim_id, c.status as claim_status, f.food_name, c.quantity as quantity, f.location as pickup_loc, n.location as dest_loc, n.name as ngo_name, d.name as donor_name, n.contact as ngo_contact, d.contact as donor_contact
                        FROM claims c
                        JOIN food_listings f ON c.food_listing_id = f.id
                        JOIN users n ON c.ngo_id = n.id
                        JOIN users d ON f.donor_id = d.id
                        WHERE c.volunteer_id = ? AND c.status = 'delivering'
                    ");
                    $activeStmt->execute([$userId]);
                    $activeTask = $activeStmt->fetch();

                    // Delivery History logs
                    $volHistoryStmt = $pdo->prepare("
                        SELECT c.id as claim_id, f.food_name, c.quantity as quantity, f.location as pickup_loc, n.location as dest_loc, c.created_at, n.name as ngo_name
                        FROM claims c
                        JOIN food_listings f ON c.food_listing_id = f.id
                        JOIN users n ON c.ngo_id = n.id
                        WHERE c.volunteer_id = ? AND c.status = 'delivered'
                        ORDER BY c.created_at DESC LIMIT 10
                    ");
                    $volHistoryStmt->execute([$userId]);
                    $volHistory = $volHistoryStmt->fetchAll();

                } catch (PDOException $e) {
                    $availableTasks = [];
                    $activeTask = null;
                    $volHistory = [];
                }
            ?>
                <!-- 3a. Active Task in Progress -->
                <?php if ($activeTask): ?>
                    <div class="glass-container" style="padding: 30px; border: 1px solid var(--border-glass-focused); box-shadow: 0 0 20px var(--primary-glow);">
                        <h3 class="panel-title" style="margin-bottom: 20px; color: var(--primary);"><i class="fa-solid fa-truck-fast"></i> Current Delivery In Progress</h3>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 25px;">
                            <div>
                                <h4 style="font-family: var(--font-display); margin-bottom: 12px; font-size: 1.1rem;">
                                    Task: <?php echo htmlspecialchars($activeTask['food_name']); ?>
                                </h4>
                                <div style="font-size: 0.95rem; line-height: 1.8;">
                                    <div>📦 Quantity: <strong><?php echo $activeTask['quantity']; ?> servings</strong></div>
                                    <div>🙋‍♂️ Donor Partner: <strong><?php echo htmlspecialchars($activeTask['donor_name']); ?></strong></div>
                                    <?php if (!empty($activeTask['donor_contact'])): ?>
                                        <div>📞 Contact: <strong><?php echo htmlspecialchars($activeTask['donor_contact']); ?></strong></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div style="border-left: 1px solid var(--border-glass); padding-left: 20px;">
                                <div style="font-size: 0.95rem; line-height: 1.8;">
                                    <div>📍 <strong>PICKUP SOURCE:</strong></div>
                                    <div style="color: var(--text-secondary); margin-bottom: 8px;">
                                        <i class="fa-solid fa-location-dot" style="color: var(--primary);"></i> <?php echo htmlspecialchars($activeTask['pickup_loc']); ?>
                                    </div>
                                    
                                    <div>🏁 <strong>DESTINATION NGO:</strong></div>
                                    <div style="color: var(--text-secondary); margin-bottom: 8px;">
                                        <i class="fa-solid fa-house-chimney" style="color: var(--accent);"></i> <?php echo htmlspecialchars($activeTask['ngo_name']); ?> (<?php echo htmlspecialchars($activeTask['dest_loc']); ?>)
                                    </div>
                                    <?php if (!empty($activeTask['ngo_contact'])): ?>
                                        <div style="color: var(--text-secondary); font-size: 0.88rem;">
                                            <i class="fa-solid fa-phone" style="color: var(--accent);"></i> <?php echo htmlspecialchars($activeTask['ngo_contact']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <button onclick="completeTask(<?php echo $activeTask['claim_id']; ?>)" class="btn btn-accent" style="width: 100%; height: 48px;">
                            <i class="fa-solid fa-circle-check"></i> Mark Delivery as Completed (+15 Points)
                        </button>
                    </div>

                    <!-- Live Navigation Radar Panel -->
                    <div class="glass-container" style="padding: 30px; margin-top: 25px;">
                        <h3 class="panel-title" style="margin-bottom: 15px; color: var(--accent);"><i class="fa-solid fa-map-location-dot"></i> Live Navigation Route</h3>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 15px;">
                            Calculated route: <strong>Your Station</strong> ➔ <strong>Donor</strong> ➔ <strong>NGO Shelter</strong>. Highlighted in neon pathing.
                        </p>
                        
                        <!-- Map Container -->
                        <div id="liveNavMap" style="height: 350px; width: 100%; border-radius: var(--radius-sm); border: 1px solid var(--border-glass); margin-bottom: 20px; background: #1a1a2e; overflow: hidden; position: relative;">
                            <div id="mapLoader" style="position: absolute; top:0; left:0; width:100%; height:100%; display:flex; align-items:center; justify-content:center; background: rgba(15,23,42,0.8); z-index: 1000; color: #fff; font-size: 0.9rem;">
                                <i class="fa-solid fa-spinner fa-spin" style="margin-right: 8px; color: var(--accent);"></i> Resolving live tracking satellite routes...
                            </div>
                        </div>

                        <!-- External Navigation Redirects -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <a id="gmapsLink" href="https://www.google.com/maps/dir/?api=1&origin=<?php echo urlencode($userLoc); ?>&destination=<?php echo urlencode($activeTask['dest_loc']); ?>&waypoints=<?php echo urlencode($activeTask['pickup_loc']); ?>&travelmode=two-wheeler" 
                               class="btn btn-primary" target="_blank" style="text-decoration: none; text-align: center; display: flex; align-items: center; justify-content: center; gap: 8px; height: 44px; font-size: 0.9rem;">
                                <i class="fa-solid fa-location-arrow"></i> Google Maps Navigation
                            </a>
                            <a id="osmLink" href="https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=<?php echo urlencode($userLoc); ?>%3B<?php echo urlencode($activeTask['pickup_loc']); ?>%3B<?php echo urlencode($activeTask['dest_loc']); ?>" 
                               class="btn btn-outline" target="_blank" style="text-decoration: none; text-align: center; display: flex; align-items: center; justify-content: center; gap: 8px; height: 44px; font-size: 0.9rem;">
                                <i class="fa-solid fa-route"></i> OpenStreetMap Route
                            </a>
                        </div>
                    </div>

                    <!-- Leaflet Assets & Geocoding Routing Script -->
                    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
                    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
                    <script>
                        document.addEventListener("DOMContentLoaded", function() {
                            const volunteerAddr = <?php echo json_encode($userLoc); ?>;
                            const donorAddr = <?php echo json_encode($activeTask['pickup_loc']); ?>;
                            const ngoAddr = <?php echo json_encode($activeTask['dest_loc']); ?>;

                            // Geocode an address using OSM Nominatim API
                            async function geocode(address) {
                                try {
                                    // Append India to focus geocoding searches for local landmarks
                                    const query = encodeURIComponent(address + ", India");
                                    const response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${query}&limit=1`);
                                    const data = await response.json();
                                    if (data && data.length > 0) {
                                        return [parseFloat(data[0].lat), parseFloat(data[0].lon)];
                                    }
                                } catch (e) {
                                    console.error("Geocoding failed for: " + address, e);
                                }
                                return null;
                            }

                            // Dynamic Update External Maps links with exact GPS coordinates
                            function updateExternalLinks(lat, lon) {
                                const donorEncoded = encodeURIComponent(donorAddr);
                                const ngoEncoded = encodeURIComponent(ngoAddr);
                                
                                const gmapsElement = document.getElementById('gmapsLink');
                                const osmElement = document.getElementById('osmLink');

                                if (gmapsElement) {
                                    gmapsElement.href = `https://www.google.com/maps/dir/?api=1&origin=${lat},${lon}&destination=${ngoEncoded}&waypoints=${donorEncoded}&travelmode=two-wheeler`;
                                }
                                if (osmElement) {
                                    osmElement.href = `https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=${lat},${lon}%3B${donorEncoded}%3B${ngoEncoded}`;
                                }
                            }

                            async function initMap() {
                                // Default coordinates centered on Kolhapur region
                                let centerCoords = [16.7050, 74.2433]; 
                                
                                const donorCoords = await geocode(donorAddr);
                                const ngoCoords = await geocode(ngoAddr);
                                let volCoords = null;

                                // Try browser HTML5 Geolocation first for real-time live navigation!
                                if (navigator.geolocation) {
                                    try {
                                        const position = await new Promise((resolve, reject) => {
                                            navigator.geolocation.getCurrentPosition(resolve, reject, {
                                                enableHighAccuracy: true,
                                                timeout: 4000,
                                                maximumAge: 0
                                            });
                                        });
                                        volCoords = [position.coords.latitude, position.coords.longitude];
                                        console.log("Using browser GPS live location:", volCoords);
                                        updateExternalLinks(volCoords[0], volCoords[1]);
                                    } catch (geoError) {
                                        console.warn("Geolocation failed or denied. Falling back to profile address.", geoError);
                                        volCoords = await geocode(volunteerAddr);
                                    }
                                } else {
                                    volCoords = await geocode(volunteerAddr);
                                }

                                document.getElementById('mapLoader').style.display = 'none';

                                // Initialize Leaflet map
                                const map = L.map('liveNavMap').setView(volCoords || donorCoords || ngoCoords || centerCoords, 12);

                                // Load dark tile styles to match the premium aesthetic
                                L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                                    attribution: '&copy; OpenStreetMap contributors &copy; CARTO'
                                }).addTo(map);

                                // Custom Pin Icons using pure CSS/HTML markers for wow factor
                                const createPinIcon = (color, text, pulse = false) => L.divIcon({
                                    html: `<div style="background-color: ${color}; width: 12px; height: 12px; border: 2px solid #fff; border-radius: 50%; box-shadow: 0 0 10px ${color}; ${pulse ? 'animation: pulse 1.5s infinite;' : ''}"></div>
                                           <div style="font-size: 0.72rem; color: #fff; background: rgba(15,23,42,0.85); border: 1px solid var(--border-glass); border-radius: 3px; padding: 2px 5px; white-space: nowrap; margin-top: 5px; margin-left: -20px; font-weight:700;">${text}</div>`,
                                    className: 'custom-pin-icon',
                                    iconSize: [12, 12],
                                    iconAnchor: [6, 6]
                                });

                                // Setup pulsing animation CSS in page if not exists
                                if (!document.getElementById('leafletPulseStyle')) {
                                    const style = document.createElement('style');
                                    style.id = 'leafletPulseStyle';
                                    style.innerHTML = `
                                        @keyframes pulse {
                                            0% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.7); }
                                            70% { transform: scale(1.1); box-shadow: 0 0 0 8px rgba(99, 102, 241, 0); }
                                            100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(99, 102, 241, 0); }
                                        }
                                    `;
                                    document.head.appendChild(style);
                                }

                                let volMarker = null;
                                let pathPolyline = null;

                                function drawRoutes(currentVolCoords) {
                                    const coordinates = [];
                                    if (currentVolCoords) {
                                        coordinates.push(currentVolCoords);
                                        if (volMarker) {
                                            volMarker.setLatLng(currentVolCoords);
                                        } else {
                                            volMarker = L.marker(currentVolCoords, { icon: createPinIcon('#6366f1', '🚲 You (Live GPS)', true) }).addTo(map)
                                             .bindPopup(`<strong>Your Exact GPS Coordinates (Live)</strong>`);
                                        }
                                    }
                                    
                                    if (donorCoords) {
                                        coordinates.push(donorCoords);
                                    }
                                    if (ngoCoords) {
                                        coordinates.push(ngoCoords);
                                    }

                                    // Clear existing path polyline if drawn before
                                    if (pathPolyline) {
                                        map.removeLayer(pathPolyline);
                                    }

                                    if (coordinates.length > 0) {
                                        const bounds = L.latLngBounds(coordinates);
                                        map.fitBounds(bounds, { padding: [40, 40] });

                                        // Draw path connecting Volunteer -> Donor -> NGO in neon sky-blue
                                        pathPolyline = L.polyline(coordinates, {
                                            color: '#38bdf8', 
                                            weight: 4, 
                                            dashArray: '8, 8', 
                                            lineJoin: 'round'
                                        }).addTo(map);
                                    }
                                }

                                // Initial Render
                                drawRoutes(volCoords);

                                if (donorCoords) {
                                    L.marker(donorCoords, { icon: createPinIcon('#fbbf24', '🏪 Donor Pickup') }).addTo(map)
                                     .bindPopup(`<strong>Surplus Food Location:</strong><br>${donorAddr}`);
                                }
                                if (ngoCoords) {
                                    L.marker(ngoCoords, { icon: createPinIcon('#10b981', '🏢 NGO Shelter') }).addTo(map)
                                     .bindPopup(`<strong>NGO Destination:</strong><br>${ngoAddr}`);
                                }

                                // Setup real-time tracking watchPosition to dynamically trace user movement
                                if (navigator.geolocation) {
                                    navigator.geolocation.watchPosition((pos) => {
                                        const liveCoords = [pos.coords.latitude, pos.coords.longitude];
                                        console.log("Realtime movement tracking:", liveCoords);
                                        drawRoutes(liveCoords);
                                        updateExternalLinks(liveCoords[0], liveCoords[1]);
                                    }, (err) => {
                                        console.warn("watchPosition track aborted:", err);
                                    }, {
                                        enableHighAccuracy: true,
                                        maximumAge: 1000
                                    });
                                }
                            }

                            initMap();
                        });
                    </script>
                <?php endif; ?>

                <!-- 3b. Open Pickup Request Board -->
                <div class="glass-container" style="padding: 30px;">
                    <h3 class="panel-title" style="margin-bottom: 20px;"><i class="fa-solid fa-list-check"></i> Open Delivery Task Board</h3>
                    
                    <?php if (count($availableTasks) === 0): ?>
                        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fa-solid fa-circle-check" style="font-size: 3rem; margin-bottom: 12px; opacity: 0.25; color: var(--primary);"></i>
                            <p>Fantastic! No pending pickup requests. All food deliveries are currently assigned.</p>
                        </div>
                    <?php else: ?>
                        <div class="grid-cards">
                            <?php foreach ($availableTasks as $task): ?>
                                <div class="glass-card food-card" style="padding: 22px;">
                                    <div class="card-header">
                                        <h4 class="food-title"><?php echo htmlspecialchars($task['food_name']); ?></h4>
                                        <span class="food-qty"><?php echo $task['quantity']; ?> servings</span>
                                    </div>
                                    
                                    <div style="font-size: 0.88rem; color: var(--text-secondary); display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px;">
                                        <div>
                                            <i class="fa-solid fa-arrow-up-from-bracket" style="color: var(--primary); margin-right: 5px;"></i>
                                            <span><strong>From:</strong> <?php echo htmlspecialchars($task['donor_name']); ?> (<?php echo htmlspecialchars($task['pickup_loc']); ?>)</span>
                                            <span style="display: block; margin-left: 20px; font-size: 0.82rem; color: var(--text-secondary);">
                                                <i class="fa-solid fa-phone" style="font-size: 0.75rem; color: var(--accent); margin-right: 3px;"></i> <?php echo htmlspecialchars(!empty($task['donor_contact']) ? $task['donor_contact'] : '+91 98765 43210'); ?>
                                            </span>
                                        </div>
                                        <div>
                                            <i class="fa-solid fa-arrow-down-to-bracket" style="color: var(--accent); margin-right: 5px;"></i>
                                            <span><strong>To:</strong> <?php echo htmlspecialchars($task['ngo_name']); ?> (<?php echo htmlspecialchars($task['dest_loc']); ?>)</span>
                                            <span style="display: block; margin-left: 20px; font-size: 0.82rem; color: var(--text-secondary);">
                                                <i class="fa-solid fa-phone" style="font-size: 0.75rem; color: var(--accent); margin-right: 3px;"></i> <?php echo htmlspecialchars(!empty($task['ngo_contact']) ? $task['ngo_contact'] : '+91 98765 43210'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($activeTask): ?>
                                        <button class="btn btn-outline btn-sm" style="width: 100%; cursor: not-allowed;" disabled>
                                            Complete Active Task First
                                        </button>
                                    <?php else: ?>
                                        <button onclick="acceptTask(<?php echo $task['claim_id']; ?>)" class="btn btn-primary btn-sm" style="width: 100%;">
                                            <i class="fa-solid fa-check"></i> Accept Rescue Task
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 3c. Volunteer Completion History -->
                <div class="glass-container" style="padding: 30px;">
                    <h3 class="panel-title" style="margin-bottom: 20px;">Your Completed Deliveries</h3>
                    
                    <?php if (count($volHistory) === 0): ?>
                        <p style="text-align: center; color: var(--text-muted); padding: 20px;">No completed delivery logs found.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="leaderboard-table">
                                <thead>
                                    <tr>
                                        <th>Food Item</th>
                                        <th>Quantity Rescued</th>
                                        <th>Route Locations</th>
                                        <th>Delivered To</th>
                                        <th>Completion Date</th>
                                        <th style="text-align: right;">Points Gained</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($volHistory as $hist): ?>
                                        <tr>
                                            <td style="font-weight: 600;"><?php echo htmlspecialchars($hist['food_name']); ?></td>
                                            <td><?php echo $hist['quantity']; ?> Servings</td>
                                            <td style="font-size: 0.85rem;">
                                                <i class="fa-solid fa-arrow-up-from-bracket" style="color: var(--primary); font-size: 0.78rem;"></i> <?= htmlspecialchars($hist['pickup_loc']) ?> <br>
                                                <i class="fa-solid fa-arrow-down-to-bracket" style="color: var(--accent); font-size: 0.78rem;"></i> <?= htmlspecialchars($hist['dest_loc']) ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($hist['ngo_name']); ?></td>
                                            <td style="color: var(--text-muted); font-size: 0.85rem;"><?php echo $hist['created_at']; ?></td>
                                            <td style="text-align: right;" class="points-text">+15 pts</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </section>

    </div>

</main>

<script>
// Volunteer: Accept a Delivery Task
function acceptTask(claimId) {
    if (!confirm("Are you sure you want to accept this delivery task? You will be responsible for picking up and delivering it.")) return;
    
    const formData = new FormData();
    formData.append('claim_id', claimId);
    
    fetch('api.php?action=accept_delivery', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            alert(data.message);
            window.location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert("Action failed. Please try again.");
    });
}

// Volunteer: Mark a Delivery Task as Completed
function completeTask(claimId) {
    if (!confirm("Confirm delivery of food to the NGO partner? Points will be allocated to both you and the donor.")) return;
    
    const formData = new FormData();
    formData.append('claim_id', claimId);
    
    fetch('api.php?action=complete_delivery', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            alert(data.message);
            window.location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert("Action failed. Please try again.");
    });
}
</script>

</body>
</html>
