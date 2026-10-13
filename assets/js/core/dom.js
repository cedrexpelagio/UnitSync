/**
 * UnitSync core: shared DOM helpers.
 * Load this BEFORE any page script (it is included in the js/core.js bundle).
 * Exposes a single global: window.UnitSync.
 */
(function () {
    'use strict';

    var reducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function $(selector, root) { return (root || document).querySelector(selector); }
    function $$(selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }

    var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) { return ESC[c]; });
    }

    window.UnitSync = { $: $, $$: $$, esc: esc, reducedMotion: reducedMotion };
})();
