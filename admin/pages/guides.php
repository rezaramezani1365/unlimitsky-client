<?php
$GLOBALS['page_title'] = __('guides_title');
$GLOBALS['active_nav'] = 'guides';
$canUsePanels = USK_License::can_use_external_panels();
?>
<div class="alert alert-usk-info mb-4">
    <i class="fa-solid fa-circle-info"></i> <?= __('guides_intro') ?>
</div>
<?php if (!$canUsePanels) : ?>
<div class="alert alert-usk-info mb-4">
    <i class="fa-solid fa-crown"></i> <?= __('panels_pro_banner') ?>
    <a href="<?= usk_admin_url('license') ?>" class="btn btn-usk-primary btn-sm ms-2"><?= __('panels_pro_activate') ?></a>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="usk-card h-100">
            <div class="usk-card-header"><i class="fa-solid fa-cube"></i> <?= __('guides_marzban') ?></div>
            <div class="p-3">
                <div class="guide-step">
                    <div class="guide-num">1</div>
                    <div><strong>Panel URL</strong><p class="text-muted small mb-0"><code class="usk-code">https://IP:8000</code></p></div>
                </div>
                <div class="guide-step">
                    <div class="guide-num">2</div>
                    <div><strong>Username / Password</strong><p class="text-muted small mb-0">Marzban admin credentials</p></div>
                </div>
                <div class="guide-step">
                    <div class="guide-num">3</div>
                    <div><strong>Protocols</strong><p class="text-muted small mb-0"><code class="usk-code">vless|vmess|</code> · Flow: <code class="usk-code">flowon</code></p></div>
                </div>
                <div class="guide-step">
                    <div class="guide-num">4</div>
                    <div><strong>Inbounds</strong><p class="text-muted small mb-0">One inbound tag per line</p></div>
                </div>
                <?php if ($canUsePanels) : ?>
                <a href="<?= usk_admin_url('panels') ?>" class="btn btn-usk-primary btn-sm mt-2"><?= __('guides_add_marzban') ?></a>
                <?php else : ?>
                <a href="<?= usk_admin_url('license') ?>" class="btn btn-outline btn-sm mt-2"><i class="fa-solid fa-crown"></i> <?= __('panels_pro_activate') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="usk-card h-100">
            <div class="usk-card-header"><i class="fa-solid fa-diagram-project"></i> <?= __('guides_sanaei') ?></div>
            <div class="p-3">
                <div class="guide-step">
                    <div class="guide-num">1</div>
                    <div><strong>Panel URL</strong><p class="text-muted small mb-0"><code class="usk-code">http://IP:2053</code></p></div>
                </div>
                <div class="guide-step">
                    <div class="guide-num">2</div>
                    <div><strong>Username / Password</strong><p class="text-muted small mb-0">3x-ui login</p></div>
                </div>
                <div class="guide-step">
                    <div class="guide-num">3</div>
                    <div><strong>Inbound ID</strong><p class="text-muted small mb-0">Number from Inbounds list</p></div>
                </div>
                <div class="guide-step">
                    <div class="guide-num">4</div>
                    <div><strong>Link template</strong><p class="text-muted small mb-0"><code class="usk-code">%s1</code> uuid · <code class="usk-code">%s2</code> host · <code class="usk-code">%s3</code> name</p></div>
                </div>
                <?php if ($canUsePanels) : ?>
                <a href="<?= usk_admin_url('panels') ?>" class="btn btn-usk-primary btn-sm mt-2"><?= __('guides_add_sanaei') ?></a>
                <?php else : ?>
                <a href="<?= usk_admin_url('license') ?>" class="btn btn-outline btn-sm mt-2"><i class="fa-solid fa-crown"></i> <?= __('panels_pro_activate') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="usk-card">
            <div class="usk-card-header"><i class="fa-solid fa-lightbulb"></i> WooCommerce</div>
            <div class="p-3 text-muted small mb-0"><?= __('settings_wc_note') ?></div>
        </div>
    </div>
</div>
