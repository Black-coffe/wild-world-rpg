<?php
/**
 * E30 — публичный веб-профиль персонажа (flat ADR-062, viral-петля).
 *
 * Brag-данные выжившего: уровень, активный титул, фракция, стаж, достижения (+глобальный %),
 * титулы, коллекции, PvP-сводка. 🔒 НИКАКОЙ локации/координат/золота/здоровья (приватность
 * own-only, память feedback_web_tier_privacy_own_only). Свой профиль видит баннер «поделись».
 * Компоненты — только wildworld-ui.css (без новых, переиспользуем .card/.grid/.stat-line/.rarity).
 *
 * @var string $name
 * @var int    $level
 * @var string $factionName
 * @var string $activeIcon
 * @var string $activeName
 * @var int    $daysAlive
 * @var int    $kills
 * @var float  $str
 * @var float  $agi
 * @var float  $intl
 * @var int    $achCount
 * @var int    $achTotal
 * @var list<array{icon:string,title:string,pct:float}> $myAch
 * @var list<array{icon:string,name:string,pct:float}>  $myTitles
 * @var int    $titlesTotal
 * @var int    $collDone
 * @var int    $collTotal
 * @var int    $pvpTotal
 * @var int    $pvpWins
 * @var int    $explored
 * @var string $profileUrl
 * @var string $battlesUrl
 * @var bool   $isOwn
 * @var bool   $authed
 * @var string $botUsername
 */
// Редкость из глобального процента (Steam-логика, как /achievements).
$rarityOf = static function (float $pct): array {
    if ($pct < 2)  { return ['legend',   'Легендарное']; }
    if ($pct < 10) { return ['epic',     'Очень редкое']; }
    if ($pct < 30) { return ['rare',     'Редкое']; }
    if ($pct < 60) { return ['uncommon', 'Необычное']; }
    return ['common', 'Частое'];
};
$fmtPct = static fn (float $p): string => rtrim(rtrim(number_format($p, 1, '.', ''), '0'), '.') . '%';
$titleTag = trim($activeIcon . ' ' . $activeName);
$pvpRate  = $pvpTotal > 0 ? (int) round($pvpWins / $pvpTotal * 100) : 0;
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<?= view('site/_breadcrumbs', ['items' => $meta['breadcrumbs'] ?? []]) ?>

