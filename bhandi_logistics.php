<?php
/**
 * Food Logistics Partner Dashboard
 * Container (Bhandi) Management with Time Tracking & Analytics
 */
require_once 'config.php';

// Auth Guard: Only Logistics Partners allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'logistics') {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];
$userLoc = $_SESSION['location'] ?? 'Logistics Base';

$errorMsg = '';
$successMsg = '';

// 1. Handle Add Container
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_container'])) {
    $containerId = trim($_POST['container_id'] ?? '');
    $size = $_POST['size'] ?? 'Medium';
    
    if (empty($containerId)) {
        $errorMsg = "Container ID cannot be empty.";
    } else {
        try {
            // Check if already exists
            $stmt = $pdo->prepare("SELECT container_id FROM bhandi_containers WHERE container_id = ?");
            $stmt->execute([$containerId]);
            if ($stmt->fetch()) {
                $errorMsg = "Container ID '{$containerId}' already exists in inventory.";
            } else {
                $ins = $pdo->prepare("INSERT INTO bhandi_containers (container_id, size, status, times_used) VALUES (?, ?, 'Available', 0)");
                $ins->execute([$containerId, $size]);
                $successMsg = "📦 Container '{$containerId}' added to inventory successfully.";
            }
        } catch (PDOException $e) {
            $errorMsg = "Failed to add container: " . $e->getMessage();
        }
    }
}

// 2. Handle Container Status Update (e.g. Cleaning -> Available)
if (isset($_GET['action']) && $_GET['action'] === 'clean_container') {
    $cid = $_GET['id'] ?? '';
    try {
        $up = $pdo->prepare("UPDATE bhandi_containers SET status = 'Available' WHERE container_id = ? AND status = 'Cleaning'");
        $up->execute([$cid]);
        $successMsg = "🧼 Container '{$cid}' is now clean and Available.";
    } catch (PDOException $e) {
        $errorMsg = "Failed to update container status: " . $e->getMessage();
    }
}

// 3. Handle Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_container'])) {
    $containerId = $_POST['container_id'] ?? '';
    $foodListingId = intval($_POST['food_listing_id'] ?? 0);
    $volunteerId = intval($_POST['volunteer_id'] ?? 0);
    
    if (empty($containerId) || $foodListingId <= 0) {
        $errorMsg = "Please select both a container and a valid food listing.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check container status
            $stmt = $pdo->prepare("SELECT status, times_used FROM bhandi_containers WHERE container_id = ? FOR UPDATE");
            $stmt->execute([$containerId]);
            $container = $stmt->fetch();
            
            if (!$container || $container['status'] !== 'Available') {
                $errorMsg = "Selected container is not available.";
                $pdo->rollBack();
            } else {
                // Calculate 3-hour expiry
                $startTime = date('Y-m-d H:i:s');
                $expiryTime = date('Y-m-d H:i:s', strtotime('+3 hours'));
                
                // Insert Assignment
                $ins = $pdo->prepare("
                    INSERT INTO bhandi_assignments (container_id, food_listing_id, volunteer_id, logistics_partner_id, start_time, expiry_time, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'Active')
                ");
                $ins->execute([
                    $containerId,
                    $foodListingId,
                    $volunteerId > 0 ? $volunteerId : null,
                    $userId,
                    $startTime,
                    $expiryTime
                ]);
                
                // Update Container status to 'In Use' and increment reuse count
                $upC = $pdo->prepare("UPDATE bhandi_containers SET status = 'In Use', times_used = times_used + 1 WHERE container_id = ?");
                $upC->execute([$containerId]);
                
                // Update Food requirements to reflect assigned partner
                $upR = $pdo->prepare("UPDATE bhandi_food_requirements SET assigned_partner_id = ? WHERE food_listing_id = ?");
                $upR->execute([$userId, $foodListingId]);
                
                // Notify Volunteer and Donor
                if ($volunteerId > 0) {
                    $volMsg = "🚚 BHANDI ASSIGNED: You have been assigned container '{$containerId}' for food delivery #{$foodListingId}. Expiry: 3 Hours.";
                    notifyUser($volunteerId, $volMsg);
                }
                
                $pdo->commit();
                $successMsg = "✅ Container '{$containerId}' assigned successfully. Time-tracking activated!";
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorMsg = "Assignment failed: " . $e->getMessage();
        }
    }
}

