<?php
/**
 * Публичная страница достижений (Asana «Визуальная таблица достижений», Steam/Монобанк-стиль, flat ADR-062).
 *
 * Сетка карточек по категориям: иконка (emoji) + название + описание + ГЛОБАЛЬНЫЙ процент игроков
 * (полоска + число; процент, не количество — чтобы не демотивировать). Редкость выводится из процента.
 * Залогиненным через Telegram — отметка ✅/🔒 своих + прогресс X/Y (приватность own-only).
 *
 * @var array<string,list<array<int|string,mixed>>> $byCategory
 * @var int $total            всего персонажей (знаменатель процента)
 * @var int $achCount         всего достижений
 * @var array<int,bool> $myUnlocked   id→true для разблокированных СВОИМ персонажем
 * @var array<int,int> $myProgress     id→текущее значение прогресса (для незакрытых)
 * @var int $myCount          сколько открыто своим персонажем
 * @var bool $authed
 * @var string $tgName
 * @var string $botUsername
 * @var bool $hasChar         у залогиненного есть персонаж в игре
 */
$catMeta = [
    'progression' => ['📈', 'Развитие'],
    'exploration' => ['🧭', 'Исследование'],
    'survival'    => ['🛡', 'Выживание'],
    'crafting'    => ['🔨', 'Крафт'],
    'building'    => ['🏗', 'Стройка'],
    'economy'     => ['💰', 'Экономика'],
    'social'      => ['🏳️', 'Социальное'],
    'general'     => ['🏅', 'Общее'],
];
$catOrder = ['progression', 'exploration', 'survival', 'crafting', 'building', 'economy', 'social', 'general'];

// Редкость из глобального процента (Steam-логика: реже открыли → ценнее).
$rarityOf = static function (float $pct): array {
    if ($pct < 2)  { return ['legend',   'Легендарное']; }
    if ($pct < 10) { return ['epic',     'Очень редкое']; }
    if ($pct < 30) { return ['rare',     'Редкое']; }
    if ($pct < 60) { return ['uncommon', 'Необычное']; }
    return ['common', 'Частое'];
};

$currentPath = '/' . uri_string();
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<?= view('site/_breadcrumbs', ['items' => $meta['breadcrumbs'] ?? []]) ?>

