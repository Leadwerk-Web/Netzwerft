/* =========================================================
   die netzwerft — UI-Interaktionen
   Header-Scroll · Mobile-Menü · FAQ · Sticky-CTA ·
   Erstgespräch-Funnel-Modal · Scroll-Reveals · T2med-Scroll-Effekt · Trust-Tabs
   (Scroll-Flow: js/scroll-smooth.js)
   ========================================================= */

(() => {
    "use strict";

    const prefersReduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* ---------- Header: Scroll-Zustand ---------- */
    const header = document.querySelector(".site-header");
    const setHeaderState = () => {
        if (!header) return;
        header.classList.toggle("is-scrolled", window.scrollY > 40);
    };
    setHeaderState();
    window.addEventListener("scroll", setHeaderState, { passive: true });

    /* ---------- Mobile-Menü ---------- */
    const nav = document.getElementById("site-nav");
    const toggle = document.querySelector(".nav-toggle");
    const backdrop = document.querySelector(".nav-backdrop");

    const openMenu = () => {
        if (!nav || !toggle) return;
        nav.classList.add("is-open");
        toggle.setAttribute("aria-expanded", "true");
        toggle.setAttribute("aria-label", "Men\u00fc schlie\u00dfen");
        backdrop && backdrop.classList.add("is-visible");
        document.body.classList.add("nav-open");
        const firstLink = nav.querySelector("a, button");
        firstLink && firstLink.focus({ preventScroll: true });
    };

    const closeMenu = ({ returnFocus = false } = {}) => {
        if (!nav || !toggle) return;
        nav.classList.remove("is-open");
        toggle.setAttribute("aria-expanded", "false");
        toggle.setAttribute("aria-label", "Men\u00fc \u00f6ffnen");
        backdrop && backdrop.classList.remove("is-visible");
        document.body.classList.remove("nav-open");
        if (returnFocus) toggle.focus({ preventScroll: true });
    };

    if (toggle) {
        toggle.addEventListener("click", () => {
            const isOpen = toggle.getAttribute("aria-expanded") === "true";
            isOpen ? closeMenu({ returnFocus: true }) : openMenu();
        });
    }
    backdrop && backdrop.addEventListener("click", () => closeMenu());

    // Menü-Links und CTA schließen das Menü
    nav &&
        nav.querySelectorAll("a, button").forEach((item) => {
            item.addEventListener("click", () => closeMenu());
        });

    // Desktop-Untermenü: Escape schließt Fokus im Nav-Item
    document.querySelectorAll(".site-nav__item--has-sub").forEach((item) => {
        item.addEventListener("keydown", (e) => {
            if (e.key !== "Escape") return;
            const trigger = item.querySelector(":scope > .site-nav__link");
            if (trigger) trigger.focus({ preventScroll: true });
        });
    });

    // Escape schließt Menü; bei Desktop-Breite offen halten
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && nav && nav.classList.contains("is-open")) {
            closeMenu({ returnFocus: true });
        }
    });

    // Beim Wechsel auf Desktop Menü zurücksetzen
    const desktopQuery = window.matchMedia("(min-width: 1025px)");
    const handleDesktop = (e) => {
        if (e.matches) closeMenu();
    };
    desktopQuery.addEventListener
        ? desktopQuery.addEventListener("change", handleDesktop)
        : desktopQuery.addListener(handleDesktop);

    /* ---------- FAQ-Accordion ---------- */
    const faqTriggers = document.querySelectorAll(".faq__trigger");
    faqTriggers.forEach((trigger) => {
        const panel = document.getElementById(trigger.getAttribute("aria-controls"));
        trigger.addEventListener("click", () => {
            const expanded = trigger.getAttribute("aria-expanded") === "true";
            // Andere schließen (klassisches Accordion-Verhalten)
            faqTriggers.forEach((other) => {
                if (other !== trigger) {
                    other.setAttribute("aria-expanded", "false");
                    const p = document.getElementById(other.getAttribute("aria-controls"));
                    if (p) p.style.maxHeight = null;
                }
            });
            trigger.setAttribute("aria-expanded", String(!expanded));
            if (panel) {
                panel.style.maxHeight = expanded ? null : panel.scrollHeight + "px";
            }
        });
    });

    /* ---------- Trust-Filter-Tabs ---------- */
    /* Referenzlogos: js/references.js */

    /* ---------- Leistungen: Kompetenzfeld-Tabs ---------- */
    const serviceTabs = document.querySelectorAll("[data-service-tab]");
    const servicePanels = document.querySelectorAll("[data-service-panel]");
    const capabilitySlider = document.querySelector(".capability-slider");

    if (serviceTabs.length && servicePanels.length) {
        const padTab = (n) => String(n).padStart(2, "0");

        const activateServiceTab = (index) => {
            const total = serviceTabs.length;
            const id = String(((index % total) + total) % total);

            serviceTabs.forEach((tab) => {
                const active = tab.dataset.serviceTab === id;
                tab.setAttribute("aria-selected", active ? "true" : "false");
                tab.tabIndex = active ? 0 : -1;
            });

            servicePanels.forEach((panel) => {
                const active = panel.dataset.servicePanel === id;
                panel.hidden = !active;
            });

            capabilitySlider
                ?.querySelectorAll("[data-capability-counter]")
                .forEach((counter) => {
                    counter.textContent = `${padTab(Number(id) + 1)} / ${padTab(total)}`;
                });
        };

        serviceTabs.forEach((tab, index) => {
            tab.addEventListener("click", () => activateServiceTab(index));

            tab.addEventListener("keydown", (e) => {
                const tabs = Array.from(serviceTabs);
                const current = tabs.indexOf(tab);
                let next = current;

                if (e.key === "ArrowRight") next = (current + 1) % tabs.length;
                else if (e.key === "ArrowLeft") next = (current - 1 + tabs.length) % tabs.length;
                else if (e.key === "Home") next = 0;
                else if (e.key === "End") next = tabs.length - 1;
                else return;

                e.preventDefault();
                tabs[next].focus();
                activateServiceTab(next);
            });
        });

        capabilitySlider?.querySelectorAll("[data-capability-prev]").forEach((btn) => {
            btn.addEventListener("click", () => {
                const active = Array.from(serviceTabs).findIndex(
                    (tab) => tab.getAttribute("aria-selected") === "true"
                );
                activateServiceTab(active - 1);
            });
        });

        capabilitySlider?.querySelectorAll("[data-capability-next]").forEach((btn) => {
            btn.addEventListener("click", () => {
                const active = Array.from(serviceTabs).findIndex(
                    (tab) => tab.getAttribute("aria-selected") === "true"
                );
                activateServiceTab(active + 1);
            });
        });
    }

    /* ---------- Sticky-CTA (Mobile-Bar) & Back-to-Top ---------- */
    const stickyEls = document.querySelectorAll("[data-sticky-cta]");
    const backToTop = document.querySelector("[data-back-to-top]");
    const hero = document.getElementById("hero");

    const scrollToTop = () => {
        if (window.siteScroll?.lenis) {
            window.siteScroll.lenis.scrollTo(0, { immediate: prefersReduced });
            return;
        }

        window.scrollTo({ top: 0, behavior: prefersReduced ? "auto" : "smooth" });
    };

    if (backToTop) {
        backToTop.addEventListener("click", scrollToTop);
    }

    if ((stickyEls.length || backToTop) && "IntersectionObserver" in window) {
        // Sichtbar, sobald der Hero aus dem Blickfeld gescrollt ist
        const heroObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    const show = !entry.isIntersecting;
                    stickyEls.forEach((el) => el.classList.toggle("is-visible", show));
                    if (backToTop) {
                        backToTop.classList.toggle("is-visible", show);
                        backToTop.toggleAttribute("hidden", !show);
                    }
                });
            },
            { threshold: 0, rootMargin: "-120px 0px 0px 0px" }
        );
        if (hero) heroObserver.observe(hero);
    }

    /* ---------- Scroll-Reveals ---------- */
    const animated = document.querySelectorAll("[data-animate]");

    const applyRevealStagger = () => {
        animated.forEach((el) => {
            const parent = el.parentElement;
            if (!parent) return;

            const group = [...parent.children].filter((node) => node.matches("[data-animate]"));
            const index = group.indexOf(el);
            if (index >= 0) {
                el.style.setProperty("--reveal-delay", `${0.05 + index * 0.12}s`);
            }
        });
    };

    applyRevealStagger();

    if (animated.length && "IntersectionObserver" in window && !prefersReduced) {
        const revealObserver = new IntersectionObserver(
            (entries, obs) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add("is-in");
                        obs.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.08, rootMargin: "0px 0px -5% 0px" }
        );
        animated.forEach((el) => revealObserver.observe(el));
    } else {
        animated.forEach((el) => el.classList.add("is-in"));
    }

    /* ---------- T2med-Bild: Überhang + Scroll-Reveal ---------- */
    const t2medDesktopQuery = window.matchMedia("(min-width: 901px)");

    const initT2medVisualScroll = (section, { overhangEl = null } = {}) => {
        if (!section) return;
        const visual = section.querySelector(":scope > .t2med__visual");
        if (!visual) return;

        let t2medScrollBound = false;
        let unsubscribeScroll = null;

        const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

        const updateT2medScrollIn = () => {
            if (prefersReduced) {
                visual.style.setProperty("--t2med-scroll-x", "0px");
                return;
            }

            const rect = section.getBoundingClientRect();
            const viewH = window.innerHeight;
            const start = viewH * 1.02;
            const end = viewH * 0.12;
            const progress = clamp((start - rect.top) / (start - end), 0, 1);
            const eased = 1 - Math.pow(1 - progress, 1.35);
            const maxShift = visual.offsetWidth + (t2medDesktopQuery.matches ? 80 : 40);
            const offsetX = maxShift * (1 - eased);

            visual.style.setProperty("--t2med-scroll-x", `${offsetX.toFixed(2)}px`);
        };

        const updateT2medOverhang = () => {
            if (!overhangEl) return;

            if (!t2medDesktopQuery.matches) {
                overhangEl.style.removeProperty("--t2med-overhang");
                return;
            }

            const sectionRect = section.getBoundingClientRect();
            const visualRect = visual.getBoundingClientRect();
            const overhang = Math.max(0, Math.round(sectionRect.top - visualRect.top));

            overhangEl.style.setProperty("--t2med-overhang", `${overhang}px`);
        };

        const updateT2medLayout = () => {
            updateT2medScrollIn();
            updateT2medOverhang();
        };

        const bindT2medScroll = () => {
            if (t2medScrollBound) return;
            t2medScrollBound = true;

            if (window.siteScroll) {
                unsubscribeScroll = window.siteScroll.on(updateT2medLayout);
            } else {
                window.addEventListener("scroll", updateT2medLayout, { passive: true });
            }

            window.addEventListener("resize", updateT2medLayout, { passive: true });
        };

        const unbindT2medScroll = () => {
            if (!t2medScrollBound) return;
            t2medScrollBound = false;

            unsubscribeScroll?.();
            unsubscribeScroll = null;
            window.removeEventListener("scroll", updateT2medLayout);
            window.removeEventListener("resize", updateT2medLayout);
        };

        if ("IntersectionObserver" in window) {
            const t2medObserver = new IntersectionObserver(
                (entries) => {
                    if (entries.some((entry) => entry.isIntersecting)) {
                        bindT2medScroll();
                        updateT2medLayout();
                    } else {
                        unbindT2medScroll();
                        updateT2medLayout();
                    }
                },
                { rootMargin: "25% 0px 25% 0px", threshold: 0 }
            );
            t2medObserver.observe(section);
        } else {
            bindT2medScroll();
            updateT2medLayout();
        }

        window.addEventListener("load", updateT2medLayout);

        if ("ResizeObserver" in window) {
            new ResizeObserver(updateT2medLayout).observe(section);
        }

        t2medDesktopQuery.addEventListener
            ? t2medDesktopQuery.addEventListener("change", updateT2medLayout)
            : t2medDesktopQuery.addListener(updateT2medLayout);

        updateT2medLayout();
    };

    initT2medVisualScroll(document.querySelector(".t2med"), {
        overhangEl: document.getElementById("leistungen"),
    });

    /* ---------- Video-Embed: Lightbox (datenschutzfreundlich) ---------- */
    const videoLightbox = (() => {
        let root = document.getElementById("video-lightbox");
        let stage = null;
        let closeBtn = null;
        let lastFocused = null;

        const ensure = () => {
            if (root) {
                stage = root.querySelector(".video-lightbox__stage");
                closeBtn = root.querySelector(".video-lightbox__close");
                return root;
            }

            root = document.createElement("div");
            root.id = "video-lightbox";
            root.className = "video-lightbox";
            root.setAttribute("role", "dialog");
            root.setAttribute("aria-modal", "true");
            root.setAttribute("aria-label", "Video");
            root.setAttribute("aria-hidden", "true");
            root.innerHTML = `
                <div class="video-lightbox__backdrop" data-video-lightbox-close></div>
                <div class="video-lightbox__dialog">
                    <button type="button" class="video-lightbox__close" data-video-lightbox-close aria-label="Video schlie\u00dfen">&times;</button>
                    <div class="video-lightbox__stage"></div>
                </div>
            `;
            document.body.appendChild(root);
            stage = root.querySelector(".video-lightbox__stage");
            closeBtn = root.querySelector(".video-lightbox__close");

            root.querySelectorAll("[data-video-lightbox-close]").forEach((el) => {
                el.addEventListener("click", close);
            });

            root.addEventListener("keydown", (e) => {
                if (e.key === "Escape") {
                    e.preventDefault();
                    close();
                }
            });

            return root;
        };

        const open = ({ id, title }) => {
            ensure();
            lastFocused = document.activeElement;
            stage.innerHTML = "";

            const iframe = document.createElement("iframe");
            iframe.src = `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&modestbranding=1`;
            iframe.title = title || "Video";
            iframe.allow =
                "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share";
            iframe.allowFullscreen = true;
            iframe.setAttribute("loading", "lazy");
            stage.appendChild(iframe);

            root.classList.add("is-open");
            root.setAttribute("aria-hidden", "false");
            document.body.classList.add("video-lightbox-open");
            closeBtn && closeBtn.focus({ preventScroll: true });
        };

        const close = () => {
            if (!root || !root.classList.contains("is-open")) return;
            root.classList.remove("is-open");
            root.setAttribute("aria-hidden", "true");
            document.body.classList.remove("video-lightbox-open");
            if (stage) stage.innerHTML = "";
            if (lastFocused && lastFocused.focus) {
                lastFocused.focus({ preventScroll: true });
            }
        };

        return { open, close };
    })();

    document.querySelectorAll(".video-embed").forEach((embed) => {
        const trigger = embed.querySelector(".video-embed__trigger");
        const id = embed.dataset.videoId;
        if (!trigger || !id) return;

        trigger.addEventListener("click", () => {
            videoLightbox.open({
                id,
                title: embed.dataset.videoTitle || "Video",
            });
        });
    });

    /* ---------- Erstgespräch-Funnel-Modal ---------- */
    // TODO: Anbindung an ein echtes Terminbuchungs-/CRM-Tool. Aktuell reine UI-Vorbereitung.
    const modal = document.getElementById("funnel-modal");
    let lastFocused = null;

    const getFocusable = () =>
        modal
            ? modal.querySelectorAll(
                  'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'
              )
            : [];

    const openModal = () => {
        if (!modal) return;
        lastFocused = document.activeElement;
        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        document.body.classList.add("nav-open");
        const focusable = getFocusable();
        focusable.length && focusable[0].focus();
    };

    const closeModal = () => {
        if (!modal) return;
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        document.body.classList.remove("nav-open");
        lastFocused && lastFocused.focus && lastFocused.focus();
    };

    // Öffner: alle Elemente mit data-conversion="appointment_start"
    document.querySelectorAll('[data-conversion="appointment_start"]').forEach((btn) => {
        btn.addEventListener("click", (e) => {
            // Falls es ein Link ist, Modal statt Navigation öffnen
            if (btn.tagName === "A") e.preventDefault();
            openModal();
        });
    });

    if (modal) {
        modal.querySelectorAll("[data-modal-close]").forEach((el) =>
            el.addEventListener("click", closeModal)
        );
        // Fokus-Falle + Escape
        modal.addEventListener("keydown", (e) => {
            if (e.key === "Escape") {
                closeModal();
                return;
            }
            if (e.key === "Tab") {
                const focusable = Array.from(getFocusable());
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });
        // Chip-Auswahl (Single-Select pro Gruppe)
        modal.querySelectorAll("[data-funnel-group]").forEach((group) => {
            group.querySelectorAll(".funnel-chip").forEach((chip) => {
                chip.addEventListener("click", () => {
                    group.querySelectorAll(".funnel-chip").forEach((c) =>
                        c.setAttribute("aria-pressed", "false")
                    );
                    chip.setAttribute("aria-pressed", "true");
                });
            });
        });
    }

    /* ---------- Footer-Jahr ---------- */
    const yearEl = document.getElementById("footer-year");
    if (yearEl) yearEl.textContent = String(new Date().getFullYear());
})();
