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
    /** Маркер one-shot в `action_log` для ПЕРЕХОДНОГО каркаса (слайсы 1-4). */
    public const MARKER = 'NavMenuRefreshed_adr150';

    /**
     * Маркер финальной сетки 2×3.
     *
     * 🔴 Зачем отдельный. Маркер one-shot: игрок, уже получивший переходный каркас, имеет
     * {@see MARKER} и второго сообщения не получил бы НИКОГДА — а его клавиатура снова
     * устарела бы (в финале «Перс»→«🧑 Я», «Карта» исчезла, добавилась «🏠 База»).
     * Версионирование маркера = каждый новый каркас доезжает ровно один раз.
     */
    public const MARKER_FINAL = 'NavMenuRefreshed_adr150_final';

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
                    . $this->explainer(),
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

    /** Маркер текущего каркаса: финальная сетка 2×3 доезжает отдельно от переходной. */
    protected function marker(): string
    {
        return BotMenuService::finalGridEnabled() ? self::MARKER_FINAL : self::MARKER;
    }

    /** Пояснение к кнопкам — ровно про тот каркас, который сейчас уезжает игроку. */
    protected function explainer(): string
    {
        if (BotMenuService::finalGridEnabled()) {
            return "_Шесть кнопок — шесть мест. «🌍 Мир» — компас ходьбы и всё, что на клетке. "
                . "«🧑 Я» — персонаж, инвентарь, экипировка. «🔨 Крафт» — верстаки и ремонт инструментов. "
                . "«🏠 База» — стройка, склад, маяки. «📋 Дела» — что идёт сейчас, квесты и задания дня. "
                . "«⚙️ Ещё» — магазин, арена, развлечения, помощь и настройки._";
        }

        return "_«🌍 Мир» — компас ходьбы. «📋 Дела» — что идёт сейчас, квесты и задания дня. "
            . "«⚙️ Ещё» — магазин, арена, развлечения, помощь и настройки._";
    }

    /** Overridable seam (тесты — без БД). */
    protected function alreadyRefreshed(int $charId): bool
    {
        return (new ActionLogModel())
            ->where('character_id', $charId)
            ->where('action_name', $this->marker())
            ->countAllResults() > 0;
    }

    /**
     * Пометить персонажа как «уже держит новый каркас» БЕЗ отправки сообщения. Зовётся оттуда,
     * где меню и так пере-аттачится штатно (`/start`, `/menu`) → такой игрок не получит потом
     * лишнее «Меню обновилось». Идемпотентно.
     */
    public function markAlreadyFresh(int $charId): void
    {
        try {
            if ($charId <= 0 || $this->alreadyRefreshed($charId)) {
                return;
            }
            $this->mark($charId);
        } catch (\Throwable $e) {
            log_message('error', '[NavMenuRefresh] mark failed: ' . $e->getMessage());
        }
    }

    /** Overridable seam (тесты — без БД). */
    protected function mark(int $charId): void
    {
        (new ActionLogModel())->insert([
            'character_id'  => $charId,
            'chat_id'       => 0,
            'action_name'   => $this->marker(),
            'action_status' => 'Completed',
            'description'   => 'ADR-150 reply keyboard re-attached',
        ]);
    }
}
