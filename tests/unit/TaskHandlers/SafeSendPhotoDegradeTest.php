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
        $this->assertFileExists($target, 'цель должна быть реальным файлом (канонизирована realpath)');
        $this->assertStringEndsWith('hunter_vest.jpg', str_replace('\\', '/', $target));
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

    public function testExternalUrlDegradesToText(): void
    {
        // Анти-SSRF: чужой URL не фетчим, каким бы «настоящим» он ни был → текст.
        [, $missing] = $this->resolve('https://external.example.com/somewhere/x.jpg');

        $this->assertTrue($missing, 'внешний URL не наш сайт → не фетчим, деградация на текст');
    }

    public function testEmptyPathIsMissing(): void
    {
        [, $missing] = $this->resolve('');

        $this->assertTrue($missing, 'пустой путь (напр. base_url(null)) → сразу текст, не fopen корня');
    }

    public function testPublicButOutsideUploadsIsBlocked(): void
    {
        // public/index.php существует, но НЕ под uploads → конфайнмент отсекает.
        [, $missing] = $this->resolve(FCPATH . 'index.php');

        $this->assertTrue($missing, 'файл вне uploads (даже внутри public) → не отдаём в encodeFile');
    }

    public function testTraversalToSecretIsBlocked(): void
    {
        // Path traversal через `..` к секрету вне webroot. Файл РЕАЛЬНО существует
        // (.env лежит в корне проекта), но realpath уводит за границу uploads → блок.
        $secretAbs = FCPATH . 'uploads' . str_repeat(DIRECTORY_SEPARATOR . '..', 2) . DIRECTORY_SEPARATOR . '.env';
        [, $missingLocal] = $this->resolve($secretAbs);
        $this->assertTrue($missingLocal, 'traversal к .env локальным путём → блок → текст');

        // Тот же traversal, но через URL нашего сайта.
        $secretUrl = rtrim(base_url(), '/') . '/uploads/../../.env';
        [, $missingUrl] = $this->resolve($secretUrl);
        $this->assertTrue($missingUrl, 'traversal к .env через base_url → блок → текст');
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
