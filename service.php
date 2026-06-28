<?php

define('USK_ROOT', __DIR__);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin/lib/panel-access.php';
USK_PanelAccess::enforce_request_host();
require_once __DIR__ . '/admin/lib/i18n.php';
require_once __DIR__ . '/admin/lib/protocols/limits.php';
require_once __DIR__ . '/admin/lib/customer-portal.php';
require_once __DIR__ . '/admin/lib/usage-sync-settings.php';

$portalUsageInterval = (int) (USK_UsageSyncSettings::get()['interval_minutes'] ?? 5);

$portalLang = strtolower((string) ($_GET['lang'] ?? ($_SESSION['usk_portal_lang'] ?? 'en')));
if (!in_array($portalLang, array('fa', 'en'), true)) {
    $portalLang = 'en';
}
$_SESSION['usk_portal_lang'] = $portalLang;
USK_I18n::boot($portalLang);

$code = (string) ($_GET['code'] ?? '');
$token = (string) ($_GET['t'] ?? '');
$view = USK_CustomerPortal::load($code, $token);

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$portalStatsUrl = ($scriptDir === '' || $scriptDir === '.') ? '/portal-stats.php' : ($scriptDir . '/portal-stats.php');
$assetBase = ($scriptDir === '' || $scriptDir === '.') ? '/admin/assets' : ($scriptDir . '/admin/assets');
$isRtl = USK_I18n::is_rtl();
$bootstrapFile = $isRtl ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';
$lang = USK_I18n::lang();

$queryBase = array();
if ($code !== '') {
    $queryBase['code'] = $code;
}
if ($token !== '') {
    $queryBase['t'] = $token;
}
$langUrlFa = '?' . http_build_query(array_merge($queryBase, array('lang' => 'fa')));
$langUrlEn = '?' . http_build_query(array_merge($queryBase, array('lang' => 'en')));

