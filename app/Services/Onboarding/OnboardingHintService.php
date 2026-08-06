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

    /** ADR-150 — маркер «открыл живой компас ходьбы» (action_log.action_name). */
    private const LIVE_MOVE_MARKER = 'live_move_used';

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
     * Первый шаг (2026-06-20) — хинт «сделай первый шаг» при открытии карты ИЛИ хаба
     * «Действия» (E4 Слайс 2, {@see maybeSendFirstMoveHintById}) новичком,
     * который ещё фактически не двигался (стоит на спавне). Гейты: level ≤
     * contextual_hints.max_level + почти не двигался (`hasBarelyMoved` — мало
     * explored_cells) + общие killswitch/opt-out/one-shot. Закрывает холодный старт
     * OnbStepMove (находка пере-среза A+B 2026-06-20: 38/67 = 57%). Карта — статичное
     * фото без кнопок ходьбы → подсказываем, где компас «🧭 Двигаться».
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSendFirstMoveHint(array|CharacterEntity $character, int $chatId): bool
    {
        $level = self::intField($character, 'level');
        if ($level > $this->gsInt('onboarding.contextual_hints.max_level', 6)) {
            return false;
        }

        $charId = self::intField($character, 'id');
        if ($charId <= 0 || $this->alreadyShown($charId, OnboardingHintCatalog::FIRST_MOVE)) {
            return false;
        }

        // ADR-150 Слайс 1 (фикс мёртвого гейта): показываем тем, кто ещё НИ РАЗУ не
        // открывал живой компас ходьбы (MoveSurfaceService ставит маркер live_move_used).
        // 🔴 Прежний гейт `hasBarelyMoved` (explored_cells ≤ 1) был ложно-false для
        // выпускников обучения — туториал сам заполняет explored_cells (8 соседей +
        // revealAround) → подсказка НИКОГДА не стреляла именно тем, кто прошёл обучение
        // (ровно жалобщик с Пикабу «после обучения непонятно как идти»). Теперь сигнал —
        // «открывал ли настоящий компас», а не «сколько клеток раскрыто».
        if ($this->hasUsedLiveMove($charId)) {
            return false;
        }

        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::FIRST_MOVE);
    }

    /**
     * E4 Слайс 2 (срез 07-07): тот же хинт «сделай первый шаг», но по characterId —
     * для хаба «Действия» (CharacterGoActions). Firehose показал: новички сидят на
     * хабе (добыча/Поход/окопаться) и карту НЕ открывают → карт-триггер хинта у них
     * никогда не стреляет. Все гейты (level-ceiling, one-shot, hasBarelyMoved,
     * killswitch/opt-out) — внутри {@see maybeSendFirstMoveHint}; one-shot маркер
     * общий с карт-триггером → двойного показа не будет.
     */
    public function maybeSendFirstMoveHintById(int $characterId, int $chatId): bool
    {
        if ($characterId <= 0) {
            return false;
        }

        $character = $this->loadCharacter($characterId);
        if ($character === null) {
            return false;
        }

        return $this->maybeSendFirstMoveHint($character, $chatId);
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
     * Первый крафт (2026-06-21) — хинт «скрафти первый предмет» при открытии крафт-хаба
     * новичком, который ещё НИ РАЗУ ничего не скрафтил. Гейты: level ≤
     * contextual_hints.max_level + пустой crafted_items_log (`hasCraftedAnything` —
     * зеркало objective `craft_any`) + общие killswitch/opt-out/one-shot. Закрывает
     * горлышко OnbStepCraft (находка пере-среза A+B 2026-06-20: 12/36 = 33%, −24 игрока —
     * следующая по величине утечка после Build/Move). Крафт-хаб даёт 4 раздела →
     * новичок не знает, с чего начать → ведём в «🔨 Общий крафт» (верстак не нужен).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSendFirstCraftHint(array|CharacterEntity $character, int $chatId): bool
    {
        $level = self::intField($character, 'level');
        if ($level > $this->gsInt('onboarding.contextual_hints.max_level', 6)) {
            return false;
        }

        $charId = self::intField($character, 'id');
        if ($charId <= 0 || $this->alreadyShown($charId, OnboardingHintCatalog::FIRST_CRAFT)) {
            return false;
        }

        // Уже что-то скрафтил — навык освоен, хинт не нужен.
        if ($this->hasCraftedAnything($charId)) {
            return false;
        }

        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::FIRST_CRAFT);
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
     * WB13 (ADR-137 «Узлы») — just-in-time подсказка при ПЕРВОМ обнаружении живого узла-босса
     * на карте (кнопка «☠ Узел»). Объясняет, что такое Узел, как оценить силу ДО боя
     * («☠ Осмотреть») и что раны узла персистентны.
     *
     * БЕЗ level-ceiling (в отличие от newbie-funnel хинтов): узлы встречаются на всех уровнях
     * (юг L1 → север L180), level-гейт сделал бы хинт мёртвым — типичный игрок натыкается на
     * первый узел уже за L6. Естественный лимитер — one-shot dedup + killswitch + opt-out
     * (всё внутри {@see maybeSend}). Узлы спят за `world.nodes.point_mode_enabled` → пока фича
     * dormant, кнопка «☠ Узел» не появляется и хинт не триггерится.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSendFirstBossSightingHint(array|CharacterEntity $character, int $chatId): bool
    {
        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::FIRST_BOSS_SIGHTING);
    }

    /**
     * Slice 2 («ресурс-грамотность») — just-in-time подсказка при ПЕРВОМ открытии
     * «📦 Склад базы». Объясняет, что добыча в рюкзаке (не на складе), склад наполняется
     * только карго-дроном, и где смотреть всё разом («Все мои ресурсы»).
     *
     * БЕЗ level-ceiling (как у обнаружения узла): путаница «склад vs рюкзак» не
     * привязана к уровню — триггер-инцидент был у игрока L209. Лимитер — one-shot dedup
     * + killswitch + opt-out (всё внутри {@see maybeSend}).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function maybeSendFirstStorageHint(array|CharacterEntity $character, int $chatId): bool
    {
        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::FIRST_STORAGE_OPEN);
    }

    /**
     * Крафт брони/оружия без Арсенала (2026-07-02, след жалобы Евгения) — just-in-time
     * подсказка после завершения крафта снаряжения у персонажа БЕЗ Арсенала: объясняет,
     * что вещь не потеряна (лежит в «Перс → ⚔️ Экип», а не в инвентаре ресурсов) и что
     * надеть можно после постройки Арсенала. Гейт — «нет Арсенала» (у кого есть — кнопка
     * «👕 Надеть» и так работает). БЕЗ level-ceiling: броню крафтят задолго до Арсенала
     * (L15). Лимитер — one-shot + killswitch + opt-out (внутри {@see maybeSend}).
     *
     * Принимает characterId (task-handler'ы завершения крафта имеют его, но не полный
     * массив персонажа) — грузим CharacterEntity сами, чтобы уважить per-char opt-out.
     */
    public function maybeSendArmorCraftedNoArsenalHint(int $characterId, int $chatId): bool
    {
        if ($characterId <= 0 || $this->ownsArsenal($characterId)) {
            return false;
        }

        $character = $this->loadCharacter($characterId);
        if ($character === null) {
            return false;
        }

        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::ARMOR_NO_ARSENAL);
    }

    /**
     * Первый Поход (2026-07-05, след вопроса Max Syskov про скорость) — just-in-time
     * подсказка при ПЕРВОМ запуске Похода: объясняет, что темп постоянный (~минута-две
     * на клетку, привязка к игровому циклу) и НЕ зависит от здоровья/выносливости
     * (❤️/💤 = топливо, не двигатель). БЕЗ level-ceiling: путаница про скорость не
     * привязана к уровню (триггер-игрок — не новичок). Лимитер — one-shot + killswitch
     * + opt-out (внутри {@see maybeSend}).
     *
     * Принимает characterId (MarchAction::startMarch имеет его, но не полный массив
     * персонажа) — грузим CharacterEntity сами, чтобы уважить per-char opt-out.
     */
    public function maybeSendFirstMarchHint(int $characterId, int $chatId): bool
    {
        if ($characterId <= 0) {
            return false;
        }

        $character = $this->loadCharacter($characterId);
        if ($character === null) {
            return false;
        }

        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::FIRST_MARCH);
    }

    /**
     * Скрафтил телепорт-маяк (2026-08-06, вопрос постоянного игрока «как пользоваться
     * телепортом, который не ранец?») — just-in-time подсказка сразу после выдачи маяка:
     * чем он отличается от рюкзака, где его ставить и как потом прыгать. Ни один экран
     * крафта этого не объяснял: игрок получал предмет и упирался в «а дальше что».
     * БЕЗ level-ceiling (маяк требует L12+ и Центр телепортации — ceiling убил бы хинт).
     * Лимитер — one-shot + killswitch + opt-out (внутри {@see maybeSend}).
     *
     * Принимает characterId (task-handler завершения крафта имеет его, но не полный
     * массив персонажа) — грузим CharacterEntity сами, чтобы уважить per-char opt-out.
     */
    public function maybeSendBeaconCraftedHint(int $characterId, int $chatId): bool
    {
        if ($characterId <= 0) {
            return false;
        }

        $character = $this->loadCharacter($characterId);
        if ($character === null) {
            return false;
        }

        return $this->maybeSend($character, $chatId, OnboardingHintCatalog::BEACON_CRAFTED);
    }

    /**
     * Загрузка персонажа по id (overridable seam для тестов — без DB).
     *
     * @return array<string, mixed>|CharacterEntity|null
     */
    protected function loadCharacter(int $charId): array|CharacterEntity|null
    {
        $character = (new \App\Models\CharacterModel())->find($charId);

        return $character instanceof CharacterEntity ? $character : null;
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
     * Есть ли у персонажа Арсенал (любого уровня, любая база). Overridable seam.
     */
    protected function ownsArsenal(int $charId): bool
    {
        return $this->ownsBuilding($charId, 'Arsenal');
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
     * «Почти не двигался» — у персонажа ≤ 1 исследованной клетки (стоит на спавне,
     * ни разу не сходил). Overridable seam для тестов (без DB).
     */
    protected function hasBarelyMoved(int $charId): bool
    {
        return (new \App\Models\ExploredCellsModel())
            ->where('character_id', $charId)
            ->countAllResults() <= 1;
    }

    /**
     * ADR-150 — открывал ли персонаж живой компас ходьбы (MoveSurfaceService::show
     * ставит маркер `live_move_used`). Сигнал «знает, где ходьба» — заменил
     * `hasBarelyMoved` (тот считал explored_cells, которые заполняет туториал →
     * ложно-false для выпускников обучения). Overridable seam для тестов (без DB).
     */
    protected function hasUsedLiveMove(int $charId): bool
    {
        return $this->logModel()
            ->where('character_id', $charId)
            ->where('action_name', self::LIVE_MOVE_MARKER)
            ->countAllResults() > 0;
    }

    /**
     * Ставит маркер «открыл живой компас» ровно один раз (идемпотентно). Вызывается из
     * {@see \App\Services\World\MoveSurfaceService::show} при показе поверхности ходьбы
     * (callback move / нижняя «🌍 Мир» / slash /go).
     */
    public function markLiveMoveUsed(int $charId): void
    {
        if ($charId <= 0 || $this->hasUsedLiveMove($charId)) {
            return;
        }
        $this->logModel()->insert([
            'character_id'  => $charId,
            'chat_id'       => 0,
            'action_name'   => self::LIVE_MOVE_MARKER,
            'action_status' => 'Completed',
            'description'   => 'ADR-150 live move surface opened',
        ]);
    }

    /**
     * Скрафтил ли персонаж хоть что-нибудь (любая запись в crafted_items_log).
     * Зеркало objective `craft_any` (QuestObjectiveHandler) → гейт хинта консистентен
     * с завершением шага OnbStepCraft. Overridable seam для тестов (без DB).
     */
    protected function hasCraftedAnything(int $charId): bool
    {
        return (new \App\Models\CraftedItemsLogModel())
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
