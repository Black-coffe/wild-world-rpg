<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Onboarding\BuildLockService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139, спина-слайс 2) — клик по lock-кнопке уровневой постройки.
 *
 * callback `buildLocked_<Key>` (exact-роут, резолв по первому сегменту explode('_')[0]; Key
 * разбирает сам action — урок мёртвых `npcAct_`). Показывает alert с prerequisite (нужный
 * уровень + сколько осталось + польза + как качаться) — UX-Discoverability (CLAUDE.md): вход
 * виден, клик объясняет, а не молча отдаёт ошибку. Зеркало {@see ChooseFactionLockedAction}.
 *
 * Рендерится только при killswitch `onboarding.cold_open_v2.build_locks` (BuildListAction
 * ставит lock-кнопку лишь тогда); сам alert безвреден при любом состоянии флага.
 */
final class BuildLockedAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $parts = explode('_', $this->callbackQuery->getData(), 2);
        $key   = $parts[1] ?? '';

        [$user, $character] = $this->getUserAndCharacter();
        $level = ($character !== null && isset($character['level']) && is_numeric($character['level']))
            ? (int) $character['level']
            : 0;

        $msg = (new BuildLockService())->lockAlert($key, $level);

        return Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
            'cache_time'        => 0,
        ]);
    }
}
