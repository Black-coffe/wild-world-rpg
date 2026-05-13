<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AdminAuditLogModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Базовый контроллер для всех страниц `/admin/*` (Phase A рефакторинга админки,
 * 2026-05-13, см. `mmorpg-vault/inbox/2026-05-13-admin-refactor-roadmap.md`).
 *
 * Цель: убрать дубликаты `auditAdminAction()` (D1) и flash-redirect helper'ов (D2),
 * которые копипастились в 7+ контроллерах. Поведение не меняется — это чистый
 * DRY-рефактор. Подготавливает почву для Phase B (plugin architecture).
 *
 * Подключает {@see ResponseTrait} (раньше каждый контроллер делал `use ResponseTrait`
 * отдельно). Подклассы получают `failNotFound()`, `failValidationErrors()`,
 * `failServerError()` без дополнительных импортов.
 */
abstract class BaseAdminController extends BaseController
{
    use ResponseTrait;

    /**
     * CI4 4.5+ breaking change в ResponseTrait — строки по умолчанию теперь
     * возвращаются как JSON. Все админ-страницы возвращают HTML (view-render),
     * поэтому явно сохраняем старое поведение для всех потомков.
     */
    protected bool $stringAsHtml = true;

    /**
     * Записать destructive admin-действие в `admin_audit_log`.
     * Тонкая обёртка над {@see AdminAuditLogModel::record()} — извлекает текущего
     * админа из `service('auth')`, никогда не ронит request если audit-вставка падает.
     *
     * @param array<string, mixed> $payload
     */
    protected function audit(
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $payload = [],
    ): void {
        $auth        = service('auth');
        $adminUserId = is_object($auth) && method_exists($auth, 'user')
            ? (int) ($auth->user()->id ?? 0)
            : 0;

        (new AdminAuditLogModel())->record($adminUserId, $action, $targetType, $targetId, $payload);
    }

    /**
     * Redirect на абсолютный URL с success flash-сообщением (стандартный
     * паттерн после успешного create/update/delete в админке).
     */
    protected function redirectWithSuccess(string $url, string $message): RedirectResponse
    {
        return redirect()->to($url)->with('success', $message);
    }

    /**
     * Redirect назад (на форму) с input-данными + массивом ошибок (стандартный
     * паттерн при provider-валидации `$this->validate(...)` или `Model::errors()`).
     *
     * @param array<int|string, string> $errors
     */
    protected function redirectBackWithErrors(array $errors): RedirectResponse
    {
        return redirect()->back()->withInput()->with('errors', $errors);
    }

    /**
     * Redirect назад с одним error flash-сообщением (без input — для случаев
     * NotFound / forbidden / простых ошибок без сохранения формы).
     */
    protected function redirectBackWithError(string $message): RedirectResponse
    {
        return redirect()->back()->with('error', $message);
    }
}
