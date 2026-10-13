/**
 * UnitSync theme switcher (light / dark).
 *
 * - First visit: follows the operating system (prefers-color-scheme).
 * - After the user clicks a toggle: their choice is saved in localStorage and wins.
 * - The initial theme is applied by the inline script in includes/theme_head.php
 *   (before first paint) so there is no flash; this file only handles interaction.
 * Standalone: no dependencies. Load with defer on every page that has a toggle.
 */
(function () {
    'use strict';

    var KEY = 'unitsync-theme';
    var root = document.documentElement;
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var COLORS = { light: '#FBFBFA', dark: '#141612' };
    var timer;

    function stored() {
        try { var v = localStorage.getItem(KEY); return (v === 'light' || v === 'dark') ? v : null; }
        catch (e) { return null; }
    }
    function system() { return media && media.matches ? 'dark' : 'light'; }
    function current() { return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; }

    // Keep every toggle button and the browser chrome colour in sync with the theme
    function sync() {
        var dark = current() === 'dark';
        var buttons = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].setAttribute('aria-pressed', dark ? 'true' : 'false');
            buttons[i].setAttribute('title', dark ? 'Switch to light mode' : 'Switch to dark mode');
        }
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) { meta.setAttribute('content', COLORS[dark ? 'dark' : 'light']); }
    }

    function apply(theme, animate) {
        if (animate) {
            root.classList.add('theme-switching');
            clearTimeout(timer);
            timer = setTimeout(function () { root.classList.remove('theme-switching'); }, 260);
        }
        root.setAttribute('data-theme', theme);
        sync();
    }

    function onToggle() {
        var next = current() === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem(KEY, next); } catch (e) { /* private mode: still switch for this page */ }
        apply(next, true);
    }

    function init() {
        var buttons = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < buttons.length; i++) { buttons[i].addEventListener('click', onToggle); }
        sync();
    }

    // Follow OS changes live, but only while the user has not made a choice
    if (media) {
        var onSystem = function () { if (!stored()) { apply(system(), true); } };
        if (media.addEventListener) { media.addEventListener('change', onSystem); }
        else if (media.addListener) { media.addListener(onSystem); }
    }
    // Keep other open tabs in step
    window.addEventListener('storage', function (e) {
        if (e.key === KEY) { apply(stored() || system(), true); }
    });

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();
