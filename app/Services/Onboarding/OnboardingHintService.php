<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Entities\CharacterEntity;
use App\Models\ActionLogModel;
use App\Models\ClaimedCellModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Longman\TelegramBot\Request;

/**
 * ADR-103 Часть B Слой 1 — контекстные one-shot обучающие подсказки (just-in-time).
 *
 * Показывает подсказку РОВНО ОДИН РАЗ на персонажа. Трекинг — через `action_log`
 * (тот же паттерн, что у шагов обучения Robi: проверка существующей записи по
 * `character_id` + `action_name`), поэтому НОВОЙ таблицы/миграции схемы не требуется.
 *
 * Уважает: killswitch `onboarding.contextual_hints.enabled` (GameSettings) и
 * per-char opt-out — тот же тумблер, что у «Совета дня» (`characters.daily_tips_enabled`):
 * если игрок отключил советы, контекстные подсказки тоже молчат.
 *
 * Каталог текстов — {@see OnboardingHintCatalog} (расширяется с каждой механикой,
 * конституционное правило ONBOARDING-COVERAGE).
 */
class OnboardingHintService
{
    use GameSettingsReaderTrait;

    private const LOG_PREFIX = 'OnbHint_';

    public function __construct(private readonly ?ActionLogModel $log = null)
    {
    }

    /**
     * Слой 1 — подсказка «первая база» новичку без базы.
     * Гейты: killswitch + opt-out + не показано + level ≤ max + база ещё не разбита.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSendFirstBaseTip(array|CharacterEntity $character, int $chatId): bool
    {
        // Уровневый гейт ПЕРВЫМ (без DB) — ветераны отсекаются на каждом move дёшево,
        // per-character запросы (alreadyShown/hasActiveBase) делаем только для новичков.
        $level = self::intField($character, 'level');
        if ($level > $this->gsInt('onboarding.contextual_hints.max_level', 6)) {
            return false;
        }

        $charId = self::intField($character, 'id');
        if ($charId <= 0 || $this->alreadyShown($charId, OnboardingHintCatalog::FIRST_BASE)) {
            return false;
        }

        if ($this->hasActiveBase($charId)) {
            return false;
        }

        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::FIRST_BASE);
    }

    /**
     * Первая постройка (2026-06-20) — хинт «построй первую постройку» при открытии
     * экрана базы новичком, у которого база ЕСТЬ, но НИ ОДНОЙ постройки.
     * Гейты: level ≤ contextual_hints.max_level + есть активная база + нет ни одной
     * постройки + общие killswitch/opt-out/one-shot. Закрывает горлышко OnbStepBuild
     * (находка пере-среза A+B 2026-06-20: ClaimBase/OpenBase проходят, Build — 9%).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSendFirstBuildHint(array|CharacterEntity $character, int $chatId): bool
    {
        $level = self::intField($character, 'level');
        if ($level > $this->gsInt('onboarding.contextual_hints.max_level', 6)) {
            return false;
        }

        $charId = self::intField($character, 'id');
        if ($charId <= 0 || $this->alreadyShown($charId, OnboardingHintCatalog::FIRST_BUILD)) {
            return false;
        }

        // База должна БЫТЬ (иначе это сценарий FIRST_BASE), но ещё НИ ОДНОЙ постройки.
        if (! $this->hasActiveBase($charId) || $this->hasAnyBuilding($charId)) {
            return false;
        }

        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::FIRST_BUILD);
    }

    /**
     * E8 (ADR-109) Ф2 — интро-подсказка про ежедневные задания (just-in-time).
     * Гейты: level ≤ max (только новички — ветераны находят фичу кнопкой «🗓 Задания дня»
     * на карточке Перса, без масс-пинга всей популяции) + killswitch + opt-out + не показано.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSendDailyTasksTip(array|CharacterEntity $character, int $chatId): bool
    {
        $level = self::intField($character, 'level');
        if ($level > $this->gsInt('onboarding.contextual_hints.max_level', 6)) {
            return false;
        }

        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::DAILY_TASKS);
    }

    /**
     * E20 (ADR-120) — хинт «Автоматизация» при открытии экрана базы.
     * Гейты: окно уровня [automation_hint.min_level .. max_level] (ветеранов не пингуем,
     * анти soft-broadcast — они находят всегда видимую кнопку «🤖 Ангар» сами) +
     * Мастерская робототехники ещё НЕ построена + общие killswitch/opt-out/one-shot.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSendAutomationHint(array|CharacterEntity $character, int $chatId): bool
    {
        $level = self::intField($character, 'level');
        if ($level < $this->gsInt('onboarding.automation_hint.min_level', 12)
            || $level > $this->gsInt('onboarding.automation_hint.max_level', 25)
        ) {
            return false;
        }

        $charId = self::intField($character, 'id');
        if ($charId <= 0 || $this->alreadyShown($charId, OnboardingHintCatalog::AUTOMATION)) {
            return false;
        }

        if ($this->ownsRoboticsWorkshop($charId)) {
            return false;
        }

        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::AUTOMATION);
    }

    /**
     * Теплица (2026-06-18) — хинт «выращивай свою еду» при открытии экрана базы.
     * Гейты: новичок (level ≤ contextual_hints.max_level — ветеранов не пингуем,
     * они находят Теплицу в «🏗 Строить» сами) + Теплица ещё НЕ построена + общие
     * killswitch/opt-out/one-shot. Часть правила ONBOARDING-COVERAGE.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSendGreenhouseTip(array|CharacterEntity $character, int $chatId): bool
    {
        $level = self::intField($character, 'level');
        if ($level > $this->gsInt('onboarding.contextual_hints.max_level', 6)) {
            return false;
        }

        $charId = self::intField($character, 'id');
        if ($charId <= 0 || $this->alreadyShown($charId, OnboardingHintCatalog::GREENHOUSE)) {
            return false;
        }

        if ($this->ownsGreenhouse($charId)) {
            return false;
        }

        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::GREENHOUSE);
    }

    /**
     * Базовый one-shot отправитель: killswitch + opt-out + дедуп + отправка + запись.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSend(array|CharacterEntity $character, int $chatId, string $hintKey): bool
    {
        if (! $this->gsBool('onboarding.contextual_hints.enabled', true)) {
            return false;
        }
        if (! self::optedIn($character)) {
            return false;
        }
        $charId = self::intField($character, 'id');
        if ($charId <= 0) {
            return false;
        }

        $hint = OnboardingHintCatalog::get($hintKey);
        if ($hint === null || $this->alreadyShown($charId, $hintKey)) {
            return false;
        }

        $payload = ['chat_id' => $chatId, 'text' => $hint['text'], 'parse_mode' => 'Markdown'];
        if (isset($hint['reply_markup'])) {
            $payload['reply_markup'] = $hint['reply_markup'];
        }
        $this->send($payload);

        $this->recordShown($charId, $chatId, $hintKey);

        return true;
    }

    /**
     * Overridable seam отправки (тесты подменяют, чтобы не дёргать Telegram на CI —
     * memory feedback_taskhandler_telegram_init_in_tests).
     *
     * @param array<string, mixed> $payload
     */
    protected function send(array $payload): void
    {
        Request::sendMessage($payload);
    }

