<?php
require_once __DIR__ . '/../lib/init.php';

$GLOBALS['page_title'] = __('nav_settings');
$GLOBALS['active_nav'] = 'settings';

require_once __DIR__ . '/../lib/usage-sync-settings.php';
require_once __DIR__ . '/../lib/protocols/limits.php';

global $sql;
$auth = USK_Admin_Auth::get_data();
$test = $sql->query("SELECT * FROM `test_account_setting` LIMIT 1")->fetch_assoc();
$spam = $sql->query("SELECT * FROM `spam_setting` LIMIT 1")->fetch_assoc();
$clientDns = USK_ClientDns::get();
$connectHostCfg = USK_ConnectHost::get();
$wooShopCfg = USK_WooCommerce_Shop::get();
$detectedServerIp = USK_ConnectHost::detect_ip();
$panelAccessCfg = USK_PanelAccess::get();
$panelAccessCfg['panel_port'] = (int) ($panelAccessCfg['panel_port'] ?? USK_PanelAccess::detect_port());
if ($panelAccessCfg['panel_port'] < 1024) {
    $panelAccessCfg['panel_port'] = USK_PanelAccess::detect_port();
}
$panelCurrentUrl = USK_PanelAccess::current_public_url();
$panelAdminUrl = USK_PanelAccess::admin_login_url();
$usageSyncCfg = USK_UsageSyncSettings::get();
$usageSyncStatus = USK_UsageSyncSettings::status();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';

    if ($section === 'account') {
        $user = trim($_POST['username'] ?? '');
        $p1 = $_POST['password'] ?? '';
        $p2 = $_POST['password2'] ?? '';
        $lang = $_POST['language'] ?? 'fa';

        if ($user === '') {
            usk_flash(__('error') . ': username', 'error');
        } elseif ($p1 !== '' && strlen($p1) < 6) {
            usk_flash(__('password_min'), 'error');
        } elseif ($p1 !== '' && $p1 !== $p2) {
            usk_flash(__('password_mismatch'), 'error');
        } else {
            USK_Admin_Auth::update_account($user, $p1 !== '' ? $p1 : null, $lang);
            USK_I18n::set_lang($lang);
            usk_flash(__('settings_saved'));
        }
    }

    if ($section === 'test') {
        $st = $_POST['status'] ?? 'inactive';
        $vol = $sql->real_escape_string($_POST['volume'] ?? '1');
        $time = $sql->real_escape_string($_POST['time'] ?? '24');
        $panel = $sql->real_escape_string($_POST['panel'] ?? 'none');
        $sql->query("UPDATE `test_account_setting` SET `status`='$st',`volume`='$vol',`time`='$time',`panel`='$panel'");
        usk_flash(__('settings_saved'));
    }
    if ($section === 'spam') {
        $st = $_POST['status'] ?? 'active';
        $type = $sql->real_escape_string($_POST['type'] ?? 'ban');
        $time = $sql->real_escape_string($_POST['time'] ?? '3');
        $count = $sql->real_escape_string($_POST['count_message'] ?? '10');
        $sql->query("UPDATE `spam_setting` SET `status`='$st',`type`='$type',`time`='$time',`count_message`='$count'");
        usk_flash(__('settings_saved'));
    }
    if ($section === 'connect_host') {
        USK_ConnectHost::save(array(
            'enabled' => !empty($_POST['connect_host_enabled']),
            'connect_host' => $_POST['connect_host'] ?? '',
            'hint' => $_POST['connect_host_hint'] ?? '',
        ));
        usk_flash(__('settings_saved'));
    }
    if ($section === 'panel_access') {
        $saved = USK_PanelAccess::save(array(
            'domain_enabled' => !empty($_POST['panel_domain_enabled']),
            'panel_domain' => $_POST['panel_domain'] ?? '',
            'panel_port' => $_POST['panel_port'] ?? 8082,
            'https_enabled' => !empty($_POST['panel_https_enabled']),
            'hint' => $_POST['panel_access_hint'] ?? '',
        ));
        $applied = USK_PanelAccess::apply($saved);
        if (!empty($applied['ok'])) {
            $msg = __('settings_panel_access_applied') . ': ' . ($applied['admin_url'] ?? '');
            usk_flash($msg);
        } else {
            usk_flash(USK_PanelAccess::error_label($applied['error'] ?? 'apply_failed'), 'error');
        }
    }
    if ($section === 'client_dns') {
        USK_ClientDns::save(array(
            'enabled' => !empty($_POST['dns_enabled']),
            'default_dns' => $_POST['default_dns'] ?? '',
            'xray_dns' => $_POST['xray_dns'] ?? '',
            'amnezia_dns' => $_POST['amnezia_dns'] ?? '',
            'openvpn_dns' => $_POST['openvpn_dns'] ?? '',
            'wireguard_dns' => $_POST['wireguard_dns'] ?? '',
            'hint' => $_POST['dns_hint'] ?? '',
        ));
        usk_flash(__('settings_saved'));
    }
    if ($section === 'usage_sync') {
        USK_UsageSyncSettings::save(array(
            'enabled' => !empty($_POST['usage_sync_enabled']),
            'interval_minutes' => $_POST['usage_sync_interval'] ?? 5,
            'hint' => $_POST['usage_sync_hint'] ?? '',
        ));
        usk_flash(__('settings_usage_sync_saved'));
    }
    if ($section === 'woocommerce_shop') {
        USK_WooCommerce_Shop::save(array(
            'enabled' => !empty($_POST['woo_shop_enabled']),
            'shop_url' => $_POST['woo_shop_url'] ?? '',
            'hint' => $_POST['woo_shop_hint'] ?? '',
        ));
        usk_flash(__('settings_saved'));
    }
    header('Location: ' . usk_admin_url('settings') . ($section === 'connect_host' ? '#connect-host' : ($section === 'client_dns' ? '#client-dns' : ($section === 'panel_access' ? '#panel-access' : ($section === 'usage_sync' ? '#usage-sync' : ($section === 'woocommerce_shop' ? '#woocommerce-shop' : '')))));
    exit;
}

