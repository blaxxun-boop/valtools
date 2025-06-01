<?php

$starttime = microtime(1);

// Load configuration
$config_file = __DIR__ . '/config/config.php';
if (!file_exists($config_file)) {
    die('Configuration file not found. Copy config.example.php to config.php');
}
$config = require $config_file;

// Database connection
$db_host = $config['database']['host'];
$db_user = $config['database']['user'];
$db_pass = $config['database']['pass'];
$db_name = $config['database']['dbname'];

// OAuth2 client
$oauth_client_id = $config['discord']['client_id'];
$oauth_client_secret = $config['discord']['client_secret'];
$oauth_redirect_uri = $config['discord']['redirect_uri'];
$bot_token = $config['discord']['bot_token'];

require __DIR__ . "/dbinc.php";
require __DIR__ . "/OAuth2.php";

session_start();

$xsrfValid = isset($_SESSION["xsrf"]) && ($_POST["xsrf"] ?? "") === $_SESSION["xsrf"];

// Function to check if user has specific role
function hasRole($user_id, $role) {
	global $pdo;
	if (empty($user_id)) return false;

	$stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
	$stmt->execute([$user_id]);
	$user_role = $stmt->fetch();

	if (!$user_role) return false;

	$roles = explode(',', $user_role['role']);
	return in_array($role, $roles);
}

function hasPermission($permission) {
	global $pdo;
	static $permissions = null;

	if (!$permissions) {
		$stmt = $pdo->prepare("SELECT id FROM bans WHERE id = :user_id");
		$stmt->execute(["user_id" => $_SESSION["discord_user"]["id"]]);
		$banned = $stmt->fetchColumn();

		$stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :user_id");
		$stmt->execute(["user_id" => $_SESSION["discord_user"]["id"]]);
		$role = $stmt->fetchColumn();

		if ($role == "Admin") {
			$permissions[] = "users";
			$permissions[] = "modcomments";
			$permissions[] = "addcomments";
		}
		elseif ($role == "Moderator") {
			$permissions[] = "modcomments";
			$permissions[] = "addcomments";
		}
		else {
			if (!$banned) {
				$permissions[] = "addcomments";
			}
		}
	}

	return in_array($permission, $permissions);
}

function getPermissions(): ?array
{
    global $pdo;
    static $permissions = null;
    if (!$permissions) {
        $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :user_id");
        $stmt->execute(["user_id" => $_SESSION["discord_user"]["id"]]);
        $role = $stmt->fetchColumn();
        if ($role == "Admin") {
            $permissions[] = "users";
            $permissions[] = "modcomments";
        } elseif ($role == "Moderator") {
            $permissions[] = "modcomments";
        }
        $permissions[] = "addcomments";
    }
    return $permissions;
}

function checkPermission($permission) {
	if (!hasPermission($permission)) {
		header("Location: index.php");
		exit;
	}
}

function getDiscordUserName($user_id): string
{
	global $bot_token;
	$context = stream_context_create([
		'http' => [
			'header' => ["Authorization: Bot $bot_token", "User-Agent: DiscordBot (valtools.org, 1)", "content-type: application/json"],
		]
	]);
	$contents = file_get_contents("https://discord.com/api/v10/users/$user_id", false, $context);
	$discord_user = json_decode($contents, true);
	return $discord_user["global_name"] ?? $discord_user["username"];
}