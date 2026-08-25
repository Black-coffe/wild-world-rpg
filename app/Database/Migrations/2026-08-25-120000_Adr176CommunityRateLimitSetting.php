<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * story community-chat-bot-25 (ADR-176) — собственный admin-tunable лимит для
 * группового rate-limit-ведра (`tg_rate_group_{chatId}`).
 *
 * До этой миграции групповое ведро делило числовой лимит с персональным окном
 * игрока (`TelegramRateLimitFilter::DEFAULT_MAX_PER_MINUTE` = 60/мин, см. Contract
 * story). Чат на пару сотен человек в прайм-тайм превышает 60/мин легко: лишние
 * апдейты глотаются фильтром с `200 OK` до контроллера, и живой ответ человека
 * человеку теряется — метрика «человек ответил человеку» занижена в пользу бота.
 *
 * category=experimental: это анти-абьюз/ёмкостный параметр для ОДНОГО конкретного
 * community-чата (см. ADR — spec `community-chat-bot`), не общий игровой баланс —
 * такие тонкие эксплуатационные ручки живут в experimental (см. соседние ADR-163
 * `world.webhook.rate_limit_per_minute`).
 *
 * Стартовое значение 600/мин рассчитано на чат, а не на игрока: десятикратный
 * запас над персональным 60/мин, тот же порядок расчёта, что и у персонального
 * лимита в `TelegramRateLimitFilter` (комментарий у DEFAULT_MAX_PER_MINUTE) —
 * трёхкратный запас над замеренным пиком живой игры. Для чата пик пропорционален
 * числу активных участников; 600/мин держит несколько сотен человек в прайм-тайм,
 * не открывая ведро под настоящий флуд-бот (счёт всё ещё идёт по чату целиком,
 * не по каждому участнику отдельно).
 *
 * game_settings = KEEP (WipeManifest не трогаем). Идемпотентно по setting_key.
 */
class Adr176CommunityRateLimitSetting extends Migration
{
    private const KEY = 'experimental.community_chat.rate_limit_per_minute';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => self::KEY,
            'category'           => 'experimental',
            'value_type'         => 'int',
            'value_bool'         => null,
            'value_int'          => 600,
            'value_float'        => null,
            'value_string'       => null,
            'default_value_text' => '600',
            'rationale_text'     => 'Групповое rate-limit-ведро (tg_rate_group_{chatId}) до этой настройки делило числовой лимит с персональным окном одного игрока (60/мин). Чат на пару сотен человек в прайм-тайм превышает такой лимит на первых же секундах общения, а лишние апдейты тихо глотаются фильтром до контроллера. Стартовое значение 600/мин — тот же трёхкратный запас над реалистичным пиком живого чата, что и у персонального лимита над пиком живого игрока, только применённый к масштабу целого чата, а не одного человека.',
            'effect_text'        => 'Читается `TelegramRateLimitFilter` как потолок группового ведра `tg_rate_group_{chatId}` — отдельно от персонального лимита игрока (тот не меняется ни ключом, ни величиной, ни этим параметром). Превышение — те же 200 OK без ответа боту в чат (Non-goals story: бот в общих чатах молчит), только окно закрывается позже.',
            'above_effect_text'  => 'Выше 600/мин ведро почти никогда не закрывается — при реальном флуд-боте в общем чате апдейты продолжат доходить до игрового диспетчера дольше, чем задумано анти-абьюз-защитой.',
            'below_effect_text'  => 'Ниже 600/мин обычное оживлённое общение в чате начинает упираться в лимит раньше настоящего флуда: ответы игроков друг другу и модерация скама снова теряются в живом трафике, как до этой story.',
            'recommended_min'    => 60,
            'recommended_max'    => 6000,
            'hard_min'           => 60,
            'hard_max'           => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $exists = $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->get()
            ->getRowArray();

        if (empty($exists)) {
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->delete();
    }
}
