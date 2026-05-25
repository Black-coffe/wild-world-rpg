<?php
/**
 * @var string $sectionKey
 * @var string $heading
 * @var list<array<string,mixed>> $items
 */
$labels = [
    'rarity'              => 'Редкость',
    'level_required'      => 'Уровень',
    'price'              => 'Цена',
    'biome_type'         => 'Тип',
    'danger_level'       => 'Опасность',
    'survival_difficulty' => 'Выживание',
    'weapon_type'        => 'Класс',
    'damage_value'       => 'Урон',
    'damage_type'        => 'Тип урона',
    'armor_value'        => 'Броня',
    'armor_type'         => 'Тип брони',
    'slot'               => 'Слот',
    'building_type'      => 'Тип',
    'hp'                 => 'HP',
    'construction_time'  => 'Стройка, мин',
    'tax'                => 'Налог',
    'level'              => 'Уровень',
    'min_character_level' => 'Треб. ур.',
    'type'               => 'Тип',
];
?>
<?= $this->extend('site/layout') ?>
<?= $this->section('content') ?>

<section class="ww-page-head">
    <div class="container">
        <a class="ww-back" href="<?= base_url('wiki') ?>">← Вся вики</a>
        <h1><?= esc($heading) ?></h1>
    </div>
</section>

<section class="ww-section">
    <div class="container">
        <?php if ($items === []): ?>
            <p class="ww-muted">В этом разделе пока нет данных.</p>
        <?php else: ?>
        <div class="row g-4">
            <?php foreach ($items as $it): ?>
                <?php
                $name = is_string($it['name'] ?? null) ? $it['name'] : '';
                $icon = is_string($it['icon_text'] ?? null) ? trim($it['icon_text']) : '';
                $desc = is_string($it['description'] ?? null) ? $it['description'] : '';
                $img  = is_string($it['image'] ?? null) && $it['image'] !== '' ? base_url($it['image']) : null;
                $ability = is_string($it['unique_ability'] ?? null) ? $it['unique_ability'] : '';
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="ww-entry">
                        <?php if ($img !== null): ?><div class="ww-entry-img" style="background-image:url('<?= esc($img, 'attr') ?>')"></div><?php endif; ?>
                        <div class="ww-entry-body">
                            <h3 class="ww-entry-title"><?= $icon !== '' ? esc($icon) . ' ' : '' ?><?= esc($name) ?></h3>
                            <?php if ($ability !== ''): ?><p class="ww-entry-ability">⚡ <?= esc($ability) ?></p><?php endif; ?>
                            <?php if ($desc !== ''): ?><p class="ww-entry-desc"><?= esc(mb_substr($desc, 0, 220)) ?><?= mb_strlen($desc) > 220 ? '…' : '' ?></p><?php endif; ?>
                            <div class="ww-badges">
                                <?php foreach ($labels as $key => $label): ?>
                                    <?php
                                    if (! array_key_exists($key, $it)) { continue; }
                                    $val = $it[$key];
                                    if (! is_scalar($val)) { continue; }
                                    $valStr = trim((string) $val);
                                    if ($valStr === '' || $valStr === '0') { continue; }
                                    ?>
                                    <span class="ww-badge"><span class="ww-badge-k"><?= esc($label) ?></span> <?= esc($valStr) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<?= $this->endSection() ?>
