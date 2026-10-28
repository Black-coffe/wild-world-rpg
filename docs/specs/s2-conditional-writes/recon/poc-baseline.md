# PoC baseline до волны 1 (2026-10-28, develop ba43d401, пофайлово, последовательно)

`for f in tests/exploit-poc/*.php; do vendor/bin/phpunit --no-coverage --no-progress "$f"; done` — локальная
`wildworld_tests`, MySQL Laragon. **7 красных, 0 ошибок окружения.**

| Файл | Красные |
|---|---|
| DuplicationTest | — |
| EconomyLimitsTest | — (F6 закрыт v0.51.691, ADR-192) |
| GapAuditTest | `testConfirmationCreatesOneRelocationTask` (индексы — S1) |
| MultiAccountTest | `testOracleMaxStakeCapLimitsCombinedExposureFromMultipleCharacters` (F12), `testLadderPointsHaveNoDiminishingReturnsOrCapForRepeatedSameOpponent` (F18), `testTalkSpamReachesAllyGateWithoutAnyCooldown` (F17) — S5 |
| RepeatedHandlerTest | — |
| TaskExclusivityTest | `testCharacterTasksHasUniqueConstraintOnExclusiveSlot`, `testExclusiveGateRefusesSecondStartBeforeFirstWriteLands`, `testQuestStartCannotCreateTwoQuestStepsRows` — S1 |
| TeleportChargeTest | — |
| TradeInputTest | — |

Гейт ask 15: после спеки — ровно эти 7 (или меньше), ни одного нового, 0 ошибок окружения.