$panels = $sql->query("SELECT `code`,`name` FROM `panels`");
?>
<div class="alert alert-usk-info mb-4">
    <i class="fa-solid fa-circle-info"></i> <?= __('settings_wc_note') ?>
</div>

<?php if (USK_Admin_Auth::must_change_password()) : ?>
    <div class="alert alert-danger mb-4"><?= __('must_change_pass') ?></div>
<?php endif; ?>

<div class="usk-card mb-4">
    <div class="usk-card-header"><i class="fa-solid fa-user-gear"></i> <?= __('settings_account') ?></div>
    <div class="p-3">
        <p class="text-muted small mb-3"><?= __('settings_account_desc') ?></p>
        <form method="post">
            <input type="hidden" name="section" value="account">
            <div class="form-row">
                <div class="form-group">
                    <label><?= __('username') ?></label>
                    <input class="form-control" name="username" required value="<?= usk_esc($auth['username'] ?? 'admin') ?>">
                </div>
                <div class="form-group">
                    <label><?= __('settings_language') ?></label>
                    <select class="form-select" name="language">
                        <option value="fa" <?= ($auth['language'] ?? 'fa') === 'fa' ? 'selected' : '' ?>><?= __('settings_lang_fa') ?></option>
                        <option value="en" <?= ($auth['language'] ?? '') === 'en' ? 'selected' : '' ?>><?= __('settings_lang_en') ?></option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?= __('settings_new_password') ?></label>
                    <input class="form-control" type="password" name="password" autocomplete="new-password" placeholder="<?= __('settings_password_hint') ?>">
                </div>
                <div class="form-group">
                    <label><?= __('settings_confirm_password') ?></label>
                    <input class="form-control" type="password" name="password2" autocomplete="new-password">
                </div>
            </div>
            <button type="submit" class="btn btn-usk-primary"><?= __('save') ?></button>
        </form>
    </div>
</div>

