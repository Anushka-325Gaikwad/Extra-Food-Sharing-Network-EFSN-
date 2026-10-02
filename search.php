<?php
/**
 * Extra Food Sharing Network (EFSN)
 * NGO Search Engine & Smart Matching / Combination Recommender
 */
require_once 'config.php';

// Auth Guard: Only NGOs can search and request food
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ngo') {
    header("Location: login.php");
    exit;
}

$ngoId = $_SESSION['user_id'];
$ngoName = $_SESSION['name'];

$searchResults = [];
$combinationResult = [];
$searchExecuted = false;
$reqQty = 0;
$reqFood = '';
$searchQueryText = '';

// Handle NGO Posting an Unmet Requirement/Demand
if (isset($_POST['post_requirement_submit'])) {
    $reqName = trim($_POST['req_food_name']);
    $reqQuantity = intval($_POST['req_quantity']);
    
    if (!empty($reqName) && $reqQuantity > 0) {
        try {
            // Insert demand request
            $stmt = $pdo->prepare("INSERT INTO requests (ngo_id, food_name, quantity, status) VALUES (?, ?, ?, 'open')");
            $stmt->execute([$ngoId, $reqName, $reqQuantity]);
            
            // NOTIFY ALL DONORS (Mandatory Requirement)
            $notifyMsg = "🚨 URGENT NGO DEMAND: NGO '{$ngoName}' is in critical need of '{$reqQuantity} servings' of '{$reqName}'. Can you donate?";
            notifyAllByRole('donor', $notifyMsg);
            
            $_SESSION['success_toast'] = "📋 Requirement posted! All active food donors have been notified via urgent EFSN broadcasts.";
            header("Location: dashboard.php");
            exit;
        } catch (PDOException $e) {
            $errorMsg = "Failed to post requirement: " . $e->getMessage();
        }
    }
}

// Handle Smart Search & Default Display Logic
$searchExecuted = true;

if (isset($_GET['query']) || isset($_GET['qty'])) {
    $searchQueryText = isset($_GET['query']) ? trim($_GET['query']) : '';
    $reqQty = isset($_GET['qty']) ? intval($_GET['qty']) : 1;
    
    // Parse Query Text (e.g., "30 rice packets" or "15 apples")
    if (!empty($searchQueryText) && preg_match('/^(\d+)\s+(.+)$/i', $searchQueryText, $matches)) {
        $reqQty = intval($matches[1]);
        $reqFood = preg_replace('/\b(packets|servings|pieces|items|plates|roti)\b/i', '', $matches[2]);
        $reqFood = trim($reqFood);
    } else {
        $reqFood = $searchQueryText;
    }
} else {
    // Default state: display all available listings
    $searchQueryText = '';
    $reqFood = '';
    $reqQty = 1;
}

if ($reqQty <= 0) {
    $reqQty = 1;
}

try {
    // 1. Fetch Exact Matches (Quantity >= Requested)
    $sql = "
        SELECT f.*, u.name as donor_name, u.contact as donor_contact
        FROM food_listings f
        JOIN users u ON f.donor_id = u.id
        WHERE f.status = 'available' 
          AND f.expiry_time > NOW()
          AND f.quantity >= ?
    ";
    $params = [$reqQty];
    
    if (!empty($reqFood)) {
        $sql .= " AND f.food_name LIKE ?";
        $params[] = "%" . $reqFood . "%";
    }
    
    $sql .= " ORDER BY f.quantity ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $searchResults = $stmt->fetchAll();
    
    // 2. SMART COMBINATION SOLVER (If no exact matches found OR to provide backup options)
    if (empty($searchResults) && $reqQty > 1) {
        // Fetch all available, active listings of this food item (smaller quantities)
        $comboSql = "
            SELECT f.*, u.name as donor_name, u.contact as donor_contact
            FROM food_listings f
            JOIN users u ON f.donor_id = u.id
            WHERE f.status = 'available' 
              AND f.expiry_time > NOW()
        ";
        $comboParams = [];
        
        if (!empty($reqFood)) {
            $comboSql .= " AND f.food_name LIKE ?";
            $comboParams[] = "%" . $reqFood . "%";
        }
        
        $comboSql .= " ORDER BY f.quantity DESC";
        
        $comboStmt = $pdo->prepare($comboSql);
        $comboStmt->execute($comboParams);
        $allCandidates = $comboStmt->fetchAll();
        
        // Greedy Solver Algorithm to find subset sum >= $reqQty with minimal excess
        $currentSum = 0;
        $tempCombo = [];
        
        foreach ($allCandidates as $candidate) {
            if ($currentSum < $reqQty) {
                $needed = $reqQty - $currentSum;
                $claimQtyForThis = min(intval($candidate['quantity']), $needed);
                $candidate['claim_qty'] = $claimQtyForThis;
                $tempCombo[] = $candidate;
                $currentSum += $claimQtyForThis;
            }
        }
        
        // Only suggest if the total sum actually satisfies the requirement
        if ($currentSum >= $reqQty) {
            $combinationResult = [
                'listings' => $tempCombo,
                'total_qty' => $currentSum,
                'required_qty' => $reqQty
            ];
        }
    }
    
} catch (PDOException $e) {
    $errorMsg = "Search error occurred: " . $e->getMessage();
}
?>

