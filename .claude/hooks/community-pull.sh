#!/usr/bin/env bash
# SessionStart hook (ADR-176, story community-chat-bot-13): "прод -> локаль" половина канала
# сообщества. Тянет открытые вопросы настроенного чата с боевого сервера по SSH и складывает
# их в .claude/community/inbox-<дата>.json; курсор — .claude/community/state.json. В контекст
# сессии возвращается ОДНА строка со счётчиком, не содержимое чата (всё, что попало в
# транскрипт, пересылается каждый следующий ход).
#
# Никогда не валит старт сессии (feedback_network_failure_is_not_evidence_of_death): нет
# сети / нет ключа / прод недоступен — понятная строка и код 0. Массовый сетевой отказ не
# значит «прод умер».
#
# Push (черновики -> прод) отсюда сознательно НЕ запускается — non-goal story 13: пуш
# осознанное действие в конце работы, не побочный эффект открытия терминала.
set -uo pipefail

ROOT="${CLAUDE_PROJECT_DIR:-$(pwd)}"
DIR="$ROOT/.claude/community"
STATE="$DIR/state.json"
SSH_KEY="$HOME/.ssh/wildworld_deploy"
REMOTE="wildworld-bot@bot.wildworld.fun"

mkdir -p "$DIR" 2>/dev/null || {
    echo "[community-pull] не удалось создать $DIR — синхронизация пропущена"
    exit 0
}

# --- курсор: id последнего уже виденного сообщения -----------------------------
since=0
if [ -f "$STATE" ]; then
    cursor=$(php -r '
        $data = json_decode((string) file_get_contents($argv[1]), true);
        if (! is_array($data) || ! isset($data["since"]) || ! is_numeric($data["since"])) {
            exit(1);
        }
        echo max(0, (int) $data["since"]);
    ' "$STATE" 2>/dev/null)
    if [ $? -eq 0 ] && [ -n "$cursor" ]; then
        since="$cursor"
    else
        echo "[community-pull] state.json повреждён — курсор сброшен в 0" >&2
        since=0
    fi
fi

if [ ! -f "$SSH_KEY" ]; then
    echo "[community-pull] SSH-ключ не найден ($SSH_KEY) — синхронизация пропущена"
    exit 0
fi

if ! command -v ssh >/dev/null 2>&1; then
    echo "[community-pull] ssh недоступен в PATH — синхронизация пропущена"
    exit 0
fi

sshRunner="ssh"
if command -v timeout >/dev/null 2>&1; then
    sshRunner="timeout 15 ssh"
fi

# 🔴 --no-header ОБЯЗАТЕЛЕН (найдено на приёмке story 11): без него `php spark` печатает
# в stdout свой баннер ДО запуска команды, и stdout перестаёт быть валидным JSON.
raw=$($sshRunner -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=8 -o StrictHostKeyChecking=accept-new \
    "$REMOTE" "cd ~/htdocs/bot.wildworld.fun && php spark --no-header community:export --since=$since" 2>/dev/null)
status=$?

if [ $status -ne 0 ] || [ -z "$raw" ]; then
    echo "[community-pull] прод недоступен по SSH — синхронизация пропущена, попробуем при следующем запуске"
    exit 0
fi

# --- второй рубеж: полученное ОБЯЗАНО быть валидным JSON перед записью ---------
result=$(printf '%s' "$raw" | php -r '
    $raw = stream_get_contents(STDIN);
    $data = json_decode((string) $raw, true);
    if (! is_array($data)) {
        exit(1);
    }
    $maxId = 0;
    foreach ($data as $row) {
        if (is_array($row) && isset($row["id"]) && is_numeric($row["id"])) {
            $maxId = max($maxId, (int) $row["id"]);
        }
    }
    echo count($data) . "\t" . $maxId;
')
status=$?

if [ $status -ne 0 ]; then
    # Тихо записать мусор хуже, чем не записать ничего — inbox не трогаем.
    echo "[community-pull] получен невалидный JSON от community:export — синхронизация пропущена (второй рубеж защиты)"
    exit 0
fi

count="${result%%$'\t'*}"
maxId="${result##*$'\t'}"

inbox="$DIR/inbox-$(date +%Y-%m-%d).json"
if ! printf '%s' "$raw" > "$inbox" 2>/dev/null; then
    echo "[community-pull] не удалось записать $inbox — синхронизация пропущена"
    exit 0
fi

newSince=$since
if [ "$maxId" -gt "$since" ] 2>/dev/null; then
    newSince=$maxId
fi
php -r '
    file_put_contents($argv[1], json_encode(["since" => (int) $argv[2]], JSON_PRETTY_PRINT) . PHP_EOL);
' "$STATE" "$newSince" 2>/dev/null || echo "[community-pull] не удалось обновить state.json" >&2

echo "[community-pull] новых вопросов из чата сообщества: $count -> $inbox"
exit 0
