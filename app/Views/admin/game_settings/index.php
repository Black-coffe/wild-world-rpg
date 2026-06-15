<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Параметры баланса<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры</p>
        <h1 class="aui-display">⚙️ Параметры баланса</h1>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>
<?php $err = session()->getFlashdata('error'); if ($err): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div><?= esc($err) ?></div></div>
<?php endif; ?>

<div class="aui-alert aui-alert--info" style="margin-bottom:var(--sp-4)">
    <i class="ri-information-line"></i>
    <div>
        Live-tunable параметры игрового баланса. Изменение применяется без редеплоя (кеш 60 сек),
        audit-log сохраняет кто/когда/что. <strong>Soft bounds</strong> (warning жёлтым) — рекомендуемый
        диапазон. <strong>Hard bounds</strong> — сохранение запрещено вне диапазона. Кнопка
        <strong>«Сбросить»</strong> возвращает к default-значению, выбранному при дизайне параметра.
    </div>
</div>

<?php if (! empty($categories)): ?>
    <div class="aui-tabs" id="gs-categories" style="margin-bottom:var(--sp-4); flex-wrap:wrap">
        <a class="aui-tab <?= $activeCategory === null ? 'is-active' : '' ?>" href="<?= site_url('admin/game-settings') ?>">Все</a>
        <?php foreach ($categories as $cat): ?>
            <a class="aui-tab <?= $activeCategory === $cat ? 'is-active' : '' ?>" href="<?= site_url('admin/game-settings') . '?category=' . urlencode($cat) ?>"><?= esc($cat) ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (empty($rows)): ?>
    <div class="aui-card"><div class="aui-empty"><i class="ri-inbox-line"></i><p>Нет настроек в выбранной категории.</p></div></div>
<?php endif; ?>

<?php foreach ($grouped as $catName => $catRows): ?>
    <div class="aui-card" style="margin-bottom:var(--sp-5)">
        <div class="aui-card__head"><i class="ri-settings-3-line"></i><span class="aui-card__title"><?= esc($catName) ?></span></div>
        <div class="aui-tablewrap">
        <table class="aui-table">
            <thead><tr>
                <th style="width:26%">Ключ</th>
                <th style="width:7%">Тип</th>
                <th style="width:17%">Значение</th>
                <th style="width:12%">Default</th>
                <th style="width:13%">Recommended</th>
                <th style="width:13%">Hard bounds</th>
                <th style="width:12%"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($catRows as $row): ?>
                <?php
                    $type = (string) ($row['value_type'] ?? 'string');
                    $currentValue = match ($type) {
                        'int'    => (string) ($row['value_int'] ?? ''),
                        'float'  => (string) ($row['value_float'] ?? ''),
                        'bool'   => ((int) ($row['value_bool'] ?? 0)) === 1 ? 'true' : 'false',
                        default  => (string) ($row['value_string'] ?? ''),
                    };
                    $key       = (string) ($row['setting_key'] ?? '');
                    $recMin    = (string) ($row['recommended_min'] ?? '—');
                    $recMax    = (string) ($row['recommended_max'] ?? '—');
                    $hardMin   = (string) ($row['hard_min'] ?? '—');
                    $hardMax   = (string) ($row['hard_max'] ?? '—');
                    $isNumeric = in_array($type, ['int', 'float'], true);

                    // Warning жёлтым если вне recommended.
                    $outsideRecommended = false;
                    if ($isNumeric && is_numeric($currentValue)) {
                        $v = (float) $currentValue;
                        if (is_numeric($row['recommended_min'] ?? null) && $v < (float) $row['recommended_min']) {
                            $outsideRecommended = true;
                        }
                        if (is_numeric($row['recommended_max'] ?? null) && $v > (float) $row['recommended_max']) {
                            $outsideRecommended = true;
                        }
                    }
                ?>
                <tr<?= $outsideRecommended ? ' style="background:var(--warning-wash)"' : '' ?>>
                    <td>
                        <details>
                            <summary style="cursor:pointer; list-style:none; display:flex; align-items:center; gap:6px">
                                <span class="aui-mono strong"><?= esc($key) ?></span>
                                <i class="ri-information-line aui-faint" title="Показать обоснование"></i>
                            </summary>
                            <div class="aui-code" style="margin-top:var(--sp-2); white-space:normal; font-family:var(--font-body); line-height:1.6">
                                <div><strong>Почему сейчас именно так:</strong><br><?= esc($row['rationale_text'] ?? '') ?></div>
                                <div style="margin-top:var(--sp-2)"><strong>На что влияет:</strong><br><?= esc($row['effect_text'] ?? '') ?></div>
                                <div style="margin-top:var(--sp-2)"><strong>Если выше:</strong><br><?= esc($row['above_effect_text'] ?? '') ?></div>
                                <div style="margin-top:var(--sp-2)"><strong>Если ниже:</strong><br><?= esc($row['below_effect_text'] ?? '') ?></div>
                                <?php if (! empty($row['updated_by'])): ?>
                                    <div style="margin-top:var(--sp-2)" class="aui-faint">
                                        Последнее изменение: <?= esc((string) $row['updated_by']) ?> (<?= esc((string) ($row['updated_at'] ?? '')) ?>)
                                    </div>
                                <?php endif; ?>
                            </div>
                        </details>
                    </td>
                    <td><span class="aui-badge"><?= esc($type) ?></span></td>
                    <td>
                        <form action="<?= site_url('admin/game-settings/update') ?>" method="post" style="display:flex; gap:6px; align-items:center">
                            <?= csrf_field() ?>
                            <input type="hidden" name="setting_key" value="<?= esc($key) ?>">
                            <?php if ($type === 'bool'): ?>
                                <select name="value" class="aui-select" style="padding:6px 28px 6px 10px">
                                    <option value="true"  <?= $currentValue === 'true'  ? 'selected' : '' ?>>true</option>
                                    <option value="false" <?= $currentValue === 'false' ? 'selected' : '' ?>>false</option>
                                </select>
                            <?php else: ?>
                                <input type="<?= $isNumeric ? 'number' : 'text' ?>" name="value" value="<?= esc($currentValue) ?>"
                                       class="aui-input <?= $isNumeric ? 'aui-input--mono' : '' ?>" style="padding:7px 10px"
                                       <?= $type === 'float' ? 'step="0.01"' : '' ?>>
                            <?php endif; ?>
                            <button type="submit" class="aui-btn aui-btn--primary aui-btn--icon" title="Сохранить" aria-label="Сохранить"><i class="ri-save-line"></i></button>
                        </form>
                    </td>
                    <td><span class="aui-mono aui-small"><?= esc((string) ($row['default_value_text'] ?? '')) ?></span></td>
                    <td class="aui-small aui-muted"><?= esc($recMin) ?> &mdash; <?= esc($recMax) ?></td>
                    <td class="aui-small aui-muted"><?= esc($hardMin) ?> &mdash; <?= esc($hardMax) ?></td>
                    <td>
                        <form action="<?= site_url('admin/game-settings/reset') ?>" method="post"
                              onsubmit="return confirm('Сбросить «<?= esc($key) ?>» к default-значению?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="setting_key" value="<?= esc($key) ?>">
                            <button type="submit" class="aui-btn aui-btn--subtle aui-btn--sm" title="Сбросить к default"><i class="ri-refresh-line"></i> Сбросить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endforeach; ?>

<?= $this->endSection() ?>
