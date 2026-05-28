<?php

namespace App\Models;

use CodeIgniter\Model;

class QuestModel extends Model
{
    protected $table = 'quests';
    protected $primaryKey = 'id';

    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['title_ru', 'title_en', 'description', 'status', 'reward', 'min_level', 'reward_type', 'prerequisite_quest', 'objective_type', 'objective_target', 'objective_qty', 'branch_group', 'branch_label'];

    protected $useTimestamps = false;
    protected $createdField  = '';
    protected $updatedField  = '';
    protected $deletedField  = '';

    protected $validationRules = [
        'title_ru' => 'required|min_length[3]|max_length[255]',
        'title_en' => 'required|min_length[3]|max_length[255]',
        'status' => 'required|in_list[active,completed,available]',
        'description' => 'required',
        'reward' => 'required|numeric',
        'min_level' => 'integer',
        'reward_type' => 'max_length[255]',
    ];

    protected $validationMessages = [
        'title_ru' => [
            'required' => 'Поле Название квеста (рус.) обязательно для заполнения.',
            'min_length' => 'Минимальная длина поля Название квеста (рус.) - 3 символа.',
            'max_length' => 'Максимальная длина поля Название квеста (рус.) - 255 символов.',
        ],
        'title_en' => [
            'required' => 'Поле Название квеста (англ.) обязательно для заполнения.',
            'min_length' => 'Минимальная длина поля Название квеста (англ.) - 3 символа.',
            'max_length' => 'Максимальная длина поля Название квеста (англ.) - 255 символов.',
        ],
        'status' => [
            'required' => 'Поле Статус обязательно для выбора.',
            'in_list' => 'Недопустимое значение для поля Статус.',
        ],
        'description' => [
            'required' => 'Поле Описание обязательно для заполнения.',
        ],
        'reward' => [
            'required' => 'Поле Награда обязательно для заполнения.',
            'numeric' => 'Поле Награда должно быть числом.',
        ],
        'min_level' => [
            'integer' => 'Поле Минимальный уровень должно быть целым числом.',
        ],
        'reward_type' => [
            'max_length' => 'Максимальная длина поля Тип награды - 255 символов.',
        ],
    ];

    protected $skipValidation = false;

    /**
     * Retrieves available quests based on the character's level.
     *
     * @param int $characterLevel The level of the character.
     * @return array The list of quests available for the character's level.
     */
    public function getAvailableQuests(int $characterLevel): array
    {
        return $this->asArray()
            ->where('status', 'active')
            ->where('min_level <=', $characterLevel)
            ->findAll();
    }

    /**
     * V11 (ADR-036) — title_en всех квестов, ЗАВЕРШЁННых персонажем (есть quest_steps
     * с is_completed=1). Используется QuestChainService для проверки prerequisite.
     * Зеркало логики «quest completed» из GenericCraftActionStart (S25 required_quest).
     *
     * @return list<string>
     */
    public function getCompletedQuestTitles(int $characterId): array
    {
        $rows = $this->db->table('quest_steps qs')
            ->select('q.title_en')
            ->distinct()
            ->join('quests q', 'q.id = qs.quest_id')
            ->where('qs.character_id', $characterId)
            ->where('qs.is_completed', 1)
            ->get();
        if ($rows === false) {
            return [];
        }
        $out = [];
        foreach ($rows->getResultArray() as $r) {
            if (isset($r['title_en']) && is_string($r['title_en']) && $r['title_en'] !== '') {
                $out[] = $r['title_en'];
            }
        }
        return $out;
    }

    /**
     * W11 (ADR-067) — active branch-квесты (branch_group непустой) для данного
     * prerequisite. Raw db-builder (как getCompletedQuestTitles) — без model-builder
     * state quirk при множественных запросах.
     *
     * @return list<array<int|string,mixed>>
     */
    public function getActiveBranchQuests(string $prerequisiteTitleEn): array
    {
        if ($prerequisiteTitleEn === '') {
            return [];
        }
        $rows = $this->db->table('quests')
            ->where('prerequisite_quest', $prerequisiteTitleEn)
            ->where('status', 'active')
            ->where('branch_group IS NOT NULL', null, false)
            ->get();
        return $rows === false ? [] : array_values($rows->getResultArray());
    }

    /**
     * W11 (ADR-067) — active branch-квесты для набора prerequisite (hub-экран развилок).
     *
     * @param list<string> $prerequisiteTitlesEn
     * @return list<array<int|string,mixed>>
     */
    public function getActiveBranchQuestsForPrereqs(array $prerequisiteTitlesEn): array
    {
        if ($prerequisiteTitlesEn === []) {
            return [];
        }
        $rows = $this->db->table('quests')
            ->whereIn('prerequisite_quest', $prerequisiteTitlesEn)
            ->where('status', 'active')
            ->where('branch_group IS NOT NULL', null, false)
            ->get();
        return $rows === false ? [] : array_values($rows->getResultArray());
    }

    /** W11 — title_ru квеста по title_en (шапка развилки). Fallback на сам title_en. */
    public function titleRuByEn(string $titleEn): string
    {
        if ($titleEn === '') {
            return '';
        }
        $row = $this->db->table('quests')->select('title_ru')->where('title_en', $titleEn)->get();
        $r   = $row === false ? null : $row->getRowArray();
        return is_array($r) && is_string($r['title_ru'] ?? null) && $r['title_ru'] !== '' ? $r['title_ru'] : $titleEn;
    }
}
