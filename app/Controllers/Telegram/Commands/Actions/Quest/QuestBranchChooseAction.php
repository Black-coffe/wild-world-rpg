<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Quest\QuestChainService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * W11 (ADR-067) — выбор ветки квест-развилки. Callback prefix `questBranch_<quest_id>`
 * (через CallbackPrefixDispatcher).
 *
 * Игрок жмёт одну из веток развилки → QuestChainService::chooseBranch валидирует
 * (branching on / квест — ветка / prerequisite завершён / сиблинг ещё не выбран) и
 * создаёт quest_steps для выбранной ветки. Остальные ветки группы навсегда закрыты.
 * Выбор необратим.
 */
final class QuestBranchChooseAction extends BaseAction
{
    private QuestChainService $chain;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->chain = new QuestChainService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($charId <= 0) {
            return $this->alert('Невозможно определить персонажа.');
        }

        // Парсим quest_id из callback_data: questBranch_<quest_id>
        $parts   = explode('_', (string) $this->callbackQuery->getData());
        $questId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;
        if ($questId <= 0) {
            return $this->alert('Некорректная ветка развилки.');
        }

        $res = $this->chain->chooseBranch($charId, $questId);
        if ($res['ok'] !== true) {
            $msg = match ($res['reason']) {
                'disabled'       => '🔀 Развилки квестов сейчас недоступны.',
                'already_chosen' => '🔀 Ты уже выбрал путь на этой развилке — назад дороги нет.',
                'prereq_not_met' => '🔀 Эта развилка ещё не открыта.',
                'inactive'       => '🔀 Эта ветка больше недоступна.',
                default          => '🔀 Не удалось выбрать путь. Попробуй из «Доступных квестов».',
            };
            return $this->alert($msg);
        }

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Путь выбран!',
        ]);

        $titleRu = $res['title_ru'] !== '' ? $res['title_ru'] : 'выбранный путь';
        $reward  = $res['reward'];

        $text  = "🔀 *Путь выбран!*\n\n";
        $text .= "✅ Ты пошёл по пути: *{$titleRu}*\n";
        if ($reward > 0) {
            $text .= "🏆 Награда за завершение: *{$reward}* золота\n";
        }
        $text .= "\n_Остальные ветки этой развилки закрыты — на пустоши решения необратимы._\n";
        $text .= "\n🛡️ Отслеживай прогресс в *«🚀 Активные квесты»*.";

        $keyboard = ['inline_keyboard' => [[
            ['text' => '🚀 Активные квесты', 'callback_data' => 'activeQuests'],
            ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
        ]]];

        // edit-in-place (ADR-018): кнопка выбора жила на списке «Доступные квесты» /
        // уведомлении → редактируем то сообщение (fallback на новое при ошибке).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function alert(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);
        return Request::emptyResponse();
    }
}
