<footer>
	<div id="footer-time">
		Generated in <?=round((microtime(1) - $starttime) * 1000, 1)?> ms
	</div>
</footer>
<?php if (isset($_GET["yscroll"])): ?>
<script>
	window.scrollTo(0, <?=(float)$_GET["yscroll"]?>)
</script>
<?php endif; ?>