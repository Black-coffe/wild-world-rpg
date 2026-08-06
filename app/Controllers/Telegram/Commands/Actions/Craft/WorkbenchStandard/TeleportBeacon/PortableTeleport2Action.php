<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Notifications\MediaSender;
use App\Services\Player\Craft\PortableTeleportRecipe;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Экран требований крафта «📡 Портативный телепорт» (callback `portableTeleport2`).
 *
 * Замыкает цикл предмета `PortableTeleport`: механика применения жила в коде с 2025 года
 * ({@see \App\Services\Player\TeleportUse\TeleportUseValidator::validatePortable}), а взять
 * устройство было негде — рецепта не существовало (0 владельцев на проде).
 *
 * Экран ВСЕГДА рабочий (UX-discoverability): если чего-то не хватает — показываем чего
 * именно и куда идти, а не молчим и не прячем кнопку. Killswitch OFF — тоже объясняем.
 *
 * 🖼 MEDIA-OFF (ADR-020): весь смысл — в тексте; картинка подставляется, только если файл
 * реально лежит на диске (memory `feedback_gear_image_must_resolve_via_is_file`:
 * `Request::encodeFile` на отсутствующем файле роняет экран).
 */
class PortableTeleport2Action extends BaseAction
{
    private const IMAGE_REL = 'uploads/telegram/craft/standard/portable_teleport.jpg';

