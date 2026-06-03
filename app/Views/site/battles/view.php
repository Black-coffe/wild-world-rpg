<?php
/**
 * Публичная детальная страница PvP-боя (ADR-092 Фаза 2-3, flat ADR-062).
 *
 * Тиринг (Фаза 3): незарегистрированные видят общую сводку (имена/уровни/фракции/статы/экип/исход);
 * зарегистрированные через Telegram (сессия ADR-061) — ДОПОЛНИТЕЛЬНО координаты, HP до/после и
 * пораундовый ход боя.
 *
 * @var object $battle
 * @var string $createdAt
 * @var array<string,mixed> $att
 * @var array<string,mixed> $def
 * @var list<array<string,mixed>> $rounds
 * @var int|null $winnerId
 * @var array<string,mixed> $outcome
 * @var string|null $biome
 * @var int $version
 * @var bool $authed
 * @var string $tgName
 * @var string $botUsername
 * @var int $myCharId  ID персонажа залогиненного игрока (0 если нет) — для приватности координат
 */
$num = static fn ($v): ?float => is_numeric($v) ? (float) $v : null;
$fmt = static fn (?float $v): string => $v === null ? '—' : (string) (round($v * 10) / 10);

// Карточка бойца. $authed → HP до/после и раунды. Координаты (локация) — ТОЛЬКО для своего
// персонажа ($myCharId): чужие локации не раскрываем даже залогиненным (приватность, фидбэк владельца).
$playerCard = static function (array $p, ?int $winnerId, string $roleLabel, bool $authed, int $myCharId) use ($num, $fmt): string {
    $id      = is_numeric($p['id'] ?? null) ? (int) $p['id'] : 0;
    $name    = is_scalar($p['name'] ?? null) ? (string) $p['name'] : '—';
    $level   = is_numeric($p['level'] ?? null) ? (int) $p['level'] : null;
    $faction = is_scalar($p['faction'] ?? null) ? (string) $p['faction'] : null;
    $hpBefore = $num($p['health_before'] ?? null);
    $hpAfter  = $num($p['health_after'] ?? null);
    $weapon   = is_array($p['weapon'] ?? null) ? $p['weapon'] : null;
    $armor    = is_array($p['armor'] ?? null) ? $p['armor'] : [];
    $coords   = is_array($p['coords'] ?? null) ? $p['coords'] : null;
    $won      = $winnerId !== null && $id === $winnerId;
    $lost     = $winnerId !== null && $id !== $winnerId;

    $pct = ($hpBefore !== null && $hpBefore > 0 && $hpAfter !== null)
        ? max(0, min(100, (int) round($hpAfter / $hpBefore * 100)))
        : 0;

    ob_start(); ?>
    <div class="card">
        <div class="row gap-1" style="flex-wrap:wrap">
            <h3 class="mb-0" style="font-size:20px"><?= esc($name) ?></h3>
            <?php if ($level !== null): ?><span class="badge mono">L <?= $level ?></span><?php endif ?>
            <?php if ($faction !== null): ?><span class="rarity rare"><?= esc($faction) ?></span><?php endif ?>
            <?php if ($won): ?><span class="badge dot accent">🏆 Победитель</span><?php endif ?>
            <?php if ($lost): ?><span class="badge dim">Повержен</span><?php endif ?>
        </div>
        <div class="caption mt-1"><?= esc($roleLabel) ?></div>

        <div class="stack mt-2" style="gap:8px">
            <?php if ($authed): ?>
                <div class="stat-line">
                    <span class="lbl">HP</span>
                    <div class="bar hp"><span style="--val:<?= $pct ?>%"></span></div>
                    <span class="val mono"><?= $fmt($hpBefore) ?> → <?= $fmt($hpAfter) ?></span>
                </div>
            <?php endif ?>
            <div class="stat-line"><span class="lbl">Сила</span><span class="val mono"><?= $fmt($num($p['strength'] ?? null)) ?></span></div>
            <div class="stat-line"><span class="lbl">Ловкость</span><span class="val mono"><?= $fmt($num($p['agility'] ?? null)) ?></span></div>
            <div class="stat-line"><span class="lbl">Интеллект</span><span class="val mono"><?= $fmt($num($p['intellect'] ?? null)) ?></span></div>
            <?php if ($authed && $coords !== null && $myCharId > 0 && $id === $myCharId): ?>
                <div class="stat-line">
                    <span class="lbl">📍 Где</span>
                    <span class="val mono">X <?= is_numeric($coords['x'] ?? null) ? (int) $coords['x'] : '?' ?> · Y <?= is_numeric($coords['y'] ?? null) ? (int) $coords['y'] : '?' ?> <span class="caption">(клетка <?= is_numeric($coords['cell'] ?? null) ? (int) $coords['cell'] : '?' ?>)</span></span>
                </div>
            <?php endif ?>
        </div>

        <div class="divider-stencil" style="margin:16px 0"></div>

        <div class="stack" style="gap:6px">
            <div class="stat-line">
                <span class="lbl">🗡 Оружие</span>
                <span class="val"><?= $weapon !== null && isset($weapon['name']) ? esc((string) $weapon['name']) : '<span class="dim">—</span>' ?></span>
            </div>
            <div class="stat-line" style="align-items:flex-start">
                <span class="lbl">🛡 Броня</span>
                <span class="val">
                    <?php if ($armor === []): ?><span class="dim">—</span>
                    <?php else: foreach ($armor as $a): ?><?= isset($a['name']) ? esc((string) $a['name']) : '' ?><br><?php endforeach; endif ?>
                </span>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};

