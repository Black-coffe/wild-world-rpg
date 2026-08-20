<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Vehicle;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterFactionModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\Player\VehicleActivationService;
use App\Services\Player\VehicleScreenRenderer;
use App\Services\World\VehicleEffectsService;
use CodeIgniter\Database\BaseConnection;
use Config\CraftRecipes;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * transport-10 (ADR-174, `docs/specs/transport-system/`) — экран «🚚 Мой транспорт»:
 * активная машина + износ в клетках + строка окупаемости + гараж + витрина четырёх
 * фракционных машин. Собирает состояние и передаёт его чистому
 * {@see VehicleScreenRenderer}, который несёт весь текст/раскладку кнопок.
 *
 * Callbacks (зарегистрированы в `Config\CallbackRoutes`):
 *   `vehicleScreen`        → главный экран
 *   `vehicleActivate_<id>` → активировать строку `crafted_items_log.id` (анти-IDOR —
 *                            владение проверяет {@see VehicleActivationService::activate()})
 *   `vehicleDeactivate`    → снять активную машину (работает откуда угодно, без базы)
 *   `vehicleShowcase`      → отдельный вид витрины (нужен story 12)
 *   `vehicleLockInfo_<key>`→ alert с причиной блокировки конкретной машины
 */
class VehicleAction extends BaseAction
{
    use GameSettingsReaderTrait;

    /** faction_id → ключ рецепта фракционной машины (Config\CraftRecipes). */
    private const VEHICLE_RECIPE_BY_FACTION_ID = [
        1 => 'Snowmobile',      // Милитари
        2 => 'MountainBike',    // Партизаны
        3 => 'AutonomousDrone', // Инженеры
        4 => 'DraftCart',       // Фермеры
    ];

    /** @var array<int,array{name:string,icon:string}> */
    private const FACTIONS = [
        1 => ['name' => 'Милитари', 'icon' => '🛡️'],
        2 => ['name' => 'Партизаны', 'icon' => '🌲'],
        3 => ['name' => 'Инженеры', 'icon' => '🛠️'],
        4 => ['name' => 'Фермеры', 'icon' => '🌾'],
    ];

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        $data   = $this->callbackQuery->getData();
        $parts  = explode('_', $data);
        $action = $parts[0];

