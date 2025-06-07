<?php

require __DIR__ . "/inc.php";

global $pdo;

$oauth = new \Xwilarg\Discord\OAuth2($oauth_client_id, $oauth_client_secret, $oauth_redirect_uri);

$loginError = false;
$permissionError = false;

if ($oauth->isRedirected()) {
	$hasToken = $oauth->loadToken();
	if ($hasToken === true) {

		$user_id = $oauth->getUserInformation()["id"];

        /*$_SESSION["username"] = $oauth->getUserInformation()["username"];*/
        $user = $oauth->getUserInformation();
        $_SESSION["discord_user"] = $user;
        /* Access information on the logged in discord user using
        $_SESSION["discord_user"]["username"]
        $_SESSION["discord_user"]["id"]
        $_SESSION["discord_user"]["avatar"]
        */

		$_SESSION["authorized_token"] = $oauth->accessToken;
		$_SESSION["xsrf"] = bin2hex(random_bytes(8));

        header("Location: https://valtools.org/index.php");

		return;
	}
	else {
		loginError:
		$loginError = true;
	}
}
elseif (isset($_GET["redirect"])) {
	$oauth->startRedirection(["identify", "guilds.members.read"]);
}

?>
<!DOCTYPE html>
<html id="loginpage">
<head>
	<?php require __DIR__ . "/head.php"; ?>
    <title>Valtools - Login</title>
</head>
<body>
<?php require __DIR__ . "/topnav.php"; ?>
<main class="page-centered">
    You need to log in, to gain access to additional information or submit comments.
    <div class="logindiv" style="width: 100%;"></div>
    <a href="?redirect" class="loginbutton2">Login with Discord</a>
	<?php
	if ($loginError) {
		if ($permissionError) {
			echo "<div class='error'>Invalid permissions</div>";
		}
		else {
			echo "<div class='error'>Invalid login</div>";
		}
	}
	?>
</main>
<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>
