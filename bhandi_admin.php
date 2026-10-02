<?php
/**
 * Admin Panel Control Center Extension
 * User Monitoring, Activity Reports, User Suspension & Inactive Alerts
 */
require_once 'config.php';

// Auth Guard: Only Admin allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

$errorMsg = '';
$successMsg = '';

// Handle Admin Actions: Warn, Suspend, Remove
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $targetUserId = intval($_GET['user_id'] ?? 0);
    
    if ($targetUserId > 0 && $targetUserId !== $userId) { // Cannot act on self
        try {
            if ($action === 'warn') {
                $warnMsg = "⚠️ INACTIVITY WARNING: Your EFSN account has been flagged for inactivity. Please participate in active food rescues to maintain status.";
                notifyUser($targetUserId, $warnMsg);
                $successMsg = "⚠️ Inactivity warning notification successfully sent to the user.";
            } elseif ($action === 'suspend') {
                $stmt = $pdo->prepare("SELECT status, name FROM users WHERE id = ?");
                $stmt->execute([$targetUserId]);
                $user = $stmt->fetch();
                if ($user) {
                    $newStatus = $user['status'] === 'Active' ? 'Suspended' : 'Active';
                    $up = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                    $up->execute([$newStatus, $targetUserId]);
                    
                    if ($newStatus === 'Suspended') {
                        $successMsg = "❌ Account '{$user['name']}' has been Suspended. They are locked out of EFSN.";
                        notifyUser($targetUserId, "❌ Your EFSN account has been suspended by the Admin due to inactivity or policy violation.");
                    } else {
                        $successMsg = "✅ Account '{$user['name']}' has been Reactivated.";
                        notifyUser($targetUserId, "✅ Your EFSN account has been successfully reactivated. Welcome back!");
                    }
                }
            } elseif ($action === 'remove') {
                $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $stmt->execute([$targetUserId]);
                $name = $stmt->fetchColumn();
                if ($name) {
                    $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $del->execute([$targetUserId]);
                    $successMsg = "🗑️ Account '{$name}' has been permanently deleted from EFSN system.";
                }
            }
        } catch (PDOException $e) {
            $errorMsg = "Failed to execute action: " . $e->getMessage();
        }
    }
}

// --- FETCH REPORT TYPE (Daily, Weekly, Monthly) ---
$reportType = $_GET['report'] ?? 'monthly';
$startDate = '';
$endDate = date('Y-m-d H:i:s');

if ($reportType === 'daily') {
    $startDate = date('Y-m-d 00:00:00');
    $reportTitle = "Daily Operations Report (" . date('M d, Y') . ")";
} elseif ($reportType === 'weekly') {
    $startDate = date('Y-m-d H:i:s', strtotime('-7 days'));
    $reportTitle = "Weekly Analytics Report (Past 7 Days)";
} else {
    $startDate = date('Y-m-d H:i:s', strtotime('-30 days'));
    $reportTitle = "Monthly Impact Report (Past 30 Days)";
}

// 1. Report Statistics (within range)
$repFoodSaved = 0;
$repMeals = 0;
$repCO2 = 0;
$repDonations = 0;
$repAssignments = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM food_listings WHERE created_at >= ?");
    $stmt->execute([$startDate]);
    $repDonations = intval($stmt->fetchColumn());
    
    $stmt = $pdo->prepare("SELECT IFNULL(SUM(quantity), 0) FROM claims WHERE status = 'delivered' AND created_at >= ?");
    $stmt->execute([$startDate]);
    $repFoodSaved = floatval($stmt->fetchColumn());
    $repMeals = round($repFoodSaved * 2.2);
    $repCO2 = round($repFoodSaved * 2.5, 1);
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bhandi_assignments WHERE start_time >= ?");
    $stmt->execute([$startDate]);
    $repAssignments = intval($stmt->fetchColumn());
} catch (PDOException $e) {}

