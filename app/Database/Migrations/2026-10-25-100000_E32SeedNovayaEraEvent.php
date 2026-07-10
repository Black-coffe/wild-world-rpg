<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E32 (ROADMAP-100-SESSIONS) — витринное событие «Новая эра»: глобальный праздничный
 * буст добычи на неделю в честь консолидированного анонса этапа I.
 *
 * Механика — честный реюз тракта добычи: событие даёт +effect_value% ко ВСЕМ
 * добываемым ресурсам, пока активно (зеркало глобального штрафа LocustExodus, но
 * положительное). Применяется в GatherEventModifierService::applyResourceModifiers
 * в МОМЕНТ добычи — не tick-эффект → нет one_shot-компаундинга (урок ADR-141).
 *
 * effect_kind/handler_key='noop': EventDispatcher (tick, ежеминутно) резолвит чисто и
 * НЕ применяет ничего (без warning-спама). frequency_per_week=0: авто-активатор
 * событие НЕ выбирает — только ручная активация строки в active_events (deliberate
 * week-long showcase, а не случайный выбор из пула).
 *
 * Глобальный буст добычи сильнее всего помогает низким уровням (они добывают больше и
 * чаще) — прямое попадание в цель E32 «вернуть уснувших» без гейта по уровню (529/611
 * персонажей на L1). Активируется владельцем через admin UI; killswitch
 * events.novaya_era.enabled — аварийный офф без деплоя.
 *
 * Idempotent (events по name_english, game_settings по setting_key).
 * events = KEEP (контент-определения), game_settings = KEEP → WipeManifest не тронут.
 */
class E32SeedNovayaEraEvent extends Migration
{
    private const EVENT_NAME_EN = 'NovayaEra';

    /** Величина буста в процентах ко всем добываемым ресурсам. Tunable через admin (events.effect_value). */
    private const EFFECT_PERCENT = 25;

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1) Строка-определение события (dormant: без active_events-строки не действует).
        $existing = $this->db->table('events')->where('name_english', self::EVENT_NAME_EN)->get()->getRowArray();
        if (empty($existing)) {
            $this->db->table('events')->insert([
                'name'               => 'Новая эра',
                'name_english'       => self::EVENT_NAME_EN,
                'handler_key'        => 'noop', // tick-диспетчер ничего не применяет; буст — в тракте добычи
                'description'        => 'Остров будто выдохнул. После долгой череды перемен земля щедра как никогда: '
                    . 'жилы бьют полнее, поросль гуще, а находки — крупнее обычного. Пока держится эта пора, '
                    . 'любая добыча приносит заметно больше. Самое время браться за дело и звать тех, кто ушёл.',
                'biome_ids'          => null,       // global — буст ко всей добыче, вне зависимости от биома
                'event_type'         => 'global',
                'random_coverage'    => 0,
                'duration'           => 10080,      // 7 дней в минутах (справочно; факт задаёт active_events.end_time)
                'frequency_per_week' => 0,          // 0 → авто-активатор НЕ выбирает; только ручная активация
                'effect_type'        => 'buff',
                'effect_value'       => self::EFFECT_PERCENT,
                'protection_item_id' => null,
                'start_time'         => null,
                'end_time'           => null,
                'img_path'           => 'uploads/telegram/default_event_image.png',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        // 2) Killswitch (ADR-024). Дефолт true: настоящий гейт активации — active_events-строка,
        //    флаг лишь глушит буст при уже активном событии (аварийный офф без деплоя).
        $flagKey = 'events.novaya_era.enabled';
        $flagRow = $this->db->table('game_settings')->where('setting_key', $flagKey)->get()->getRowArray();
        if (empty($flagRow)) {
            $this->db->table('game_settings')->insert([
                'setting_key'        => $flagKey,
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'category'           => 'world',
                'rationale_text'     => 'Аварийный killswitch праздничного буста добычи «Новая эра» (E32). true — если '
                    . 'событие активно (есть строка в active_events), даёт +' . self::EFFECT_PERCENT . '% ко всей добыче. '
                    . 'Дефолт true, потому что ИСТИННЫЙ гейт активации — наличие active_events-строки: без неё буст не '
                    . 'действует независимо от флага (byte-identical).',
                'effect_text'        => 'GatherEventModifierService::applyResourceModifiers умножает выхлоп каждого ресурса '
                    . 'на (1 + events.effect_value/100), пока событие NovayaEra активно И флаг true.',
                'above_effect_text'  => 'true — буст работает при активном событии (штатно на время «Новой эры»).',
                'below_effect_text'  => 'false — буст мгновенно выключен даже при активном событии (мягкий откат без '
                    . 'деплоя; сам ивент остаётся активным, но добыча возвращается к обычной).',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'events.novaya_era.enabled')->delete();
        // active_events удаляем каскадом через FK при удалении events-строки.
        $this->db->table('events')->where('name_english', self::EVENT_NAME_EN)->delete();
    }
}
