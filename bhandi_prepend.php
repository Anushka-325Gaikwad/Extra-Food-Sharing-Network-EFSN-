<?php
/**
 * Bhandi (Container) Management Extension Plugin
 * Core Hook & Output Buffer Interceptor
 */

// Initialize Database connection and session
require_once 'C:/xampp/htdocs/EXTRA FOO/config.php';

// Helper: Notify all users with a specific role
if (!function_exists('notifyAllByRole')) {
    function notifyAllByRole($role, $message) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE role = ?");
            $stmt->execute([$role]);
            $users = $stmt->fetchAll();
            foreach ($users as $user) {
                notifyUser($user['id'], $message);
            }
            return true;
        } catch (PDOException $e) {
            error_log("Notify role error: " . $e->getMessage());
            return false;
        }
    }
}

// 1. Run Time-Based Overdue Checks in real-time
function checkOverdueAssignments($pdo) {
    $now = date('Y-m-d H:i:s');
    try {
        $stmt = $pdo->prepare("
            SELECT a.id, a.container_id, a.food_listing_id, a.volunteer_id, a.logistics_partner_id, f.food_name 
            FROM bhandi_assignments a
            JOIN food_listings f ON a.food_listing_id = f.id
            WHERE a.status = 'Active' AND a.expiry_time <= ?
        ");
        $stmt->execute([$now]);
        $overdue = $stmt->fetchAll();
        
        foreach ($overdue as $assignment) {
            $pdo->beginTransaction();
            // Update assignment status
            $up1 = $pdo->prepare("UPDATE bhandi_assignments SET status = 'Overdue' WHERE id = ?");
            $up1->execute([$assignment['id']]);
            
            // Update container status
            $up2 = $pdo->prepare("UPDATE bhandi_containers SET status = 'Overdue' WHERE container_id = ?");
            $up2->execute([$assignment['container_id']]);
            
            // Trigger alert notifications
            $msg = "🚨 OVERDUE CONTAINER ALERT: Container '{$assignment['container_id']}' for food '{$assignment['food_name']}' has exceeded its 3-hour limit and is OVERDUE!";
            notifyUser($assignment['logistics_partner_id'], $msg);
            if ($assignment['volunteer_id']) {
                notifyUser($assignment['volunteer_id'], $msg);
            }
            $pdo->commit();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Bhandi overdue engine error: " . $e->getMessage());
    }
}

checkOverdueAssignments($pdo);

// 2. Inactive User Detection Daemon (System detects inactive users and sends admin alerts)
function checkInactiveUsers($pdo) {
    try {
        // Find users registered > 24 hours ago, with 0 activity, who haven't triggered an admin alert yet
        $stmt = $pdo->query("
            SELECT u.id, u.name, u.role
            FROM users u
            WHERE u.inactive_alert_sent = 0 AND u.role != 'admin'
              AND (SELECT COUNT(*) FROM food_listings WHERE donor_id = u.id) = 0
              AND (SELECT COUNT(*) FROM claims WHERE ngo_id = u.id) = 0
              AND (SELECT COUNT(*) FROM claims WHERE volunteer_id = u.id) = 0
              AND (SELECT COUNT(*) FROM bhandi_assignments WHERE logistics_partner_id = u.id) = 0
              AND u.created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $inactives = $stmt->fetchAll();
        
        foreach ($inactives as $user) {
            $pdo->beginTransaction();
            try {
                // Mark alert as sent
                $up = $pdo->prepare("UPDATE users SET inactive_alert_sent = 1 WHERE id = ?");
                $up->execute([$user['id']]);
                
                // Notify all admins
                $msg = "⚠️ INACTIVE USER ALERT: User '" . $user['name'] . "' (" . ucfirst($user['role']) . ") has been flagged as inactive. Please audit and warn/suspend/remove.";
                notifyAllByRole('admin', $msg);
                
                $pdo->commit();
            } catch (Exception $ex) {
                $pdo->rollBack();
            }
        }
    } catch (PDOException $e) {
        error_log("Bhandi inactivity daemon error: " . $e->getMessage());
    }
}

checkInactiveUsers($pdo);

// 3. User Trust Lock: Prevent suspended users from accessing dashboard/session
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userStatus = $stmt->fetchColumn();
        if ($userStatus === 'Suspended') {
            $_SESSION = [];
            session_destroy();
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['plugin_error'] = "❌ ACCESS DENIED: Your EFSN account has been suspended by the Admin.";
            header("Location: login.php");
            exit;
        }
    } catch (PDOException $e) {}
}

// Safe check variables
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$scriptName = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '';

// 4. Intercept Registration of Logistics Partner and Admin
if ($requestMethod === 'POST' && isset($_POST['register_submit'])) {
    $role = $_POST['reg_role'] ?? '';
    if (in_array($role, ['logistics', 'admin'])) {
        $name = trim($_POST['reg_name'] ?? '');
        $email = trim($_POST['reg_email'] ?? '');
        $contact = trim($_POST['reg_contact'] ?? '');
        $password = trim($_POST['reg_password'] ?? '');
        $password_conf = trim($_POST['reg_password_conf'] ?? '');
        $location = trim($_POST['reg_location'] ?? '');
        
        if (empty($name) || empty($email) || empty($contact) || empty($password) || empty($location)) {
            $_SESSION['plugin_error'] = "Please complete all fields in the registration form.";
            header("Location: login.php?register=1");
            exit;
        } elseif ($password !== $password_conf) {
            $_SESSION['plugin_error'] = "Passwords do not match. Please verify.";
            header("Location: login.php?register=1");
            exit;
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                
                if ($stmt->fetch()) {
                    $_SESSION['plugin_error'] = "This email is already associated with an EFSN account.";
                    header("Location: login.php?register=1");
                    exit;
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $insertStmt = $pdo->prepare("INSERT INTO users (name, email, password, role, location, contact) VALUES (?, ?, ?, ?, ?, ?)");
                    $insertStmt->execute([$name, $email, $hashedPassword, $role, $location, $contact]);
                    $newUserId = $pdo->lastInsertId();
                    
                    $rewardInit = $pdo->prepare("INSERT INTO rewards (user_id, points) VALUES (?, 0)");
                    $rewardInit->execute([$newUserId]);
                    
                    $_SESSION['plugin_success'] = "🎉 Registration successful as " . ($role === 'logistics' ? 'Logistics Partner' : 'Admin') . "! You can now log in below.";
                    header("Location: login.php");
                    exit;
                }
            } catch (PDOException $e) {
                $_SESSION['plugin_error'] = "Database Registry Error: " . $e->getMessage();
                header("Location: login.php?register=1");
                exit;
            }
        }
    }
}

