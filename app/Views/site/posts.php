<?php
/**
 * @var string $heading
 * @var string $lead
 * @var list<array<string,mixed>> $posts
 */
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="ww-page-head">
    <div class="container">
        <h1><?= esc($heading) ?></h1>
        <?php if (($lead ?? '') !== ''): ?><p class="ww-muted"><?= esc(mb_substr(strip_tags($lead), 0, 240)) ?></p><?php endif; ?>
    </div>
</section>

<section class="ww-section">
    <div class="container">
        <?php if ($posts === []): ?>
            <p class="ww-muted">Пока нет записей в этом разделе.</p>
        <?php else: ?>
        <div class="row g-4">
            <?php foreach ($posts as $p): ?>
                <?php
                $slug = is_string($p['slug'] ?? null) ? $p['slug'] : '';
                $img  = is_string($p['featured_image'] ?? null) && $p['featured_image'] !== '' ? base_url($p['featured_image']) : null;
                $date = is_string($p['published_at'] ?? null) ? date('d.m.Y', (int) strtotime($p['published_at'])) : '';
                $exc  = is_string($p['excerpt'] ?? null) ? mb_substr($p['excerpt'], 0, 140) : '';
                ?>
                <div class="col-md-6 col-lg-4">
                    <a class="ww-card" href="<?= base_url(esc($slug, 'url')) ?>">
                        <?php if ($img !== null): ?>
                            <div class="ww-card-img" style="background-image:url('<?= esc($img, 'attr') ?>')"></div>
                        <?php else: ?>
                            <div class="ww-card-img ww-card-img-empty"></div>
                        <?php endif; ?>
                        <div class="ww-card-body">
                            <?php if ($date !== ''): ?><span class="ww-card-date"><?= esc($date) ?></span><?php endif; ?>
                            <h3 class="ww-card-title"><?= esc($p['title'] ?? '') ?></h3>
                            <p class="ww-card-exc"><?= esc($exc) ?><?= mb_strlen((string)($p['excerpt'] ?? '')) > 140 ? '…' : '' ?></p>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<?= $this->endSection() ?>
