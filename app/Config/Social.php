<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * ADR-052 — канонические Telegram-ссылки публичного сайта.
 *
 * Разведение по назначению (по требованию владельца):
 *   - $botLink   — ВСЕ call-to-action кнопки сайта («Играть», «Начать», «Открыть бота») ведут сюда;
 *   - $groupLink — информационные/сообщественные ссылки (новости, обсуждение) ведут в группу.
 *
 * Не секреты — публичные URL, поэтому держим в коде (деплоятся с релизом), а не в .env.
 */
class Social extends BaseConfig
{
    /** Бот игры — целевая ссылка всех CTA. */
    public string $botLink = 'https://t.me/wildworldrpg_bot';

    /** Группа-сообщество (новости и обсуждение) — целевая ссылка информационных блоков. */
    public string $groupLink = 'https://t.me/wild_world_info';
}
