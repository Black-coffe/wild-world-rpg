<?php

declare(strict_types=1);

namespace App\Services\Quest;

use App\Models\CharacterFactionModel;
use App\Models\QuestModel;
use Config\Database;

/**
 * w2-n5-deeds-01 (ADR-190) — старт квеста в нейтральном ядре, без `chat_id`: бот
 * (`GenericQuestStartAction` и четыре легаси-кнопки) и веб (`/play?view=tasks`) зовут одно и то же.
 *
 * `start()` — тело бывшего `GenericQuestStartAction`: квест активен, это startable-корень ADR-088
 * (killswitch `quests.extended_enabled`) или bespoke-квест со своей кнопкой старта в боте ({@see LEGACY_STARTS},
 * предусловие цепочки выполнено), фракция, уровень, не начат. `claimFirstStep()` — общий для
 * всех стартов шаг записи: проверка «уже есть строка `quest_steps`» и вставка идут в одной транзакции
 * под `SELECT … FOR UPDATE` строки персонажа. Двойной тап, повтор формы или бот+веб одновременно —
 * второй ждёт первого и видит его строку. `UNIQUE` на `quest_steps` не вводится (повторяемость квестов
 * не проверена), поэтому сериализация — по персонажу.
 */
final class QuestStartService
{
    public const STARTED  = 'started';
    public const ALREADY  = 'already';
    public const LOCKED   = 'locked';
    public const DISABLED = 'disabled';
    public const UNKNOWN  = 'unknown';

    public const TEXT_ALREADY = 'Ты уже начал этот квест — смотри «🚀 Активные квесты».';

    /**
     * Bespoke-квесты (без `objective_type`), у которых в боте своя кнопка старта (exact-роуты
     * `questStart<TitleEn>` в `CallbackRoutes`): их прогресс ведут собственные обработчики, поэтому стартовать
     * их вручную можно и без ADR-088. Остальные bespoke — как в боте, «нельзя начать вручную».
     */
    public const LEGACY_STARTS = ['Explore30Cells', 'Explore300Cells', 'ExploreAllBiomes', 'FirstAidkitBasic'];

    private QuestChainService $chain;

    public function __construct(?QuestChainService $chain = null)
    {
        $this->chain = $chain ?? new QuestChainService();
    }

    /**
     * @return array{ok: bool, code: string, message: string, title_ru: ?string, description: string, reward: int}
     */
    public function start(int $characterId, string $titleEn): array
    {
        $fail = static fn (string $code, string $message): array => [
            'ok' => false, 'code' => $code, 'message' => $message, 'title_ru' => null, 'description' => '', 'reward' => 0,
        ];

        if ($titleEn === '') {
            return $fail(self::UNKNOWN, 'Некорректный квест.');
        }
        $res       = Database::connect()->table('characters')->select('level')->where('id', $characterId)->get();
        $character = $res === false ? null : $res->getRowArray();
        if ($characterId <= 0 || ! is_array($character)) {
            return $fail(self::UNKNOWN, 'Персонаж не найден.');
        }

        $quest = (new QuestModel())->where('title_en', $titleEn)->where('status', 'active')->first();
        if (! is_array($quest)) {
            return $fail(self::UNKNOWN, 'Квест не найден или недоступен.');
        }

        // ADR-088: стартовать вручную можно только расширенные startable-«корни»
        // (killswitch + objective задан + не discover_object + без prerequisite).
        if (! $this->chain->isExtendedStartableRoot($quest) && ! $this->isLegacyStart($quest, $characterId)) {
            return $fail(self::DISABLED, 'Этот квест нельзя начать вручную.');
        }

        // ADR-088 Фаза 3: фракционный квест — только для игроков своей фракции
        // (и при killswitch quests.faction_quests_enabled=ON).
        $charFactionId = (new CharacterFactionModel())->getFactionId($characterId);
        if (! $this->chain->factionGateOk($quest, $charFactionId)) {
            return $fail(self::LOCKED, 'Этот квест доступен только членам соответствующей фракции.');
        }

        $charLevel = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 0;
        $minLevel  = is_numeric($quest['min_level'] ?? null) ? (int) $quest['min_level'] : 0;
        if ($charLevel < $minLevel) {
            return $fail(self::LOCKED, "Квест доступен с {$minLevel}-го уровня.");
        }

        $questId = is_numeric($quest['id'] ?? null) ? (int) $quest['id'] : 0;
        $titleRu = is_string($quest['title_ru'] ?? null) ? $quest['title_ru'] : $titleEn;
        if (! $this->claimFirstStep($characterId, $questId, is_string($quest['title_ru'] ?? null) ? $quest['title_ru'] : 'Квест начат')) {
            return $fail(self::ALREADY, self::TEXT_ALREADY);
        }

        return [
            'ok'          => true,
            'code'        => self::STARTED,
            'message'     => 'Квест начат!',
            'title_ru'    => $titleRu,
            'description' => is_string($quest['description'] ?? null) ? $quest['description'] : '',
            'reward'      => is_numeric($quest['reward'] ?? null) ? (int) $quest['reward'] : 0,
        ];
    }

    /**
     * Bespoke-квест из {@see LEGACY_STARTS} с выполненным предусловием цепочки — тот, что «📜 Доступные»
     * показывают и бот стартует своей кнопкой.
     *
     * @param array<int|string, mixed> $quest
     */
    private function isLegacyStart(array $quest, int $characterId): bool
    {
        if (! empty($quest['objective_type']) || ! in_array($quest['title_en'] ?? null, self::LEGACY_STARTS, true)) {
            return false;
        }

        return $this->chain->prerequisiteMet(
            $this->chain->prerequisiteOf($quest),
            (new QuestModel())->getCompletedQuestTitles($characterId)
        );
    }

    /**
     * Первая строка `quest_steps` квеста, если у персонажа её ещё нет. Проверка и вставка — под
     * блокировкой строки персонажа; false — строка уже была (квест начат раньше, в т.ч. параллельно).
     */
    public function claimFirstStep(int $characterId, int $questId, string $description): bool
    {
        $db = Database::connect();
        $db->transBegin();

        try {
            $db->query('SELECT id FROM characters WHERE id = ? FOR UPDATE', [$characterId]);
            $exists = $db->table('quest_steps')->where('quest_id', $questId)->where('character_id', $characterId)->countAllResults() > 0;
            if ($exists) {
                $db->transRollback();

                return false;
            }
            $now = date('Y-m-d H:i:s');
            $db->table('quest_steps')->insert([
                'quest_id'     => $questId,
                'character_id' => $characterId,
                'step_order'   => 1,
                'description'  => $description,
                'is_completed' => 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        return true;
    }
}
