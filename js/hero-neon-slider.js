/**
 * Hero V3 — Neon full-bleed Leistungs-Slider
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-neon-slider]');
    if (!root) return;

    var slides = Array.prototype.slice.call(root.querySelectorAll('[data-neon-slide]'));
    var navItems = Array.prototype.slice.call(root.querySelectorAll('[data-neon-nav]'));
    var progress = root.querySelector('[data-neon-progress]');
    var index = 0;
    var total = slides.length;
    var busy = false;
    var timer = null;
    var AUTO_MS = 7500;
    var TRANSITION_MS = 780;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function clearAnim(slide) {
        slide.classList.remove('is-entering', 'is-leaving');
    }

    function updateNav(active) {
        navItems.forEach(function (item, i) {
            var on = i === active;
            item.classList.toggle('is-active', on);
            item.setAttribute('aria-current', on ? 'true' : 'false');
        });
    }

    function restartProgress() {
        if (!progress) return;
        progress.classList.remove('is-running');
        progress.style.animationDuration = '';
        void progress.offsetWidth;
        if (reduceMotion) {
            progress.style.width = '100%';
            return;
        }
        progress.style.width = '';
        progress.style.animationDuration = AUTO_MS + 'ms';
        progress.classList.add('is-running');
    }

    function goTo(next) {
        if (!total || busy) return;
        var target = ((next % total) + total) % total;
        if (target === index) {
            restartProgress();
            return;
        }

        var outgoing = slides[index];
        var incoming = slides[target];

        if (reduceMotion) {
            outgoing.hidden = true;
            outgoing.classList.remove('is-active');
            outgoing.setAttribute('aria-hidden', 'true');
            clearAnim(outgoing);

            incoming.hidden = false;
            incoming.classList.add('is-active');
            incoming.setAttribute('aria-hidden', 'false');
            clearAnim(incoming);

            index = target;
            updateNav(index);
            restartAuto();
            return;
        }

        busy = true;
        clearAnim(outgoing);
        clearAnim(incoming);

        outgoing.classList.remove('is-active');
        outgoing.classList.add('is-leaving');
        outgoing.setAttribute('aria-hidden', 'true');

        incoming.hidden = false;
        incoming.setAttribute('aria-hidden', 'false');
        void incoming.offsetWidth;
        incoming.classList.add('is-active', 'is-entering');

        index = target;
        updateNav(index);

        window.setTimeout(function () {
            outgoing.hidden = true;
            clearAnim(outgoing);
            incoming.classList.remove('is-entering');
            busy = false;
            restartAuto();
        }, TRANSITION_MS);
    }

    function next() {
        goTo(index + 1);
    }

    function restartAuto() {
        if (timer) window.clearInterval(timer);
        restartProgress();
        if (reduceMotion || busy) return;
        timer = window.setInterval(next, AUTO_MS);
    }

    navItems.forEach(function (item) {
        item.addEventListener('click', function () {
            var i = Number(item.getAttribute('data-neon-nav'));
            if (Number.isNaN(i)) return;
            goTo(i);
        });
    });

    root.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
            e.preventDefault();
            goTo(index + 1);
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
            e.preventDefault();
            goTo(index - 1);
        }
    });

    root.setAttribute('tabindex', '0');

    root.addEventListener('mouseenter', function () {
        if (timer) window.clearInterval(timer);
        if (progress) progress.classList.remove('is-running');
    });
    root.addEventListener('mouseleave', restartAuto);

    // Initial enter
    var first = slides[0];
    if (first && !reduceMotion) {
        first.hidden = false;
        first.classList.add('is-active', 'is-entering');
        window.setTimeout(function () {
            first.classList.remove('is-entering');
        }, TRANSITION_MS);
    }

    updateNav(0);
    restartAuto();
})();
