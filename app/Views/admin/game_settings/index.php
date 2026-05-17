<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2 class="mb-3">⚙️ Параметры баланса</h2>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success" role="alert">
        <?= session()->getFlashdata('success') ?>
    </div>
<?php endif; ?>

<?php $err = session()->getFlashdata('error'); if ($err): ?>
    <div class="alert alert-danger" role="alert">
        <?= esc($err) ?>
    </div>
<?php endif; ?>

<p class="text-muted">
    Live-tunable параметры игрового баланса. Изменение применяется без редеплоя
    (кеш 60 сек), audit-log сохраняет кто/когда/что.
    <strong>Soft bounds</strong> (warning жёлтым) — рекомендуемый диапазон.
    <strong>Hard bounds</strong> — сохранение запрещено вне диапазона.
    Кнопка <strong>«Сбросить»</strong> возвращает к default-значению,
    выбранному при дизайне параметра.
</p>

<?php if (! empty($categories)): ?>
    <ul class="nav nav-tabs mb-3" id="gs-categories" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?= $activeCategory === null ? 'active' : '' ?>"
               href="<?= site_url('admin/game-settings') ?>">Все</a>
        </li>
        <?php foreach ($categories as $cat): ?>
            <li class="nav-item">
                <a class="nav-link <?= $activeCategory === $cat ? 'active' : '' ?>"
                   href="<?= site_url('admin/game-settings') . '?category=' . urlencode($cat) ?>">
                    <?= esc($cat) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if (empty($rows)): ?>
    <div class="alert alert-info">Нет настроек в выбранной категории.</div>
<?php endif; ?>

<?php foreach ($grouped as $catName => $catRows): ?>
    <h4 class="mt-4 mb-2"><?= esc($catName) ?></h4>

    <table class="table table-striped table-bordered align-middle mb-4">
        <thead class="table-light">
        <tr>
            <th style="width:24%">Ключ</th>
            <th style="width:8%">Тип</th>
            <th style="width:14%">Значение</th>
            <th style="width:14%">Default</th>
            <th style="width:14%">Recommended</th>
            <th style="width:14%">Hard bounds</th>
            <th style="width:12%">Действия</th>
        </tr>
        </thead>
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
                $rowClass = $outsideRecommended ? 'table-warning' : '';
            ?>
            <tr class="<?= $rowClass ?>">
                <td>
                    <code><?= esc($key) ?></code>
                    <button type="button"
                            class="btn btn-sm btn-link p-0 ms-1"
                            data-bs-toggle="collapse"
                            data-bs-target="#detail-<?= esc(md5($key)) ?>"
                            aria-expanded="false"
                            title="Показать обоснование">
                        <i class="ri-information-line"></i>
                    </button>
                    <div class="collapse mt-2" id="detail-<?= esc(md5($key)) ?>">
                        <div class="border rounded p-2 small">
                            <div><strong>Почему сейчас именно так:</strong><br>
                                <?= esc($row['rationale_text'] ?? '') ?></div>
                            <div class="mt-2"><strong>На что влияет:</strong><br>
                                <?= esc($row['effect_text'] ?? '') ?></div>
                            <div class="mt-2"><strong>Если выше:</strong><br>
                                <?= esc($row['above_effect_text'] ?? '') ?></div>
                            <div class="mt-2"><strong>Если ниже:</strong><br>
                                <?= esc($row['below_effect_text'] ?? '') ?></div>
                            <?php if (! empty($row['updated_by'])): ?>
                                <div class="mt-2 text-muted">
                                    Последнее изменение: <?= esc((string) $row['updated_by']) ?>
                                    (<?= esc((string) ($row['updated_at'] ?? '')) ?>)
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td><span class="badge bg-secondary"><?= esc($type) ?></span></td>
                <td>
                    <form action="<?= site_url('admin/game-settings/update') ?>"
                          method="post" class="d-flex gap-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="setting_key" value="<?= esc($key) ?>">
                        <?php if ($type === 'bool'): ?>
                            <select name="value" class="form-select form-select-sm">
                                <option value="true"  <?= $currentValue === 'true'  ? 'selected' : '' ?>>true</option>
                                <option value="false" <?= $currentValue === 'false' ? 'selected' : '' ?>>false</option>
                            </select>
                        <?php else: ?>
                            <input type="<?= $isNumeric ? 'number' : 'text' ?>"
                                   name="value"
                                   value="<?= esc($currentValue) ?>"
                                   class="form-control form-control-sm"
                                   <?= $type === 'float' ? 'step="0.01"' : '' ?>>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-primary"
                                title="Сохранить">
                            <i class="ri-save-line"></i>
                        </button>
                    </form>
                </td>
                <td><code><?= esc((string) ($row['default_value_text'] ?? '')) ?></code></td>
                <td><small><?= esc($recMin) ?> &mdash; <?= esc($recMax) ?></small></td>
                <td><small><?= esc($hardMin) ?> &mdash; <?= esc($hardMax) ?></small></td>
                <td>
                    <form action="<?= site_url('admin/game-settings/reset') ?>"
                          method="post"
                          onsubmit="return confirm('Сбросить «<?= esc($key) ?>» к default-значению?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="setting_key" value="<?= esc($key) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-warning"
                                title="Сбросить к default">
                            <i class="ri-refresh-line"></i> Сбросить
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>

<?= $this->endSection() ?>
