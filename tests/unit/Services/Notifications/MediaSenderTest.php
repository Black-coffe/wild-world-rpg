<?php

namespace Tests\Unit\Services\Notifications;

use App\Services\Notifications\MediaSender;
use CodeIgniter\Test\CIUnitTestCase;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;

/**
 * Идея #12 (edit-in-place), батч 1 — тесты на чистые трансформации параметров
 * для {@see MediaSender::editOrSend()}.
 *
 * Покрываются pure-методы buildEditTextParams() / buildEditMediaParams(): они
 * не трогают сеть и БД. Сам editOrSend() (выбор ветки + fallback) проверяется
 * Telegram-smoke'ом на testbot при вайр-ине handler'ов в следующих батчах.
 *
 * @internal
 */
final class MediaSenderTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MediaSender::reset();
    }

    // ---------------------------------------------------------------------
    // buildEditTextParams() — ветка media-disabled (#14): caption → text
    // ---------------------------------------------------------------------

    public function testBuildEditTextParamsMapsCaptionToText(): void
    {
        $out = MediaSender::buildEditTextParams([
            'chat_id'    => 111,
            'message_id' => 222,
            'photo'      => 'some_photo.jpg',
            'caption'    => 'Меню базы',
        ]);

        $this->assertSame(111, $out['chat_id']);
        $this->assertSame(222, $out['message_id']);
        $this->assertSame('Меню базы', $out['text']);
        // photo в editMessageText не нужен.
        $this->assertArrayNotHasKey('photo', $out);
        $this->assertArrayNotHasKey('caption', $out);
    }

    public function testBuildEditTextParamsPassesThroughOptionalKeys(): void
    {
        $out = MediaSender::buildEditTextParams([
            'chat_id'                   => 1,
            'message_id'                => 2,
            'caption'                   => 'x',
            'parse_mode'                => 'HTML',
            'reply_markup'              => ['inline_keyboard' => []],
            'disable_web_page_preview'  => true,
        ]);

        $this->assertSame('HTML', $out['parse_mode']);
        $this->assertSame(['inline_keyboard' => []], $out['reply_markup']);
        $this->assertTrue($out['disable_web_page_preview']);
    }

    public function testBuildEditTextParamsOmitsAbsentOptionalKeys(): void
    {
        $out = MediaSender::buildEditTextParams([
            'chat_id'    => 1,
            'message_id' => 2,
            'caption'    => 'x',
        ]);

        $this->assertArrayNotHasKey('parse_mode', $out);
        $this->assertArrayNotHasKey('reply_markup', $out);
        $this->assertArrayNotHasKey('disable_web_page_preview', $out);
    }

    public function testBuildEditTextParamsEmptyCaptionFallsBackToPlaceholder(): void
    {
        $out = MediaSender::buildEditTextParams([
            'chat_id'    => 1,
            'message_id' => 2,
        ]);
        $this->assertSame('📭 (без описания)', $out['text']);

        $out2 = MediaSender::buildEditTextParams([
            'chat_id'    => 1,
            'message_id' => 2,
            'caption'    => '',
        ]);
        $this->assertSame('📭 (без описания)', $out2['text']);
    }

    // ---------------------------------------------------------------------
    // buildEditMediaParams() — обычная ветка: InputMediaPhoto(photo+caption+parse_mode)
    // ---------------------------------------------------------------------

    public function testBuildEditMediaParamsWrapsPhotoIntoInputMediaPhoto(): void
    {
        $out = MediaSender::buildEditMediaParams([
            'chat_id'    => 333,
            'message_id' => 444,
            'photo'      => 'base_menu.jpg',
            'caption'    => 'Лагерь',
            'parse_mode' => 'HTML',
        ]);

        $this->assertSame(333, $out['chat_id']);
        $this->assertSame(444, $out['message_id']);
        $this->assertInstanceOf(InputMediaPhoto::class, $out['media']);

        /** @var InputMediaPhoto $media */
        $media = $out['media'];
        $this->assertSame('photo', $media->getType());
        $this->assertSame('base_menu.jpg', $media->getMedia());
        $this->assertSame('Лагерь', $media->getCaption());
        $this->assertSame('HTML', $media->getParseMode());
    }

    public function testBuildEditMediaParamsPassesReplyMarkupAlongside(): void
    {
        $kb  = ['inline_keyboard' => [[['text' => '🔙', 'callback_data' => 'Base']]]];
        $out = MediaSender::buildEditMediaParams([
            'chat_id'      => 1,
            'message_id'   => 2,
            'photo'        => 'x.jpg',
            'caption'      => 'y',
            'reply_markup' => $kb,
        ]);

        $this->assertSame($kb, $out['reply_markup']);
        // reply_markup идёт отдельным полем editMessageMedia, не внутрь InputMediaPhoto.
        $this->assertInstanceOf(InputMediaPhoto::class, $out['media']);
    }

    public function testBuildEditMediaParamsOmitsEmptyCaptionAndAbsentOptionalKeys(): void
    {
        $out = MediaSender::buildEditMediaParams([
            'chat_id'    => 1,
            'message_id' => 2,
            'photo'      => 'x.jpg',
        ]);

        /** @var InputMediaPhoto $media */
        $media = $out['media'];
        $this->assertSame('x.jpg', $media->getMedia());
        // пустой caption не передаётся (Telegram бы отверг 0-длину в некоторых случаях,
        // и нет смысла слать пустую строку) → getCaption()/getParseMode() пусты
        $this->assertEmpty($media->getCaption());
        $this->assertEmpty($media->getParseMode());
        $this->assertArrayNotHasKey('reply_markup', $out);
    }

    public function testBuildEditMediaParamsHandlesMissingPhotoGracefully(): void
    {
        // photo не задан — не падаем, отдаём пустую строку (editOrSend всё равно
        // упадёт в fallback при реальной отправке, но трансформация чистая).
        $out = MediaSender::buildEditMediaParams([
            'chat_id'    => 1,
            'message_id' => 2,
            'caption'    => 'z',
        ]);

        /** @var InputMediaPhoto $media */
        $media = $out['media'];
        $this->assertSame('', $media->getMedia());
        $this->assertSame('z', $media->getCaption());
    }
}