<section class="block" style="padding-top:32px">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="num">🏅 достижения</span>
                <h1 class="mt-1 mb-0">Достижения выживших</h1>
            </div>
            <p class="desc mb-0">
                <?= (int) $achCount ?> достижений · процент показывает, сколько выживших их открыли.
                Чем реже — тем ценнее.
            </p>
        </div>

        <!-- auth-бар: вход через Telegram → личный прогресс (общая сессия ADR-061) -->
        <div class="card row" style="margin-bottom:24px;flex-wrap:wrap;gap:12px;justify-content:space-between">
            <?php if ($authed && $hasChar): ?>
                <span class="caption">🔓 Вы вошли как <b><?= esc($tgName) ?></b> — открыто <b><?= (int) $myCount ?></b> из <?= (int) $achCount ?>. Ваши отметки ✅ и прогресс видны только вам.</span>
                <form method="post" action="<?= site_url('logout/telegram') ?>" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="next" value="<?= esc($currentPath, 'attr') ?>">
                    <button class="btn" type="submit">Выйти</button>
                </form>
            <?php elseif ($authed && ! $hasChar): ?>
                <span class="caption">🔓 Вы вошли как <b><?= esc($tgName) ?></b>, но персонажа в игре пока нет. Начни игру в Telegram, чтобы открывать достижения.</span>
            <?php else: ?>
                <span class="caption">🔒 Войди через Telegram, чтобы видеть <b>свой прогресс</b> — что открыто, а что ещё впереди.</span>
                <?php if ($botUsername !== ''): ?><div id="ww-ach-login"></div><?php endif ?>
            <?php endif ?>
        </div>

        <?php foreach ($catOrder as $cat): ?>
            <?php if (empty($byCategory[$cat])): continue; endif; ?>
            <?php [$cIcon, $cLabel] = $catMeta[$cat] ?? ['🏅', ucfirst($cat)]; ?>
            <h2 class="kicker mt-4" style="margin-bottom:14px"><?= $cIcon ?> <?= esc($cLabel) ?></h2>
            <div class="grid grid-3" style="gap:16px">
                <?php foreach ($byCategory[$cat] as $a): ?>
                    <?php
                        $aid    = is_numeric($a['id'] ?? null) ? (int) $a['id'] : 0;
                        $icon   = is_string($a['icon'] ?? null) && $a['icon'] !== '' ? $a['icon'] : '🏅';
                        $title  = is_string($a['title'] ?? null) ? $a['title'] : '';
                        $descr  = is_string($a['description'] ?? null) ? $a['description'] : '';
                        $pct    = is_numeric($a['percent'] ?? null) ? (float) $a['percent'] : 0.0;
                        $target = is_numeric($a['criteria_target'] ?? null) ? (int) $a['criteria_target'] : 0;
                        [$rCls, $rLabel] = $rarityOf($pct);
                        $unlocked = isset($myUnlocked[$aid]);
                        $prog     = $myProgress[$aid] ?? null;
                        $barVal   = max(2, min(100, (int) round($pct)));
                    ?>
                    <div class="card" style="<?= $unlocked ? 'border-color:var(--success)' : '' ?>">
                        <div class="row gap-1" style="align-items:flex-start;flex-wrap:nowrap">
                            <div style="font-size:34px;line-height:1;flex:0 0 auto;<?= $unlocked ? '' : 'opacity:.45' ?>"><?= $icon ?></div>
                            <div style="flex:1 1 auto;min-width:0">
                                <div class="row gap-1" style="flex-wrap:wrap">
                                    <b style="font-size:15px"><?= esc($title) ?></b>
                                    <?php if ($authed && $hasChar && $unlocked): ?><span class="badge dot success">✅ открыто</span><?php endif ?>
                                </div>
                                <span class="rarity <?= $rCls ?>" style="margin-top:4px"><?= esc($rLabel) ?></span>
                            </div>
                        </div>

                        <div class="caption mt-2"><?= esc($descr) ?></div>

                        <div class="stat-line mt-2">
                            <span class="lbl">Открыли</span>
                            <div class="bar"><span style="--val:<?= $barVal ?>%"></span></div>
                            <span class="val mono"><?= rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.') ?>%</span>
                        </div>

                        <?php if ($authed && $hasChar && ! $unlocked && $prog !== null && $target > 0): ?>
                            <?php $pp = max(0, min(100, (int) round($prog / $target * 100))); ?>
                            <div class="stat-line">
                                <span class="lbl">Твой путь</span>
                                <div class="bar hp"><span style="--val:<?= max(2, $pp) ?>%"></span></div>
                                <span class="val mono"><?= (int) $prog ?>/<?= $target ?></span>
                            </div>
                        <?php endif ?>
                    </div>
                <?php endforeach ?>
            </div>
        <?php endforeach ?>

        <?php if ($total <= 0 || $achCount === 0): ?>
            <div class="card center" style="padding:32px 24px;margin-top:8px">
                <p class="dim mb-0">Достижения пока не открыты никем — будь первым.</p>
            </div>
        <?php endif ?>
    </div>
</section>

<?= $this->endSection() ?>

<?php if (! $authed && $botUsername !== ''): ?>
<?= $this->section('scripts') ?>
<script>
(function(){
    window.onTelegramAuthAch = function(user){
        if (!user || typeof user !== 'object') return;
        var qs = Object.keys(user)
            .filter(function(k){ return user[k] !== null && user[k] !== undefined; })
            .map(function(k){ return encodeURIComponent(k) + '=' + encodeURIComponent(user[k]); })
            .join('&');
        var next = encodeURIComponent(window.location.pathname + window.location.search);
        window.location.href = <?= json_encode(base_url('login/telegram/callback'), JSON_UNESCAPED_SLASHES) ?> + '?' + qs + '&next=' + next;
    };
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://telegram.org/js/telegram-widget.js?22';
    s.setAttribute('data-telegram-login', <?= json_encode($botUsername) ?>);
    s.setAttribute('data-size', 'medium');
    s.setAttribute('data-onauth', 'onTelegramAuthAch(user)');
    s.setAttribute('crossorigin', 'anonymous');
    s.setAttribute('referrerpolicy', 'no-referrer');
    var mount = document.getElementById('ww-ach-login');
    if (mount) { mount.appendChild(s); }
})();
</script>
<?= $this->endSection() ?>
<?php endif ?>
