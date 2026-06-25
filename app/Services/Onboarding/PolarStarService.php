<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\Database;

/**
 * S4 (ROADMAP-RETENTION-10) — «Полярная звезда»: одна видимая закреплённая цель новичка.
 *
 * Проблема (S1-диагностика): живая онбординг-цепочка (OnbStep*, ADR-103 Слой 2) ведёт
 * новичка по шагам, но он её НЕ видит постоянно — «Активные квесты» это плоский список,
 * а just-in-time подсказка после шага исчезает. Доминирующая утечка ретеншна — bounce
 * («что делать дальше?»). S4 делает текущий шаг цепочки ВСЕГДА видимым на ключевых
 * экранах новичка (карточка Перса, Карта) одной строкой «🎯 Сейчас: <цель> (X/Y)».
 *
 * Источник цели — {@see OnboardingChainCatalog} (поле `goal`, единый источник истины →
 * нет дрейфа). Прогресс (X/Y) НЕ хранится в quest_steps (только is_completed) → считаем
 * на лету теми же запросами, что и {@see \App\TaskHandlers\Quests\QuestObjectiveHandler::objectiveMet}.
 *
 * Гейт: собственный killswitch `onboarding.cold_open_v2.enabled` (S4, default OFF →
 * dormant = строка не выводится = byte-identical). ОТДЕЛЬНЫЙ от `onboarding.quest_chain.enabled`:
 * цепочка может катиться на проде, а полярная звезда показывается только после активации
 * S4 (Tier-3 cold-smoke перед экспозицией). read-only — НИКАКИХ мутаций состояния.
 *
 * Самодостаточна в тексте (media-off, ADR-020). DB-доступ — через overridable seam'ы
 * (тесты подменяют → проверка рендера без БД на CI, memory feedback_taskhandler_telegram_init_in_tests).
 */
class PolarStarService
{
    use GameSettingsReaderTrait;

    /**
     * Объектив-типы с осмысленным числовым прогрессом «X/Y» (накопительный счётчик).
     * Остальные онбординг-типы — one-shot («сделай это действие»), дробь не показываем.
     */
    private const COUNTABLE = ['explore_cells', 'collect_resource', 'level_milestone', 'char_level', 'npc_kills'];

    /** Killswitch S4: показывать полярную звезду. Default false → dormant. */
    public function enabled(): bool
    {
        return $this->gsBool('onboarding.cold_open_v2.enabled', false);
    }

    /**
     * Готовая строка «полярной звезды» для текущего шага цепочки, или null —
     * если killswitch OFF, активного онбординг-шага нет (ветеран/цепочка пройдена/не назначена)
     * или шаг неизвестен каталогу. Caller добавляет строку к caption/тексту своего экрана.
     */
    public function line(int $charId): ?string
    {
        if (! $this->enabled() || $charId <= 0) {
            return null;
        }

        $titleEn = $this->activeStepTitle($charId);
        if ($titleEn === null) {
            return null;
        }

        $step = OnboardingChainCatalog::find($titleEn);
        if ($step === null) {
            return null;
        }

        $line = '🎯 *Сейчас:* ' . $step['goal'];

        if (in_array($step['objective_type'], self::COUNTABLE, true)) {
            $target  = max(1, $step['objective_qty']);
            $current = max(0, min($target, $this->progressCount($step['objective_type'], $step['objective_target'], $charId)));
            $line .= " ({$current}/{$target})";
        }

        return $line;
    }

    // ── Overridable seam'ы (тесты подменяют — без БД на CI) ──────────────────

    /**
     * title_en активного (незавершённого) шага онбординг-цепочки персонажа, в порядке
     * каталога (защита от теоретических дублей — цепочка линейна, активен один). null —
     * если незавершённых OnbStep-шагов нет.
     */
    protected function activeStepTitle(int $charId): ?string
    {
        $db   = Database::connect();
        $rows = $db->table('quest_steps qs')
            ->select('q.title_en')
            ->join('quests q', 'q.id = qs.quest_id')
            ->where('qs.character_id', $charId)
            ->where('qs.is_completed', 0)
            ->where('q.status', 'active')
            ->like('q.title_en', OnboardingChainCatalog::PREFIX, 'after')
            ->get();
        if ($rows === false) {
            return null;
        }

        $active = [];
        foreach ($rows->getResultArray() as $row) {
            if (is_string($row['title_en'] ?? null) && $row['title_en'] !== '') {
                $active[$row['title_en']] = true;
            }
        }
        if ($active === []) {
            return null;
        }

        // Возвращаем самый ранний по порядку каталога.
        foreach (OnboardingChainCatalog::steps() as $step) {
            if (isset($active[$step['title_en']])) {
                return $step['title_en'];
            }
        }

        return null;
    }

    /**
     * Текущий накопительный прогресс по объектив-типу (для COUNTABLE-типов). Те же
     * запросы, что в QuestObjectiveHandler::objectiveMet — но возвращаем счётчик, не bool.
     */
    protected function progressCount(string $type, ?string $target, int $charId): int
    {
        $db = Database::connect();

        switch ($type) {
            case 'explore_cells':
                return (int) $db->table('explored_cells')->where('character_id', $charId)->countAllResults();

            case 'level_milestone':
            case 'char_level':
                return $this->characterIntColumn($db, $charId, 'level');

            case 'npc_kills':
                return $this->characterIntColumn($db, $charId, 'npc_kills');

            case 'collect_resource':
                if (! is_string($target) || $target === '') {
                    return 0;
                }
                $refRes = $db->table('resources')->select('id')->where('name_en', $target)->get();
                $refRow = $refRes === false ? null : $refRes->getRowArray();
                if (! is_array($refRow) || ! is_numeric($refRow['id'] ?? null)) {
                    return 0;
                }
                $sumRes = $db->table('character_resources')
                    ->selectSum('quantity', 'total')
                    ->where('id_characters', $charId)
                    ->where('id_resources', (int) $refRow['id'])
                    ->get();
                $sumRow = $sumRes === false ? null : $sumRes->getRowArray();
                return is_array($sumRow) && is_numeric($sumRow['total'] ?? null) ? (int) $sumRow['total'] : 0;

            default:
                return 0;
        }
    }

    /**
     * Целочисленная колонка из characters (level / npc_kills). 0 если не найдено.
     *
     * @param \CodeIgniter\Database\BaseConnection<object, object> $db
     */
    private function characterIntColumn(\CodeIgniter\Database\BaseConnection $db, int $charId, string $column): int
    {
        $res = $db->table('characters')->select($column)->where('id', $charId)->get();
        $row = $res === false ? null : $res->getRowArray();

        return is_array($row) && is_numeric($row[$column] ?? null) ? (int) $row[$column] : 0;
    }
}
