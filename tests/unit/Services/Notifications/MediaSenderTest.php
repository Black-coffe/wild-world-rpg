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

    // ---------------------------------------------------------------------
    // buildEditTextOnlyParams() — text-handler'ы (Sell/, Quest/ и т.п.): sendMessage → editMessageText
    // ---------------------------------------------------------------------

    public function testBuildEditTextOnlyParamsPassesTextAndIds(): void
    {
        $out = MediaSender::buildEditTextOnlyParams([
            'chat_id'    => 555,
            'message_id' => 666,
            'text'       => 'Меню продажи',
        ]);

        $this->assertSame(555, $out['chat_id']);
        $this->assertSame(666, $out['message_id']);
        $this->assertSame('Меню продажи', $out['text']);
    }

    public function testBuildEditTextOnlyParamsPassesThroughOptionalKeys(): void
    {
        $kb  = ['inline_keyboard' => [[['text' => '🔙', 'callback_data' => 'shop']]]];
        $out = MediaSender::buildEditTextOnlyParams([
            'chat_id'                  => 1,
            'message_id'               => 2,
            'text'                     => 'x',
            'parse_mode'               => 'Markdown',
            'reply_markup'             => $kb,
            'disable_web_page_preview' => true,
        ]);

        $this->assertSame('Markdown', $out['parse_mode']);
        $this->assertSame($kb, $out['reply_markup']);
        $this->assertTrue($out['disable_web_page_preview']);
    }

    public function testBuildEditTextOnlyParamsOmitsAbsentOptionalKeys(): void
    {
        $out = MediaSender::buildEditTextOnlyParams([
            'chat_id'    => 1,
            'message_id' => 2,
            'text'       => 'x',
        ]);

        $this->assertArrayNotHasKey('parse_mode', $out);
        $this->assertArrayNotHasKey('reply_markup', $out);
        $this->assertArrayNotHasKey('disable_web_page_preview', $out);
        // photo/caption тут вообще не при делах
        $this->assertArrayNotHasKey('photo', $out);
        $this->assertArrayNotHasKey('caption', $out);
    }

    public function testBuildEditTextOnlyParamsEmptyTextFallsBackToPlaceholder(): void
    {
        $out = MediaSender::buildEditTextOnlyParams([
            'chat_id'    => 1,
            'message_id' => 2,
        ]);
        $this->assertSame('📭 (без описания)', $out['text']);

        $out2 = MediaSender::buildEditTextOnlyParams([
            'chat_id'    => 1,
            'message_id' => 2,
            'text'       => '',
        ]);
        $this->assertSame('📭 (без описания)', $out2['text']);
    }

    // ---------------------------------------------------------------------
    // captionExceedsPhotoLimit() — гард длины подписи фото.
    // Прод-инцидент 2026-06-16: gather-завершение high-level в богатом биоме
    // (24 ресурса в «Пещерах») → caption >1024 → sendPhoto ok=false → уведомление
    // тихо терялось. Гард деградирует к тексту (картинка = enhancement, MEDIA-OFF).
    // ---------------------------------------------------------------------

    public function testCaptionExceedsPhotoLimitFalseForShortCaption(): void
    {
        $this->assertFalse(MediaSender::captionExceedsPhotoLimit('Короткая подпись'));
        $this->assertFalse(MediaSender::captionExceedsPhotoLimit(''));
    }

    public function testCaptionExceedsPhotoLimitBoundaryAt1024(): void
    {
        // Telegram allows up to 1024 inclusive → ровно 1024 НЕ деградирует, 1025 — да.
        $this->assertFalse(MediaSender::captionExceedsPhotoLimit(str_repeat('a', 1024)));
        $this->assertTrue(MediaSender::captionExceedsPhotoLimit(str_repeat('a', 1025)));
    }

    public function testCaptionExceedsPhotoLimitCountsVisibleTextNotRawHtml(): void
    {
        // 400 видимых символов, обёрнутых в множество <b></b> → сырая длина ~1800,
        // но Telegram меряет ПОСЛЕ парсинга entity (видимые 400) → НЕ деградируем.
        $caption = str_repeat('<b>ab</b>', 200);
        $this->assertGreaterThan(1024, mb_strlen($caption));               // сырая >1024
        $this->assertLessThanOrEqual(1024, mb_strlen(strip_tags($caption))); // видимая ≤1024
        $this->assertFalse(MediaSender::captionExceedsPhotoLimit($caption));
    }

    public function testCaptionExceedsPhotoLimitCountsEmojiAsTwoUtf16Units(): void
    {
        // 520 эмодзи = 520 кодпоинтов, но 1040 UTF-16 units (>1024) — как считает Telegram.
        $caption = str_repeat('🪨', 520);
        $this->assertSame(520, mb_strlen($caption));
        $this->assertTrue(MediaSender::captionExceedsPhotoLimit($caption));
    }

    public function testCaptionExceedsPhotoLimitTrueForRealisticLootList(): void
    {
        // Реконструкция подписи завершения добычи: 24 ресурса с заголовками редкости.
        $caption = "<b>Успешная добыча ресурсов!</b>\nВремя: <b>720</b> мин.\n"
            . "Биом: <b>Пещеры и подземелья</b>\n\n<b>Найдены следующие ресурсы:</b>\n";
        for ($i = 0; $i < 24; $i++) {
            $caption .= "\n<b>Редкость...</b>\n— <b>Сталактиты и сталагмиты {$i}</b>: 1234 шт.\n";
        }
        $this->assertTrue(MediaSender::captionExceedsPhotoLimit($caption));
    }
}
