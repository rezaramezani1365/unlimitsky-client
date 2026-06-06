<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/init.php';

$GLOBALS['page_title'] = __('nav_settings');
$GLOBALS['active_nav'] = 'settings';

global $sql;
$auth = USK_Admin_Auth::get_data();
$test = $sql->query("SELECT * FROM `test_account_setting` LIMIT 1")->fetch_assoc();
$spam = $sql->query("SELECT * FROM `spam_setting` LIMIT 1")->fetch_assoc();
$clientDns = USK_ClientDns::get();

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
    header('Location: ' . usk_admin_url('settings'));
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
                        <label>Amnezia</label>
                        <input class="form-control" name="amnezia_dns" dir="ltr" style="text-align:left;" value="<?= usk_esc($clientDns['amnezia_dns'] ?? '') ?>" placeholder="<?= __('settings_client_dns_optional') ?>">
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

<div class="usk-card mt-3">
    <div class="usk-card-header"><i class="fa-solid fa-database"></i> <?= __('nav_backup') ?></div>
    <div class="p-3">
        <p class="text-muted small mb-3"><?= __('backup_v1_intro') ?></p>
        <a class="btn btn-outline-usk" href="<?= usk_admin_url('backup') ?>"><i class="fa-solid fa-database"></i> <?= __('nav_backup') ?></a>
    </div>
</div>
