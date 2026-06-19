<?php
if (option('debug') || kirby()->user()) return;

$domain = option('medienbaecker.plausibly.site');
$src    = option('medienbaecker.plausibly.script')
	?? rtrim(option('medienbaecker.plausibly.url'), '/') . '/js/script.js';
?>
<script defer data-domain="<?= $domain ?>" src="<?= $src ?>"></script>