<section class="block" style="padding-top:32px">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="num">👤 профиль выжившего</span>
                <h1 class="mt-1 mb-0"><?= esc($name) ?></h1>
            </div>
            <p class="desc mb-0">
                📈 Уровень <b><?= (int) $level ?></b>
                <?php if ($titleTag !== ''): ?> · <?= esc($titleTag) ?><?php endif ?>
                · 🏳️ <?= esc($factionName) ?> · ⏳ <?= (int) $daysAlive ?> дн. в пустоши
            </p>
        </div>

        <?php if ($isOwn): ?>
            <div class="card" style="margin-bottom:24px;border-color:var(--accent)">
                <span class="caption">🔗 Это <b>твой профиль</b>. Поделись ссылкой — пусть видят, чего ты достиг:</span>
                <div class="stat-line mt-2"><code class="mono" style="word-break:break-all"><?= esc($profileUrl) ?></code></div>
            </div>
        <?php endif ?>

        <!-- Brag-сводка -->
        <div class="grid grid-3" style="gap:16px">
            <div class="card center">
                <div class="num">🏅 достижения</div>
                <div class="mono" style="font-size:30px;line-height:1.1"><?= (int) $achCount ?>/<?= (int) $achTotal ?></div>
                <a class="caption" href="<?= site_url('achievements') ?>">Все достижения →</a>
            </div>
            <div class="card center">
                <div class="num">🎖 титулы</div>
                <div class="mono" style="font-size:30px;line-height:1.1"><?= count($myTitles) ?>/<?= (int) $titlesTotal ?></div>
                <span class="caption">заработано</span>
            </div>
            <div class="card center">
                <div class="num">🏛 коллекции</div>
                <div class="mono" style="font-size:30px;line-height:1.1"><?= (int) $collDone ?>/<?= (int) $collTotal ?></div>
                <span class="caption">завершено</span>
            </div>
            <div class="card center">
                <div class="num">⚔️ pvp</div>
                <div class="mono" style="font-size:30px;line-height:1.1"><?= (int) $pvpWins ?>/<?= (int) $pvpTotal ?></div>
                <a class="caption" href="<?= esc($battlesUrl, 'attr') ?>"><?= $pvpTotal > 0 ? $pvpRate . '% побед · бои →' : 'боёв пока нет' ?></a>
            </div>
            <div class="card center">
                <div class="num">💀 трофеи</div>
                <div class="mono" style="font-size:30px;line-height:1.1"><?= (int) $kills ?></div>
                <span class="caption">NPC повержено</span>
            </div>
            <div class="card center">
                <div class="num">🎢 разведка</div>
                <div class="mono" style="font-size:30px;line-height:1.1"><?= (int) $explored ?></div>
                <span class="caption">клеток изучено</span>
            </div>
        </div>

        <!-- Параметры (brag, без локации) -->
        <h2 class="kicker mt-4" style="margin-bottom:14px">📊 Параметры</h2>
        <div class="card">
            <div class="stat-line"><span class="lbl">💪 Сила</span><span class="val mono"><?= esc((string) $str) ?></span></div>
            <div class="stat-line"><span class="lbl">🤸 Ловкость</span><span class="val mono"><?= esc((string) $agi) ?></span></div>
            <div class="stat-line"><span class="lbl">🧠 Интеллект</span><span class="val mono"><?= esc((string) $intl) ?></span></div>
        </div>

        <?php if ($myTitles !== []): ?>
            <h2 class="kicker mt-4" style="margin-bottom:14px">🎖 Заработанные титулы</h2>
            <div class="grid grid-3" style="gap:16px">
                <?php foreach ($myTitles as $t): ?>
                    <?php [$rCls, $rLabel] = $rarityOf((float) $t['pct']); ?>
                    <div class="card">
                        <div class="row gap-1" style="align-items:center;flex-wrap:nowrap">
                            <div style="font-size:30px;line-height:1;flex:0 0 auto"><?= esc($t['icon']) ?></div>
                            <div style="flex:1 1 auto;min-width:0">
                                <b style="font-size:15px"><?= esc($t['name']) ?></b>
                                <span class="rarity <?= $rCls ?>" style="margin-top:4px"><?= esc($rLabel) ?> · <?= $fmtPct((float) $t['pct']) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
        <?php endif ?>

        <?php if ($myAch !== []): ?>
            <h2 class="kicker mt-4" style="margin-bottom:14px">🏅 Открытые достижения</h2>
            <div class="grid grid-3" style="gap:16px">
                <?php foreach ($myAch as $a): ?>
                    <?php [$rCls, $rLabel] = $rarityOf((float) $a['pct']); ?>
                    <div class="card" style="border-color:var(--success)">
                        <div class="row gap-1" style="align-items:flex-start;flex-wrap:nowrap">
                            <div style="font-size:30px;line-height:1;flex:0 0 auto"><?= esc($a['icon']) ?></div>
                            <div style="flex:1 1 auto;min-width:0">
                                <b style="font-size:15px"><?= esc($a['title']) ?></b>
                                <span class="rarity <?= $rCls ?>" style="margin-top:4px"><?= esc($rLabel) ?> · <?= $fmtPct((float) $a['pct']) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
        <?php else: ?>
            <div class="card center mt-4" style="padding:28px 24px">
                <p class="dim mb-0">Этот выживший ещё не открыл достижений. Всё впереди.</p>
            </div>
        <?php endif ?>

        <div class="center mt-4">
            <a class="btn" href="<?= site_url('achievements') ?>">🏅 Все достижения</a>
            <?php if ($botUsername !== ''): ?>
                <?php // Единственная CTA сайта, которая шла БЕЗ метки источника (аудит трафика
                      // 14.08). Профиль — вирусная поверхность (E30, им делятся), то есть ровно
                      // тот канал, приход по которому важнее всего различать. Ссылку собираем
                      // через Social::botStart(), как все прочие CTA, а не руками из username. ?>
                <a class="btn primary" href="<?= esc((new \Config\Social())->botStart('src_site_profile'), 'attr') ?>" target="_blank" rel="noopener">▶ Играть в Telegram</a>
            <?php endif ?>
        </div>
    </div>
</section>

<?= $this->endSection() ?>
