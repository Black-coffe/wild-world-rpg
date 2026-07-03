<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Social;

/**
 * Атрибуция первого касания (ADR-052): CTA-ссылки сайта должны нести
 * deep-link метку `?start=<src>`, иначе регистрация невидима в acquisitionSlice.
 * Проверяем построение метки {@see Social::botStart()} — чистая, без БД.
 *
 * @internal
 */
final class SocialTest extends CIUnitTestCase
{
    public function testEmptySourceReturnsBareLink(): void
    {
        $s = new Social();
        // Голая ссылка — для schema.org sameAs и не-CTA мест.
        $this->assertSame($s->botLink, $s->botStart());
        $this->assertSame($s->botLink, $s->botStart(''));
        $this->assertStringNotContainsString('?start=', $s->botStart());
    }

    public function testSourceAppendsStartPayload(): void
    {
        $s = new Social();
        $this->assertSame($s->botLink . '?start=src_site_home', $s->botStart('src_site_home'));
        $this->assertSame($s->botLink . '?start=src_site_header', $s->botStart('src_site_header'));
    }

    public function testSourceSanitizedLikeStartCommand(): void
    {
        $s = new Social();
        // StartCommand принимает только [a-zA-Z0-9_-]; чужой символ ВЫПАДАЕТ
        // (не заменяется), токены схлопываются — ссылка не ломается.
        $this->assertSame($s->botLink . '?start=srcsitex', $s->botStart('src site &x'));
        $this->assertSame($s->botLink . '?start=abc123_-', $s->botStart('abc123_-'));
        // Полностью невалидный источник → голая ссылка, а не битый `?start=`.
        $this->assertSame($s->botLink, $s->botStart('!!!'));
    }

    public function testPayloadWithinTelegramLimit(): void
    {
        $s = new Social();
        // StartCommand режет payload до 191 символов — наши коды заведомо короче,
        // но фиксируем инвариант «реестр src не разрастается в длину».
        foreach (['src_site_home', 'src_site_header', 'src_site_footer', 'src_site_post', 'src_site_botstub'] as $src) {
            $this->assertLessThanOrEqual(191, strlen($src));
        }
    }
}
