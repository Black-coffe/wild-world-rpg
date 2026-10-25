# W-REG: мелочи текста — титулы, звёздочки, подсказка имени (plan)

**Tier:** 1 · **Spec slug:** `w-reg-text-fixes` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-190 (нейтральное ядро), ADR-020 (media-off), память `feedback_legacy_markdown_no_backslash_escape`
**Depends on:** `web-onboarding` (v0.51.698)

## Goal
Три формулировки, которые врут или ломаются в одном из клиентов, приводятся к правде для обоих: путь к
титулам — через хаб «📊 Прогресс» (метка из единого источника), экран «Действия» — legacy Markdown без
`**`, веб-подсказка имени — нейтральное правило до ввода, ошибка — только после неверного ввода.

## Assumptions
- Отчёт 11.10 называл верным путём «🔗 Мой профиль» — это ссылка на публичный веб-профиль, а «🎖 Титулы»
  живёт в хабе «📊 Прогресс» карточки (`ProfileHubService::progressButtons`, `CharacterSheetService:331`);
  берём хаб.
- Тексты экрана «Действия» оставляем дословно, меняем только разметку `**` → `*`.

## Stories

**Wave 1**
- `w-reg-text-fixes-01` — путь к титулам, `**` на экране действий, нейтральная подсказка имени в вебе

## Contracts
- none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:**
**Briefed:** via mini-brief, Andrei, 2026-10-25
**Branch:** vulyk/w-reg-text-fixes
**Checked:**
**Council:**
**Shipped:**
