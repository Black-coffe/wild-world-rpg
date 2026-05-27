<?php
/**
 * Раздел вики — на дизайн-системе ADR-062.
 *
 * @var string $sectionKey
 * @var string $heading
 * @var list<array<string,mixed>> $items
 * @var list<array{name:string,url:string}> $breadcrumbs
 */
$labels = [
    'rarity'              => 'Редкость',
    'level_required'      => 'Уровень',
    'price'               => 'Цена',
    'biome_type'          => 'Тип',
    'danger_level'        => 'Опасность',
    'survival_difficulty' => 'Выживание',
    'weapon_type'         => 'Класс',
    'damage_value'        => 'Урон',
    'damage_type'         => 'Тип урона',
    'armor_value'         => 'Броня',
    'armor_type'          => 'Тип брони',
    'slot'                => 'Слот',
    'building_type'       => 'Тип',
    'hp'                  => 'HP',
    'construction_time'   => 'Стройка, мин',
    'tax'                 => 'Налог',
    'level'               => 'Уровень',
    'min_character_level' => 'Треб. ур.',
    'type'                => 'Тип',
];

// Маппинг rarity → класс .rarity для цветной плашки
$rarityClass = static function (string $r): string {
    $r = mb_strtolower(trim($r));
    return match (true) {
        str_contains($r, 'легенд') || $r === 'legendary' => 'legend',
        str_contains($r, 'эпич')   || $r === 'epic'      => 'epic',
        str_contains($r, 'редк')   || $r === 'rare'      => 'rare',
        str_contains($r, 'необыч') || $r === 'uncommon'  => 'uncommon',
        str_contains($r, 'обыч')   || $r === 'common'    => 'common',
        str_contains($r, 'хлам')   || $r === 'trash'     => 'trash',
        str_contains($r, 'мифич')  || $r === 'mythic'    => 'mythic',
        default                                          => '',
    };
};
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<?= view('site/_breadcrumbs', ['items' => $breadcrumbs ?? []]) ?>

<section class="block" style="padding-top:24px">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="num"># <?= esc($sectionKey) ?></span>
                <h1 class="mt-1 mb-0"><?= esc($heading) ?></h1>
            </div>
            <p class="desc mb-0"><span class="dim">Найдено записей:</span> <b><?= count($items) ?></b></p>
        </div>

        <?php if ($items === []): ?>
            <div class="card center" style="padding:48px 24px">
                <div style="font-family:var(--font-display);font-size:48px;color:var(--text-muted);letter-spacing:.1em">∅</div>
                <h4>Пока пусто</h4>
                <p class="dim">В&nbsp;этом разделе ещё нет данных. Зайди позже.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($items as $it): ?>
                    <?php
                        $name    = is_string($it['name'] ?? null) ? $it['name'] : '';
                        $icon    = is_string($it['icon_text'] ?? null) ? trim($it['icon_text']) : '';
                        $desc    = is_string($it['description'] ?? null) ? $it['description'] : '';
                        $img     = is_string($it['image'] ?? null) && $it['image'] !== '' ? base_url($it['image']) : null;
                        $ability = is_string($it['unique_ability'] ?? null) ? $it['unique_ability'] : '';
                        $rarity  = is_string($it['rarity'] ?? null) ? trim($it['rarity']) : '';
                        $rClass  = $rarity !== '' ? $rarityClass($rarity) : '';
                    ?>
                    <article class="item-card<?= $rClass !== '' && in_array($rClass, ['legend', 'mythic'], true) ? ' ' . $rClass : '' ?>">
                        <?php if ($img !== null): ?>
                            <div class="img" style="background-image:url('<?= esc($img, 'attr') ?>')">
                                <div class="sheen"></div>
                            </div>
                        <?php elseif ($icon !== ''): ?>
                            <div class="img">
                                <div class="placeholder"><?= esc($icon) ?></div>
                            </div>
                        <?php endif ?>
                        <div class="meta">
                            <div class="row" style="justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:nowrap">
                                <div class="name"><?= $img === null && $icon !== '' ? '' : ($icon !== '' ? esc($icon) . '&nbsp;' : '') ?><?= esc($name) ?></div>
                                <?php if ($rarity !== ''): ?>
                                    <span class="rarity <?= esc($rClass) ?>"><?= esc($rarity) ?></span>
                                <?php endif ?>
                            </div>
                            <?php if ($ability !== ''): ?>
                                <p class="mb-0" style="font-size:13px;color:var(--accent);line-height:1.5">⚡ <?= esc($ability) ?></p>
                            <?php endif ?>
                            <?php if ($desc !== ''): ?>
                                <p class="dim mb-0" style="font-size:13px;line-height:1.5"><?= esc(mb_substr(strip_tags($desc), 0, 200)) ?><?= mb_strlen($desc) > 200 ? '…' : '' ?></p>
                            <?php endif ?>
                            <?php
                                // Собираем scalar badges, скрывая rarity (показан выше)
                                $badges = [];
                                foreach ($labels as $key => $label) {
                                    if ($key === 'rarity' || !array_key_exists($key, $it)) {
                                        continue;
                                    }
                                    $val = $it[$key];
                                    if (!is_scalar($val)) {
                                        continue;
                                    }
                                    $valStr = trim((string) $val);
                                    if ($valStr === '' || $valStr === '0') {
                                        continue;
                                    }
                                    $badges[] = [$label, $valStr];
                                }
                            ?>
                            <?php if ($badges !== []): ?>
                                <div class="stat-row" style="gap:6px 12px">
                                    <?php foreach ($badges as [$lbl, $val]): ?>
                                        <span><span class="dim"><?= esc($lbl) ?>:</span> <b><?= esc($val) ?></b></span>
                                    <?php endforeach ?>
                                </div>
                            <?php endif ?>
                        </div>
                    </article>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </div>
</section>

<?= $this->endSection() ?>
