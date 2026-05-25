<!-- ========== Left Sidebar Start ========== -->
<div class="leftside-menu">

    <!-- Brand Logo Light -->
    <a href="<?= base_url('dashboard')?>" class="logo logo-light">
        <span class="logo-lg">
            <img src="<?= base_url('images/logo.png')?>" alt="logo">
        </span>
        <span class="logo-sm">
            <img src="<?= base_url('images/logo-sm.png')?>" alt="small logo">
        </span>
    </a>

    <!-- Sidebar Hover Menu Toggle Button -->
    <div class="button-sm-hover" data-bs-toggle="tooltip" data-bs-placement="right" title="Show Full Sidebar">
        <i class="ri-checkbox-blank-circle-line align-middle"></i>
    </div>

    <!-- Full Sidebar Menu Close Button -->
    <div class="button-close-fullsidebar">
        <i class="ri-close-fill align-middle"></i>
    </div>

    <!-- Sidebar -left -->
    <div class="h-100" id="leftside-menu-container" data-simplebar>
        <!-- Leftbar User -->

        <!--- Sidemenu -->
        <ul class="side-nav">

            <li class="side-nav-title">Navigation</li>

            <li class="side-nav-item menuitem-active">
                <a data-bs-toggle="collapse" href="#settingGame" aria-expanded="false" aria-controls="settingGame" class="side-nav-link collapsed">
                    <i class="uil-store"></i>
                    <span> Настройки игры </span>
                    <span class="menu-arrow"></span>
                </a>
                <div class="collapse" id="settingGame" style="">
                    <ul class="side-nav-second-level">
                        <li>
                            <a href="<?= base_url('admin/biomes')?>">Биомы</a>
                        </li>
                        <li>
                            <a href="<?= base_url('admin/resources')?>">Ресурсы</a>
                        </li>
                        <li>
                            <a href="<?= base_url('admin/tasks')?>">Задачи</a>
                        </li>
                        <li>
                            <a href="<?= base_url('admin/events')?>">События</a>
                        </li>
                        <li>
                            <a href="<?= base_url('admin/world-objects')?>">Объекты</a>
                        </li>
                        <li>
                            <a href="<?= base_url('admin/quests')?>">Квесты</a>
                        </li>
                    </ul>
                </div>
            </li>

            <li class="side-nav-item">
                <a href="<?= base_url('admin/craft-tree')?>" class="side-nav-link">
                    <i class="ri-node-tree"></i>
                    <span> Дерево крафта </span>
                </a>
            </li>

            <li class="side-nav-item">
                <a href="<?= base_url('admin/crafting-economy')?>" class="side-nav-link">
                    <i class="ri-line-chart-line"></i>
                    <span> Экономика крафта </span>
                </a>
            </li>

            <li class="side-nav-item">
                <a href="<?= base_url('admin/navigation')?>" class="side-nav-link">
                    <i class="ri-route-line"></i>
                    <span> Дерево навигации </span>
                </a>
            </li>

            <li class="side-nav-item">
                <a href="<?= base_url('admin/game-settings')?>" class="side-nav-link">
                    <i class="ri-settings-3-line"></i>
                    <span> Параметры баланса </span>
                </a>
            </li>

            <li class="side-nav-item">
                <a href="<?= base_url('admin/tips')?>" class="side-nav-link">
                    <i class="ri-advertisement-fill"></i>
                    <span> Советы в игре </span>
                </a>
            </li>

            <li class="side-nav-item">
                <a href="<?= base_url('admin/send-message')?>" class="side-nav-link">
                    <i class="uil-layer-group"></i>
                    <span> Сообщение всем </span>
                </a>
            </li>

            <li class="side-nav-item">
                <a href="<?= base_url('admin/character-reset')?>" class="side-nav-link">
                    <i class="uil-layer-group"></i>
                    <span> Сброс персонажа </span>
                </a>
            </li>

            <!-- Новый пункт меню: Опросы -->
            <li class="side-nav-item">
                <a data-bs-toggle="collapse" href="#pollsMenu" aria-expanded="false" aria-controls="pollsMenu" class="side-nav-link collapsed">
                    <i class="uil-comment-alt"></i>
                    <span> Опросы </span>
                    <span class="menu-arrow"></span>
                </a>
                <div class="collapse" id="pollsMenu" style="">
                    <ul class="side-nav-second-level">
                        <li>
                            <a href="<?= base_url('admin/polls')?>">Список опросов</a>
                        </li>
                        <li>
                            <a href="<?= base_url('admin/polls/create')?>">Создать опрос</a>
                        </li>
                    </ul>
                </div>
            </li>
            <!-- Конец нового пункта меню -->

        </ul>
        <!--- End Sidemenu -->

        <div class="clearfix"></div>
    </div>
</div>
<!-- ========== Left Sidebar End ========== -->
