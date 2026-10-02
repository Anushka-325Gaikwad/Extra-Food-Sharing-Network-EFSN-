<?php
/**
 * Extra Food Sharing Network (EFSN)
 * Seamless Unified Registration & Authentication Portal
 */
require_once 'config.php';

// Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$errorMsg = '';
$successMsg = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Process LOGIN
    if (isset($_POST['login_submit'])) {
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);
        
        if (empty($email) || empty($password)) {
            $errorMsg = "Please fill in all login credentials.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password'])) {
                    // Setup Session Variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['location'] = $user['location'];
                    $_SESSION['contact'] = $user['contact'];
                    
                    header("Location: dashboard.php");
                    exit;
                } else {
                    $errorMsg = "Invalid email address or password.";
                }
            } catch (PDOException $e) {
                $errorMsg = "System Authentication Error: " . $e->getMessage();
            }
        }
    }
    
    // 2. Process REGISTRATION
    if (isset($_POST['register_submit'])) {
        $name = trim($_POST['reg_name']);
        $email = trim($_POST['reg_email']);
        $contact = trim($_POST['reg_contact']);
        $password = trim($_POST['reg_password']);
        $password_conf = trim($_POST['reg_password_conf']);
        $role = $_POST['reg_role'];
        $location = trim($_POST['reg_location']);
        
        if (empty($name) || empty($email) || empty($contact) || empty($password) || empty($location)) {
            $errorMsg = "Please complete all fields in the registration form.";
        } elseif ($password !== $password_conf) {
            $errorMsg = "Passwords do not match. Please verify.";
        } elseif (!in_array($role, ['donor', 'ngo', 'volunteer'])) {
            $errorMsg = "Selected user role is invalid.";
        } else {
            $donor_type = null;
            if ($role === 'donor') {
                $donor_type = $_POST['reg_donor_type'] ?? '';
                if (empty($donor_type)) {
                    $errorMsg = "Please select your Donor Type.";
                }
            }
            
            if (empty($errorMsg)) {
                try {
                    // Check if email already registered
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    
                    if ($stmt->fetch()) {
                        $errorMsg = "This email is already associated with an EFSN account.";
                    } else {
                        // Hash the password securely
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        
                        // Insert new user
                        $insertStmt = $pdo->prepare("INSERT INTO users (name, email, password, role, location, contact, donor_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $insertStmt->execute([$name, $email, $hashedPassword, $role, $location, $contact, $donor_type]);
                        $newUserId = $pdo->lastInsertId();
                        
                        // Initialize their reward points to 0
                        $rewardInit = $pdo->prepare("INSERT INTO rewards (user_id, points) VALUES (?, 0)");
                        $rewardInit->execute([$newUserId]);
                        
                        $successMsg = "🎉 Registration successful! You can now log in below.";
                    }
                } catch (PDOException $e) {
                    $errorMsg = "Database Registry Error: " . $e->getMessage();
                }
            }
        }
    }
}

// Check if directed to Register Tab from home page
$showRegister = isset($_GET['register']) ? true : false;
?>

<?php require_once 'header.php'; ?>

