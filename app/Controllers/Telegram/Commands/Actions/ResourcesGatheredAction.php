<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Services\Notifications\MediaSender;
use App\Services\Player\InventorySortService;
use App\Services\Player\WeightCapacityService;

class ResourcesGatheredAction extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel = new ResourceModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // W8: режим сортировки из callback `resourcesGathered_sort_<mode>` (stateless).
        $mode = $this->parseSortMode((string) $this->callbackQuery->getData());

        $characterResources = $this->characterResourceModel
            ->select('character_resources.quantity, resources.name, resources.rarity, resources.price, resources.weight')
            ->join('resources', 'resources.id = character_resources.id_resources')
            ->where('character_resources.id_characters', $character['id'])
            ->findAll();

        if (empty($characterResources)) {
            $text = "🤷‍♂️ *Не переживай, друг!* Всё ещё впереди.\n\n"
                . "Тебе всего лишь нужно раз выйти за лутом, и твой складской сундук наполнится сокровищами! 🗝️💎\n\n"
                . "Собери свои снаряжение, наберись смелости и вперёд к приключениям! 🏹🧭\n\n"
                . "И помни, каждый великий начинал с малого! 🌟";
            return $this->reply($text, $mode);
        }

        // Нормализуем строки к массиву (Model может вернуть Entity при ином returnType).
        $rows = [];
        foreach ($characterResources as $r) {
            $rows[] = is_array($r) ? $r : (array) $r;
        }

        $totalValue  = 0.0;
        $totalWeight = 0.0;
        foreach ($rows as $r) {
            $qty   = is_numeric($r['quantity'] ?? null) ? (int) $r['quantity'] : 0;
            $price = is_numeric($r['price'] ?? null) ? (float) $r['price'] : 0.0;
            $w     = is_numeric($r['weight'] ?? null) ? (float) $r['weight'] : 0.0;
            $totalValue  += $price * $qty;
            $totalWeight += $w * $qty;
        }

        $sorted = InventorySortService::sortRows($rows, $mode);

        $text = "🎒 *Добытые ресурсы* (" . $this->modeLabel($mode) . ")\n\n";

        if ($mode === InventorySortService::MODE_RARITY) {
            $lastRarity = null;
            foreach ($sorted as $r) {
                $rarity = is_scalar($r['rarity'] ?? null) ? (string) $r['rarity'] : '';
                if ($rarity !== $lastRarity) {
                    $text .= ($lastRarity === null ? '' : "\n") . "*Редкость {$rarity}:*\n";
                    $lastRarity = $rarity;
                }
                $text .= $this->line($r);
            }
        } else {
            foreach ($sorted as $r) {
                $text .= $this->line($r);
            }
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $level  = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 1;
        $cap    = is_numeric($character['weight_capacity'] ?? null) ? (int) $character['weight_capacity'] : 0;

        $text .= "\n💰 *Общая стоимость* ~ " . number_format($totalValue) . "\n";
        $text .= $this->weightLine($charId, $level, $cap, $totalWeight);

        return $this->reply($text, $mode);
    }

    /**
     * Строка одного ресурса: «📦 name | N шт · X кг».
     *
     * @param array<string,mixed> $r
     */
    private function line(array $r): string
    {
        $name = is_string($r['name'] ?? null) ? $r['name'] : '';
        $qty  = is_numeric($r['quantity'] ?? null) ? (int) $r['quantity'] : 0;
        $w    = is_numeric($r['weight'] ?? null) ? (float) $r['weight'] : 0.0;
        $kg   = round($qty * $w, 1);
        return "📦 {$name} | " . number_format($qty) . " шт · {$kg} кг\n";
    }

    /** Футер веса: при включённом weight-cap — load/cap/остаток, иначе общий вес. */
    private function weightLine(int $charId, int $level, int $cap, float $totalWeight): string
    {
        $svc = new WeightCapacityService();
        if (! $svc->isEnabled()) {
            return "⚖️ *Общий вес:* " . round($totalWeight, 1) . " кг";
        }
        $current = round($svc->getCurrentLoad($charId), 1);
        $capVal  = $cap > 0 ? $cap : $svc->computeCapacity($level);
        $remain  = round($svc->getRemainingCapacity($charId, $level, $cap), 1);
        return "⚖️ *Вес:* {$current} / {$capVal} кг (осталось {$remain})";
    }

    private function parseSortMode(string $callbackData): string
    {
        $mode = null;
        if (str_starts_with($callbackData, 'resourcesGathered_sort_')) {
            $mode = substr($callbackData, strlen('resourcesGathered_sort_'));
        }
        return InventorySortService::normalizeMode($mode, InventorySortService::RESOURCE_MODES, InventorySortService::MODE_RARITY);
    }

    private function modeLabel(string $mode): string
    {
        return match ($mode) {
            InventorySortService::MODE_NAME  => 'по названию',
            InventorySortService::MODE_QTY   => 'по количеству',
            InventorySortService::MODE_VALUE => 'по стоимости',
            default                          => 'по редкости',
        };
    }

    private function reply(string $text, string $mode): ServerResponse
    {
        $sortRow = [];
        foreach ([
            InventorySortService::MODE_RARITY => '🏷 Редкость',
            InventorySortService::MODE_NAME   => '🔤 Название',
            InventorySortService::MODE_QTY    => '🔢 Кол-во',
            InventorySortService::MODE_VALUE  => '💰 Стоимость',
        ] as $m => $label) {
            $sortRow[] = [
                'text'          => ($m === $mode ? '• ' : '') . $label,
                'callback_data' => "resourcesGathered_sort_{$m}",
            ];
        }

        $keyboard = [
            'inline_keyboard' => [
                $sortRow,
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
                [
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                ],
            ],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): просмотр склада ресурсов — навигация → редактируем
        // сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
