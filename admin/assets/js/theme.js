(function () {
    var KEY = 'unlimitsky-theme';
    function apply(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        var icon = document.getElementById('theme-icon');
        if (icon) {
            icon.className = theme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        }
    }
    var saved = localStorage.getItem(KEY);
    if (!saved) saved = 'dark';
    apply(saved);
    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('theme-toggle');
        if (btn) {
            btn.addEventListener('click', function () {
                var cur = document.documentElement.getAttribute('data-bs-theme');
                var next = cur === 'dark' ? 'light' : 'dark';
                localStorage.setItem(KEY, next);
                apply(next);
            });
        }
    });
})();
