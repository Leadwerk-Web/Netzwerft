/* =========================================================
   die netzwerft — Sanftes Scroll-Erlebnis (Lenis)
   ========================================================= */

(() => {
    "use strict";

    const prefersReduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const scrollListeners = new Set();

    const emitScroll = () => {
        scrollListeners.forEach((fn) => {
            fn(window.scrollY);
        });
    };

    window.siteScroll = {
        on(fn) {
            scrollListeners.add(fn);
            return () => scrollListeners.delete(fn);
        },
        refresh() {
            emitScroll();
        },
    };

    if (prefersReduced || typeof Lenis === "undefined") {
        window.addEventListener("scroll", emitScroll, { passive: true });
        return;
    }

    document.documentElement.classList.add("lenis", "lenis-smooth");

    const lenis = new Lenis({
        lerp: 0.068,
        duration: 1.45,
        smoothWheel: true,
        wheelMultiplier: 0.88,
        touchMultiplier: 1.35,
        easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
    });

    window.siteScroll.lenis = lenis;

    lenis.on("scroll", emitScroll);

    const raf = (time) => {
        lenis.raf(time);
        requestAnimationFrame(raf);
    };
    requestAnimationFrame(raf);

    document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
        anchor.addEventListener("click", (event) => {
            const href = anchor.getAttribute("href");
            if (!href || href === "#" || href === "#top") return;

            const target = document.querySelector(href);
            if (!target) return;

            event.preventDefault();
            lenis.scrollTo(target, { offset: 0 });
        });
    });

    window.addEventListener("load", () => lenis.resize());
})();
