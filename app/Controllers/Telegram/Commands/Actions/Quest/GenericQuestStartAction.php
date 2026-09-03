<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use App\Services\Notifications\MediaSender;
use App\Services\Quest\QuestChainService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-088 — generic-старт STANDALONE расширенных квестов (collect_resource /
 * building_level / npc_kills / char_level / craft_item без prerequisite).
 *
 * Callback prefix `questStart<TitleEn>` (через CallbackPrefixDispatcher; exact-роуты
 * 4 legacy bespoke квестов имеют приоритет). Извлекает title_en из callback_data,
 * валидирует (killswitch quests.extended_enabled / квест активен / это startable-корень /
 * уровень / не начат) и создаёт quest_steps. Прогресс/завершение — в QuestObjectiveHandler.
 *
 * Заменяет потребность в per-quest хендлерах для нового контента.
 */
final class GenericQuestStartAction extends BaseAction
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

        // callback_data: questStart<TitleEn> → title_en.
        $data    = (string) $this->callbackQuery->getData();
        $titleEn = substr($data, strlen('questStart'));
        if ($titleEn === '') {
            return $this->alert('Некорректный квест.');
        }

        $questModel = new QuestModel();
        $quest      = $questModel->where('title_en', $titleEn)->where('status', 'active')->first();
        if (!is_array($quest)) {
            return $this->alert('Квест не найден или недоступен.');
        }

        // ADR-088: стартовать вручную можно только расширенные startable-«корни»
        // (killswitch + objective задан + не discover_object + без prerequisite).
        if (! $this->chain->isExtendedStartableRoot($quest)) {
            return $this->alert('Этот квест нельзя начать вручную.');
        }

        // ADR-088 Фаза 3: фракционный квест — только для игроков своей фракции
        // (и при killswitch quests.faction_quests_enabled=ON).
        $charFactionId = (new \App\Models\CharacterFactionModel())->getFactionId($charId);
        if (! $this->chain->factionGateOk($quest, $charFactionId)) {
            return $this->alert('Этот квест доступен только членам соответствующей фракции.');
        }

        $charLevel = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 0;
        $minLevel  = is_numeric($quest['min_level'] ?? null) ? (int) $quest['min_level'] : 0;
        if ($charLevel < $minLevel) {
            return $this->alert("Квест доступен с {$minLevel}-го уровня.");
        }

        $questId        = is_numeric($quest['id'] ?? null) ? (int) $quest['id'] : 0;
        $questStepModel = new QuestStepsModel();
        if ($questStepModel->where(['quest_id' => $questId, 'character_id' => $charId])->first()) {
            return $this->alert('Ты уже начал этот квест — смотри «🚀 Активные квесты».');
        }

        // exploit-fix-09 (ADR-181 §5) — предыдущая проверка выше закрывает общий случай
        // быстрым чтением, но не гонку: два почти одновременных тапа оба проходят её ДО
        // первой записи. insertUnique() — вторая, окончательная линия, гасящая гонку в
        // `Refused` вместо второй строки/500 (UNIQUE-индекс на `quest_steps` под этот путь
        // не заведён — story 10 ограничена `resources_bank`, см. plan.md `## Descoped`).
        // exploit-fix-14 — `created_at`/`updated_at` раньше подставляла `QuestStepsModel`
        // (`$useTimestamps`); сырая вставка мимо модели несёт их сама.
        $nowStr  = date('Y-m-d H:i:s');
        $outcome = (new ConditionalWriteService())->insertUnique('quest_steps', [
            'quest_id'     => $questId,
            'character_id' => $charId,
            'step_order'   => 1,
            'description'  => is_string($quest['title_ru'] ?? null) ? $quest['title_ru'] : 'Квест начат',
            'is_completed' => false,
            'created_at'   => $nowStr,
            'updated_at'   => $nowStr,
        ]);
        if ($outcome === WriteOutcome::Refused) {
            return $this->alert('Ты уже начал этот квест — смотри «🚀 Активные квесты».');
        }

        $titleRu     = is_string($quest['title_ru'] ?? null) ? $quest['title_ru'] : $titleEn;
        $description = is_string($quest['description'] ?? null) ? $quest['description'] : '';
        $reward      = is_numeric($quest['reward'] ?? null) ? (int) $quest['reward'] : 0;

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
