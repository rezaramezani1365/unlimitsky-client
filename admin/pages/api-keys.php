<?php
require_once dirname(__DIR__) . '/lib/api-keys.php';

$GLOBALS['page_title'] = __('nav_api_keys');
$GLOBALS['active_nav'] = 'api-keys';

$new_key = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_key'])) {
        $name = trim($_POST['key_name'] ?? 'WooCommerce');
        $domain = trim($_POST['allowed_domain'] ?? '');
        $created = USK_ApiKeys::create($name, $domain);
        if (!empty($created['error'])) {
            usk_flash(__('api_key_domain_required'), 'danger');
        } else {
            $_SESSION['usk_new_api_key'] = $created['key'];
            usk_flash(__('api_key_created'));
        }
    }
    if (isset($_POST['revoke_key'])) {
        $id = preg_replace('/[^a-f0-9]/', '', $_POST['revoke_key'] ?? '');
        if ($id !== '') {
            USK_ApiKeys::revoke($id);
            usk_flash(__('api_key_revoked'));
        }
    }
    header('Location: ' . usk_admin_url('api-keys'));
    exit;
}

$keys = USK_ApiKeys::list_keys();
$api_url = USK_ApiKeys::api_base_url();
$installed = USK_ProtocolManager::installed_protocols();
?>
<div class="alert alert-usk-info mb-4">
    <i class="fa-solid fa-plug"></i> <?= __('api_keys_intro') ?>
</div>

<?php if (!empty($_SESSION['usk_new_api_key'])) :
    $shown = $_SESSION['usk_new_api_key'];
    unset($_SESSION['usk_new_api_key']);
?>
<div class="usk-card mb-4" style="border-color:var(--success);">
    <h3 class="text-success mb-3"><i class="fa-solid fa-key"></i> <?= __('api_key_copy_now') ?></h3>
    <code class="usk-code d-block p-3" style="direction:ltr;text-align:left;word-break:break-all;"><?= usk_esc($shown) ?></code>
    <p class="text-muted small mt-2 mb-0"><?= __('api_key_copy_warning') ?></p>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="usk-card h-100">
            <div class="usk-card-header"><?= __('api_connection_info') ?></div>
            <div class="p-3">
                <p class="small text-muted"><?= __('api_url_label') ?></p>
                <code class="usk-code d-block p-2 mb-3" style="direction:ltr;text-align:left;word-break:break-all;"><?= usk_esc($api_url) ?></code>

                <p class="small text-muted"><?= __('api_auth_label') ?></p>
                <code class="usk-code d-block p-2 mb-3" style="direction:ltr;text-align:left;">Authorization: Bearer USK-API-...</code>

                <p class="small text-muted"><?= __('api_installed_protocols') ?></p>
                <?php if (empty($installed)) : ?>
                    <span class="badge badge-danger"><?= __('api_no_protocols') ?></span>
                    <a href="<?= usk_admin_url('protocols') ?>" class="small ms-2"><?= __('nav_protocols') ?></a>
                <?php else : ?>
                    <?php foreach ($installed as $k => $m) : ?>
                        <span class="badge badge-success me-1"><?= usk_esc($m['name']) ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="usk-card mb-3">
            <div class="usk-card-header"><?= __('api_create_key') ?></div>
            <div class="p-3">
                <form method="post" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small"><?= __('api_key_name') ?></label>
                        <input type="text" name="key_name" class="form-control" placeholder="WooCommerce" value="WooCommerce">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small"><?= __('api_key_allowed_domain') ?></label>
                        <input type="text" name="allowed_domain" class="form-control" placeholder="shop.example.com" required dir="ltr" style="text-align:left">
                        <p class="form-text small mb-0"><?= __('api_key_allowed_domain_hint') ?></p>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" name="create_key" value="1" class="btn btn-usk-primary w-100">
                            <i class="fa-solid fa-plus"></i> <?= __('api_create_key_btn') ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="usk-card">
            <div class="usk-card-header"><?= __('api_keys_list') ?></div>
            <div class="p-3">
                <?php if (empty($keys)) : ?>
                    <p class="text-muted mb-0"><?= __('api_keys_empty') ?></p>
                <?php else : ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?= __('api_key_name') ?></th>
                                    <th><?= __('api_key_allowed_domain') ?></th>
                                    <th><?= __('api_key_prefix') ?></th>
                                    <th><?= __('api_key_created_at') ?></th>
                                    <th><?= __('status') ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($keys as $k) : ?>
                                <tr>
                                    <td><?= usk_esc($k['name']) ?></td>
                                    <td><code dir="ltr"><?= usk_esc($k['allowed_domain'] ?: '—') ?></code></td>
                                    <td><code><?= usk_esc($k['prefix']) ?></code></td>
                                    <td class="small text-muted"><?= usk_esc(substr($k['created_at'], 0, 10)) ?></td>
                                    <td>
                                        <?php if ($k['status'] === 'active') : ?>
                                            <span class="badge badge-success"><?= __('active') ?></span>
                                        <?php else : ?>
                                            <span class="badge badge-danger"><?= __('revoked') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($k['status'] === 'active') : ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('<?= __('api_revoke_confirm') ?>')">
                                            <input type="hidden" name="revoke_key" value="<?= usk_esc($k['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><?= __('revoke') ?></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="usk-card mt-4">
    <div class="usk-card-header"><?= __('api_woocommerce_guide') ?></div>
    <div class="p-3 text-muted small">
        <ol class="mb-0">
            <li><?= __('api_wc_step1') ?></li>
            <li><?= __('api_wc_step2') ?></li>
            <li><?= __('api_wc_step3') ?></li>
            <li><?= __('api_wc_step4') ?></li>
        </ol>
    </div>
</div>

<div class="usk-card mt-3">
    <div class="usk-card-header"><?= __('protocol_sudo_title') ?></div>
    <div class="p-3 text-muted small">
        <p><?= __('protocol_sudo_provision_note') ?></p>
        <pre class="usk-code p-2" style="white-space:pre-wrap;direction:ltr;text-align:left">www-data ALL=(root) NOPASSWD: /bin/bash <?= usk_esc(USK_ROOT) ?>/bin/add-user-*.sh *</pre>
    </div>
</div>
