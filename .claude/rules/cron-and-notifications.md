---
paths: ["app/TaskHandlers/**", "app/Services/Notifications/**", "app/Controllers/Worker.php", "app/Commands/**"]
---
# Крон, рассылки, наблюдаемость

Перенесено из `docs/defects/once-guard-in-cache.md` и `memory/learnings/CONSOLIDATED.md` старого VULYK.

- **«Раз в период» — не в кэше.** Деплой или `cache:clear` стирает маркер, и всем приходит дубль. Только атомарный
  условный UPDATE строки-маркера в БД (`WHERE value != :today`, `affectedRows() === 1`), claim до цикла;
  маркер сидится `''`, не NULL. Тест: отправить → очистить кэш → отправить → 0. Эталон —
  `DailyTipBroadcastHandler::claimToday()`.
- **Отправка из крона молча теряется — это класс** (3 случая). В CLI-кроне мост Telegram не инициализирован
  (`getBotUsername() on null`): бой записан, сообщение игроку потеряно. Любой путь, шлющий вне `BaseTaskHandler`,
  под подозрением. Фолбэк `BaseTaskHandler::telegram()` (`new Telegram('invalid','invalid')`) сам бросает исключение.
- **Порог лога на проде 4: `warning` не пишется.** Нет строки в прод-логе ≠ путь не выполнялся. Мониторинг —
  через `action_log`.
- **Нет таблицы `game_settings` (окно деплоя: rsync раньше миграций)** → `GameSettingsService` копит условия
  билдера и растёт по памяти. Растёт память — сначала `php spark migrate:status`, потом профайлер.
