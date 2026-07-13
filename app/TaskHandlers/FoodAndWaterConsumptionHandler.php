<?php

namespace App\TaskHandlers;

use App\Attributes\HandlerKey;
use App\Models\CharacterResourceModel;
use App\Models\CharacterModel;
use App\Models\BiomeModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use Config\GameBalance;

/**
 * v0.51.37 (F2.9 batch-1 expansion): extends BaseTaskHandler. Раніше extends
 * нічого + manual `private $telegram = new Telegram()` init у constructor —
 * dead pattern після F2.9 series (BaseTaskHandler::telegram() lazy-init).
 *
 * Зміни:
 *  - extends BaseTaskHandler (lazy Telegram через telegram() / safeSendPhoto).
 *  - Drop manual Telegram init у ctor.
 *  - Drop unused imports (TelegramException, Telegram, Request).
 *  - process() → handle(array $task = []): void (TaskHandlerInterface signature).
 *  - Drop **broken** `Request::answerCallbackQuery(['callback_query_id' => $chatId])`
 *    у subtractHealth + sendMessageToTelegram — wrong arg semantics (chat_id passed
 *    as callback_query_id). Fires daily silently без value (handler runs from cron,
 *    not callback context). Зайвий API call → noise.
 *  - `\App\Services\Notifications\MediaSender::sendPhotoOrText([...])` → `$this->safeSendPhoto($chatId, $img, $caption, ...)`
 *    (catches TelegramException, не падає на rate-limit).
 */
#[HandlerKey(
    key: 'food_water_consumption',
    displayName: 'Голод/жажда (раз в сутки)',
    description: 'Recurring (Tasks.php daily 21:33): списывает еду/воду у всех chars, дамажит HP при отсутствии. Внутри handler — guard на 21:33.',
)]
class FoodAndWaterConsumptionHandler extends BaseTaskHandler
{
    protected $characterModel;
    protected $biomeModel;
    protected $mapModel;
    protected $resourceModel;
    protected $characterResourceModel;
    protected $telegramUserModel;
    private GameBalance $cfg;

    /**
     * F2.10 wire-in: $cfg инжектируется опционально (для тестов), по умолчанию
     * читается из config('GameBalance') — централизованный balance config.
     */
    public function __construct(?GameBalance $cfg = null)
    {
        $this->cfg                    = $cfg ?? config('GameBalance');
        $this->characterModel = new CharacterModel();
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->resourceModel = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->telegramUserModel = new TelegramUserModel();
    }

    /**
     * @param array<string,mixed> $task TaskHandlerInterface signature (recurring tasks
     *                                  не приймають task data).
     */
    public function handle(array $task = []): void
    {
        // Получаем текущее время
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        // Устанавливаем часовой пояс, который соответствует времени в Киеве
        $now->setTimezone(new \DateTimeZone('Europe/Kiev'));

        // Получаем текущий час
        $currentHour = (int)$now->format('H');

        // Получаем текущую минуту
        $currentMinute = (int)$now->format('i');

        // Проверяем, соответствует ли текущее время 21:33 по Киеву
        if ($currentHour === 21 && $currentMinute === 33) {
            // ПОМЕНЯТЬ ПОТОМ УРОВЕНЬ НА 3 с 300
            $characters = $this->characterModel->where('level >=', 3)->findAll();

            foreach ($characters as $character) {
                $telegramId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
                $mapRow = $this->mapModel->where('cell_number', $character['cell_number'])->first();
                if (!$mapRow) {
                    continue; // Пропускаем обработку персонажа, если не найдена строка карты
                }

                $biome = $this->biomeModel->where('id', $mapRow['biome_id'])->first();
                if (!$biome) {
                    continue; // Пропускаем обработку персонажа, если биом не найден
                }

                // Расчет ресурсов еды и воды, которые нужно потребить (mealsPerDay = 3 default)
                $foodToConsume = $this->calculateResourceConsumption($character['level'], $biome['survival_difficulty'], 'food') * $this->cfg->mealsPerDay;
                $waterToConsume = $this->calculateResourceConsumption($character['level'], $biome['survival_difficulty'], 'water') * $this->cfg->mealsPerDay;

                // Вычитание ресурсов и отправка сообщения
                $result = $this->subtractResources($character, $foodToConsume, $waterToConsume);

                if (!$result['healthSubtracted']) {
                    // Используйте $result['totalFoodResources'] и $result['totalWaterResources']
                    // для отображения остатков в сообщении
                    $this->sendMessageToTelegram(
                        $telegramId['telegram_id'],
                        $foodToConsume,
                        $waterToConsume,
                        $result['totalFoodResources'],
                        $result['totalWaterResources']
                    );
                }
            }
        }
    }
    private function calculateTotalResources($characterId)
    {
        $resources = $this->resourceModel->getCharacterResources($characterId);
        $totalFoodResources = 0;
        $totalWaterResources = 0;

        foreach ($resources as $resource) {
            $types = explode(',', $resource['type']);
            if ($resource['type'] === 'food' || in_array('food', $types)) {
                $totalFoodResources += $resource['quantity'];
            } elseif ($resource['type'] === 'water' || in_array('water', $types)) {
                $totalWaterResources += $resource['quantity'];
            }
        }

        return [$totalFoodResources, $totalWaterResources];
    }

