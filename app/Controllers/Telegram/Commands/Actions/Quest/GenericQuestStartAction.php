<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Quest\QuestStartService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-088 — generic-старт STANDALONE расширенных квестов (collect_resource /
 * building_level / npc_kills / char_level / craft_item без prerequisite).
 *
 * Callback prefix `questStart<TitleEn>` (через CallbackPrefixDispatcher; exact-роуты
 * 4 legacy bespoke квестов имеют приоритет). Извлекает title_en из callback_data и рисует
 * результат {@see QuestStartService::start()} (w2-n5-deeds-01, ADR-190): проверки killswitch
 * quests.extended_enabled / квест активен / startable-корень / фракция / уровень / не начат и
 * запись quest_steps под блокировкой строки персонажа — в ядре, общем с вебом.
 * Прогресс/завершение — в QuestObjectiveHandler.
 *
 * Заменяет потребность в per-quest хендлерах для нового контента.
 */
final class GenericQuestStartAction extends BaseAction
{
    private QuestStartService $starter;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->starter = new QuestStartService();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($charId <= 0) {
            return $this->alert('Невозможно определить персонажа.');
        }

        // callback_data: questStart<TitleEn> → title_en.
        $data   = (string) $this->callbackQuery->getData();
        $result = $this->starter->start($charId, substr($data, strlen('questStart')));
        if (! $result['ok']) {
            return $this->alert($result['message']);
        }

        $titleRu     = $result['title_ru'] ?? '';
        $description = $result['description'];
        $reward      = $result['reward'];

        $text  = "🔍 *{$titleRu}*\n\n";
        if ($description !== '') {
            $text .= "📜 {$description}\n\n";
        }
        if ($reward > 0) {
            $text .= "🏆 Награда: *{$reward}* золота\n\n";
        }
        $text .= "🛡️ Отслеживай прогресс в *«🚀 Активные квесты»*.";

        $keyboard = ['inline_keyboard' => [[
            ['text' => '🚀 Активные квесты', 'callback_data' => 'activeQuests'],
            ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
        ]]];

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Квест начат!',
        ]);

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
