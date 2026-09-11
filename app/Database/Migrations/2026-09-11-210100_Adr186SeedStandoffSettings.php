<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-186 — шесть `GameSettings` ключей окна противостояния (`pvp.standoff.*`), категория
 * `combat`. Читает их `PvpStandoffService` (story `-06`) — здесь только фундамент баланса.
 *
 * `pvp.standoff.enabled` засеян **false**: окно строится с этой волны, но включается
 * отдельным флипом на testbot (Tier-3), а не автоматически с деплоем схемы.
 *
 * `game_settings` = KEEP (WipeManifest не трогаем, таблица уже классифицирована).
 * Идемпотентно по `setting_key`.
 */
class Adr186SeedStandoffSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'pvp.standoff.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Окно противостояния — новая живая механика над базами игроков: атакующий больше не бьёт мгновенно, а даёт защитнику `window_sec` на реакцию. false на старте — схема и seed баланса едут этой волной, но включение резервируется под отдельный Tier-3 смоук на testbot двумя аккаунтами (тревога защитнику, экран ожидания, пинг об истечении), прежде чем механика тронет живых игроков.',
                'effect_text'        => '`PvpStandoffService::isEnabled()` (story `-06`) — при false атака у базы идёт прежним прямым путём, окно не открывается, кулдаун и остальные пять ключей не читаются.',
                'above_effect_text'  => 'Булев ключ — «above» не применимо: включение это true.',
                'below_effect_text'  => 'Булев ключ — «below» не применимо: выключение это false, прежнее поведение без окна.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'pvp.standoff.window_sec',
                'value_type'         => 'int',
                'value_int'          => 300,
                'default_value_text' => '300',
                'rationale_text'     => '300 с (5 мин) — ровно требование владельца («ровно пять минут, потом атакующий может атаковать»): достаточно, чтобы защитник заметил тревогу в Telegram и принял решение (укрыться/бежать/ударить первым), но не настолько долго, чтобы атакующий терял интерес и уходил без боя.',
                'effect_text'        => '`PvpStandoffService::open()` ставит `expires_at = NOW() + window_sec` при открытии окна; `secondsLeft()` считает остаток для экрана ожидания атакующего и тревоги защитнику.',
                'above_effect_text'  => 'При 600 с (10 мин) окно тянется вдвое дольше договорённости с владельцем — атакующий чаще уходит со скуки до истечения, механика теряет темп «нападение случилось, что-то решать надо сейчас».',
                'below_effect_text'  => 'При 120 с (2 мин) у защитника может не хватить времени заметить сообщение в Telegram (не все онлайн постоянно) и среагировать — окно вырождается в формальность, которая почти всегда истекает без реакции.',
                'recommended_min'    => '120',
                'recommended_max'    => '600',
                'hard_min'           => '30',
                'hard_max'           => '1800',
            ],
            [
                'setting_key'        => 'pvp.standoff.cooldown_sec',
                'value_type'         => 'int',
                'value_int'          => 900,
                'default_value_text' => '900',
                'rationale_text'     => '900 с (15 мин) на защитника — не даёт открывать окно повторно на того же защитника сразу после закрытия предыдущего: без кулдауна серия атакующих (или один по кругу) держала бы защитника в бесконечной цепочке тревог.',
                'effect_text'        => '`PvpStandoffService::shouldOpen()` проверяет, что с момента закрытия последнего окна этого защитника прошло не меньше `cooldown_sec`, прежде чем разрешить открыть новое.',
                'above_effect_text'  => 'При 3600 с (1 ч) защитник, один раз отбившийся, на час становится неуязвим к новому окну — де-факто неприкосновенность, которую может использовать сам защитник, чтобы спровоцировать окно и получить час «страховки».',
                'below_effect_text'  => 'При 300 с (5 мин, равно самому окну) кулдаун почти не отличим от его отсутствия — тот же атакующий (или сообщник) может держать защитника в почти непрерывной серии окон.',
                'recommended_min'    => '300',
                'recommended_max'    => '3600',
                'hard_min'           => '0',
                'hard_max'           => '86400',
            ],
            [
                'setting_key'        => 'pvp.standoff.require_tower',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'false — окно открывается по самому факту непустого набора построек защитника (`DefenseStructureService::hasActiveStructuresOnCell()`), Дозорная вышка не обязательна. Требование владельца говорит про базу вообще, а не про конкретную постройку раннего решения не ограничивать окно наличием одной конкретной постройки.',
                'effect_text'        => '`PvpStandoffService::shouldOpen()` — при true дополнительно требует построенную и не разрушенную Дозорную вышку на клетке защитника, иначе окно не открывается вовсе (прямая атака как раньше).',
                'above_effect_text'  => 'Булев ключ — «above» не применимо: true это требование вышки.',
                'below_effect_text'  => 'Булев ключ — «below» не применимо: false это текущий выбор — открытие по любой постройке.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'pvp.standoff.hold_damage_reduction_percent',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => '10% — защитник, выбравший «встретить удар первым» (`held`), получает небольшую скидку по урону как награду за то, что не спрятался и не убежал, а принял бой на своих условиях. Скромная величина — это бонус за выбор тактики, а не полноценный defense-профиль (тот уже считается отдельно через `getDefenseProfile()`).',
                'effect_text'        => 'Читается кодом боя (story `-08`/`-09`) как дополнительный множитель снижения входящего урона по атакующему на первый обмен после закрытия окна статусом `held`.',
                'above_effect_text'  => 'При 40% скидка по урону приближается к полноценному defense-бонусу построек — «встретить удар первым» становится доминирующей тактикой независимо от реального состояния обороны.',
                'below_effect_text'  => 'При 0% выбор «встретить удар первым» ничем не отличается от простого бездействия — у кнопки `standoffHold_` нет игровой причины существовать.',
                'recommended_min'    => '0',
                'recommended_max'    => '20',
                'hard_min'           => '0',
                'hard_max'           => '40',
            ],
            [
                'setting_key'        => 'pvp.standoff.notify_attacker_on_expiry',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'true — атакующий, дождавшийся истечения окна на экране ожидания, получает явный пинг «можно атаковать» вместо того, чтобы сам угадывать момент или жать «проверить» вручную (UX-Discoverability): пять минут ожидания без сигнала о завершении — тупик, а не механика.',
                'effect_text'        => '`StandoffNotifier::notifyAttackerExpired()` (story `-06`) вызывается по истечении окна только если ключ true; при false атакующий узнаёт об истечении только повторным тапом `standoffCheck_`.',
                'above_effect_text'  => 'Булев ключ — «above» не применимо: true это отправка пинга.',
                'below_effect_text'  => 'Булев ключ — «below» не применимо: false — тишина, атакующий проверяет сам.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
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
            'pvp.standoff.enabled',
            'pvp.standoff.window_sec',
            'pvp.standoff.cooldown_sec',
            'pvp.standoff.require_tower',
            'pvp.standoff.hold_damage_reduction_percent',
            'pvp.standoff.notify_attacker_on_expiry',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