    public function alreadyShown(int $charId, string $hintKey): bool
    {
        return $this->logModel()
            ->where('character_id', $charId)
            ->where('action_name', self::LOG_PREFIX . $hintKey)
            ->countAllResults() > 0;
    }

    protected function recordShown(int $charId, int $chatId, string $hintKey): void
    {
        $this->logModel()->insert([
            'character_id'  => $charId,
            'chat_id'       => $chatId,
            'action_name'   => self::LOG_PREFIX . $hintKey,
            // action_status строго из enum('Pending','Completed','Skipped','REJECTED') —
            // 'shown' было вне enum → STRICT_TRANS_TABLES коэрсил в '' (37 строк на проде),
            // а в полном strict-режиме валил бы INSERT → подсказка спамила бы (фикс ADR-104).
            'action_status' => 'Completed',
            'description'   => 'ADR-103 contextual onboarding hint',
        ]);
    }

    protected function hasActiveBase(int $charId): bool
    {
        return (new ClaimedCellModel())
            ->where('character_id', $charId)
            ->where('status', 'active')
            ->countAllResults() > 0;
    }

    /**
     * E20: есть ли у персонажа Мастерская робототехники (любого уровня, любая база).
     * Overridable seam для тестов (без DB).
     */
    protected function ownsRoboticsWorkshop(int $charId): bool
    {
        return $this->ownsBuilding($charId, 'RoboticsWorkshop');
    }

    /**
     * Есть ли у персонажа Теплица (любого уровня, любая база). Overridable seam.
     */
    protected function ownsGreenhouse(int $charId): bool
    {
        return $this->ownsBuilding($charId, 'Greenhouse');
    }

    /**
     * Построена ли у персонажа ХОТЬ ОДНА постройка (любого типа, любая база).
     * Overridable seam для тестов (без DB).
     */
    protected function hasAnyBuilding(int $charId): bool
    {
        return (new \App\Models\CharacterBuildingModel())
            ->where('character_id', $charId)
            ->countAllResults() > 0;
    }

    /**
     * Построено ли у персонажа здание с данным name_en (любой уровень, любая база).
     */
    private function ownsBuilding(int $charId, string $nameEn): bool
    {
        $building = (new \App\Models\BuildingModel())->where('name_en', $nameEn)->first();
        $idRaw    = is_array($building) ? ($building['id'] ?? null) : null;
        if (! is_numeric($idRaw) || (int) $idRaw <= 0) {
            return false;
        }

        return (new \App\Models\CharacterBuildingModel())
            ->where('character_id', $charId)
            ->where('building_id', (int) $idRaw)
            ->countAllResults() > 0;
    }

    private function logModel(): ActionLogModel
    {
        return $this->log ?? new ActionLogModel();
    }

    /**
     * opt-out = тумблер «Совета дня» (daily_tips_enabled); default — включено.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    private static function optedIn(array|CharacterEntity $character): bool
    {
        $raw = $character['daily_tips_enabled'] ?? 1;

        return is_numeric($raw) ? (int) $raw === 1 : true;
    }

    /**
     * Безопасное чтение целочисленного поля персонажа (mixed → int).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    private static function intField(array|CharacterEntity $character, string $field): int
    {
        $raw = $character[$field] ?? 0;

        return is_numeric($raw) ? (int) $raw : 0;
    }
}