// 2. Fetch User Monitoring List (with activity metrics, status & ratings)
$userList = [];
try {
    $userList = $pdo->query("
        SELECT u.id, u.name, u.email, u.role, u.location, u.contact, u.status, u.created_at,
               (SELECT COUNT(*) FROM food_listings WHERE donor_id = u.id) as donor_listings_count,
               (SELECT COUNT(*) FROM claims WHERE ngo_id = u.id) as ngo_claims_count,
               (SELECT COUNT(*) FROM claims WHERE volunteer_id = u.id) as volunteer_completed_count,
               (SELECT COUNT(*) FROM bhandi_assignments WHERE logistics_partner_id = u.id) as logistics_assignments_count,
               (SELECT IFNULL(AVG(rating), 0) FROM bhandi_ratings WHERE to_user_id = u.id) as avg_rating
        FROM users u
        ORDER BY u.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {}

// 3. Fake/Inactive User Detection (registered > 1 day ago but 0 activity)
$inactiveUsers = [];
try {
    $inactiveUsers = $pdo->query("
        SELECT u.* 
        FROM users u
        WHERE (SELECT COUNT(*) FROM food_listings WHERE donor_id = u.id) = 0
          AND (SELECT COUNT(*) FROM claims WHERE ngo_id = u.id) = 0
          AND (SELECT COUNT(*) FROM claims WHERE volunteer_id = u.id) = 0
          AND (SELECT COUNT(*) FROM bhandi_assignments WHERE logistics_partner_id = u.id) = 0
          AND u.role != 'admin'
          AND u.created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
        ORDER BY u.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {}

// 4. Container Fleet Metrics
$topReusedContainers = [];
try {
    $topReusedContainers = $pdo->query("
        SELECT container_id, size, status, times_used 
        FROM bhandi_containers 
        ORDER BY times_used DESC LIMIT 5
    ")->fetchAll();
} catch (PDOException $e) {}

// 5. Donor Ratings by Type Summary
$donorTypeStats = [];
try {
    $donorTypeStats = $pdo->query("
        SELECT u.donor_type,
               COUNT(DISTINCT u.id) as donor_count,
               COUNT(r.id) as total_reviews,
               IFNULL(AVG(r.rating_quality), 0) as avg_quality,
               IFNULL(AVG(r.rating_quantity), 0) as avg_quantity,
               IFNULL(AVG(r.rating_timeliness), 0) as avg_timeliness,
               IFNULL(AVG(r.rating), 0) as avg_overall
        FROM users u
        LEFT JOIN bhandi_ratings r ON u.id = r.to_user_id
        WHERE u.role = 'donor'
        GROUP BY u.donor_type
        ORDER BY avg_overall DESC
    ")->fetchAll();
} catch (PDOException $e) {}

// 6. Detailed Donor Ratings Table
$detailedDonorRatings = [];
try {
    $detailedDonorRatings = $pdo->query("
        SELECT u.id, u.name, u.location, u.donor_type,
               COUNT(r.id) as review_count,
               IFNULL(AVG(r.rating_quality), 0) as avg_quality,
               IFNULL(AVG(r.rating_quantity), 0) as avg_quantity,
               IFNULL(AVG(r.rating_timeliness), 0) as avg_timeliness,
               IFNULL(AVG(r.rating), 0) as avg_overall
        FROM users u
        LEFT JOIN bhandi_ratings r ON u.id = r.to_user_id
        WHERE u.role = 'donor'
        GROUP BY u.id, u.name, u.location, u.donor_type
        ORDER BY avg_overall DESC, review_count DESC
    ")->fetchAll();
} catch (PDOException $e) {}

require_once 'header.php';
?>

<main class="main-content">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px;">
        <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
            <div>
                <h1 class="gradient-text"><i class="fa-solid fa-user-shield"></i> Admin Control Center</h1>
                <p style="color: var(--text-secondary); margin-bottom: 0;">Monitor community accounts, track active food recovery metrics, and audit fleet integrity.</p>
            </div>
            <!-- Profile Widget -->
            <div style="display: flex; align-items: center; gap: 12px; background: var(--bg-card); padding: 8px 15px; border-radius: var(--radius-sm); border: 1px solid var(--border-glass);">
                <div style="width: 38px; height: 38px; border-radius: 50%; overflow: hidden; background: var(--accent); border: 2px solid var(--accent); display: flex; justify-content: center; align-items: center;">
                    <?php
                    $adminPicStmt = $pdo->prepare("SELECT profile_pic FROM users WHERE id = ?");
                    $adminPicStmt->execute([$userId]);
                    $adminPic = $adminPicStmt->fetchColumn();
                    if (!empty($adminPic) && file_exists(__DIR__ . '/' . $adminPic)): ?>
                        <img src="<?php echo htmlspecialchars($adminPic); ?>?t=<?php echo time(); ?>" style="width: 100%; height: 100%; object-fit: cover;" alt="Admin Avatar" />
                    <?php else: ?>
                        <span style="font-weight: bold; color: #ffffff; font-size: 1rem;"><?php echo strtoupper(substr($userName, 0, 1)); ?></span>
                    <?php endif; ?>
                </div>
                <div style="line-height: 1.2;">
                    <strong style="font-size: 0.85rem; color: #ffffff; display: block;"><?php echo htmlspecialchars($userName); ?></strong>
                    <span style="font-size: 0.7rem; color: var(--text-secondary);">System Admin</span>
                </div>
                <a href="bhandi_profile.php" class="btn btn-accent btn-sm" style="font-size: 0.72rem; padding: 3px 6px; margin-left: 5px;"><i class="fa-solid fa-user-pen"></i> Profile</a>
            </div>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="bhandi_admin.php?report=daily" class="btn <?php echo $reportType==='daily'?'btn-primary':'btn-outline'; ?> btn-sm">Daily</a>
            <a href="bhandi_admin.php?report=weekly" class="btn <?php echo $reportType==='weekly'?'btn-primary':'btn-outline'; ?> btn-sm">Weekly</a>
            <a href="bhandi_admin.php?report=monthly" class="btn <?php echo $reportType==='monthly'?'btn-primary':'btn-outline'; ?> btn-sm">Monthly</a>
            <button onclick="window.print()" class="btn btn-accent btn-sm"><i class="fa-solid fa-print"></i> Print Report</button>
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

    <!-- Operations Report Panel -->
    <div class="print-report-container glass-container" style="padding: 30px; margin-bottom: 30px;">
        <h3 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 12px; margin-bottom: 20px; color: var(--accent);">
            <i class="fa-solid fa-file-invoice"></i> <?php echo $reportTitle; ?>
        </h3>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 25px;">
            <div class="glass-card" style="padding: 15px; text-align: center; border-top: 3px solid var(--primary);">
                <span style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">Food Recovered</span>
                <strong style="font-size: 1.4rem; display: block; margin-top: 5px; color: #ffffff;"><?php echo $repFoodSaved; ?> kg</strong>
            </div>
            <div class="glass-card" style="padding: 15px; text-align: center; border-top: 3px solid var(--accent);">
                <span style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">Meals Saved</span>
                <strong style="font-size: 1.4rem; display: block; margin-top: 5px; color: #ffffff;"><?php echo $repMeals; ?></strong>
            </div>
            <div class="glass-card" style="padding: 15px; text-align: center; border-top: 3px solid var(--info);">
                <span style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">CO₂ Avoided</span>
                <strong style="font-size: 1.4rem; display: block; margin-top: 5px; color: #ffffff;"><?php echo $repCO2; ?> kg</strong>
            </div>
            <div class="glass-card" style="padding: 15px; text-align: center; border-top: 3px solid var(--primary);">
                <span style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">Donations Listed</span>
                <strong style="font-size: 1.4rem; display: block; margin-top: 5px; color: #ffffff;"><?php echo $repDonations; ?></strong>
            </div>
            <div class="glass-card" style="padding: 15px; text-align: center; border-top: 3px solid var(--accent);">
                <span style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">Box Shipments</span>
                <strong style="font-size: 1.4rem; display: block; margin-top: 5px; color: #ffffff;"><?php echo $repAssignments; ?></strong>
            </div>
        </div>
    </div>

    <!-- Donors Performance & Ratings Control Panel (Admin Only) -->
    <div class="glass-container" style="padding: 30px; margin-bottom: 30px;">
        <h3 style="border-bottom: 1px solid var(--border-glass); padding-bottom: 12px; margin-bottom: 20px; color: var(--accent);">
            <i class="fa-solid fa-ranking-star"></i> Donors Overall Ratings Summary
        </h3>
        
        <!-- Category-wise Donor Rating Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 25px;">
            <?php
            $categories = ['Restaurant', 'Hotel', 'Home Kitchen', 'Catering'];
            foreach ($categories as $cat) {
                // Find matching stats
                $stats = ['donor_count' => 0, 'total_reviews' => 0, 'avg_overall' => 0];
                foreach ($donorTypeStats as $ds) {
                    if (strtolower($ds['donor_type']) === strtolower($cat)) {
                        $stats = $ds;
                        break;
                    }
                }
                $avgOverall = round($stats['avg_overall'], 1);
                $starLabel = $avgOverall > 0 ? $avgOverall . ' ★' : 'No Ratings';
            ?>
                <div class="glass-card" style="padding: 15px; border-left: 3px solid var(--accent); position: relative;">
                    <span style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; display: block;"><?php echo $cat; ?>s</span>
                    <strong style="font-size: 1.25rem; display: block; margin: 5px 0; color: #ffffff;"><?php echo $starLabel; ?></strong>
                    <div style="font-size: 0.78rem; color: var(--text-secondary);">
                        <span><?php echo $stats['donor_count']; ?> Donors</span> | 
                        <span><?php echo $stats['total_reviews']; ?> Reviews</span>
                    </div>
                </div>
            <?php } ?>
        </div>

        <!-- Detailed ratings list -->
        <div style="overflow-x: auto;">
            <table class="leaderboard-table">
                <thead>
                    <tr>
                        <th>Donor Name</th>
                        <th>Donor Type</th>
                        <th>Location</th>
                        <th style="text-align: center;">Reviews</th>
                        <th style="text-align: center;">Quality</th>
                        <th style="text-align: center;">Quantity</th>
                        <th style="text-align: center;">Timeliness</th>
                        <th style="text-align: right;">Overall Score</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($detailedDonorRatings) === 0): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 15px;">No donor reviews registered yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($detailedDonorRatings as $dr): 
                            $overall = round($dr['avg_overall'], 1);
                            $q = round($dr['avg_quality'], 1);
                            $qty = round($dr['avg_quantity'], 1);
                            $t = round($dr['avg_timeliness'], 1);
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($dr['name']); ?></strong></td>
                                <td>
                                    <span class="role-badge" style="background: rgba(251, 191, 36, 0.08); color: var(--accent); border: 1px solid var(--accent-glow); font-size: 0.72rem; padding: 2px 6px; border-radius: var(--radius-sm);">
                                        <?php echo htmlspecialchars($dr['donor_type'] ?? 'Restaurant'); ?>
                                    </span>
                                </td>
                                <td style="font-size: 0.85rem;"><i class="fa-solid fa-location-dot" style="font-size: 0.75rem; color: var(--primary);"></i> <?php echo htmlspecialchars($dr['location']); ?></td>
                                <td style="text-align: center; font-weight: bold;"><?php echo $dr['review_count']; ?></td>
                                <td style="text-align: center; color: #a7f3d0;"><?php echo $dr['review_count'] > 0 ? $q . ' ★' : '-'; ?></td>
                                <td style="text-align: center; color: #a7f3d0;"><?php echo $dr['review_count'] > 0 ? $qty . ' ★' : '-'; ?></td>
                                <td style="text-align: center; color: #a7f3d0;"><?php echo $dr['review_count'] > 0 ? $t . ' ★' : '-'; ?></td>
                                <td style="text-align: right;" class="points-text">
                                    <?php if ($dr['review_count'] > 0): ?>
                                        <strong style="color: var(--accent);"><i class="fa-solid fa-star"></i> <?php echo $overall; ?> / 5</strong>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.8rem;">No reviews</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Active User Accounts Grid -->
    <div class="dashboard-grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px;">
        
        <!-- Left: User Monitoring panel -->
        <section class="glass-container" style="padding: 25px;">
            <h3 class="panel-title" style="margin-bottom: 20px;"><i class="fa-solid fa-users"></i> Network User Monitoring</h3>
            
            <div style="overflow-x: auto;">
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>User Name</th>
                            <th>Role</th>
                            <th>Contact Info</th>
                            <th>Activity Summary</th>
                            <th style="text-align: right;">Avg Rating</th>
                            <th style="text-align: right; width: 140px;">Status / Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($userList as $u): 
                            $roleLabel = ucfirst($u['role']);
                            if ($u['role'] === 'logistics') $roleLabel = 'Logistics Partner';
                            
                            $actStr = '';
                            if ($u['role'] === 'donor') $actStr = "{$u['donor_listings_count']} Posts";
                            elseif ($u['role'] === 'ngo') $actStr = "{$u['ngo_claims_count']} Claims";
                            elseif ($u['role'] === 'volunteer') $actStr = "{$u['volunteer_completed_count']} Deliv";
                            elseif ($u['role'] === 'logistics') $actStr = "{$u['logistics_assignments_count']} Box Runs";
                            else $actStr = "-";
                            
                            $isSuspended = ($u['status'] === 'Suspended');
                        ?>
                            <tr style="<?php echo $isSuspended ? 'opacity: 0.6; background: rgba(239, 68, 68, 0.03);' : ''; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($u['name']); ?></strong>
                                    <span style="font-size: 0.72rem; color: var(--text-muted); display: block;"><?php echo htmlspecialchars($u['email']); ?></span>
                                </td>
                                <td>
                                    <span class="role-badge role-<?php echo $u['role']; ?>">
                                        <?php echo $roleLabel; ?>
                                    </span>
                                </td>
                                <td style="font-size: 0.85rem;">
                                    <div><i class="fa-solid fa-location-dot" style="font-size: 0.75rem; color: var(--primary);"></i> <?php echo htmlspecialchars($u['location']); ?></div>
                                    <div><i class="fa-solid fa-phone" style="font-size: 0.75rem; color: var(--accent);"></i> <?php echo htmlspecialchars($u['contact'] ?? 'No contact'); ?></div>
                                </td>
                                <td style="font-size: 0.88rem; font-weight: bold;"><?php echo $actStr; ?></td>
                                <td style="text-align: right;" class="points-text">
                                    <?php if ($u['role'] === 'donor' || $u['role'] === 'ngo'): ?>
                                        <i class="fa-solid fa-star"></i> <?php echo $u['avg_rating'] > 0 ? round($u['avg_rating'], 1) : 'N/A'; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.8rem;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <?php if ($u['id'] !== $userId): ?>
                                        <!-- Warn -->
                                        <a href="bhandi_admin.php?action=warn&user_id=<?php echo $u['id']; ?>" class="btn btn-outline btn-sm" style="font-size: 0.72rem; padding: 4px 8px; color: var(--accent); border-color: var(--accent-glow); margin-right: 4px;" title="Send Inactivity Warning">
                                            <i class="fa-solid fa-bell"></i>
                                        </a>
                                        <!-- Suspend / Reactivate -->
                                        <?php if ($isSuspended): ?>
                                            <a href="bhandi_admin.php?action=suspend&user_id=<?php echo $u['id']; ?>" class="btn btn-primary btn-sm" style="font-size: 0.72rem; padding: 4px 8px; background: var(--primary); color: #fff; margin-right: 4px;" title="Reactivate Account">
                                                <i class="fa-solid fa-user-check"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="bhandi_admin.php?action=suspend&user_id=<?php echo $u['id']; ?>" class="btn btn-outline btn-sm" style="font-size: 0.72rem; padding: 4px 8px; color: var(--danger); border-color: var(--danger-glow); margin-right: 4px;" title="Suspend Account">
                                                <i class="fa-solid fa-user-slash"></i>
                                            </a>
                                        <?php endif; ?>
                                        <!-- Delete -->
                                        <a href="bhandi_admin.php?action=remove&user_id=<?php echo $u['id']; ?>" onclick="return confirm('Are you sure you want to permanently delete this user from the system?')" class="btn btn-danger btn-sm" style="font-size: 0.72rem; padding: 4px 8px;" title="Delete User">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--primary); font-size: 0.8rem; font-weight: bold;">(You)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Right: Inactive Audit & Container Reuse -->
        <section style="display: flex; flex-direction: column; gap: 30px;">
            
            <!-- Inactive Account auditor (with inline actions) -->
            <div class="glass-container" style="padding: 25px;">
                <h3 class="panel-title" style="margin-bottom: 15px; color: var(--danger);"><i class="fa-solid fa-user-xmark"></i> Inactive Accounts Detected</h3>
                <p style="font-size: 0.78rem; color: var(--text-secondary); margin-bottom: 15px;">Accounts registered over 24 hours ago with zero documented donations, claims, or delivery actions.</p>
                
                <?php if (count($inactiveUsers) === 0): ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 20px 0; font-size: 0.9rem;">Clean Audit! All registered users have active logs.</p>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 12px; max-height: 350px; overflow-y: auto;">
                        <?php foreach ($inactiveUsers as $iu): 
                            $isIuSuspended = ($iu['status'] === 'Suspended');
                        ?>
                            <div class="glass-card" style="padding: 15px; border-left: 3px solid var(--danger); <?php echo $isIuSuspended ? 'opacity:0.6;' : ''; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                    <div>
                                        <strong style="font-size: 0.88rem; display: block; color: #ffffff;"><?php echo htmlspecialchars($iu['name']); ?></strong>
                                        <span style="font-size: 0.72rem; color: var(--text-muted);">Joined: <?php echo date('M d, Y', strtotime($iu['created_at'])); ?></span>
                                    </div>
                                    <span class="role-badge role-<?php echo $iu['role']; ?>" style="font-size: 0.65rem; padding: 2px 6px;">
                                        <?php echo ucfirst($iu['role']); ?>
                                    </span>
                                </div>
                                <div style="display: flex; gap: 8px; justify-content: flex-end; border-top: 1px dashed var(--border-glass); padding-top: 10px; margin-top: 8px;">
                                    <a href="bhandi_admin.php?action=warn&user_id=<?php echo $iu['id']; ?>" class="btn btn-outline btn-sm" style="font-size: 0.7rem; padding: 3px 6px; color: var(--accent); border-color: var(--accent-glow);" title="Send Inactivity Warning">
                                        <i class="fa-solid fa-triangle-exclamation"></i> Warn
                                    </a>
                                    <?php if ($isIuSuspended): ?>
                                        <a href="bhandi_admin.php?action=suspend&user_id=<?php echo $iu['id']; ?>" class="btn btn-primary btn-sm" style="font-size: 0.7rem; padding: 3px 6px; background: var(--primary); color: #fff;" title="Reactivate Account">
                                            <i class="fa-solid fa-user-check"></i> Active
                                        </a>
                                    <?php else: ?>
                                        <a href="bhandi_admin.php?action=suspend&user_id=<?php echo $iu['id']; ?>" class="btn btn-outline btn-sm" style="font-size: 0.7rem; padding: 3px 6px; color: var(--danger); border-color: var(--danger-glow);" title="Suspend Account">
                                            <i class="fa-solid fa-user-slash"></i> Suspend
                                        </a>
                                    <?php endif; ?>
                                    <a href="bhandi_admin.php?action=remove&user_id=<?php echo $iu['id']; ?>" onclick="return confirm('Are you sure you want to permanently delete this user from the system?')" class="btn btn-danger btn-sm" style="font-size: 0.7rem; padding: 3px 6px;" title="Remove User">
                                        <i class="fa-solid fa-trash"></i> Remove
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Top Container Reuses -->
            <div class="glass-container" style="padding: 25px;">
                <h3 class="panel-title" style="margin-bottom: 15px; color: var(--accent);"><i class="fa-solid fa-recycle"></i> Container Fleet Efficiency</h3>
                <p style="font-size: 0.78rem; color: var(--text-secondary); margin-bottom: 15px;">Audit list of container boxes with the highest cumulative reuse counts.</p>
                
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Box Code</th>
                            <th>Size</th>
                            <th style="text-align: right;">Trips Saved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topReusedContainers as $tr): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($tr['container_id']); ?></strong></td>
                                <td><?php echo $tr['size']; ?></td>
                                <td style="text-align: right;" class="points-text"><?php echo $tr['times_used']; ?> Runs 🔄</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </section>

    </div>

</main>

<style>
@media print {
    body {
        background: #ffffff !important;
        color: #000000 !important;
    }
    .app-header, nav, footer, .btn, .stats-grid, section:not(.print-report-container), h1, p {
        display: none !important;
    }
    .print-report-container {
        border: none !important;
        background: transparent !important;
        box-shadow: none !important;
        width: 100% !important;
        color: #000000 !important;
    }
    .glass-card {
        border: 1px solid #cccccc !important;
        color: #000000 !important;
    }
}
</style>

</body>
</html>
