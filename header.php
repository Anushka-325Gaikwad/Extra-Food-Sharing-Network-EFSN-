<?php
/**
 * Extra Food Sharing Network (EFSN)
 * Shared Page Header & Navigation Layout
 */
require_once 'config.php';

// Fetch user points if logged in
$userPoints = 0;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT points FROM rewards WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $reward = $stmt->fetch();
    if ($reward) {
        $userPoints = $reward['points'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extra Food Sharing Network</title>
    
    <!-- Google Fonts & FontAwesome CDN for premium iconography -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Main Style Sheet -->
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header class="app-header">
    <div class="header-container">
        <a href="index.php" class="logo">
            <i class="fa-solid fa-hand-holding-heart"></i> Extra Food Sharing Network<span>.</span>
        </a>
        
        <nav>
            <ul class="nav-links">
                <li><a href="index.php" class="nav-link"><i class="fa-solid fa-house"></i> Home</a></li>
                
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li><a href="dashboard.php" class="nav-link"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>
                    
                    <?php if ($_SESSION['role'] === 'ngo'): ?>
                        <li><a href="search.php" class="nav-link"><i class="fa-solid fa-magnifying-glass"></i> Search Food</a></li>
                    <?php endif; ?>
                    
                    <!-- Reward Points Panel -->
                    <li class="profile-points-nav">
                        <span class="role-badge role-<?php echo $_SESSION['role']; ?>">
                            <?php echo ucfirst($_SESSION['role']); ?>
                        </span>
                    </li>
                    
                    <?php if ($_SESSION['role'] !== 'ngo'): ?>
                        <li class="user-pts">
                            <span class="points-text"><i class="fa-solid fa-trophy"></i> <?php echo $userPoints; ?> pts</span>
                        </li>
                    <?php endif; ?>
                    
                    <!-- Real-Time Notification Bell -->
                    <li class="notification-bell-container" id="notifBellContainer">
                        <i class="fa-solid fa-bell bell-icon"></i>
                        <span class="notification-badge" id="notifBadge" style="display: none;">0</span>
                        
                        <!-- Notifications Dropdown -->
                        <div class="notifications-dropdown" id="notifDropdown">
                            <div class="notification-header">
                                <span>Notifications</span>
                                <button onclick="markAllAsRead(event)" class="btn btn-outline btn-sm" style="padding: 2px 8px; font-size: 0.75rem;">Clear All</button>
                            </div>
                            <div id="notifItemsContainer">
                                <div class="notification-item" style="border-left: none; text-align: center; color: var(--text-muted);">
                                    No new notifications
                                </div>
                            </div>
                        </div>
                    </li>
                    
                    <li><a href="login.php?action=logout" class="btn btn-outline btn-sm"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></li>
                <?php else: ?>
                    <li><a href="login.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-user-plus"></i> Join / Login</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</header>

<!-- Toast Container for Glowing Micro-Alerts -->
<div class="toast-container" id="toastContainer"></div>

<script>
// Dynamic Dropdown Toggle
const bell = document.getElementById('notifBellContainer');
const dropdown = document.getElementById('notifDropdown');

if (bell && dropdown) {
    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('active');
    });

    document.addEventListener('click', () => {
        dropdown.classList.remove('active');
    });
}

// Global Toast System
function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    let icon = 'fa-info-circle';
    if (type === 'danger') icon = 'fa-circle-exclamation';
    if (type === 'warning') icon = 'fa-triangle-exclamation';
    if (type === 'success') icon = 'fa-circle-check';
    
    toast.innerHTML = `
        <i class="fa-solid ${icon}"></i>
        <div>${message}</div>
    `;
    
    container.appendChild(toast);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        toast.style.animation = 'fadeOut 0.5s forwards';
        setTimeout(() => toast.remove(), 500);
    }, 5000);
}

// AJAX Notification Poller (Real-time updates)
let lastNotificationId = 0;

function pollNotifications() {
    if (!<?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>) return;
    
    fetch('api.php?action=get_notifications')
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const notifs = data.notifications;
                const badge = document.getElementById('notifBadge');
                const container = document.getElementById('notifItemsContainer');
                
                // Filter unread notifications
                const unreadCount = notifs.filter(n => n.is_read == 0).length;
                
                if (unreadCount > 0) {
                    badge.innerText = unreadCount;
                    badge.style.display = 'block';
                } else {
                    badge.style.display = 'none';
                }
                
                if (notifs.length === 0) {
                    container.innerHTML = `
                        <div class="notification-item" style="border-left: none; text-align: center; color: var(--text-muted);">
                            No new notifications
                        </div>
                    `;
                } else {
                    // Check if there are new unread notifications that were added since last poll
                    notifs.forEach(n => {
                        if (n.id > lastNotificationId) {
                            if (lastNotificationId !== 0 && n.is_read == 0) {
                                // Determine alert level for Toast
                                let type = 'info';
                                if (n.message.includes('⚠️') || n.message.includes('EXPIRED SOON')) type = 'warning';
                                if (n.message.includes('❌') || n.message.includes('EXPIRED')) type = 'danger';
                                if (n.message.includes('✅') || n.message.includes('DELIVERED') || n.message.includes('CLAIMED')) type = 'success';
                                
                                showToast(n.message, type);
                            }
                        }
                    });
                    
                    // Update last id
                    if (notifs.length > 0) {
                        lastNotificationId = Math.max(...notifs.map(n => n.id));
                    }
                    
                    // Render HTML in dropdown
                    container.innerHTML = notifs.map(n => {
                        const unreadClass = n.is_read == 0 ? 'unread' : '';
                        return `
                            <div class="notification-item ${unreadClass}">
                                <div>${n.message}</div>
                                <span class="time">${n.created_at}</span>
                            </div>
                        `;
                    }).join('');
                }
            }
        })
        .catch(err => console.error("Notification polling failed", err));
}

function markAllAsRead(e) {
    if (e) e.stopPropagation();
    fetch('api.php?action=clear_notifications', { method: 'POST' })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const badge = document.getElementById('notifBadge');
                if (badge) badge.style.display = 'none';
                pollNotifications();
            }
        });
}

// Initial poll and set interval
if (<?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>) {
    pollNotifications();
    setInterval(pollNotifications, 5000); // Poll every 5 seconds
}
</script>