<div class="usk-card mb-4" id="panel-access">
    <div class="usk-card-header"><i class="fa-solid fa-link"></i> <?= __('settings_panel_access') ?></div>
    <div class="p-3">
        <p class="text-muted small mb-3"><?= __('settings_panel_access_desc') ?></p>
        <p class="alert alert-usk-info small py-2 mb-3">
            <?= __('settings_panel_access_current') ?>:
            <code dir="ltr"><?= usk_esc($panelAdminUrl) ?></code>
        </p>
        <?php if (!empty($panelAccessCfg['last_apply_error'])) : ?>
            <p class="alert alert-danger small py-2"><?= usk_esc(USK_PanelAccess::error_label($panelAccessCfg['last_apply_error'])) ?></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="section" value="panel_access">
            <div class="form-group mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="panel_domain_enabled" id="panel-domain-enabled" value="1" <?= !empty($panelAccessCfg['domain_enabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="panel-domain-enabled"><?= __('settings_panel_access_domain_enable') ?></label>
                </div>
            </div>
            <div class="form-group mb-3">
                <label><?= __('settings_panel_access_domain') ?></label>
                <input class="form-control" name="panel_domain" dir="ltr" style="text-align:left;" value="<?= usk_esc($panelAccessCfg['panel_domain'] ?? '') ?>" placeholder="<?= __('settings_panel_access_domain_ph') ?>">
                <p class="text-muted small mt-1 mb-0"><?= __('settings_panel_access_domain_hint') ?></p>
                <p class="text-muted small mt-1 mb-0"><?= __('settings_panel_access_domain_lock_note') ?></p>
            </div>
            <div class="form-group mb-3">
                <label><?= __('settings_panel_access_port') ?></label>
                <input class="form-control" type="number" name="panel_port" min="1024" max="65535" dir="ltr" style="text-align:left;" value="<?= (int) ($panelAccessCfg['panel_port'] ?? 8082) ?>">
                <p class="text-muted small mt-1 mb-0"><?= __('settings_panel_access_port_hint') ?></p>
            </div>
            <div class="form-group mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="panel_https_enabled" id="panel-https-enabled" value="1" <?= !empty($panelAccessCfg['https_enabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="panel-https-enabled"><?= __('settings_panel_access_https_enable') ?></label>
                </div>
                <p class="text-muted small mt-1 mb-0"><?= __('settings_panel_access_https_hint') ?></p>
            </div>
            <?php if (USK_PanelAccess::is_domain_locked()) : ?>
            <p class="alert alert-usk-info small py-2"><?= __('settings_panel_access_ip_blocked') ?></p>
            <?php endif; ?>
            <div class="form-group mb-3">
                <label><?= __('settings_panel_access_hint_label') ?></label>
                <input class="form-control" name="panel_access_hint" value="<?= usk_esc($panelAccessCfg['hint'] ?? '') ?>" placeholder="<?= __('settings_panel_access_hint_ph') ?>">
            </div>
            <div class="alert alert-warning small py-2 mb-3">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= __('settings_panel_access_warn') ?>
            </div>
            <?php if (!USK_PanelAccess::can_apply_from_web()) : ?>
                <p class="text-danger small"><?= __('settings_panel_access_err_script') ?></p>
            <?php else : ?>
                <button type="submit" class="btn btn-usk-primary"><?= __('settings_panel_access_apply') ?></button>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="usk-card mb-4" id="connect-host">
    <div class="usk-card-header"><i class="fa-solid fa-server"></i> <?= __('settings_connect_host') ?></div>
    <div class="p-3">
        <p class="text-muted small mb-3"><?= __('settings_connect_host_desc') ?></p>
        <p class="text-muted small mb-3"><?= __('settings_connect_host_detected_ip') ?>: <code dir="ltr"><?= usk_esc($detectedServerIp) ?></code></p>
        <?php if (USK_ConnectHost::is_enabled()) : ?>
            <p class="alert alert-usk-info small py-2"><?= __('settings_connect_host_active') ?>: <code dir="ltr"><?= usk_esc(USK_ConnectHost::display()) ?></code></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="section" value="connect_host">
            <div class="form-group mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="connect_host_enabled" id="connect-host-enabled" value="1" <?= !empty($connectHostCfg['enabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="connect-host-enabled"><?= __('settings_connect_host_enable') ?></label>
                </div>
            </div>
            <div class="form-group mb-3">
                <label><?= __('settings_connect_host_domain') ?></label>
                <input class="form-control" name="connect_host" dir="ltr" style="text-align:left;" value="<?= usk_esc($connectHostCfg['connect_host'] ?? '') ?>" placeholder="<?= __('settings_connect_host_domain_ph') ?>">
                <p class="text-muted small mt-1 mb-0"><?= __('settings_connect_host_domain_hint') ?></p>
            </div>
            <div class="form-group mb-3">
                <label><?= __('settings_connect_host_hint_label') ?></label>
                <input class="form-control" name="connect_host_hint" value="<?= usk_esc($connectHostCfg['hint'] ?? '') ?>" placeholder="<?= __('settings_connect_host_hint_ph') ?>">
            </div>
            <button type="submit" class="btn btn-usk-primary"><?= __('save') ?></button>
        </form>
    </div>
