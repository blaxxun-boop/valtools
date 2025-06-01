<?php

require_once __DIR__ . "/inc.php";

if (empty($_SESSION["authorized_token"])) {
	require __DIR__ . "/unauthorized.php";
	exit;
}

$message = "";
$error = "";

// Define available themes
$available_themes = [
	'Valtools' => 'Valtools Theme (Valheim-inspired)',
	'Dark' => 'Dark Theme',
	'Light' => 'Light Theme'
];

// Define available timezones
$available_timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);

// Get current user settings
$user_id = $_SESSION["authorized_token"];
$current_settings = [];

try {
	$stmt = $pdo->prepare("SELECT theme, timezone FROM settings WHERE user_id = ?");
	$stmt->execute([$user_id]);
	$settings = $stmt->fetch();

	if ($settings) {
		$current_settings['theme'] = $settings['theme'];
		$current_settings['timezone'] = $settings['timezone'];
	} else {
		// Default settings if no settings exist
		$current_settings['theme'] = 'Valtools';
		$current_settings['timezone'] = 'UTC';
	}
} catch (PDOException $e) {
	$error = "Error loading settings: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $xsrfValid) {
	if (isset($_POST['action']) && $_POST['action'] === 'save_settings') {
		$selected_theme = $_POST['theme'];
		$selected_timezone = $_POST['timezone'];

		if (!empty($selected_theme) && isset($available_themes[$selected_theme]) &&
			!empty($selected_timezone) && isset($available_timezones[$selected_timezone])) {
			try {
				// Check if user already has settings
				$stmt = $pdo->prepare("SELECT user_id FROM settings WHERE user_id = ?");
				$stmt->execute([$user_id]);
				$existing = $stmt->fetch();

				if ($existing) {
					// Update existing settings
					$stmt = $pdo->prepare("UPDATE settings SET theme = ?, timezone = ? WHERE user_id = ?");
					$stmt->execute([$selected_theme, $selected_timezone, $user_id]);
				} else {
					// Insert new settings
					$stmt = $pdo->prepare("INSERT INTO settings (user_id, theme, timezone) VALUES (?, ?, ?)");
					$stmt->execute([$user_id, $selected_theme, $selected_timezone]);
				}

				$current_settings['theme'] = $selected_theme;
				$current_settings['timezone'] = $selected_timezone;
				$message = "Settings saved successfully!";

			} catch (PDOException $e) {
				$error = "Error saving settings: " . $e->getMessage();
			}
		} else {
			$error = "Invalid theme or timezone selection";
		}
	}
}

// Get current time in user's timezone for display
$current_time = "";
if (!empty($current_settings['timezone'])) {
	try {
		$timezone = new DateTimeZone($current_settings['timezone']);
		$datetime = new DateTime('now', $timezone);
		$current_time = $datetime->format('Y-m-d H:i:s T');
	} catch (Exception $e) {
		$current_time = "Invalid timezone";
	}
}

?>
<!DOCTYPE html>
<html id="settings">
<head>
	<?php require __DIR__ . "/head.php"; ?>
	<title>Personal Settings - Valtools</title>
</head>
<body>
<?php require __DIR__ . "/topnav.php"; ?>
<main>
	<h1>Personal Settings</h1>

	<?php if ($message): ?>
		<div class="bigcard success"><?php echo $message; ?></div>
	<?php endif; ?>

	<?php if ($error): ?>
		<div class="bigcard error"><?php echo $error; ?></div>
	<?php endif; ?>

	<div class="bigcard">
		<h2>Personal Preferences</h2>
		<form method="POST" action="">
            <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
			<input type="hidden" name="action" value="save_settings">

			<label for="theme">Select Theme:</label>
			<select id="theme" name="theme" required>
				<?php foreach ($available_themes as $theme_key => $theme_name): ?>
					<option value="<?php echo htmlspecialchars($theme_key); ?>"
						<?php echo ($current_settings['theme'] === $theme_key) ? 'selected' : ''; ?>>
						<?php echo htmlspecialchars($theme_name); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<div class="hinttext">
				Choose your preferred theme for the Valtools interface.
			</div>

			<label for="timezone">Select Timezone:</label>
			<select id="timezone" name="timezone" required>
				<?php foreach ($available_timezones as $timezone_key => $timezone_name): ?>
					<option value="<?php echo htmlspecialchars($timezone_key); ?>"
						<?php echo ($current_settings['timezone'] === $timezone_key) ? 'selected' : ''; ?>>
						<?php echo htmlspecialchars($timezone_name); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<div class="hinttext">
				Choose your timezone for displaying dates and times correctly.
				<?php if ($current_time): ?>
					<br><strong>Current time in your timezone:</strong> <?php echo htmlspecialchars($current_time); ?>
				<?php endif; ?>
			</div>

			<input type="submit" value="Save Settings">
		</form>
	</div>

	<div class="bigcard">
		<h2>Current Settings</h2>
		<ul>
			<li><strong>Theme:</strong> <?php echo htmlspecialchars($available_themes[$current_settings['theme']]); ?></li>
			<li><strong>Timezone:</strong> <?php echo htmlspecialchars($available_timezones[$current_settings['timezone']] ?? $current_settings['timezone']); ?></li>
			<?php if ($current_time): ?>
				<li><strong>Current Time:</strong> <?php echo htmlspecialchars($current_time); ?></li>
			<?php endif; ?>
		</ul>
	</div>

	<div class="bigcard">
		<h2>Theme Preview</h2>
		<p>Here's what each theme looks like:</p>
		<ul>
			<li><strong>Valtools:</strong> A Valheim-inspired theme with earthy colors and Viking aesthetics</li>
			<li><strong>Dark:</strong> A dark theme with purple accents, easy on the eyes for extended use</li>
			<li><strong>Light:</strong> A clean, bright theme with blue accents for daytime use</li>
		</ul>
	</div>

	<div class="bigcard">
		<h2>About Personal Settings</h2>
		<p>This page allows you to customize your Valtools experience.</p>
		<ul>
			<li><strong>Theme:</strong> Choose between different visual themes to match your preference</li>
			<li><strong>Timezone:</strong> Set your local timezone for accurate time display throughout the website</li>
		</ul>

		<div class="hinttext">
			<strong>Note:</strong> Your settings are saved automatically and will persist across browser sessions.
			Timezone changes will affect how dates and times are displayed in comments and mod updates.
		</div>
	</div>
</main>
<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>
