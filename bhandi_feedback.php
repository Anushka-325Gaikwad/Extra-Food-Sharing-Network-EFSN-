<?php
/**
 * Feedback & Rating System Portal
 * One-way ratings: NGOs/receivers rate Donors per food item (quality, quantity, timeliness)
 */
require_once 'config.php';

// Auth Guard: Only logged-in NGOs allowed (Donors do not rate NGOs/volunteers)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ngo') {
    header("Location: dashboard.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

$claimId = intval($_GET['claim_id'] ?? 0);
$errorMsg = '';
$successMsg = '';

if ($claimId <= 0) {
    header("Location: dashboard.php");
    exit;
}

try {
    // Fetch Claim details
    $stmt = $pdo->prepare("
        SELECT c.*, f.food_name, f.donor_id, u_ngo.name as ngo_name, u_don.name as donor_name
        FROM claims c
        JOIN food_listings f ON c.food_listing_id = f.id
        JOIN users u_ngo ON c.ngo_id = u_ngo.id
        JOIN users u_don ON f.donor_id = u_don.id
        WHERE c.id = ? AND c.status = 'delivered'
    ");
    $stmt->execute([$claimId]);
    $claim = $stmt->fetch();
    
    if (!$claim) {
        $errorMsg = "Invalid claim or delivery is not marked as delivered yet.";
    } else {
        // Verify NGO ownership and find target Donor
        if ($claim['ngo_id'] != $userId) {
            header("Location: dashboard.php");
            exit;
        }
        $targetUserId = $claim['donor_id'];
        $targetName = $claim['donor_name'];
        $ratingHeading = "Rate Food Donor: " . htmlspecialchars($targetName);
        $aspectsText = "Rate food freshness, portion compliance and timeliness.";
        
        // Check if rating already exists
        $check = $pdo->prepare("SELECT id FROM bhandi_ratings WHERE claim_id = ? AND from_user_id = ?");
        $check->execute([$claimId, $userId]);
        if ($check->fetch()) {
            $successMsg = "Thank you! You have already submitted feedback for this delivery.";
        }
    }
} catch (PDOException $e) {
    $errorMsg = "Database error: " . $e->getMessage();
}

// Handle Rating Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating']) && empty($successMsg) && empty($errorMsg)) {
    $q = intval($_POST['rating_quality'] ?? 0);
    $qty = intval($_POST['rating_quantity'] ?? 0);
    $t = intval($_POST['rating_timeliness'] ?? 0);
    $feedback = trim($_POST['feedback'] ?? '');
    
    if ($q < 1 || $q > 5 || $qty < 1 || $qty > 5 || $t < 1 || $t > 5) {
        $errorMsg = "Please provide star ratings for food quality, quantity, and timeliness.";
    } else {
        // Computed overall average
        $overall = round(($q + $qty + $t) / 3);
        try {
            $ins = $pdo->prepare("
                INSERT INTO bhandi_ratings (claim_id, from_user_id, to_user_id, rating, rating_quality, rating_quantity, rating_timeliness, feedback)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$claimId, $userId, $targetUserId, $overall, $q, $qty, $t, $feedback]);
            $successMsg = "🎉 Thank you! Your item-wise food feedback has been registered and average scores updated.";
        } catch (PDOException $e) {
            $errorMsg = "Failed to submit rating: " . $e->getMessage();
        }
    }
}

require_once 'header.php';
?>