// 4. Handle Container Return
if (isset($_GET['action']) && $_GET['action'] === 'return_container') {
    $assignmentId = intval($_GET['id'] ?? 0);
    $targetStatus = $_GET['status'] ?? 'Available'; // can return as Available or Cleaning
    
    try {
        $pdo->beginTransaction();
        
        // Fetch assignment details
        $stmt = $pdo->prepare("SELECT container_id FROM bhandi_assignments WHERE id = ? FOR UPDATE");
        $stmt->execute([$assignmentId]);
        $containerId = $stmt->fetchColumn();
        
        if ($containerId) {
            // Update assignment status
            $upA = $pdo->prepare("UPDATE bhandi_assignments SET status = 'Returned' WHERE id = ?");
            $upA->execute([$assignmentId]);
            
            // Update container status
            $upC = $pdo->prepare("UPDATE bhandi_containers SET status = ? WHERE container_id = ?");
            $upC->execute([$targetStatus, $containerId]);
            
            $pdo->commit();
            $successMsg = "📦 Container '{$containerId}' successfully returned (Status: {$targetStatus}).";
        } else {
            $pdo->rollBack();
            $errorMsg = "Assignment record not found.";
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errorMsg = "Failed to process return: " . $e->getMessage();
    }
}

// --- FETCH DATA FOR DASHBOARD PANELS ---

// A. Fetch Inventory List
$inventory = [];
try {
    $inventory = $pdo->query("SELECT * FROM bhandi_containers ORDER BY container_id ASC")->fetchAll();
} catch (PDOException $e) {}

// B. Fetch Unassigned Food Requirements that need containers
$pendingRequests = [];
try {
    $pendingRequests = $pdo->query("
        SELECT r.food_listing_id, r.container_size, f.food_name, f.quantity, f.location, u.name as donor_name
        FROM bhandi_food_requirements r
        JOIN food_listings f ON r.food_listing_id = f.id
        JOIN users u ON f.donor_id = u.id
        LEFT JOIN bhandi_assignments a ON a.food_listing_id = r.food_listing_id AND a.status != 'Returned'
        WHERE r.needs_containers = 1 AND a.id IS NULL AND f.status = 'available'
    ")->fetchAll();
} catch (PDOException $e) {}

// C. Fetch Active Assignments
$activeAssignments = [];
try {
    $activeAssignments = $pdo->query("
        SELECT a.*, f.food_name, u_vol.name as volunteer_name, u_don.name as donor_name
        FROM bhandi_assignments a
        JOIN food_listings f ON a.food_listing_id = f.id
        JOIN users u_don ON f.donor_id = u_don.id
        LEFT JOIN users u_vol ON a.volunteer_id = u_vol.id
        WHERE a.status IN ('Active', 'Overdue')
        ORDER BY a.expiry_time ASC
    ")->fetchAll();
} catch (PDOException $e) {}

// D. Fetch Active Volunteers for assignment panel
$volunteers = [];
try {
    $volunteers = $pdo->query("SELECT id, name FROM users WHERE role = 'volunteer' ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {}

// E. Waste Reduction Metrics 🌱
$totalFoodSaved = 0;
$mealsServed = 0;
$co2Reduced = 0;
try {
    // Total food saved = sum of quantities of all delivered claims
    $stmt = $pdo->query("SELECT SUM(c.quantity) FROM claims c WHERE c.status = 'delivered'");
    $totalFoodSaved = floatval($stmt->fetchColumn());
    
    // 1 kg food saved is approx 2.2 meals served
    $mealsServed = round($totalFoodSaved * 2.2);
    
    // 1 kg food diverted from landfills reduces CO2 emissions by approx 2.5 kg
    $co2Reduced = round($totalFoodSaved * 2.5, 1);
} catch (PDOException $e) {}

require_once 'header.php';
?>

<main class="main-content">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px;">
        <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
            <div>
                <h1 class="gradient-text"><i class="fa-solid fa-boxes-packing"></i> Logistics Command Center</h1>
                <p style="color: var(--text-secondary); margin-bottom: 0;">Manage container logistics, track delivery run durations, and visualize waste reduction impact.</p>
            </div>
            <!-- Profile Widget -->
            <div style="display: flex; align-items: center; gap: 12px; background: var(--bg-card); padding: 8px 15px; border-radius: var(--radius-sm); border: 1px solid var(--border-glass);">
                <div style="width: 38px; height: 38px; border-radius: 50%; overflow: hidden; background: var(--primary); border: 2px solid var(--primary); display: flex; justify-content: center; align-items: center;">
                    <?php
                    $logPicStmt = $pdo->prepare("SELECT profile_pic FROM users WHERE id = ?");
                    $logPicStmt->execute([$userId]);
                    $logPic = $logPicStmt->fetchColumn();
                    if (!empty($logPic) && file_exists(__DIR__ . '/' . $logPic)): ?>
                        <img src="<?php echo htmlspecialchars($logPic); ?>?t=<?php echo time(); ?>" style="width: 100%; height: 100%; object-fit: cover;" alt="Logistics Avatar" />
                    <?php else: ?>
                        <span style="font-weight: bold; color: #ffffff; font-size: 1rem;"><?php echo strtoupper(substr($userName, 0, 1)); ?></span>
                    <?php endif; ?>
                </div>
                <div style="line-height: 1.2;">
                    <strong style="font-size: 0.85rem; color: #ffffff; display: block;"><?php echo htmlspecialchars($userName); ?></strong>
                    <span style="font-size: 0.7rem; color: var(--text-secondary);">Logistics Partner</span>
                </div>
                <a href="bhandi_profile.php" class="btn btn-accent btn-sm" style="font-size: 0.72rem; padding: 3px 6px; margin-left: 5px;"><i class="fa-solid fa-user-pen"></i> Profile</a>
            </div>
        </div>
        <!-- Impact Badge Grid -->
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <div class="glass-card" style="padding: 12px 20px; border-left: 3px solid var(--primary); display: flex; align-items: center; gap: 12px;">
                <i class="fa-solid fa-seedling" style="color: var(--primary); font-size: 1.5rem;"></i>
                <div>
                    <span style="font-size: 0.72rem; color: var(--text-secondary); display: block; text-transform: uppercase;">CO₂ Saved</span>
                    <strong style="font-size: 1.1rem; color: #ffffff;"><?php echo $co2Reduced; ?> kg</strong>
                </div>
            </div>
            <div class="glass-card" style="padding: 12px 20px; border-left: 3px solid var(--accent); display: flex; align-items: center; gap: 12px;">
                <i class="fa-solid fa-utensils" style="color: var(--accent); font-size: 1.5rem;"></i>
                <div>
                    <span style="font-size: 0.72rem; color: var(--text-secondary); display: block; text-transform: uppercase;">Meals Saved</span>
                    <strong style="font-size: 1.1rem; color: #ffffff;"><?php echo $mealsServed; ?></strong>
                </div>
            </div>
            <div class="glass-card" style="padding: 12px 20px; border-left: 3px solid var(--info); display: flex; align-items: center; gap: 12px;">
                <i class="fa-solid fa-weight-hanging" style="color: var(--info); font-size: 1.5rem;"></i>
                <div>
                    <span style="font-size: 0.72rem; color: var(--text-secondary); display: block; text-transform: uppercase;">Food Saved</span>
                    <strong style="font-size: 1.1rem; color: #ffffff;"><?php echo $totalFoodSaved; ?> kg</strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($successMsg)): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--primary); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 25px; font-size: 0.9rem; color: #a7f3d0; display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid fa-circle-check" style="color: var(--primary);"></i>
            <div><?php echo htmlspecialchars($successMsg); ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorMsg)): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 25px; font-size: 0.9rem; color: #fca5a5; display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid fa-circle-exclamation" style="color: var(--danger);"></i>
            <div><?php echo htmlspecialchars($errorMsg); ?></div>
        </div>
    <?php endif; ?>

    <div class="dashboard-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
        
        <!-- ================= LEFT COLUMN: ACTIVE TRACKING & ASSIGNMENT ================= -->
        <section style="display: flex; flex-direction: column; gap: 30px;">
            
            <!-- 1. Live Countdown Tracking display -->
            <div class="glass-container" style="padding: 25px;">
                <h3 class="panel-title" style="margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-hourglass-half" style="color: var(--accent);"></i> Active Container Assignments
                </h3>
                
                <?php if (count($activeAssignments) === 0): ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 40px 10px;">No containers currently in transit.</p>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <?php foreach ($activeAssignments as $row): 
                            $expiryEpoch = strtotime($row['expiry_time']);
                        ?>
                            <div class="glass-card" style="padding: 18px; border-left: 4px solid var(--primary);" id="assign-card-<?php echo $row['id']; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                                    <div>
                                        <strong style="font-size: 1.05rem; display: block;"><?php echo htmlspecialchars($row['container_id']); ?></strong>
                                        <span style="font-size: 0.8rem; color: var(--text-secondary);">Food: <?php echo htmlspecialchars($row['food_name']); ?></span>
                                    </div>
                                    <div class="timer-badge" id="timer-<?php echo $row['id']; ?>" data-expiry="<?php echo $expiryEpoch; ?>" style="padding: 4px 10px; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: bold; background: rgba(16, 185, 129, 0.15); color: var(--primary);">
                                        --:--:--
                                    </div>
                                </div>
                                
                                <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 15px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <div><i class="fa-solid fa-user-tag" style="color: var(--accent);"></i> Courier: <?php echo htmlspecialchars($row['volunteer_name'] ?? 'Unassigned'); ?></div>
                                    <div><i class="fa-solid fa-clock" style="color: var(--info);"></i> Dispatched: <?php echo date('h:i A', strtotime($row['start_time'])); ?></div>
                                </div>
                                
                                <div style="display: flex; gap: 10px;">
                                    <a href="bhandi_logistics.php?action=return_container&id=<?php echo $row['id']; ?>&status=Available" class="btn btn-primary btn-sm" style="flex: 1; font-size: 0.8rem; padding: 6px;">
                                        <i class="fa-solid fa-circle-check"></i> Returned (Clean)
                                    </a>
                                    <a href="bhandi_logistics.php?action=return_container&id=<?php echo $row['id']; ?>&status=Cleaning" class="btn btn-outline btn-sm" style="flex: 1; font-size: 0.8rem; padding: 6px;">
                                        <i class="fa-solid fa-soap"></i> Send to Clean
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 2. Container Assignment Panel -->
            <div class="glass-container" style="padding: 25px;">
                <h3 class="panel-title" style="margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-circle-arrow-right" style="color: var(--primary);"></i> Dispatch & Container Assignment
                </h3>
                
                <?php if (count($pendingRequests) === 0): ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 30px 10px;">No pending food listings require container services at this moment.</p>
                <?php else: ?>
                    <form action="bhandi_logistics.php" method="POST" style="display: flex; flex-direction: column; gap: 15px;">
                        <div class="form-group">
                            <label class="form-label" for="food_listing_id">Select Donor Request requiring Bhandi:</label>
                            <select name="food_listing_id" id="food_listing_id" class="form-control" required onchange="filterAvailableContainers(this.options[this.selectedIndex].getAttribute('data-size'))">
                                <option value="">-- Choose Food Listing Request --</option>
                                <?php foreach ($pendingRequests as $req): ?>
                                    <option value="<?php echo $req['food_listing_id']; ?>" data-size="<?php echo $req['container_size']; ?>">
                                        <?php echo htmlspecialchars($req['donor_name']); ?> - <?php echo htmlspecialchars($req['food_name']); ?> (Requires: <?php echo $req['container_size']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="container_id">Select Available Container:</label>
                            <select name="container_id" id="container_id" class="form-control" required>
                                <option value="">-- Choose Container Unit --</option>
                                <?php foreach ($inventory as $c): ?>
                                    <?php if ($c['status'] === 'Available'): ?>
                                        <option value="<?php echo htmlspecialchars($c['container_id']); ?>" data-size="<?php echo $c['size']; ?>">
                                            <?php echo htmlspecialchars($c['container_id']); ?> [Size: <?php echo $c['size']; ?>]
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="volunteer_id">Assign Logistics Courier / Volunteer:</label>
                            <select name="volunteer_id" id="volunteer_id" class="form-control">
                                <option value="0">-- Direct/No Volunteer (Self Pickup) --</option>
                                <?php foreach ($volunteers as $v): ?>
                                    <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" name="assign_container" class="btn btn-accent" style="width: 100%;">
                            <i class="fa-solid fa-truck-ramp-box"></i> Dispatch Container
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            
        </section>

        <!-- ================= RIGHT COLUMN: INVENTORY & REUSE TRACKING ================= -->
        <section style="display: flex; flex-direction: column; gap: 30px;">
            
            <!-- 3. Add Container Form -->
            <div class="glass-container" style="padding: 25px;">
                <h3 class="panel-title" style="margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-plus" style="color: var(--primary);"></i> Add Container to Fleet
                </h3>
                <form action="bhandi_logistics.php" method="POST" style="display: grid; grid-template-columns: 2fr 1fr auto; gap: 15px; align-items: end;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="new_container_id">Container ID / Barcode</label>
                        <input type="text" name="container_id" id="new_container_id" class="form-control" placeholder="e.g. C-MED-205" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="new_size">Size</label>
                        <select name="size" id="new_size" class="form-control" required>
                            <option value="Small">Small</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="Large">Large</option>
                        </select>
                    </div>
                    <button type="submit" name="add_container" class="btn btn-primary" style="height: 48px;">Add Box</button>
                </form>
            </div>

            <!-- 4. Container Inventory List & Reuse Tracking -->
            <div class="glass-container" style="padding: 25px;">
                <h3 class="panel-title" style="margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-clipboard-list" style="color: var(--primary);"></i> Container Fleet Status & Reuse Logs
                </h3>
                
                <div style="max-height: 400px; overflow-y: auto;">
                    <table class="leaderboard-table">
                        <thead>
                            <tr>
                                <th>Container ID</th>
                                <th>Size</th>
                                <th>Status</th>
                                <th style="text-align: center;">Times Reused</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventory as $c): 
                                $statusColor = 'var(--text-secondary)';
                                if ($c['status'] === 'Available') $statusColor = 'var(--primary)';
                                elseif ($c['status'] === 'In Use') $statusColor = 'var(--accent)';
                                elseif ($c['status'] === 'Cleaning') $statusColor = 'var(--info)';
                                elseif ($c['status'] === 'Overdue') $statusColor = 'var(--danger)';
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($c['container_id']); ?></strong></td>
                                    <td><?php echo $c['size']; ?></td>
                                    <td>
                                        <span style="display: inline-flex; align-items: center; gap: 5px; color: <?php echo $statusColor; ?>; font-weight: bold; font-size: 0.85rem;">
                                            <span style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $statusColor; ?>; display: inline-block;"></span>
                                            <?php echo $c['status']; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;" class="points-text"><?php echo $c['times_used']; ?> 🔄</td>
                                    <td style="text-align: right;">
                                        <?php if ($c['status'] === 'Cleaning'): ?>
                                            <a href="bhandi_logistics.php?action=clean_container&id=<?php echo urlencode($c['container_id']); ?>" class="btn btn-outline btn-sm" style="font-size: 0.72rem; padding: 3px 8px;">
                                                <i class="fa-solid fa-check"></i> Set Ready
                                            </a>
                                        <?php else: ?>
                                            <span style="font-size: 0.8rem; color: var(--text-muted);">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </section>
        
    </div>

</main>

<script>
// Filter container select dropdown options by matching size
function filterAvailableContainers(size) {
    const containerSelect = document.getElementById('container_id');
    if (!containerSelect) return;
    
    // Reset selection
    containerSelect.value = "";
    
    for (let option of containerSelect.options) {
        if (option.value === "") continue;
        const cSize = option.getAttribute('data-size');
        if (!size || cSize === size) {
            option.style.display = "block";
        } else {
            option.style.display = "none";
        }
    }
}

// Live Countdown timer logic
document.addEventListener("DOMContentLoaded", function() {
    function updateTimers() {
        const now = Math.floor(Date.now() / 1000);
        const badges = document.querySelectorAll('.timer-badge');
        
        badges.forEach(badge => {
            const expiry = parseInt(badge.getAttribute('data-expiry'));
            const card = badge.closest('.glass-card');
            const diff = expiry - now;
            
            if (diff <= 0) {
                badge.innerText = "OVERDUE 🚨";
                badge.style.color = "var(--danger)";
                badge.style.background = "rgba(239, 68, 68, 0.15)";
                if (card) {
                    card.style.borderColor = "var(--danger)";
                }
            } else {
                const hours = Math.floor(diff / 3600);
                const minutes = Math.floor((diff % 3600) / 60);
                const seconds = diff % 60;
                
                const timeString = 
                    String(hours).padStart(2, '0') + ':' + 
                    String(minutes).padStart(2, '0') + ':' + 
                    String(seconds).padStart(2, '0');
                
                badge.innerText = timeString;
                
                // Color indications
                if (diff < 3600) { // Less than 1 hour (Yellow)
                    badge.style.color = "var(--accent)";
                    badge.style.background = "rgba(251, 191, 36, 0.15)";
                    if (card) card.style.borderColor = "var(--accent)";
                } else { // Safe (Green)
                    badge.style.color = "var(--primary)";
                    badge.style.background = "rgba(16, 185, 129, 0.15)";
                    if (card) card.style.borderColor = "var(--primary)";
                }
            }
        });
    }
    
    updateTimers();
    setInterval(updateTimers, 1000);
});
</script>

</body>
</html>