</div>

<div class="usk-card mb-4" id="usage-sync">
    <div class="usk-card-header"><i class="fa-solid fa-gauge-high"></i> <?= __('settings_usage_sync') ?></div>
    <div class="p-3">
        <p class="text-muted small mb-3"><?= __('settings_usage_sync_desc') ?></p>
        <?php if (!empty($usageSyncStatus['last_sync_at'])) : ?>
            <p class="alert alert-usk-info small py-2 mb-3">
                <?= sprintf(
                    __('settings_usage_sync_last'),
                    usk_esc(USK_ProtocolLimits::format_last_run_at($usageSyncStatus['last_sync_at'])),
                    (int) ($usageSyncStatus['last_run']['usage_updated'] ?? 0),
                    (int) ($usageSyncStatus['last_run']['disabled'] ?? 0)
                ) ?>
            </p>
        <?php endif; ?>
        <?php if (!empty($usageSyncStatus['force_pending'])) : ?>
            <p class="alert alert-warning small py-2 mb-3"><?= __('settings_usage_sync_force_pending') ?></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="section" value="usage_sync">
            <div class="form-group mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="usage_sync_enabled" id="usage-sync-enabled" value="1" <?= !empty($usageSyncCfg['enabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="usage-sync-enabled"><?= __('settings_usage_sync_enable') ?></label>
                </div>
                <p class="text-muted small mt-1 mb-0"><?= __('settings_usage_sync_enable_hint') ?></p>
            </div>
            <div class="form-group mb-3">
                <label><?= __('settings_usage_sync_interval') ?></label>
                <div class="input-group" style="max-width:220px">
                    <input class="form-control" type="number" name="usage_sync_interval" min="1" max="120" step="1"
                           value="<?= (int) ($usageSyncCfg['interval_minutes'] ?? 5) ?>" dir="ltr" style="text-align:left">
                    <span class="input-group-text"><?= __('settings_usage_sync_minutes') ?></span>
                </div>
                <p class="text-muted small mt-1 mb-0"><?= __('settings_usage_sync_interval_hint') ?></p>
                <p class="text-muted small mt-1 mb-0"><?= __('settings_usage_sync_interval_presets') ?>:
                    <?php foreach (USK_UsageSyncSettings::preset_intervals() as $i => $mins) : ?><?= $i > 0 ? ', ' : '' ?><code><?= (int) $mins ?></code><?php endforeach; ?>
                </p>
            </div>
            <div class="alert alert-usk-info small py-2 mb-3">
                <i class="fa-solid fa-circle-info"></i> <?= __('settings_usage_sync_disable_note') ?>
            </div>
            <div class="form-group mb-3">
                <label><?= __('settings_usage_sync_hint_label') ?></label>
                <input class="form-control" name="usage_sync_hint" value="<?= usk_esc($usageSyncCfg['hint'] ?? '') ?>" placeholder="<?= __('settings_usage_sync_hint_ph') ?>">
            </div>
            <button type="submit" class="btn btn-usk-primary"><?= __('save') ?></button>
        </form>
    </div>
