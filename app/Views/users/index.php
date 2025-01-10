<?= $this->extend('templates/admin_drivers') ?>

<?= $this->section('content') ?>
<?php $userRole = service('auth')->getCurrentUser(); ?>
<div class="container-fluid">
    <div id="example_wrapper" class="dataTables_wrapper dt-bootstrap5 mt-3">
        <div class="row dt-row">
            <div class="col-sm-12">
                <table class="table table-striped table-centered mb-0">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Фото</th>
                        <th>Имя</th>
                        <th>Email</th>
                        <th>Дата регистрации</th>
                        <th>Админ?</th>
                        <?php if($userRole->name == 'Administrator' and $userRole->is_admin): ?>
                            <th>Управление</th>
                        <?php endif;?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?=$user->id;?></td>
                            <td class="table-user">
                                <img src="<?= site_url('/uploads/users/' . $user->photo_url) ?>" alt="avatar user" class="me-2 rounded-circle">
                            </td>
                            <td><?=$user->name;?></td>
                            <td><?=$user->email;?></td>
                            <td><?=$user->created_at;?></td>
                            <?php
                            if ($user->is_admin === "0") {
                                echo "<td><span class='badge bg-danger p-1'>Нет</span></td>";
                            } else {
                                echo "<td><span class='badge bg-success p-1'>Да</span></td>";
                            }
                            ?>
                            <?php if($userRole->name == 'Administrator' and $userRole->is_admin): ?>
                                <td class="icon d-flex">
                                    <a class="edit action-icon" href="/users/edit/<?= $user->id ?>" title="Редактировать"><i class="ri-edit-fill"></i></a>
                                    <form action="/users/<?= $user->id ?>" method="post" onsubmit="return confirm('Вы уверены, что хотите удалить этого пользователя?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button type="submit" class="btn btn-link p-0 action-icon" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                    </form>
                                </td>
                            <?php endif;?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
