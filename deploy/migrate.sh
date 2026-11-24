#!/bin/bash
# Шаг миграций post-deploy.sh. Выход ненулём — релиз не переключается.
#
# s1-atomicity-1124 (ревью, ask 6): `php spark migrate` выходит с кодом 0, даже когда миграция
# бросила исключение — `Migrate::run()` ловит его, печатает блок `[Класс исключения]` и
# возвращает успех. Голый вызов под `set -e` поэтому не останавливал деплой: симлинк
# переключался на новый код при старой схеме. Здесь провалом считается и ненулевой код, и
# строка-заголовок исключения в выводе spark (`error_exception.php`: `[<класс>]` отдельной строкой).
#
# PHP_BIN — какой php звать (по умолчанию `php`; тест подставляет свой).
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

out="$("${PHP_BIN:-php}" spark migrate --all -n 2>&1)"
code=$?
printf '%s\n' "$out"

if [[ $code -ne 0 ]]; then
    echo "ERROR: php spark migrate exited with $code — release NOT switched" >&2
    exit 1
fi

# Цвета CLI срезаются (в пайпе их нет, но на всякий случай), затем ищется заголовок исключения.
if printf '%s\n' "$out" | sed -E 's/\x1b\[[0-9;]*m//g' | grep -Eiq '^[[:space:]]*\[[A-Za-z0-9_\\]*(exception|error)[A-Za-z0-9_]*\][[:space:]]*$'; then
    echo "ERROR: php spark migrate printed an exception (exit code 0 hides it) — release NOT switched" >&2
    exit 1
fi
