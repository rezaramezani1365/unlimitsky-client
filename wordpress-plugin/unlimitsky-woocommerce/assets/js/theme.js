(function () {
    var KEY = 'unlimitsky-wp-theme';
    function apply(theme) {
        document.querySelectorAll('.usk-admin-wrap').forEach(function (el) {
            el.classList.toggle('usk-dark', theme === 'dark');
        });
        var icon = document.getElementById('usk-wp-theme-icon');
        if (icon) icon.textContent = theme === 'dark' ? '☀️' : '🌙';
    }
    document.addEventListener('DOMContentLoaded', function () {
        var saved = localStorage.getItem(KEY) || 'light';
        apply(saved);
        var btn = document.getElementById('usk-wp-theme-toggle');
        if (btn) {
            btn.addEventListener('click', function () {
                var next = localStorage.getItem(KEY) === 'dark' ? 'light' : 'dark';
                localStorage.setItem(KEY, next);
                apply(next);
            });
        }
    });
})();
