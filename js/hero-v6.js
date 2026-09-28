/**
 * Hero V6 — drei Etagen (senkrechtes Akkordeon).
 * Eine Etage ist offen, zeigt Bild, Satz und Link und hebt das passende
 * Wort der Headline hervor (wie V5).
 * Hover oder Fokus oeffnet eine Etage; auf Touch-Geraeten oeffnet der erste
 * Tipp, der zweite folgt dem Link. Ohne Interaktion wechselt sie auf dem
 * Desktop langsam von selbst (nicht bei prefers-reduced-motion).
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-etagen]');
    if (!root) return;

    var etagen = Array.prototype.slice.call(root.querySelectorAll('.etage'));
    var woerter = Array.prototype.slice.call(root.querySelectorAll('.etagen-hero__wort'));
    if (!etagen.length) return;

    var desktop = window.matchMedia('(min-width: 901px)');
    var reduziert = window.matchMedia('(prefers-reduced-motion: reduce)');
    var aktiv = 0;
    var timer = null;

    function aktiviere(i) {
        aktiv = i;
        etagen.forEach(function (e, n) { e.classList.toggle('is-aktiv', n === i); });
        woerter.forEach(function (w, n) { w.classList.toggle('is-aktiv', n === i); });
    }

    function stopp() {
        clearInterval(timer);
        timer = null;
    }

    function start() {
        if (timer || reduziert.matches || !desktop.matches) return;
        timer = setInterval(function () {
            if (!document.hidden) aktiviere((aktiv + 1) % etagen.length);
        }, 5500);
    }

    etagen.forEach(function (etage, i) {
        var tippOeffnet = false;

        // Nur echte Maus: beim Tippen feuern Browser ebenfalls mouseenter/focus
        etage.addEventListener('pointerenter', function (e) {
            if (e.pointerType === 'mouse') { stopp(); aktiviere(i); }
        });
        etage.addEventListener('pointerdown', function (e) {
            tippOeffnet = e.pointerType !== 'mouse' && aktiv !== i;
        });
        etage.addEventListener('focusin', function () { stopp(); aktiviere(i); });

        // Touch: erster Tipp oeffnet die Etage, zweiter folgt dem Link
        var link = etage.querySelector('.etage__link');
        if (link) {
            link.addEventListener('click', function (e) {
                if (tippOeffnet) {
                    e.preventDefault();
                    tippOeffnet = false;
                    stopp();
                    aktiviere(i);
                }
            });
        }
    });

    // Headline-Woerter: Maus darueber oder Antippen oeffnet die passende Etage
    woerter.forEach(function (wort, i) {
        wort.addEventListener('pointerenter', function (e) {
            if (e.pointerType === 'mouse') { stopp(); aktiviere(i); }
        });
        wort.addEventListener('click', function () { stopp(); aktiviere(i); });
    });

    root.addEventListener('mouseleave', start);
    desktop.addEventListener('change', function () { stopp(); start(); });

    start();
})();
