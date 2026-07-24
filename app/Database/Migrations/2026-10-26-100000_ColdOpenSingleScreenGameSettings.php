<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Слайс «Первые 3 минуты» (замер 2026-07-24) — суб-killswitch «одно окно вместо пяти».
 *
 * Замер когорты Хабра 07–08.07 (45 регистраций, атрибуция `src_habr_article6` и соседи):
 * живы через две недели 3 человека, 42 из 45 остались на L1, единственную кнопку первого
 * экрана нажали 18 (40%), а 18 из 26 ушедших уложились в ≤3 минуты. Слайс 1b
 * (`start_greeting`, сокращение копии со 121 слова) метрику «не назвали героя» не сдвинул:
 * было ~58%, стало 60% → барьер не в ДЛИНЕ текста, а в ФОРМЕ первого контакта — пять
 * сообщений подряд, CTA в последнем, и он требует печатать имя, которое и так подставлено
 * из username.
 *
 * false (default) → DORMANT, byte-identical. game_settings = KEEP (WipeManifest не трогаем).
 * Idempotent по setting_key. Категория world — как все онбординг-ключи.
 */
class ColdOpenSingleScreenGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'onboarding.cold_open_v2.single_screen',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Суб-killswitch слайса «Первые 3 минуты»: первый контакт новичка сводится к ОДНОМУ содержательному сообщению (вступление Роби + паёк + радио-сигнал + встречающий одной простынёй), а первый CTA ведёт в мир (callback `move` — компас ходьбы), не в форму имени. false (default) — DORMANT: пять отдельных сообщений и CTA «Задать имя» (byte-identical). Основание — замер 2026-07-24 по когорте Хабра 07-08.07: 45 пришли, 3 живы через две недели, 40% нажали единственную кнопку welcome, 18 из 26 ушедших уложились в ≤3 минуты; предыдущая попытка (сокращение копии, start_greeting) named-rate не подняла. ОТДЕЛЬНЫЙ флаг от мастер-`enabled` (тот ON с 2026-06-25): копия первого впечатления высокоставочна → свой Tier-3 cold-smoke + одобрение владельцем до экспозиции.',
                'effect_text'        => 'StartCommand, ветка нового персонажа: ON → паёк/сигнал/встречающий встраиваются секциями в welcome (отдельные сообщения не шлются), кнопки экрана — «🧭 Сделать первый шаг» (move) и «✏️ Назвать героя» (setCharacterName). Выдача пайка, размещение приманки и встречающего, спавн и нижнее reply-меню НЕ меняются — меняется только доставка текста и порядок CTA. Если собранный экран не влезает в одно сообщение (>3900 символов) — автоматический откат на раздельную отправку.',
                'above_effect_text'  => 'true — новичок видит одно окно вместо пяти и может начать играть в один тап без печати; ожидаем рост доли дошедших до первого шага по карте (базлайн когорты: 20 из 45) и до первого действия вообще. Риск: имя перестаёт быть воротами → часть игроков останется с ником из username (сегодня так и живут 27 из 45, механика это допускает), а длинная простыня хуже читается на маленьком экране, чем короткие сообщения.',
                'below_effect_text'  => 'false — прежний холодный старт: пять сообщений подряд, первый CTA требует ввести имя вручную. Лор подаётся дробно и полнее, но каждый лишний экран до первого игрового действия стоит части когорты (замер 2026-07-24).',
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
        $this->db->table('game_settings')->whereIn('setting_key', [
            'onboarding.cold_open_v2.single_screen',
        ])->delete();
    }
}
