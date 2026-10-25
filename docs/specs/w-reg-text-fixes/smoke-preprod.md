# Smoke preprod — w-reg-text-fixes (2026-10-25)

- `scripts/preprod-parity.sh`: preprod matches prod.
- Telegram Web (testbot, аккаунт andreii0): «⚙️ Действия» → текст экрана жирным (`<strong>`), сырых `*` нет, кнопки прежние.
- Подсказка имени в вебе и путь к титулам — тестами (`AccountRegistrationTest`, `ReferralServiceTest`); живой триггер титула не вызывался.
