<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel; // Модель для работы с ресурсами персонажа
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class CalculateInsuranceAction extends BaseAction
{
    protected $characterModel;
    protected $characterResourceModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->characterResourceModel = new CharacterResourceModel(); // Инициализация модели ресурсов персонажа
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

        // 2. Получаем общее количество ресурсов персонажа
        $totalResources = $this->characterResourceModel->where('id_characters', $character['id'])->countAllResults();

        // 3. Рассчитываем стоимость страховки

        // Получаем необходимые параметры
        $monthsInGame = (new \DateTime($character['created_at']))->diff(new \DateTime())->m + 1; // Месяцы в игре
        $level = $character['level'];
        $experience = $character['experience'];
        $strength = $character['strength'];
        $agility = $character['agility'];
        $intellect = $character['intellect'];
        $tired = $character['tired'];
        $goldInChest = $character['gold'];

        // Используем формулу для расчета страховки
        $insuranceCost = $this->calculateInsuranceCost(
            $totalResources,
            $monthsInGame,
            $level,
            $experience,
            $strength,
            $agility,
            $intellect,
            $tired,
            $goldInChest
        );

        // Проверяем активна ли страховка
        $insuranceStatus = $character['insurance'] ? 'активна' : 'не активна';

        // 4. Формируем текст сообщения
        $messageText = "Статус вашей страховки: *{$insuranceStatus}*\n\n";
        $messageText .= "Если страховой случай произойдет сегодня, примерная стоимость страховки составит: *" . number_format($insuranceCost) . "* золотых монет.";

        // 5. Отправляем сообщение
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧍 Страховка', 'callback_data' => 'PersonalInsurance'],
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ],
            ],
        ];

        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $messageText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function calculateInsuranceCost($totalResources, $monthsInGame, $level, $experience, $strength, $agility, $intellect, $tired, $goldInChest)
    {
        // Рассчитываем стоимость на основе предложенной формулы

        // Стоимость ресурсов
        $resourceCost = ($totalResources / 1000) * 10;

        // Стоимость времени в игре
        $timeCost = ($monthsInGame / 12) * 5;

        // Стоимость уровня
        $levelCost = ($level / 10) * 20;

        // Стоимость опыта
        $experienceCost = ($experience / 10) * 5;

        // Стоимость атрибутов
        $attributesCost = (($strength + $agility + $intellect + $tired) / 100) * 10;

        // Стоимость золота
        $goldCost = ($goldInChest / 10000) * 50;

        // Итоговая стоимость
        $totalCost = $resourceCost + $timeCost + $levelCost + $experienceCost + $attributesCost + $goldCost;

        return round($totalCost);
    }
}
