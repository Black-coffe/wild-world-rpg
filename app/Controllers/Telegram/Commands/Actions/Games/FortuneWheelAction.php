<?php

namespace App\Controllers\Telegram\Commands\Actions\Games;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class FortuneWheelAction extends BaseAction
{
    protected $characterModel;
    protected $resourceModel;
    protected $characterResourceModel;

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

        if ($character['gold'] < 1 or $character['gold'] === null) {
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

        if (isset($params[2]) && $params[2] === 'confirm') {
            $bet = $params[1];
            // Здесь логика подтверждения ставки и переход к кручению колеса или другому действию
        }

        return Request::emptyResponse();
    }

    protected function showStartScreen($character)
    {
        $text = "*Готов испытать свою удачу, путник?* 😈\n\n"
            . "*Фортуна твоего колеса* уже ждёт! 🎡\n\n"
            . "*Испытай свою смекалку и вдохновение, сражаясь с капризной Фортуной!* 🤑\n\n"
            . "💰 1 монета - шанс на *массовые ресурсы* (x1) 📦\n"
            . "💰 5 монет - шанс на *частые ресурсы* (x3) 📦\n"
            . "💰 10 монет - шанс на *редкие ресурсы* (x5) 📦\n"
            . "💰 50 монет - шанс на *уникальные ресурсы* (x8) 📦\n\n"
            . "*Крутани колесо и покори Фортуну!* ✊\n\n"
            . "*P.S.* _И нет, это не лагание, это ожидание пока твое колесо фортуны остановится_ 😜\n";

        $keyboardButtons = [
            ['text' => "1 монета", 'callback_data' => "WheelOfFortune_spin_1"],
            ['text' => "5 монет", 'callback_data' => "WheelOfFortune_spin_5"],
            ['text' => "10 монет", 'callback_data' => "WheelOfFortune_spin_10"],
            ['text' => "50 монет", 'callback_data' => "WheelOfFortune_spin_50"],
        ];

        $keyboard = ['inline_keyboard' => array_chunk($keyboardButtons, 2)];
        $imagePath = base_url('uploads/telegram/wheel_fortune_game.png'); // Укажите актуальный путь к изображению
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

        $bet = $params[2];
        $result = $this->spinWheel($character, $bet);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $result['message'],
            'reply_markup' => json_encode(['inline_keyboard' => [[
                ['text' => 'Крутить ещё раз', 'callback_data' => "WheelOfFortune_spin_{$bet}"],
                ['text' => 'Завершить игру', 'callback_data' => "character"]
            ]]]),
        ]);
    }

    protected function spinWheel($character, $bet)
    {
        sleep(2);
        // Получаем вероятность выигрыша, зависящую от ставки
        $winChance = $this->getWinChanceForBet($bet);
        $win = rand(0, 99) < ($winChance * 100);

        if ($win) {
            // Выбор ресурса для награды
            $resources = $this->resourceModel->where('rarity', $this->getRarityForBet($bet))->findAll();
            $randomResource = $resources[array_rand($resources)];
            $quantity = $this->getQuantityForBet($bet);

            // Добавление ресурса к персонажу
            $this->addOrUpdateCharacterResource($character, $randomResource['id'], $quantity);
            // Обновляем характеристики персонажа
            $this->characterModel->update($character['id'], [
                'experience' => $character['experience'] + 0.01,
                'agility' => $character['agility'] + 0.02,
                'intellect' => $character['intellect'] + 0.03,
            ]);
            $message = "Поздравляем! Вы выиграли {$quantity} штук ресурса '{$randomResource['name']}'!";
        } else {
            // Проигрыш: списывание золота и незначительное снижение характеристик
            $this->subtractGoldFromCharacter($character['id'], $bet);
            $this->characterModel->update($character['id'], [
                'experience' => $character['experience'] - 0.01,
                'agility' => $character['agility'] - 0.02,
                'intellect' => $character['intellect'] - 0.03,
            ]);
            $message = "К сожалению, вы проиграли. С вашего счета снято {$bet} монет.";
        }

        return ['message' => $message];
    }

    /**
     * Возвращает вероятность выигрыша в зависимости от ставки.
     *
     * @param mixed $bet Ставка, передаваемая как строка или число.
     * @return float Вероятность выигрыша (от 0 до 1).
     */
    protected function getWinChanceForBet($bet): float
    {
        $bet = (int) $bet;
        switch ($bet) {
            case 1:
                return 0.50;
            case 5:
                return 0.35;
            case 10:
                return 0.15;
            case 50:
                return 0.07;
            default:
                return 0.25; // Безопасное значение по умолчанию
        }
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

        $newGoldAmount = max(0, $character['gold'] - $amount);
        $this->characterModel->update($characterId, ['gold' => $newGoldAmount]);
    }

    protected function getRarityForBet($bet)
    {
        switch ($bet) {
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

    protected function getQuantityForBet($bet)
    {
        switch ($bet) {
            case 1:
                return 1;
            case 5:
                return 3;
            case 10:
                return 6;
            case 50:
                return 10;
            default:
                return 2;
        }
    }
}
