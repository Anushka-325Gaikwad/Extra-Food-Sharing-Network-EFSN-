<?php
/**
 * Update Profile Module
 * Allows users to edit details, upload a profile picture, and view role-specific statistics
 */
require_once 'config.php';

// Auth Guard: User must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

$errorMsg = '';
$successMsg = '';

// Fetch current user details from database
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        die("User profile not found.");
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Fetch Role-Specific Statistics
$stats = [];
try {
    if ($userRole === 'donor') {
        // 1. Total listed items
        $sStmt = $pdo->prepare("SELECT COUNT(*) FROM food_listings WHERE donor_id = ?");
        $sStmt->execute([$userId]);
        $stats['total_listings'] = $sStmt->fetchColumn();
        
        // 2. Average Rating
        $sStmt = $pdo->prepare("SELECT IFNULL(AVG(rating), 0), COUNT(*) FROM bhandi_ratings WHERE to_user_id = ?");
        $sStmt->execute([$userId]);
        $rRow = $sStmt->fetch(PDO::FETCH_NUM);
        $stats['avg_rating'] = round($rRow[0], 1);
        $stats['review_count'] = $rRow[1];
        
        // 3. Impact Score (Points)
        $sStmt = $pdo->prepare("SELECT IFNULL(points, 0) FROM rewards WHERE user_id = ?");
        $sStmt->execute([$userId]);
        $stats['points'] = $sStmt->fetchColumn() ?: 0;
        
    } elseif ($userRole === 'ngo') {
        // 1. Total claims made
        $sStmt = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE ngo_id = ?");
        $sStmt->execute([$userId]);
        $stats['total_claims'] = $sStmt->fetchColumn();
        
        // 2. Total servings rescued
        $sStmt = $pdo->prepare("SELECT IFNULL(SUM(quantity), 0) FROM claims WHERE ngo_id = ? AND status = 'delivered'");
        $sStmt->execute([$userId]);
        $stats['total_servings'] = $sStmt->fetchColumn();
        
    } elseif ($userRole === 'volunteer') {
        // 1. Completed deliveries
        $sStmt = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE volunteer_id = ? AND status = 'delivered'");
        $sStmt->execute([$userId]);
        $stats['completed_deliveries'] = $sStmt->fetchColumn();
        
        // 2. Points
        $sStmt = $pdo->prepare("SELECT IFNULL(points, 0) FROM rewards WHERE user_id = ?");
        $sStmt->execute([$userId]);
        $stats['points'] = $sStmt->fetchColumn() ?: 0;
        
    } elseif ($userRole === 'logistics') {
        // 1. Active container assignments
        $sStmt = $pdo->prepare("SELECT COUNT(*) FROM bhandi_assignments WHERE logistics_partner_id = ? AND status = 'Active'");
        $sStmt->execute([$userId]);
        $stats['active_assignments'] = $sStmt->fetchColumn();
        
        // 2. Total fleet containers
        $stats['total_containers'] = $pdo->query("SELECT COUNT(*) FROM bhandi_containers")->fetchColumn();
        
    } elseif ($userRole === 'admin') {
        // 1. Total registered users
        $stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        
        // 2. Suspended accounts
        $stats['suspended_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Suspended'")->fetchColumn();
        
        // 3. Expiration/Inactivity Warnings sent
        $stats['inactive_warnings'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Active' AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY) AND role != 'admin'")->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("Stats retrieval failed: " . $e->getMessage());
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $donor_type = trim($_POST['donor_type'] ?? '');
    
    if (empty($name) || empty($contact) || empty($location)) {
        $errorMsg = "Name, contact details, and location are required.";
    } else {
        $profilePicPath = $user['profile_pic'];
        
        // Handle Image Upload
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['profile_image']['tmp_name'];
            $fileName = $_FILES['profile_image']['name'];
            $fileSize = $_FILES['profile_image']['size'];
            $fileType = $_FILES['profile_image']['type'];
            
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                $errorMsg = "Invalid file extension. Only JPG, JPEG, PNG, and GIF files are allowed.";
            } elseif ($fileSize > 2 * 1024 * 1024) { // 2MB Limit
                $errorMsg = "File size exceeds the 2MB limit.";
            } else {
                // Generate a unique clean filename
                $newFileName = 'avatar_' . $userId . '_' . time() . '.' . $fileExtension;
                $uploadFileDir = __DIR__ . '/uploads/';
                $destPath = $uploadFileDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    // Delete old profile picture if exists
                    if (!empty($user['profile_pic']) && file_exists(__DIR__ . '/' . $user['profile_pic'])) {
                        unlink(__DIR__ . '/' . $user['profile_pic']);
                    }
                    $profilePicPath = 'uploads/' . $newFileName;
                } else {
                    $errorMsg = "There was an error moving the uploaded file to the destination folder.";
                }
            }
        }
        
        if (empty($errorMsg)) {
            try {
                // Update query based on role
                if ($userRole === 'donor') {
                    $upStmt = $pdo->prepare("UPDATE users SET name = ?, contact = ?, location = ?, donor_type = ?, profile_pic = ? WHERE id = ?");
                    $upStmt->execute([$name, $contact, $location, $donor_type, $profilePicPath, $userId]);
                } else {
                    $upStmt = $pdo->prepare("UPDATE users SET name = ?, contact = ?, location = ?, profile_pic = ? WHERE id = ?");
                    $upStmt->execute([$name, $contact, $location, $profilePicPath, $userId]);
                }
                
                // Instantly update session parameters to reflect across the site
                $_SESSION['name'] = $name;
                $_SESSION['contact'] = $contact;
                $_SESSION['location'] = $location;
                
                $successMsg = "🎉 Profile updated successfully! Changes are now active across the network.";
                
                // Refresh local user variables
                $user['name'] = $name;
                $user['contact'] = $contact;
                $user['location'] = $location;
                $user['profile_pic'] = $profilePicPath;
                if ($userRole === 'donor') {
                    $user['donor_type'] = $donor_type;
                }
            } catch (PDOException $e) {
                $errorMsg = "Database update failed: " . $e->getMessage();
            }
        }
    }
}

