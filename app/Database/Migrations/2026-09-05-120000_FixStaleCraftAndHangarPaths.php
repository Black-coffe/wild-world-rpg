<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Аудит путей 2026-09-05 (продолжение `FixStaleHubPathsInTips`).
 *
 * После починки трёх каналов про специализацию прогнали ВЕСЬ player-facing контент против
 * реального индекса кнопок (403 подписи, извлечённые из `app/`). Осталось три класса лжи:
 *
 *  1. **«Стандартный верстак» вместо «🔧 Стандартный крафт»** (советы 70, 92; тексты
 *     `whats_new_topics` 4). Кнопки с таким именем в интерфейсе НЕТ вовсе: «Стандартный
 *     верстак» — это тир крафта и верстак-предмет, а раздел подписан «🔧 Стандартный крафт»
 *     ({@see \App\Services\Player\CraftService::hubCaption()}). Плюс в паре с ним звучал
 *     «🛠 Крафт» — нижняя кнопка называется «🔨 Крафт».
 *  2. **Ангар под тремя эмодзи** — «🚁 Ангар» (совет 130) и «🏭 Ангар» (совет 132) при живой
 *     кнопке «🤖 Ангар». Игрок ищет глазами по эмодзи, а не по слову.
 *  3. **Дверь на тап глубже, чем сказано** — «🛒 Магазин» (советы 114, 115) живёт внутри
 *     «⚙️ Ещё» с ADR-150, «🥫 Консервы» (совет 122) — внутри «🔨 Общий крафт», а совет 86
 *     звал «База» без эмодзи. Тот же класс, что специализация внутри «⚙️ Развитие».
 *
 * Советы переводим на плейсхолдеры `{{menu:<группа>}}` ({@see \App\Services\Player\TipService::applyMenuLabels()}),
 * чтобы текст пережил следующую смену каркаса. `whats_new_topics` подстановки не знает
 * (рендер сырой, {@see \App\Controllers\Telegram\Commands\Actions\WhatsNew\WhatsNewTopicAction}),
 * поэтому там формулировка без названий кнопок нижнего меню.
 *
 * Идемпотентна: каждая правка — адресный `REPLACE()` по точной старой подстроке, повторный
 * прогон ничего не меняет. Новых таблиц нет, WipeManifest не трогаем (`game_tips`,
 * `whats_new_topics` уже классифицированы).
 */
class FixStaleCraftAndHangarPaths extends Migration
{
    /** Советы: title_en → список [что искать, на что заменить]. */
    private const TIP_REPLACEMENTS = [
        'DroneScout' => [
            ['🛠 Крафт → 🔧 Стандартный верстак → 🚁 Дрон-разведчик', '{{menu:craft}} → 🔧 Стандартный крафт → 🚁 Дрон-разведчик'],
        ],
        'CargoDroneToStorage' => [
            ['(«Крафт» → «Стандартный верстак»)', '(«{{menu:craft}}» → «🔧 Стандартный крафт»)'],
        ],
        'DroneChargesInFieldToo' => [
            ['«🚁 Ангар»', '«🤖 Ангар»'],
        ],
        'DroneCraftInsurance' => [
            ['«🏭 Ангар»', '«🤖 Ангар»'],
        ],
        'FirstShelter' => [
            ['*«База»* в нижнем меню', '*«{{menu:base}}»* в нижнем меню'],
        ],
        'WildMeatUses' => [
            ['*«🔨 Крафт»* → *«🥫 Консервы»*', '*«{{menu:craft}}»* → *«🔨 Общий крафт»* → *«🥫 Консервы»*'],
        ],
        'SellGearToVendor' => [
            ['*«🛒 Магазин» → «💰 Продать крафт»*', '*«{{menu:more}}» → «🛒 Магазин» → «💰 Продать крафт»*'],
        ],
        'CraftShopfrontIsAlive' => [
            ['*«🛒 Магазин» → «🛍️ Купить крафт»*', '*«{{menu:more}}» → «🛒 Магазин» → «🛍️ Купить крафт»*'],
        ],
    ];

    /** «Что нового»: id темы → список [что искать, на что заменить]. */
    private const WHATS_NEW_REPLACEMENTS = [
        4 => [
            ['крафтится в Стандартном верстаке', 'крафтится в разделе «🔧 Стандартный крафт»'],
            ['появляются на карте, в Стандартном верстаке и на Перс', 'появляются на карте, в разделе «🔧 Стандартный крафт» и в карточке персонажа'],
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::TIP_REPLACEMENTS as $titleEn => $pairs) {
            foreach ($pairs as [$from, $to]) {
                $this->db->query(
                    'UPDATE ' . $this->db->prefixTable('game_tips')
                    . ' SET content = REPLACE(content, ?, ?), updated_at = ? WHERE title_en = ? AND content LIKE ?',
                    [$from, $to, $now, $titleEn, '%' . $from . '%']
                );
            }
        }

        foreach (self::WHATS_NEW_REPLACEMENTS as $id => $pairs) {
            foreach ($pairs as [$from, $to]) {
                $this->db->query(
                    'UPDATE ' . $this->db->prefixTable('whats_new_topics')
                    . ' SET content = REPLACE(content, ?, ?) WHERE id = ? AND content LIKE ?',
                    [$from, $to, $id, '%' . $from . '%']
                );
            }
        }
    }

    public function down(): void
    {
        // Откат не возвращает старые формулировки намеренно: они называли кнопки,
        // которых в интерфейсе нет. Вернуть ложь — хуже, чем оставить правду.
    }
}
