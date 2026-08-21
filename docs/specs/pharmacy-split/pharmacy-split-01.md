---
story: pharmacy-split-01
spec: pharmacy-split
status: done
tier: 2
worker: worker-code
tracer: true
wave: 1
blocked_by: []
---

# Полка расходника и её отрисовка становятся отдельным, проверяемым слоем

## Goal
Появляется единственный источник правды «лекарство это или провизия» (`Config\Consumables`)
и чистый сервис `App\Services\Player\ConsumableShelfService`, который из строк выборки
собирает готовый текст экрана и кнопки — без БД, без Telegram, без `Request`. После этой
story экраны ещё не изменились: слой есть и тестируем, подключение — story 02.

## Requirements
> Возможно продукты и консервы надо убрать из аптечки в отдельную кнопку — не дело
> пересыпать тушёнку аспирином ☺️
> **Каждое лекарство называет раны, которые снимает** — строкой в своём описании, всегда,
> а не только при активной ране.
> Классификация живёт в `Config\Consumables`.

## Files
- app/Config/Consumables.php
- app/Services/Player/ConsumableShelfService.php

## Что именно классифицируем

`Config\Consumables` — две константы со списком `crafted_items.name_eng` (регистр и пробелы
как в БД, дословно):

`MEDICINE` (15): `headache_tablets`, `FirstAidKit`, `Common cold tincture`, `TonicElixir`,
`Bandage`, `AnalgesicPowder`, `Stimulator`, `Antiseptic`, `Sedative`, `Regenerator`,
`SyntheticMedicine`, `EmergencyTransfusion`, `SurgicalKit`, `WinterWarmingBalm`,
`SummerAloeBalm`.

`PROVISION` (28): `WinterHerbalBrew`, `WinterHoneyMead`, `WinterCampStew`, `WinterPreserves`,
`SpringFirstHerbTea`, `SpringBirchSap`, `SpringPrimroseInfusion`, `SpringWildGreens`,
`SpringShootsDecoction`, `SummerColdKvass`, `SummerBerryMors`, `SummerFruitWater`,
`SummerMintTea`, `AutumnBerryJam`, `AutumnMushroomStew`, `AutumnNutMix`, `AutumnCider`,
`AutumnVegPreserves`, `MushroomSoup`, `BerryBrew`, `BakedFruit`, `GrainPorridge`,
`HeartyStew`, `StewPreserve`, `DryRation`, `FishSoup`, `GrilledFish`, `FishPreserve`.

🔴 **Незнакомое имя → провизия.** Обоснуй это комментарием в файле: кулинарного контента
прибавляется в разы быстрее медицинского, и забытый в списке новый отвар должен попасть на
полку с едой, а не снова засорить аптечку. Забытое новое лекарство при этом остаётся
применимым — оно просто стоит не на своей полке, тупика не создаёт.

## API сервиса

```php
final class ConsumableShelfService
{
    public const SHELF_MEDICINE  = 'medicine';
    public const SHELF_PROVISION = 'provision';

    public function __construct(?ConsumableExpiryService $expiry = null, ?DebuffService $debuffs = null);

    /** @param list<array<string,mixed>> $rows строки выборки crafted_items_log+crafted_items */
    /** @return array{medicine: list<array<string,mixed>>, provision: list<array<string,mixed>>} */
    public function split(array $rows): array;

    /**
     * Готовый экран полки: заголовок, список предметов, кнопки применения.
     * $activeDebuffLines — уже отрисованные строки активных ран (пусто = раздела нет).
     * @return array{text: string, buttons: list<array{text: string, callback_data: string}>}
     */
    public function screen(string $shelf, array $rows, array $activeDebuffLines = []): array;
}
```

Формат строки предмета сохраняется как сейчас в `PharmacyAction` (название, количество, дозы
в начатой упаковке, срок годности, «Баф»), **плюс** для полки лекарств — строка снимаемых
ран, всегда, из `Config\Debuffs::curedByItem($nameEng)`:
`🩺 *Снимает:* 🤢 Отравление, 🥶 Обморожение`. Если предмет не снимает ничего — строки нет.

