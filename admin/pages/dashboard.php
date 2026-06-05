<?php
global $sql, $config;
$GLOBALS['page_title'] = __('nav_dashboard');
$GLOBALS['active_nav'] = 'dashboard';

$stats = [
    'users' => (int) $sql->query("SELECT COUNT(*) c FROM `users`")->fetch_assoc()['c'],
    'orders' => (int) $sql->query("SELECT COUNT(*) c FROM `orders`")->fetch_assoc()['c'],
    'panels' => (int) $sql->query("SELECT COUNT(*) c FROM `panels` WHERE `status`='active'")->fetch_assoc()['c'],
    'plans' => (int) $sql->query("SELECT COUNT(*) c FROM `category` WHERE `status`='active'")->fetch_assoc()['c'],
];
$recent = $sql->query("SELECT * FROM `orders` ORDER BY `row` DESC LIMIT 8");
?>
<div class="row g-3 mb-4">
    <?php
    $items = [
        ['users', __('dashboard_users'), 'fa-users'],
        ['orders', __('dashboard_services'), 'fa-shield-halved'],
        ['panels', __('dashboard_panels'), 'fa-server'],
        ['plans', __('dashboard_plans'), 'fa-tags'],
    ];
    foreach ($items as $it) :
    ?>
    <div class="col-6 col-md-3">
        <div class="usk-stat">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fa-solid <?= $it[2] ?>" style="color:var(--usk-blue)"></i>
                <span class="usk-stat-label"><?= $it[1] ?></span>
            </div>
            <div class="usk-stat-value"><?= $stats[$it[0]] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="usk-card mb-4">
    <div class="usk-card-header"><?= __('dashboard_recent') ?></div>
    <div class="p-0">
        <?php if ($recent->num_rows === 0) : ?>
            <p class="text-muted p-3 mb-0"><?= __('dashboard_no_services') ?></p>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th><?= __('code') ?></th><th><?= __('server') ?></th><th><?= __('volume') ?></th><th><?= __('duration') ?></th><th><?= __('status') ?></th></tr></thead>
                    <tbody>
                    <?php while ($r = $recent->fetch_assoc()) : ?>
                        <tr>
                            <td><code class="usk-code"><?= usk_esc($r['code']) ?></code></td>
                            <td><?= usk_esc($r['location']) ?></td>
                            <td><?= usk_esc($r['volume']) ?> GB</td>
                            <td><?= usk_esc($r['date']) ?> <?= __('days') ?></td>
                            <td><span class="badge badge-<?= $r['status'] === 'active' ? 'success' : 'danger' ?>"><?= usk_esc($r['status']) ?></span></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="usk-card">
    <div class="usk-card-header"><?= __('dashboard_quick') ?></div>
    <div class="p-3">
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-usk-primary" href="<?= usk_admin_url('create-service') ?>"><i class="fa-solid fa-plus"></i> <?= __('nav_create') ?></a>
            <a class="btn btn-outline-usk" href="<?= usk_admin_url('panels') ?>"><i class="fa-solid fa-server"></i> <?= __('nav_panels') ?></a>
            <a class="btn btn-outline-usk" href="<?= usk_admin_url('guides') ?>"><i class="fa-solid fa-book"></i> <?= __('nav_guides') ?></a>
        </div>
    </div>
</div>
