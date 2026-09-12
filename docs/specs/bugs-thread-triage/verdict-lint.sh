#!/usr/bin/env bash
# Формальный гейт файлов вердиктов спеки bugs-thread-triage.
#
#   Usage: bash docs/specs/bugs-thread-triage/verdict-lint.sh docs/specs/bugs-thread-triage/verdicts/<домен>.md
#
# Проверяет ФОРМУ, а не правду: что у каждой жалобы есть все семь полей, что вердикт из
# разрешённого набора, что черновик ответа существует ровно у «устранено» и markdown в нём
# не разъедет в Telegram, и что «устранено» опирается на исполнение и на прод, а не на
# существование коммита в develop. Правду смотрит ревью — скрипт её не заменяет.
#
# Формат блока — docs/specs/bugs-thread-triage/verdicts/_FORMAT.md.
# Линия отсечения прода (v0.51.666) — docs/specs/bugs-thread-triage/RECON.md.
# Выход: 0 — форма в порядке; 1 — есть нарушения (печатаются по одному в строку).

set -u

FILE="${1:-}"
if [ -z "$FILE" ] || [ ! -f "$FILE" ]; then
  echo "verdict-lint: usage: $0 <verdicts/<домен>.md>" >&2
  exit 1
fi

BAD=0
problem() { BAD=1; echo "  ! $1"; }

count() { grep -c "$1" "$FILE" 2>/dev/null || true; }

BLOCKS="$(count '^### `[0-9]\{10\}`')"
if [ "$BLOCKS" -eq 0 ]; then
  problem "$FILE: ни одного блока вида '### \`<10 цифр>\`' — закрывать нечего"
  echo "verdict-lint: $FILE — 1 нарушение"
  exit 1
fi

# 1. список mid в шапке и его покрытие блоками
HDR="$(sed -n 's/^<!--[[:space:]]*mids:[[:space:]]*\(.*\)-->.*/\1/p' "$FILE" | head -1)"
if [ -z "$HDR" ]; then
  problem "$FILE: нет шапки '<!-- mids: ... -->' — непонятно, какие жалобы файл обязан закрыть"
else
  for m in $HDR; do
    grep -q "^### \`$m\`" "$FILE" || problem "$FILE: mid $m заявлен в шапке, но блока под него нет"
  done
fi

# 2. семь меток у каждого блока
for label in "Суть" "Вердикт" "Чем доказано" "На проде" "Живой прогон" "Черновик ответа" "Сомнения"; do
  n="$(count "^\*\*$label:\*\*")"
  [ "$n" -eq "$BLOCKS" ] || problem "$FILE: меток «$label» — $n при $BLOCKS блоках"
  e="$(count "^\*\*$label:\*\*[[:space:]]*$")"
  [ "$e" -eq 0 ] || problem "$FILE: пустых меток «$label» — $e"
done

# 3. вердикт из разрешённого набора
while IFS= read -r v; do
  [ -z "$v" ] && continue
  case "$v" in
    "устранено"|"исправлено, но не на проде"|"не устранено"|"не баг"|"не воспроизводится"|"требует живого прогона") ;;
    *) problem "$FILE: вердикт «$v» вне набора (устранено / исправлено, но не на проде / не устранено / не баг / не воспроизводится / требует живого прогона)" ;;
  esac
done < <(sed -n 's/^\*\*Вердикт:\*\*[[:space:]]*//p' "$FILE" | sed 's/[[:space:]]*$//')

FIXED="$(count '^\*\*Вердикт:\*\*[[:space:]]*устранено[[:space:]]*$')"

# 4. черновик существует ровно у «устранено»
DRAFTS=0
while IFS= read -r d; do
  d="$(printf '%s' "$d" | sed 's/[[:space:]]*$//')"
  [ -z "$d" ] && continue
  [ "$d" = "—" ] && continue
  DRAFTS=$((DRAFTS+1))
  stars="$(printf '%s' "$d" | tr -cd '*' | wc -c | tr -d ' ')"
  if [ $((stars % 2)) -ne 0 ]; then
    problem "$FILE: непарные '*' в черновике — Telegram отдаст 400 и не отправит: ${d:0:60}..."
  fi
  [ "${#d}" -le 900 ] || echo "  ~ $FILE: черновик длиной ${#d} символов — владелец просил коротко"
done < <(sed -n 's/^\*\*Черновик ответа:\*\*[[:space:]]*//p' "$FILE")

[ "$DRAFTS" -eq "$FIXED" ] || problem "$FILE: черновиков $DRAFTS при $FIXED вердиктах «устранено» (у остальных вердиктов, включая «исправлено, но не на проде», черновик обязан быть «—»)"

# 5. «устранено» опирается на исполнение, а не на существование правки
MARKED="$(sed -n 's/^\*\*Чем доказано:\*\*//p' "$FILE" | grep -c -e 'SELECT' -e 'прогон' -e 'phpunit' -e 'вебхук' -e 'скриншот' || true)"
[ "$MARKED" -ge "$FIXED" ] || problem "$FILE: строк «Чем доказано» с маркером исполнения (SELECT/прогон/phpunit/вебхук/скриншот) — $MARKED при $FIXED вердиктах «устранено»: найденный коммит это гипотеза, а не вердикт"

# 6. «устранено» доказано ПРОДОМ, а не develop
PROD="$(sed -n 's/^\*\*На проде:\*\*//p' "$FILE" | grep -c -e 'v0\.51\.' -e 'git tag' -e 'SELECT' -e 'прод' || true)"
[ "$PROD" -ge "$FIXED" ] || problem "$FILE: строк «На проде» с маркером прода (v0.51./git tag/SELECT/прод) — $PROD при $FIXED вердиктах «устранено»: прод стоит на v0.51.666, develop игрок не видит"

if [ "$BAD" -eq 0 ]; then
  echo "verdict-lint: $FILE — $BLOCKS блоков, форма в порядке ($FIXED «устранено»)"
  exit 0
fi
echo "verdict-lint: $FILE — форма нарушена, см. строки выше"
exit 1
