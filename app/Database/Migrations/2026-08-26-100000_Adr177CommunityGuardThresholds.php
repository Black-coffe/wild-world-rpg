<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * story community-chat-bot-63 (ADR-177, поправлено ADR-178) — четыре admin-tunable
 * ключа рубежа 1 `CommunityGuard` (провенанс предложений): порог покрытия,
 * минимальная длина юнита-предложения источника, режим сравнительно-оценочной
 * формы, режим самого провенанса.
 *
 * category=experimental — тот же прецедент, что `Adr176CommunityRateLimitSetting`
 * и `world.webhook.*` (ADR-163): анти-абьюз/качественная ручка ОДНОГО чат-бота,
 * не общий игровой баланс.
 *
 * 🔴 История числа `community.guard.provenance_threshold`, коротко (полная
 * математика — докблок `CommunityGuard::DEFAULT_PROVENANCE_THRESHOLD`). ADR-177
 * предполагал 0.65 по пилотному замеру из трёх фабрикатов. Story 63 расширила
 * выборку до 22 естественных несравнительных фабрикатов, нашла высший ratio 0.805
 * и по инструкции story временно сдвинула порог на 0.80. Ревью тем же прогоном
 * измерило 12-14 ответов В ТОНЕ ВЛАДЕЛЬЦА (не цитаты `/guide`) — законный пересказ
 * дал 0.265, а 0.50 и 0.80 показали ОДИНАКОВЫЙ результат до строки. **ADR-178:
 * классы перекрыты и перевёрнуты, порога между «дословность» и «правдивость» не
 * существует — признак двумодален, а не непрерывен.** Провенанс лишён права вето;
 * порог возвращён к исходным **0.65** и меняет смысл — регулирует шумность
 * пометки для ревьюера на одобрении, а не пропуск/отказ. Обязательство «перемерить
 * и сдвинуть дефолт» с этой миграции снято (ADR-178 §«Конкретно», п.6) — soft-
 * диапазон возвращён к исходному ADR-177 (0.55–0.75).
 *
 * game_settings = KEEP (WipeManifest не трогаем). Идемпотентно по setting_key,
 * все четыре ключа — в одном up()/down(), четыре текстовых поля обязательны у
 * каждого (invariant `GameSettingsService`/CLAUDE.md §ADMIN-TUNABLE BALANCE).
 */
