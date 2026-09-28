/**
 * Hero V7 — senkrechtes Karussell.
 * Drei Folien laufen endlos nach oben: aktiv in der Mitte, vorige und
 * naechste angeschnitten oben/unten; das passende Wort der Headline ist
 * hervorgehoben (wie V5). Die Fuellung der Leiste (CSS-Animation)
 * schaltet weiter; Hover, Tastaturfokus oder ein Hero ausserhalb des
 * Sichtfelds pausieren. Klick/Tipp auf eine angeschnittene Folie holt sie
 * in die Mitte, erst der naechste Klick folgt dem Link.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-karussell]');
    if (!root) return;

    var folien = Array.prototype.slice.call(root.querySelectorAll('.folie'));
    var punkte = Array.prototype.slice.call(root.querySelectorAll('.karussell__punkt'));
    var woerter = Array.prototype.slice.call(document.querySelectorAll('.karussell-hero__wort'));
    var n = folien.length;
    if (n < 3) return;

    var ROLLEN = ['is-aktiv', 'is-nach', 'is-vor', 'is-raus-oben', 'is-raus-unten'];
    var aktiv = 0;
    var wechsel = null; // laufender Sprung einer Folie von oben nach unten (oder umgekehrt)
    var pausen = {};

    function rolle(i, ziel) {
        var abstand = (i - ziel + n) % n;
        if (abstand === 0) return 'is-aktiv';
        if (abstand === 1) return 'is-nach';
        if (abstand === n - 1) return 'is-vor';
        return 'is-raus-unten';
    }

    function setzeRolle(folie, neu) {
        ROLLEN.forEach(function (r) { folie.classList.toggle(r, r === neu); });
    }

    function aktuelleRolle(folie) {
        for (var r = 0; r < ROLLEN.length; r++) {
            if (folie.classList.contains(ROLLEN[r])) return ROLLEN[r];
        }
        return '';
    }

    function wechselAbschliessen() {
        if (!wechsel) return;
        clearTimeout(wechsel.timer);
        wechsel.fertig();
        wechsel = null;
    }

    // Folie ohne sichtbare Bewegung auf die Gegenseite setzen und dann einfahren lassen
    function springe(folie, ueber, neu) {
        setzeRolle(folie, ueber);
        var fertig = function () {
            folie.classList.add('ohne-uebergang');
            setzeRolle(folie, ueber === 'is-raus-oben' ? 'is-raus-unten' : 'is-raus-oben');
            void folie.offsetHeight;
            folie.classList.remove('ohne-uebergang');
            setzeRolle(folie, neu);
        };
        wechsel = { fertig: fertig, timer: setTimeout(function () { fertig(); wechsel = null; }, 450) };
    }

    function gehe(ziel) {
        ziel = (ziel + n) % n;
        if (ziel === aktiv) return;
        wechselAbschliessen();

        folien.forEach(function (folie, i) {
            var alt = aktuelleRolle(folie);
            var neu = rolle(i, ziel);
            if (alt === 'is-vor' && neu === 'is-nach') springe(folie, 'is-raus-oben', neu);
            else if (alt === 'is-nach' && neu === 'is-vor') springe(folie, 'is-raus-unten', neu);
            else setzeRolle(folie, neu);
        });

        punkte.forEach(function (p, i) {
            p.classList.toggle('is-aktiv', i === ziel);
            if (i === ziel) p.setAttribute('aria-current', 'true');
            else p.removeAttribute('aria-current');
        });

        woerter.forEach(function (w, i) { w.classList.toggle('is-aktiv', i === ziel); });

        aktiv = ziel;
    }

    function pause(grund, an) {
        pausen[grund] = an;
        var pausiert = Object.keys(pausen).some(function (k) { return pausen[k]; });
        root.classList.toggle('ist-pausiert', pausiert);
    }

    // Autoplay: Ende der Fuellung der aktiven Leiste schaltet weiter
    punkte.forEach(function (punkt, i) {
        punkt.addEventListener('click', function () { gehe(i); });
        punkt.querySelector('.karussell__fuellung').addEventListener('animationend', function () {
            if (i === aktiv) gehe(aktiv + 1);
        });
    });

    root.querySelector('[data-zurueck]').addEventListener('click', function () { gehe(aktiv - 1); });
    root.querySelector('[data-weiter]').addEventListener('click', function () { gehe(aktiv + 1); });

    folien.forEach(function (folie, i) {
        var link = folie.querySelector('.folie__link');
        var holen = false;

        folie.addEventListener('pointerdown', function () { holen = aktiv !== i; });
        link.addEventListener('click', function (e) {
            if (holen || aktiv !== i) {
                e.preventDefault();
                holen = false;
                gehe(i);
            }
        });
        // Tastatur: eine angeschnittene Folie kommt beim Fokussieren in die Mitte
        link.addEventListener('focus', function () {
            if (link.matches(':focus-visible')) gehe(i);
        });
    });

    // Headline-Woerter: Maus darueber holt die passende Folie und pausiert, Antippen holt sie
    woerter.forEach(function (wort, i) {
        wort.addEventListener('pointerenter', function (e) {
            if (e.pointerType === 'mouse') { pause('wort', true); gehe(i); }
        });
        wort.addEventListener('pointerleave', function () { pause('wort', false); });
        wort.addEventListener('click', function () { gehe(i); });
    });

    root.addEventListener('pointerenter', function (e) {
        if (e.pointerType === 'mouse') pause('hover', true);
    });
    root.addEventListener('pointerleave', function () { pause('hover', false); });
    root.addEventListener('focusin', function (e) {
        if (e.target.matches(':focus-visible')) pause('fokus', true);
    });
    root.addEventListener('focusout', function (e) {
        if (!root.contains(e.relatedTarget)) pause('fokus', false);
    });

    if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (eintraege) {
            pause('unsichtbar', !eintraege[0].isIntersecting);
        }).observe(root);
    }
})();