</div>

<div class="usk-card mb-4">
    <div class="usk-card-header"><?= __('settings_test') ?></div>
    <div class="p-3">
        <form method="post">
            <input type="hidden" name="section" value="test">
            <div class="form-row">
                <div class="form-group"><label><?= __('status') ?></label>
                    <select class="form-select" name="status">
                        <option value="active" <?= ($test['status'] ?? '') === 'active' ? 'selected' : '' ?>><?= __('active') ?></option>
                        <option value="inactive"><?= __('inactive') ?></option>
                    </select>
                </div>
                <div class="form-group"><label><?= __('server') ?></label>
                    <select class="form-select" name="panel">
                        <option value="none">—</option>
                        <?php while ($p = $panels->fetch_assoc()) : ?>
                            <option value="<?= usk_esc($p['code']) ?>" <?= ($test['panel'] ?? '') === $p['code'] ? 'selected' : '' ?>><?= usk_esc($p['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= __('volume') ?> (GB)</label><input class="form-control" name="volume" value="<?= usk_esc($test['volume'] ?? '1') ?>"></div>
                <div class="form-group"><label>Time (h)</label><input class="form-control" name="time" value="<?= usk_esc($test['time'] ?? '24') ?>"></div>
            </div>
            <button type="submit" class="btn btn-usk-primary"><?= __('save') ?></button>
        </form>
    </div>
</div>

<div class="usk-card mb-4" id="woocommerce-shop">
    <div class="usk-card-header"><i class="fa-brands fa-wordpress"></i> <?= __('settings_woo_shop') ?></div>
    <div class="p-3">
        <p class="text-muted small mb-3"><?= __('settings_woo_shop_desc') ?></p>
        <?php if (USK_WooCommerce_Shop::is_enabled()) : ?>
            <p class="alert alert-usk-info small py-2"><?= __('settings_woo_shop_active') ?>: <code dir="ltr"><?= usk_esc(USK_WooCommerce_Shop::shop_url()) ?></code></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="section" value="woocommerce_shop">
            <div class="form-group mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="woo_shop_enabled" id="woo-shop-enabled" value="1" <?= !empty($wooShopCfg['enabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="woo-shop-enabled"><?= __('settings_woo_shop_enable') ?></label>
                </div>
            </div>
            <div class="form-group mb-3">
                <label><?= __('settings_woo_shop_url') ?></label>
                <input class="form-control" name="woo_shop_url" dir="ltr" style="text-align:left;" value="<?= usk_esc($wooShopCfg['shop_url'] ?? '') ?>" placeholder="https://shop.example.com">
                <p class="text-muted small mt-1 mb-0"><?= __('settings_woo_shop_url_hint') ?></p>
            </div>
            <div class="form-group mb-3">
                <label><?= __('settings_woo_shop_hint_label') ?></label>
                <input class="form-control" name="woo_shop_hint" value="<?= usk_esc($wooShopCfg['hint'] ?? '') ?>" placeholder="<?= __('settings_woo_shop_hint_ph') ?>">
            </div>
            <p class="text-muted small mb-3"><?= __('settings_woo_shop_renew_note') ?></p>
            <button type="submit" class="btn btn-usk-primary"><?= __('save') ?></button>
        </form>
    </div>
</div>

<div class="usk-card mb-4" id="client-dns">
    <div class="usk-card-header"><i class="fa-solid fa-globe"></i> <?= __('settings_client_dns') ?></div>
    <div class="p-3">
        <p class="text-muted small mb-3"><?= __('settings_client_dns_desc') ?></p>
        <?php if (!empty($clientDns['enabled']) && USK_ClientDns::display_for_protocol('xray') !== '') : ?>
            <p class="alert alert-usk-info small py-2"><?= __('settings_client_dns_active') ?>: <code dir="ltr"><?= usk_esc(USK_ClientDns::display_for_protocol('xray')) ?></code></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="section" value="client_dns">
            <div class="form-group mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="dns_enabled" id="dns-enabled" value="1" <?= !empty($clientDns['enabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="dns-enabled"><?= __('settings_client_dns_enable') ?></label>
                </div>
            </div>
            <div class="form-group mb-3">
                <label><?= __('settings_client_dns_default') ?></label>
                <input class="form-control" name="default_dns" dir="ltr" style="text-align:left;" value="<?= usk_esc($clientDns['default_dns'] ?? '') ?>" placeholder="<?= __('create_client_dns_placeholder') ?>">
                <p class="text-muted small mt-1 mb-0"><?= __('settings_client_dns_default_hint') ?></p>
            </div>
            <details class="mb-3">
                <summary class="small text-muted mb-2" style="cursor:pointer;"><?= __('settings_client_dns_per_protocol') ?></summary>
                <div class="form-row mt-2">
                    <div class="form-group col-md-6">
                        <label>Xray</label>
                        <input class="form-control" name="xray_dns" dir="ltr" style="text-align:left;" value="<?= usk_esc($clientDns['xray_dns'] ?? '') ?>" placeholder="<?= __('settings_client_dns_optional') ?>">
                    </div>
                    <div class="form-group col-md-6">
                        <label>OpenVPN</label>
                        <input class="form-control" name="openvpn_dns" dir="ltr" style="text-align:left;" value="<?= usk_esc($clientDns['openvpn_dns'] ?? '') ?>" placeholder="<?= __('settings_client_dns_optional') ?>">
                    </div>
                    <div class="form-group col-md-6">
                        <label>WireGuard</label>
                        <input class="form-control" name="wireguard_dns" dir="ltr" style="text-align:left;" value="<?= usk_esc($clientDns['wireguard_dns'] ?? '') ?>" placeholder="<?= __('settings_client_dns_optional') ?>">
                    </div>
                </div>
            </details>
            <div class="form-group mb-3">
                <label><?= __('settings_client_dns_hint_label') ?></label>
                <input class="form-control" name="dns_hint" value="<?= usk_esc($clientDns['hint'] ?? '') ?>" placeholder="<?= __('settings_client_dns_hint_ph') ?>">
            </div>
            <button type="submit" class="btn btn-usk-primary"><?= __('save') ?></button>
        </form>
    </div>
</div>

<div class="usk-card">
    <div class="usk-card-header"><?= __('settings_spam') ?></div>
    <div class="p-3">
        <form method="post">
            <input type="hidden" name="section" value="spam">
            <div class="form-row">
                <div class="form-group"><label><?= __('status') ?></label>
                    <select class="form-select" name="status">
                        <option value="active" <?= ($spam['status'] ?? '') === 'active' ? 'selected' : '' ?>><?= __('active') ?></option>
                        <option value="inactive"><?= __('inactive') ?></option>
                    </select>
                </div>
                <div class="form-group"><label>Type</label>
                    <select class="form-select" name="type">
                        <option value="ban" <?= ($spam['type'] ?? '') === 'ban' ? 'selected' : '' ?>>Ban</option>
                        <option value="warn">Warn</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Interval (s)</label><input class="form-control" name="time" value="<?= usk_esc($spam['time'] ?? '3') ?>"></div>
                <div class="form-group"><label>Count</label><input class="form-control" name="count_message" value="<?= usk_esc($spam['count_message'] ?? '10') ?>"></div>
            </div>
            <button type="submit" class="btn btn-usk-primary"><?= __('save') ?></button>
        </form>
    </div>
</div>

<div class="usk-card mt-3" id="usk-backup-section">
    <div class="usk-card-header"><i class="fa-solid fa-database"></i> <?= __('nav_backup') ?></div>
    <div class="p-3">
        <?php
        $backupFormSuffix = '-settings';
        $backupReturnPage = 'settings';
        require __DIR__ . '/../includes/backup-panel.php';
        ?>
    </div>
</div>
