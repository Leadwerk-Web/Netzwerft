/**
 * Hero V2 Leistungs-Slider — gestaffelte Folien-Übergänge
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-hero-slider]');
    if (!root) return;

    var slides = Array.prototype.slice.call(root.querySelectorAll('[data-hero-slide]'));
    var dots = Array.prototype.slice.call(root.querySelectorAll('[data-hero-dot]'));
    var counter = root.querySelector('[data-hero-counter]');
    var prevBtn = root.querySelector('[data-hero-prev]');
    var nextBtn = root.querySelector('[data-hero-next]');
    var index = 0;
    var total = slides.length;
    var timer = null;
    var busy = false;
    var AUTO_MS = 9000;
    var TRANSITION_MS = 820;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function updateChrome(activeIndex) {
        dots.forEach(function (dot, i) {
            var active = i === activeIndex;
            dot.classList.toggle('is-active', active);
            dot.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        if (counter) {
            counter.textContent = pad(activeIndex + 1) + ' / ' + pad(total);
        }

        slides.forEach(function (slide, i) {
            var heading = slide.querySelector('.hero-slide__title');
            if (!heading) return;
            if (i === activeIndex) {
                heading.id = 'hero-slide-title';
            } else {
                heading.removeAttribute('id');
            }
        });
    }

    function clearAnimClasses(slide) {
        slide.classList.remove('is-entering', 'is-leaving', 'dir-next', 'dir-prev');
    }

    function goTo(next, direction) {
        if (!total || busy) return;

        var target = ((next % total) + total) % total;
        if (target === index) return;

        var dir = direction || (target > index || (index === total - 1 && target === 0) ? 1 : -1);
        // Wrap: 0 -> last is prev
        if (index === 0 && target === total - 1 && direction == null) dir = -1;
        if (index === total - 1 && target === 0 && direction == null) dir = 1;

        var outgoing = slides[index];
        var incoming = slides[target];
        var dirClass = dir === 1 ? 'dir-next' : 'dir-prev';

        if (reduceMotion) {
            outgoing.hidden = true;
            outgoing.classList.remove('is-active');
            outgoing.setAttribute('aria-hidden', 'true');
            clearAnimClasses(outgoing);

            incoming.hidden = false;
            incoming.classList.add('is-active');
            incoming.setAttribute('aria-hidden', 'false');
            clearAnimClasses(incoming);

            index = target;
            updateChrome(index);
            restartAuto();
            return;
        }

        busy = true;
        root.classList.add('is-transitioning');

        clearAnimClasses(outgoing);
        clearAnimClasses(incoming);

        outgoing.classList.remove('is-active');
        outgoing.classList.add('is-leaving', dirClass);
        outgoing.setAttribute('aria-hidden', 'true');

        incoming.hidden = false;
        incoming.setAttribute('aria-hidden', 'false');
        // Force reflow so enter animation restarts
        void incoming.offsetWidth;
        incoming.classList.add('is-active', 'is-entering', dirClass);

        index = target;
        updateChrome(index);

        window.setTimeout(function () {
            outgoing.hidden = true;
            clearAnimClasses(outgoing);
            incoming.classList.remove('is-entering', 'dir-next', 'dir-prev');
            root.classList.remove('is-transitioning');
            busy = false;
            restartAuto();
        }, TRANSITION_MS);
    }

    function next() {
        goTo(index + 1, 1);
    }

    function prev() {
        goTo(index - 1, -1);
    }

    function restartAuto() {
        if (timer) window.clearInterval(timer);
        if (reduceMotion || busy) return;
        timer = window.setInterval(next, AUTO_MS);
    }

    if (prevBtn) prevBtn.addEventListener('click', prev);
    if (nextBtn) nextBtn.addEventListener('click', next);

    dots.forEach(function (dot) {
        dot.addEventListener('click', function () {
            var i = Number(dot.getAttribute('data-hero-dot'));
            if (Number.isNaN(i) || i === index) return;
            goTo(i, i > index ? 1 : -1);
        });
    });

    root.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowLeft') {
            e.preventDefault();
            prev();
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            next();
        }
    });

    root.setAttribute('tabindex', '0');

    root.addEventListener('mouseenter', function () {
        if (timer) window.clearInterval(timer);
    });
    root.addEventListener('mouseleave', restartAuto);
    root.addEventListener('focusin', function () {
        if (timer) window.clearInterval(timer);
    });
    root.addEventListener('focusout', function (e) {
        if (!root.contains(e.relatedTarget)) restartAuto();
    });

    // Initial enter
    var first = slides[0];
    if (first && !reduceMotion) {
        first.hidden = false;
        first.classList.add('is-active', 'is-entering', 'dir-next');
        void first.offsetWidth;
        window.setTimeout(function () {
            first.classList.remove('is-entering', 'dir-next', 'dir-prev');
        }, TRANSITION_MS);
    }

    updateChrome(0);
    restartAuto();
})();
