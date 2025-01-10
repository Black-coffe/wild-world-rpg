<!DOCTYPE html>
<html>
<head>
    <title>Карта</title>
    <style>
        body {
            background: #0a0e14;
        }
        #mapCanvas {
            border: 2px solid #2a9292;
            margin: 10px auto;
        }
    </style>
</head>
<body>

<div class="container">
    <canvas id="mapCanvas" width="<?= 1000 ?>" height="<?= 1000 ?>"></canvas>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        var mapData = <?= json_encode($mapData) ?>;
        var canvas = document.getElementById("mapCanvas");
        var ctx = canvas.getContext("2d");

        // Рисуем карту
        mapData.forEach(function (cell) {
            console.log(cell.biome_id);
            var color = cell.biome_id === 2 ? "red" : "transparent";
            ctx.fillStyle = color;
            ctx.fillRect(cell.coordinate_x, cell.coordinate_y, 1, 1);
        });
    });
</script>

</body>
</html>
