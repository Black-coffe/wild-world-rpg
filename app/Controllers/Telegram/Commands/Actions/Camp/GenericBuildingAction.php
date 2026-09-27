<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Buildings\BuildOrderService;
use Config\Buildings;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * F2.1 — generic-handler начала постройки любого здания (`genericStartBuild_<Key>`[`_b<id>`]).
 *
 * w2-n4-base-02 (ADR-190): гейты, списание и задача — ядро {@see BuildOrderService::start()}, общее с
 * вебом; здесь прежние тексты, картинка и запись отказов в `action_log` (`logRejected` — боту нужен чат).
 * Время стройки экран не считает: его даёт ядро через `BuildDurationService` (ADR-160).
 * Ядро списывает материалы условной записью под блокировкой строки персонажа (ADR-181): двойное нажатие
 * при запасе на одну стройку даёт одну задачу и одно списание.
 */
class GenericBuildingAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // 1. ack-callback (убираем «часики»)
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 2. парсим building_key из callback_data — формат: genericStartBuild_Arsenal[_b<id>]
        [$callbackData, $baseId] = BaseCallbackSuffix::split((string) $this->callbackQuery->getData());
        $parts       = explode('_', $callbackData, 2);
        $buildingKey = $parts[1] ?? '';
        if ($buildingKey === '') {
            return $this->sendError('Не указан тип здания.');
        }

        /** @var Buildings $cfg */
        $cfg    = config('Buildings');
        $recipe = $cfg->get($buildingKey);
        if ($recipe === null) {
            return $this->sendError("Неизвестное здание: {$buildingKey}");
        }

        // 3. user/character (общий boilerplate F1.6)
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Персонаж не найден. Попробуйте /start.');
        }

        $result = (new BuildOrderService())->start((int) $character['id'], $baseId, $buildingKey);

        if (! $result['ok']) {
            if (is_array($result['log'])) {
                $this->logRejected((int) $character['id'], $result['log']['action'], $result['log']['reason'], $result['log']['extra']);
            }
            if ($result['code'] === BuildOrderService::LEANTO_GATED) {
                $suffix = static fn (string $cb): string => $baseId !== null ? BaseCallbackSuffix::append($cb, $baseId) : $cb;

                return Request::sendMessage([
                    'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'         => $result['message'],
                    'parse_mode'   => 'Markdown',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [[
                            ['text' => '🏗 Строить', 'callback_data' => $suffix('Build')],
                            ['text' => '🏠 База', 'callback_data' => $suffix('Base')],
                        ]],
                    ]),
                ]);
            }

            return $this->sendError($result['message']);
        }

        // 10. Уведомление. ADR-143: стройка всегда «🏠 Только на базе», занятость — из флага задачи.
        $scope = new \App\Services\Tasks\ActionScopeService();
        $text  = "*Строительство {$recipe['name_rus']} начато!*\n\n"
            . $scope->startedBlock(\App\Services\Tasks\ActionScopeService::KIND_BUILD, $result['background']) . "\n\n"
            . "Длительность: ~{$result['minutes']} мин.\n"
            . "По завершении здание будет добавлено на базу.";

        // building-bonus-absorption story-02, круг 3–4: оговорка про дубль — и здесь, на экране решения
        // (в превью она почти никогда не влезает в 1024). Отдельная мысль — через пустую строку.
        if ($result['duplicate']['owned']) {
            $textWithBreak = $text . "\n";
            $notice        = \App\Services\Buildings\BuildingCopyNotice::duplicateWarningFitting(
                $textWithBreak,
                $recipe['emoji'],
                $recipe['name_rus'],
                $result['duplicate']['stacks_defense']
            );
            if ($notice !== '') {
                $text = $textWithBreak . $notice;
            }
        }

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile(base_url($recipe['image_in_progress'])),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function sendError(string $text): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}
