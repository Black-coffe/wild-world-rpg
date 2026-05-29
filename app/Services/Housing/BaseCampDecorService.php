<?php

declare(strict_types=1);

namespace App\Services\Housing;

use App\Models\ClaimedCellModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * W21 (ADR-076) + W22 (ADR-077) — Housing customisation: декор базы (косметика, 0 механики).
 *
 * W21 (экстерьер):
 *   Имя лагеря: 12 постапок-пресетов (игрок выбирает один).
 *   Флаг лагеря: 16 emoji-вариантов (игрок выбирает один).
 *   Хранится в claimed_cells.camp_name / camp_flag (NULL = не задано).
 *
 * W22 (интерьер — interior items):
 *   Очаг: 6 пресетов (костёр/генератор/печь…) → camp_hearth.
 *   Обстановка: 6 пресетов (стулья/диван/верстак…) → camp_furniture.
 *   Питомец-маскот: 6 пресетов (пёс/волк/кот…) → camp_pet.
 *   Хранится готовая display-строка с emoji (NULL = не задано).
 *
 * Killswitch housing.decoration.enabled: при dormant — кнопка «🎨 Декор» скрыта
 * (W22 переиспользует тот же killswitch — единый housing-feature, как W19→W20).
 * try/catch → false при отсутствии game_settings (тест-среда).
 */
final class BaseCampDecorService
{
    /** 12 постапок-названий лагеря. Порядок = индекс кнопки UI. */
    public const PRESET_NAMES = [
        'Форт Надежды',
        'Последний Рубеж',
        'Серый Бастион',
        'Точка Возврата',
        'Тихая Гавань',
        'Стальные Врата',
        'Пепел и Воля',
        'Кордон 7',
        'Лагерь Омега',
        'Старый Маяк',
        'Бункер Рассвета',
        'Убежище Выживших',
    ];

    /** 16 emoji-флагов. Порядок = индекс кнопки UI. */
    public const PRESET_FLAGS = [
        '⚔️', '🔫', '🛡️', '🔥', '☢️', '⚡', '🦅', '🐺',
        '💀', '⚙️', '🌑', '🗡️', '🏹', '🔱', '⚜️', '🌪️',
    ];

    /** W22: 6 пресетов очага (центр атмосферы базы). Display-строка с emoji. */
    public const PRESET_HEARTHS = [
        '🔥 Костёр',
        '🛢️ Бочка с огнём',
        '⚙️ Генератор',
        '🕯️ Факелы',
        '♨️ Печь-буржуйка',
        '🪵 Поленница',
    ];

    /** W22: 6 пресетов обстановки (мебель/утварь). Display-строка с emoji. */
    public const PRESET_FURNITURE = [
        '🪑 Складные стулья',
        '🛋️ Старый диван',
        '🛏️ Лежанка',
        '🔧 Верстак',
        '🧶 Самотканый ковёр',
        '📻 Радиоприёмник',
    ];

    /** W22: 6 пресетов питомца-маскота. Display-строка с emoji. */
    public const PRESET_PETS = [
        '🐕 Сторожевой пёс',
        '🐺 Прирученный волк',
        '🐈 Кот-крысолов',
        '🐐 Коза',
        '🐦 Ворон-разведчик',
        '🐀 Ручная крыса',
    ];

    public function enabled(): bool
    {
        try {
            $v = (new GameSettingsService())->get('housing.decoration.enabled', false);
        } catch (\Throwable) {
            return false;
        }
        if (is_bool($v)) {
            return $v;
        }
        return is_numeric($v) && (int) $v === 1;
    }

    /**
     * Возвращает текущий декор активной базы персонажа (экстерьер W21 + интерьер W22).
     *
     * @return array{name: string|null, flag: string|null, hearth: string|null, furniture: string|null, pet: string|null}
     */
    public function getCampDecor(int $charId): array
    {
        $row = (new ClaimedCellModel())
            ->where('character_id', $charId)
            ->where('status', 'active')
            ->first();
        $get = static function (?array $r, string $col): ?string {
            $v = is_array($r) ? ($r[$col] ?? null) : null;
            return is_string($v) && $v !== '' ? $v : null;
        };
        $row = is_array($row) ? $row : null;
        return [
            'name'      => $get($row, 'camp_name'),
            'flag'      => $get($row, 'camp_flag'),
            'hearth'    => $get($row, 'camp_hearth'),
            'furniture' => $get($row, 'camp_furniture'),
            'pet'       => $get($row, 'camp_pet'),
        ];
    }

    /**
     * Возвращает имя лагеря для PvP-контекста (атака на чужую базу).
     */
    public function getDefenderCampName(int $charId): ?string
    {
        return $this->getCampDecor($charId)['name'];
    }

    /**
     * Устанавливает имя лагеря по индексу из PRESET_NAMES. Возвращает false при невалидном idx.
     */
    public function setCampName(int $charId, int $idx): bool
    {
        if (! array_key_exists($idx, self::PRESET_NAMES)) {
            return false;
        }
        return $this->setCampField($charId, 'camp_name', self::PRESET_NAMES[$idx]);
    }

    /**
     * Устанавливает emoji-флаг по индексу из PRESET_FLAGS. Возвращает false при невалидном idx.
     */
    public function setCampFlag(int $charId, int $idx): bool
    {
        if (! array_key_exists($idx, self::PRESET_FLAGS)) {
            return false;
        }
        return $this->setCampField($charId, 'camp_flag', self::PRESET_FLAGS[$idx]);
    }

    /**
     * W22: устанавливает очаг по индексу из PRESET_HEARTHS. Возвращает false при невалидном idx.
     */
    public function setCampHearth(int $charId, int $idx): bool
    {
        if (! array_key_exists($idx, self::PRESET_HEARTHS)) {
            return false;
        }
        return $this->setCampField($charId, 'camp_hearth', self::PRESET_HEARTHS[$idx]);
    }

    /**
     * W22: устанавливает обстановку по индексу из PRESET_FURNITURE. Возвращает false при невалидном idx.
     */
    public function setCampFurniture(int $charId, int $idx): bool
    {
        if (! array_key_exists($idx, self::PRESET_FURNITURE)) {
            return false;
        }
        return $this->setCampField($charId, 'camp_furniture', self::PRESET_FURNITURE[$idx]);
    }

    /**
     * W22: устанавливает питомца по индексу из PRESET_PETS. Возвращает false при невалидном idx.
     */
    public function setCampPet(int $charId, int $idx): bool
    {
        if (! array_key_exists($idx, self::PRESET_PETS)) {
            return false;
        }
        return $this->setCampField($charId, 'camp_pet', self::PRESET_PETS[$idx]);
    }

    /**
     * Записывает одно поле декора в active claimed_cells строку персонажа.
     * Возвращает false, если базы (active-строки) нет.
     */
    private function setCampField(int $charId, string $field, string $value): bool
    {
        $model = new ClaimedCellModel();
        $row   = $model->where('character_id', $charId)->where('status', 'active')->first();
        if (! is_array($row)) {
            return false;
        }
        $id = $row['id'] ?? null;
        $model->update(is_numeric($id) ? (int) $id : 0, [$field => $value]);
        return true;
    }
}
