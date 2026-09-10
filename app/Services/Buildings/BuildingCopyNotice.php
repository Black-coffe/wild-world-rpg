<?php

declare(strict_types=1);

namespace App\Services\Buildings;

/**
 * ADR будущий (building-bonus-absorption) — честные тексты про то, что игра сейчас
 * делает молча: бонус от здания начисляется один раз на тип, сколько бы копий и баз ни
 * было («поглощение»), а «Навес» — одноразовое стартовое укрытие, недоступное на второй базе.
 *
 * Чистый класс: без БД, без Telegram — на входе название/факты, на выходе строка. Экшены
 * ({@see \App\Controllers\Telegram\Commands\Actions\Camp\GenericBuildingInfoAction},
 * {@see \App\Controllers\Telegram\Commands\Actions\Camp\GenericBuildingAction}) только зовут
 * его и вставляют результат в caption/text. Формулировки живут тут и проверяются
 * {@see \Tests\Unit\Services\BuildingCopyNoticeTest} — phpstan к длине подписи и парности
 * `*` слеп, а именно там ломается Telegram (400 при непарной звёздочке, тихий обрыв за 1024 байт).
 */
class BuildingCopyNotice
{
    /** Лимит Telegram photo-caption в байтах — молчаливый отказ отправки за этой чертой. */
    public const CAPTION_BYTE_LIMIT = 1024;

    /**
     * Оговорка в превью здания, которое у персонажа уже есть (на любой базе).
     *
     * Держит два факта, верных для ЛЮБОГО здания: бонус начисляется один раз на тип здания
     * вне зависимости от числа баз; налог за копию на ЭТОЙ ЖЕ базе не растёт (копия увеличивает
     * `amount` в той же строке), но на КАЖДОЙ базе, где здание стоит, налог берётся отдельно.
     *
     * Круг 2 (ревью): «больше построек — крепче оборона от рейда» была ложью для 13 зданий
     * из 16 — {@see \App\Services\PVE\DefenseStructureService::activeStructures()} читает
     * бонус ТОЛЬКО у `building_type='defensive'`, и только на той клетке, где идёт бой; вторая
     * Мастерская/Склад/Теплица обороне не даёт ничего. Третий факт про оборону теперь появляется
     * ТОЛЬКО когда `$stacksDefense` = true — это стена/ограда (не вышка: она presence-based,
     * копия ей ничего не добавляет — см. `defenseStackFactor()`, вышка туда не попадает).
     */
    public static function duplicateWarning(string $emoji, string $nameRus, bool $stacksDefense): string
    {
        $text = "\n⚠️ *У тебя уже есть {$emoji} {$nameRus}.* Копия не даст новых бонусов — они "
            . 'начисляются один раз на тип здания на все базы. Налог с копии на этой базе не '
            . 'растёт, но с каждой базы берётся отдельно.';

        if ($stacksDefense) {
            $text .= ' Здесь копия усиливает оборону именно этой базы от рейда (с убывающей отдачей).';
        }

        return $text . "\n";
    }

    /**
     * Круг 2 (ревью, п.3) — короткий вариант для рецептов, где полной оговорке уже не хватает
     * бюджета: замеры (`php spark tmp:measure-caption`, удалён после замера) показали, что
     * happy-path превью Арсенала — 817 байт, Склада — 1022 байт из 1024 БЕЗ этой оговорки, а
     * `duplicateWarning()` добавляет ~330-430 байт. Экран не должен молча падать (Telegram
     * `ok=false`) из-за нашей добавки — короче название здания не повторяет (оно уже есть в
     * заголовке caption выше), держит только факты 1 и 2 (бонус один раз / налог с каждой базы
     * отдельно); факт про оборону в короткую версию не добавляем — у стены/ограды бюджет обычно
     * есть (это дешёвые ранние здания), к ним короткий вариант практически не применяется.
     */
    public static function duplicateWarningShort(bool $stacksDefense): string
    {
        $text = "\n⚠️ *Копия бонуса не даёт* — раз на тип здания на все базы, налог с каждой "
            . 'базы свой.';
        if ($stacksDefense) {
            $text .= ' Тут усиливает оборону от рейда.';
        }
        return $text . "\n";
    }

    /**
     * Пусть caption (уже собранный без оговорки) сам решает, какая версия влезает — полная,
     * короткая или никакая (пустая строка, ничего не добавляем). НИКОГДА не выталкивает
     * caption за {@see CAPTION_BYTE_LIMIT} тем, чего до нашей оговорки там не было — если и
     * короткая не влезает, значит caption был на пределе бюджета уже без нас (см. Склад выше).
     */
    public static function duplicateWarningFitting(string $captionSoFar, string $emoji, string $nameRus, bool $stacksDefense): string
    {
        $full = self::duplicateWarning($emoji, $nameRus, $stacksDefense);
        if (strlen($captionSoFar) + strlen($full) <= self::CAPTION_BYTE_LIMIT) {
            return $full;
        }

        $short = self::duplicateWarningShort($stacksDefense);
        if (strlen($captionSoFar) + strlen($short) <= self::CAPTION_BYTE_LIMIT) {
            return $short;
        }

        return '';
    }

    /**
     * Экран вместо превью/старта «Навеса», когда {@see \App\Services\Onboarding\FirstShelterService::leanToGateReason()}
     * гейтит: объясняет ПРИЧИНУ отказа, а не одно общее «недоступно» на все случаи.
     *
     * Круг 2 (ревью): единый текст «ставится один раз, на новых базах не появляется» был
     * враньём для ветерана 10 уровня, который Навес никогда не строил — ему нужна другая
     * причина (перерос порог новичка), а не «уже поставил».
     */
    public static function leanToGateExplanation(string $reason): string
    {
        $why = match ($reason) {
            \App\Services\Onboarding\FirstShelterService::REASON_ALREADY_USED
                => 'Он одноразовый: ты уже поставил его раньше, и на новых базах он больше не появляется.',
            \App\Services\Onboarding\FirstShelterService::REASON_OUTGREW
                => 'Его предлагают только новичкам без построек — ты уже перерос этот этап.',
            default => 'Сейчас оно временно недоступно для всех.',
        };

        return "*⛺ Навес — стартовое укрытие первых шагов.*\n{$why} Что построить здесь — "
            . 'смотри в списке построек.';
    }

    /**
     * Все player-facing строки класса — для теста markdown-парности и длины в байтах.
     * Разные названия и эмодзи специально: длина зависит от входа, а не только от шаблона.
     *
     * @return list<string>
     */
    public static function allSamples(): array
    {
        return [
            self::duplicateWarning('🚰', 'Ручная скважина', false),
            self::duplicateWarning('⚔️', 'Арсенал', false),
            self::duplicateWarning('🏚️', 'Склад', false),
            self::duplicateWarning('🪵', 'Деревянная стена', true),
            self::duplicateWarningShort(false),
            self::duplicateWarningShort(true),
            self::leanToGateExplanation(\App\Services\Onboarding\FirstShelterService::REASON_ALREADY_USED),
            self::leanToGateExplanation(\App\Services\Onboarding\FirstShelterService::REASON_OUTGREW),
            self::leanToGateExplanation(\App\Services\Onboarding\FirstShelterService::REASON_DISABLED),
        ];
    }
}