class Adr177CommunityGuardThresholds extends Migration
{
    private const KEY_THRESHOLD   = 'community.guard.provenance_threshold';
    private const KEY_MIN_WORDS   = 'community.guard.min_source_sentence_words';
    private const KEY_COMPARATIVE = 'community.guard.comparative_form';
    private const KEY_MODE        = 'community.guard.provenance_mode';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => self::KEY_THRESHOLD,
                'category'           => 'experimental',
                'value_type'         => 'float',
                'value_bool'         => null,
                'value_int'          => null,
                'value_float'        => 0.65,
                'value_string'       => null,
                'default_value_text' => '0.65',
                'rationale_text'     => 'Единица подтверждения рубежа 1 (ADR-177) — предложение-юнит белого корпуса, не документ целиком. ADR-178: лексическое покрытие детектирует ДОСЛОВНОСТЬ, а не ПРАВДИВОСТЬ — законный пересказ своими словами дал ratio 0.265, лучший несравнительный фабрикат из 22-элементной выборки story 63 дал 0.805; классы перекрыты и перевёрнуты, а 0.50 и 0.80 показали одинаковый результат до строки на живой выборке (признак двумодален: почти дословная опора ≈0.9+ либо её нет ≈0.3). Порога между «дословность» и «правдивость» не существует, поэтому рубеж 1 лишён права вето (advisory-режим) и это число больше не решает allow/deny — оно регулирует, при каком ratio предложение получает пометку для ревьюера. 0.65 — исходное значение ADR-177 без изменений, настраивать заново нечего.',
                'effect_text'        => 'Читается `CommunityGuard::provenanceAdvisories()` — минимальная доля взвешенного покрытия значимых слов предложения ответа ОДНИМ юнитом-предложением белого корпуса (`GuideCatalog` + `game_tips`), ниже которой предложение попадает в `Verdict::$advisories` (адрес лучшего источника + ratio). В режиме `provenance_mode=advisory` (default) пометка НЕ блокирует ответ; в режиме `deny` непустые пометки дают `Verdict::deny(\'no_provenance\')`.',
                'above_effect_text'  => 'Выше 0.65 растёт число пометок на добросовестных пересказах (не дословных цитатах) — в режиме `advisory` это просто больше строк для ревьюера; в режиме `deny` — больше отказов легальным ответам, написанным своими словами.',
                'below_effect_text'  => 'Ниже 0.65 пометок меньше — в режиме `deny` рубеж 1 слабее держит выдуманные утверждения, которые случайно делят редкий стем с посторонним фрагментом корпуса.',
                'recommended_min'    => 0.55,
                'recommended_max'    => 0.75,
                'hard_min'           => 0.30,
                'hard_max'           => 1.00,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => self::KEY_MIN_WORDS,
                'category'           => 'experimental',
                'value_type'         => 'int',
                'value_bool'         => null,
                'value_int'          => 3,
                'value_float'        => null,
                'value_string'       => null,
                'default_value_text' => '3',
                'rationale_text'     => 'ADR-177 §1: юнит короче этого числа значимых слов подтверждением быть не может — короткий обрывок предложения источника («да, конечно» / «в общем») совпадает со слишком многим случайно, а взвешенное покрытие на таком юните не несёт содержательной информации о том, что источник ДЕЙСТВИТЕЛЬНО подтверждает claim.',
                'effect_text'        => 'Читается `CommunityGuard::corpusUnits()` — минимальное число уникальных значимых стемов (≥4 буквы) в предложении-юните корпуса, чтобы юнит вообще участвовал в проверке рубежа 1. Юниты короче отбрасываются целиком, ни один claim подтвердить ими нельзя.',
                'above_effect_text'  => 'Выше 3 корпус теряет короткие, но осмысленные предложения источника (напр. «Верстак общий открывает базовые рецепты сразу.») — легитимные короткие claim\'ы перестают находить подтверждение и получают пометку/отказ (в зависимости от `provenance_mode`).',
                'below_effect_text'  => 'Ниже 3 (например 1-2) в корпус попадают юниты вроде «Смотри выше.» или «Так бывает.» — почти любой короткий claim случайно наберёт высокое покрытие против такого юнита, признак слабеет.',
                'recommended_min'    => 2,
                'recommended_max'    => 8,
                'hard_min'           => 1,
                'hard_max'           => 20,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => self::KEY_COMPARATIVE,
                'category'           => 'experimental',
                'value_type'         => 'string',
                'value_bool'         => null,
                'value_int'          => null,
                'value_float'        => null,
                'value_string'       => 'deny',
                'default_value_text' => 'deny',
                'rationale_text'     => 'ADR-177 §2, дополнено ADR-178 (поправка №2, R4): сравнительно-оценочная форма (союз сопоставления R1 / корень оценки R2 / рекомендательный оборот R3 / сравнительная степень + условие-действие R4) отклоняется независимо от лексического совпадения с корпусом — главный измеренный разделитель ADR-177 (V1→V2 убирает ложный пропуск с 11-42% до 0% на пилотном замере) и ЕДИНСТВЕННЫЙ рубеж 1, сохранивший право вето после ADR-178 (провенанс его лишился). R4 закрыла зеркальную форму совета («Y происходит чаще, если делать X»), которую R3 не ловил. `off` — аварийный выключатель этой ОДНОЙ проверки без деплоя, если она окажется слишком грубой на живом чате (Revisit-when ADR-177 (г)); рубеж 1 (провенанс-пометка) продолжает работать при `off`.',
                'effect_text'        => 'Читается `CommunityGuard::readComparativeFormMode()`. `deny` — `verdict()` отклоняет ответ с причиной `comparative_claim` до проверки провенанса, если сработало любое из правил R1/R2/R3/R4 (`CommunityGuard::isComparativeClaim()`). `off` — проверка пропускается целиком, ответ проверяется только провенансом (пометкой).',
                'above_effect_text'  => 'Значений выше `deny` не существует — единственное действие, отличное от `deny`, это `off` (см. below).',
                'below_effect_text'  => 'При `off` бот снова может подтвердить сравнение двух подсистем («Х выгоднее Y», «Х быстрее, чем Y»), если лексика совпадёт с корпусом — обещание, которое следующий ребаланс сделает ложью, а игрок сохранит скриншотом (ADR-177 §2, §6 плана community-chat-bot).',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => self::KEY_MODE,
                'category'           => 'experimental',
                'value_type'         => 'string',
                'value_bool'         => null,
                'value_int'          => null,
                'value_float'        => null,
                'value_string'       => 'advisory',
                'default_value_text' => 'advisory',
                'rationale_text'     => 'ADR-178: провенанс (рубеж 1) измерен как признак, НЕ разделяющий классы — законный пересказ своими словами дал ratio 0.265, лучший несравнительный фабрикат 0.805, а 0.50 и 0.80 давали одинаковый результат до строки (двумодальное распределение: почти дословная опора либо есть, либо нет, промежуточного порога не существует). Лексическое покрытие детектирует дословность, не правдивость, а через `/admin/community` (`audit()`, ADR-176) проходит ВЕСЬ исходящий текст без исключения — гвард, отменяющий решение владельца студии о том, что говорит его студия, стоит в неверном отношении к власти. `advisory` (default) превращает рубеж 1 в обязательную пометку для ревьюера вместо запрета; `deny` — откат к старому вето ADR-177 на случай неряшливых черновиков `community:import`; `off` отключает пометки целиком.',
                'effect_text'        => 'Читается `CommunityGuard::readProvenanceMode()`. `advisory` — `verdict()` всегда возвращает `allow($advisories)`, если ни один другой рубеж не сработал; пометки уходят во второй шаг одобрения (story 68). `deny` — непустые пометки дают `Verdict::deny(\'no_provenance\')` вместо `allow`. `off` — рубеж 1 не считается вовсе, пометок нет. На авто-отправке (`CommunityAutoReplyHandler`, story 57) провенанс не считается ни в одном режиме — только на одобрении.',
                'above_effect_text'  => 'Значений выше `advisory` в порядке строгости нет — `deny` строже, `off` мягче; это enum, не число (см. below/above как «строже»/«мягче»).',
                'below_effect_text'  => 'При `deny` рубеж 1 снова получает право вето и денит одобренные ответы, которые владелец написал своими словами (измеренная ложная denial-ставка — большинство реалистичных перефразов, см. `## Findings` story 63) — бот перестаёт отвечать почти на всё, кроме дословных цитат `/guide`.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();

            if (empty($exists)) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [self::KEY_THRESHOLD, self::KEY_MIN_WORDS, self::KEY_COMPARATIVE, self::KEY_MODE])
            ->delete();
    }
}
