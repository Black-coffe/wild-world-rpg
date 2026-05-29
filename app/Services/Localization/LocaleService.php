<?php

declare(strict_types=1);

namespace App\Services\Localization;

use App\Entities\CharacterEntity;
use Config\Services;

/**
 * W27 (ADR-082) — per-character locale: резолв + применение языка интерфейса.
 *
 * Источник — characters.locale (ru/en, default ru). `applyForCharacter` ставит
 * локаль CI4 Language-сервиса на текущий запрос (webhook = 1 update = 1 игрок),
 * после чего все `lang()` отдают строки на языке игрока. Вызывается из BaseAction
 * (и SettingsAction) после резолва персонажа.
 *
 * Неизвестная/пустая локаль → SUPPORTED[0] ('ru'), чтобы не выставить
 * невалидный язык. Список синхронен Config\App::$supportedLocales.
 */
final class LocaleService
{
    /** @var list<string> поддерживаемые локали (default — первая) */
    public const SUPPORTED = ['ru', 'en'];

    /**
     * Валидная локаль персонажа (default 'ru' при отсутствии/невалидной).
     *
     * @param array<int|string,mixed>|CharacterEntity|null $character
     */
    public function localeFor(array|CharacterEntity|null $character): string
    {
        if ($character === null) {
            return self::SUPPORTED[0];
        }
        $raw = $character['locale'] ?? null;
        $loc = is_string($raw) ? strtolower(trim($raw)) : '';
        return in_array($loc, self::SUPPORTED, true) ? $loc : self::SUPPORTED[0];
    }

    /**
     * Применяет локаль персонажа к Language-сервису текущего запроса.
     *
     * @param array<int|string,mixed>|CharacterEntity|null $character
     */
    public function applyForCharacter(array|CharacterEntity|null $character): void
    {
        $locale = $this->localeFor($character);
        Services::language()->setLocale($locale !== '' ? $locale : self::SUPPORTED[0]);
    }
}