<?php require_once 'header.php'; ?>

<main class="main-content">
    
    <!-- Header -->
    <div class="panel-header" style="margin-bottom: 30px;">
        <h2 class="panel-title"><i class="fa-solid fa-magnifying-glass"></i> NGO Surplus Search Engine</h2>
        <a href="dashboard.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-gauge"></i> Back to Dashboard</a>
    </div>

    <!-- 1. Search Query Box -->
    <section class="glass-container" style="padding: 30px; margin-bottom: 40px;">
        <form action="search.php" method="GET" style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 20px; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="searchQuery">Food Item Name</label>
                <input type="text" name="query" id="searchQuery" class="form-control" 
                       placeholder="e.g. Rice, Biryani, Pulav" 
                       value="<?php echo htmlspecialchars($searchQueryText); ?>" required>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="qtyField">Quantity Needed</label>
                <input type="number" name="qty" id="qtyField" class="form-control" min="1" 
                       value="<?php echo $reqQty > 0 ? $reqQty : 1; ?>" required>
            </div>

            <button type="submit" name="search_submit" class="btn btn-primary" style="height: 48px; width: 100%;">
                <i class="fa-solid fa-magnifying-glass"></i> Search Food
            </button>
        </form>
    </section>

    <!-- 2. Search Results Panel -->
    <?php if ($searchExecuted): ?>
        <section class="results-section">
            <h3 style="margin-bottom: 20px; font-family: var(--font-display);">
                Search Results for "<?php echo htmlspecialchars($reqFood); ?>" (Required: <?php echo $reqQty; ?>)
            </h3>
            
            <!-- A. DIRECT MATCHES FOUND (quantity >= required) -->
            <?php if (!empty($searchResults)): ?>
                <div class="grid-cards">
                    <?php foreach ($searchResults as $listing): 
                        // Expiry visual cues
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
                                <i class="fa-solid fa-circle-user"></i>
                                <span>Donor: <?php echo htmlspecialchars($listing['donor_name']); ?></span>
                            </div>

                            <div class="food-info-item">
                                <i class="fa-solid fa-phone" style="color: var(--accent); font-size: 0.85rem;"></i>
                                <span>Contact: <?php echo htmlspecialchars($listing['donor_contact'] ?? 'N/A'); ?></span>
                            </div>
                            
                            <div class="food-info-item">
                                <i class="fa-solid fa-location-dot"></i>
                                <span>Area: <?php echo htmlspecialchars($listing['location']); ?></span>
                            </div>
                            
                            <div class="food-info-item expiry-warn">
                                <i class="fa-solid fa-clock"></i>
                                <span>Expires in: <?php echo "{$hLeft}h {$mLeft}m"; ?></span>
                            </div>
                            
                            <div class="card-actions">
                                <button onclick="claimListing(<?php echo $listing['id']; ?>, <?php echo $reqQty; ?>)" class="btn btn-primary btn-sm" style="width: 100%;">
                                    <i class="fa-solid fa-hand-holding-heart"></i> Claim & Rescue
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
            <!-- B. NO DIRECT MATCH: SHOW SMART COMBINATION RECOMMENDATIONS -->
            <?php elseif (!empty($combinationResult)): ?>
                <div class="smart-match-box glass-container">
                    <span class="smart-match-badge"><i class="fa-solid fa-bolt"></i> Smart Matching Combination Solution</span>
                    
                    <h4 style="margin-bottom: 12px; font-family: var(--font-display); color: var(--accent);">
                        No single donor can satisfy your request of <?php echo $reqQty; ?> units of "<?php echo htmlspecialchars($reqFood); ?>".
                    </h4>
                    <p style="font-size: 0.92rem; color: var(--text-secondary); margin-bottom: 20px;">
                        However, our smart matching algorithm found an optimal combination of <strong><?php echo count($combinationResult['listings']); ?> donors</strong> that together satisfy your requirement:
                    </p>
                    
                    <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 25px;">
                        <?php foreach ($combinationResult['listings'] as $idx => $comboItem): ?>
                            <div class="smart-combination-item">
                                <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($comboItem['food_name']); ?></strong>
                                <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 4px;">
                                    <span>Claiming: <strong style="color: var(--accent);"><?php echo $comboItem['claim_qty']; ?> servings</strong> (out of <?php echo $comboItem['quantity']; ?> available)</span> | 
                                    <span>Donor: <?php echo htmlspecialchars($comboItem['donor_name']); ?> (<?php echo htmlspecialchars($comboItem['donor_contact'] ?? 'N/A'); ?>)</span> | 
                                    <span>Location: <?php echo htmlspecialchars($comboItem['location']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div style="background: rgba(251, 191, 36, 0.1); border: 1px solid var(--border-glass); border-radius: var(--radius-sm); padding: 12px; font-size: 0.9rem; margin-bottom: 20px;">
                        🎯 Combined Total: <strong><?php echo $combinationResult['total_qty']; ?> servings</strong> (Fulfills your requirement of <?php echo $combinationResult['required_qty']; ?>!)
                    </div>
                    
                    <?php 
                    $comboJsonArray = array_map(function($item) {
                        return ['id' => $item['id'], 'qty' => $item['claim_qty']];
                    }, $combinationResult['listings']);
                    ?>
                    <button onclick="claimCombo(<?php echo htmlspecialchars(json_encode($comboJsonArray)); ?>)" class="btn btn-accent">
                        <i class="fa-solid fa-truck-ramp-box"></i> Bulk Claim Combo & Notify Volunteers
                    </button>
                </div>
                
            <!-- C. TRULY NO STOCK FOUND -->
            <?php else: ?>
                <div class="glass-container" style="text-align: center; padding: 50px 20px;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 3rem; color: var(--accent); margin-bottom: 20px;"></i>
                    <h4 style="margin-bottom: 10px;">No Active Food Listings Found</h4>
                    <p style="color: var(--text-secondary); max-width: 500px; margin: 0 auto 20px;">
                        We currently do not have any surplus listings matching "<strong><?php echo htmlspecialchars($reqFood); ?></strong>". 
                        You can post this as an urgent requirement below. This will instantly broadcast alert notifications to all registered EFSN donors!
                    </p>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- 3. Post Requirement Section (Unmet Demand Alert System) -->
    <section class="glass-container" style="padding: 40px; margin-top: 50px;">
        <h3 class="panel-title" style="margin-bottom: 15px;"><i class="fa-solid fa-circle-exclamation"></i> Demand Alert System</h3>
        <p style="font-size: 0.95rem; color: var(--text-secondary); margin-bottom: 25px;">
            Cannot find what you need? Post your exact food requirements below. We will immediately broadcast notifications to all registered Donors (restaurants, caterers, hotels) so they can prepare and list matching items!
        </p>
        
        <form action="search.php" method="POST" style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 20px; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="reqFoodName">Food Item Needed</label>
                <input type="text" name="req_food_name" id="reqFoodName" class="form-control" placeholder="e.g. Rice Packets or Meal Boxes" required>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="reqQuantity">Quantity Required</label>
                <input type="number" name="req_quantity" id="reqQuantity" class="form-control" min="1" placeholder="e.g. 30" required>
            </div>
            
            <button type="submit" name="post_requirement_submit" class="btn btn-accent" style="height: 48px;">
                <i class="fa-solid fa-bullhorn"></i> Post & Broadcast Alerts
            </button>
        </form>
    </section>

</main>

<script>
// Claim a single listing with partial quantity support
function claimListing(foodId, defaultQty) {
    let qty = prompt("How many servings do you want to claim?", defaultQty);
    if (qty === null) return; // User cancelled
    
    qty = parseInt(qty);
    if (isNaN(qty) || qty <= 0) {
        alert("Please enter a valid positive quantity.");
        return;
    }
    
    if (!confirm(`Are you sure you want to claim ${qty} servings of this food listing? Doing so notifies a volunteer for delivery pickup.`)) return;
    
    const formData = new FormData();
    formData.append('food_id', foodId);
    formData.append('quantity', qty);
    
    fetch('api.php?action=claim_food', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            alert(data.message);
            window.location.href = 'dashboard.php';
        } else {
            alert(data.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert("An error occurred. Please try again.");
    });
}

// Bulk claim combination listings with specific quantities
function claimCombo(comboItems) {
    if (!confirm(`Are you sure you want to bulk claim this combination of ${comboItems.length} food listings?`)) return;
    
    let claimsCompleted = 0;
    
    comboItems.forEach(item => {
        const formData = new FormData();
        formData.append('food_id', item.id);
        formData.append('quantity', item.qty);
        
        fetch('api.php?action=claim_food', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            claimsCompleted++;
            if (claimsCompleted === comboItems.length) {
                alert("🎉 Success! The combination has been successfully claimed. Volunteers have been notified for bulk pickups!");
                window.location.href = 'dashboard.php';
            }
        })
        .catch(err => console.error("Combo claim failed for ID: " + item.id, err));
    });
}
</script>

</body>
</html>