Кнопка применения — прежняя: `usePharmacy_<name_eng>`, никаких новых префиксов.

## Non-goals
- Не трогать `PharmacyAction`, `UsePharmacyAction`, `CallbackRoutes` — это story 02.
- Не менять `crafted_items.type` ни в БД, ни в коде, не писать миграций.
- Не заводить новых ключей `GameSettings` и не менять числа лечения/сроки годности.
- Не тащить в сервис `Request`/`MediaSender`/модели: он обязан оставаться чистым, иначе
  тесты story 03 снова станут скан-исходника вместо поведения.
- Не переписывать `Config\Debuffs` — только читать `curedByItem()`.

## Map slice
memory/map/ — раздела по аптечке нет; читать `app/Controllers/Telegram/Commands/Actions/PharmacyAction.php`
(текущая отрисовка, строки 70–140) и `app/Config/Debuffs.php`.

## Acceptance criteria
- [ ] `Consumables::shelfOf('StewPreserve')` → провизия, `shelfOf('Bandage')` → лекарство,
      `shelfOf('НовыйНеизвестныйПредмет')` → провизия.
- [ ] Списки `MEDICINE` и `PROVISION` не пересекаются; каждый предмет из
      `Debuffs::CATALOG[*]['cured_by']` лежит в `MEDICINE`.
- [ ] `screen()` для полки лекарств печатает строку «Снимает» у бинта, антисептика,
      регенератора и аптечки — при пустом списке активных ран тоже.
- [ ] `screen()` ничего не печатает про раны у предмета провизии.
- [ ] Текст самодостаточен без картинки (ADR-020): имя, количество, эффект, годность.
- [ ] Разметка Markdown парная (`*` не остаётся висеть) на любом наборе строк.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Config/Consumables.php app/Services/Player/ConsumableShelfService.php`

## Tracer
Слой проходит насквозь: классификация (Config) → сборка текста и кнопок (Service) →
формат, который story 02 отдаст в `MediaSender` без изменений.

## Implementation notes

- `Config\Consumables::shelfOf()` — незнакомое имя падает в `PROVISION` через тернарник
  (`in_array` по `MEDICINE`, иначе provision); `MEDICINE`/`PROVISION` проверены скриптом на
  дизъюнктность и на то, что каждый `Debuffs::CATALOG[*]['cured_by']` лежит в `MEDICINE` — ок.
- `ConsumableShelfService` принимает `?DebuffService $debuffs` по контракту API, но не читает
  его (активные раны приходят готовой строкой `$activeDebuffLines`, «снимает» — статика
  `Debuffs::curedByItem()`); добавлен геттер `debuffs()`, иначе phpstan (`property.onlyWritten`)
  и `constructor.unusedParameter` валят анализ.
- `screen()` принимает уже отфильтрованные под `$shelf` строки (результат `split()`), а не весь
  список — так `$shelf` однозначно решает, показывать ли раздел «Снимает» и «Сейчас на тебе».
- Формат строки предмета — дословно из `PharmacyAction` (name/qty → дозы → годность → «Баф»),
  плюс «🩺 Снимает» для лекарств; фикс phpstan level 9: `$effectValue` из JSON-бафа кастуется
  в string только через `is_scalar()`-проверку, не голым кастом.
- Правка от ревью (21.08.2026): при переносе `itemLine()` из `PharmacyAction` потерялась
  нормализация неразрывного пробела (`\xC2\xA0`) в `character_boost` перед `json_decode()` —
  без неё правка баффа через админку с NBSP молча даёт пустое «Баф:» (json_decode → null),
  без ошибки в логе. Вернул явным `str_replace("\xC2\xA0", ' ', ...)` — байтовый литерал, не
  сырой символ в исходнике, чтобы не зависеть от кодировки редактора.
- Валидация: сборка кода вручную (bootstrap CI4 через `php -r` не поднимается без framework
  init для `GameSettingsService`), плюс `shelfOf`/дизъюнктность/`cured_by`-покрытие проверены
  отдельным php-скриптом, phpstan — зелёный.
