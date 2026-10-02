<?php
/**
 * Extra Food Sharing Network (EFSN)
 * Portal Home, Global Statistics & Gamified Leaderboard
 */
require_once 'header.php';

// Fetch overall impact statistics dynamically from database (Actual Raw Database Counts)
try {
    // 1. Actual Donors Count
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'donor'");
    $donorCount = intval($stmt->fetchColumn());

    // 2. Actual NGO Count
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ngo'");
    $ngoCount = intval($stmt->fetchColumn());

    // 3. Actual Volunteer Count
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'volunteer'");
    $volunteerCount = intval($stmt->fetchColumn());

    // 4. Actual Meals Saved (sum of quantity of all delivered claims)
    $stmt = $pdo->query("SELECT IFNULL(SUM(c.quantity), 0) 
                         FROM claims c 
                         WHERE c.status = 'delivered'");
    $mealsSaved = intval($stmt->fetchColumn());

} catch (PDOException $e) {
    // Fallback if db is not fully loaded yet
    $donorCount = 0;
    $ngoCount = 0;
    $volunteerCount = 0;
    $mealsSaved = 0;
}

// Fetch Leaderboard entries from Database
$leaderboard = [];
try {
    $stmt = $pdo->query("
        SELECT r.points, u.name, u.role, u.location 
        FROM rewards r 
        JOIN users u ON r.user_id = u.id 
        WHERE u.role != 'ngo'
        ORDER BY r.points DESC 
        LIMIT 10
    ");
    $leaderboard = $stmt->fetchAll();
} catch (PDOException $e) {
    // No points records yet
}
?>

<main class="main-content">
    
    <!-- 1. Hero / Branding Section -->
    <section class="hero-section glass-container">
        <h1 class="hero-title">Minimize Food Waste.<br>Maximize Human Support.</h1>
        <p class="hero-subtitle">
            Welcome to the <strong>Extra Food Sharing Network (EFSN)</strong>. We bridge the gap between food donors, local NGOs, and dedicated volunteer networks to rescue surplus food and feed those in need in real-time.
        </p>
        
        <?php if (!isset($_SESSION['user_id'])): ?>
            <div style="display: flex; gap: 15px; justify-content: center; margin-top: 20px;">
                <a href="login.php" class="btn btn-primary"><i class="fa-solid fa-right-to-bracket"></i> Access Dashboard</a>
                <a href="login.php?register=1" class="btn btn-outline"><i class="fa-solid fa-user-plus"></i> Join the Cause</a>
            </div>
        <?php else: ?>
            <div style="display: flex; gap: 15px; justify-content: center; margin-top: 20px;">
                <a href="dashboard.php" class="btn btn-primary"><i class="fa-solid fa-gauge"></i> Go to Your Dashboard</a>
                <?php if ($_SESSION['role'] === 'ngo'): ?>
                    <a href="search.php" class="btn btn-accent"><i class="fa-solid fa-magnifying-glass"></i> Search Food Listings</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- 2. Dynamic Impact Statistics Grid -->
    <section class="stats-grid">
        <div class="stat-card glass-card">
            <div class="stat-number"><?php echo $mealsSaved; ?></div>
            <div class="stat-label">Meals Rescued & Saved</div>
        </div>
        
        <div class="stat-card glass-card">
            <div class="stat-number"><?php echo $donorCount; ?></div>
            <div class="stat-label">Active Food Donors</div>
        </div>
        
        <div class="stat-card glass-card">
            <div class="stat-number"><?php echo $ngoCount; ?></div>
            <div class="stat-label">Partner NGO Hubs</div>
        </div>
        
        <div class="stat-card glass-card">
            <div class="stat-number"><?php echo $volunteerCount; ?></div>
            <div class="stat-label">Volunteer Rescuers</div>
        </div>
    </section>

    <!-- 3. Gamified Reward Leaderboard Section -->
    <section class="leaderboard-section glass-container" style="padding: 40px;">
        <h2 class="section-title">
            <i class="fa-solid fa-crown"></i> Gamified Community Leaderboard
        </h2>
        <p style="text-align: center; color: var(--text-secondary); margin-bottom: 30px;">
            EFSN rewards positive impact. Donors earn <strong>10 pts per claimed donation</strong>, and volunteers earn <strong>15 pts per completed delivery</strong>! Join the movement and rise in ranks!
        </p>

        <?php if (count($leaderboard) === 0): ?>
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                <i class="fa-solid fa-trophy" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
                <p>Leaderboard is currently empty. Be the first to register, donate, or deliver to climb the ranks!</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Rank</th>
                            <th>Contributor</th>
                            <th>Role Type</th>
                            <th>Regional Location</th>
                            <th style="text-align: right;">Total Impact Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($leaderboard as $row): 
                            $rankBadgeClass = '';
                            if ($rank === 1) $rankBadgeClass = 'rank-1';
                            elseif ($rank === 2) $rankBadgeClass = 'rank-2';
                            elseif ($rank === 3) $rankBadgeClass = 'rank-3';
                        ?>
                            <tr>
                                <td>
                                    <span class="rank-badge <?php echo $rankBadgeClass; ?>">
                                        <?php echo $rank; ?>
                                    </span>
                                </td>
                                <td style="font-weight: 600; font-family: var(--font-display);">
                                    <?php echo htmlspecialchars($row['name']); ?>
                                </td>
                                <td>
                                    <span class="role-badge role-<?php echo $row['role']; ?>">
                                        <?php echo $row['role']; ?>
                                    </span>
                                </td>
                                <td style="color: var(--text-secondary);">
                                    <i class="fa-solid fa-location-dot" style="color: var(--primary); font-size: 0.85rem; margin-right: 4px;"></i> 
                                    <?php echo htmlspecialchars($row['location']); ?>
                                </td>
                                <td style="text-align: right;" class="points-text">
                                    <i class="fa-solid fa-star"></i> <?php echo $row['points']; ?>
                                </td>
                            </tr>
                        <?php 
                            $rank++;
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</main>

<footer style="text-align: center; padding: 40px 20px; border-top: 1px solid var(--border-glass); margin-top: 60px; color: var(--text-muted); font-size: 0.9rem;">
    <p>&copy; <?php echo date('Y'); ?> Extra Food Sharing Network (EFSN). All rights reserved.</p>
    <p style="margin-top: 8px; font-size: 0.8rem; color: var(--primary);">Saving meals, feeding dreams.</p>
</footer>

</body>
</html>
