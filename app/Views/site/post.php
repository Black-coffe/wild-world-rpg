<?php
/**
 * @var array<string,mixed> $post
 * @var list<array<string,mixed>> $categories
 * @var array<string,mixed> $meta
 */
$title = is_string($post['title'] ?? null) ? $post['title'] : '';
$img   = is_string($post['featured_image'] ?? null) && $post['featured_image'] !== '' ? base_url($post['featured_image']) : null;
$date  = is_string($post['published_at'] ?? null) ? date('d.m.Y', (int) strtotime($post['published_at'])) : '';
$dateISO = is_string($post['published_at'] ?? null) ? date('c', (int) strtotime($post['published_at'])) : '';
$updISO  = is_string($post['updated_at'] ?? null) ? date('c', (int) strtotime($post['updated_at'])) : $dateISO;
$content = is_string($post['content_html'] ?? null) ? $post['content_html'] : '';
$canonical = is_string($meta['canonical'] ?? null) ? $meta['canonical'] : rtrim(base_url(), '/');
?>
<?= $this->extend('site/layout') ?>

<?= $this->section('head') ?>
<script type="application/ld+json">
<?= json_encode([
    '@context'         => 'https://schema.org',
    '@type'            => 'BlogPosting',
    'headline'         => $title,
    'datePublished'    => $dateISO,
    'dateModified'     => $updISO,
    'image'            => $img !== null ? [$img] : [],
    'mainEntityOfPage' => $canonical,
    'author'           => ['@type' => 'Organization', 'name' => 'Wild World'],
    'publisher'        => ['@type' => 'Organization', 'name' => 'Wild World'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<article class="ww-article">
    <div class="container">
        <a class="ww-back" href="<?= base_url('devblog') ?>">← Назад к девблогу</a>
        <header class="ww-article-head">
            <?php if ($categories !== []): ?>
                <div class="ww-tags">
                    <?php foreach ($categories as $c): ?>
                        <?php if (is_array($c) && is_string($c['slug'] ?? null)): ?>
                            <a class="ww-tag" href="<?= base_url(esc($c['slug'], 'url')) ?>"><?= esc($c['name'] ?? $c['slug']) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <h1><?= esc($title) ?></h1>
            <?php if ($date !== ''): ?><div class="ww-article-meta"><?= esc($date) ?></div><?php endif; ?>
        </header>

        <?php if ($img !== null): ?>
            <div class="ww-article-cover"><img src="<?= esc($img, 'attr') ?>" alt="<?= esc($title) ?>" loading="lazy"></div>
        <?php endif; ?>

        <?php /* content_html — доверенный HTML из собственного WordPress (ADR-052) */ ?>
        <div class="ww-prose"><?= $content ?></div>
    </div>
</article>

<?= $this->endSection() ?>