function portal_esc($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

?><!DOCTYPE html>
<html lang="<?= portal_esc($lang) ?>" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= portal_esc(__('portal_page_title')) ?></title>
    <link href="<?= portal_esc($assetBase . '/css/fonts.css') ?>" rel="stylesheet">
    <link href="<?= portal_esc($assetBase . '/vendor/fontawesome/css/all.min.css') ?>" rel="stylesheet">
    <link href="<?= portal_esc($assetBase . '/vendor/bootstrap/' . $bootstrapFile) ?>" rel="stylesheet">
    <link href="<?= portal_esc($assetBase . '/css/customer-portal.css') ?>" rel="stylesheet">
    <script src="<?= portal_esc($assetBase . '/js/theme.js') ?>"></script>
</head>
<body class="portal-body">
<div class="portal-wrap">
    <header class="portal-header">
        <div class="portal-brand"><i class="fa-solid fa-shield-halved text-primary"></i> <?= portal_esc(__('portal_page_title')) ?></div>
        <div class="portal-header-actions">
            <select class="portal-lang-select" id="portal-lang-select" aria-label="<?= portal_esc(__('portal_language')) ?>">
                <option value="en"<?= $lang === 'en' ? ' selected' : '' ?>>English</option>
                <option value="fa"<?= $lang === 'fa' ? ' selected' : '' ?>>فارسی</option>
            </select>
            <button type="button" class="portal-icon-btn" id="portal-theme-toggle" aria-label="<?= portal_esc(__('theme')) ?>">
                <i class="fa-solid fa-circle-half-stroke"></i>
            </button>
        </div>
    </header>

<?php if (empty($view['ok'])) : ?>
    <div class="portal-card portal-error-card">
        <i class="fa-solid fa-link-slash"></i>
        <h1 class="h5"><?= portal_esc(__('portal_error_title')) ?></h1>
        <p class="text-muted mb-0"><?= portal_esc(__('portal_error_' . ($view['error'] ?? 'not_found'))) ?></p>
    </div>
<?php else :
    $svcStatus = $view['service_status'] ?? 'active';
    $badgeClass = 'portal-badge-active';
    $badgeKey = 'portal_status_active';
    if ($svcStatus === 'expired') {
        $badgeClass = 'portal-badge-danger';
        $badgeKey = 'portal_status_expired';
    } elseif ($svcStatus === 'volume_exceeded') {
        $badgeClass = 'portal-badge-warn';
        $badgeKey = 'portal_status_volume';
    }
    $usage = $view['usage'] ?? array();
    $remaining = $view['remaining'] ?? array();
    $protocol = $view['protocol'] ?? '';
?>
    <div class="portal-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h5 mb-1"><?= portal_esc(USK_CustomerPortal::protocol_label($protocol)) ?></h1>
                <span class="portal-badge <?= portal_esc($badgeClass) ?>"><?= portal_esc(__($badgeKey)) ?></span>
            </div>
            <div class="text-muted small"><?= portal_esc(__('code')) ?>: <code><?= portal_esc($view['order']['code'] ?? '') ?></code></div>
        </div>
        <div class="portal-stat-grid">
            <div class="portal-stat">
                <div class="label"><?= portal_esc(__('portal_remaining_time')) ?></div>
                <div class="value"><?php
                    if (!empty($remaining['expired'])) {
                        echo portal_esc(__('portal_expired'));
                    } elseif ($remaining['days'] !== null) {
                        echo portal_esc(sprintf(__('portal_remaining_fmt'), (int) $remaining['days'], (int) $remaining['hours']));
                    } else {
                        echo portal_esc(__('portal_unlimited_time'));
                    }
                ?></div>
            </div>
            <div class="portal-stat" id="portal-usage-block"
                 data-stats-url="<?= portal_esc($portalStatsUrl) ?>"
                 data-code="<?= portal_esc($view['order']['code'] ?? '') ?>"
                 data-token="<?= portal_esc($token) ?>">
                <div class="label"><?= portal_esc(__('volume')) ?></div>
                <div class="value"><?= (int) ($view['volume_gb'] ?? 0) ?> GB</div>
                <?php if (($view['volume_gb'] ?? 0) > 0) : ?>
                <div class="portal-progress"><div class="portal-progress-bar" id="portal-usage-bar" style="width:<?= min(100, (float) ($usage['percent'] ?? 0)) ?>%"></div></div>
                <div class="small text-muted mt-1" id="portal-usage-text"><?= portal_esc(__('portal_used')) ?>: <?= portal_esc((string) ($usage['used_gb'] ?? 0)) ?> GB · <?= portal_esc(__('portal_left')) ?>: <?= portal_esc((string) ($usage['remaining_gb'] ?? 0)) ?> GB</div>
                <div class="small text-muted mt-1"><i class="fa-solid fa-clock"></i> <?= portal_esc(sprintf(__('portal_stats_live'), $portalUsageInterval)) ?></div>
                <?php endif; ?>
            </div>
            <div class="portal-stat" id="portal-connections-block"
                 data-stats-url="<?= portal_esc($portalStatsUrl) ?>"
                 data-code="<?= portal_esc($view['order']['code'] ?? '') ?>"
                 data-token="<?= portal_esc($token) ?>"
                 data-live="<?= !empty($usage['connections_tracked']) ? '1' : '0' ?>">
                <div class="label"><?= portal_esc(__('portal_max_connections')) ?></div>
                <div class="value" id="portal-connections-value"><?php
                    $usageConn = $usage ?? array();
                    if (!empty($usageConn['connections_display'])) {
                        echo portal_esc((string) $usageConn['connections_display']);
                    } else {
                        require_once __DIR__ . '/admin/lib/protocols/connections.php';
                        $proto = (string) ($view['order']['protocol'] ?? '');
                        $lbl = USK_ProtocolConnections::slots_label_for(
                            array('max_connections' => (int) ($view['max_connections'] ?? 1)),
                            $proto
                        );
                        echo portal_esc($lbl !== null ? $lbl : ((int) ($view['max_connections'] ?? 1) . ' ' . __('plan_connections_unit')));
                    }
                ?></div>
            </div>
            <div class="portal-stat">
                <div class="label"><?= portal_esc(__('duration')) ?></div>
                <div class="value"><?= (int) ($view['duration_days'] ?? 0) ?> <?= portal_esc(__('days')) ?></div>
            </div>
        </div>
    </div>

<?php if (!empty($view['renew_enabled']) && !empty($view['renew_plans'])) : ?>
    <div class="portal-card portal-renew-card">
        <h2><i class="fa-solid fa-rotate"></i> <?= portal_esc(__('portal_renew_title')) ?></h2>
        <p class="small text-muted mb-3"><?= portal_esc(__('portal_renew_hint')) ?></p>
        <p class="small text-muted mb-3"><?= portal_esc(sprintf(__('portal_renew_protocol_note'), USK_CustomerPortal::protocol_label($protocol))) ?></p>
        <div class="portal-renew-grid">
            <?php foreach ($view['renew_plans'] as $plan) : ?>
            <a class="portal-renew-plan" href="<?= portal_esc($plan['url']) ?>" rel="noopener">
                <div class="portal-renew-plan-name"><?= portal_esc($plan['name']) ?></div>
                <div class="portal-renew-plan-meta">
                    <?= (int) ($plan['volume_gb'] ?? 0) ?> GB · <?= (int) ($plan['duration_days'] ?? 0) ?> <?= portal_esc(__('days')) ?>
                </div>
                <?php if (!empty($plan['price']) && (int) $plan['price'] > 0) : ?>
                <div class="portal-renew-plan-price"><?= portal_esc(number_format((int) $plan['price'])) ?></div>
                <?php endif; ?>
                <span class="portal-renew-plan-btn"><?= portal_esc(__('portal_renew_btn')) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($view['show_copy_link']) && !empty($view['primary_link'])) : ?>
    <div class="portal-card">
        <h2><i class="fa-solid fa-link"></i> <?= portal_esc(__('portal_connection_link')) ?></h2>
        <div class="portal-link-box">
            <input type="text" class="portal-link-input" id="portal-primary-link" readonly value="<?= portal_esc($view['primary_link']) ?>">
            <button type="button" class="btn-portal-copy" id="portal-copy-btn" data-copy-target="portal-primary-link">
                <i class="fa-regular fa-copy"></i> <?= portal_esc(__('portal_copy')) ?>
            </button>
        </div>
        <?php if (!empty($view['show_qr'])) : ?>
        <div class="text-center mt-3">
            <p class="small text-muted mb-2"><?= portal_esc(__('portal_qr_hint')) ?></p>
            <div class="portal-qr-wrap">
                <?php if (!empty($view['qr_b64'])) : ?>
                    <img id="portal-qr-img" src="data:image/png;base64,<?= portal_esc($view['qr_b64']) ?>" alt="QR">
                <?php else : ?>
                    <canvas id="portal-qr-canvas"></canvas>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($view['wireguard_tcp_mode'])) : ?>
    <div class="portal-card" style="border-color:#f0ad4e;">
        <h2><i class="fa-solid fa-plug"></i> <?= portal_esc(__('portal_wg_tcp_title')) ?></h2>
        <p class="small text-muted mb-3"><?= portal_esc(__('portal_wg_tcp_hint')) ?></p>
        <?php if (!empty($view['wireguard_tcp_cmd'])) : ?>
        <div class="portal-link-box">
            <input type="text" class="portal-link-input" id="portal-wg-tcp-cmd" readonly value="<?= portal_esc($view['wireguard_tcp_cmd']) ?>" dir="ltr" style="text-align:left;">
            <button type="button" class="btn-portal-copy" data-copy-target="portal-wg-tcp-cmd">
                <i class="fa-regular fa-copy"></i> <?= portal_esc(__('portal_copy')) ?>
            </button>
        </div>
        <?php endif; ?>
        <p class="small text-muted mt-3 mb-0"><?= portal_esc(__('portal_wg_tcp_steps')) ?></p>
    </div>
