<?php
/**
 * Список постов категории — ADR-062 design system.
 *
 * @var string $heading
 * @var string $lead
 * @var list<array<string,mixed>> $posts
 * @var list<array{name:string,url:string}> $breadcrumbs
 */
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<?= view('site/_breadcrumbs', ['items' => $breadcrumbs ?? []]) ?>

<section class="block" style="padding-top:32px">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="num"># <?= esc($heading) ?></span>
                <h1 class="mt-1 mb-0"><?= esc($heading) ?></h1>
            </div>
            <?php if (($lead ?? '') !== ''): ?>
                <p class="desc mb-0"><?= esc(mb_substr(strip_tags($lead), 0, 240)) ?></p>
            <?php endif ?>
        </div>

        <?php if ($posts === []): ?>
            <div class="card center" style="padding:48px 24px">
                <div style="font-family:var(--font-display);font-size:48px;color:var(--text-muted);letter-spacing:.1em">∅</div>
                <h4>Пока пусто</h4>
                <p class="dim">Записей в&nbsp;этом разделе ещё нет. Зайди позже — у&nbsp;нас всегда есть что рассказать с&nbsp;поля.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($posts as $p): ?>
                    <?php
                        $slug = is_string($p['slug'] ?? null) ? $p['slug'] : '';
                        $img  = is_string($p['featured_image'] ?? null) && $p['featured_image'] !== '' ? base_url($p['featured_image']) : null;
                        $date = is_string($p['published_at'] ?? null) ? date('d.m.Y', (int) strtotime($p['published_at'])) : '';
                        $exc  = is_string($p['excerpt'] ?? null) ? mb_substr(strip_tags($p['excerpt']), 0, 160) : '';
                    ?>
                    <article class="item-card">
                        <a href="<?= base_url(esc($slug, 'url')) ?>" style="display:contents;color:inherit">
                            <div class="img" <?= $img !== null ? 'style="background-image:url(\'' . esc($img, 'attr') . '\')"' : '' ?>>
                                <?php if ($img === null): ?><div class="placeholder">§</div><?php endif ?>
                                <div class="sheen"></div>
                            </div>
                            <div class="meta">
                                <?php if ($date !== ''): ?><div class="caption"><?= esc($date) ?></div><?php endif ?>
                                <div class="name"><?= esc($p['title'] ?? '') ?></div>
                                <p class="dim mb-0" style="font-size:13px;line-height:1.5"><?= esc($exc) ?><?= mb_strlen((string)($p['excerpt'] ?? '')) > 160 ? '…' : '' ?></p>
                            </div>
                        </a>
                    </article>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </div>
</section>

<?= $this->endSection() ?>
