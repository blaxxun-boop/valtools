<?php

require_once __DIR__ . "/inc.php";

if (empty($_SESSION["authorized_token"])) {
    require __DIR__ . "/unauthorized.php";
    exit;
}

checkPermission("users");

$message = "";
$error = "";

// Define available roles (can be moved to config later)
$available_roles = ['Admin' => "Can do everything.", 'Moderator' => "Can approve / reject comments and ban users."]; // Add more roles here as needed: ['Admin', 'Moderator', 'Editor', etc.]

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $xsrfValid) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_role':
                $user_id = trim($_POST['user_id']);
                $new_role = $_POST['role'];

                if (!empty($user_id) && !empty($new_role) && isset($available_roles[$new_role])) {
                    try {
                        // Get existing roles for user
                        $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        $existing = $stmt->fetch();

                        if ($existing) {
                            // User exists, add role if not already present
                            $current_roles = explode(',', $existing['role']);
                            if (!in_array($new_role, $current_roles)) {
                                $current_roles[] = $new_role;
                                $updated_roles = implode(',', $current_roles);
                                $stmt = $pdo->prepare("UPDATE user_roles SET role = ? WHERE user_id = ?");
                                $stmt->execute([$updated_roles, $user_id]);
                                $message = "Role '{$new_role}' added successfully for user: " . htmlspecialchars($user_id);
                            } else {
                                $error = "User already has the '{$new_role}' role";
                            }
                        } else {
                            // New user, create entry
                            $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (?, ?)");
                            $stmt->execute([$user_id, $new_role]);
                            $message = "Role '{$new_role}' added successfully for user: " . htmlspecialchars($user_id);
                        }
                    } catch (PDOException $e) {
                        $error = "Error adding role: " . $e->getMessage();
                    }
                } else {
                    $error = "Invalid user ID or role";
                }
                break;

            case 'remove_role':
                $user_id = $_POST['user_id'];
                $role_to_remove = $_POST['role'];

                if (!empty($user_id) && !empty($role_to_remove)) {
                    try {
                        $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        $existing = $stmt->fetch();

                        if ($existing) {
                            $current_roles = explode(',', $existing['role']);
                            $updated_roles = array_filter($current_roles, function ($role) use ($role_to_remove) {
                                return $role !== $role_to_remove;
                            });

                            if (count($updated_roles) > 0) {
                                // Update with remaining roles
                                $stmt = $pdo->prepare("UPDATE user_roles SET role = ? WHERE user_id = ?");
                                $stmt->execute([implode(',', $updated_roles), $user_id]);
                            } else {
                                // No roles left, delete user entry
                                $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
                                $stmt->execute([$user_id]);
                            }
                            $message = "Role '{$role_to_remove}' removed successfully from user: " . htmlspecialchars($user_id);
                        }
                    } catch (PDOException $e) {
                        $error = "Error removing role: " . $e->getMessage();
                    }
                }
                break;

            case 'remove_user':
                $user_id = $_POST['user_id'];
                if (!empty($user_id)) {
                    try {
                        $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        $message = "All roles removed successfully for user: " . htmlspecialchars($user_id);
                    } catch (PDOException $e) {
                        $error = "Error removing user: " . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// Get all users with roles
$stmt = $pdo->prepare("SELECT user_id, role FROM user_roles ORDER BY user_id");
$stmt->execute();
$users = $stmt->fetchAll();

// Process users data for display
$users_data = [];
foreach ($users as $user) {
    $roles = explode(',', $user['role']);
    $users_data[] = [
        'user_id' => $user['user_id'],
        'roles' => $roles,
        'role_string' => $user['role']
    ];
}

?>
<!DOCTYPE html>
<html id="manageusers">
<head>
    <?php require __DIR__ . "/head.php"; ?>
    <title>Manage Users - Valtools</title>
</head>
<body>
<?php require __DIR__ . "/topnav.php"; ?>
<main>
    <h1>Manage Users</h1>

    <?php if ($message): ?>
        <div class="bigcard success"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bigcard error"><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="bigcard">
        <h2>Add Role to User</h2>
        <form method="POST" action="">
            <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
            <input type="hidden" name="action" value="add_role">
            <label for="user_id">User ID:</label>
            <input type="text" id="user_id" name="user_id" required placeholder="Enter user ID">

            <label for="role">Role:</label>
            <select id="role" name="role" required>
                <option value="">Select a role</option>
                <?php foreach ($available_roles as $role => $description): ?>
                    <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars($role); ?></option>
                <?php endforeach; ?>
            </select>

            <div class="hinttext">
                Select a role to grant to the specified user. Users can have multiple roles.
            </div>
            <input type="submit" value="Add Role">
        </form>
    </div>

    <div class="bigcard">
        <h2>Current Users and Roles</h2>
        <?php if (empty($users_data)): ?>
            <p>No users with roles found.</p>
        <?php else: ?>
            <table class="table-view">
                <thead>
                <tr>
                    <th>User ID</th>
                    <th>Roles</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users_data as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(getDiscordUserName($user['user_id'])); ?></td>
                        <td>
                            <?php foreach ($user['roles'] as $role): ?>
                                <span style="background: #733cb7; color: white; padding: 2px 8px; border-radius: 12px; margin: 2px; display: inline-block; font-size: 0.9em;">
                                    <?php echo htmlspecialchars($role); ?>
                                </span>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                <!-- Remove individual roles -->
                                <?php foreach ($user['roles'] as $role): ?>
                                    <form method="POST" action="" style="display: inline;"
                                          onsubmit="return confirm('Remove <?php echo htmlspecialchars($role); ?> role from <?php echo htmlspecialchars($user['user_id']); ?>?');">
                                        <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                                        <input type="hidden" name="action" value="remove_role">
                                        <input type="hidden" name="user_id"
                                               value="<?php echo htmlspecialchars($user['user_id']); ?>">
                                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($role); ?>">
                                        <input type="submit" value="Remove <?php echo htmlspecialchars($role); ?>"
                                               class="button"
                                               style="background: linear-gradient(126deg, #904000, #cf8565); margin: 0; padding: 0.2em 0.5em; font-size: 0.8em;">
                                    </form>
                                <?php endforeach; ?>

                                <!-- Remove all roles -->
                                <form method="POST" action="" style="display: inline;"
                                      onsubmit="return confirm('Remove ALL roles from <?php echo htmlspecialchars($user['user_id']); ?>? This will delete the user entirely.');">
                                    <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                                    <input type="hidden" name="action" value="remove_user">
                                    <input type="hidden" name="user_id"
                                           value="<?php echo htmlspecialchars($user['user_id']); ?>">
                                    <input type="submit" value="Remove User" class="button"
                                           style="background: linear-gradient(126deg, #900000, #cf6565); margin: 0; padding: 0.2em 0.5em; font-size: 0.8em;">
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="bigcard">
        <h2>User Role Management</h2>
        <p>This page allows you to manage user roles for the Valtools system.</p>
        <ul>
            <li><strong>Add Role:</strong> Grant specific roles to users. Users can have multiple roles simultaneously.
            </li>
            <li><strong>Remove Role:</strong> Remove specific roles from users while keeping other roles intact.</li>
            <li><strong>Remove User:</strong> Remove all roles from a user (deletes the user from the roles system).
            </li>
        </ul>

        <h3>Available Roles:</h3>
        <ul>
            <?php foreach ($available_roles as $role => $role_description): ?>
                <li><strong><?php echo htmlspecialchars($role) . "</strong>: $role_description"; ?> </li>
            <?php endforeach; ?>
        </ul>

        <div class="hinttext">
            <strong>Note:</strong> Users can have multiple roles. When you add a role to a user who already has roles,
            the new role is added to their existing roles. Be careful when removing admin roles - make sure there
            is always at least one admin user to manage the system.
        </div>
    </div>
</main>
<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>