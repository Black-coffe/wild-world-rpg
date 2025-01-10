<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <script src="https://polyfill.io/v3/polyfill.min.js?features=default"></script>

    <meta content="Система, помогающая в своевременном и регулируемом режиме проводить технические проверки (чекины) автомобилей с использованием онлайн-сервиса." name="description" />
    <meta content="Checking of cars" name="author" />

    <!-- App favicon -->
    <link rel="shortcut icon" href="<?= base_url('images/favicon.ico') ?>">

    <!-- Datatables css -->
    <link href="<?= base_url('assets/vendor/datatables.net-bs5/css/dataTables.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('assets/vendor/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('assets/vendor/datatables.net-fixedcolumns-bs5/css/fixedColumns.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('assets/vendor/datatables.net-fixedheader-bs5/css/fixedHeader.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('assets/vendor/datatables.net-buttons-bs5/css/buttons.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('assets/vendor/datatables.net-select-bs5/css/select.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />

    <!-- Daterangepicker css -->
    <link rel="stylesheet" href="<?= base_url('assets/vendor/simplemde/simplemde.min.css') ?>">

    <!-- Theme Config Js -->
    <script src="<?= base_url('js/hyper-config.js') ?>"></script>
    <!-- App css -->
    <link href="<?= base_url('css/app-saas.min.css') ?>" rel="stylesheet" type="text/css" id="app-style" />
    <!-- Icons css -->
    <link href="<?= base_url('css/icons.min.css') ?>" rel="stylesheet" type="text/css" />
    <!-- My css -->
    <link href="<?= base_url('css/my.css') ?>" rel="stylesheet" type="text/css" />

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

        <!-- Footer Start -->
        <footer class="footer">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-6">
                        <script>document.write(new Date().getFullYear())</script> © <a target="_blank" href="https://www.youtube.com/@andrievskii">Andrievskii</a>
                    </div>
                </div>
            </div>
        </footer>
        <!-- end Footer -->

    </div>

    <!-- ============================================================== -->
    <!-- End Page content -->
    <!-- ============================================================== -->

</div>

<!-- Vendor js -->
<script src="<?= base_url('js/vendor.min.js')?>"></script>

<!-- Datatables js -->
<script src="<?= base_url('assets/vendor/datatables.net/js/jquery.dataTables.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-bs5/js/dataTables.bootstrap5.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-responsive/js/dataTables.responsive.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-buttons/js/dataTables.buttons.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-keytable/js/dataTables.keyTable.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-select/js/dataTables.select.min.js')?>"></script>

<!-- Apex  Charts js -->
<script src="<?= base_url('vendor/apexcharts/apexcharts.min.js')?>"></script>


<!-- App js -->
<script src="<?= base_url('js/app.min.js')?>"></script>

<!-- My js -->
<script src="<?= base_url('js/my.js')?>"></script>

<!-- Input Mask Plugin js -->
<script src="<?= base_url('assets/vendor/jquery-mask-plugin/jquery.mask.min.js')?>"></script>

<!-- Bootstrap Touchspin Plugin js -->
<script src="<?= base_url('assets/vendor/bootstrap-touchspin/jquery.bootstrap-touchspin.min.js')?>"></script>

<!-- Bootstrap Maxlength Plugin js -->
<script src="<?= base_url('assets/vendor/bootstrap-maxlength/bootstrap-maxlength.min.js')?>"></script>

<!-- quill js -->
<script src="<?= base_url('assets/vendor/simplemde/simplemde.min.js') ?>"></script>
<script src="<?= base_url('assets/js/pages/demo.simplemde.js') ?>"></script>
<!-- Datatables js -->
<script src="<?= base_url('assets/vendor/datatables.net-select/js/dataTables.select.min.js') ?>"></script>
</body>
</html>