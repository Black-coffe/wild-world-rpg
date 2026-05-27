<?php
/**
 * Хлебные крошки в новом флэт-стиле (ADR-062 design system).
 * Schema.org BreadcrumbList отдаётся отдельно из layout @graph;
 * здесь — UX-навигация для людей.
 *
 * @var list<array{name:string,url:string}> $items
 */
$items = isset($items) && is_array($items) ? $items : [];
if (count($items) < 2) {
    return;
}
$last = count($items) - 1;
?>
<nav class="crumbs container" aria-label="Навигационная цепочка">
    <?php foreach ($items as $i => $bc): ?>
        <?php if (! is_array($bc)) { continue; } ?>
        <?php
            $nm  = is_string($bc['name'] ?? null) ? $bc['name'] : '';
            $url = is_string($bc['url'] ?? null) ? $bc['url'] : '';
        ?>
        <?php if ($i > 0): ?><span class="sep">/</span><?php endif ?>
        <?php if ($i === $last || $url === ''): ?>
            <span class="current"<?= $i === $last ? ' aria-current="page"' : '' ?>><?= esc($nm) ?></span>
        <?php else: ?>
            <a href="<?= esc($url, 'attr') ?>"><?= esc($nm) ?></a>
        <?php endif ?>
    <?php endforeach ?>
</nav>
