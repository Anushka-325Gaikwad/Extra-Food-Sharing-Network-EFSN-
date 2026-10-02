<?php
/**
 * Extra Food Sharing Network (EFSN)
 * AJAX Backend API Handler
 */
header('Content-Type: application/json');
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$userName = $_SESSION['name'];

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    
    // 1. Get real-time notifications for the active user
    case 'get_notifications':
        try {
            $stmt = $pdo->prepare("SELECT id, message, is_read, DATE_FORMAT(created_at, '%h:%i %p, %d %b') as created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 15");
            $stmt->execute([$userId]);
            $notifications = $stmt->fetchAll();
            echo json_encode(['status' => 'success', 'notifications' => $notifications]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    // 2. Clear all notifications (Mark as read)
    case 'clear_notifications':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt->execute([$userId]);
            echo json_encode(['status' => 'success', 'message' => 'Notifications cleared.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    // 3. NGO Claims a food listing
    case 'claim_food':
        if ($userRole !== 'ngo') {
            echo json_encode(['status' => 'error', 'message' => 'Only NGOs can claim food listings.']);
            exit;
        }
        
        $foodId = isset($_POST['food_id']) ? intval($_POST['food_id']) : 0;
        $claimQty = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;
        
        if ($foodId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Food Listing ID.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Check if listing is available and not expired
            $stmt = $pdo->prepare("SELECT * FROM food_listings WHERE id = ? FOR UPDATE");
            $stmt->execute([$foodId]);
            $listing = $stmt->fetch();

            if (!$listing) {
                echo json_encode(['status' => 'error', 'message' => 'Food listing not found.']);
                $pdo->rollBack();
                exit;
            }

            if ($listing['status'] !== 'available') {
                echo json_encode(['status' => 'error', 'message' => 'This listing has already been claimed or is unavailable.']);
                $pdo->rollBack();
                exit;
            }

            if (strtotime($listing['expiry_time']) <= time()) {
                echo json_encode(['status' => 'error', 'message' => 'This food listing has expired and cannot be claimed.']);
                $pdo->rollBack();
                exit;
            }

            // Fallback: If no custom quantity is specified, claim the entire remaining amount
            if ($claimQty <= 0) {
                $claimQty = intval($listing['quantity']);
            }

            if (intval($listing['quantity']) < $claimQty) {
                echo json_encode(['status' => 'error', 'message' => "Only {$listing['quantity']} units are available. Cannot claim {$claimQty}."]);
                $pdo->rollBack();
                exit;
            }

            // Calculate remaining quantity
            $newQuantity = intval($listing['quantity']) - $claimQty;
            $newStatus = ($newQuantity === 0) ? 'claimed' : 'available';

            // Update food listing quantity and status
            $updateListStmt = $pdo->prepare("UPDATE food_listings SET quantity = ?, status = ? WHERE id = ?");
            $updateListStmt->execute([$newQuantity, $newStatus, $foodId]);

            // Create claim entry with the exact claimed quantity
            $claimStmt = $pdo->prepare("INSERT INTO claims (food_listing_id, ngo_id, volunteer_id, status, quantity) VALUES (?, ?, NULL, 'claimed', ?)");
            $claimStmt->execute([$foodId, $userId, $claimQty]);

            // Send notification to Donor
            $donorMsg = "🎉 CLAIMED: NGO '{$userName}' has claimed {$claimQty} units of your food listing '{$listing['food_name']}'! A volunteer will accept and deliver it soon.";
            notifyUser($listing['donor_id'], $donorMsg);

            // Send notification to ALL volunteers that food is ready for pickup
            $volMsg = "🚚 NEW TASK: Fresh food '{$listing['food_name']}' (Qty: {$claimQty}) is ready for pickup at {$listing['location']}! Accept the delivery task now.";
            notifyAllByRole('volunteer', $volMsg);

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => "Food listing claimed successfully! Requested quantity: {$claimQty} unit(s). Volunteers have been notified."]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Claim failed: ' . $e->getMessage()]);
        }
        break;

    // 4. Volunteer Accepts a delivery task
    case 'accept_delivery':
        if ($userRole !== 'volunteer') {
            echo json_encode(['status' => 'error', 'message' => 'Only Volunteers can accept delivery tasks.']);
            exit;
        }

        $claimId = isset($_POST['claim_id']) ? intval($_POST['claim_id']) : 0;
        if ($claimId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Claim ID.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Fetch claim details
            $stmt = $pdo->prepare("SELECT c.*, f.food_name, f.donor_id, f.location as pickup_loc, n.location as dest_loc, n.name as ngo_name 
                                   FROM claims c
                                   JOIN food_listings f ON c.food_listing_id = f.id
                                   JOIN users n ON c.ngo_id = n.id
                                   WHERE c.id = ? FOR UPDATE");
            $stmt->execute([$claimId]);
            $claim = $stmt->fetch();

            if (!$claim) {
                echo json_encode(['status' => 'error', 'message' => 'Delivery task not found.']);
                $pdo->rollBack();
                exit;
            }

            if ($claim['status'] !== 'claimed' || !empty($claim['volunteer_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'This delivery task has already been accepted by another volunteer.']);
                $pdo->rollBack();
                exit;
            }

            // Update Claim Status
            $updateClaim = $pdo->prepare("UPDATE claims SET volunteer_id = ?, status = 'delivering' WHERE id = ?");
            $updateClaim->execute([$userId, $claimId]);

            // Notify NGO
            $ngoMsg = "🚚 DELIVERY UPDATE: Volunteer '{$userName}' has accepted the delivery task for '{$claim['food_name']}'. They are on their way to pick it up!";
            notifyUser($claim['ngo_id'], $ngoMsg);

            // Notify Donor
            $donorMsg = "🚚 DELIVERY UPDATE: Volunteer '{$userName}' has accepted the delivery task for your food donation '{$claim['food_name']}'. They will arrive shortly to pick it up.";
            notifyUser($claim['donor_id'], $donorMsg);

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Task accepted! Live GPS navigation route tracking from your current location has been successfully activated. Check your active radar below!']);

        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    // 5. Volunteer Completes a delivery task
    case 'complete_delivery':
        if ($userRole !== 'volunteer') {
            echo json_encode(['status' => 'error', 'message' => 'Only Volunteers can complete delivery tasks.']);
            exit;
        }

        $claimId = isset($_POST['claim_id']) ? intval($_POST['claim_id']) : 0;
        if ($claimId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Claim ID.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Fetch claim details
            $stmt = $pdo->prepare("SELECT c.*, f.food_name, f.donor_id, f.quantity
                                   FROM claims c
                                   JOIN food_listings f ON c.food_listing_id = f.id
                                   WHERE c.id = ? AND c.volunteer_id = ? FOR UPDATE");
            $stmt->execute([$claimId, $userId]);
            $claim = $stmt->fetch();

            if (!$claim) {
                echo json_encode(['status' => 'error', 'message' => 'Delivery task not found or you are not assigned to it.']);
                $pdo->rollBack();
                exit;
            }

            if ($claim['status'] !== 'delivering') {
                echo json_encode(['status' => 'error', 'message' => 'This delivery task is not currently in progress.']);
                $pdo->rollBack();
                exit;
            }

            // Update Claim status to delivered
            $updateClaim = $pdo->prepare("UPDATE claims SET status = 'delivered' WHERE id = ?");
            $updateClaim->execute([$claimId]);

            // Update listing status to picked_up only if all claims on this listing are delivered and no quantity remains
            $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE food_listing_id = ? AND status != 'delivered'");
            $stmtPending->execute([$claim['food_listing_id']]);
            $pendingCount = intval($stmtPending->fetchColumn());
            
            $stmtListing = $pdo->prepare("SELECT quantity FROM food_listings WHERE id = ?");
            $stmtListing->execute([$claim['food_listing_id']]);
            $listingQty = intval($stmtListing->fetchColumn());
            
            if ($pendingCount === 0 && $listingQty === 0) {
                $updateList = $pdo->prepare("UPDATE food_listings SET status = 'picked_up' WHERE id = ?");
                $updateList->execute([$claim['food_listing_id']]);
            }

            // Disburse points:
            // Donor gets 10 points per donation
            addPoints($claim['donor_id'], 10);
            // Volunteer gets 15 points per delivery
            addPoints($userId, 15);

            // Notify NGO
            $ngoMsg = "✅ DELIVERED: The food listing '{$claim['food_name']}' has been successfully delivered by volunteer '{$userName}'!";
            notifyUser($claim['ngo_id'], $ngoMsg);

            // Notify Donor
            $donorMsg = "✅ DONATION SUCCESS: Your food '{$claim['food_name']}' has been safely delivered to the NGO! You have earned +10 points.";
            notifyUser($claim['donor_id'], $donorMsg);

            // Notify Volunteer
            $volMsg = "🎉 DELIVERY SUCCESS: You completed the delivery for '{$claim['food_name']}'! You have earned +15 points.";
            notifyUser($userId, $volMsg);

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Delivery marked as complete! You earned +15 reward points.']);

        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Action not defined.']);
        break;
}
?>
