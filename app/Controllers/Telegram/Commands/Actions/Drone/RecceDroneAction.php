<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Services\Player\DroneService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * W2 (ADR-058) — main launch action. Callback `recceDrone_<crafted_items_log_id>`
 * (PrefixDispatcher). Расходует drone.scout.battery_drain_per_launch заряда из
 * конкретного дрон-инстанса, открывает зону radius=10 (Чебышёв) вокруг текущей
 * клетки игрока через ExploredCellsModel::revealAround() (ADR-019 compat).
 *
 * Проверки (в порядке): killswitch / log exists / qty>0 / durability >= drain /
 * cell coords / PvP-zone block (другой character стоит на клетке = чужая база).
 *
 * После запуска caption media-off-safe (ADR-058): результат (новые клетки + биомы
 * + остаток заряда) полностью в тексте.
 *
 * Дрон детерминирован (revealAround = pure geometric), 0 mt_rand (RNG-fence safe).
 */
class RecceDroneAction extends BaseAction
{
    private CraftedItemsLogModel $logModel;
    private MapModel $mapModel;
    private BiomeModel $biomeModel;
    private ExploredCellsModel $exploredCellsModel;
    private CharacterModel $charModel;
    private DroneService $service;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->logModel           = new CraftedItemsLogModel();
        $this->mapModel           = new MapModel();
        $this->biomeModel         = new BiomeModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->charModel          = new CharacterModel();
        $this->service            = new DroneService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }

        if (! $this->service->isEnabled()) {
            return $this->errReply($chatId, '🚁 Дроны временно отключены.');
        }

        $logId = $this->parseIdSuffix($this->callbackQuery->getData(), 'recceDrone_');
        if ($logId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор дрона.');
        }

        $log = $this->logModel->find($logId);
        if (! is_array($log)) {
            return $this->errReply($chatId, 'Дрон не найден.');
        }

        $charId       = $this->extractInt($character, 'id');
        $logCharId    = $this->extractInt($log, 'character_id');
        if ($charId !== $logCharId) {
            return $this->errReply($chatId, 'Этот дрон принадлежит не тебе.');
        }

        $qty      = $this->extractInt($log, 'quantity');
        $charge   = $this->extractInt($log, 'durability_count');
        $drain    = $this->service->batteryDrainPerLaunch();

        if ($qty <= 0) {
            return $this->errReply($chatId, 'Дрона уже нет.');
        }
        if ($charge < $drain) {
            return $this->errReply($chatId, "🪫 Заряд дрона ниже {$drain} — нужно подзарядить на базе.");
        }

        $currentCellNum = $this->extractInt($character, 'cell_number');
        if ($currentCellNum <= 0) {
            return $this->errReply($chatId, 'Невозможно определить твою клетку.');
        }

        // PvP-zone block (anti-snooping, ADR-058 резолюция #5):
        // Если на клетке стоит ДРУГОЙ персонаж — это его территория, дрон не запускается.
        $otherChar = $this->charModel
            ->where('cell_number', $currentCellNum)
            ->where('id !=', $charId)
            ->first();
        if (is_array($otherChar) || is_object($otherChar)) {
            return $this->errReply(
                $chatId,
                "🚫 Дрон не запускается над чужой клеткой (anti-snooping). Перейди на свою территорию или нейтральную клетку."
            );
        }

        // Координаты текущей клетки (для revealAround Чебышёва).
        $cellRow = $this->mapModel->where('cell_number', $currentCellNum)->first();
        if (! is_array($cellRow)) {
            return $this->errReply($chatId, 'Клетка не найдена в карте.');
        }
        $cellX = $this->extractInt($cellRow, 'coordinate_x');
        $cellY = $this->extractInt($cellRow, 'coordinate_y');
        if ($cellX <= 0 || $cellY <= 0) {
            return $this->errReply($chatId, 'Невозможно определить координаты твоей клетки.');
        }

        $radius   = $this->service->radiusCells();
        $charLvl  = $this->extractInt($character, 'level');
        $tgUserId = $this->extractInt($user, 'id');

        // Открываем зону (idempotent, ADR-019).
        $newCellsCount = $this->exploredCellsModel->revealAround(
            $charId,
            $tgUserId,
            $cellX,
            $cellY,
            $charLvl ?: null,
            $radius
        );

        // Списываем заряд.
        $newCharge = max(0, $charge - $drain);
        $this->logModel->update($logId, ['durability_count' => $newCharge]);

        // E20 (ADR-120) — инструментация адопшена дронов (раньше use-path был немеряем).
        $this->logActivity($charId, 'DRONE_SCOUT_LAUNCH', "radius={$radius} new_cells={$newCellsCount}");

        // Сбор уникальных биомов в зоне (для информативного caption'а).
        $biomeNames = $this->collectBiomeNamesInRadius($cellX, $cellY, $radius);

        $batteryMax = $this->service->batteryMax();
        $chargePct  = $batteryMax > 0 ? (int) round($newCharge * 100 / $batteryMax) : 0;
        $bar        = $this->renderChargeBar($chargePct);
        $cellsScan  = (2 * $radius + 1) * (2 * $radius + 1);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Дрон взлетел!',
        ]);

        $text  = "🚁 *Дрон-разведчик вернулся!*\n\n";
        $text .= "Координаты запуска: `X={$cellX}, Y={$cellY}`\n";
        $text .= "Зона разведки: *{$cellsScan}* клеток (радиус {$radius}).\n";
        $text .= "Открыто *новых* клеток: *{$newCellsCount}*.\n\n";

        if (! empty($biomeNames)) {
            $text .= "Биомы в зоне:\n";
            foreach ($biomeNames as $name) {
                $text .= "  • {$name}\n";
            }
            $text .= "\n";
        }

        $text .= "🔋 Остаток заряда: {$bar} `{$newCharge}/{$batteryMax}` ({$chargePct}%)\n";
        if ($newCharge < $drain) {
            $text .= "_Следующий запуск — после подзарядки на базе._";
        } else {
            $text .= "_Можешь запустить ещё раз._";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚁 Мои дроны', 'callback_data' => 'droneScoutList'],
                    ['text' => '🗺 Карта',     'callback_data' => 'move'],
                ],
            ],
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * @return list<string>
     */
    private function collectBiomeNamesInRadius(int $centerX, int $centerY, int $radius): array
    {
        $xMin = max(1, $centerX - $radius);
        $xMax = min(1000, $centerX + $radius);
        $yMin = max(1, $centerY - $radius);
        $yMax = min(1000, $centerY + $radius);

        $rows = $this->mapModel
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();

        $biomeIds = [];
        foreach ($rows as $r) {
            $bid = $this->extractInt($r, 'biome_id');
            if ($bid > 0) {
                $biomeIds[$bid] = true;
            }
        }
        if (empty($biomeIds)) {
            return [];
        }

        $names = [];
        foreach (array_keys($biomeIds) as $bid) {
            $biome = $this->biomeModel->find($bid);
            if ($biome === null) {
                continue;
            }
            // BiomeModel может вернуть Entity (ArrayAccess) — читаем как массив.
            $name = $biome['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    private function renderChargeBar(int $pct): string
    {
        $pct    = max(0, min(100, $pct));
        $filled = (int) round($pct / 10);
        $empty  = 10 - $filled;
        return str_repeat('▰', $filled) . str_repeat('▱', $empty);
    }

    private function extractInt(mixed $row, string $key): int
    {
        if (is_array($row)) {
            $v = $row[$key] ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        if (is_object($row)) {
            $v = $row->{$key} ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        return 0;
    }

    private function parseIdSuffix(string $callbackData, string $prefix): int
    {
        if (! str_starts_with($callbackData, $prefix)) {
            return 0;
        }
        $rest = substr($callbackData, strlen($prefix));
        return is_numeric($rest) ? (int) $rest : 0;
    }

    private function errReply(int $chatId, string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
        ]);
        return Request::sendMessage(['chat_id' => $chatId, 'text' => $msg]);
    }
}
