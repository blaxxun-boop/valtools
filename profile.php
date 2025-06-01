<?php
require_once __DIR__ . "/inc.php";

$user_id = $_GET['user'] ?? $_SESSION["discord_user"]["id"] ?? null;
if (!$user_id) {
    header("Location: /");
    exit;
}

// Get user info and stats
$stmt = $pdo->prepare("
    SELECT 
        comment_author as username,
        comment_author_avatar as avatar,
        COUNT(*) as total_comments,
        COUNT(CASE WHEN approved = 1 THEN 1 END) as approved_comments
    FROM comments 
    WHERE comment_author_id = ? 
    GROUP BY comment_author_id, comment_author
");
$stmt->execute([$user_id]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user's recent activity
$stmt = $pdo->prepare("
    SELECT c.*, m.name as mod_name, m.author as mod_author
    FROM comments c
    JOIN mods m ON c.mod_author = m.author AND c.mod_name = m.name
    WHERE c.comment_author_id = ?
    ORDER BY c.comment_time DESC
    LIMIT 10
");
$stmt->execute([$user_id]);
$recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . "/head.php"; ?>
    <title>User Profile - <?php echo htmlspecialchars($user_profile['username'] ?? 'Unknown User'); ?></title>
    <style>
        .profile-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
        }

        .profile-header {
            background: linear-gradient(135deg, var(--color-header-bg_tsblue) 0%, var(--color-table-header-bg_tsblue) 100%);
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .profile-info {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .avatar-container {
            position: relative;
        }

        .avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease;
        }

        .avatar:hover {
            transform: scale(1.05);
        }

        .user-details h1 {
            margin: 0 0 1rem 0;
            font-size: 2.5rem;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }

        .user-stats {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .stat {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            font-weight: 600;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .stat:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .recent-activity {
            background: #1a1a1a;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .recent-activity h2 {
            margin: 0 0 2rem 0;
            font-size: 2rem;
            color: #fff;
            border-bottom: 3px solid #667eea;
            padding-bottom: 0.5rem;
            display: inline-block;
        }

        .activity-item {
            background: #2a2a2a;
            border: 1px solid #444;
            border-radius: 12px;
            padding: 1.5rem;
            padding-right: 4rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .activity-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, var(--color-button-blue-hover), var(--color-table-header-bg_tsblue));
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .mod-info {
            font-size: 1.2rem;
            font-weight: 600;
            color: #667eea;
        }

        .timestamp {
            background: #333;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            color: #ccc;
        }

        .status-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .status-approved {
            background: #4CAF50;
            color: white;
        }

        .status-pending {
            background: #FF9800;
            color: white;
        }

        .comment-text {
            color: #e0e0e0;
            line-height: 1.6;
            padding: 1rem;
            background: #1a1a1a;
            border-radius: 8px;
        }

        .rating {
            display: flex;
            gap: 0.25rem;
            margin-top: 1rem;
        }

        .star {
            font-size: 1.2rem;
            transition: all 0.2s ease;
        }

        .star.filled {
            color: #FFD700;
            text-shadow: 0 0 10px rgba(255, 215, 0, 0.5);
        }

        .star:not(.filled) {
            color: #444;
        }

        .no-activity {
            text-align: center;
            padding: 3rem;
            color: #888;
            font-style: italic;
        }

        .user-not-found {
            text-align: center;
            padding: 4rem 2rem;
            background: #1a1a1a;
            border-radius: 15px;
            margin: 2rem auto;
            max-width: 600px;
        }

        .user-not-found h1 {
            color: #ff6b6b;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .profile-container {
                padding: 1rem;
            }

            .profile-info {
                flex-direction: column;
                text-align: center;
            }

            .user-stats {
                justify-content: center;
            }

            .activity-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .status-badge {
                position: static;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
<?php require __DIR__ . "/topnav.php"; ?>

<main class="profile-container">
    <?php if ($user_profile): ?>
        <div class="profile-header">
            <div class="profile-info">
                <div class="avatar-container">
                    <img src="https://cdn.discordapp.com/avatars/<?= htmlspecialchars($_SESSION["discord_user"]["id"]) ?>/<?= htmlspecialchars($_SESSION["discord_user"]["avatar"]) ?>.png"
                         alt="Avatar" class="avatar">
                </div>
                <div class="user-details">
                    <h1><?php echo htmlspecialchars($user_profile['username']); ?></h1>
                    <div class="user-stats">
                        <span class="stat">📝 <?php echo $user_profile['total_comments']; ?> reviews</span>
                        <span class="stat">✅ <?php echo $user_profile['approved_comments']; ?> approved</span>
                        <span class="stat">📊 <?php echo $user_profile['total_comments'] > 0 ? round(($user_profile['approved_comments'] / $user_profile['total_comments']) * 100) : 0; ?>% approval rate</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="recent-activity">
            <h2>Recent Activity</h2>
            <?php if (empty($recent_activity)): ?>
                <div class="no-activity">
                    <p>No recent activity found.</p>
                </div>
            <?php else: ?>
                <?php foreach ($recent_activity as $activity): ?>
                    <div class="activity-item">
                        <div class="status-badge <?php echo $activity['approved'] == 1 ? 'status-approved' : 'status-pending'; ?>">
                            <?php echo $activity['approved'] == 1 ? '✓' : '⏳'; ?>
                        </div>

                        <div class="activity-header">
                            <div class="mod-info">
                                <?php echo htmlspecialchars($activity['mod_author']); ?> - <?php echo htmlspecialchars($activity['mod_name']); ?>
                            </div>
                            <span class="timestamp">
                                <?= $activity['comment_time'] ? date('M j, Y • H:i', $activity['comment_time']) : 'N/A' ?>
                            </span>
                        </div>

                        <div class="comment-text">
                            <?php echo htmlspecialchars($activity['comment']); ?>
                        </div>

                        <?php if ($activity['rating']): ?>
                            <div class="rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span class="star <?php echo $i <= $activity['rating'] ? 'filled' : ''; ?>">★</span>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="user-not-found">
            <h1>👤 User Not Found</h1>
            <p>The requested user profile could not be found or has no activity yet.</p>
        </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>