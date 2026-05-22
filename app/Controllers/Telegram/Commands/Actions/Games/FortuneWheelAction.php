<?php

namespace App\Controllers\Telegram\Commands\Actions\Games;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * 🛞 Колесо фортуны — азартная игра на золото с наградой ресурсами (N5, ADR-040).
 *
 * Весь баланс live-tunable через GameSettings (economy.wheel.*): killswitch, шанс
 * выигрыша и количество ресурса на каждую ставку. Caption печатает реальный шанс (%) и
 * количество → текст не расходится с механикой (раньше обещал «x5/x8», код давал 6/10).
 * Игра НЕ трогает навыки (ADR-040 решение 3). Удалён `sleep(2)`, блокировавший PHP-воркер
 * (🔴 #5 backlog). Золото списывается только при проигрыше; вход требует золота на ставку.
 */
class FortuneWheelAction extends BaseAction
{
    use GameSettingsReaderTrait;

    protected $characterModel;
    protected $resourceModel;
    protected $characterResourceModel;

    /** @var array<int, float> */
    private const DEFAULT_WIN_CHANCE = [1 => 0.50, 5 => 0.35, 10 => 0.15, 50 => 0.07];
    /** @var array<int, int> */
    private const DEFAULT_QUANTITY   = [1 => 1, 5 => 3, 10 => 6, 50 => 10];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->resourceModel = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        if (!$this->gsBool('economy.wheel.enabled', true)) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Игра «Колесо фортуны» временно недоступна.',
            ]);
        }

        if ((int) ($character['gold'] ?? 0) < 1) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'К сожалению у вас недостаточно золотых монет для игры!',
            ]);
        }

        $callbackData = $this->callbackQuery->getData();
        $params = explode('_', $callbackData);

        // Если дополнительных параметров нет, показываем стартовое меню или экран
        if (count($params) == 1) {
            return $this->showStartScreen($character);
        }

        // Обработка кручения колеса
        if (isset($params[1]) && $params[1] === 'spin') {
            return $this->handleSpinWheel($character, $params);
        }

        return Request::emptyResponse();
    }

    protected function showStartScreen($character)
    {
        $p1 = (int) round($this->winChance(1) * 100);
        $p5 = (int) round($this->winChance(5) * 100);
        $p10 = (int) round($this->winChance(10) * 100);
        $p50 = (int) round($this->winChance(50) * 100);

        $q1 = $this->quantity(1);
        $q5 = $this->quantity(5);
        $q10 = $this->quantity(10);
        $q50 = $this->quantity(50);

        $text = "*Готов испытать свою удачу, путник?* 😈\n\n"
            . "*Ставь золото и крути колесо Фортуны!* 🎡\n"
            . "Повезёт — заберёшь ресурсы; нет — потеряешь ставку.\n\n"
            . "💰 1 монета — шанс *{$p1}%*, массовые ресурсы *×{$q1}* 📦\n"
            . "💰 5 монет — шанс *{$p5}%*, частые ресурсы *×{$q5}* 📦\n"
            . "💰 10 монет — шанс *{$p10}%*, редкие ресурсы *×{$q10}* 📦\n"
            . "💰 50 монет — шанс *{$p50}%*, уникальные ресурсы *×{$q50}* 📦\n\n"
            . "*Крутани колесо и покори Фортуну!* ✊\n";

        $keyboardButtons = [
            ['text' => "1 монета", 'callback_data' => "WheelOfFortune_spin_1"],
            ['text' => "5 монет", 'callback_data' => "WheelOfFortune_spin_5"],
            ['text' => "10 монет", 'callback_data' => "WheelOfFortune_spin_10"],
            ['text' => "50 монет", 'callback_data' => "WheelOfFortune_spin_50"],
        ];

        $keyboard = ['inline_keyboard' => array_chunk($keyboardButtons, 2)];
        $imagePath = base_url('uploads/telegram/wheel_fortune_game.png');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function handleSpinWheel($character, $params)
    {
        if (count($params) < 3) {
            return Request::emptyResponse();
        }

        $bet = (int) $params[2];
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // Per-bet affordability: для ставки нужно иметь это золото.
        if ((int) ($character['gold'] ?? 0) < $bet) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "У вас недостаточно золота для ставки {$bet}.",
            ]);
        }

        $result = $this->spinWheel($character, $bet);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $result['message'],
            'reply_markup' => json_encode(['inline_keyboard' => [[
                ['text' => 'Крутить ещё раз', 'callback_data' => "WheelOfFortune_spin_{$bet}"],
                ['text' => 'Завершить игру', 'callback_data' => "character"]
            ]]]),
        ]);
    }

    /**
     * @return array{message: string}
     */
    protected function spinWheel($character, $bet): array
    {
        $win = rand(0, 99) < ($this->winChance($bet) * 100);

        if ($win) {
            $resources = $this->resourceModel->where('rarity', $this->getRarityForBet($bet))->findAll();
            if (empty($resources)) {
                // Награды этой редкости нет в БД — ставку не списываем (не наказываем за наш пробел).
                return ['message' => 'Колесо остановилось на пустом секторе. Ставка не списана, попробуй ещё!'];
            }
            $randomResource = $resources[array_rand($resources)];
            $quantity = $this->quantity($bet);

            $this->addOrUpdateCharacterResource($character, $randomResource['id'], $quantity);
            return ['message' => "Поздравляем! Вы выиграли {$quantity} шт. ресурса «{$randomResource['name']}»!"];
        }

        // Проигрыш: списываем ставку (без изменения навыков).
        $this->subtractGoldFromCharacter($character['id'], $bet);
        return ['message' => "К сожалению, вы проиграли. С вашего счёта снято {$bet} монет."];
    }

    /**
     * Шанс выигрыша (0..1) для ставки — из GameSettings с дефолтом.
     */
    protected function winChance(int $bet): float
    {
        $default = self::DEFAULT_WIN_CHANCE[$bet] ?? 0.25;
        return $this->gsFloat('economy.wheel.win_chance.bet_' . $bet, $default);
    }

    /**
     * Количество ресурса на выигрыш для ставки — из GameSettings с дефолтом.
     */
    protected function quantity(int $bet): int
    {
        $default = self::DEFAULT_QUANTITY[$bet] ?? 2;
        return $this->gsInt('economy.wheel.quantity.bet_' . $bet, $default);
    }

    protected function addOrUpdateCharacterResource($character, $resourceId, $quantity)
    {
        $existingResource = $this->characterResourceModel->where([
            'id_characters' => $character['id'],
            'id_resources' => $resourceId
        ])->first();

        if ($existingResource) {
            $newQuantity = $existingResource['quantity'] + $quantity;
            $this->characterResourceModel->update($existingResource['id'], ['quantity' => $newQuantity]);
        } else {
            $this->characterResourceModel->insert([
                'id_characters' => $character['id'],
                'id_resources' => $resourceId,
                'id_telegram_users' => $character['telegram_user_id'],
                'quantity' => $quantity
            ]);
        }
    }

    protected function subtractGoldFromCharacter($characterId, $amount)
    {
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            return;
        }

        $newGoldAmount = max(0, (int) ($character['gold'] ?? 0) - (int) $amount);
        $this->characterModel->update($characterId, ['gold' => $newGoldAmount]);
    }

    /**
     * Маппинг ставки → редкость лута (структурный «какой тир выпадает», не balance-число;
     * deferred из GameSettings в ADR-040 из-за rand-диапазонов).
     */
    protected function getRarityForBet($bet)
    {
        switch ((int) $bet) {
            case 1:
                return 10;
            case 5:
                return rand(5, 9);
            case 10:
                return rand(3, 4);
            case 50:
                return 2;
            default:
                return 10;
        }
    }
}
