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
 *
 * АТРИБУЦИЯ ПЕРВОГО КАСАНИЯ: CTA-кнопки должны нести deep-link метку `?start=<src>`,
 * иначе регистрация пишется как NULL/органика и невидима в `/admin/funnel`
 * (acquisitionSlice). Telegram передаёт значение payload'ом в /start →
 * {@see \App\Controllers\Telegram\Commands\StartCommand::extractAcquisitionSource()}
 * санитайзит `[a-zA-Z0-9_-]` и пишет first-touch в `telegram_users.acquisition_source`.
 * Метку строит {@see self::botStart()}; НЕ хардкодить `?start=` в вьюхах.
 *
 * РЕЕСТР src-кодов сайта (единый источник, чтобы не плодить ad-hoc теги):
 *   src_site_home     — главная (hero + нижний CTA)
 *   src_site_header   — шапка (desktop-nav + мобильный drawer)
 *   src_site_footer   — подвал
 *   src_site_post     — CTA внутри статьи/поста
 *   src_site_botstub  — интерстишл-страница бота
 * Внешние конвейеры несут свои: src_habr_article<N>, src_pikabu_<slug>.
 */
class Social extends BaseConfig
{
    /** Бот игры — целевая (голая) ссылка; для CTA используй {@see self::botStart()}. */
    public string $botLink = 'https://t.me/wildworldrpg_bot';

    /** Группа-сообщество (новости и обсуждение) — целевая ссылка информационных блоков. */
    public string $groupLink = 'https://t.me/wild_world_info';

    /**
     * CTA-ссылка на бота с меткой источника для атрибуции первого касания.
     *
     * $source санитайзится так же, как в StartCommand (defense-in-depth: чужой
     * символ выпадет, ссылка не сломается). Пустой источник → голая ссылка
     * (для schema.org sameAs и прочих не-CTA мест трекинг не нужен).
     */
    public function botStart(string $source = ''): string
    {
        $source = preg_replace('/[^a-zA-Z0-9_-]/', '', $source) ?? '';

        return $source === '' ? $this->botLink : $this->botLink . '?start=' . $source;
    }
}
