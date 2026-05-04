<?php

namespace Config;

use App\Filters\LoginFilter;
use App\Filters\TelegramRateLimitFilter;
use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseConfig
{
    /**
     * Configures aliases for Filter classes to
     * make reading things nicer and simpler.
     *
     * @var array<string, class-string|list<class-string>> [filter_name => classname]
     *                                                     or [filter_name => [classname1, classname2, ...]]
     */
    public array $aliases = [
        'csrf'              => CSRF::class,
        'toolbar'           => DebugToolbar::class,
        'honeypot'          => Honeypot::class,
        'invalidchars'      => InvalidChars::class,
        'secureheaders'     => SecureHeaders::class,
        'login'             => LoginFilter::class,
        'telegramRateLimit' => TelegramRateLimitFilter::class,
    ];

    /**
     * List of filter aliases that are always
     * applied before and after every request.
     *
     * @var array<string, array<string, array<string, string>>>|array<string, list<string>>
     */
    public array $globals = [
        'before' => [
            // F0.7 — CSRF включён глобально для всех POST-запросов.
            // Исключение: telegram/webhook — это API endpoint от Telegram,
            // у Telegram нет токена нашего фреймворка. Защиту webhook'а
            // обеспечивает X-Telegram-Bot-API-Secret-Token (см. F0.9).
            'csrf' => ['except' => ['telegram/webhook']],
            // 'honeypot',
            // 'invalidchars',
        ],
        'after' => [
            'toolbar',
            // 'honeypot',
            // 'secureheaders',
        ],
    ];

    /**
     * List of filter aliases that works on a
     * particular HTTP method (GET, POST, etc.).
     *
     * Example:
     * 'post' => ['foo', 'bar']
     *
     * If you use this, you should disable auto-routing because auto-routing
     * permits any HTTP method to access a controller. Accessing the controller
     * with a method you don't expect could bypass the filter.
     */
    public array $methods = [];

    /**
     * List of filter aliases that should run on any
     * before or after URI patterns.
     *
     * Example:
     * 'isLoggedIn' => ['before' => ['account/*', 'profiles/*']]
     */
    public array $filters = [
        'login'             => ['before' => ['drivers*']],
        // F1.5 — rate-limit на /telegram/webhook. До 30 update'ов/мин на
        // одного telegram-юзера. См. app/Filters/TelegramRateLimitFilter.php.
        'telegramRateLimit' => ['before' => ['telegram/webhook']],
    ];
}