// 5. Post-Process Food Listings for Container Requirements
register_shutdown_function(function() use ($pdo, $requestMethod, $scriptName) {
    if ($requestMethod === 'POST' && $scriptName === 'add_food.php') {
        if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'donor') {
            $stmt = $pdo->prepare("SELECT id FROM food_listings WHERE donor_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $foodId = $stmt->fetchColumn();
            
            if ($foodId) {
                $check = $pdo->prepare("SELECT food_listing_id FROM bhandi_food_requirements WHERE food_listing_id = ?");
                $check->execute([$foodId]);
                if (!$check->fetchColumn()) {
                    $needsContainers = isset($_POST['needs_containers']) ? intval($_POST['needs_containers']) : 0;
                    $containerSize = isset($_POST['container_size']) ? $_POST['container_size'] : 'Medium';
                    
                    $ins = $pdo->prepare("INSERT INTO bhandi_food_requirements (food_listing_id, needs_containers, container_size) VALUES (?, ?, ?)");
                    $ins->execute([$foodId, $needsContainers, $containerSize]);
                    
                    if ($needsContainers) {
                        $donorName = $_SESSION['name'];
                        $msg = "📢 CONTAINER REQUIRED: Donor '{$donorName}' needs a {$containerSize} container for their food listing #{$foodId}!";
                        notifyAllByRole('logistics', $msg);
                    }
                }
            }
        }
    }
});

// 6. Redirect Logistics and Admin from standard dashboard
if ($scriptName === 'dashboard.php') {
    if (isset($_SESSION['role'])) {
        if ($_SESSION['role'] === 'logistics') {
            header("Location: bhandi_logistics.php");
            exit;
        } elseif ($_SESSION['role'] === 'admin') {
            header("Location: bhandi_admin.php");
            exit;
        }
    }
}

// 7. Output Buffer Injection for UI Add-ons
ob_start('bhandi_ui_interceptor');