require_once 'header.php';
?>

<main class="main-content">
    <div style="max-width: 900px; margin: 40px auto;">
        
        <div class="panel-header" style="margin-bottom: 25px;">
            <h2 class="panel-title"><i class="fa-solid fa-user-gear"></i> Account Profile Settings</h2>
            <a href="dashboard.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        </div>

        <?php if (!empty($errorMsg)): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 25px; font-size: 0.9rem; color: #fca5a5; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-exclamation" style="color: var(--danger);"></i>
                <div><?php echo htmlspecialchars($errorMsg); ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMsg)): ?>
            <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--primary); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 25px; font-size: 0.9rem; color: #a7f3d0; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check" style="color: var(--primary);"></i>
                <div><?php echo htmlspecialchars($successMsg); ?></div>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1.2fr 2fr; gap: 30px; align-items: start;">
            
            <!-- Left Side: Role-Specific Summary & Preview -->
            <section style="display: flex; flex-direction: column; gap: 30px;">
                
                <!-- Avatar Preview Card -->
                <div class="glass-container" style="padding: 30px; text-align: center;">
                    <div style="width: 120px; height: 120px; border-radius: 50%; overflow: hidden; margin: 0 auto 15px auto; background: var(--bg-card); display: flex; justify-content: center; align-items: center; border: 3px solid var(--accent); box-shadow: 0 0 15px var(--accent-glow);">
                        <?php if (!empty($user['profile_pic']) && file_exists(__DIR__ . '/' . $user['profile_pic'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_pic']); ?>?t=<?php echo time(); ?>" style="width: 100%; height: 100%; object-fit: cover;" alt="Avatar" />
                        <?php else: ?>
                            <span style="font-size: 3rem; font-weight: bold; color: var(--accent);"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <h3 style="color: #ffffff; margin-bottom: 5px;"><?php echo htmlspecialchars($user['name']); ?></h3>
                    <span class="role-badge role-<?php echo $userRole; ?>" style="display: inline-block; margin-bottom: 10px;">
                        <?php 
                        $rLabel = ucfirst($userRole);
                        if ($userRole === 'logistics') $rLabel = 'Logistics Partner';
                        echo $rLabel; 
                        ?>
                    </span>
                    <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0;">Registered: <?php echo date('M d, Y', strtotime($user['created_at'])); ?></p>
                </div>

                <!-- Stats summary block -->
                <div class="glass-container" style="padding: 25px;">
                    <h4 style="margin-bottom: 15px; color: var(--accent); border-bottom: 1px solid var(--border-glass); padding-bottom: 8px;"><i class="fa-solid fa-chart-line"></i> Activity Statistics</h4>
                    
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php if ($userRole === 'donor'): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Donor Type:</span>
                                <strong style="color: #ffffff;"><?php echo htmlspecialchars($user['donor_type'] ?? 'Restaurant'); ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Total Listings:</span>
                                <strong style="color: #ffffff;"><?php echo $stats['total_listings']; ?> Posts</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Average Star Rating:</span>
                                <strong style="color: var(--accent);"><i class="fa-solid fa-star"></i> <?php echo $stats['avg_rating'] > 0 ? $stats['avg_rating'] . ' ★ (' . $stats['review_count'] . ' reviews)' : 'No reviews'; ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Reward Points:</span>
                                <strong style="color: var(--primary);"><?php echo $stats['points']; ?> Points 🏆</strong>
                            </div>
                            
                        <?php elseif ($userRole === 'ngo'): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Total Claim Operations:</span>
                                <strong style="color: #ffffff;"><?php echo $stats['total_claims']; ?> Claims</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Total Servings Rescued:</span>
                                <strong style="color: var(--primary);"><?php echo $stats['total_servings']; ?> Servings 📦</strong>
                            </div>
                            
                        <?php elseif ($userRole === 'volunteer'): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Completed Deliveries:</span>
                                <strong style="color: #ffffff;"><?php echo $stats['completed_deliveries']; ?> Shipments</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Points earned:</span>
                                <strong style="color: var(--primary);"><?php echo $stats['points']; ?> Points 🏆</strong>
                            </div>
                            
                        <?php elseif ($userRole === 'logistics'): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Active Container Shipments:</span>
                                <strong style="color: #ffffff;"><?php echo $stats['active_assignments']; ?> assigned</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Containers Fleet Inventory:</span>
                                <strong style="color: var(--primary);"><?php echo $stats['total_containers']; ?> Units 📦</strong>
                            </div>
                            
                        <?php elseif ($userRole === 'admin'): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Total Network Users:</span>
                                <strong style="color: #ffffff;"><?php echo $stats['total_users']; ?> Accounts</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Suspended users:</span>
                                <strong style="color: var(--danger);"><?php echo $stats['suspended_users']; ?> Locked</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <span style="color: var(--text-secondary);">Inactive User Warning Pool:</span>
                                <strong style="color: var(--accent);"><?php echo $stats['inactive_warnings']; ?> Active Accounts</strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
            
            <!-- Right Side: Editable details form -->
            <section class="glass-container" style="padding: 35px;">
                <h3 style="color: #ffffff; margin-bottom: 20px;"><i class="fa-solid fa-user-pen"></i> Edit Profile Details</h3>
                
                <form action="bhandi_profile.php" method="POST" enctype="multipart/form-data">
                    
                    <div class="form-group">
                        <label class="form-label" for="profileName">Full Display Name</label>
                        <input type="text" name="name" id="profileName" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" required autocomplete="name" />
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="profileEmail">Registered Email (Read-Only)</label>
                        <input type="email" name="email" id="profileEmail" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" disabled style="opacity: 0.6; cursor: not-allowed;" />
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="profileContact">Contact / Mobile Number</label>
                        <input type="text" name="contact" id="profileContact" class="form-control" value="<?php echo htmlspecialchars($user['contact'] ?? ''); ?>" required autocomplete="tel" />
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="profileLocation">Operating Location / Address</label>
                        <input type="text" name="location" id="profileLocation" class="form-control" value="<?php echo htmlspecialchars($user['location']); ?>" required />
                    </div>

                    <?php if ($userRole === 'donor'): ?>
                        <div class="form-group">
                            <label class="form-label" for="profileDonorType">Donor Type Classification</label>
                            <select name="donor_type" id="profileDonorType" class="form-control">
                                <option value="Restaurant" <?php echo ($user['donor_type'] === 'Restaurant') ? 'selected' : ''; ?>>Restaurant / Bistro</option>
                                <option value="Hotel" <?php echo ($user['donor_type'] === 'Hotel') ? 'selected' : ''; ?>>Hotel / Banquet Hall</option>
                                <option value="Home Kitchen" <?php echo ($user['donor_type'] === 'Home Kitchen') ? 'selected' : ''; ?>>Home Kitchen</option>
                                <option value="Catering" <?php echo ($user['donor_type'] === 'Catering') ? 'selected' : ''; ?>>Catering Service</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="form-group" style="margin-top: 25px; border-top: 1px solid var(--border-glass); padding-top: 20px;">
                        <label class="form-label" for="profileImage"><i class="fa-solid fa-image"></i> Change Profile Picture</label>
                        <span style="font-size: 0.75rem; color: var(--text-secondary); display: block; margin-bottom: 8px;">Upload a clean square image (JPG, PNG, GIF) up to 2MB.</span>
                        <input type="file" name="profile_image" id="profileImage" class="form-control" style="background: rgba(255,255,255,0.03); border-color: var(--border-glass);" accept="image/png, image/jpeg, image/gif" />
                    </div>
                    
                    <button type="submit" name="update_profile" class="btn btn-accent" style="width: 100%; margin-top: 20px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Profile Settings
                    </button>
                    
                </form>
            </section>
            
        </div>
    </div>
</main>

</body>
</html>