    private function subtractResources($character, $foodToConsume, $waterToConsume)
    {
        $characterId = $character['id'];
        $health = $character['health'];
        $resources = $this->resourceModel->getCharacterResources($characterId);
        $totalFoodResources = 0;
        $totalWaterResources = 0;
        $healthSubtracted = false; // Флаг для отслеживания, было ли списание здоровья

        if (!empty($resources)) {
            usort($resources, function($a, $b) {
                return $b['rarity'] <=> $a['rarity'];
            });

            $foodConsumed = 0;
            $waterConsumed = 0;

            // Инициализация переменных для отслеживания нехватки ресурсов
            $missingFood = $foodToConsume;
            $missingWater = $waterToConsume;
            foreach ($resources as $resource) {
                // v0.51.36 hotfix: ResourceModel::getCharacterResources повертає тепер
                // list<ResourceEntity> (F1.4.2). array_key_exists() приймає лише array —
                // fatal на проді daily 21:33. isset() працює через ArrayAccessibleEntity.
                if (isset($resource['quantity'])) {
                    $types = explode(',', $resource['type']);

                    // Проверяем, есть ли у персонажа достаточно еды для потребления
                    if ($foodConsumed < $foodToConsume && $resource['quantity'] > 0 && (in_array('food', $types) || $resource['type'] == 'food')) {
                        $consumption = min($resource['quantity'], $foodToConsume - $foodConsumed);
                        $this->resourceModel->subtractResourceQuantity($characterId, $resource['id'], $consumption);
                        $foodConsumed += $consumption;
                        $totalFoodResources += $consumption;
                        $missingFood -= $consumption;
                    }

                    // Проверяем, есть ли у персонажа достаточно воды для потребления
                    if ($waterConsumed < $waterToConsume && $resource['quantity'] > 0 && (in_array('water', $types) || $resource['type'] == 'water')) {
                        $consumption = min($resource['quantity'], $waterToConsume - $waterConsumed);
                        $this->resourceModel->subtractResourceQuantity($characterId, $resource['id'], $consumption);
                        $waterConsumed += $consumption;
                        $totalWaterResources += $consumption;
                        $missingWater -= $consumption;
                    }

                    // Проверяем, достигнуты ли условия для завершения цикла
                    if ($foodConsumed >= $foodToConsume && $waterConsumed >= $waterToConsume) {
                        break;
                    }
                } else {
                    log_message('error', 'Key "quantity" is missing in resource array');
                }
            }

            // Проверка на недостаток ресурсов и списание здоровья
            if (($foodConsumed < $foodToConsume || $waterConsumed < $waterToConsume) && $health > 0.02) {
                $healthToSubtract = max(0.01, $health * 0.5);
                $this->subtractHealth($character, $healthToSubtract, max(0, $missingFood), max(0, $missingWater));
                $healthSubtracted = true; // Устанавливаем флаг, что здоровье было списано
            }
        } else {
            // F1.4-postmortem (2026-05-05): character має 0 рядків у character_resources —
            // повний голод. Це нормальна ситуація (новачки після /start, або старі гравці
            // які витратили все), НЕ помилка → log_message прибраний (раніше створював
            // recurring noise: 7 chars × 1 ERROR/день у логах 34+ днів поспіль).
            //
            // Game-design fix: chars з 0 inventory тепер ОТРИМУЮТЬ health damage (раніше
            // мали "free pass" — несправедливо vs chars з 1 одиницею food/water).
            // Захист нових гравців: skip damage якщо level < 5 АБО створений < 24h тому
            // (UX grace period — нові юзери не встигли зрозуміти як збирати ресурси).
            $createdAt = $character['created_at'] ?? null;
            $isWithin24h = is_string($createdAt) && strtotime($createdAt) > time() - 86400;
            $isNewPlayer = ((int) $character['level'] < 5) || $isWithin24h;

            if (!$isNewPlayer && $health > 0.02) {
                $healthToSubtract = max(0.01, $health * 0.5);
                $this->subtractHealth($character, $healthToSubtract, $foodToConsume, $waterToConsume);
                $healthSubtracted = true;
            }
        }
        // После вычитания ресурсов вызовите метод для проверки и удаления ресурса при необходимости
        $this->deleteResourceIfNeeded($characterId);
        // Возвращает актуальные остатки после вычитания
        list($totalFoodResources, $totalWaterResources) = $this->calculateTotalResources($characterId);
        // Возвращает массив с информацией о списании ресурсов и здоровья
        return [
            'healthSubtracted' => $healthSubtracted,
            'totalFoodResources' => $totalFoodResources,
            'totalWaterResources' => $totalWaterResources
        ];
    }