function bhandi_ui_interceptor($buffer) {
    global $pdo, $scriptName;
    
    // Inject Custom Alerts in login.php
    if ($scriptName === 'login.php') {
        if (isset($_SESSION['plugin_error'])) {
            $err = htmlspecialchars($_SESSION['plugin_error']);
            $alert = '<div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 20px; font-size: 0.9rem; color: #fca5a5; display: flex; align-items: center; gap: 10px;">' .
                     '<i class="fa-solid fa-circle-exclamation" style="color: var(--danger);"></i>' .
                     '<div>' . $err . '</div>' .
                     '</div>';
            $buffer = str_replace('<div class="glass-container auth-wrapper">', '<div class="glass-container auth-wrapper">' . $alert, $buffer);
            unset($_SESSION['plugin_error']);
        }
        if (isset($_SESSION['plugin_success'])) {
            $succ = htmlspecialchars($_SESSION['plugin_success']);
            $alert = '<div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--primary); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 20px; font-size: 0.9rem; color: #a7f3d0; display: flex; align-items: center; gap: 10px;">' .
                     '<i class="fa-solid fa-circle-check" style="color: var(--primary);"></i>' .
                     '<div>' . $succ . '</div>' .
                     '</div>';
            $buffer = str_replace('<div class="glass-container auth-wrapper">', '<div class="glass-container auth-wrapper">' . $alert, $buffer);
            unset($_SESSION['plugin_success']);
        }
        
        // Inject Logistics Partner and Admin options in Register Select Form
        $origSelect = '<option value="volunteer">Volunteer Rescuer (Delivery Partner)</option>';
        $newSelect = '<option value="volunteer">Volunteer Rescuer (Delivery Partner)</option>' . "\n" .
                     '                        <option value="logistics">Food Logistics Partner (Provides Containers)</option>' . "\n" .
                     '                        <option value="admin">System Admin (Monitor & Report)</option>';
        $buffer = str_replace($origSelect, $newSelect, $buffer);
    }
    
    // Inject Bhandi requirements form in add_food.php
    if ($scriptName === 'add_food.php') {
        $bhandiFields = '
                <!-- Bhandi (Container) Management Extension Field -->
                <div class="form-group" style="border-top: 1px solid var(--border-glass); padding-top: 20px;">
                    <label class="form-label" for="needsContainers"><i class="fa-solid fa-boxes-packing" style="color: var(--accent);"></i> Do you have containers (bhandi) for safe transportation?</label>
                    <select name="needs_containers" id="needsContainers" class="form-control" onchange="toggleBhandiSize(this.value)">
                        <option value="0" selected>Yes, I have my own containers</option>
                        <option value="1">No, I need containers provided by Logistics Partner</option>
                    </select>
                </div>
                
                <div class="form-group" id="bhandiSizeGroup" style="display: none;">
                    <label class="form-label" for="containerSize">Preferred Container Size</label>
                    <select name="container_size" id="containerSize" class="form-control">
                        <option value="Small">Small (under 10 servings)</option>
                        <option value="Medium" selected>Medium (10 - 30 servings)</option>
                        <option value="Large">Large (30+ servings)</option>
                    </select>
                </div>
                
                <script>
                function toggleBhandiSize(val) {
                    const group = document.getElementById("bhandiSizeGroup");
                    if (group) {
                        group.style.display = (val === "1") ? "block" : "none";
                    }
                }
                </script>
        ';
        $buffer = str_replace('<div style="margin-top: 30px;">', $bhandiFields . '<div style="margin-top: 30px;">', $buffer);
    }
    
    // Inject Custom Navigation menu headers
    if (isset($_SESSION['user_id'])) {
        $role = $_SESSION['role'];
        $navInjection = '';
        if ($role === 'logistics') {
            $navInjection = '<li><a href="bhandi_logistics.php" class="nav-link"><i class="fa-solid fa-boxes-stacked"></i> Logistics Panel</a></li>' . "\n" .
                           '<li><a href="bhandi_map.php" class="nav-link"><i class="fa-solid fa-map-location-dot"></i> Live Map</a></li>';
        } elseif ($role === 'admin') {
            $navInjection = '<li><a href="bhandi_admin.php" class="nav-link"><i class="fa-solid fa-user-shield"></i> Admin Panel</a></li>' . "\n" .
                           '<li><a href="bhandi_map.php" class="nav-link"><i class="fa-solid fa-map-location-dot"></i> Live Map</a></li>';
        } elseif ($role === 'donor') {
            $navInjection = '<li><a href="bhandi_donor_ratings.php" class="nav-link"><i class="fa-solid fa-star"></i> My Ratings</a></li>' . "\n" .
                           '<li><a href="bhandi_map.php" class="nav-link"><i class="fa-solid fa-map-location-dot"></i> Live Map</a></li>';
        } else {
            $navInjection = '<li><a href="bhandi_map.php" class="nav-link"><i class="fa-solid fa-map-location-dot"></i> Live Map</a></li>';
        }
        
        $buffer = str_replace('<li><a href="dashboard.php" class="nav-link"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>', '<li><a href="dashboard.php" class="nav-link"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>' . "\n" . $navInjection, $buffer);
        
        // Inject ratings display and pending feedback alerts in main dashboards
        if ($scriptName === 'dashboard.php') {
            // A. Inject Rating/Donor Type in Profile card
            $ratingHtml = '';
            if ($_SESSION['role'] === 'donor') {
                // Fetch donor type dynamically
                $dTypeStmt = $pdo->prepare("SELECT donor_type FROM users WHERE id = ?");
                $dTypeStmt->execute([$_SESSION['user_id']]);
                $dType = $dTypeStmt->fetchColumn();
                $dTypeLabel = htmlspecialchars($dType ?? 'Donor');
                
                $ratingHtml = '<div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-secondary); text-align: center;">' .
                              'Type: <strong style="color: #ffffff;">' . $dTypeLabel . '</strong>' .
                              '</div>' .
                              '<div style="margin-top: 10px; text-align: center;">' .
                              '<a href="bhandi_donor_ratings.php" class="btn btn-accent btn-sm" style="font-size: 0.72rem; padding: 3px 8px; border-radius: var(--radius-sm);"><i class="fa-solid fa-star"></i> My Food Ratings</a>' .
                              '</div>';
            }
            
            if (!empty($ratingHtml)) {
                // Look for badge closing span in dashboard.php (around line 104)
                $badgeFind = 'Role' . "\n" . '            </span>';
                $buffer = str_replace($badgeFind, $badgeFind . $ratingHtml, $buffer);
            }
            
            // B. Inject Profile Picture in evaluated avatar circle
            $uStmt = $pdo->prepare("SELECT profile_pic FROM users WHERE id = ?");
            $uStmt->execute([$_SESSION['user_id']]);
            $profPic = $uStmt->fetchColumn();
            
            if (!empty($profPic) && file_exists(__DIR__ . '/' . $profPic)) {
                $imgHtml = '<div class="avatar-circle" style="background: transparent; border: 3px solid var(--accent); overflow: hidden; display: flex; justify-content: center; align-items: center; box-shadow: 0 0 15px var(--accent-glow);">' .
                           '<img src="' . htmlspecialchars($profPic) . '?t=' . time() . '" style="width: 100%; height: 100%; object-fit: cover;" alt="Avatar" />' .
                           '</div>';
                $buffer = preg_replace('/<div class="avatar-circle">.*?<\/div>/s', $imgHtml, $buffer, 1);
            }
            
            // C. Inject Update Profile button on profile card
            $profileBtn = '<a href="bhandi_profile.php" class="btn btn-outline" style="width: 100%; border-color: var(--accent); color: var(--accent); margin-top: 10px;"><i class="fa-solid fa-user-pen"></i> Update Profile</a>';
            $buffer = str_replace('<div style="margin-top: 25px; display: flex; flex-direction: column; gap: 10px;">', '<div style="margin-top: 25px; display: flex; flex-direction: column; gap: 10px;">' . "\n" . $profileBtn, $buffer);
            
            // B. Inject Unrated Deliveries Alert Box (Only for NGOs)
            $unratedHtml = '';
            if ($role === 'ngo') {
                $stmt = $pdo->prepare("
                    SELECT c.id as claim_id, f.food_name, u_don.name as donor_name 
                    FROM claims c
                    JOIN food_listings f ON c.food_listing_id = f.id
                    JOIN users u_don ON f.donor_id = u_don.id
                    WHERE c.ngo_id = ? AND c.status = 'delivered'
                      AND c.id NOT IN (SELECT claim_id FROM bhandi_ratings WHERE from_user_id = ?)
                    LIMIT 3
                ");
                $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
                $unrated = $stmt->fetchAll();
                
                if (count($unrated) > 0) {
                    $unratedHtml = '
                    <div class="glass-container" style="padding: 20px; margin-bottom: 25px; border-left: 4px solid var(--accent); background: rgba(251, 191, 36, 0.05);">
                        <h4 style="color: var(--accent); margin-bottom: 8px;"><i class="fa-solid fa-star-half-stroke"></i> Pending Partner Feedback</h4>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 12px;">You have completed food rescue operations awaiting mutual trust ratings. Please submit your feedback:</p>
                        <ul style="list-style: none; font-size: 0.88rem; display: flex; flex-direction: column; gap: 8px;">';
                    foreach ($unrated as $row) {
                        $unratedHtml .= '<li>📦 Food item <strong>' . htmlspecialchars($row['food_name']) . '</strong> from ' . htmlspecialchars($row['donor_name']) . ' was successfully delivered. <a href="bhandi_feedback.php?claim_id=' . $row['claim_id'] . '" class="btn btn-accent btn-sm" style="padding: 2px 8px; font-size: 0.72rem; margin-left: 10px;">Rate Food Quality</a></li>';
                    }
                    $unratedHtml .= '</ul></div>';
                }
            }
            
            if (!empty($unratedHtml)) {
                // Inject right at the beginning of the interactive dashboard panel section (first occurrence of <div class="glass-container")
                $buffer = preg_replace('/' . preg_quote('<div class="glass-container"', '/') . '/', $unratedHtml . '<div class="glass-container"', $buffer, 1);
            }
        }
    }
    
    return $buffer;
}
?>
