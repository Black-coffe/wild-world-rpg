<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * Единый поиск Telegram-чата персонажа для фонового слоя (Worker, кроны).
 *
 * Web-only персонаж (web-accounts-p0, ADR-188) не имеет строки в `telegram_users` и несёт
 * `characters.telegram_user_id = NULL`. Раньше каждый handler искал чат сам —
 * `TelegramUserModel::find($task['telegram_user_id'])`, а в CI4 `find(null)` возвращает ВСЕ
 * строки таблицы, `->first()['telegram_id']` на пустом результате бросает. Здесь пропуск
 * Telegram — это `null`, без исключения и без запроса по пустому ключу.
 *
 * Правило вызывающего: награда начисляется ДО поиска чата, уведомление пропускается на null.
 */
final class TelegramChatResolver
{
    /**
     * `telegram_users.telegram_id` персонажа — chat id для отправки.
     *
     * @return int|null null, если персонажа нет, у него нет Telegram или строки `telegram_users`
     */
    public function chatIdForCharacter(int $characterId): ?int
    {
        if ($characterId <= 0) {
            return null;
        }

        // Query Builder, не raw SQL: тесты под префиксом таблиц (`setPrefix`) должны его видеть.
        $query = Database::connect()->table('characters c')
            ->select('u.telegram_id')
            ->join('telegram_users u', 'u.id = c.telegram_user_id')
            ->where('c.id', $characterId)
            ->limit(1)
            ->get();
        if (! $query instanceof BaseResult) {
            return null;
        }
        $row = $query->getRowArray();
        if (! is_array($row) || ! is_numeric($row['telegram_id'] ?? null)) {
            return null;
        }
        $chatId = (int) $row['telegram_id'];

        return $chatId !== 0 ? $chatId : null;
    }

    /**
     * `characters.telegram_user_id` персонажа (ключ строки `telegram_users`).
     *
     * @return int|null null, если персонажа нет или он web-only
     */
    public function telegramUserIdForCharacter(int $characterId): ?int
    {
        if ($characterId <= 0) {
            return null;
        }

        $query = Database::connect()->table('characters')
            ->select('telegram_user_id')
            ->where('id', $characterId)
            ->limit(1)
            ->get();
        if (! $query instanceof BaseResult) {
            return null;
        }
        $row = $query->getRowArray();
        if (! is_array($row) || ! is_numeric($row['telegram_user_id'] ?? null)) {
            return null;
        }
        $id = (int) $row['telegram_user_id'];

        return $id > 0 ? $id : null;
    }
}
