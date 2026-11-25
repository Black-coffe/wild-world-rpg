# Queued 🔒 craft is promoted when any 🔒 task ends (brief)
**Date:** 2026-11-25 · **Owner:** Andrei

## Request (verbatim)
> Что осталось:
> 1. F4. Поставленный в очередь 🔒-крафт может застрять в узкой гонке. На смоуке не встретился, подтверждён только чтением кода. Рекомендую следующей спекой: продвигать очередь при завершении любой 🔒-задачи.
> 2. Мелочи по вашему слову:
>    - отменить зависший run CI от 11.11;
>    - удалить на GitHub ветки bugs-info-0923 и w2-n7-combat;
>    - запушить документацию.
>
> Делайц

## Answers

## Asks
1. When any 🔒 task of a character ends (completed, interrupted or cancelled — gather, march, explore, repair, relocation, a craft of another recipe, …), that character's queued crafts are offered the freed 🔒 slot; a queued 🔒 craft no longer waits for a craft of its own recipe to finish.
2. Promotion stays atomic through the slot core (`ActiveTasksService::moveToInWork`): two promoters never start two 🔒 tasks, and a craft refused because the slot is taken stays `queued`, visible and cancellable with refund, as today.
3. The player learns that a queued craft started, with the same message the existing promotion sends (media-off complete).
4. The race from F4 (s1-atomicity-1124 Follow-up item 7) is covered by a test that fails on today's code.
5. Item 2 of the request (CI run, GitHub branches, docs push) was handled before this spec; not part of it.
6. Verdicts: `/guide` and tips — stated in the plan, with the reason.
