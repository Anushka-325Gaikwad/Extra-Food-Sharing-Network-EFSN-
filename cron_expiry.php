<?php
/**
 * Extra Food Sharing Network (EFSN)
 * Scheduled/Cron Script for Auto Expiry Check and Database Cleanup
 * Can be run via CLI: php cron_expiry.php
 * Or via web browser for testing/debugging.
 */

// Force CLI output headers if run in CLI
if (php_sapi_name() === 'cli') {
    define('NEWLINE', "\n");
} else {
    define('NEWLINE', "<br>");
    echo "<h2>EFSN Auto-Expiry Cron Job</h2>";
}

require_once 'config.php';

$now = date('Y-m-d H:i:s');
$twoHoursLater = date('Y-m-d H:i:s', strtotime('+2 hours'));

echo "Starting Expiry Engine Cron Job at: {$now}" . NEWLINE;
echo "----------------------------------------------------" . NEWLINE;

try {
    // 1. Process 2-hour warnings
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

    echo "Processing Expiry Warnings (<= 2 hours left):" . NEWLINE;
    if (count($expiringItems) === 0) {
        echo " - No listings found near expiry." . NEWLINE;
    } else {
        foreach ($expiringItems as $item) {
            $diffMinutes = round((strtotime($item['expiry_time']) - strtotime($now)) / 60);
            $msg = "⚠️ EXPIRED SOON: Your listing '{$item['food_name']}' is expiring in {$diffMinutes} minutes! Please claim or distribute it quickly.";
            notifyUser($item['donor_id'], $msg);

            // Mark as notified in database
            $updateNotified = $pdo->prepare("UPDATE food_listings SET expiry_notified = 1 WHERE id = ?");
            $updateNotified->execute([$item['id']]);
            
            echo "   [ALERT SENT] Listing ID {$item['id']}: '{$item['food_name']}' for Donor ID {$item['donor_id']}. Expiring in {$diffMinutes} minutes." . NEWLINE;
        }
    }

    echo NEWLINE;

    // 2. Process automatic marking of fully expired food listings as 'expired'
    $expiredStmt = $pdo->prepare("
        SELECT id, food_name, donor_id 
        FROM food_listings 
        WHERE status = 'available' AND expiry_time <= ?
    ");
    $expiredStmt->execute([$now]);
    $expiredItems = $expiredStmt->fetchAll();

    echo "Processing Expired Food Listings (expiry <= now):" . NEWLINE;
    if (count($expiredItems) === 0) {
        echo " - No fully expired listings found." . NEWLINE;
    } else {
        foreach ($expiredItems as $item) {
            // Notify the donor that their food listing expired and was marked as expired
            $msg = "❌ EXPIRED: Your food listing '{$item['food_name']}' has expired and is now marked as expired.";
            notifyUser($item['donor_id'], $msg);
            
            echo "   [EXPIRED] Listing ID {$item['id']}: '{$item['food_name']}' for Donor ID {$item['donor_id']}. Notify-and-mark." . NEWLINE;
        }

        // Execute bulk status update
        $updateExec = $pdo->prepare("UPDATE food_listings SET status = 'expired' WHERE status = 'available' AND expiry_time <= ?");
        $updateExec->execute([$now]);
        
        echo "   [DATABASE CLEANUP] Successfully marked " . count($expiredItems) . " expired record(s)." . NEWLINE;
    }

    echo "----------------------------------------------------" . NEWLINE;
    echo "Expiry Engine Cron Job completed successfully." . NEWLINE;

} catch (PDOException $e) {
    echo "ERROR running expiry cron: " . $e->getMessage() . NEWLINE;
}
?>
