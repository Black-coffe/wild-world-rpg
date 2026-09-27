<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Buildings\BuildingUpgradeService;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeMessageFormatter;
use App\Services\Bases\BaseCallbackSuffix;

/**
 * Двухэтапный апгрейд здания:
 *   1) askForUpgrade()  -> Показываем требования, кнопку «Подтвердить»
 *   2) confirmUpgrade() -> Если подтверждено, списываем ресурсы и повышаем уровень
 *
 * w2-n4-base-02 (ADR-190): проверка, атомарное применение и хуки после апгрейда — ядро
 * {@see BuildingUpgradeService}, общее с вебом; здесь прежние тексты и кнопки.
 */
class UpgradeBuildingAction extends BaseAction
{
    protected BuildingUpgradeMessageFormatter $formatter;
    protected BuildingUpgradeService $upgrades;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->formatter = new BuildingUpgradeMessageFormatter();
        $this->upgrades  = new BuildingUpgradeService();
    }

    /**
     * Helper (v0.51.58): send message via Request::sendMessage з payload-array.
     *
     * @param array<string,mixed> $payload
     */
    private function send(int|string $chatId, array $payload): ServerResponse
    {
        return Request::sendMessage(array_merge(['chat_id' => $chatId], $payload));
    }

    /**
     * Обязательный метод из BaseAction — по умолчанию «шаг 1».
     */
    public function handle(): ServerResponse
    {
        return $this->askForUpgrade();
    }

    /**
     * Шаг 1: проверяем возможность апгрейда и предлагаем подтверждение (кнопка).
     */
    public function askForUpgrade(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return $this->send($chatId, $this->formatter->userOrCharacterNotFound());
        }

        // Active relocation block (handled у service зі своїм response)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // Parse buildingId з callback_data ("upgrade_building_4" или "upgrade_building_4_b345")
        [$withoutSuffix, $baseId] = BaseCallbackSuffix::split((string) $this->callbackQuery->getData());
        $parts      = explode('_', $withoutSuffix);
        $buildingId = $parts[2] ?? null;
        if (!$buildingId) {
            return $this->send($chatId, $this->formatter->buildingIdMissingAsk());
        }

        // story multibase-picker-03: суффикс `_b<baseId>` — заново проверенный выбор базы (в ядре).
        $res = $this->upgrades->preview((int) $character['id'], $baseId, (int) $buildingId);
        if (!$res['ok']) {
            if ($res['code'] === BuildingUpgradeService::MISSING) {
                return $this->send($chatId, $this->formatter->missingResourcesAsk($res['next_level'], $res['missing']));
            }
            return $this->send($chatId, $this->formatter->simpleError($res['message']));
        }

        $req = $res['requirements'];

        // Скрываем "загрузка" (answerCallbackQuery)
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Проверка завершена.',
            'show_alert'        => false,
        ]);

        return $this->send($chatId, $this->formatter->askPrompt(
            (int) $buildingId,
            $res['name'] ?? "ID={$buildingId}",
            $res['current_level'],
            $res['level'],
            (int) $req['level'],
            (int) $req['gold'],
            $req['resources'],
            $res['character'],
            $baseId
        ));
    }

    /**
     * Шаг 2: пользователь подтвердил апгрейд (callback_data: "confirm_upgrade_building_X")
     */
    public function confirmUpgrade(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return $this->send($chatId, $this->formatter->userOrCharacterNotFound());
        }

        // Parse buildingId з callback_data: "confirm_upgrade_building_4" (или "..._b345")
        [$withoutSuffix, $baseId] = BaseCallbackSuffix::split((string) $this->callbackQuery->getData());
        $parts      = explode('_', $withoutSuffix);
        $buildingId = $parts[3] ?? null;
        if (!$buildingId) {
            return $this->send($chatId, $this->formatter->buildingIdMissingConfirm());
        }

        // Ядро перепроверяет всё (запас мог измениться после шага 1) и применяет атомарно.
        $res = $this->upgrades->apply((int) $character['id'], $baseId, (int) $buildingId);
        if (!$res['ok']) {
            if ($res['code'] === BuildingUpgradeService::MISSING) {
                return $this->send($chatId, $this->formatter->missingResourcesConfirm($res['missing'][0]));
            }
            if ($res['code'] === BuildingUpgradeService::RACE) {
                Request::answerCallbackQuery([
                    'callback_query_id' => $this->callbackQuery->getId(),
                    'text'              => BuildingUpgradeService::TEXT_RACE,
                    'show_alert'        => false,
                ]);
            }
            return $this->send($chatId, $this->formatter->simpleError($res['message']));
        }

        // Скрываем alert у кнопки
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Улучшение выполнено!',
            'show_alert'        => false,
        ]);

        return $this->send($chatId, $this->formatter->upgradeSuccess(
            $res['name'] ?? "Здание #{$buildingId}",
            $res['current_level'],
            $res['level']
        ));
    }
}
