<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-150 Слайс 4 — killswitch группы «⚙️ Ещё» (пере-архитектура навигации).
 *
 * Создаёт дом для всего, что не «место» и не «дело»: торговля, Арена, развлечения (+Оракул),
 * фракция/проект, реферал, подать, помощь, настройки. Отдельного агрегатора для этой группы
 * в игре НЕ существовало.
 *
 * 🔴 Триггерная находка аудита (firehose, 2026-07-09): «🏟 Арена» включена на проде
 * (`pvp.duel.enabled=1`), но за всё время НОЛЬ тапов — её единственные входы это хаб поселения
 * (куда надо физически дойти) и «🏆 Рейтинг», который сам открывается только из Арены (замкнутый
 * круг). Оракул (`oracle.enabled=1`) — 3 тапа за всё время, лежал на дне цепочки
 * «Окопаться → Развлечения → Оракул». BUILT-BUT-INVISIBLE (правило UX-DISCOVERABILITY).
 *
 * false (default) — DORMANT: byte-identical (нижняя кнопка «Настройки» как раньше, экрана «Ещё»
 * нет). true — «⚙️ Ещё» занимает слот «Настроек» в нижнем меню; сами Настройки живут внутри
 * (один тап) + `/settings` + текст «настройки» → путь не теряется. Активация после Tier-3.
 *
 * Кросс-групповые кнопки с карточки Перса (Магазин/Развлечения/фракция/реферал) НЕ удаляются
 * в этом слайсе — anti-orphan; чистка дуал-хоминга запланирована на финал ADR-150.
 *
 * game_settings = KEEP (WipeManifest не трогаем — таблица уже классифицирована).
 * Idempotent по setting_key. Категория world (рядом с navigation.world_hub / me_hub / tasks_hub).
 */
class Adr150NavMoreHubGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'navigation.more_hub.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'ADR-150 Слайс 4: killswitch группы «⚙️ Ещё». Аудит firehose (2026-07-09): Арена включена (pvp.duel.enabled=1), но за всё время ноль тапов — вход только из хаба поселения и из Рейтинга, который сам открывается лишь из Арены (замкнутый круг). Оракул при oracle.enabled=1 набрал 3 тапа: лежал на дне цепочки «Окопаться → Развлечения → Оракул». Отдельного дома у группы не было. false (default) — DORMANT, byte-identical. true — «⚙️ Ещё» занимает слот «Настроек» в нижнем меню, Настройки живут внутри (один тап) + /settings. Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'Появляется экран MoreSurfaceService (callback moreHub): Магазин, Арена (при pvp.duel.enabled), Развлечения (внутри — Оракул), фракция/проект или lock-кнопка <L10, реферал и подать (при своих killswitch), Путь новичка, Что нового, Настройки. Нижняя кнопка «Настройки» заменяется на «⚙️ Ещё». Каждая фича уважает СВОЙ killswitch — dormant не обещаем.',
                'above_effect_text'  => 'true — Арена и Оракул наконец находимы с постоянного экрана в один тап; торговля, фракция и помощь собраны в одном доме. Ожидаемый эффект — первые тапы по Арене (сейчас ровно 0) и рост PvP-активности.',
                'below_effect_text'  => 'false — текущее поведение: Арена достижима только из поселения, Оракул — через «Развлечения» с карточки Перса, Настройки — нижней кнопкой; отдельного экрана «Ещё» нет.',
            ],
        ];

        $defaults = [
            'category'        => 'world',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'navigation.more_hub.enabled')->delete();
    }
}
