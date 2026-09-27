<?php

declare(strict_types=1);

namespace App\Services\Web;

use App\Services\Player\CharacterSheetService;
use App\Services\Telegram\BotMenuService;
use InvalidArgumentException;

/**
 * W2.N1 (ADR-190) — нативные экраны `/play`: веб-рендерер моделей экранов поверх того же ядра,
 * из которого рисует бот. Мост (ADR-189) остаётся фолбэком для кнопок без нативного экрана.
 *
 * Экран — серверный HTML в `#play-state` (работает без JS), HUD — партиал `site/_play/hud`
 * над ним. Идентификаторы персонажа приходят только из сессии (контроллер), в разметку не
 * выводятся telegram/chat id (ADR-189 инв. 6).
 *
 * Кнопка нативного экрана, которой нет нативного аналога, уходит в мост «как сейчас»: если
 * на текущем экране моста нет сообщения с этой кнопкой, сначала вызывается карточка бота
 * (текст нижнего меню «Я»), затем — callback с её кнопки. Обе ступени — обычные
 * {@see WebActService::act()} с проверкой «кнопка стоит на своём сообщении» и дедупом
 * `intent_id` (суффиксы `:card` / `:cb`).
 *
 * @phpstan-import-type State from WebScreenStore
 * @phpstan-import-type Sheet from CharacterSheetService
 */
class WebNativeScreenService
{
    public const VIEW_ME = 'me';

    /** Экраны, у которых уже есть нативная вьюха. */
    public const VIEWS = [self::VIEW_ME];

    private CharacterSheetService $sheets;

    public function __construct(private ?WebActService $act = null, ?CharacterSheetService $sheets = null)
    {
        $this->sheets = $sheets ?? new CharacterSheetService();
    }

    public static function isView(mixed $view): bool
    {
        return is_string($view) && in_array($view, self::VIEWS, true);
    }

    /**
     * Нативный экран по имени — готовый HTML для `#play-state`.
     *
     * @param array<string, mixed> $state текущее состояние моста (из него берётся док)
     *
     * @throws InvalidArgumentException неизвестный экран или нет персонажа
     */
    public function render(int $characterId, string $view, array $state, ?string $alert = null): string
    {
        if ($view !== self::VIEW_ME) {
            throw new InvalidArgumentException('unknown view');
        }
        $sheet = $this->sheets->forCharacter($characterId);
        if ($sheet === null) {
            throw new InvalidArgumentException('character not found');
        }

        return view('site/_play/native_me', ['sheet' => $sheet, 'dock' => is_array($state['dock'] ?? null) ? $state['dock'] : [], 'alert' => $alert]);
    }

    /**
     * HUD персонажа — HTML партиала; пустая строка, если персонажа нет или HUD не собрался
     * (сбой HUD не должен ронять экран и действие — игрок просто не видит полосу до следующего ответа).
     */
    public function hudHtml(int $characterId): string
    {
        try {
            $hud = $this->sheets->hud($characterId);
        } catch (\Throwable $e) {
            log_message('error', '[WebNativeScreenService] hud failed: ' . $e::class . ': ' . $e->getMessage());

            return '';
        }

        return $hud === null ? '' : view('site/_play/hud', ['hud' => $hud]);
    }

    /**
     * Кнопка нативного «Я» без нативного экрана → тот же callback через мост.
     *
     * @return array{state: State, alert: ?string, unread: int}
     *
     * @throws InvalidArgumentException кнопки нет на экране «Я» или намерение отвергнуто мостом
     */
    public function bridge(int $accountId, int $characterId, string $callback, string $intentId): array
    {
        $sheet = $this->sheets->forCharacter($characterId);
        if ($sheet === null || ! in_array($callback, self::callbacks($sheet), true)) {
            throw new InvalidArgumentException('callback is not on the character screen');
        }
        if ($intentId === '' || strlen($intentId) > 60) {
            throw new InvalidArgumentException('bad intent_id');
        }
        $act = $this->act ?? new WebActService();

        $messageId = self::messageWith($act->current($characterId)['state'], $callback);
        if ($messageId === null) {
            $card      = $act->act($accountId, $characterId, [
                'intent_id' => $intentId . ':card',
                'kind'      => WebActService::KIND_TEXT,
                'data'      => BotMenuService::menuLabel('me'),
            ]);
            $messageId = self::messageWith($card['state'], $callback);
            if ($messageId === null) {
                // Повтор того же намерения (карточка уже сменилась ответом) — просто текущий экран.
                return $card;
            }
        }

        return $act->act($accountId, $characterId, [
            'intent_id'  => $intentId . ':cb',
            'kind'       => WebActService::KIND_CALLBACK,
            'data'       => $callback,
            'message_id' => (string) $messageId,
        ]);
    }

    /** Текст кнопки нижнего меню, который открывает нативный экран; null — такого нет. */
    public static function viewForDockLabel(string $label): ?string
    {
        return in_array($label, ['🧑 Я', 'Перс'], true) ? self::VIEW_ME : null;
    }

    /**
     * Callback-кнопки экрана «Я» (то, что можно отправить через мост).
     *
     * @param Sheet $sheet
     *
     * @return list<string>
     */
    private static function callbacks(array $sheet): array
    {
        $out = [];
        foreach (array_merge($sheet['personal_actions'], $sheet['tail_actions']) as $action) {
            if (isset($action['callback'])) {
                $out[] = $action['callback'];
            }
        }

        return $out;
    }

    /** @param State $state */
    private static function messageWith(array $state, string $callback): ?int
    {
        foreach (array_reverse($state['screen']) as $msg) {
            if (WebScreenStore::hasCallback($msg, $callback)) {
                return $msg['message_id'];
            }
        }

        return null;
    }
}
