<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * S5 (v0.51.187) — admin UI для live-tunable balance параметров (ADR-024).
 *
 * Endpoints:
 *  - GET  /admin/game-settings(/?category=craft) — overview всех / детально по группе
 *  - POST /admin/game-settings/update            — inline edit одного ключа
 *  - POST /admin/game-settings/reset             — reset одного ключа к default
 *
 * Validation:
 *  - Type coercion в `GameSettingsService::set()` (int/float/bool/string)
 *  - Hard-bounds check там же — save запрещён вне диапазона
 *  - Soft-bounds (recommended) — отображаются warning'ом в UI, но не блокируют
 *
 * Audit: каждый update + reset пишет в `admin_audit_log` через
 * `BaseAdminController::audit('GAME_SETTING_UPDATE', ...)` / `..._RESET`.
 */
class GameSettingsController extends BaseAdminController
{
    private GameSettingsService $service;

    public function __construct()
    {
        $this->service = new GameSettingsService();
    }

    public function index(): string
    {
        $category = $this->request->getGet('category');
        $category = (is_string($category) && $category !== '') ? $category : null;

        $rows       = $this->service->all($category);
        $categories = $this->service->categories();

        // Группировка для UI (массив category → list of rows).
        $grouped = [];
        foreach ($rows as $row) {
            $cat = isset($row['category']) && is_string($row['category']) ? $row['category'] : 'other';
            if (! isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][] = $row;
        }

        return view('admin/game_settings/index', [
            'title'           => 'Параметры баланса',
            'rows'            => $rows,
            'grouped'         => $grouped,
            'categories'      => $categories,
            'activeCategory'  => $category,
        ]);
    }

    public function update(): RedirectResponse
    {
        $key      = $this->request->getPost('setting_key');
        $valueRaw = $this->request->getPost('value');

        if (! is_string($key) || $key === '') {
            return $this->redirectBackWithError('Не указан ключ настройки.');
        }
        if (! is_scalar($valueRaw)) {
            return $this->redirectBackWithError('Значение должно быть скалярным.');
        }

        $value      = is_string($valueRaw) ? $valueRaw : (string) $valueRaw;
        $updatedBy  = $this->currentAdminLabel();

        // Сначала прочтём текущее значение для audit-payload.
        $oldRows   = $this->service->all(null);
        $oldValue  = null;
        foreach ($oldRows as $row) {
            if (($row['setting_key'] ?? null) === $key) {
                $oldValue = $this->extractCurrentValueForLog($row);
                break;
            }
        }

        $ok = $this->service->set($key, $value, $updatedBy);
        if (! $ok) {
            return $this->redirectBackWithError(
                "Не удалось сохранить «{$key}»: значение вне разрешённого диапазона или ключ не найден."
            );
        }

        $this->audit('GAME_SETTING_UPDATE', 'game_setting', null, [
            'setting_key' => $key,
            'old_value'   => $oldValue,
            'new_value'   => $value,
        ]);

        return $this->redirectWithSuccess(
            site_url('admin/game-settings'),
            "Настройка «{$key}» обновлена."
        );
    }

    public function reset(): RedirectResponse
    {
        $key = $this->request->getPost('setting_key');
        if (! is_string($key) || $key === '') {
            return $this->redirectBackWithError('Не указан ключ настройки.');
        }

        $updatedBy = $this->currentAdminLabel();
        $ok        = $this->service->reset($key, $updatedBy);
        if (! $ok) {
            return $this->redirectBackWithError("Не удалось сбросить «{$key}».");
        }

        $this->audit('GAME_SETTING_RESET', 'game_setting', null, [
            'setting_key' => $key,
        ]);

        return $this->redirectWithSuccess(
            site_url('admin/game-settings'),
            "Настройка «{$key}» сброшена к default."
        );
    }

    private function currentAdminLabel(): string
    {
        $auth = service('auth');
        if (is_object($auth) && method_exists($auth, 'user')) {
            $user = $auth->user();
            if (is_object($user)) {
                $email = $user->email ?? null;
                if (is_string($email) && $email !== '') {
                    return $email;
                }
                $id = $user->id ?? null;
                if (is_numeric($id)) {
                    return 'admin#' . (int) $id;
                }
            }
        }
        return 'unknown';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractCurrentValueForLog(array $row): string
    {
        $type = isset($row['value_type']) && is_string($row['value_type']) ? $row['value_type'] : 'string';
        switch ($type) {
            case 'int':
                $v = $row['value_int'] ?? '';
                return is_scalar($v) ? (string) $v : '';
            case 'float':
                $v = $row['value_float'] ?? '';
                return is_scalar($v) ? (string) $v : '';
            case 'bool':
                $v = $row['value_bool'] ?? 0;
                $intV = is_numeric($v) ? (int) $v : 0;
                return $intV === 1 ? 'true' : 'false';
            case 'string':
            default:
                $v = $row['value_string'] ?? '';
                return is_scalar($v) ? (string) $v : '';
        }
    }
}
