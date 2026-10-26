<?php
/**
 * W-REG.1 — блок «Как начать»: две двери в игру, Telegram и браузер. Общий для главной и /about,
 * выводится только при открытом `web.open_registration` (решение — в контроллере). Без JS,
 * только существующие классы `wildworld-ui.css`.
 *
 * @var string $doorsSource метка источника для ссылки на бота (`?start=`)
 */
$doorsSource = is_string($doorsSource ?? null) ? $doorsSource : 'src_site_doors';
$doorsTg     = config('Social')->botStart($doorsSource);
?>
<section class="block" data-start-doors>
    <div class="container">
        <div class="section-head">
            <div>
                <span class="kicker">Как начать</span>
                <h2 class="mt-1 mb-0">Две двери в&nbsp;один мир</h2>
            </div>
            <div class="desc">Мир, персонаж и&nbsp;прогресс общие. Дверь выбираешь только ты.</div>
        </div>
        <div class="grid grid-2">
            <div class="card stack">
                <div class="label">Telegram</div>
                <p class="mb-0">Открой бота и&nbsp;нажми «Старт». Персонаж появится сразу, аккаунт на&nbsp;сайте не&nbsp;нужен.</p>
                <div class="row">
                    <a class="btn primary" href="<?= esc($doorsTg, 'attr') ?>" target="_blank" rel="noopener">▶ Играть в Telegram</a>
                </div>
            </div>
            <div class="card stack">
                <div class="label">Браузер</div>
                <p class="mb-0">Войди через Google или Яндекс&nbsp;— аккаунт создастся при&nbsp;первом входе. Придумай имя персонажа, и&nbsp;игра откроется здесь&nbsp;же, на&nbsp;сайте.</p>
                <div class="row">
                    <a class="btn primary" href="<?= esc(base_url('play'), 'attr') ?>">▶ Играть в браузере</a>
                </div>
                <p class="provider-note">Уже играешь в&nbsp;Telegram? Отправь боту /web&nbsp;— он&nbsp;даст код, и&nbsp;в&nbsp;браузере будет тот&nbsp;же персонаж.</p>
            </div>
        </div>
    </div>
</section>
