<?php
/**
 * Видимые хлебные крошки (ADR-052, SEO). Schema.org BreadcrumbList отдаётся
 * отдельно из layout @graph; здесь — UX-навигация для людей.
 *
 * @var list<array{name:string,url:string}> $items
 */
$items = isset($items) && is_array($items) ? $items : [];
if (count($items) < 2) {
    return;
}
$last = count($items) - 1;
?>
<nav class="ww-crumbs" aria-label="Навигационная цепочка">
    <div class="container">
        <ol>
            <?php foreach ($items as $i => $bc): ?>
                <?php if (! is_array($bc)) { continue; } ?>
                <?php $nm = is_string($bc['name'] ?? null) ? $bc['name'] : ''; $url = is_string($bc['url'] ?? null) ? $bc['url'] : ''; ?>
                <li<?= $i === $last ? ' aria-current="page"' : '' ?>>
                    <?php if ($i === $last || $url === ''): ?>
                        <span><?= esc($nm) ?></span>
                    <?php else: ?>
                        <a href="<?= esc($url, 'url') ?>"><?= esc($nm) ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</nav>
