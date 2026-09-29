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
 *
 * w2-n4-tails-01: «✅ Подтвердить» = `confirm_upgrade_building_<id>_l<уровень>[_b<база>]`. Ядро применяет
 * апгрейд только с этого уровня (`stale` — ничего не списано). Кнопка старых сообщений без `_l` ничего не
 * списывает: заново показывает «Подтвердите апгрейд?» с актуальными ценой и уровнем.
 *
 * w2-n4-tails2: каждый отказ ядра (запрос и подтверждение) снимает «часики» кнопки — один
 * `answerCallbackQuery` на апдейт, текст отказа уходит сообщением как раньше.
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
     * Снять «часики» кнопки (один раз на апдейт); `$text` — короткая всплывашка, без неё — пусто.
     */
    private function answerCallback(?string $text = null): void
    {
        $payload = ['callback_query_id' => $this->callbackQuery->getId()];
        if ($text !== null) {
            $payload['text']       = $text;
            $payload['show_alert'] = false;
        }
        Request::answerCallbackQuery($payload);
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

        return $this->prompt($chatId, (int) $character['id'], (int) $buildingId, $baseId);
    }

    /**
     * Экран «Подтвердите апгрейд?» из превью ядра (или его отказ).
     */
    private function prompt(int|string $chatId, int $characterId, int $buildingId, ?int $baseId): ServerResponse
    {
        // story multibase-picker-03: суффикс `_b<baseId>` — заново проверенный выбор базы (в ядре).
        $res = $this->upgrades->preview($characterId, $baseId, $buildingId);
        if (!$res['ok']) {
            $this->answerCallback();
            if ($res['code'] === BuildingUpgradeService::MISSING) {
                return $this->send($chatId, $this->formatter->missingResourcesAsk($res['next_level'], $res['missing']));
            }
            if ($res['code'] === BuildingUpgradeService::RELOCATING) {
                return $this->send($chatId, $this->formatter->markdownError($res['message']));
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
            $buildingId,
            $res['name'] ?? "ID={$buildingId}",
            $res['current_level'],
            $res['level'],
            (int) $req['level'],
            (int) $req['gold'],
            $req['resources'],
            $res['character'],
            $baseId,
            $res['effect_now'],
            $res['effect_next']
        ));
    }

    /**
     * Шаг 2: пользователь подтвердил апгрейд (callback_data: "confirm_upgrade_building_X_lN", старое — без "_lN")
     */
    public function confirmUpgrade(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return $this->send($chatId, $this->formatter->userOrCharacterNotFound());
        }

        // Parse buildingId з callback_data: "confirm_upgrade_building_4_l7" (или "..._b345")
        [$withoutSuffix, $baseId] = BaseCallbackSuffix::split((string) $this->callbackQuery->getData());
        $parts      = explode('_', $withoutSuffix);
        $buildingId = $parts[3] ?? null;
        if (!$buildingId) {
            return $this->send($chatId, $this->formatter->buildingIdMissingConfirm());
        }

        // Кнопка без уровня (сообщение до w2-n4-tails) — ничего не списываем, показываем свежий запрос.
        $levelPart = $parts[4] ?? '';
        if (preg_match('/^l(\d{1,4})$/', $levelPart, $m) !== 1) {
            return $this->prompt($chatId, (int) $character['id'], (int) $buildingId, $baseId);
        }

        // Ядро перепроверяет всё (запас мог измениться после шага 1) и применяет атомарно — только с уровня N.
        $res = $this->upgrades->apply((int) $character['id'], $baseId, (int) $buildingId, (int) $m[1]);
        if (!$res['ok']) {
            $this->answerCallback($res['code'] === BuildingUpgradeService::RACE ? BuildingUpgradeService::TEXT_RACE : null);
            if ($res['code'] === BuildingUpgradeService::MISSING) {
                return $this->send($chatId, $this->formatter->missingResourcesConfirm($res['missing'][0]));
            }
            if ($res['code'] === BuildingUpgradeService::RELOCATING) {
                return $this->send($chatId, $this->formatter->markdownError($res['message']));
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
