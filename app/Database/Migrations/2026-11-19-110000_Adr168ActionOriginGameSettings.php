<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-168 — killswitch простановки меток источника на callback_data.
 *
 * Почему выключено по умолчанию. Метка меняет строку, которую кнопка шлёт серверу, поэтому
 * OFF = byte-identical прежнему поведению: ни один экран не отличить от доправочного. Включаем
 * отдельным решением после Tier-3, как и все прочие слайсы навигации.
 *
 * Почему выключение НЕ ломает уже отправленные кнопки. Снятие метки в
 * {@see \App\Services\Logging\ActionOrigin::strip()} безусловно и killswitch не читает:
 * сообщения живут в истории чата вечно, и `gather~cmp`, отправленный при ON, обязан
 * продолжать работать после OFF.
 *
 * game_settings = KEEP (WipeManifest не трогаем). Идемпотентно по setting_key.
 */
class Adr168ActionOriginGameSettings extends Migration
{
    private const KEY = 'logging.action_origin.enabled';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => self::KEY,
            'category'           => 'experimental',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'value_int'          => null,
            'value_float'        => null,
            'value_string'       => null,
            'default_value_text' => '0',
            'rationale_text'     => 'Разрешает кнопкам нести метку экрана-источника (`gather~cmp`), которую сервер снимает до обработки и пишет в колонку `origin` firehose. Выключено по умолчанию: метка меняет строку, уходящую от кнопки, поэтому OFF гарантирует поведение, неотличимое от доправочного. Нужна затем, что шесть экранов рисуют кнопку добычи с одинаковым callback_data, и замер 2026-08-12 не смог отделить входы с компаса от входов из меню действий.',
            'effect_text'        => 'ON — экраны, которые рисуют вход в добычу (компас ходьбы, хаб действий, уведомление о голоде, финиш Похода, экран постройки, нехватка сырья в крафте), добавляют к callback_data хвост `~<код>`; сервер снимает его в единой точке вебхука, поэтому хендлеры видят прежнюю строку. Меню длительности добычи наследует метку входа, так что источник виден и на реальном старте задачи, а не только на открытии меню. В firehose появляется заполненная колонка `origin`; `action_name` и `raw_input` не меняются и остаются сравнимыми с историей.',
            'above_effect_text'  => 'Значений выше 1 нет — это переключатель.',
            'below_effect_text'  => 'Выключить (0) — кнопки снова шлют строку без метки, колонка `origin` у новых строк пустая, и вопрос «с какого экрана зашли в добычу» опять остаётся без ответа: замер слайса «второй шаг» снова возможен только когортами. Уже отправленные помеченные кнопки продолжают работать — снятие метки от этого флага не зависит.',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
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
        $this->db->table('game_settings')->where('setting_key', self::KEY)->delete();
    }
}
