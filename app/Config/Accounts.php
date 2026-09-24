<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Web-аккаунты (ADR-188, web-accounts-p0) — константы безопасности.
 *
 * Это инфраструктура, а не баланс (ADR-024 не применяется): TTL кодов и токенов, длина кода,
 * минимальная длина пароля, лимиты троттлинга форм.
 */
class Accounts extends BaseConfig
{
    /** Время жизни одноразового кода из бота, секунд. */
    public int $linkCodeTtlSeconds = 600;

    /** Длина кода из бота (алфавит без 0/O/1/I/L). */
    public int $linkCodeLength = 8;

    /** Срок жизни remember-me, секунд (30 дней). */
    public int $rememberLifetimeSeconds = 2592000;

    /** Имя cookie remember-me (`selector:validator`). */
    public string $rememberCookie = 'ww_remember';

    /** Минимальная длина пароля. */
    public int $passwordMinLength = 8;

    /** Время жизни токена сброса пароля, секунд. */
    public int $passwordResetTtlSeconds = 3600;

    /** Лимит POST-запросов форм /account с одного IP в минуту. */
    public int $throttleIpPerMinute = 10;

    /** Лимит попыток на один идентификатор (email/код) в час. */
    public int $throttleIdentifierPerHour = 20;
}
