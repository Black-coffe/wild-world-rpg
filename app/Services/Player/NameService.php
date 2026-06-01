<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\CharacterModel;

/**
 * N4 (ADR-039) — единый источник правды для установки/смены имени персонажа.
 *
 * Используется и slash-командой `/name <name>` (NameCommand), и ForceReply-вводом
 * имени в онбординге (GenericmessageCommand::handleNameReply ← SetNameAction шлёт
 * forceReply-промпт с маркером «✍ NAME»). Раньше tier-логика жила только в
 * NameCommand → ForceReply-путь её бы дублировал. Один метод = одна правда.
 *
 * Tier-правила смены имени (1:1 с легаси NameCommand):
 *   - level < 2 + has_renamed=0 → первая установка, показываем обучение.
 *   - 2 ≤ level < 10 + has_renamed=1 → вторая (последняя до 10 lvl) установка.
 *   - level ≥ 10 → смена не чаще раза в 30 дней (last_name_change).
 */
class NameService
{
    private CharacterModel $characterModel;

    public function __construct(?CharacterModel $characterModel = null)
    {
        $this->characterModel = $characterModel ?? new CharacterModel();
    }

    /**
     * Валидация + tier-логика + обновление имени.
     *
     * @param array<int|string, mixed>|\App\Entities\CharacterEntity $character
     * @return array{ok:bool, text:string, keyboard:array<string,mixed>|null}
     */
    public function applyName(array|\App\Entities\CharacterEntity $character, string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            return [
                'ok'       => false,
                'text'     => "❌ Имя не указано. Пришли имя одним сообщением.",
                'keyboard' => $this->retryKeyboard(),
            ];
        }

        // Имя на ЛЮБОМ языке: \p{L} — буквы любого письма (латиница, кириллица,
        // славянские диакритики, и т.д.), \p{N} — цифры, «_». Флаг /u — Unicode-режим
        // (корректный счёт длины в code points + работа \p{...}). Пробелы/эмодзи/
        // спецсимволы по-прежнему запрещены (безопасно для Markdown-вывода имени).
        if (!preg_match('/^[\p{L}\p{N}_]{3,20}$/u', $name)) {
            return [
                'ok'       => false,
                'text'     => "❌ Имя не соответствует правилам: *3–20 символов*, буквы любого языка, цифры и «\_». Без пробелов, эмодзи и спецсимволов.",
                'keyboard' => $this->retryKeyboard(),
            ];
        }

        $levelRaw      = $character['level'] ?? 0;
        $level         = is_numeric($levelRaw) ? (int) $levelRaw : 0;
        $hasRenamedRaw = $character['has_renamed'] ?? 0;
        $hasRenamed    = is_numeric($hasRenamedRaw) ? (int) $hasRenamedRaw : 0;

        $lastNameChange = $character['last_name_change'] ?? null;
        if ($lastNameChange instanceof \DateTimeInterface) {
            $lastNameChange = $lastNameChange->format('Y-m-d H:i:s');
        }

        $now    = new \DateTime();
        $nowStr = $now->format('Y-m-d H:i:s');

        $showTraining       = false;
        $cannotChangeReason = '';

        if ($level < 2) {
            if ($hasRenamed === 0) {
                $showTraining = true;
                $hasRenamed   = 1;
            } else {
                $cannotChangeReason = "Вы уже устанавливали имя. Повторная смена доступна с 2-го уровня.";
            }
        } elseif ($level < 10) {
            if ($hasRenamed === 1) {
                $hasRenamed = 2;
            } else {
                $cannotChangeReason = "Имя можно менять не более двух раз до 10-го уровня (у вас уже {$hasRenamed}).";
            }
        } else {
            if (is_string($lastNameChange) && $lastNameChange !== '') {
                $daysSince = (int) (new \DateTime($lastNameChange))->diff($now)->days;
                if ($daysSince < 30) {
                    $cannotChangeReason = "С момента предыдущей смены прошло только {$daysSince} дн. Нужно минимум 30 дней!";
                }
            }
        }

        if ($cannotChangeReason !== '') {
            return [
                'ok'       => false,
                'text'     => "❌ Нельзя сменить имя: {$cannotChangeReason}",
                'keyboard' => null,
            ];
        }

        $idRaw  = $character['id'] ?? 0;
        $charId = is_numeric($idRaw) ? (int) $idRaw : 0;
        $this->characterModel->update($charId, [
            'name'             => $name,
            'last_name_change' => $nowStr,
            'has_renamed'      => $hasRenamed,
        ]);

        if ($showTraining) {
            $text = "🎉 Ваше имя успешно установлено на: *{$name}*!\n\n"
                . "_Теперь пора переходить к делу, а именно пройти курс обучения для молодого бойца!_\n\n"
                . "Попутно ты узнаешь больше информации, получишь первый опыт, ресурсы и понимание механики, а также сеттинг игры.\n\n"
                . "_И помни, что пройдя обучение ты получишь знания и дополнительный опыт, что важно на старте._\n\n"
                . "🤖 *Приступаем!* ⬇️";

            return [
                'ok'       => true,
                'text'     => $text,
                'keyboard' => [
                    'inline_keyboard' => [
                        [
                            ['text' => '✖️ Без обучения',    'callback_data' => 'withoutTrainingStart'],
                            ['text' => '💡 Пройти обучение', 'callback_data' => 'getTrainedStart'],
                        ],
                    ],
                ],
            ];
        }

        $text = "🎉 Имя успешно изменено на: *{$name}*!\n\n";
        $text .= $level >= 10
            ? "_Следующую смену вы сможете сделать не раньше, чем через 30 дней._"
            : "_Это была ваша последняя смена имени до 10-го уровня._";

        return ['ok' => true, 'text' => $text, 'keyboard' => null];
    }

    /**
     * Клавиатура «Попробовать снова» (re-trigger forceReply через setName).
     *
     * @return array<string,mixed>
     */
    private function retryKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '📝 Попробовать снова', 'callback_data' => 'setName']],
            ],
        ];
    }
}
