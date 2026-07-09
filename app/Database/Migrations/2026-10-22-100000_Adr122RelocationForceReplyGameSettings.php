<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-122 (UX-хвост) — «Полноценный переезд» спрашивает координаты кнопкой, а не командой.
 *
 * 🔴 Что чиним. Кнопка «🚚 Полноценный переезд» показывала инструкцию: «введи в чат
 * `/base_shifting X=123Y=543`». Это было единственное место в игре, где от игрока требовалось
 * запомнить и без ошибок набрать синтаксис команды. Прод (firehose, 10 дней): 7 тапов по кнопке →
 * 6 набранных команд → 4 завершённых переезда. Путь работал, но терял людей на ровном месте.
 *
 * true — кнопка сразу присылает forceReply-промпт «пришли две координаты, например 357 391»;
 * ответ разбирается свободно («357 391», «357,391», «X=357Y=391»). Команда `/base_shifting`
 * остаётся рабочей: оба входа идут через один `RelocationRequestService` — второй копии
 * валидаций (кулдаун / диапазон / занятость / изученность) не существует.
 *
 * false (default) — DORMANT: историческая инструкция про команду, byte-identical.
 *
 * Не балансовый параметр (UX-поведение), но killswitch нужен: смена флоу затрагивает живых
 * игроков и должна откатываться без редеплоя. game_settings = KEEP. Idempotent по setting_key.
 */
class Adr122RelocationForceReplyGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'buildings.relocation.force_reply',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'default_value_text' => 'false',
            'category'           => 'buildings',
            'rationale_text'     => 'ADR-122 UX-хвост: кнопка «Полноценный переезд» просила игрока набрать команду /base_shifting X=123Y=543 — единственное место в игре, требующее запомнить синтаксис. Прод за 10 дней: 7 тапов по кнопке, 6 набранных команд, 4 завершённых переезда. true — кнопка сразу спрашивает координаты через forceReply, ответ разбирается свободно. false (default) — DORMANT, историческая инструкция, byte-identical. Активация после Tier-3.',
            'effect_text'        => 'Меняет ТОЛЬКО способ ввода координат. Кнопка «🚚 Полноценный переезд» вместо инструкции присылает forceReply-промпт с маркером «🚚 ПЕРЕЕЗД»; ответ игрока ловит GenericmessageCommand и передаёт в общий пайплайн RelocationRequestService::handleCoords. Команда /base_shifting продолжает работать и идёт тем же пайплайном. Валидации (диапазон 1..1000, кулдаун 10 дней, занятость и изученность клетки), подтверждение и 24-часовая задача не меняются.',
            'above_effect_text'  => 'true — переезд начинается в два тапа и одно сообщение; игрок больше не встречает синтаксис команды. Риск: forceReply в Telegram открывает поле ввода с цитатой — если игрок ответит не на тот промпт, координаты не будут распознаны, и он получит понятное «пришли два числа».',
            'below_effect_text'  => 'false — текущее поведение: кнопка объясняет, как набрать /base_shifting X=123Y=543. Часть игроков отваливается на наборе команды (по firehose — примерно каждый третий из дошедших до кнопки).',
            'value_int'          => null,
            'value_float'        => null,
            'value_string'       => null,
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
        if (empty($exists)) {
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'buildings.relocation.force_reply')->delete();
    }
}
