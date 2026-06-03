<?php
/**
 * Публичная хроника PvP-боёв — список (ADR-092 Фаза 2, flat ADR-062).
 *
 * @var list<array<string,mixed>> $rows
 * @var string $q
 * @var \CodeIgniter\Pager\Pager|null $pager
 */
$cur = $pager ? $pager->getCurrentPage() : 1;
$cnt = $pager ? $pager->getPageCount() : 1;
$pageUrl = static function (int $n) use ($q): string {
    $params = ['page' => $n];
    if ($q !== '') {
        $params['q'] = $q;
    }
    return site_url('battles') . '?' . http_build_query($params);
};
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<?= view('site/_breadcrumbs', ['items' => $meta['breadcrumbs'] ?? []]) ?>

<section class="block" style="padding-top:32px">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="num"># хроника</span>
                <h1 class="mt-1 mb-0">Хроника PvP-боёв</h1>
            </div>
            <p class="desc mb-0">Кто кого встретил в пустоши, на каком уровне и чем всё кончилось. Открой бой — увидишь ход схватки по раундам, параметры и экипировку бойцов.</p>
        </div>

        <form class="card" method="get" action="<?= site_url('battles') ?>" style="margin-bottom:24px">
            <div class="grid grid-2" style="gap:12px;align-items:end">
                <div class="field">
                    <label class="label" for="q">Поиск по имени игрока</label>
                    <input class="input" type="text" id="q" name="q" value="<?= esc($q, 'attr') ?>" placeholder="например: aviad_echo" autocomplete="off">
                </div>
                <div class="field">
                    <label class="label" for="id">Перейти к бою по ID</label>
                    <div class="row gap-1">
                        <input class="input" type="number" id="id" name="id" min="1" placeholder="напр. 1024" style="max-width:160px">
                        <button class="btn" type="submit">Найти</button>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($rows === []): ?>
            <div class="card center" style="padding:48px 24px">
                <div style="font-family:var(--font-display);font-size:48px;color:var(--text-muted);letter-spacing:.1em">∅</div>
                <h4><?= $q !== '' ? 'Ничего не найдено' : 'Пока пусто' ?></h4>
                <p class="dim">
                    <?php if ($q !== ''): ?>
                        По запросу «<?= esc($q) ?>» боёв не нашлось. Проверь имя или загляни позже.
                    <?php else: ?>
                        Хроника пока пуста — в&nbsp;пустоши ещё не сошлись. Загляни позже.
                    <?php endif ?>
                </p>
                <?php if ($q !== ''): ?>
                    <a class="btn" href="<?= site_url('battles') ?>">Сбросить поиск</a>
                <?php endif ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Дата</th>
                            <th>Бой</th>
                            <th>Исход</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $id   = (int) ($r['id'] ?? 0);
                                $att  = (string) ($r['attacker'] ?? '—');
                                $def  = (string) ($r['defender'] ?? '—');
                                $aLvl = $r['attackerLevel'] ?? null;
                                $dLvl = $r['defenderLevel'] ?? null;
                                $win  = $r['winnerId'] ?? null;
                                $aWon = $win !== null && (int) $win === (int) ($r['attackerId'] ?? -1);
                                $dWon = $win !== null && (int) $win === (int) ($r['defenderId'] ?? -1);
                                $date = ($r['date'] ?? '') !== '' ? date('d.m.Y H:i', (int) strtotime((string) $r['date'])) : '—';
                            ?>
                            <tr>
                                <td class="mono">#<?= $id ?></td>
                                <td class="caption" style="white-space:nowrap"><?= esc($date) ?></td>
                                <td>
                                    <span class="<?= $aWon ? '' : 'dim' ?>"><?= esc($att) ?><?= $aLvl !== null ? ' <span class="caption">L' . (int) $aLvl . '</span>' : '' ?></span>
                                    <span class="caption">⚔</span>
                                    <span class="<?= $dWon ? '' : 'dim' ?>"><?= esc($def) ?><?= $dLvl !== null ? ' <span class="caption">L' . (int) $dLvl . '</span>' : '' ?></span>
                                </td>
                                <td>
                                    <?php if ($win === null): ?>
                                        <span class="badge">ничья</span>
                                    <?php else: ?>
                                        <span class="badge dot accent">🏆 <?= esc($aWon ? $att : $def) ?></span>
                                    <?php endif ?>
                                </td>
                                <td><a class="btn" href="<?= site_url('battles/view/' . $id) ?>">Смотреть</a></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>

            <?php if ($cnt > 1): ?>
                <div class="card row" style="margin-top:20px">
                    <div class="pagi">
                        <a class="pg <?= $cur <= 1 ? 'is-disabled' : '' ?>" href="<?= $cur > 1 ? esc($pageUrl($cur - 1), 'attr') : '#' ?>">‹</a>
                        <?php for ($i = max(1, $cur - 2); $i <= min($cnt, $cur + 2); $i++): ?>
                            <a class="pg <?= $i === $cur ? 'is-active' : '' ?>" href="<?= esc($pageUrl($i), 'attr') ?>"><?= $i ?></a>
                        <?php endfor ?>
                        <a class="pg <?= $cur >= $cnt ? 'is-disabled' : '' ?>" href="<?= $cur < $cnt ? esc($pageUrl($cur + 1), 'attr') : '#' ?>">›</a>
                    </div>
                    <span class="caption">Страница <?= $cur ?> из <?= $cnt ?></span>
                </div>
            <?php endif ?>
        <?php endif ?>
    </div>
</section>

<?= $this->endSection() ?>
