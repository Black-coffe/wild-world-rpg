<?php
/**
 * @var array<string,mixed> $page
 */
$title   = is_string($page['title'] ?? null) ? $page['title'] : '';
$content = is_string($page['content_html'] ?? null) ? $page['content_html'] : '';
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<article class="ww-article">
    <div class="container">
        <header class="ww-article-head">
            <h1><?= esc($title) ?></h1>
        </header>
        <?php /* доверенный HTML (ADR-052) */ ?>
        <div class="ww-prose"><?= $content ?></div>
    </div>
</article>

<?= $this->endSection() ?>
