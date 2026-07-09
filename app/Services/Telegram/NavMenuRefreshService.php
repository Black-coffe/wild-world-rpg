<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\ActionLogModel;
use Longman\TelegramBot\Request;

/**
 * ADR-150 — доставка нового reply-каркаса живым игрокам («пере-аттач меню»).
 *
 * 🔴 Проблема, которую решает. Telegram НЕ обновляет постоянную reply-клавиатуру сам: она
 * остаётся у игрока такой, какой её прислали в последний раз. Замер прода 2026-07-09: после
 * активации Слайса 1 команду `/start` нажали лишь **6 из 79** активных игроков → у остальных
 * висит старое меню `[Перс·База·Крафт·Карта]/[Настройки]`, и кнопок «🌍 Мир» / «📋 Дела» /
 * «⚙️ Ещё» для них **не существует**. Вся пере-архитектура навигации лежала мёртвым грузом.
 *
 * **Как ловим устаревшую клавиатуру — без рассылки.** Игрок выдаёт её сам: он присылает текст
 * `Карта` или `Настройки` — подписи, которых в новом каркасе УЖЕ НЕТ (там «🌍 Мир» и «⚙️ Ещё»).
 * Значит, сообщение пришло со старой клавиатуры. Это точный сигнал без ложных срабатываний,
 * и он не требует ни broadcast'а, ни массовых сообщений: реагируем на действие самого игрока.
 *
 * One-shot на персонажа (маркер в `action_log`) + гейт «каркас реально изменился» (хоть один
 * nav-killswitch включён). Оба флага OFF → метод не делает ничего (byte-identical).
 * Defensive: никогда не выбрасывает наружу — пере-аттач не должен ронять действие игрока.
 */
class NavMenuRefreshService
{
    /** Маркер one-shot в `action_log`. Меняется, если каркас пере-собран заново. */
    public const MARKER = 'NavMenuRefreshed_adr150';

    /**
     * Пере-аттачит нижнее меню ровно один раз, если каркас изменился, а у игрока — старый.
     * Вызывается ПОСЛЕ основного ответа (чтобы меню пришло вторым сообщением, не подменяя его).
     */
    public function maybeRefresh(int $charId, int $chatId): void
    {
        try {
            if ($charId <= 0 || $chatId === 0 || ! $this->layoutChanged() || $this->alreadyRefreshed($charId)) {
                return;
            }

            $response = Request::sendMessage([
                'chat_id'      => $chatId,
                'parse_mode'   => 'Markdown',
                'text'         => "🧭 *Меню обновилось.*\n\n"
                    . 'Внизу экрана теперь: ' . BotMenuService::replyButtonsLine() . ".\n\n"
                    . "_«🌍 Мир» — компас ходьбы. «📋 Дела» — что идёт сейчас, квесты и задания дня. "
                    . "«⚙️ Ещё» — магазин, арена, развлечения, помощь и настройки._",
                'reply_markup' => BotMenuService::mainReplyKeyboard(),
            ]);

            // Маркер ставим ТОЛЬКО при успешной доставке: иначе игрок с заблокированным ботом
            // (или упавшей отправкой) навсегда остался бы со старой клавиатурой.
            if ($response->isOk()) {
                $this->mark($charId);
            }
        } catch (\Throwable $e) {
            log_message('error', '[NavMenuRefresh] failed: ' . $e->getMessage());
        }
    }

    /**
     * Каркас отличается от исторического `[Перс·База·Крафт·Карта]/[Настройки]`?
     * Если все nav-killswitch OFF — меню то же самое, пере-аттачивать нечего.
     */
    protected function layoutChanged(): bool
    {
        return BotMenuService::worldHubEnabled()
            || BotMenuService::tasksHubEnabled()
            || BotMenuService::moreHubEnabled();
    }

    /** Overridable seam (тесты — без БД). */
    protected function alreadyRefreshed(int $charId): bool
    {
        return (new ActionLogModel())
            ->where('character_id', $charId)
            ->where('action_name', self::MARKER)
            ->countAllResults() > 0;
    }

    /** Overridable seam (тесты — без БД). */
    protected function mark(int $charId): void
    {
        (new ActionLogModel())->insert([
            'character_id'  => $charId,
            'chat_id'       => 0,
            'action_name'   => self::MARKER,
            'action_status' => 'Completed',
            'description'   => 'ADR-150 reply keyboard re-attached',
        ]);
    }
}
