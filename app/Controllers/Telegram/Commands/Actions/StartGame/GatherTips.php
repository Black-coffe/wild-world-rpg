<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Exception\TelegramException;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Telegram;
use App\Models\ActionLogModel;

class GatherTips extends Controller
{
    protected $characterModel;
    protected $characterResourceModel;
    protected $mapModel;
    protected $biomeModel;
    protected $resourceModel;
    protected $telegramUserModel;
    private $telegram;
    protected $callbackQuery;
    protected $actionLogModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->characterModel = new CharacterModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->resourceModel = new ResourceModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->callbackQuery = $callbackQuery;
        $this->actionLogModel = new ActionLogModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    public function handle(): \Longman\TelegramBot\Entities\ServerResponse
    {
        $telegramUserId = $this->callbackQuery->getFrom()->getId();
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных',
            ]);
        }

        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character || !$character['cell_number']) {
           return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Персонаж не найден в базе данных',
            ]);
        }

        // Получаем имя класса без полного namespace
        $className = basename(str_replace('\\', '/', get_class($this)));

        // Проверяем, записано ли уже действие как выполненное
        $lastAction = $this->actionLogModel->where([
            'character_id' => $character['id'],
            'action_name' => $className,
            'action_status' => 'Completed'
        ])->first();

        if ($lastAction) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => "Обучение уже пройдено. Переходите к следующему шагу.",
            ]);
        }

        $resources = $this->getAvailableResources($character);
        $foundResources = $this->calculateFoundResources($resources, $character);
        $this->saveFoundResources($foundResources, $character);
        $this->sendResourcesFoundReply($foundResources, $character);

        // Записываем действие как выполненное
        $this->actionLogModel->save([
            'character_id' => $character['id'],
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'action_name' => $className,
            'action_status' => 'Completed',
            'description' => 'User completed training section: ' . $className
        ]);

        $lastAction = $this->actionLogModel->where([
            'character_id' => $character['id'],
            'action_name' => $className,
            'action_status' => 'Completed'
        ])->first();

        if ($lastAction) {
            // ADR-150 Слайс 1: хендофф обучения учит ЖИВОЙ связке ходьбы. В самом
            // туториале «ход» делался одноразовой кнопкой «↗️ Северо-восток», которой в
            // игре нет → выпускник не знал, как ходить (жалоба Пикабу «после обучения
            // непонятно как идти»). Теперь финал даёт кнопку «🧭 Двигаться» (компас) как
            // основную + объясняет путь. media-off-безопасно (весь смысл в тексте).
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🧭 Двигаться', 'callback_data' => 'move'],
                    ],
                    [
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ],
                ]
            ];
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => "🎓 *Обучение закончено!*\n\n"
                    . "Дальше — сам. Чтобы пойти по миру, жми *🧭 Двигаться*: появится "
                    . "компас со стрелками сторон света, и каждый шаг открывает новые клетки.\n\n"
                    . "А все остальные дела — добыть ресурсы, крафт, база, квесты — в "
                    . "*🧑‍🌾 Действия*.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // F1.3 phpstan fix: fallback при отсутствии lastAction. Без этого PHP 8
        // кидает TypeError так как метод декларирует return ServerResponse.
        return Request::emptyResponse();
    }

    protected function getAvailableResources($character)
    {
        // Получаем текущую локацию персонажа
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            log_message('error', "Location for character {$character['id']} not found.");
            return []; // Возвращаем пустой массив, если локация не найдена
        }

        // Получаем биом текущей локации
        $biome = $this->biomeModel->find($currentCell['biome_id']);
        if (!$biome) {
            log_message('error', "Biome for cell {$currentCell['cell_number']} not found.");
            return []; // Возвращаем пустой массив, если биом не найден
        }

        // S7: FIND_IN_SET для exact CSV-item match (раньше like 'both' тянул substring — биом id=1 матчил biome_id='10').
        $biomeId   = (int) $biome['id'];
        $resources = $this->resourceModel
            ->where("FIND_IN_SET({$biomeId}, biome_id) > 0", null, false)
            ->where('level_required <=', $character['level'])
            ->findAll();
        return $resources;
    }

    protected function calculateFoundResources($resources, $character)
    {
        $foundResources = [];

        // ADR-090 «Мягкий старт»: при включённом early-access применяем тот же soft-ramp,
        // что и реальный сбор (GatherTaskHandler) → туториал и реальная добыча консистентны
        // (вода доминирует + базовый спред). При killswitch OFF — прежнее поведение туториала
        // (все level_required<=level), чтобы dormant был no-op.
        $ea      = $this->earlyAccessParams();
        $formula = new \App\Services\Player\Gather\GatherFormulaService();
        $level   = (int) $character['level'];

        foreach ($resources as $resource) {
            if ($this->isResourceCollectible($resource, $character)) {

                $resourceFactor = $this->getResourceFactor($resource);
                $characterFactor = $this->getCharacterFactor($character);

                $totalAmount = round($resourceFactor * $characterFactor);

                if ($ea['enabled']) {
                    $access = $formula->rarityYieldFactor($level, (int) $resource['rarity'], true, $ea['window'], $ea['step']);
                    if ($access <= 0.0) {
                        continue; // вне окна превью — как в реальном сборе
                    }
                    $totalAmount = round($totalAmount * $access);
                }

                if ($totalAmount > 0) {
                    $foundResources[] = [
                        'resource_id' => $resource['id'],
                        'amount' => max(1, $totalAmount)
                    ];
                }
            }
        }

        return $foundResources;
    }

    /**
     * ADR-090 — параметры «мягкого старта» добычи из GameSettings (live-tunable).
     *
     * @return array{enabled:bool, window:int, step:float}
     */
    private function earlyAccessParams(): array
    {
        $gs    = new \App\Services\GameSettings\GameSettingsService();
        $enRaw = $gs->get('gather.early_access_enabled', false);
        $enabled = is_bool($enRaw)
            ? $enRaw
            : (is_numeric($enRaw) ? ((int) $enRaw === 1) : in_array(strtolower((string) $enRaw), ['1', 'true', 'yes', 'on'], true));

        $winRaw  = $gs->get('gather.early_access_window', 2);
        $stepRaw = $gs->get('gather.early_access_step', 0.20);

        return [
            'enabled' => $enabled,
            'window'  => is_numeric($winRaw) ? (int) $winRaw : 2,
            'step'    => is_numeric($stepRaw) ? (float) $stepRaw : 0.20,
        ];
    }

    protected function isResourceCollectible($resource, $character)
    {
        // Код остается без изменений
        return isset($resource['rarity'], $resource['level_required']) &&
            $resource['level_required'] <= $character['level'];
    }

    protected function getResourceFactor($resource)
    {
        // Адаптируем значение редкости в зависимости от заданного диапазона
        if ($resource['rarity'] >= 2 && $resource['rarity'] <= 5) {
            $randomMultiplier1 = rand(101, 299) / 100;
            $resource['rarity'] *= $randomMultiplier1;
        } elseif ($resource['rarity'] >= 6 && $resource['rarity'] <= 9) {
            $randomMultiplier2 = rand(155, 399) / 100;
            $resource['rarity'] *= $randomMultiplier2;
        } elseif ($resource['rarity'] === 10) {
            $randomMultiplier3 = rand(255, 499) / 100;
            $resource['rarity'] *= $randomMultiplier3;
        }

        // Рассчитываем фактор редкости
        $rarityFactor = $resource['rarity'];
        $difficultyLevel = max(1, 5);

        // Рассчитываем корректировку на основе сложности задачи
        $difficultyAdjustment = 1 / (1 + $difficultyLevel / 10);

        // Возвращаем конечный фактор ресурса
        return $rarityFactor * $difficultyAdjustment;
    }

    protected function getCharacterFactor($character)
    {
        return 1 + 0.05 * ($character['strength'] + $character['agility'] + $character['intellect'] + $character['level']);
    }

    // Метод сохранения найденных ресурсов и обновления статистики персонажа
    protected function saveFoundResources($foundResources, $character)
    {
        foreach ($foundResources as $resource) {
            // Проверка на существование ресурса для персонажа
            $existingResource = $this->characterResourceModel->where([
                'id_characters' => $character['id'],
                'id_resources' => $resource['resource_id'],
            ])->first();

            if ($existingResource) {
                $newQuantity = $existingResource['quantity'] + $resource['amount'];
                $this->characterResourceModel->update($existingResource['id'], ['quantity' => $newQuantity]);
            } else {
                $this->characterResourceModel->insert([
                    'id_characters' => $character['id'],
                    'id_resources' => $resource['resource_id'],
                    'id_telegram_users' => $character['telegram_user_id'],
                    'quantity' => $resource['amount'],
                ]);
            }
        }
    }

    // Метод отправки сообщения пользователю о найденных ресурсах и обновлении статистики
    protected function sendResourcesFoundReply($foundResources, $character) {
        $messageText = "*🤖 Поздравляю с первой добычей ресурсов!*\n\n";

        if (!empty($foundResources)) {
            foreach ($foundResources as $resource) {
                $resourceData = $this->resourceModel->find($resource['resource_id']);
                $messageText .= "📦 *{$resourceData['name']}:* {$resource['amount']} единиц\n";
            }
        } else {
            $messageText .= "К сожалению, ресурсы не были найдены.\n";
        }

        // ⚠️ Это caption фото — лимит Telegram 1024 символа (UTF-16, эмодзи = 2). Текст держим коротким.
        $messageText .= "\nЭто первые ресурсы из этого биома. Сейчас добыча — в основном вода и базовые "
            . "материалы; с ростом уровня открываются новые ресурсы (древесина, камень, руды…) и сбор "
            . "становится щедрее. Дальше добыча займёт от 3 до 15 минут. Разведка идёт сама: двигаясь, "
            . "ты открываешь клетки вокруг.\n\n"
            . "🤖 Проверенный метод первой недели:\n"
            . "1️⃣ Двигайся и открывай местность\n"
            . "2️⃣ Постоянно собирай ресурсы\n"
            . "3️⃣ Продвигайся по острову, но не спеши вглубь севера\n"
            . "4️⃣ Изучи возможности крафта\n"
            . "5️⃣ Продавай ненужное, покупай нужное для крафта\n\n"
            . "🌟 На 2–4 уровнях я приду с новыми новостями. 🛠️ Меню — внизу чата. Удачи!";

        // ADR-150 (чистка дублей): новичку нужна ОДНА ясная стрелка, а не три равнозначные.
        $keyboard = \App\Services\Telegram\NavKeyboards::simplified()
            ? \App\Services\Telegram\NavKeyboards::whatNextWith()
            : [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '🎉 События', 'callback_data' => 'events'],
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                    ]
                ]
            ];

        // #12 edit-in-place (ADR-018): результат первой добычи — навигация → редактируем
        // сообщение, на котором нажата кнопка «⛏️ Добыть ресурсы» (fallback на новое).
        // Класс extends Controller → navTarget строим вручную.
        $navTarget = [
            'chat_id'    => (int) $this->callbackQuery->getMessage()->getChat()->getId(),
            'message_id' => (int) $this->callbackQuery->getMessage()->getMessageId(),
        ];
        try {
            return \App\Services\Notifications\MediaSender::editOrSend($navTarget + [
                'photo'   => Request::encodeFile(base_url('uploads/telegram/loot_resources_in_the_box.png')),
                'caption' => $messageText,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Failed to send message: " . $e->getMessage());
        }
    }


}
