<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Settlement;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BiomeModel;
use App\Services\Notifications\MediaSender;
use App\Services\Settlement\SettlementTeleportService;
use App\Services\Tasks\ActiveTasksService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-101 Фаза 5 — подтверждение + выполнение быстрого перехода в поселение.
 *
 * Exact-роут `settleTeleportGo`; хвост `_id={id}` (предпросмотр) или `_id={id}_go`
 * (выполнить) разбирается regex'ом — НЕ prefix (урок мёртвых `npcAct_` ADR-089).
 * Переход тратит золото → ОБЯЗАТЕЛЬНЫЙ confirm-шаг (паттерн ADR-096). Анти-абуз:
 * killswitch + не во время марша ({@see ActiveTasksService::checkRelocationAndBlock}) +
 * кулдаун/золото (в сервисе). Сделку выполняет ТОЛЬКО `_go`-ветка.
 */
final class SettlementTeleportGoAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }

        $service = new SettlementTeleportService();
        if (! $service->enabled()) {
            return $this->alert('Быстрое перемещение сейчас недоступно.');
        }

        $data = (string) $this->callbackQuery->getData();
        if (! preg_match('/^settleTeleportGo_id=(\d+)(_go)?$/', $data, $m)) {
            return $this->alert('Некорректный переход.');
        }
        $settlementId = (int) $m[1];
        $confirmed    = isset($m[2]); // '_go'-суффикс присутствует только в ветке выполнения

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        // Предпросмотр (подтверждение). Сделку НЕ делает.
        if (! $confirmed) {
            return $this->preview($service, $charId, $settlementId);
        }

        // Анти-абуз: нельзя телепортироваться во время марша/перемещения.
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        if ((new ActiveTasksService())->checkRelocationAndBlock($charId, $this->callbackQuery->getId(), $chatId)) {
            return Request::emptyResponse();
        }

        $result = $service->teleportTo($charId, $settlementId, 0);
        if (! $result['success']) {
            return $this->screen("🛰 {$result['message']}", [
                [['text' => '⬅️ К списку', 'callback_data' => 'settleTeleport']],
            ]);
        }

        $dest      = $result['settlement'] ?? [];
        $name      = is_string($dest['name_ru'] ?? null) ? $dest['name_ru'] : 'Поселение';
        $sx        = is_numeric($dest['coordinate_x'] ?? null) ? (int) $dest['coordinate_x'] : 0;
        $sy        = is_numeric($dest['coordinate_y'] ?? null) ? (int) $dest['coordinate_y'] : 0;
        $cost      = is_numeric($result['cost'] ?? null) ? (int) $result['cost'] : 0;
        $biomeName = $this->biomeNameForSettlement($dest);

        $text = "🌀 *Переход завершён!*\n\n"
            . "Ты прибыл в *{$name}* (X={$sx}, Y={$sy}).\n"
            . "Биом: *{$biomeName}*\n"
            . "💰 Списано: *" . number_format($cost) . "* золота.";

        return $this->screen($text, [
            [['text' => '🏚 Войти в поселение', 'callback_data' => 'settleHub']],
            [['text' => '🗺 Карта', 'callback_data' => 'move'], ['text' => '🧑‍🌾 Действия', 'callback_data' => 'characterActions']],
        ]);
    }

    /**
     * Шаг подтверждения: имя/стоимость/кулдаун без мутаций.
     */
    private function preview(SettlementTeleportService $service, int $charId, int $settlementId): ServerResponse
    {
        $dest = (new \App\Models\SettlementModel())->find($settlementId);
        if (! is_array($dest) || ! $service->isOpenForCharacter($charId, $dest)) {
            return $this->screen('🛰 Это место недоступно для перехода.', [
                [['text' => '⬅️ К списку', 'callback_data' => 'settleTeleport']],
            ]);
        }

        $remaining = $service->cooldownRemainingSeconds($charId);
        if ($remaining > 0) {
            $min = (int) ceil($remaining / 60);
            return $this->screen(
                "🛰 Переход на кулдауне — подожди ещё *~{$min} мин*.",
                [[['text' => '⬅️ К списку', 'callback_data' => 'settleTeleport']]]
            );
        }

        $name = is_string($dest['name_ru'] ?? null) ? $dest['name_ru'] : 'Поселение';
        $sx   = is_numeric($dest['coordinate_x'] ?? null) ? (int) $dest['coordinate_x'] : 0;
        $sy   = is_numeric($dest['coordinate_y'] ?? null) ? (int) $dest['coordinate_y'] : 0;
        $cost = $service->cost();

        $text = "🛰 *Быстрое перемещение*\n\n"
            . "Перейти в *{$name}* (X={$sx}, Y={$sy}).\n"
            . "Стоимость: *" . number_format($cost) . "* 💰.\n\n"
            . "Подтвердить переход?";

        return $this->screen($text, [
            [['text' => '✅ Да, перейти', 'callback_data' => "settleTeleportGo_id={$settlementId}_go"]],
            [['text' => '⬅️ Назад', 'callback_data' => 'settleTeleport']],
        ]);
    }

    /**
     * Имя биома клетки-якоря назначения (для финального текста).
     *
     * @param array<int|string,mixed> $settlement
     */
    private function biomeNameForSettlement(array $settlement): string
    {
        $anchor = is_numeric($settlement['anchor_cell'] ?? null) ? (int) $settlement['anchor_cell'] : 0;
        if ($anchor <= 0) {
            return '???';
        }
        $mapRow  = (new \App\Models\MapModel())->find($anchor);
        $biomeId = is_array($mapRow) && is_numeric($mapRow['biome_id'] ?? null) ? (int) $mapRow['biome_id'] : 0;
        if ($biomeId <= 0) {
            return '???';
        }
        $biome = (new BiomeModel())->find($biomeId);
        $arr   = $biome instanceof \CodeIgniter\Entity\Entity ? $biome->toArray() : [];
        $nm    = $arr['name'] ?? null;

        return is_string($nm) ? $nm : '???';
    }

    /**
     * @param list<list<array<string,string>>> $rows
     */
    private function screen(string $text, array $rows): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
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
