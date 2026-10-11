# Прод-смоук — duel-first-strike-coin (v0.51.694)

**Дата:** 2026-10-11 · релиз `2026-10-11-184903` (GitHub Actions run 38165097431, ✓) · только чтение, без боёв на проде.

- Миграция `FixArmorResistancesTipMarkdown` применена (batch 370). Совет #68 `ArmorResistancesPvP`: `armor_value` — нет,
  «базовая защита брони» — есть. Следующая рассылка советов 12.10 ~10:00 — проверить отсутствие «byte offset 542».
- Лог прода после выкатки (с 21:44): ни одной ERROR/CRITICAL, кроме известного «bot was blocked by the user».
- Дуэль на проде не запускали (живые игроки); путь проверен на preprod (`smoke-preprod.md`).