<main class="main-content">
    
    <div style="max-width: 600px; margin: 40px auto;">
        
        <div class="panel-header" style="margin-bottom: 25px;">
            <h2 class="panel-title"><i class="fa-solid fa-star-half-stroke"></i> Interaction Rating Portal</h2>
            <a href="dashboard.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
        </div>

        <div class="glass-container" style="padding: 40px;">
            
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
                <div style="text-align: center; margin-top: 30px;">
                    <a href="dashboard.php" class="btn btn-primary">Return to Dashboard</a>
                </div>
            <?php else: ?>
                
                <h3 style="margin-bottom: 8px; color: var(--accent);"><?php echo $ratingHeading; ?></h3>
                <p style="font-size: 0.88rem; color: var(--text-secondary); margin-bottom: 25px;"><?php echo $aspectsText; ?></p>
                
                <form action="bhandi_feedback.php?claim_id=<?php echo $claimId; ?>" method="POST">
                    
                    <!-- Food Item context -->
                    <div class="glass-card" style="padding: 15px; margin-bottom: 25px; border-left: 3px solid var(--primary);">
                        <span style="font-size: 0.78rem; text-transform: uppercase; color: var(--text-secondary); display: block;">Rescued surplus listing</span>
                        <strong style="font-size: 1.05rem;"><?php echo htmlspecialchars($claim['food_name']); ?></strong> (<?php echo $claim['quantity']; ?> Servings)
                    </div>
                    
                    <!-- Food Quality Rating -->
                    <div class="form-group" style="text-align: center; margin-bottom: 20px; border-bottom: 1px dashed var(--border-glass); padding-bottom: 15px;">
                        <label class="form-label" style="text-align: center; font-size: 1rem; margin-bottom: 8px; font-weight: bold; color: #ffffff;">Food Quality</label>
                        <span style="font-size: 0.78rem; color: var(--text-secondary); display: block; margin-bottom: 10px;">Taste, freshness, and safety of food item</span>
                        <div class="star-rating">
                            <input type="radio" id="q-star5" name="rating_quality" value="5" /><label for="q-star5" title="5 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="q-star4" name="rating_quality" value="4" /><label for="q-star4" title="4 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="q-star3" name="rating_quality" value="3" /><label for="q-star3" title="3 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="q-star2" name="rating_quality" value="2" /><label for="q-star2" title="2 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="q-star1" name="rating_quality" value="1" /><label for="q-star1" title="1 star"><i class="fa-solid fa-star"></i></label>
                        </div>
                    </div>
                    
                    <!-- Food Quantity Rating -->
                    <div class="form-group" style="text-align: center; margin-bottom: 20px; border-bottom: 1px dashed var(--border-glass); padding-bottom: 15px;">
                        <label class="form-label" style="text-align: center; font-size: 1rem; margin-bottom: 8px; font-weight: bold; color: #ffffff;">Food Quantity</label>
                        <span style="font-size: 0.78rem; color: var(--text-secondary); display: block; margin-bottom: 10px;">Portion sizes, serving accuracy, and adequacy</span>
                        <div class="star-rating">
                            <input type="radio" id="qty-star5" name="rating_quantity" value="5" /><label for="qty-star5" title="5 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="qty-star4" name="rating_quantity" value="4" /><label for="qty-star4" title="4 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="qty-star3" name="rating_quantity" value="3" /><label for="qty-star3" title="3 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="qty-star2" name="rating_quantity" value="2" /><label for="qty-star2" title="2 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="qty-star1" name="rating_quantity" value="1" /><label for="qty-star1" title="1 star"><i class="fa-solid fa-star"></i></label>
                        </div>
                    </div>
                    
                    <!-- Timeliness Rating -->
                    <div class="form-group" style="text-align: center; margin-bottom: 25px;">
                        <label class="form-label" style="text-align: center; font-size: 1rem; margin-bottom: 8px; font-weight: bold; color: #ffffff;">Timeliness</label>
                        <span style="font-size: 0.78rem; color: var(--text-secondary); display: block; margin-bottom: 10px;">Speed of preparation, readiness for courier pickup</span>
                        <div class="star-rating">
                            <input type="radio" id="t-star5" name="rating_timeliness" value="5" /><label for="t-star5" title="5 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="t-star4" name="rating_timeliness" value="4" /><label for="t-star4" title="4 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="t-star3" name="rating_timeliness" value="3" /><label for="t-star3" title="3 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="t-star2" name="rating_timeliness" value="2" /><label for="t-star2" title="2 stars"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="t-star1" name="rating_timeliness" value="1" /><label for="t-star1" title="1 star"><i class="fa-solid fa-star"></i></label>
                        </div>
                    </div>
                    
                    <!-- Feedback Comments -->
                    <div class="form-group">
                        <label class="form-label" for="feedback">Written Feedback Comments</label>
                        <textarea name="feedback" id="feedback" rows="4" class="form-control" placeholder="Share your experience working with this partner..." required></textarea>
                    </div>
                    
                    <button type="submit" name="submit_rating" class="btn btn-primary" style="width: 100%; margin-top: 15px;">
                        <i class="fa-solid fa-paper-plane"></i> Submit Feedback
                    </button>
                    
                </form>
                
            <?php endif; ?>
            
        </div>
    </div>

</main>

<style>
/* Star rating CSS */
.star-rating {
    display: inline-flex;
    flex-direction: row-reverse;
    font-size: 2.2rem;
    justify-content: center;
}
.star-rating input {
    display: none;
}
.star-rating label {
    color: var(--text-muted);
    cursor: pointer;
    padding: 0 5px;
    transition: var(--transition-smooth);
}
.star-rating input:checked ~ label,
.star-rating label:hover,
.star-rating label:hover ~ label {
    color: var(--accent);
    text-shadow: 0 0 10px var(--accent-glow);
}
</style>

</body>
</html>
