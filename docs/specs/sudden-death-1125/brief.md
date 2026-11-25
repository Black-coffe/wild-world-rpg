# Sudden death without warning (brief)
**Date:** 2026-11-25 · **Owner:** Andrei

## Request (verbatim)
> [Image #3] [24.11.2026 02:36] Евгений Лисовский: Надо что-то делать с внезапной смертностью.
> По местному времени:
> В 6:59 герой поел, здоровье было 100.
> В 7:20 закончил добычу.
> В 7:31 приходят два сообщения: здоровье 0.01 и следующее в эту же минуту - умер.
>
> Понимаю, что был обвал где-то в пещерах, но...
> [24.11.2026 17:15] Евгений Лисовский in reply to Andrei Andrievskii:
> > Жизнь она такая.... Зато как реалистично))
> ну хз. или ночью копаешь. просыпаешься - умер. причём предпосылок и не было.
> [24.11.2026 17:16] Евгений Лисовский: я на северах как-то просто шёл походом и не дошёл. выходил здоровым. обидно.

Screenshot (Image #3), transcribed — the chat on November 24:
> Евгений Лисовский: Надо что-то делать с внезапной смертностью. … Понимаю, что был обвал где-то в пещерах, но… (02:36)
> Andrei Andrievskii: Жизнь она такая.... Зато как реалистично)) (16:18)
> Andrei Andrievskii: Просто надо назвать: Инсульт или Инфаркт (16:18)
> Евгений Лисовский (reply): ну хз. или ночью копаешь. просыпаешься - умер. причём предпосылок и не было. (17:15)
> Евгений Лисовский: я на северах как-то просто шёл походом и не дошёл. выходил здоровым. обидно. (17:16)

## Answers
- 2026-11-25 · direction (cause: a world event drains 3–10 HP ~every third minute with no messages, floor 0.01 HP where the death roulette kills 99% the same minute the first warning arrives; hunger also floors at 0.01) — «A: пассивное не убивает (Рекомендую)»: events and hunger lower HP only to a safe floor 1.0 HP (GameSettings key, like poison); the roulette stays only for chosen risk (march or step into a dangerous biome); plus damage and «уйди на базу» in the event notice, one warning when HP drops below a threshold, march preview shows the danger surcharge and resulting HP, death message names the cause and the damage.
- 2026-11-25 · price of the floor — «Прервать добычу в биоме (Рекомендую)»: at the floor, gathering in the event's biome is interrupted with a «go to base» message; a new one does not start while HP is below a threshold (GameSettings key).
- 2026-11-25 · loot of a gather interrupted by an event at the floor (today an interrupted gather yields nothing) — «Доля по времени (Рекомендую)»: loot proportional to elapsed time (one normal roll × elapsed/total, rounded down, no guaranteed minimum); the message names what was gathered; manual cancel unchanged.

## Asks
1. World events and the daily hunger never take HP below a safe floor (GameSettings key, default 1.0, hard lower bound above the roulette threshold 0.99): a character hit only by them never reaches the death roulette. Poison keeps its own floor 1.0.
2. Chosen risk stays lethal: march and single steps into dangerous biomes, as today.
3. When an event (or hunger) brings HP to the floor while the character gathers in that event's biome, the gather is interrupted with a message telling to go to base (media-off complete) and the player keeps loot proportional to the elapsed time (one normal roll × elapsed/total, rounded down, no guaranteed minimum; never more than a full gather for the same time; never paid twice if the gather also completes that minute), the message names what was gathered; manual cancel stays as today; a new gather in a biome of an active damage event does not start while HP is below a threshold (GameSettings key), with the refusal saying why and what to do — in both clients (bot and `/play`).
4. The event start notice states that the event damages health (roughly how much) and that on the own base it is safe.
5. When passive damage drops HP below a warning threshold (GameSettings key), the player gets one warning before HP reaches the floor — not in the same minute as the roulette; a single hit that jumps over several thresholds gives one message.
6. The march preview shows the danger-biome surcharge and the HP left at the end; a march that would end below the roulette threshold says so before the player confirms.
7. The death message names the cause and the damage that led to it (which event / hunger / march, how many HP over what time).
8. Every new number is in GameSettings with rationale / effect / above / below, soft and hard bounds; no balance number in code.
9. ADR for the rule «passive sources do not kill» (🟠); tech-writing notes for every touched service/handler; verdicts for `/guide` and tips stated, with the reason.
