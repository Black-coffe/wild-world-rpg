<?php
/**
 * Счётчик Statable (web-accounts-p0, Ask 8). Подключается из meta.php, то есть на каждой
 * странице, которая рендерится через site/layout.php.
 *
 * Хэш сайта — публичный id из env STATABLE_SITE_HASH. Пусто — не рендерим ничего.
 *
 * web-accounts-hardening-01: страница с секретом в URL (сброс пароля) передаёт
 * `noThirdPartyAnalytics` — сторонний скрипт читает `location.href`, туда токен не отдаём.
 */
$statableHash = empty($noThirdPartyAnalytics) ? trim((string) env('STATABLE_SITE_HASH', '')) : '';
?>
<?php if ($statableHash !== ''): ?>
<!-- Statable -->
<script src="https://statable.com/js/<?= esc(rawurlencode($statableHash), 'attr') ?>/s.js" defer></script>
<?php endif ?>