<main class="main-content">
    
    <div class="glass-container auth-wrapper">
        
        <!-- Toggle Tabs -->
        <div class="auth-tabs">
            <div class="auth-tab <?php echo !$showRegister ? 'active' : ''; ?>" id="tabLogin" onclick="switchTab('login')">
                <i class="fa-solid fa-right-to-bracket"></i> Login
            </div>
            <div class="auth-tab <?php echo $showRegister ? 'active' : ''; ?>" id="tabRegister" onclick="switchTab('register')">
                <i class="fa-solid fa-user-plus"></i> Register
            </div>
        </div>
        
        <!-- Action Alerts -->
        <?php if (!empty($errorMsg)): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 20px; font-size: 0.9rem; color: #fca5a5; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-exclamation" style="color: var(--danger);"></i>
                <div><?php echo htmlspecialchars($errorMsg); ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMsg)): ?>
            <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--primary); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 20px; font-size: 0.9rem; color: #a7f3d0; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check" style="color: var(--primary);"></i>
                <div><?php echo htmlspecialchars($successMsg); ?></div>
            </div>
        <?php endif; ?>

        <!-- 1. LOGIN FORM -->
        <section class="auth-form-section <?php echo !$showRegister ? 'active' : ''; ?>" id="formLoginSection">
            <form action="login.php" method="POST">
                <div class="form-group">
                    <label class="form-label" for="loginEmail">Email Address</label>
                    <input type="email" name="email" id="loginEmail" class="form-control" placeholder="name@example.com" required autocomplete="email">
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="loginPassword">Password</label>
                    <input type="password" name="password" id="loginPassword" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                </div>
                
                <button type="submit" name="login_submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                    <i class="fa-solid fa-right-to-bracket"></i> Authenticate Account
                </button>
            </form>
        </section>

        <!-- 2. REGISTRATION FORM -->
        <section class="auth-form-section <?php echo $showRegister ? 'active' : ''; ?>" id="formRegisterSection">
            <form action="login.php" method="POST">
                <div class="form-group">
                    <label class="form-label" for="regName">Full Name / Organization Name</label>
                    <input type="text" name="reg_name" id="regName" class="form-control" placeholder="John Doe or SaveLife NGO" required autocomplete="name">
                </div>

                <div class="form-group">
                    <label class="form-label" for="regEmail">Email Address</label>
                    <input type="email" name="reg_email" id="regEmail" class="form-control" placeholder="hub@example.com" required autocomplete="email">
                </div>

                <div class="form-group">
                    <label class="form-label" for="regContact">Contact Number</label>
                    <input type="text" name="reg_contact" id="regContact" class="form-control" placeholder="e.g. +91 98765 43210" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="regRole">Join Network As:</label>
                    <select name="reg_role" id="regRole" class="form-control" required>
                        <option value="donor">Food Donor (Restaurant, Hotel, Home, Catering)</option>
                        <option value="ngo">NGO Recipient (Food Distribution, Shelter)</option>
                        <option value="volunteer">Volunteer Rescuer (Delivery Partner)</option>
                    </select>
                </div>

                <div class="form-group" id="donorTypeGroup" style="display: none;">
                    <label class="form-label" for="regDonorType">Donor Type:</label>
                    <select name="reg_donor_type" id="regDonorType" class="form-control">
                        <option value="Restaurant">Restaurant / Bistro</option>
                        <option value="Hotel">Hotel / Banquet Hall</option>
                        <option value="Home Kitchen">Home Kitchen</option>
                        <option value="Catering">Catering Service</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="regLocation">Location / Operating Area</label>
                    <input type="text" name="reg_location" id="regLocation" class="form-control" placeholder="e.g. Connaught Place, New Delhi" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="regPassword">Secure Password</label>
                    <input type="password" name="reg_password" id="regPassword" class="form-control" placeholder="Min. 8 characters" required autocomplete="new-password">
                </div>

                <div class="form-group">
                    <label class="form-label" for="regPasswordConf">Confirm Password</label>
                    <input type="password" name="reg_password_conf" id="regPasswordConf" class="form-control" placeholder="Re-enter password" required autocomplete="new-password">
                </div>

                <button type="submit" name="register_submit" class="btn btn-accent" style="width: 100%; margin-top: 10px;">
                    <i class="fa-solid fa-user-plus"></i> Register & Initialize Points
                </button>
            </form>
        </section>

    </div>

</main>

<script>
function switchTab(mode) {
    const tabLogin = document.getElementById('tabLogin');
    const tabReg = document.getElementById('tabRegister');
    const formLogin = document.getElementById('formLoginSection');
    const formReg = document.getElementById('formRegisterSection');
    
    if (mode === 'login') {
        tabLogin.classList.add('active');
        tabReg.classList.remove('active');
        formLogin.classList.add('active');
        formReg.classList.remove('active');
    } else {
        tabReg.classList.add('active');
        tabLogin.classList.remove('active');
        formReg.classList.add('active');
        formLogin.classList.remove('active');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const regRole = document.getElementById('regRole');
    const donorTypeGroup = document.getElementById('donorTypeGroup');
    const regDonorType = document.getElementById('regDonorType');
    
    function toggleDonorType() {
        if (regRole && donorTypeGroup && regDonorType) {
            if (regRole.value === 'donor') {
                donorTypeGroup.style.display = 'block';
                regDonorType.required = true;
            } else {
                donorTypeGroup.style.display = 'none';
                regDonorType.required = false;
            }
        }
    }
    
    if (regRole) {
        regRole.addEventListener('change', toggleDonorType);
        toggleDonorType(); // Run initially
    }
});
</script>

</body>
</html>
