<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Admin\WipeService;
use App\Services\Notifications\BroadcastService;
use CodeIgniter\HTTP\RedirectResponse;
use Throwable;

/**
 * Полный вайп сервера — admin-flow (ADR-087, задача Asana «ВАЙП всех игроков»).
 *
 * Поток (как заказал владелец):
 *   1. GET  /admin/wipe          — авто-прогон coverage-чека + сканирование БД →
 *                                  таблица «что удалится / до чего сбросится / сколько игроков»
 *                                  + редактируемый черновик broadcast-сообщения всем игрокам.
 *   2. POST /admin/wipe/arm      — «Делать вайп» → финальный экран подтверждения с
 *                                  предпросмотром сообщения + поле пароля админки.
 *   3. POST /admin/wipe/execute  — повторный ввод пароля + «Подтвердить» → атомарный вайп
 *                                  через {@see WipeService::wipeAll()} → ПОСЛЕ вайпа рассылка
 *                                  отредактированного сообщения ВСЕМ игрокам без исключения.
 *
 * 🔴 Деструктивно и необратимо. Защиты: повторная аутентификация (пароль текущего
 * админа), двойное подтверждение, coverage-гейт (незаклассифицированная таблица →
 * отказ), полный audit-trail.
 */
class WipeController extends BaseAdminController
{
    private function defaultBroadcastTitle(): string
    {
        return 'Вайп сервера — новый сезон';
    }

    private function defaultBroadcastMessage(): string
    {
        return "Выжившие.\n\n"
            . "Мир Wild World обнулён — начат новый сезон. Весь прогресс сброшен до стартового: "
            . "уровень, золото, ресурсы, снаряжение, постройки, фракция, исследованная карта.\n\n"
            . "Осталось только твоё имя и сам остров. Условия на старте у всех одинаковые — честный рестарт.\n\n"
            . "Напиши /start и начни заново.";
    }

    public function index(): string
    {
        $service = new WipeService();

        return view('admin/wipe_index', [
            'title'            => 'ВАЙП всех игроков',
            'preview'          => $service->preview(),
            'broadcastTitle'   => old('broadcast_title', $this->defaultBroadcastTitle()),
            'broadcastMessage' => old('broadcast_message', $this->defaultBroadcastMessage()),
        ]);
    }

    /**
     * «Делать вайп» — переносим отредактированное сообщение на финальный экран
     * подтверждения с вводом пароля.
     */
    public function arm(): string|RedirectResponse
    {
        $title   = $this->postString('broadcast_title');
        $message = $this->postString('broadcast_message');

        if (trim($message) === '') {
            return redirect()->back()->withInput()->with('errors', ['Сообщение игрокам не может быть пустым.']);
        }

        $service = new WipeService();
        $preview = $service->preview();

        if ($preview['coverage']['unclassified'] !== []) {
            return redirect()->back()->with('errors', [
                'Вайп заблокирован: незаклассифицированные таблицы — '
                . implode(', ', $preview['coverage']['unclassified'])
                . '. Добавь их в Config\\WipeManifest (см. ADR-087).',
            ]);
        }

        return view('admin/wipe_confirm', [
            'title'            => 'Подтверждение вайпа',
            'preview'          => $preview,
            'broadcastTitle'   => $title !== '' ? $title : $this->defaultBroadcastTitle(),
            'broadcastMessage' => $message,
        ]);
    }

    /**
     * Финальный запуск: пароль + двойное подтверждение → вайп → рассылка после вайпа.
     */
    public function execute(): RedirectResponse
    {
        $password = $this->postString('admin_password');
        $confirm  = $this->postString('confirm') === 'yes';
        $title    = $this->postString('broadcast_title');
        $message  = $this->postString('broadcast_message');

        if (! $confirm) {
            return redirect()->to('/admin/wipe')->with('errors', ['Не отмечено финальное подтверждение.']);
        }
        if (trim($message) === '') {
            return redirect()->to('/admin/wipe')->with('errors', ['Сообщение игрокам не может быть пустым.']);
        }
        if (! $this->verifyAdminPassword($password)) {
            return redirect()->to('/admin/wipe')->with('errors', ['Неверный пароль администратора. Вайп НЕ выполнен.']);
        }

        $service = new WipeService();

        // Повторный coverage-гейт прямо перед деструктивом.
        $gaps = $service->coverageGaps();
        if ($gaps['unclassified'] !== []) {
            return redirect()->to('/admin/wipe')->with('errors', [
                'Вайп заблокирован: незаклассифицированные таблицы — ' . implode(', ', $gaps['unclassified']),
            ]);
        }

        // Долгая операция: вайп + рассылка всем. Не даём оборваться по таймауту/закрытию вкладки.
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $report = $service->wipeAll();
        } catch (Throwable $e) {
            log_message('error', '[admin/wipe] wipe failed: ' . $e->getMessage());
            return redirect()->to('/admin/wipe')->with('errors', ['Вайп провалился (откатан): ' . $e->getMessage()]);
        }

        $this->audit('GAME_WIPE_EXECUTE', 'server', null, [
            'deleted'              => $report['deleted'],
            'reset'                => $report['reset'],
            'characters_respawned' => $report['characters_respawned'],
            'broadcast_title'      => mb_substr($title, 0, 160),
        ]);

        // ПОСЛЕ вайпа — рассылка отредактированного сообщения всем без исключения.
        $cleanTitle   = mb_substr(strip_tags($title !== '' ? $title : $this->defaultBroadcastTitle()), 0, 160);
        $cleanMessage = mb_substr(strip_tags($message), 0, 3500);
        $fullMessage  = "*ℹ️ {$cleanTitle} ℹ️*\n\n{$cleanMessage}";

        $delivery = ['sent' => 0, 'blocked' => 0, 'failed' => 0, 'total' => 0];
        try {
            $delivery = (new BroadcastService())->broadcastToAll($fullMessage);
        } catch (Throwable $e) {
            log_message('error', '[admin/wipe] post-wipe broadcast failed: ' . $e->getMessage());
        }

        $this->audit('GAME_WIPE_BROADCAST', 'telegram_chat', null, $delivery);

        $deletedTotal = array_sum($report['deleted']);

        return redirect()->to('/admin/wipe')->with(
            'success',
            "🔥 Вайп выполнен: удалено {$deletedTotal} строк, сброшено/респавнено {$report['characters_respawned']} персонажей. "
            . "Рассылка: доставлено {$delivery['sent']}, заблокировали {$delivery['blocked']}, ошибки {$delivery['failed']} (всего {$delivery['total']})."
        );
    }

    private function verifyAdminPassword(string $password): bool
    {
        if ($password === '') {
            return false;
        }

        $auth = service('auth');
        $user = is_object($auth) && method_exists($auth, 'getCurrentUser')
            ? $auth->getCurrentUser()
            : null;

        return is_object($user) && method_exists($user, 'verifyPassword')
            ? (bool) $user->verifyPassword($password)
            : false;
    }

    private function postString(string $key): string
    {
        $raw = $this->request->getPost($key);

        return is_scalar($raw) ? (string) $raw : '';
    }
}
