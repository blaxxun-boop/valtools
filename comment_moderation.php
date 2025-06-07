<?php
session_start();
require_once __DIR__ . "/inc.php";

// Check if user has moderation permissions
checkPermission("modcomments");

// Handle comment approval/rejection
if ($xsrfValid) {
	if (($_POST['action'] ?? '') === 'approve' && !empty($_POST['comment_id'])) {
		$stmt = $pdo->prepare("UPDATE comments SET approved = 1 WHERE id = ?");
		$stmt->execute([$_POST['comment_id']]);
		$success_message = "Comment approved successfully.";
	}
    elseif (($_POST['action'] ?? '') === 'reject' && !empty($_POST['comment_id'])) {
		$stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
		$stmt->execute([$_POST['comment_id']]);
		$success_message = "Comment rejected and deleted.";
	}
	if (($_POST['action'] ?? '') === 'ban' && !empty($_POST['comment_id'])) {
		// 1) look up the author ID
		$stmt = $pdo->prepare("SELECT comment_author_id FROM comments WHERE id = ?");
		$stmt->execute($_POST['comment_id']);
		$authorId = $stmt->fetchColumn();

		if ($authorId) {
			// 2) insert into bans (avoid duplicate with IGNORE)
			$bannedBy = $_SESSION["discord_user"]['username']
				?? $_SESSION['user']
				?? 'unknown';
			$banStmt = $pdo->prepare("
            INSERT IGNORE INTO bans (id, date, banned_by)
            VALUES (?, ?, ?)
        ");
			$banStmt->execute([
				$authorId,
				time(),      // unix_timestamp
				$bannedBy
			]);

			// 3) delete only the one comment
			$del = $pdo->prepare("DELETE FROM comments WHERE id = ?");
			$del->execute($_POST['comment_id']);

			$success_message = "User “{$authorId}” has been banned and the comment removed.";
		}
		else {
			$error_message = "Could not find that comment’s author.";
		}
	}
}

try {
	// Get pending comments
	$pending_comments = $pdo->query("
        SELECT c.*, m.name as mod_name, m.author as mod_author
        FROM comments c
        LEFT JOIN mods m ON c.mod_author = m.author AND c.mod_name = m.name
        WHERE c.approved = 0
        ORDER BY c.comment_time DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

}
catch (PDOException $e) {
	$error_message = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
	<?php require __DIR__ . "/head.php"; ?>
    <title>Comment Moderation - Valtools</title>
    <style>
        .moderation-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .moderation-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--color-border);
        }

        .urgent-notification {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(255, 107, 107, 0.3);
            animation: urgentPulse 2s infinite;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .priority-notification {
            background: linear-gradient(135deg, #ffa726, #ff9800);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(255, 167, 38, 0.3);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .normal-notification {
            background: linear-gradient(135deg, #42a5f5, #2196f3);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(66, 165, 245, 0.3);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        @keyframes urgentPulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 2px 8px rgba(255, 107, 107, 0.3);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 4px 16px rgba(255, 107, 107, 0.5);
            }
        }

        .moderation-stats {
            background: var(--color-panel);
            padding: 15px 20px;
            border-radius: 8px;
            border: 1px solid var(--color-border);
        }

        .comment-grid {
            display: grid;
            gap: 20px;
            grid-template-columns: repeat(auto-fit, minmax(600px, 1fr));
        }

        .comment-card {
            background: var(--color-panel);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 0;
            overflow: hidden;
            transition: box-shadow 0.2s ease;
        }

        .comment-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .comment-header {
            background: var(--input-background-color);
            padding: 15px 20px;
            border-bottom: 1px solid var(--color-border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .comment-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            border: 2px solid var(--color-border);
        }

        .comment-author-info h4 {
            margin: 0;
            font-size: 1.1em;
            color: var(--color-text);
        }

        .comment-timestamp {
            font-size: 0.85em;
            color: var(--color-text-muted);
            margin: 2px 0 0 0;
        }

        .comment-body {
            padding: 20px;
        }

        .mod-info {
            background: var(--input-background-color);
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            border-left: 4px solid #007bff;
        }

        .mod-info-label {
            font-weight: 600;
            color: var(--color-text-muted);
            font-size: 0.9em;
            margin-bottom: 4px;
        }

        .mod-info-content {
            font-size: 1em;
            color: var(--color-text);
        }

        .comment-content {
            background: var(--input-background-color);
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            line-height: 1.5;
            border-left: 4px solid #28a745;
        }

        .incompatibility-section {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            border-left: 4px solid #ffc107;
        }

        .incompatibility-title {
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .incompatibility-details {
            font-size: 0.95em;
            line-height: 1.4;
        }

        .moderation-actions {
            background: var(--input-background-color);
            padding: 15px 20px;
            border-top: 1px solid var(--color-border);
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.9em;
        }

        .btn-approve {
            background: #28a745;
            color: white;
        }

        .btn-approve:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        .btn-reject {
            background: #dc3545;
            color: white;
        }

        .btn-reject:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--color-text-muted);
        }

        .empty-state-icon {
            font-size: 3em;
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .comment-grid {
                grid-template-columns: 1fr;
            }

            .moderation-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .moderation-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php require __DIR__ . "/topnav.php"; ?>

<div class="moderation-container">
    <div class="moderation-header">
        <h1>Comment Moderation</h1>
        <div class="moderation-stats">
            <?php
            $comment_count = count($pending_comments);
            if ($comment_count > 10): ?>
                <div class="urgent-notification">
                    🚨 <?= $comment_count ?> pending comments - Urgent attention needed!
                </div>
            <?php elseif ($comment_count > 5): ?>
                <div class="priority-notification">
                    ⚠️ <?= $comment_count ?> pending comments - High priority
                </div>
            <?php elseif ($comment_count > 0): ?>
                <div class="normal-notification">
                    📝 <?= $comment_count ?> pending comment<?= $comment_count !== 1 ? 's' : '' ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

	<?php if (isset($success_message)): ?>
        <div class="alert alert-success">
			<?= htmlspecialchars($success_message) ?>
        </div>
	<?php endif; ?>

	<?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
			<?= htmlspecialchars($error_message) ?>
        </div>
	<?php else: ?>

		<?php if (empty($pending_comments)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">✅</div>
                <h3>All caught up!</h3>
                <p>No pending comments to moderate at this time.</p>
            </div>
		<?php else: ?>
            <div class="comment-grid">
				<?php foreach ($pending_comments as $comment): ?>
                    <div class="comment-card">
                        <div class="comment-header">
                            <img src="https://cdn.discordapp.com/avatars/<?= htmlspecialchars($comment['comment_author_id']) ?>/<?= htmlspecialchars($comment['comment_author_avatar']) ?>.png"
                                 alt="avatar" class="comment-avatar">
                            <div class="comment-author-info">
                                <h4><?= htmlspecialchars($comment['comment_author']) ?></h4>
                                <div class="comment-timestamp">
									<?= date('M j, Y \a\t g:i A', $comment['comment_time']) ?>
                                </div>
                            </div>
                        </div>

                        <div class="comment-body">
                            <div class="mod-info">
                                <div class="mod-info-label">Commenting on mod:</div>
                                <div class="mod-info-content">
									<?= htmlspecialchars($comment['mod_author']) ?> - <?= htmlspecialchars($comment['mod_name']) ?>
                                </div>
                            </div>

                            <div class="comment-content">
								<?= nl2br(htmlspecialchars($comment['comment'])) ?>
                            </div>

							<?php if (!empty($comment['incompatible_mod_author']) || !empty($comment['incompatibility'])): ?>
                                <div class="incompatibility-section">
                                    <div class="incompatibility-title">
                                        ⚠️ Incompatibility Report
                                    </div>
                                    <div class="incompatibility-details">
										<?php if (!empty($comment['incompatible_mod_author'])): ?>
                                            <strong>Incompatible with:</strong> <?= htmlspecialchars($comment['incompatible_mod_author']) ?> - <?= htmlspecialchars($comment['incompatible_mod_name']) ?>
                                            <br>
										<?php endif; ?>
										<?php if (!empty($comment['incompatibility'])): ?>
                                            <strong>Details:</strong> <?= nl2br(htmlspecialchars($comment['incompatibility'])) ?>
										<?php endif; ?>
                                    </div>
                                </div>
							<?php endif; ?>
                        </div>

                        <div class="moderation-actions">
                            <!-- Approve -->
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                                <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                <button type="submit" name="action" value="approve" class="btn btn-approve">
                                    ✓ Approve
                                </button>
                            </form>
                            <!-- Reject -->
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                                <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                <button type="submit" name="action" value="reject" class="btn btn-reject"
                                        onclick="return confirm('Are you sure you want to delete this comment?')">
                                    ✗ Reject & Delete
                                </button>
                            </form>
                            <!-- Ban & Delete -->
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                                <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                <button type="submit" name="action" value="ban" class="btn btn-reject"
                                        onclick="return confirm('Ban this user and delete only this comment?')">
                                    🚫 Ban & Delete
                                </button>
                            </form>
                        </div>
                    </div>
				<?php endforeach; ?>
            </div>
		<?php endif; ?>

	<?php endif; ?>
</div>
</body>
</html>