<?php
/**
 * Extra Food Sharing Network (EFSN)
 * Super Sync and Auto-Repair Tool (Applies schema to both Port 3306 and 3307)
 */

$profiles = [
    ['host' => 'localhost', 'port' => 3306, 'user' => 'root', 'pass' => 'root'],
    ['host' => 'localhost', 'port' => 3306, 'user' => 'root', 'pass' => ''],
    ['host' => 'localhost', 'port' => 3307, 'user' => 'root', 'pass' => 'root'],
    ['host' => 'localhost', 'port' => 3307, 'user' => 'root', 'pass' => '']
];

echo "<h3>EFSN Global Database Repair & Table Synchronizer</h3>";
echo "--------------------------------------------------------<br>";

foreach ($profiles as $profile) {
    try {
        $dsn = "mysql:host={$profile['host']};port={$profile['port']};charset=utf8mb4";
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        $pdo = new PDO($dsn, $profile['user'], $profile['pass'], $options);
        
        echo "⚡ Connected to Profile: Port <b>{$profile['port']}</b> (Pass: '" . htmlspecialchars($profile['pass']) . "')<br>";
        
        // 1. Create Database if missing
        $pdo->exec("CREATE DATABASE IF NOT EXISTS efsn_db");
        $pdo->exec("USE efsn_db");
        
        // 2. Users Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('donor', 'ngo', 'volunteer') NOT NULL,
            location VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Force Alter Columns in users to make absolutely sure location exists!
        $userCols = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('location', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN location VARCHAR(255) NOT NULL AFTER role");
            echo "  - <b style='color: green;'>[REPAIRED]</b> Added missing column 'location' in 'users' table on port {$profile['port']}<br>";
        } else {
            echo "  - verified: 'location' column exists in 'users' table on port {$profile['port']}<br>";
        }

        // 3. Food Listings Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS food_listings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            food_name VARCHAR(150) NOT NULL,
            quantity INT NOT NULL,
            expiry_time DATETIME NOT NULL,
            donor_id INT NOT NULL,
            status ENUM('available', 'claimed', 'picked_up', 'expired') DEFAULT 'available',
            expiry_notified TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Force Alter Columns in food_listings to make absolutely sure they exist!
        $cols = $pdo->query("DESCRIBE food_listings")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('location', $cols)) {
            $pdo->exec("ALTER TABLE food_listings ADD COLUMN location VARCHAR(255) NOT NULL AFTER expiry_time");
            echo "  - <b style='color: green;'>[REPAIRED]</b> Added missing column 'location' on port {$profile['port']}<br>";
        } else {
            echo "  - verified: 'location' column exists on port {$profile['port']}<br>";
        }
        
        if (!in_array('expiry_notified', $cols)) {
            $pdo->exec("ALTER TABLE food_listings ADD COLUMN expiry_notified TINYINT(1) DEFAULT 0 AFTER status");
            echo "  - <b style='color: green;'>[REPAIRED]</b> Added missing column 'expiry_notified' on port {$profile['port']}<br>";
        }
        
        // 4. Requests Table Force Sync
        $reqCheck = $pdo->query("SHOW TABLES LIKE 'requests'")->fetch();
        if ($reqCheck) {
            $reqCols = $pdo->query("DESCRIBE requests")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('food_name', $reqCols) || !in_array('quantity', $reqCols)) {
                // Drop mismatched legacy table from old project
                $pdo->exec("DROP TABLE IF EXISTS requests");
                echo "  - <b style='color: orange;'>[REPAIRED]</b> Dropped legacy requests table on port {$profile['port']}<br>";
            }
        }
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ngo_id INT NOT NULL,
            food_name VARCHAR(150) NOT NULL,
            quantity INT NOT NULL,
            status ENUM('open', 'matched', 'completed') DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ngo_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "  - verified: requests table aligned<br>";
        
        // 5. Claims Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS claims (
            id INT AUTO_INCREMENT PRIMARY KEY,
            food_listing_id INT NOT NULL,
            ngo_id INT NOT NULL,
            volunteer_id INT DEFAULT NULL,
            status ENUM('claimed', 'ready_for_pickup', 'delivering', 'delivered') DEFAULT 'claimed',
            quantity INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (food_listing_id) REFERENCES food_listings(id) ON DELETE CASCADE,
            FOREIGN KEY (ngo_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (volunteer_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Force Alter Columns in claims to make absolutely sure quantity exists!
        $claimsCols = $pdo->query("DESCRIBE claims")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('quantity', $claimsCols)) {
            $pdo->exec("ALTER TABLE claims ADD COLUMN quantity INT NOT NULL AFTER status");
            echo "  - <b style='color: green;'>[REPAIRED]</b> Added missing column 'quantity' in 'claims' table on port {$profile['port']}<br>";
        } else {
            echo "  - verified: 'quantity' column exists in 'claims' table on port {$profile['port']}<br>";
        }
        
        // 6. Notifications Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // 7. Rewards Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS rewards (
            user_id INT PRIMARY KEY,
            points INT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        echo "✔️ All tables successfully created and synchronized on port <b>{$profile['port']}</b>!<br><br>";
        
    } catch (PDOException $e) {
        echo "❌ Profile Failed: Port {$profile['port']} (Pass: '" . htmlspecialchars($profile['pass']) . "') - " . $e->getMessage() . "<br><br>";
    }
}

echo "--------------------------------------------------------<br>";
echo "⚙️ Schema repair process completed globally.<br>";
?>
