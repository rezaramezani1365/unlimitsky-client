<?php
$uskFooterVersion = usk_panel_version();
$uskFooterGithub = usk_github_repo_url();
?>
<footer class="usk-panel-footer" role="contentinfo">
    <a class="usk-panel-footer-link" href="<?= usk_esc($uskFooterGithub) ?>" target="_blank" rel="noopener noreferrer" title="<?= usk_esc(__('footer_github')) ?>">
        <i class="fa-brands fa-github" aria-hidden="true"></i>
        <span>unlimitsky-client</span>
    </a>
    <span class="usk-panel-footer-sep" aria-hidden="true">·</span>
    <span class="usk-panel-footer-version">v<?= usk_esc($uskFooterVersion) ?></span>
</footer>
