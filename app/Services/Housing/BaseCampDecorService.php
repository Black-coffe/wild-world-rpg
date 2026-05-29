<?php

declare(strict_types=1);

namespace App\Services\Housing;

use App\Models\ClaimedCellModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * W21 (ADR-076) — Housing customisation: декор базы (косметика, 0 механики).
 *
 * Имя лагеря: 12 постапок-пресетов (игрок выбирает один).
 * Флаг лагеря: 16 emoji-вариантов (игрок выбирает один).
 * Хранится в claimed_cells.camp_name / camp_flag (NULL = не задано).
 *
 * Killswitch housing.decoration.enabled: при dormant — кнопка «🎨 Декор» скрыта.
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
     * Возвращает текущий декор активной базы персонажа.
     *
     * @return array{name: string|null, flag: string|null}
     */
    public function getCampDecor(int $charId): array
    {
        $row = (new ClaimedCellModel())
            ->where('character_id', $charId)
            ->where('status', 'active')
            ->first();
        $name = is_array($row) ? ($row['camp_name'] ?? null) : null;
        $flag = is_array($row) ? ($row['camp_flag'] ?? null) : null;
        return [
            'name' => is_string($name) && $name !== '' ? $name : null,
            'flag' => is_string($flag) && $flag !== '' ? $flag : null,
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
        $model = new ClaimedCellModel();
        $row   = $model->where('character_id', $charId)->where('status', 'active')->first();
        if (! is_array($row)) {
            return false;
        }
        $id = $row['id'] ?? null;
        $model->update(is_numeric($id) ? (int) $id : 0, ['camp_name' => self::PRESET_NAMES[$idx]]);
        return true;
    }

    /**
     * Устанавливает emoji-флаг по индексу из PRESET_FLAGS. Возвращает false при невалидном idx.
     */
    public function setCampFlag(int $charId, int $idx): bool
    {
        if (! array_key_exists($idx, self::PRESET_FLAGS)) {
            return false;
        }
        $model = new ClaimedCellModel();
        $row   = $model->where('character_id', $charId)->where('status', 'active')->first();
        if (! is_array($row)) {
            return false;
        }
        $id = $row['id'] ?? null;
        $model->update(is_numeric($id) ? (int) $id : 0, ['camp_flag' => self::PRESET_FLAGS[$idx]]);
        return true;
    }
}
