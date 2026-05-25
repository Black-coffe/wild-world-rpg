<?php
/**
 * Брендированная 404 публичного сайта (ADR-052).
 * @var array<string,mixed> $meta
 */
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="ww-hero" style="text-align:center">
    <div class="container">
        <div class="ww-hero-inner" style="margin:0 auto">
            <span class="ww-eyebrow">Ошибка 404</span>
            <h1 class="ww-hero-title">Эта тропа ведёт в пустошь</h1>
            <p class="ww-hero-lead" style="margin-left:auto;margin-right:auto">Страница не найдена или была перенесена. Вернись на базу — там безопаснее.</p>
            <div class="ww-hero-cta" style="justify-content:center">
                <a class="ww-btn ww-btn-play ww-btn-lg" href="<?= base_url() ?>">На главную</a>
                <a class="ww-btn ww-btn-ghost ww-btn-lg" href="<?= base_url('wiki') ?>">Открыть вики</a>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>
