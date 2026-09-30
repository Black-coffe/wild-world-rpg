<?php

declare(strict_types=1);

namespace App\Services\Quest;

use App\Models\CharacterFactionModel;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use Config\Database;

/**
 * w2-n5-deeds-02 (ADR-190) — три списка квестов «Дела» в нейтральном ядре, без `chat_id` и Markdown:
 * активные, доступные (с замками цепочки) и завершённые. Бот (`ActiveQuests` / `AvailableQuests` /
 * `CompletedQuests`) рисует из них прежние тексты, веб `/play?view=tasks` — свои.
 *
 * Классификация доступно/заблокировано — {@see QuestOverviewService::classifyQuests()} (флаги
 * `quests.extended_enabled` / `quests.faction_quests_enabled` / цепочки), развилки —
 * {@see QuestChainService::pendingBranchesForCharacter()} (`quests.branching_enabled`): один источник
 * для обоих клиентов.
 *
 * @phpstan-type QuestRow array{id: int, title_en: string, title_ru: string, description: string, reward: int, reward_type_ru: string}
 * @phpstan-type AvailableRow array{id: int, title_en: string, title_ru: string, description: string, reward: int, reward_type_ru: string, locked: bool, lock_reason: string, prereq_title_ru: string}
 */
final class QuestListService
{
    private const REWARD_TYPES = [
        'gold'       => 'золото',
        'experience' => 'опыт',
        'items'      => 'предметы',
    ];

    /** @return list<QuestRow> */
    public function active(int $characterId): array
    {
        return $this->byStepState($characterId, false);
    }

    /** @return list<QuestRow> */
    public function completed(int $characterId): array
    {
        return $this->byStepState($characterId, true);
    }

    /**
     * Стартуемые сейчас (`locked=false`), затем звенья цепочки с невыполненным предусловием
     * (`locked=true`, `lock_reason` — «после квеста «X»»).
     *
     * @return list<AvailableRow>
     */
    public function available(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }
        $res   = Database::connect()->table('characters')->select('level')->where('id', $characterId)->get();
        $row   = $res === false ? null : $res->getRowArray();
        $level = is_array($row) && is_numeric($row['level'] ?? null) ? (int) $row['level'] : 1;

        $factionId  = (new CharacterFactionModel())->getFactionId($characterId);
        $classified = (new QuestOverviewService())->classifyQuests($level, $characterId, $factionId);

        $out = [];
        foreach ($classified['available'] as $quest) {
            $out[] = $this->row($quest) + ['locked' => false, 'lock_reason' => '', 'prereq_title_ru' => ''];
        }
        $questModel = new QuestModel();
        foreach ($classified['locked'] as $lq) {
            $prereq  = $this->prerequisiteTitleRu($questModel, $lq['prereq']);
            $out[]   = $this->row($lq['quest']) + [
                'locked'          => true,
                'lock_reason'     => "после квеста «{$prereq}»",
                'prereq_title_ru' => $prereq,
            ];
        }

        return $out;
    }

    /**
     * Развилки цепочек, ждущие выбора (пусто при `quests.branching_enabled` = OFF).
     *
     * @return list<array{branch_point_ru:string,options:list<array{quest_id:int,title_en:string,title_ru:string,label:string}>}>
     */
    public function branches(int $characterId): array
    {
        return $characterId > 0 ? (new QuestChainService())->pendingBranchesForCharacter($characterId) : [];
    }

    public static function rewardTypeRu(mixed $type): string
    {
        if (! is_string($type)) {
            return '';
        }

        return self::REWARD_TYPES[$type] ?? $type;
    }

    /** @return list<QuestRow> */
    private function byStepState(int $characterId, bool $completed): array
    {
        if ($characterId <= 0) {
            return [];
        }
        $steps = (new QuestStepsModel())->where('character_id', $characterId)->where('is_completed', $completed)->findAll();
        if ($steps === []) {
            return [];
        }
        $ids    = array_column($steps, 'quest_id');
        $quests = (new QuestModel())->whereIn('id', $ids)->findAll();

        $out = [];
        foreach ($quests as $quest) {
            if (is_array($quest)) {
                $out[] = $this->row($quest);
            }
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $quest
     * @return QuestRow
     */
    private function row(array $quest): array
    {
        return [
            'id'             => is_numeric($quest['id'] ?? null) ? (int) $quest['id'] : 0,
            'title_en'       => is_string($quest['title_en'] ?? null) ? $quest['title_en'] : '',
            'title_ru'       => is_string($quest['title_ru'] ?? null) ? $quest['title_ru'] : '',
            'description'    => is_string($quest['description'] ?? null) ? $quest['description'] : '',
            'reward'         => is_numeric($quest['reward'] ?? null) ? (int) $quest['reward'] : 0,
            'reward_type_ru' => self::rewardTypeRu($quest['reward_type'] ?? null),
        ];
    }

    /** Русское название квеста-предусловия (по title_en) для тизера цепочки. */
    private function prerequisiteTitleRu(QuestModel $questModel, ?string $prereqTitleEn): string
    {
        if ($prereqTitleEn === null || $prereqTitleEn === '') {
            return '???';
        }
        $row = $questModel->where('title_en', $prereqTitleEn)->first();

        return is_array($row) && isset($row['title_ru']) && is_string($row['title_ru']) && $row['title_ru'] !== ''
            ? $row['title_ru']
            : $prereqTitleEn;
    }
}
