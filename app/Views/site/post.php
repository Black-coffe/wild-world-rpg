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
$imgRel  = is_string($post['featured_image'] ?? null) && $post['featured_image'] !== '' ? $post['featured_image'] : null;
$img     = $imgRel !== null ? base_url($imgRel) : null;

/* Размеры обложки — из файла на диске (SEO-аудит 2026-07-24). Без width/height браузер не
   знает пропорций до загрузки картинки и схлопывает высоту в ноль → текст под ней прыгает,
   когда картинка доезжает (CLS, один из трёх Core Web Vitals). Обложки разноформатные
   (1140×620, 1536×768, 1536×1024) — константу не поставить, поэтому читаем с диска;
   getimagesize по локальному файлу упирается в кэш ФС и стоит микросекунды. Нет файла или
   он битый → просто не печатаем атрибуты (деградация, а не падение страницы). */
$imgW = $imgH = null;
if ($imgRel !== null) {
    $imgPath = FCPATH . ltrim($imgRel, '/');
    if (is_file($imgPath)) {
        $size = @getimagesize($imgPath);
        if (is_array($size) && isset($size[0], $size[1]) && $size[0] > 0 && $size[1] > 0) {
            $imgW = (int) $size[0];
            $imgH = (int) $size[1];
        }
    }
}
$date    = is_string($post['published_at'] ?? null) ? date('d.m.Y', (int) strtotime($post['published_at'])) : '';
$content = is_string($post['content_html'] ?? null) ? $post['content_html'] : '';

$social = config('Social');
$tg     = $social->botStart('src_site_post');
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<?= view('site/_breadcrumbs', ['items' => $breadcrumbs ?? []]) ?>

<article class="block" style="padding-top:24px">
    <div class="container">
        <?php /* Текстовая колонка (header/prose) центрирована (max-width:70ch + margin:0 auto),
                hero-img ограничен той же шириной — устраняет визуальную асимметрию
                «header слева — image full — prose слева». */ ?>
        <header style="max-width:70ch;margin-left:auto;margin-right:auto">
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
            <figure style="max-width:70ch;margin:32px auto 24px">
                <?php /* Обложка — самый крупный элемент первого экрана, то есть почти всегда
                         LCP. Ей НЕЛЬЗЯ loading="lazy": браузер отложит загрузку до раскладки
                         и сам себе испортит метрику (аудит 24.07: 120 из 139 страниц грузили
                         свой LCP лениво). Наоборот — просим приоритет. */ ?>
                <img src="<?= esc($img, 'attr') ?>" alt="<?= esc($title) ?>"
                     <?php if ($imgW !== null && $imgH !== null): ?>width="<?= $imgW ?>" height="<?= $imgH ?>" <?php endif ?>
                     fetchpriority="high" decoding="async"
                     style="width:100%;height:auto;border:1px solid var(--border)">
            </figure>
        <?php endif ?>

        <?php /* content_html — доверенный HTML из собственного WordPress (ADR-052).
                .prose уже max-width:70ch; margin:0 auto центрирует под header. */ ?>
        <div class="prose mt-3" style="margin-left:auto;margin-right:auto"><?= $content ?></div>

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
