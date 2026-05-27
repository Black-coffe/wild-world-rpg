<?php
/**
 * 404 публичного сайта — на дизайн-системе ADR-062.
 *
 * @var array<string,mixed> $meta
 */
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="hero" style="padding-top:80px;padding-bottom:120px;text-align:center">
    <div class="container" style="grid-template-columns:1fr;max-width:720px">
        <div style="text-align:center">
            <div style="font-family:var(--font-display);font-size:clamp(80px, 18vw, 180px);color:var(--accent);letter-spacing:.04em;line-height:.95;opacity:.85;margin-bottom:8px">404</div>
            <span class="stamp" style="margin-bottom:8px">Ошибка · Запретная зона</span>
            <h1 class="h-display mt-2">Эта тропа<br>ведёт в&nbsp;пустошь.</h1>
            <p class="lead mt-2" style="margin-left:auto;margin-right:auto;text-align:left">Страница не&nbsp;найдена или перенесена. Координаты сбиты, маршрут потерян. Вернись на&nbsp;базу&nbsp;— там безопаснее. Карта внизу укажет, куда ещё можно дойти.</p>
            <div class="row mt-3" style="justify-content:center">
                <a class="btn primary lg" href="<?= base_url() ?>">На&nbsp;главную</a>
                <a class="btn lg" href="<?= base_url('wiki') ?>">Открыть вики</a>
                <a class="btn ghost lg" href="<?= base_url('devblog') ?>">Девблог</a>
            </div>

            <div class="divider-stencil" style="margin:48px auto 32px"></div>

            <div class="row" style="justify-content:center;gap:10px;flex-wrap:wrap">
                <span class="badge dot warning">координаты сбиты</span>
                <span class="badge dot danger">маршрут потерян</span>
                <span class="badge mono">CODE 404</span>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>