    private function calculateResourceConsumption($level, $survivalDifficulty, $resourceType)
    {
        $resourceToConsume = ($level * $survivalDifficulty) / 10;

        switch ($resourceType) {
            case 'food':
                $resourceToConsume *= $this->cfg->foodMultiplier;
                break;
            case 'water':
                $resourceToConsume *= $this->cfg->waterMultiplier;
                break;
        }

        return $resourceToConsume;
    }


    private function subtractHealth($character, $healthToSubtract, $missingFood = 0, $missingWater = 0)
    {
        $telegramId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
        // Fix 2026-07-13 (класс lost-update): делим СВЕЖЕЕ здоровье под row-lock'ом
        // (CharacterStatsService::mutate) — только что применённый препарат/бой не
        // затирается снапшотом крона. Floor 0.01 сохранён (голод не убивает).
        $divisor  = $this->cfg->hungerHealthPenaltyDivisor;
        $adjusted = (new \App\Services\Player\CharacterStatsService())->mutate(
            (int) $character['id'],
            static fn (array $stats): array => ['health' => max(0.01, $stats['health'] / $divisor)]
        );
        $newHealth = $adjusted !== null ? ($adjusted['after']['health'] ?? 0.01) : 0.01;

        // Добавляем информацию о недостатке ресурсов в текст сообщения
        $resourceMessage = '';
        if ($missingFood > 0) {
            $resourceMessage .= "🍔 __Недостаточно еды:__ " . number_format($missingFood, 2) . " единиц.\n";
        }
        if ($missingWater > 0) {
            $resourceMessage .= "💧 __Недостаточно воды:__ " . number_format($missingWater, 2) . " единиц.\n";
        }

        $text = "👤 *Персонаж теряет здоровье!* 💔\n\n"
            . "*У вас недостаточно еды или воды*\n\n"
            . $resourceMessage // Вставляем информацию о недостатке ресурсов
            . "*Здоровье персонажа просело на:* {$healthToSubtract}%\n\n"
            . "*💖 Текущее здоровье:* {$newHealth}\n\n"
            . "*Срочно пополните запасы*\n\n"
            . "*P.S.*: 👀\nТебе нужно выйти за ресурсами 🗺 \nили купить еды у торговца! 🛒\n\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⛏️ Добыть ресурсы', 'callback_data' => 'gather'],
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/water_and_food_resources.png');

        $this->safeSendPhoto(
            $telegramId,
            $imagePath,
            $text,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }

    public function sendMessageToTelegram($telegramId, $foodToConsume, $waterToConsume, $totalFoodResources, $totalWaterResources): void
    {
        $text = "👤 *Персонаж отлично покушал!* 😋\n\n"
            . "*Съел и Выпил (завтрак, обед, ужин):*\n\n"
            . "*🍔 Еды:* " . number_format($foodToConsume, 2) . " 🍖\n\n"
            . "*💧 Воды:* " . number_format($waterToConsume, 2) . " 💦\n\n"
            . "*Осталось ресурсов пропитания:*\n\n"
            . "🔹*Еды:* " . number_format($totalFoodResources) . " 🌾\n"
            . "🔹*Воды:* " . number_format($totalWaterResources) . " 💧\n\n"
            . "*P.S.*: 👀 Не забудь пополнить запасы провизии! 😉\n\n";

        // ADR-150 (чистка дублей): суп дублировал нижнюю панель → канонический «что дальше».
        $keyboard = \App\Services\Telegram\NavKeyboards::simplified()
            ? \App\Services\Telegram\NavKeyboards::whatNextWith()
            : [
                'inline_keyboard' => [
                    [
                        ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                        ['text' => '🎉 События', 'callback_data' => 'events']
                    ],
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ]
                ]
            ];
        $imagePath = base_url('uploads/telegram/water_and_food_resources.png');

        $this->safeSendPhoto(
            $telegramId,
            $imagePath,
            $text,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }
    private function deleteResourceIfNeeded($characterId)
    {
        // Получите список ресурсов у персонажа
        $resources = $this->resourceModel->getCharacterResources($characterId);

        foreach ($resources as $resource) {
            // Проверьте, равно ли количество ресурса нулю
            if ($resource['quantity'] <= 0) {
                $this->characterResourceModel->delete($resource['charResId']);
            }
        }
    }

}