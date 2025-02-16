<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Тестовый PvE-Бой</title>
    <script>
        async function fetchBattle() {
            const response = await fetch('/pve-test');
            const data = await response.json();
            document.getElementById("battleResult").innerText = JSON.stringify(data, null, 2);
        }
    </script>
</head>
<body>
<h1>Тестовый PvE-Бой</h1>
<button onclick="fetchBattle()">Запустить бой</button>
<pre id="battleResult"></pre>
</body>
</html>
