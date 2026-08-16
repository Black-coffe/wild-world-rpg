<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\NPC;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\PVE\BossEncounterService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * WB6 (ADR-137 «Узлы») — экран многоходового боя с узлом-боссом.
 *
 * Callback `nodeAct_<verb>_<bossPointId>` (префикс-роут `nodeAct`, резолв по первому сегменту
 * explode('_')[0] = 'nodeAct'; ключ БЕЗ хвостового `_` — урок npcAct/мёртвых кнопок 2026-06-02).
 *
 * verbs: look (карточка осмотра WB12: сила-словами/лут/cooldown-таймер) · start (начать бой,
 * WB13 — при смертельном разрыве уровня сперва предупреждение) · force (WB13 — напасть вопреки
 * предупреждению) · atk/def/spec/item (чанк действия) · flee (отступить).
 * Вся логика — в {@see BossEncounterService}; здесь только парс callback + рендер экрана
 * (edit-in-place через MediaSender::editTextOrSend; media-off self-contained, HP в тексте) или
 * всплывашка (alert). Гейт killswitch — внутри сервиса (world.nodes.point_mode_enabled).
 */
final class BossEncounterAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден.');
        }

        $data  = (string) $this->callbackQuery->getData();
        $parts = explode('_', $data);
        $verb  = $parts[1] ?? '';

        $svc = new BossEncounterService();

        switch ($verb) {
            case 'look':
                $screen = $svc->look($character);
                break;
            case 'start':
                $screen = $svc->start($character);
                break;
            case 'force':
                // WB13 — игрок подтвердил бой вопреки предупреждению о разрыве уровня.
                $screen = $svc->start($character, true);
                break;
            case 'atk':
            case 'def':
            case 'spec':
            case 'item':
            case 'flee':
                $screen = $svc->act($character, $verb);
                break;
            default:
                return $this->alert('Неизвестное действие.');
        }

        if (isset($screen['alert']) && is_string($screen['alert'])) {
            return $this->alert($screen['alert']);
        }

        $text     = is_string($screen['text'] ?? null) ? $screen['text'] : '…';
        $keyboard = is_array($screen['keyboard'] ?? null) ? $screen['keyboard'] : [];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
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
