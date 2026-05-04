<?php
/**
 * F2.12 — общие скрипты в подвале admin layout'ов.
 *   - vendor.min.js
 *   - app.min.js
 *   - my.js
 *
 * Layout-specific скрипты (datatables variants, apexcharts, simplemde, quill,
 * google maps, pdf.js и т.п.) остаются в каждом layout'е отдельно — они
 * различаются между admin/dashboard/templates/admin.
 */
?>
<!-- Vendor js -->
<script src="<?= base_url('js/vendor.min.js')?>"></script>

<!-- App js -->
<script src="<?= base_url('js/app.min.js')?>"></script>

<!-- My js -->
<script src="<?= base_url('js/my.js')?>"></script>