    // 🔴 Имя `charModel` НЕ повторяет `characterModel` из BaseAction: там свойство без
    // типа, и типизированное переобъявление в наследнике — фатальная ошибка PHP.
    // Модели для циклов создаются на каждой итерации: CI4 builder копит where()
    // (memory feedback_ci4_model_builder_state_quirk).
    protected CharacterModel $charModel;
    protected BuildingModel $buildingModel;
    protected CharacterBuildingModel $characterBuildingModel;
    protected ClaimedCellModel $claimedCellModel;
    protected PortableTeleportRecipe $recipe;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->charModel              = new CharacterModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->recipe                 = new PortableTeleportRecipe();
    }

    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->screen($chatId, 'Пользователь или персонаж не найдены.', $this->fallbackKeyboard());
        }

        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        // Killswitch (ADR-024). Кнопку не прячем — объясняем состояние (UX-discoverability).
        if (! $this->recipe->enabled()) {
            return $this->screen(
                $chatId,
                "*📡 Портативный телепорт*\n\n"
                . "Сборка устройства сейчас закрыта — мастерские не берутся за него.\n\n"
                . "_Возвращайся позже: когда сборку откроют, рецепт появится в этом же разделе._",
                $this->fallbackKeyboard()
            );
        }

        $blocker = $this->prerequisiteError($characterId, $character);
        if ($blocker !== null) {
            return $this->screen($chatId, $blocker, $this->fallbackKeyboard());
        }

        $goldCost   = $this->recipe->goldCost();
        $goldHave   = self::goldOf($this->charModel->find($characterId));
        $lines      = [];
        $canCraft   = true;

        foreach ($this->recipe->resources() as $name => $need) {
            $have = $this->resourceQuantity($characterId, $name);
            $canCraft = $this->appendLine($lines, $name, $have, $need) && $canCraft;
        }
        foreach ($this->recipe->components() as $name => $need) {
            $have = $this->componentQuantity($characterId, $name);
            $canCraft = $this->appendLine($lines, $name, $have, $need) && $canCraft;
        }
        if ($goldHave < $goldCost) {
            $canCraft = false;
            $lines[]  = "• Золото: {$goldCost} ❌ (есть {$goldHave})";
        } else {
            $lines[] = "• Золото: {$goldCost} ✅ (есть {$goldHave})";
        }

        $text = "*📡 Портативный телепорт*\n\n"
            . "Карманное устройство мгновенного возврата: жмёшь — и ты на своей базе, "
            . "из любой точки острова и *без ожидания*.\n\n"
            . "Чем отличается от 🎒 *Рюкзака-телепорта*: рюкзак срабатывает раз в час, "
            . "а портативный — когда угодно, хоть дважды подряд. Плата за это — "
            . "*{$this->recipe->charges()}* зарядов на устройство и цена сборки.\n\n"
            . "_Требования:_\n"
            . "• База и Центр телепортации\n"
            . "• 1-й верстак (Мастерская)\n"
            . "• Уровень персонажа от *{$this->recipe->minLevel()}*\n\n"
            . "*Материалы:*\n"
            . implode("\n", $lines) . "\n\n";

        if ($canCraft) {
            $text .= "*Всё на месте.* Сборка займёт ~{$this->recipe->durationMinutes()} мин "
                . "и идёт в фоне — можешь заниматься своими делами.";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🛠️ Собрать', 'callback_data' => 'startCraftPortableTeleport2']],
                    [
                        ['text' => '🌀 К телепортам', 'callback_data' => 'teleportBeaconCraft2'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ],
            ];
        } else {
            $text .= "_Чего-то не хватает — смотри отметки ❌ выше. Материалы можно добыть "
                . "в пустоши или купить у торговца._";
            $keyboard = $this->fallbackKeyboard();
        }

        return $this->screen($chatId, $text, $keyboard);
    }

    /**
     * Пререквизиты, общие с остальными телепорт-рецептами (база, Центр телепортации,
     * верстак) + уровень. Возвращает готовый текст-объяснение или null, если всё есть.
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     */
    private function prerequisiteError(int $characterId, array|\App\Entities\CharacterEntity $character): ?string
    {
        $hasBase = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();
        if (! $hasBase) {
            return "*📡 Портативный телепорт*\n\n"
                . "Устройство собирают на своей базе, а её у тебя пока нет.\n\n"
                . "Путь: *🏠 База* → *🏕 Разбить лагерь*, затем *🏗 Строить*.";
        }

        if (! $this->ownsBuilding($characterId, 'TeleportationCenter')) {
            return "*📡 Портативный телепорт*\n\n"
                . "Нужен *Центр телепортации* — без него не настроить привязку к базе.\n\n"
                . "Путь: *🏠 База* → *🏗 Строить* → *Центр телепортации*.";
        }

        if (! $this->ownsBuilding($characterId, 'Workshop')) {
            return "*📡 Портативный телепорт*\n\n"
                . "Нужен *1-й верстак (Мастерская)* — тонкую электронику собирают только там.\n\n"
                . "Путь: *🏠 База* → *🏗 Строить* → *Мастерская*.";
        }

        $level    = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 1;
        $minLevel = $this->recipe->minLevel();
        if ($level < $minLevel) {
            return "*📡 Портативный телепорт*\n\n"
                . "🔒 Нужен уровень *{$minLevel}*, у тебя сейчас *{$level}*.\n\n"
                . "Уровень растёт от действий — добыча, разведка, крафт и бой. "
                . "А пока в этом же разделе доступны 🌀 *маяк* и 🎒 *рюкзак-телепорт*.";
        }

        return null;
    }


    /**
     * Золото персонажа из строки `characters`. 🔴 `CharacterModel::find()` возвращает
     * **CharacterEntity**, а не массив (ArrayAccess у неё есть, но `is_array()` — false).
     * Проверка «только is_array» тихо давала 0 золота и роняла крафт в
     * «не хватает материалов» — поймано Tier-3 смоуком на testbot 2026-08-06
     * (memory feedback_entity_strict_array_typehint_trap).
     */
    private static function goldOf(mixed $charRow): int
    {
        if ($charRow instanceof \App\Entities\CharacterEntity) {
            $raw = $charRow->gold;
        } elseif (is_array($charRow)) {
            $raw = $charRow['gold'] ?? null;
        } else {
            return 0;
        }

        return is_numeric($raw) ? (int) $raw : 0;
    }

    private function ownsBuilding(int $characterId, string $nameEn): bool
    {
        $building = $this->buildingModel->where('name_en', $nameEn)->first();
        $idRaw    = is_array($building) ? ($building['id'] ?? null) : null;
        if (! is_numeric($idRaw)) {
            // Здания нет в справочнике — не блокируем игрока техническим сбоем.
            return true;
        }

        return $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', (int) $idRaw)
            ->first() !== null;
    }

    /**
     * @param list<string> $lines
     */
    private function appendLine(array &$lines, string $name, int $have, int $need): bool
    {
        if ($have < $need) {
            $lines[] = "• {$name} x {$need} ❌ (есть {$have})";

            return false;
        }
        $lines[] = "• {$name} x {$need} ✅ (есть {$have})";

        return true;
    }

    private function resourceQuantity(int $characterId, string $name): int
    {
        // Свежий инстанс: CI4 builder копит where() между вызовами в цикле
        // (memory feedback_ci4_model_builder_state_quirk).
        $row = (new CharacterResourceModel())->getResourceByNameAndCharacterId($name, $characterId);
        $qty = is_array($row) ? ($row['quantity'] ?? 0) : 0;

        return is_numeric($qty) ? (int) $qty : 0;
    }

    private function componentQuantity(int $characterId, string $name): int
    {
        $item  = (new CraftedItemsModel())->getCraftedItemByName($name);
        $idRaw = is_array($item) ? ($item['id'] ?? null) : null;
        if (! is_numeric($idRaw)) {
            return 0;
        }

        $log = (new CraftedItemsLogModel())
            ->where('crafted_item_id', (int) $idRaw)
            ->where('character_id', $characterId)
            ->first();
        $qty = is_array($log) ? ($log['quantity'] ?? 0) : 0;

        return is_numeric($qty) ? (int) $qty : 0;
    }

    /** @return array{inline_keyboard: list<list<array{text:string, callback_data:string}>>} */
    private function fallbackKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🌀 К телепортам', 'callback_data' => 'teleportBeaconCraft2'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
                [
                    ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                ],
            ],
        ];
    }

    /**
     * Единая отправка экрана: фото — только если файл реально есть, иначе чистый текст.
     *
     * @param array<string,mixed> $keyboard
     */
    private function screen(int|string $chatId, string $text, array $keyboard): ServerResponse
    {
        $payload = $this->navTarget() + [
            'chat_id'      => $chatId,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ];

        if (defined('FCPATH') && is_file(FCPATH . self::IMAGE_REL)) {
            return MediaSender::editOrSend($payload + [
                'photo'   => Request::encodeFile(FCPATH . self::IMAGE_REL),
                'caption' => $text,
            ]);
        }

        return MediaSender::editTextOrSend($payload + ['text' => $text]);
    }
}
