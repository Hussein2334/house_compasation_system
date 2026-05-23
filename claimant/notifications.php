<?php
// claimant/notifications.php - View All Notifications
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in and is claimant
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'claimant') {
    header("Location: ../dashboard.php");
    exit();
}

$page_title = 'My Notifications';
$page_heading = 'Taarifa Zangu';

$conn = getDB();
$user_id = $_SESSION['user_id'];

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    $update_query = "UPDATE notifications SET is_read = 1 WHERE user_id = ?";
    $update_stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($update_stmt, "i", $user_id);
    mysqli_stmt_execute($update_stmt);
    header("Location: notifications.php");
    exit();
}

// Mark single as read
if (isset($_GET['mark_read']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $update_query = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
    $update_stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($update_stmt, "ii", $id, $user_id);
    mysqli_stmt_execute($update_stmt);
    header("Location: notifications.php");
    exit();
}

// Get notifications
$query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$notifications = [];
while ($row = mysqli_fetch_assoc($result)) {
    $notifications[] = $row;
}

// Update session notification count
$count_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$count_stmt = mysqli_prepare($conn, $count_query);
mysqli_stmt_bind_param($count_stmt, "i", $user_id);
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$_SESSION['notification_count'] = mysqli_fetch_assoc($count_result)['count'] ?? 0;

require_once __DIR__ . '/includes/claimant-header.php';
?>

<style>
    .notification-item {
        transition: all 0.2s;
    }
    .notification-item:hover {
        background-color: #f4fcef;
    }
    .notification-unread {
        background-color: #fef3c7;
        border-left: 4px solid #f59e0b;
    }
    .notification-read {
        background-color: white;
    }
    .notification-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .notification-icon.claim { background: #d1fae5; color: #065f46; }
    .notification-icon.valuation { background: #fed7aa; color: #9a3412; }
    .notification-icon.payment { background: #a7f3d0; color: #064e3b; }
    .notification-icon.system { background: #cffafe; color: #0891b2; }
    .notification-icon.general { background: #e8f0e4; color: #3d4a3d; }
</style>

<div class="space-y-6">
    
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Taarifa Zangu</h2>
            <p class="text-secondary text-sm mt-1">Angalia taarifa zote kuhusu madai, tathmini na malipo yako</p>
        </div>
        <?php if (!empty($notifications)): ?>
        <a href="?mark_all_read=1" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-container transition">
            <span class="material-symbols-outlined text-sm">done_all</span>
            Weka Zote Kama Zimesomwa
        </a>
        <?php endif; ?>
    </div>
    
    <!-- Notifications List -->
    <div class="bg-white border border-outline-variant rounded-xl overflow-hidden">
        <?php if (empty($notifications)): ?>
        <div class="text-center py-12 text-secondary">
            <span class="material-symbols-outlined text-5xl mb-2 block">notifications_off</span>
            <p>Hakuna taarifa za hivi karibuni</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-outline-variant">
            <?php foreach ($notifications as $notification): ?>
            <div class="notification-item <?php echo $notification['is_read'] ? 'notification-read' : 'notification-unread'; ?> p-4">
                <div class="flex gap-4">
                    <div class="notification-icon <?php echo $notification['type']; ?>">
                        <span class="material-symbols-outlined">
                            <?php 
                            $icons = [
                                'claim' => 'description',
                                'valuation' => 'real_estate_agent',
                                'payment' => 'payments',
                                'system' => 'settings',
                                'general' => 'notifications'
                            ];
                            echo $icons[$notification['type']] ?? 'notifications';
                            ?>
                        </span>
                    </div>
                    <div class="flex-1">
                        <div class="flex items-start justify-between">
                            <div>
                                <h4 class="font-semibold text-on-surface"><?php echo htmlspecialchars($notification['title']); ?></h4>
                                <p class="text-sm text-secondary mt-1"><?php echo nl2br(htmlspecialchars($notification['message'])); ?></p>
                                <p class="text-xs text-secondary mt-2">
                                    <?php echo date('d M Y H:i', strtotime($notification['created_at'])); ?>
                                </p>
                            </div>
                            <?php if (!$notification['is_read']): ?>
                            <a href="?mark_read=1&id=<?php echo $notification['id']; ?>" class="text-xs text-primary hover:underline">Weka Kama Imesomwa</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
</div>

<?php require_once __DIR__ . '/includes/claimant-footer.php'; ?>