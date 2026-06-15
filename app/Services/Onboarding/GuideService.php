<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

/**
 * ADR-127 — рендер экранов команды «📖 Путь новичка» (/guide).
 *
 * Строит payload'ы (text + reply_markup) для двух поверхностей:
 *   • {@see \App\Controllers\Telegram\Commands\GuideCommand} — оглавление новым сообщением;
 *   • {@see \App\Controllers\Telegram\Commands\Actions\Guide\GuideAction} — навигация
 *     edit-in-place (оглавление ↔ разделы).
 *
 * 🔴 АНТИ-АБЬЮЗ: сервис ЧИСТО читающий. Никаких моделей записи, грантов, телепортов,
 * раскрытия карты, мутаций персонажа. Единственный вход данных — {@see GuideCatalog}
 * (статический текст). Поэтому /guide безопасно звать сколько угодно раз.
 *
 * 🖼 MEDIA-OFF: payload'ы текстовые (никаких 'photo'); отправитель использует
 * Request::sendMessage / MediaSender::editTextOrSend — оба корректны при disable_media.
 */
class GuideService
{
    /** callback_data для возврата в оглавление. */
    public const CALLBACK_INDEX = 'guide';

    /** Префикс callback_data раздела: `guide_<key>`. */
    public const CALLBACK_PREFIX = 'guide_';

    /**
     * Payload оглавления: вступление + сгруппированный список тем + сетка кнопок.
     *
     * @return array{text:string, reply_markup:string}
     */
    public function indexPayload(): array
    {
        $text = "📖 *Путь новичка*\n\n"
            . "Выбери тему — расскажу, как всё устроено и куда нажимать. "
            . "Это *справочник*: только пояснения, без наград и без выдачи. "
            . "Заглядывай сколько угодно раз.\n";

        $rows  = [];
        $byGroup = [];
        foreach (GuideCatalog::sections() as $section) {
            $byGroup[$section['group']][] = $section;
        }

        foreach (GuideCatalog::GROUPS as $groupKey => $groupTitle) {
            $sections = $byGroup[$groupKey] ?? [];
            if ($sections === []) {
                continue;
            }

            $text .= "\n{$groupTitle}\n";
            $rowButtons = [];
            foreach ($sections as $section) {
                $text .= '• ' . $section['title'] . "\n";
                $rowButtons[] = [
                    'text'          => $section['button'],
                    'callback_data' => self::CALLBACK_PREFIX . $section['key'],
                ];
                // По 2 кнопки в ряд для компактной сетки.
                if (count($rowButtons) === 2) {
                    $rows[]     = $rowButtons;
                    $rowButtons = [];
                }
            }
            if ($rowButtons !== []) {
                $rows[] = $rowButtons;
            }
        }

        return [
            'text'         => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $rows]) ?: '{}',
        ];
    }

    /**
     * Payload раздела по ключу. Неизвестный ключ → оглавление (безопасный fallback).
     *
     * @return array{text:string, reply_markup:string}
     */
    public function sectionPayload(string $key): array
    {
        $section = GuideCatalog::find($key);
        if ($section === null) {
            return $this->indexPayload();
        }

        $text = $section['title'] . "\n\n" . $section['body'];

        $navRow  = [['text' => '⬅️ К оглавлению', 'callback_data' => self::CALLBACK_INDEX]];
        $nextKey = GuideCatalog::nextKey($key);
        if ($nextKey !== null) {
            $next = GuideCatalog::find($nextKey);
            if ($next !== null) {
                $navRow[] = [
                    'text'          => 'Дальше: ' . $next['button'] . ' ▶️',
                    'callback_data' => self::CALLBACK_PREFIX . $nextKey,
                ];
            }
        }

        return [
            'text'         => $text,
            'reply_markup' => json_encode(['inline_keyboard' => [$navRow]]) ?: '{}',
        ];
    }

    /**
     * Ключ раздела из сырого callback_data (`guide` → '', `guide_combat` → 'combat').
     * Пустая строка означает «оглавление».
     */
    public static function keyFromCallback(string $callbackData): string
    {
        if (str_starts_with($callbackData, self::CALLBACK_PREFIX)) {
            return substr($callbackData, strlen(self::CALLBACK_PREFIX));
        }

        return '';
    }
}
