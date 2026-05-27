<?php
/**
 * Карточка поста блога — ADR-062 design system.
 *
 * @var array<string,mixed> $post
 * @var list<array<string,mixed>> $categories
 * @var array<string,mixed> $meta
 * @var list<array{name:string,url:string}> $breadcrumbs
 */
$title   = is_string($post['title'] ?? null) ? $post['title'] : '';
$img     = is_string($post['featured_image'] ?? null) && $post['featured_image'] !== '' ? base_url($post['featured_image']) : null;
$date    = is_string($post['published_at'] ?? null) ? date('d.m.Y', (int) strtotime($post['published_at'])) : '';
$content = is_string($post['content_html'] ?? null) ? $post['content_html'] : '';

$social = config('Social');
$tg     = $social->botLink;
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<?= view('site/_breadcrumbs', ['items' => $breadcrumbs ?? []]) ?>

<article class="block" style="padding-top:24px">
    <div class="container">
        <header style="max-width:70ch">
            <?php if ($categories !== []): ?>
                <div class="row gap-1" style="margin-bottom:16px">
                    <?php foreach ($categories as $c): ?>
                        <?php if (is_array($c) && is_string($c['slug'] ?? null)): ?>
                            <a class="badge accent" href="<?= base_url(esc($c['slug'], 'url')) ?>" style="text-decoration:none"><?= esc($c['name'] ?? $c['slug']) ?></a>
                        <?php endif ?>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
            <h1 class="mb-0"><?= esc($title) ?></h1>
            <?php if ($date !== ''): ?>
                <div class="caption mt-2" style="font-family:var(--font-mono);letter-spacing:.04em"><?= esc($date) ?></div>
            <?php endif ?>
        </header>

        <?php if ($img !== null): ?>
            <figure style="margin:32px 0 24px">
                <img src="<?= esc($img, 'attr') ?>" alt="<?= esc($title) ?>" loading="lazy" style="width:100%;border:1px solid var(--border)">
            </figure>
        <?php endif ?>

        <?php /* content_html — доверенный HTML из собственного WordPress (ADR-052) */ ?>
        <div class="prose mt-3"><?= $content ?></div>

        <div class="cta mt-4">
            <div>
                <span class="kicker">Прочитал — теперь сыграй</span>
                <h3 class="mb-0">Wild World — текстовая MMORPG в&nbsp;Telegram</h3>
                <p class="dim mt-1">Без регистраций. Без P2W. Открытый мир, караваны, фракции — заходи и&nbsp;живи.</p>
                <div class="row mt-2">
                    <a class="btn primary" href="<?= esc($tg, 'attr') ?>" target="_blank" rel="noopener">▶ Играть в&nbsp;Telegram</a>
                    <a class="btn ghost" href="<?= base_url('devblog') ?>">Другие записи</a>
                </div>
            </div>
            <div class="stack">
                <div class="quote">Связь с&nbsp;«Тропой Огней» восстановлена. Они&nbsp;дошли. Все.<span class="src">— Радист, сводка №&nbsp;42</span></div>
            </div>
        </div>
    </div>
</article>

<?= $this->endSection() ?>