$maxDmg = 1.0;
foreach ($rounds as $r) {
    $d = is_numeric($r['finalDamage'] ?? null) ? (float) $r['finalDamage'] : 0.0;
    if ($d > $maxDmg) {
        $maxDmg = $d;
    }
}
$type        = is_string($outcome['type'] ?? null) ? $outcome['type'] : null;
$currentPath = '/' . uri_string();
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<?= view('site/_breadcrumbs', ['items' => $meta['breadcrumbs'] ?? []]) ?>

<section class="block" style="padding-top:32px">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="num"># бой <?= (int) $battle->id ?></span>
                <h1 class="mt-1 mb-0"><?= esc((string) ($att['name'] ?? '?')) ?> <span class="dim">против</span> <?= esc((string) ($def['name'] ?? '?')) ?></h1>
            </div>
            <p class="desc mb-0">
                <?= $createdAt !== '' ? esc(date('d.m.Y H:i', (int) strtotime($createdAt))) : '' ?>
                <?php if ($biome !== null): ?> · биом <?= esc($biome) ?><?php endif ?>
                <?php if ($type === 'exhausted'): ?> · <span class="badge">истощение — ничья</span><?php endif ?>
            </p>
        </div>

        <!-- ADR-092 Фаза 3: auth-бар (расширенный тир через Telegram-вход, общая сессия ADR-061) -->
        <div class="card row" style="margin-bottom:20px;flex-wrap:wrap;gap:12px;justify-content:space-between">
            <?php if ($authed): ?>
                <span class="caption">🔓 Вы вошли как <b><?= esc($tgName) ?></b> — виден ход боя по раундам и HP до/после. 📍 Координаты показываются только для <b>вашего</b> персонажа (чужие локации скрыты).</span>
                <form method="post" action="<?= site_url('logout/telegram') ?>" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="next" value="<?= esc($currentPath, 'attr') ?>">
                    <button class="btn" type="submit">Выйти</button>
                </form>
            <?php else: ?>
                <span class="caption">🔒 Войдите через Telegram, чтобы увидеть <b>координаты боя</b>, <b>ход по раундам</b> и <b>HP до/после</b>.</span>
                <?php if ($botUsername !== ''): ?>
                    <div id="ww-battle-login"></div>
                <?php endif ?>
            <?php endif ?>
        </div>

        <div class="grid grid-2" style="gap:20px">
            <?= $playerCard($att, $winnerId, 'Атакующий', $authed, $myCharId) ?>
            <?= $playerCard($def, $winnerId, 'Защитник', $authed, $myCharId) ?>
        </div>

        <h3 class="kicker mt-4">Ход боя по раундам</h3>
        <?php if (! $authed): ?>
            <div class="card center" style="padding:32px 24px">
                <div style="font-family:var(--font-display);font-size:40px;color:var(--text-muted)">🔒</div>
                <p class="dim mb-0">Пораундовый разбор доступен после входа через Telegram.</p>
            </div>
        <?php elseif ($rounds === []): ?>
            <div class="card"><p class="dim mb-0">Пораундовый лог для этого боя не сохранён.</p></div>
        <?php else: ?>
            <div class="card">
                <div class="stack" style="gap:10px">
                    <?php foreach ($rounds as $r): ?>
                        <?php
                            $rn   = is_numeric($r['round'] ?? null) ? (int) $r['round'] : 0;
                            $aN   = is_scalar($r['attacker'] ?? null) ? (string) $r['attacker'] : '?';
                            $dN   = is_scalar($r['defender'] ?? null) ? (string) $r['defender'] : '?';
                            $dmg  = is_numeric($r['finalDamage'] ?? null) ? (float) $r['finalDamage'] : 0.0;
                            $hpAf = is_numeric($r['defenderHealthAfter'] ?? null) ? (float) $r['defenderHealthAfter'] : null;
                            $crit = ($r['luckyStrikeApplied'] ?? false) === true;
                            $pct  = (int) round($dmg / $maxDmg * 100);
                        ?>
                        <div style="display:flex;gap:12px;align-items:center">
                            <span class="lbl mono" style="flex:0 0 68px;white-space:nowrap">Раунд <?= $rn ?></span>
                            <div class="bar hp" style="flex:0 0 120px;max-width:120px"><span style="--val:<?= max(2, $pct) ?>%"></span></div>
                            <span class="caption mono" style="flex:1 1 auto;min-width:0">
                                <?= esc($aN) ?> → <?= esc($dN) ?> · −<?= round($dmg * 10) / 10 ?><?= $crit ? ' ⚡' : '' ?><?= $hpAf !== null ? ' (HP ' . (round($hpAf * 10) / 10) . ')' : '' ?>
                            </span>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
        <?php endif ?>

        <?php if ($version < 2): ?>
            <p class="caption mt-2 dim">Это старый бой — расширенные данные (экипировка, статы на конец боя, координаты) для него не сохранялись.</p>
        <?php endif ?>

        <div class="row mt-4">
            <a class="btn" href="<?= site_url('battles') ?>">← Ко всем боям</a>
        </div>
    </div>
</section>

<?= $this->endSection() ?>

<?php if (! $authed && $botUsername !== ''): ?>
<?= $this->section('scripts') ?>
<script>
(function(){
    window.onTelegramAuthBattle = function(user){
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
    s.setAttribute('data-onauth', 'onTelegramAuthBattle(user)');
    s.setAttribute('crossorigin', 'anonymous');
    s.setAttribute('referrerpolicy', 'no-referrer');
    var mount = document.getElementById('ww-battle-login');
    if (mount) { mount.appendChild(s); }
})();
</script>
<?= $this->endSection() ?>
<?php endif ?>
