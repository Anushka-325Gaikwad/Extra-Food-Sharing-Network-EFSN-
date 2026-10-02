<?php
/**
 * Donor Food Ratings View Portal
 * Item-wise transparency showing average quality, quantity, timeliness, and full NGO/Volunteer feedback
 */
require_once 'config.php';

// Auth Guard: Only Donors allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'donor') {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Fetch Donor Type
$dTypeQuery = $pdo->prepare("SELECT donor_type FROM users WHERE id = ?");
$dTypeQuery->execute([$userId]);
$donorType = $dTypeQuery->fetchColumn() ?: 'Donor';

// Fetch all food listings by this donor that have received ratings
$ratedListings = [];
try {
    $stmt = $pdo->prepare("
        SELECT f.id as food_id, f.food_name, f.quantity, f.created_at as listing_date,
               (SELECT COUNT(*) FROM bhandi_ratings r JOIN claims c ON r.claim_id = c.id WHERE c.food_listing_id = f.id AND r.to_user_id = ?) as review_count,
               (SELECT AVG(r.rating_quality) FROM bhandi_ratings r JOIN claims c ON r.claim_id = c.id WHERE c.food_listing_id = f.id AND r.to_user_id = ?) as avg_quality,
               (SELECT AVG(r.rating_quantity) FROM bhandi_ratings r JOIN claims c ON r.claim_id = c.id WHERE c.food_listing_id = f.id AND r.to_user_id = ?) as avg_quantity,
               (SELECT AVG(r.rating_timeliness) FROM bhandi_ratings r JOIN claims c ON r.claim_id = c.id WHERE c.food_listing_id = f.id AND r.to_user_id = ?) as avg_timeliness,
               (SELECT AVG(r.rating) FROM bhandi_ratings r JOIN claims c ON r.claim_id = c.id WHERE c.food_listing_id = f.id AND r.to_user_id = ?) as avg_overall
        FROM food_listings f
        WHERE f.donor_id = ?
          AND (SELECT COUNT(*) FROM bhandi_ratings r JOIN claims c ON r.claim_id = c.id WHERE c.food_listing_id = f.id AND r.to_user_id = ?) > 0
        ORDER BY f.created_at DESC
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    $ratedListings = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to query rated listings: " . $e->getMessage());
}

require_once 'header.php';
?>

<main class="main-content">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 class="gradient-text"><i class="fa-solid fa-star"></i> My Food Item Ratings (<?php echo htmlspecialchars($donorType); ?>)</h1>
            <p style="color: var(--text-secondary); margin-bottom: 0;">View transparent item-wise ratings and constructive feedback from NGO recipients and volunteer partners.</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
    </div>

    <?php if (count($ratedListings) === 0): ?>
        <div class="glass-container" style="padding: 60px; text-align: center; color: var(--text-muted);">
            <i class="fa-regular fa-star" style="font-size: 4rem; margin-bottom: 20px; opacity: 0.3;"></i>
            <h3>No Rated Food Listings Yet</h3>
            <p>Once NGOs receive your surplus food deliveries and rate them, item-wise transparency statistics will compile here.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 30px; margin-bottom: 50px;">
            <?php foreach ($ratedListings as $listing): 
                // Fetch full reviews for this listing
                $reviews = [];
                try {
                    $rStmt = $pdo->prepare("
                        SELECT r.rating, r.rating_quality, r.rating_quantity, r.rating_timeliness, r.feedback, r.created_at,
                               u_ngo.name as ngo_name, u_vol.name as volunteer_name
                        FROM bhandi_ratings r
                        JOIN claims c ON r.claim_id = c.id
                        JOIN users u_ngo ON c.ngo_id = u_ngo.id
                        LEFT JOIN users u_vol ON c.volunteer_id = u_vol.id
                        WHERE c.food_listing_id = ? AND r.to_user_id = ?
                        ORDER BY r.created_at DESC
                    ");
                    $rStmt->execute([$listing['food_id'], $userId]);
                    $reviews = $rStmt->fetchAll();
                } catch (PDOException $ex) {}
            ?>
                <div class="glass-container" style="padding: 30px;">
                    <!-- Listing Header Context -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                        <div>
                            <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); display: block;">Item Name</span>
                            <h3 style="color: #ffffff; font-size: 1.25rem;"><?php echo htmlspecialchars($listing['food_name']); ?></h3>
                            <span style="font-size: 0.8rem; color: var(--text-muted);">Posted on: <?php echo date('M d, Y', strtotime($listing['listing_date'])); ?> | Servings: <?php echo $listing['quantity']; ?></span>
                        </div>
                        
                        <!-- Ratings Metrics Summary -->
                        <div style="display: flex; gap: 20px; text-align: center; flex-wrap: wrap;">
                            <div class="glass-card" style="padding: 10px 15px; border-top: 3px solid var(--accent);">
                                <span style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase; display: block;">Overall Rating</span>
                                <strong style="color: var(--accent); font-size: 1.2rem;"><i class="fa-solid fa-star"></i> <?php echo round($listing['avg_overall'], 1); ?>/5</strong>
                            </div>
                            <div class="glass-card" style="padding: 10px 15px;">
                                <span style="font-size: 0.7rem; color: var(--text-secondary); display: block; text-transform: uppercase;">Quality</span>
                                <strong style="font-size: 1rem; color: #ffffff;"><?php echo round($listing['avg_quality'], 1); ?> ★</strong>
                            </div>
                            <div class="glass-card" style="padding: 10px 15px;">
                                <span style="font-size: 0.7rem; color: var(--text-secondary); display: block; text-transform: uppercase;">Quantity</span>
                                <strong style="font-size: 1rem; color: #ffffff;"><?php echo round($listing['avg_quantity'], 1); ?> ★</strong>
                            </div>
                            <div class="glass-card" style="padding: 10px 15px;">
                                <span style="font-size: 0.7rem; color: var(--text-secondary); display: block; text-transform: uppercase;">Timeliness</span>
                                <strong style="font-size: 1rem; color: #ffffff;"><?php echo round($listing['avg_timeliness'], 1); ?> ★</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Reviews & Feedback list -->
                    <h4 style="font-size: 0.95rem; color: var(--text-secondary); margin-bottom: 15px;"><i class="fa-regular fa-comment-dots"></i> Feedback Comments (<?php echo count($reviews); ?>)</h4>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <?php foreach ($reviews as $rev): ?>
                            <div class="glass-card" style="padding: 20px; border-left: 3px solid var(--primary);">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                                    <div>
                                        <strong style="font-size: 0.95rem; color: #ffffff;"><?php echo htmlspecialchars($rev['ngo_name']); ?></strong>
                                        <span style="font-size: 0.8rem; color: var(--text-muted);"> | Courier Partner: <?php echo htmlspecialchars($rev['volunteer_name'] ?? 'Direct Pickup'); ?></span>
                                    </div>
                                    <span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo date('h:i A, d M Y', strtotime($rev['created_at'])); ?></span>
                                </div>
                                <p style="font-size: 0.92rem; color: var(--text-primary); font-style: italic; line-height: 1.5; background: rgba(255,255,255,0.01); padding: 10px; border-radius: var(--radius-sm); border: 1px dashed var(--border-glass);">
                                    "<?php echo htmlspecialchars($rev['feedback']); ?>"
                                </p>
                                
                                <div style="display: flex; gap: 15px; margin-top: 12px; font-size: 0.78rem; color: var(--text-secondary);">
                                    <span>Quality: <strong style="color: var(--accent);"><?php echo $rev['rating_quality']; ?>★</strong></span>
                                    <span>Quantity: <strong style="color: var(--accent);"><?php echo $rev['rating_quantity']; ?>★</strong></span>
                                    <span>Timeliness: <strong style="color: var(--accent);"><?php echo $rev['rating_timeliness']; ?>★</strong></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

</body>
</html>
