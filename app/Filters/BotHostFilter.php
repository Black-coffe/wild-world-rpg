<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * ADR-052 — устранение SEO-дубля «поддомен бота = копия сайта».
 *
 * Одна кодовая база обслуживает два хоста (см. App::$allowedHostnames):
 *   - wildworld.fun / testbot.wildworld.fun — ПОЛНЫЙ публичный сайт (фильтр no-op);
 *   - bot.wildworld.fun — ТОЛЬКО технический хост бота, сайт здесь не дублируем:
 *       • /telegram/*  → проходит к вебхуку (бот не трогаем);
 *       • корень `/`   → одна noindex-заглушка (видимая страница «это бот»);
 *       • всё прочее   → 301 на канонический wildworld.fun/<тот-же-путь>
 *                        (схлопывание любых случайно проиндексированных URL).
 *
 * Стоит первым в Filters::$required['before'] — до pagecache (чтобы не отдать
 * закэшированную страницу сайта на хосте бота) и до роутинга.
 */
class BotHostFilter implements FilterInterface
{
    private const BOT_HOST  = 'bot.wildworld.fun';
    private const SITE_BASE = 'https://wildworld.fun/';

    public function before(RequestInterface $request, $arguments = null)
    {
        $uri = $request->getUri();

        // Действуем только на хосте бота; для сайта/препрода — полный no-op (fall-through).
        if ($uri->getHost() === self::BOT_HOST) {
            $path = trim($uri->getPath(), '/');

            // Вебхук и служебные telegram-роуты не трогаем (бот живёт) — fall-through.
            if ($path !== 'telegram' && ! str_starts_with($path, 'telegram/')) {
                // Корень поддомена — видимая заглушка, помеченная noindex.
                if ($path === '') {
                    return Services::response()
                        ->setHeader('X-Robots-Tag', 'noindex, follow')
                        ->setBody(view('site/bot_stub'));
                }

                // Остальные пути — постоянный редирект на канонический сайт.
                $query  = $uri->getQuery();
                $target = self::SITE_BASE . $path . ($query !== '' ? '?' . $query : '');

                return redirect()->to($target, 301);
            }
        }

        // Сайт / препрод / вебхук бота — пропускаем запрос дальше без изменений.
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }
}
