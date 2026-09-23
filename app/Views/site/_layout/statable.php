<?php
/**
 * Счётчик Statable (web-accounts-p0, Ask 8). Подключается из meta.php, то есть на каждой
 * странице, которая рендерится через site/layout.php.
 *
 * Хэш сайта — публичный id из env STATABLE_SITE_HASH. Пусто — не рендерим ничего.
 */
$statableHash = trim((string) env('STATABLE_SITE_HASH', ''));
?>
<?php if ($statableHash !== ''): ?>
<!-- Statable -->
<script src="https://statable.com/js/<?= esc(rawurlencode($statableHash), 'attr') ?>/s.js" defer></script>
<?php endif ?>
