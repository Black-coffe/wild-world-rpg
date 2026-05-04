<!DOCTYPE html>
<html>
<head>
    <?= $this->include('admin/partials/_head_common') ?>

    <!-- Datatables css (layout-specific: base + responsive) -->
    <link href="<?= base_url('assets/vendor/datatables.net-bs5/css/dataTables.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('assets/vendor/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css') ?>" rel="stylesheet" type="text/css" />

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@300;400;500;600;700&family=Noto+Color+Emoji&family=Nunito:wght@200&display=swap" rel="stylesheet">
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

<!-- Datatables js (layout-specific: base + bs5 + responsive variants) -->
<script src="<?= base_url('assets/vendor/datatables.net/js/jquery.dataTables.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-bs5/js/dataTables.bootstrap5.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-responsive/js/dataTables.responsive.min.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-responsive-bs5/js/responsive.bootstrap5.min.js')?>"></script>

<!-- Datatable Init js -->
<script src="<?= base_url('assets/js/pages/demo.datatable-init.js')?>"></script>
<script src="<?= base_url('assets/vendor/datatables.net-select/js/dataTables.select.min.js')?>"></script>

<!-- Bootstrap Maxlength Plugin js -->
<script src="<?= base_url('assets/vendor/bootstrap-maxlength/bootstrap-maxlength.min.js')?>"></script>

</body>
</html>
