<?php

require_once __DIR__ . "/inc.php";

http_response_code(403);

?>
<!DOCTYPE html>
<html lang="en" id="mainpage">
<head>
    <?php require __DIR__ . "/head.php"; ?>
    <title>Valtools - Forbidden</title>
</head>
<body>
<?php require __DIR__ . "/topnav.php"; ?>

<main class="page-centered">
    <h1 class="section-title" style="text-align:center;">⚠️ Access Denied</h1>
    <p style="text-align:center; font-size: 1.1rem; margin-bottom: 2rem;">
        You must be logged in to view this page. If you believe this is an error, please contact support.
    </p>

    <?php if (!$user): ?>
        <a href="login.php?redirect" class="blockdisplay button">🔐 Log In</a>
    <?php endif; ?>
</main>

<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>
<?php exit; ?>
