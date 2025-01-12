<?php

namespace App\Controllers\Telegram\Commands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Models\GameTipsModel;
use App\Models\CharacterGameTipModel;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;

class TipsCommand extends UserCommand
{
    protected $name = 'tips';
    protected $description = 'Provides a random game tip';
    protected $usage = '/tips';
    protected $version = '1.1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chatId = $message->getChat()->getId();
        $from = $message->getFrom();
        $telegramId = $from->getId();

        $characterGameTipModel = new CharacterGameTipModel();
        $telegramUserModel = new TelegramUserModel();
        $gameTipsModel = new GameTipsModel();
        $characterModel = new CharacterModel();

        $existingUser = $telegramUserModel->where('telegram_id', $telegramId)->first();
        $character = $characterModel->where('telegram_user_id', $existingUser['id'])->first();

        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "Ошибка: персонаж не найден.",
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);
        }

        $currentDate = date('Y-m-d H:i:s');
        $tenDaysAgo = date('Y-m-d H:i:s', strtotime($currentDate . ' -15 days'));

        // Получение ID советов, просмотренных менее 10 дней назад
        $recentlyViewedTips = $characterGameTipModel->select('game_tip_id')
            ->where('character_id', $character['id'])
            ->where('viewed_at >', $tenDaysAgo)
            ->findAll();
        $recentlyViewedTipIds = array_column($recentlyViewedTips, 'game_tip_id');

        // Ищем новый совет, который еще не был просмотрен в последние 10 дней
        $tip = null;
        if (!empty($recentlyViewedTipIds)) {
            $tip = $gameTipsModel->whereNotIn('id', $recentlyViewedTipIds)->orderBy('RAND()')->first();
        } else {
            // Если нет просмотренных советов, просто получаем случайный совет
            $tip = $gameTipsModel->orderBy('RAND()')->first();
        }

        if (!$tip) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "Вы уже просмотрели все доступные советы в последние 15 дней. Пожалуйста, приходите позже.",
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);
        }

        $characterGameTipModel->insert([
            'character_id' => $character['id'],
            'game_tip_id' => $tip['id'],
            'viewed_at' => $currentDate,
        ]);

        // Обновляем характеристики персонажа
        $characterModel->update($character['id'], [
            'experience' => $character['experience'] + 0.05,
            'agility' => $character['agility'] + 0.08,
            'intellect' => $character['intellect'] + 0.12,
        ]);

        $text = "🤖 Это я – *Роби* и мой тебе игровой совет #: _{$tip['id']}_\n\n"
            . "🔈 *Название:* {$tip['title_ru']}\n\n"
            . "➡️ *Направление:* {$tip['tip_type']}\n\n"
            . "📂 *Описание:* {$tip['content']}";

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
}
