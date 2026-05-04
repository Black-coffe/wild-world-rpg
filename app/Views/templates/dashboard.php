<!DOCTYPE html>
<html>
<head>
    <script src="https://polyfill.io/v3/polyfill.min.js?features=default"></script>

    <?= $this->include('admin/partials/_head_common') ?>

    <!-- Datatables css (layout-specific: full set с fixed* и select) -->
    <link href="<?= base_url('assets/vendor/datatables.net-bs5/css/dataTables.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('assets/vendor/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('assets/vendor/datatables.net-fixedcolumns-bs5/css/fixedColumns.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('assets/vendor/datatables.net-fixedheader-bs5/css/fixedHeader.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('assets/vendor/datatables.net-buttons-bs5/css/buttons.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('assets/vendor/datatables.net-select-bs5/css/select.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />

    <!-- Daterangepicker css -->
    <link rel="stylesheet" href="<?= base_url('assets/vendor/simplemde/simplemde.min.css') ?>">

    <!-- Quill css -->
    <link href="<?= base_url('assets/vendor/quill/quill.core.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('assets/vendor/quill/quill.snow.css') ?>" rel="stylesheet" type="text/css" />

</head>

<body>
<!-- Begin page -->
<div class="wrapper">

    <!-- ========== Topbar Start ========== -->
    <?= $this->include('templates/navbar_custome') ?>
    <!-- ========== Topbar End ========== -->

    <!-- ========== Left Sidebar Start ========== -->
    <?= $this->include('templates/sidebar') ?>
    <!-- ========== Left Sidebar End ========== -->

    <!-- ============================================================== -->
    <!-- Start Page Content here -->
    <!-- ============================================================== -->

    <div class="content-page">

        <?= $this->renderSection('content') ?>

        <?= $this->include('admin/partials/_footer') ?>

    </div>

    <!-- ============================================================== -->
    <!-- End Page content -->
    <!-- ============================================================== -->

</div>

<?= $this->include('admin/partials/_scripts_common') ?>

<!-- Datatables js (layout-specific: full set с buttons/keytable/select) -->
<script src="<?= base_url('assets/vendor/datatables.net/js/jquery.dataTables.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-bs5/js/dataTables.bootstrap5.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-responsive/js/dataTables.responsive.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-buttons/js/dataTables.buttons.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-keytable/js/dataTables.keyTable.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-select/js/dataTables.select.min.js')?>"></script>

<!-- Apex  Charts js -->
<script src="<?= base_url('vendor/apexcharts/apexcharts.min.js')?>"></script>

<!-- Input Mask Plugin js -->
<script src="<?= base_url('assets/vendor/jquery-mask-plugin/jquery.mask.min.js')?>"></script>

<!-- Bootstrap Touchspin Plugin js -->
<script src="<?= base_url('assets/vendor/bootstrap-touchspin/jquery.bootstrap-touchspin.min.js')?>"></script>

<!-- Bootstrap Maxlength Plugin js -->
<script src="<?= base_url('assets/vendor/bootstrap-maxlength/bootstrap-maxlength.min.js')?>"></script>

<!-- quill js -->
<script src="<?= base_url('assets/vendor/simplemde/simplemde.min.js') ?>"></script>
<script src="<?= base_url('assets/js/pages/demo.simplemde.js') ?>"></script>
<!-- Datatables select -->
<script src="<?= base_url('assets/vendor/datatables.net-select/js/dataTables.select.min.js') ?>"></script>
</body>
</html>
