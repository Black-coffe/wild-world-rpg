<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers;

use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Ядро деградации фото→текст в `BaseTaskHandler::safeSendPhoto` (аудит 2026-07-10, B2/C4).
 *
 * Проверяем `resolveLocalPhoto()` — решение «слать фото или сразу текст». Саму отправку не
 * тестируем: она бьёт в Telegram (урок taskhandler_telegram_init_in_tests — не eager-init'им).
 * Резолвер же чист: `is_file` + маппинг `base_url(...)` обратно в `public/`. Именно он ловил
 * бы отсутствующий файл ДО `encodeFile`, чтобы уведомление не терялось молча.
 *
 * @internal
 */
final class SafeSendPhotoDegradeTest extends CIUnitTestCase
{
    private SafeSendPhotoStubHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        // Конструктор BaseTaskHandler дефолтный, Telegram инициализируется лениво → инстанс безопасен.
        $this->handler = new SafeSendPhotoStubHandler();
    }

    /**
     * @return array{0:string,1:bool} [encodeTarget, definitelyMissing]
     */
    private function resolve(string $photoPath): array
    {
        return $this->handler->exposeResolve($photoPath);
    }

    public function testExistingLocalAbsolutePathIsNotMissing(): void
    {
        $abs = FCPATH . 'uploads/telegram/craft/standard/hunter_vest.jpg';
        [$target, $missing] = $this->resolve($abs);

        $this->assertFalse($missing, 'существующий локальный файл не должен считаться отсутствующим');
        $this->assertSame($abs, $target);
    }

    public function testMissingLocalAbsolutePathIsMissing(): void
    {
        [, $missing] = $this->resolve(FCPATH . 'uploads/telegram/NO_SUCH_FILE_x.jpg');

        $this->assertTrue($missing, 'отсутствующий локальный файл → деградация на текст');
    }

    public function testOwnSiteUrlMapsToPublicAndDetectsExisting(): void
    {
        $url = rtrim(base_url(), '/') . '/uploads/telegram/craft/standard/hunter_vest.jpg';
        [$target, $missing] = $this->resolve($url);

        $this->assertFalse($missing, 'URL нашего сайта на существующий файл — не отсутствует');
        $this->assertStringStartsWith(FCPATH, $target, 'URL должен резолвиться в локальный путь (надёжнее HTTP)');
        $this->assertStringEndsWith('hunter_vest.jpg', str_replace('\\', '/', $target));
    }

    public function testOwnSiteUrlToMissingFileIsMissing(): void
    {
        $url = rtrim(base_url(), '/') . '/uploads/telegram/craft/standard/NO_SUCH_x.jpg';
        [, $missing] = $this->resolve($url);

        $this->assertTrue($missing, 'URL нашего сайта на несуществующий файл → деградация на текст');
    }

    public function testExternalUrlIsNotFlaggedMissingAndPassedThrough(): void
    {
        $url = 'https://external.example.com/somewhere/x.jpg';
        [$target, $missing] = $this->resolve($url);

        $this->assertFalse($missing, 'внешний URL не проверяем на диске — не считаем отсутствующим');
        $this->assertSame($url, $target, 'внешний URL уходит в encodeFile как есть (try/catch подстрахует)');
    }

    public function testEmptyPathIsMissing(): void
    {
        [, $missing] = $this->resolve('');

        $this->assertTrue($missing, 'пустой путь (напр. base_url(null)) → сразу текст, не fopen корня');
    }
}

/**
 * Пустышка: раскрывает protected `resolveLocalPhoto` типизированной обёрткой (без reflection→mixed).
 *
 * @internal
 */
final class SafeSendPhotoStubHandler extends BaseTaskHandler
{
    public function handle(array $task = []): void {}

    /**
     * @return array{0:string,1:bool}
     */
    public function exposeResolve(string $photoPath): array
    {
        return $this->resolveLocalPhoto($photoPath);
    }
}
