<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S8 (ROADMAP-RETENTION-10, ADR-146) — гейт реферальной петли «позови выжившего».
 *
 *   - enabled (killswitch, default OFF → dormant): deep-link ref_<id> НЕ разбирается, ребро НЕ
 *     пишется, кнопка «👥 Позови выжившего» и экран скрыты, cron — no-op. /start byte-identical.
 *   - qualify_level (default 2): на каком уровне приглашённого реферрер получает титул «Зовущий».
 *     🔴 Выбран по прод-данным: пост-S3-когорта почти не доходит до L3 (анти-фарм-гейт L3 = выплаты
 *     ~0), L2 = первый сигнал «пробил стену» (всё ещё фильтрует ботов), награда non-power → мягкий
 *     гейт безопасен по П9. Поднять до 3 — когда S10 покажет здоровый L2-rate.
 *   - max_per_referrer (default 50): cap наград на одного реферрера (анти-фарм; награда non-power,
 *     потому cap щедрый, но конечный).
 *
 * Категория world. Idempotent. game_settings = KEEP (WipeManifest не трогаем).
 */
class S8ReferralGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'referral.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch S8 реферальной петли «позови выжившего». false (default) — DORMANT: deep-link ref_<id> в /start НЕ разбирается как реферал (падает в обычную acquisition-атрибуцию как сегодня), ребро в referrals НЕ пишется, кнопка «👥 Позови выжившего» и экран не показываются, ReferralQualifyCron — no-op. /start и карточка Перса byte-identical. true — Telegram-нативный двигатель роста+ретеншна: игрок зовёт друга ссылкой, при достижении другом qualify-уровня реферрер получает non-power титул «Зовущий». Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'ReferralService::enabled гейтит: парсинг ref_<id> в StartCommand, запись ребра, кнопку в CharacterService, экран ReferralAction, выдачу в ReferralQualifyCron.',
                'above_effect_text'  => 'true — приток через рефералов (рефералы держатся лучше органики) + у игрока появляется социальная причина возвращаться и звать; награда честная и не P2W.',
                'below_effect_text'  => 'false — реферальной петли нет (текущее поведение); интейк только органика/SEO/маркетинг-каналы.',
            ],
            [
                'setting_key'        => 'referral.qualify_level',
                'value_type'         => 'int',
                'value_int'          => 2,
                'default_value_text' => '2',
                'recommended_min'    => 2,
                'recommended_max'    => 5,
                'hard_min'           => 2,
                'hard_max'           => 25,
                'rationale_text'     => 'Уровень приглашённого, при достижении которого реферреру выдаётся титул «Зовущий» (анти-бот-фарм: платим за РЕАЛЬНЫЙ прогресс, а не за регистрацию). default 2 (не 3): прод-данные на момент S8 — пост-S3-когорта почти не доходит до L3 (0 из 7 за 3 дня; ~12% всех когда-либо), при L3 петля как НАГРАДА мертва. L2 = первый сигнал «пробил стену L1» (всё ещё ~13% проходят L1 → фильтр от drive-by ботов), а награда non-power → более мягкий гейт безопасен по П9.',
                'effect_text'        => 'ReferralService::qualifyLevel — порог characters.level приглашённого для qualify+выдачи титула реферреру.',
                'above_effect_text'  => 'Выше (напр. 3) — сильнее анти-бот, но выплат живым друзьям ещё меньше (петля как мотиватор слабее), пока эффект S3 не вырастет.',
                'below_effect_text'  => 'Ниже hard_min=2 запрещено: L1 = регистрация без игры → выплата за фактический сигнап = бот-фарм (🔴 П9).',
            ],
            [
                'setting_key'        => 'referral.max_per_referrer',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'recommended_min'    => 5,
                'recommended_max'    => 200,
                'hard_min'           => 1,
                'hard_max'           => 100000,
                'rationale_text'     => 'Максимум засчитанных приглашённых на одного реферрера (анти-фарм-cap). 50 — щедро для живого человека (реально мало кто приведёт столько настоящих игроков), но конечно: при компрометации не даёт неограниченно копить. Награда non-power (титул, не сила/золото) → высокий cap безопасен.',
                'effect_text'        => 'ReferralService::maxPerReferrer — потолок строк referrals на referrer_user_id (сверх — новые ребра не пишутся).',
                'above_effect_text'  => 'Выше — почти безлимит (для конкурсов/ивентов роста); риск только косметический (титул один раз, счётчик растёт).',
                'below_effect_text'  => 'Ниже — жёстче анти-фарм, но честный «амбассадор» упрётся в потолок и перестанет приглашать.',
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
            'referral.enabled',
            'referral.qualify_level',
            'referral.max_per_referrer',
        ])->delete();
    }
}
