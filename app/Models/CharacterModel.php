<?php namespace App\Models;

use CodeIgniter\Model;
use App\Models\ExploredCellsModel;
use App\Entities\CharacterEntity;

class CharacterModel extends Model
{
    protected $table = 'characters';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;

    /**
     * F1.4.4 Step B — Entity-возврат для characters.
     * Совместимость со старым `$character['name']` обеспечивается через
     * {@see \App\Entities\Traits\ArrayAccessibleEntity} trait.
     */
    protected $returnType = CharacterEntity::class;

    /**
     * Поля, которые разрешено массово заполнять
     * (в т.ч. при использовании ->update() или ->save() через модель).
     */
    protected $allowedFields = [
        'telegram_user_id',
        'name',
        'level',
        'experience',
        'health',
        'tired',
        'strength',
        'agility',
        'intellect',
        'gold',
        'cell_number',
        'biome_id',
        'trading_karma',
        'insurance',
        'preferred_map_type',
        'has_renamed',        // Новое поле
        'last_name_change',   // Новое поле
        'low_health_notified_at',
        'endgame_state',       // v0.51.110 endgame system
        'endgame_lock_at',     // v0.51.110 endgame system
        'disable_media',       // идея #14 — тумблер картинок (миграция 2026-05-08; пропущен в allowedFields)
        'last_respawn_at',     // death-validation batch 4 — момент последнего возрождения (grace-окно от damage-событий)
        'last_planted_crop',   // V6 (ADR-033) — rotation-память активного земледелия
        'well_fed_until',      // V9 (ADR-034) — момент истечения buff'а «Сытость»
        'daily_tips_enabled',  // ADR-038 Фаза C — тумблер «Совет дня» (opt-out, default 1)
        'whats_new_seen',      // W7b (ADR-065) — JSON-массив прочитанных тем каталога «Что нового»
        'specialization',      // V16 (ADR-047) — крафт-ветка (weaponsmith/medic/engineer)
        'specialization_changed_at', // V16 (ADR-047) — момент последней смены (кулдаун respec)
        'weight_capacity',     // W3a (ADR-059) — soft-cap веса инвентаря (default 9999 = off до W3b balance-pass)
        'combat_drone_active_until', // W5 (ADR-064) — lazy-expiry окно активного combat-drone defensive buff
        'duels_open',          // W17 (ADR-071) — opt-in флаг «открыт к дуэлям» (default 0)
        'locale',              // W27 (ADR-082) — язык интерфейса игрока (ru/en, default ru)
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Возвращает строку с информацией о персонаже.
     */
    public function getCharacterInfoAsString($characterId)
    {
        $character = $this->find($characterId);

        if (!$character) {
            return "Персонаж не найден.";
        }

        $info = "Информация о Персонаже:\n";
        $info .= "Имя: " . $character->name . "\n";
        $info .= "Уровень: " . $character->level . "\n";
        $info .= "Опыт: " . $character->experience . "\n";
        $info .= "Здоровье: " . $character->health . "\n";
        $info .= "Усталость: " . $character->tired . "\n";
        $info .= "Сила: " . $character->strength . "\n";
        $info .= "Ловкость: " . $character->agility . "\n";
        $info .= "Интеллект: " . $character->intellect . "\n";
        $info .= "Золото: " . $character->gold . "\n";
        $info .= "Номер ячейки: " . $character->cell_number . "\n";
        if ($character->biome_id !== null) {
            $info .= "ID биома: " . $character->biome_id . "\n";
        }
        if ($character->trading_karma !== null) {
            $info .= "Карма торговли: " . $character->trading_karma . "\n";
        }
        if ($character->preferred_map_type !== null) {
            $info .= "Тип карты: " . $character->preferred_map_type . "\n";
        }

        return $info;
    }

    public function decreaseGold(int $characterId, float $amount): bool
    {
        $amount = (int) round($amount);
        $character = $this->find($characterId);

        if (!$character || $character->gold < $amount) {
            log_message('error', "Проблема с персонажем с ID $characterId при попытке списать золото.");
            return false;
        }

        $newGoldAmount = $character->gold - $amount;
        return $this->update($characterId, ['gold' => $newGoldAmount]);
    }

    public function increaseGold(int $characterId, float $amount): bool
    {
        $amount = (int) round($amount);
        $character = $this->find($characterId);

        if (!$character) {
            log_message('error', "Персонаж с ID $characterId не найден при попытке увеличения золота.");
            return false;
        }

        $newGoldAmount = $character->gold + $amount;
        return $this->update($characterId, ['gold' => $newGoldAmount]);
    }

    // Ниже — дополнительные методы.

    public function getCurrentLocation(int $characterId)
    {
        $character = $this->find($characterId);
        return $character ? $character->cell_number : null;
    }

    public function getNeighboringCells(int $currentCellNumber)
    {
        // Предполагается, что есть MapModel
        $mapModel = new MapModel();
        return $mapModel->getNeighboringCells($currentCellNumber);
    }

    public function exploreCells(int $characterId, array $neighboringCells)
    {
        $exploredCellsModel = new ExploredCellsModel();
        foreach ($neighboringCells as $cell) {
            $exploredCellsModel->save([
                'character_id' => $characterId,
                'map_cell_id' => $cell['cell_number'],
                'explored_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function getCharacterIdByTelegramId(int $telegramId): ?int
    {
        $this->select('characters.id');
        $this->join('telegram_users', 'telegram_users.id = characters.telegram_user_id');
        $this->where('telegram_users.telegram_id', $telegramId);
        $character = $this->first();

        return $character ? (int) $character->id : null;
    }

    public function getCharacterByTelegramId(int $telegramId): ?CharacterEntity
    {
        $this->select('characters.*');
        $this->join('telegram_users', 'telegram_users.id = characters.telegram_user_id');
        $this->where('telegram_users.telegram_id', $telegramId);
        return $this->first();
    }

    /**
     * Пример обновления ловкости и интеллекта.
     */
    public function updateAgilityAndIntellect(int $characterId, float $agilityIncrement, float $intellectIncrement): bool
    {
        $character = $this->find($characterId);
        if (!$character) {
            log_message('error', "Персонаж с ID $characterId не найден при попытке обновления ловкости и интеллекта.");
            return false;
        }

        $newAgility = round($character->agility + $agilityIncrement, 2);
        $newIntellect = round($character->intellect + $intellectIncrement, 2);

        return $this->update($characterId, [
            'agility'   => $newAgility,
            'intellect' => $newIntellect
        ]);
    }

    public function updateStrengthAndAgility(int $characterId, float $strPlus, float $agiPlus): bool
    {
        $row = $this->find($characterId);
        if (!$row) {
            log_message('error', "Character #$characterId not found for Strength/Agility update.");
            return false;
        }

        $newStr = round($row->strength + $strPlus, 2);
        $newAgi = round($row->agility + $agiPlus, 2);

        return $this->update($characterId, [
            'strength' => $newStr,
            'agility'  => $newAgi,
        ]);
    }

}
