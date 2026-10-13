<?php
// Theme bootstrap. Include as the FIRST items inside <head>, before any stylesheet,
// so the saved/OS theme is applied before first paint (no flash of the wrong theme).
?>
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#FBFBFA">
<script>
    (function () {
        var d = document.documentElement;
        d.classList.add('js');
        try {
            var t = localStorage.getItem('unitsync-theme');
            if (t !== 'light' && t !== 'dark') {
                t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            }
            d.setAttribute('data-theme', t);
        } catch (e) {}
    })();
</script>