<?php endif; ?>

<?php if (!empty($view['credentials'])) : ?>
    <div class="portal-card">
        <h2><i class="fa-solid fa-key"></i> <?= portal_esc(__('portal_credentials')) ?></h2>
        <?php foreach ($view['credentials'] as $cred) : ?>
        <div class="portal-cred-row">
            <span class="portal-cred-label"><?= portal_esc($cred['label']) ?></span>
            <span class="portal-cred-value"><?= portal_esc($cred['value']) ?></span>
            <button type="button" class="btn btn-sm btn-outline-secondary portal-copy-mini" data-copy-text="<?= portal_esc($cred['value']) ?>">
                <i class="fa-regular fa-copy"></i>
            </button>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($view['show_download']) && !empty($view['download_url'])) : ?>
    <div class="portal-card">
        <h2><i class="fa-solid fa-download"></i> <?= portal_esc(__('portal_download_config')) ?></h2>
        <p class="small <?= !empty($view['wireguard_tcp_mode']) ? 'text-warning' : 'text-muted' ?>"><?= portal_esc(!empty($view['wireguard_tcp_mode']) ? __('portal_wg_tcp_download_hint') : __('portal_download_hint')) ?></p>
        <a class="btn-portal-download" href="<?= portal_esc($view['download_url']) ?>" download="<?= portal_esc($view['download_filename'] ?? 'config') ?>">
            <i class="fa-solid fa-file-arrow-down"></i>
            <?php
            if ($protocol === 'openvpn') {
                echo portal_esc(__('download_openvpn'));
            } elseif ($protocol === 'wireguard') {
                echo portal_esc(__('download_wireguard_conf'));
            } elseif ($protocol === 'xray') {
                echo portal_esc(__('download_xray_json'));
            } else {
                echo portal_esc(__('portal_download_btn'));
            }
            ?>
        </a>
    </div>
