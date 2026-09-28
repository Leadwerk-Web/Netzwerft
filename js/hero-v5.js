/**
 * Hero V5 — drei Tueren.
 * Eine Tafel ist offen und hebt das passende Wort der Headline hervor.
 * Hover, Fokus oder Tippen oeffnet eine Tafel; ohne Interaktion wechselt sie
 * auf dem Desktop langsam von selbst (nicht bei prefers-reduced-motion).
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-tueren]');
    if (!root) return;

    var tueren = Array.prototype.slice.call(root.querySelectorAll('.tuer'));
    var woerter = Array.prototype.slice.call(root.querySelectorAll('.tueren-hero__wort'));
    if (!tueren.length) return;

    var desktop = window.matchMedia('(min-width: 901px)');
    var ohneHover = window.matchMedia('(hover: none)');
    var reduziert = window.matchMedia('(prefers-reduced-motion: reduce)');
    var aktiv = 0;
    var timer = null;

    function aktiviere(i) {
        aktiv = i;
        tueren.forEach(function (t, n) { t.classList.toggle('is-aktiv', n === i); });
        woerter.forEach(function (w, n) { w.classList.toggle('is-aktiv', n === i); });
    }

    function stopp() {
        clearInterval(timer);
        timer = null;
    }

    function start() {
        if (timer || reduziert.matches || !desktop.matches) return;
        timer = setInterval(function () {
            if (!document.hidden) aktiviere((aktiv + 1) % tueren.length);
        }, 5500);
    }

    tueren.forEach(function (tuer, i) {
        tuer.addEventListener('mouseenter', function () { stopp(); aktiviere(i); });
        tuer.addEventListener('focusin', function () { stopp(); aktiviere(i); });

        // Touch auf dem Desktop-Layout: erster Tipp oeffnet die Tafel, zweiter folgt dem Link.
        var link = tuer.querySelector('.tuer__link');
        if (link) {
            link.addEventListener('click', function (e) {
                if (desktop.matches && ohneHover.matches && aktiv !== i) {
                    e.preventDefault();
                    stopp();
                    aktiviere(i);
                }
            });
        }
    });

    woerter.forEach(function (wort, i) {
        wort.addEventListener('mouseenter', function () { stopp(); aktiviere(i); });
    });

    root.addEventListener('mouseleave', start);
    desktop.addEventListener('change', function () { stopp(); start(); });

    start();
})();
