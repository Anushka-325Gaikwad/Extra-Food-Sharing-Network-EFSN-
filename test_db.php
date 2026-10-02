<?php
/**
 * Extra Food Sharing Network (EFSN)
 * MySQL Port and Password Diagnostics and Autosolver
 */

header('Content-Type: text/html; charset=utf-8');
echo "<body style='background-color: #090d16; color: #f3f4f6; font-family: sans-serif; padding: 40px; line-height: 1.6;'>";
echo "<div style='max-width: 600px; margin: 0 auto; background: rgba(31, 41, 55, 0.45); border: 1px solid rgba(255,255,255,0.07); border-radius: 12px; padding: 30px; box-shadow: 0 8px 32px 0 rgba(0,0,0,0.37);'>";
echo "<h2 style='color: #10b981; margin-top: 0;'><i class='fa-solid fa-screwdriver-wrench'></i> EFSN MySQL Port & Password Autosolver</h2>";
echo "<p style='color: #9ca3af;'>It looks like you have multiple MySQL services running (common on Windows with XAMPP + separate MySQL installs)! We are scanning both Port 3306 and Port 3307 to find your active server.</p>";
echo "<hr style='border-color: rgba(255, 255, 255, 0.08); margin: 20px 0;'>";

$host = '127.0.0.1'; // Using IP is more reliable for port selection in PDO
$user = 'root';

// Ports to scan
$portsToTest = [3306, 3307];

// Passwords to scan
$passwordsToTest = [
    'No Password (empty)' => '',
    'root' => 'root',
    'admin' => 'admin',
    'mysql' => 'mysql',
    '1234' => '1234',
    '123456' => '123456',
    'password' => 'password'
];

$successPassword = null;
$successPort = null;
$found = false;

echo "<strong>Scanning Port and Password Profiles...</strong><br><br>";

foreach ($portsToTest as $port) {
    echo "<div style='margin-bottom: 15px; border-left: 3px solid #3b82f6; padding-left: 10px;'>";
    echo "<strong style='color: #3b82f6;'>Testing Port {$port}:</strong><br>";
    
    foreach ($passwordsToTest as $label => $pass) {
        try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2 // Short timeout
            ];
            
            $pdo = new PDO($dsn, $user, $pass, $options);
            
            // Connection Succeeded!
            echo "<span style='color: #34d399; font-weight: bold;'>✔ SUCCESS:</span> Port [<strong>{$port}</strong>] + Password [<strong>{$label}</strong>] connected!<br>";
            $successPassword = $pass;
            $successPort = $port;
            $found = true;
            break 2; // Exit both loops
        } catch (PDOException $e) {
            // Silently continue for failed attempts
        }
    }
    echo "<em>Completed scan for Port {$port}</em></div>";
}

echo "<hr style='border-color: rgba(255, 255, 255, 0.08); margin: 20px 0;'>";

if ($found) {
    echo "<h3 style='color: #fbbf24; margin-top: 0;'>🎉 Solution Found!</h3>";
    echo "<p>Connected successfully using: <br>";
    echo "• Port: <strong style='color: #10b981;'>{$successPort}</strong><br>";
    echo "• Password: <strong style='color: #fbbf24;'>'" . ($successPassword === '' ? '[No Password]' : htmlspecialchars($successPassword)) . "'</strong></p>";
    
    // Attempt to automatically write the correct port and password back to config.php!
    $configFile = __DIR__ . '/config.php';
    if (file_exists($configFile)) {
        $configContent = file_get_contents($configFile);
        
        // 1. Update DB_PASS
        $escapedPass = addslashes($successPassword);
        $passPattern = "/define\(\s*'DB_PASS'\s*,\s*'(.*)'\s*\);/i";
        $configContent = preg_replace($passPattern, "define('DB_PASS', '{$escapedPass}');", $configContent);
        
        // 2. Update DSN in getDBConnection to include the correct port
        // Let's modify the DSN string standardly to use IP and include port
        $dsnPattern = '/\$dsn\s*=\s*"mysql:host="\s*\.\s*DB_HOST\s*\.\s*";dbname="\s*\.\s*DB_NAME\s*\.\s*";charset=utf8mb4";/i';
        $newDsn = '$dsn = "mysql:host=" . DB_HOST . ";port=' . $successPort . ';dbname=" . DB_NAME . ";charset=utf8mb4";';
        $configContent = preg_replace($dsnPattern, $newDsn, $configContent);
        
        // Also update the temp fallback DSN without dbname
        $dsnNoDbPattern = '/\$dsn_no_db\s*=\s*"mysql:host="\s*\.\s*DB_HOST\s*\.\s*";charset=utf8mb4";/i';
        $newDsnNoDb = '$dsn_no_db = "mysql:host=" . DB_HOST . ";port=' . $successPort . ';charset=utf8mb4";';
        $configContent = preg_replace($dsnNoDbPattern, $newDsnNoDb, $configContent);
        
        file_put_contents($configFile, $configContent);
        echo "<p style='color: #34d399;'>⚙️ <strong>Auto-Config Success:</strong> I have automatically updated <code>config.php</code> in this folder to route through Port <code>{$successPort}</code> with the correct password!</p>";
    }
    
    echo "<br><a href='index.php' style='display: inline-block; background: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: bold;'>Launch EFSN Portal Now</a>";
} else {
    echo "<h3 style='color: #ef4444; margin-top: 0;'>❌ No Port/Password Configurations Succeeded</h3>";
    echo "<p>We scanned Port 3306 and Port 3307 but could not connect. Please check if your MySQL services are running in XAMPP or if your firewall is blocking connections.</p>";
}

echo "</div>";
echo "</body>";
?>
