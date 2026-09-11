<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-186 §5 (pvp-detection-clarity-26, BLOCK-3 minor 9) — два `GameSettings` ключа,
 * категория `combat`:
 *
 *   1. `pvp.attack_cooldown_sec` — выносит магическое число `Config\GameBalance::
 *      $pvpAttackCooldownSec` (было 30) в админку. Значение байт-в-байт равно
 *      прежнему хардкоду; `AttackPlayerAction`/`DuelAction` (story `-26`) читают
 *      его оттуда, а `$cfg->pvpAttackCooldownSec` остаётся страховочным дефолтом
 *      третьим аргументом `GameSettingsService::get()`.
 *   2. `pvp.standoff.min_alert_interval_sec` — новый потолок частоты пуш-тревог
 *      ОДНОМУ защитнику, независимый от кулдауна атаки: без него нападавший после
 *      своей же отмены переоткрывает окно каждые 30 с бессрочно (`cancelled` не
 *      армирует `pvp.standoff.cooldown_sec` — {@see \App\Services\PVE\
 *      PvpStandoffService::COOLDOWN_ARMING_STATUSES}), слав ~2 тревоги в минуту.
 *      `0` выключает потолок целиком. Читает `PvpStandoffService::
 *      shouldAlertDefender()`, стамп — колонка `pvp_standoffs.last_alerted_at`
 *      (часы БД, не PHP), добавляемая здесь же.
 *
 * Также добавляет `pvp_standoffs.last_alerted_at` (DATETIME NULL) — переживает
 * перезапуск и не зависит от 60-секундного TTL `GameSettingsService`-кэша, в
 * отличие от `Cache`. Таблица уже классифицирована `PLAYER_DATA` в
 * `Config\WipeManifest` (линк `attacker_id`/`defender_id`) — новая колонка едет
 * той же классификацией, отдельной записи не требует.
 *
 * `game_settings` = KEEP. Идемпотентно по `setting_key` и по прямому `SHOW COLUMNS`
 * (не `fieldExists()` — CI4 кэширует метаданные поля в рамках соединения, второй
 * `up()` в том же запросе увидел бы устаревший «колонки ещё нет» и упал дублем;
 * `SHOW COLUMNS` читает схему напрямую при каждом вызове).
 */
class Adr186AlertRateAndAttackCooldownSettings extends Migration
{
    public function up(): void
    {
        if (! $this->columnExists()) {
            $this->forge->addColumn('pvp_standoffs', [
                'last_alerted_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'after' => 'notified_expired',
                ],
            ]);
        }

        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'pvp.attack_cooldown_sec',
                'value_type'         => 'int',
                'value_int'          => 30,
                'default_value_text' => '30',
                'rationale_text'     => '30 с — байт-идентичный перенос прежнего хардкода `Config\\GameBalance::$pvpAttackCooldownSec` (v0.51.44, Security-telegram §7): мягкий anti-spam guard от двойных кликов и агрессивных скриптов, не полноценный боевой кулдаун. Один ключ читают ДВА потребителя — `AttackPlayerAction` (полевая атака) и `DuelAction` (дуэль) — поднимать его ради частоты тревог защитнику нельзя, замедлится весь PvP, включая дуэли, к тревогам отношения не имеющие; для тревог заведён отдельный `pvp.standoff.min_alert_interval_sec`.',
                'effect_text'        => '`AttackPlayerAction::handle()` и `DuelAction::handle()` — минимальный интервал между двумя тапами «Атаковать»/«Дуэль» одного атакующего (любая цель), читается кэшем `pvp_attack_cd_<attacker_id>`/`pvp_duel_cd_<attacker_id>`.',
                'above_effect_text'  => 'При 120 с игрок с медленным интернетом или неудачным двойным тапом легитимно ждёт 2 минуты между боевыми действиями — ощущается как лаг/баг, а не защита от спама.',
                'below_effect_text'  => 'При 0 с anti-spam guard выключен полностью: скрипт или дребезг клавиатуры бьёт очередь запросов без единой паузы — ровно то, от чего guard был введён (Security-telegram §7).',
                'recommended_min'    => '10',
                'recommended_max'    => '120',
                'hard_min'           => '0',
                'hard_max'           => '600',
            ],
            [
                'setting_key'        => 'pvp.standoff.min_alert_interval_sec',
                'value_type'         => 'int',
                'value_int'          => 60,
                'default_value_text' => '60',
                'rationale_text'     => '60 с — вдвое больше `pvp.attack_cooldown_sec` (30 с), а именно он до этой story был ЕДИНСТВЕННЫМ ограничителем частоты тревог: нападавший, отменяющий своё же окно («🚶 Уйти», статус `cancelled` не армирует `pvp.standoff.cooldown_sec` в 900 с на защитника), переоткрывал его каждые 30 с бессрочно — до 2 тревог в минуту одной жертве. При 60 с максимум падает вдвое, до 1 тревоги в минуту (30/час): первую тревогу защитник получает всегда (он уже знает, что рядом враг, и защита базы работает в полном объёме — окно открывается независимо от этого ключа), а серия последующих отмен-переоткрытий той же секунды перестаёт бомбардировать телефон, оставаясь заметно короче полного окна `pvp.standoff.window_sec` (300 с по умолчанию), чтобы не выглядеть отключённым потолком при честной повторной атаке через минуту.',
                'effect_text'        => '`PvpStandoffService::shouldAlertDefender()` — минимальный интервал между двумя тревогами ОДНОМУ защитнику (любой атакующий), стамп читается из `pvp_standoffs.last_alerted_at` часами БД. Окно всё равно открывается и атака всё равно заморожена при исчерпанном интервале — гасится только уведомление.',
                'above_effect_text'  => 'При 900 с (= `pvp.standoff.cooldown_sec`) защитник, попавший под травлю циклами «атаковать → уйти → атаковать», может не увидеть НИ ОДНОЙ тревоги следующие 15 минут после первой — слишком похоже на молчаливое отключение механики.',
                'below_effect_text'  => 'При 10 с потолок почти не отличим от его отсутствия: нападавший всё ещё успевает прислать 6 тревог в минуту, окно едва успевает истечь на экране защитника, прежде чем придёт следующее.',
                'recommended_min'    => '30',
                'recommended_max'    => '300',
                'hard_min'           => '0',
                'hard_max'           => '3600',
            ],
        ];

        $defaults = [
            'category'     => 'combat',
            'value_int'    => null,
            'value_float'  => null,
            'value_bool'   => null,
            'value_string' => null,
            'updated_by'   => null,
            'created_at'   => $now,
            'updated_at'   => $now,
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
        $keys = [
            'pvp.attack_cooldown_sec',
            'pvp.standoff.min_alert_interval_sec',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();

        if ($this->columnExists()) {
            $this->forge->dropColumn('pvp_standoffs', 'last_alerted_at');
        }
    }

    private function columnExists(): bool
    {
        $row = $this->db->query("SHOW COLUMNS FROM `pvp_standoffs` LIKE 'last_alerted_at'")->getRowArray();

        return is_array($row);
    }
}