<?php endif; ?>

<?php if (!empty($view['apps'])) : ?>
    <div class="portal-card">
        <h2><i class="fa-solid fa-mobile-screen"></i> <?= portal_esc(__('portal_apps_title')) ?></h2>
        <p class="small text-muted mb-3"><?= portal_esc(__('portal_apps_hint')) ?></p>
        <div class="portal-app-grid">
            <?php foreach ($view['apps'] as $app) : ?>
            <a class="portal-app-btn" href="<?= portal_esc($app['url']) ?>" target="_blank" rel="noopener noreferrer" style="background:<?= portal_esc($app['color']) ?>">
                <i class="<?= portal_esc($app['icon']) ?>"></i>
                <span><?= portal_esc($app['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php endif; ?>
</div>

<script src="<?= portal_esc($assetBase . '/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
(function () {
    var langSelect = document.getElementById('portal-lang-select');
    if (langSelect) {
        langSelect.addEventListener('change', function () {
            var url = new URL(window.location.href);
            url.searchParams.set('lang', langSelect.value);
            window.location.href = url.toString();
        });
    }
    function copyText(text, btn) {
        if (!text) return;
        var done = function () {
            if (!btn) return;
            btn.classList.add('copied');
            var orig = btn.dataset.origLabel || btn.innerHTML;
            btn.dataset.origLabel = orig;
            btn.innerHTML = '<i class="fa-solid fa-check"></i> <?= portal_esc(__('portal_copied')) ?>';
            setTimeout(function () {
                btn.classList.remove('copied');
                btn.innerHTML = orig;
            }, 2000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                fallbackCopy(text, done);
            });
        } else {
            fallbackCopy(text, done);
        }
    }
    function fallbackCopy(text, cb) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        if (cb) cb();
    }
    var copyBtn = document.getElementById('portal-copy-btn');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var el = document.getElementById(copyBtn.getAttribute('data-copy-target'));
            if (el) copyText(el.value, copyBtn);
        });
    }
    document.querySelectorAll('.portal-copy-mini').forEach(function (btn) {
        btn.addEventListener('click', function () {
            copyText(btn.getAttribute('data-copy-text'), btn);
        });
    });
    var themeBtn = document.getElementById('portal-theme-toggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var html = document.documentElement;
            var next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-bs-theme', next);
            try { localStorage.setItem('usk-theme', next); } catch (e) {}
        });
    }
    var qrCanvas = document.getElementById('portal-qr-canvas');
    var linkEl = document.getElementById('portal-primary-link');
    if (qrCanvas && linkEl && typeof QRCode !== 'undefined') {
        QRCode.toCanvas(qrCanvas, linkEl.value, { width: 220, margin: 2 }, function () {});
    }
})();
</script>
</body>
</html>
