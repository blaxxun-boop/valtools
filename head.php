<link rel="stylesheet" href="resources/<?=$_COOKIE["theme"] ?? "default.css"?>" type="text/css" id="style">
<link rel="stylesheet" href="/resources/valtools.css">
<style>
	script { display: none !important; }
</style>
<meta charset="utf-8">
<link rel="icon" href="/resources/images/favicon.ico" type="image/x-icon">
<link rel="dns-prefetch" href="//cdn.discordapp.com">
<link rel="preconnect" href="https://cdn.discordapp.com" crossorigin>
<script>
// Add error handling for avatar images
document.addEventListener('DOMContentLoaded', function() {
	document.querySelectorAll('img[src*="cdn.discordapp.com"]').forEach(img => {
		img.addEventListener('error', function() {
			this.src = '/resources/images/default-avatar.png'; // Fallback image
			this.style.backgroundColor = '#666'; // Or colored placeholder
		});

		// Set timeout for slow loading
		setTimeout(() => {
			if (!img.complete) {
				img.src = '/resources/images/default-avatar.png';
			}
		}, 3000);
	});
});
</script>