        [, $character] = $this->getUserAndCharacter();

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $character) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => 'Персонаж не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        if ($action === 'vehicleActivate') {
            $this->activate($charId, (int) ($parts[1] ?? 0));
        } elseif ($action === 'vehicleDeactivate') {
            (new VehicleActivationService())->deactivate($charId);
        } elseif ($action === 'vehicleLockInfo') {
            return $this->lockInfo($chatId, $character, $parts[1] ?? '');
        }

        if ($action === 'vehicleShowcase') {
            return $this->showcase($chatId, $character);
        }

        return $this->screen($chatId, $character);
    }

    private function activate(int $charId, int $logId): void
    {
        if ($logId <= 0) {
            return;
        }
        // Чужая/несуществующая строка → false без записи — VehicleActivationService
        // сам держит анти-IDOR, экрану достаточно позвать метод и отрисовать факт.
        (new VehicleActivationService())->activate($charId, $logId);
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     */
    private function screen(int|string $chatId, array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $state = $this->buildState($character);
        $out   = VehicleScreenRenderer::renderScreen($state);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $out['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $out['buttons']]),
        ]);
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     */
    private function showcase(int|string $chatId, array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $state = $this->buildState($character);
        $out   = VehicleScreenRenderer::renderShowcase($state);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $out['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $out['buttons']]),
        ]);
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     */
    private function lockInfo(int|string $chatId, array|\App\Entities\CharacterEntity $character, string $recipeKey): ServerResponse
    {
        $recipes        = new CraftRecipes();
        $recipe         = $recipes->get($recipeKey);
        $characterLevel = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 0;
        $charId         = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $factionId      = (new CharacterFactionModel())->getFactionId($charId);

        $text = self::lockInfoText($recipe, $recipeKey, $characterLevel, $factionId);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '🚚 Мой транспорт', 'callback_data' => 'vehicleScreen'],
                    ['text' => '⚑ Фракции', 'callback_data' => 'chooseFaction_info'],
                ]],
            ]),
        ]);
    }

    /**
     * Чистая render-функция экрана «причина блокировки» (`vehicleLockInfo_<key>`) — без
     * БД/Telegram, покрыта {@see \Tests\Unit\Transport\VehicleScreenRenderTest}. Называет
     * настоящую причину: уровень своей строкой, фракция своей, а не одну на все машины —
     * `LightCart` закрыта только уровнем, у неё нет `required_faction` вовсе.
     *
     * @param array<string,mixed>|null $recipe
     */
    public static function lockInfoText(?array $recipe, string $recipeKey, int $characterLevel, int $characterFactionId): string
    {
        if (! $recipe) {
            return "🔒 *Машина не найдена*\n\nВыбери другую машину из витрины.";
        }

        $requiredLevel   = is_numeric($recipe['required_level'] ?? null) ? (int) $recipe['required_level'] : 0;
        $requiredFaction = is_numeric($recipe['required_faction'] ?? null) ? (int) $recipe['required_faction'] : 0;
        $vehicleName     = self::recipeString($recipe, 'item_name_rus', $recipeKey);

        $levelLocked   = $characterLevel < $requiredLevel;
        $factionLocked = $requiredFaction > 0 && $characterFactionId !== $requiredFaction;

        $lines = ['🔒 *' . $vehicleName . '*', ''];

        if ($factionLocked) {
            $factionMeta = self::FACTIONS[$requiredFaction] ?? ['name' => 'другой фракции', 'icon' => ''];
            $lines[]     = "Только {$factionMeta['icon']} {$factionMeta['name']}. Фракция выбирается на 10 уровне, "
                . 'один раз и навсегда.';
        }

        if ($levelLocked) {
            $lines[] = "Нужен {$requiredLevel} уровень, у тебя {$characterLevel}. "
                . 'Расти: исследуй мир, выполняй задания и дерись с врагами — опыт копится сам.';
        }

        if (! $factionLocked && ! $levelLocked) {
            $lines[] = 'Уже доступна тебе — собери или скрафти её.';
        }

        return implode("\n", $lines);
    }

    /**
     * Собирает состояние для {@see VehicleScreenRenderer} — единственное место, где
     * экран трогает БД/GameSettings/каталог. Renderer сам остаётся чистым.
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     * @return array{active: array{name:string,icon:string,cells_left:int,charges_full:int,wear_per_cell:int,savings_minutes:int}|null, garage: list<array{log_id:int,name:string,icon:string}>, showcase: list<array{faction_id:int,faction_name:string,faction_icon:string,vehicle_name:string,vehicle_icon:string,required_level:int,unlocked:bool}>, character_level:int}
     */
    private function buildState(array|\App\Entities\CharacterEntity $character): array
    {
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $level  = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 0;

        $activation = new VehicleActivationService();
        $effects    = new VehicleEffectsService();
        $recipes    = new CraftRecipes();
        /** @var BaseConnection<object,object> $db */
        $db = Database::connect();

        $resolved  = $activation->resolveActive($charId);
        $activeRow = null;
        $activeLogId = 0;

        if ($resolved !== null) {
            $activeLogId = $resolved['log_id'];
            $recipe      = $recipes->findByItemNameEng($resolved['key']);
            $key         = VehicleEffectsService::keyForItemNameEng($resolved['key']);

            // Единственный источник ёмкости — `resolveActive()['charges_full']`
            // (GameSettings `world.vehicle.<key>.charges_full`, ревью-находка
            // 2026-08-20): раньше этот экран читал тот же ключ ВТОРЫМ, независимым
            // вызовом GameSettings, а клэмп остатка в VehicleActivationService читал
            // каталог — два разных числа расходились на проде (120/150/100/200/80
            // против 300/350/400/400/350), окупаемость и порог предупреждения врали.
            $chargesFull = $resolved['charges_full'];
            $wearPerCell = $key !== null ? $this->gsInt("world.vehicle.{$key}.wear_per_cell", 1) : 1;
            $cellsLeft   = $wearPerCell > 0 ? intdiv($resolved['charges'], $wearPerCell) : $resolved['charges'];

            $minutesPerCell = $this->gsInt('world.march.minutes_per_cell', 1);
            $savings        = self::savingsMinutes($effects, $key, $resolved['charges'], $chargesFull, $wearPerCell, $minutesPerCell);

            $activeRow = [
                'name'            => self::recipeString($recipe, 'item_name_rus', $resolved['key']),
                'icon'            => self::recipeString($recipe, 'icon_emoji', '🚗'),
                'cells_left'      => $cellsLeft,
                'charges_full'    => max(1, $chargesFull),
                'wear_per_cell'   => $wearPerCell,
                'savings_minutes' => $savings,
            ];
        }

        // Гараж: все собранные транспортные предметы персонажа, кроме уже активного.
        // `crafted_items` не несёт `item_name_rus`/`icon_emoji` в БД — это данные
        // каталога (Config\CraftRecipes), резолв по name_eng через findByItemNameEng().
        $result = $db->table('crafted_items_log AS l')
            ->select('l.id, ci.name_eng')
            ->join('crafted_items AS ci', 'ci.id = l.crafted_item_id', 'left')
            ->where('l.character_id', $charId)
            ->where('ci.type', 'transport')
            ->get();
        $rows = $result === false ? [] : $result->getResultArray();

        $garage = [];
        foreach ($rows as $row) {
            $logId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            if ($logId === $activeLogId || $logId <= 0) {
                continue;
            }
            $nameEng = (string) ($row['name_eng'] ?? '');
            if (VehicleEffectsService::keyForItemNameEng($nameEng) === null) {
                continue;
            }
            $recipe = $recipes->findByItemNameEng($nameEng);
            $garage[] = [
                'log_id' => $logId,
                'name'   => self::recipeString($recipe, 'item_name_rus', $nameEng),
                'icon'   => self::recipeString($recipe, 'icon_emoji', '🚗'),
            ];
        }

        $factionId = (new CharacterFactionModel())->getFactionId($charId);

        $showcase = [];
        foreach (self::VEHICLE_RECIPE_BY_FACTION_ID as $fid => $recipeKey) {
            $recipe = $recipes->get($recipeKey);
            if (! $recipe) {
                continue;
            }
            $requiredLevel = is_numeric($recipe['required_level'] ?? null) ? (int) $recipe['required_level'] : 0;
            $meta          = self::FACTIONS[$fid];

            $showcase[] = [
                'faction_id'     => $fid,
                'faction_name'   => $meta['name'],
                'faction_icon'   => $meta['icon'],
                'vehicle_name'   => self::recipeString($recipe, 'item_name_rus', $recipeKey),
                'vehicle_icon'   => self::recipeString($recipe, 'icon_emoji', '🚗'),
                'required_level' => $requiredLevel,
                'unlocked'       => $factionId === $fid && $level >= $requiredLevel,
            ];
        }

        return [
            'active'          => $activeRow,
            'garage'          => $garage,
            'showcase'        => $showcase,
            'character_level' => $level,
        ];
    }

    /**
     * Строка окупаемости: сравнивает время на уже пройденные клетки (по факту износа)
     * пешком против фактического темпа машины. Ничего не хранит в БД — считается на
     * лету из остатка/полного запаса зарядов, поэтому история похода не нужна.
     *
     * `static` + явный `$minutesPerCell` (вместо `$this->gsInt()` внутри) — чистая
     * функция без БД/Telegram, тестируется рефлексией без сборки `VehicleAction`
     * (конструктор требует `CallbackQuery` + модели персонажа).
     */
    private static function savingsMinutes(VehicleEffectsService $effects, ?string $key, int $charges, int $chargesFull, int $wearPerCell, int $minutesPerCell): int
    {
        if ($key === null || $wearPerCell <= 0 || ! $effects->isEnabled()) {
            return 0;
        }

        $cellsTraveled = intdiv(max(0, $chargesFull - $charges), $wearPerCell);
        if ($cellsTraveled <= 0) {
            return 0;
        }

        $neutral = $effects->neutralProfile();
        $profile = $effects->profileFor($key, VehicleEffectsService::TERRAIN_UNEXPLORED);

        $baseCellsPerTick    = max(1, $neutral['cells_per_tick']);
        $vehicleCellsPerTick = max(1, $profile['cells_per_tick']);

        $timeWithout = (int) ceil($cellsTraveled / $baseCellsPerTick) * $minutesPerCell;
        $timeWith    = (int) ceil($cellsTraveled / $vehicleCellsPerTick) * $minutesPerCell;

        return max(0, $timeWithout - $timeWith);
    }

    /**
     * Достаёт строковое поле рецепта каталога (`Config\CraftRecipes`) без cast.mixed —
     * значения рецепта хранятся как `array<string,mixed>`, не типизированы построчно.
     *
     * @param array<string,mixed>|null $recipe
     */
    private static function recipeString(?array $recipe, string $field, string $default): string
    {
        $value = $recipe[$field] ?? null;

        return is_string($value) ? $value : $default;
    }
}
