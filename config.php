<?php
/**
 * Extra Food Sharing Network (EFSN)
 * Core Configuration, Database Connection & Helper Functions
 */

// Set timezone to match local time (IST - UTC+05:30)
date_default_timezone_set('Asia/Kolkata');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Credentials
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'efsn_db');

/**
 * Establish PDO Database Connection
 */
function getDBConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // If the database doesn't exist, we will try to connect without dbname and create it
        try {
            $dsn_no_db = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
            $pdo_temp = new PDO($dsn_no_db, DB_USER, DB_PASS);
            $pdo_temp->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
            
            // Re-attempt full connection
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            return new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $ex) {
            die("Database connection failed: " . $ex->getMessage());
        }
    }
}

// Instantiate Global PDO instance
$pdo = getDBConnection();

/**
 * Helper: Insert notification for a specific user
 */
function notifyUser($userId, $message) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
        $stmt->execute([$userId, $message]);
        return true;
    } catch (PDOException $e) {
        error_log("Notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper: Notify all users with a specific role
 */
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

/**
 * Helper: Add reward points to a user
 */
function addPoints($userId, $pointsToAdd) {
    global $pdo;
    try {
        // Check if user already exists in rewards table
        $stmt = $pdo->prepare("SELECT points FROM rewards WHERE user_id = ?");
        $stmt->execute([$userId]);
        $reward = $stmt->fetch();

        if ($reward) {
            $newPoints = $reward['points'] + $pointsToAdd;
            $updateStmt = $pdo->prepare("UPDATE rewards SET points = ? WHERE user_id = ?");
            $updateStmt->execute([$newPoints, $userId]);
        } else {
            $insertStmt = $pdo->prepare("INSERT INTO rewards (user_id, points) VALUES (?, ?)");
            $insertStmt->execute([$userId, $pointsToAdd]);
        }
        return true;
    } catch (PDOException $e) {
        error_log("Rewards system error: " . $e->getMessage());
        return false;
    }
}

/**
 * Core Expiry Engine: Run automatically on pages to delete expired food
 * and raise alerts for food items expiring in <= 2 hours.
 */
function runExpiryCheck() {
    global $pdo;
    $now = date('Y-m-d H:i:s');
    $twoHoursLater = date('Y-m-d H:i:s', strtotime('+2 hours'));

    try {
        // 1. Alert for food items expiring in <= 2 hours which haven't been notified
        $alertStmt = $pdo->prepare("
            SELECT id, food_name, donor_id, expiry_time 
            FROM food_listings 
            WHERE status = 'available' 
              AND expiry_notified = 0 
              AND expiry_time <= ? 
              AND expiry_time > ?
        ");
        $alertStmt->execute([$twoHoursLater, $now]);
        $expiringItems = $alertStmt->fetchAll();

        foreach ($expiringItems as $item) {
            $diffMinutes = round((strtotime($item['expiry_time']) - strtotime($now)) / 60);
            $msg = "⚠️ EXPIRED SOON: Your listing '{$item['food_name']}' is expiring in {$diffMinutes} minutes! Please claim or distribute it quickly.";
            notifyUser($item['donor_id'], $msg);

            // Mark as notified
            $updateNotified = $pdo->prepare("UPDATE food_listings SET expiry_notified = 1 WHERE id = ?");
            $updateNotified->execute([$item['id']]);
        }

        // 2. Automatically update expired listings that are 'available' to 'expired'
        $expiredStmt = $pdo->prepare("
            SELECT id, food_name, donor_id 
            FROM food_listings 
            WHERE status = 'available' AND expiry_time <= ?
        ");
        $expiredStmt->execute([$now]);
        $expiredItems = $expiredStmt->fetchAll();

        if (count($expiredItems) > 0) {
            foreach ($expiredItems as $item) {
                // Notify the donor that it expired
                $msg = "❌ EXPIRED: Your food listing '{$item['food_name']}' has expired and is now marked as expired.";
                notifyUser($item['donor_id'], $msg);
            }

            // Perform bulk update of status to 'expired'
            $updateExec = $pdo->prepare("UPDATE food_listings SET status = 'expired' WHERE status = 'available' AND expiry_time <= ?");
            $updateExec->execute([$now]);
        }

    } catch (PDOException $e) {
        error_log("Expiry check failed: " . $e->getMessage());
    }
}

// Automatically trigger on-demand expiry cleanup on page requests
runExpiryCheck();
?>
