<?php
/**
 * Статичная страница — ADR-062 design system.
 *
 * @var array<string,mixed> $page
 * @var list<array{name:string,url:string}> $breadcrumbs
 */
$title   = is_string($page['title'] ?? null) ? $page['title'] : '';
$content = is_string($page['content_html'] ?? null) ? $page['content_html'] : '';
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<?= view('site/_breadcrumbs', ['items' => $breadcrumbs ?? []]) ?>

<article class="block" style="padding-top:24px">
    <div class="container">
        <header style="max-width:70ch">
            <h1 class="mb-0"><?= esc($title) ?></h1>
        </header>
        <?php /* доверенный HTML (ADR-052) */ ?>
        <div class="prose mt-3"><?= $content ?></div>
    </div>
</article>

<?= $this->endSection() ?>
