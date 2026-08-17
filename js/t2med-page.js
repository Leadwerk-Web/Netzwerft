(() => {
    "use strict";

    const form = document.getElementById("lp-qualify-form");
    form?.querySelectorAll("[data-funnel-group]").forEach((group) => {
        group.querySelectorAll(".funnel-chip").forEach((chip) => {
            chip.addEventListener("click", () => {
                group.querySelectorAll(".funnel-chip").forEach((item) => item.setAttribute("aria-pressed", "false"));
                chip.setAttribute("aria-pressed", "true");
            });
        });
    });

    document.querySelectorAll("[data-pain-focus]").forEach((root) => {
        const tabs = Array.from(root.querySelectorAll("[data-pain-focus-tab]"));
        const panels = Array.from(root.querySelectorAll("[data-pain-focus-panel]"));
        if (!tabs.length) return;
        const activate = (index) => {
            tabs.forEach((tab, itemIndex) => {
                const active = itemIndex === index;
                tab.classList.toggle("is-active", active);
                tab.setAttribute("aria-selected", String(active));
                tab.tabIndex = active ? 0 : -1;
            });
            panels.forEach((panel, itemIndex) => {
                const active = itemIndex === index;
                panel.classList.toggle("is-active", active);
                panel.toggleAttribute("hidden", !active);
            });
        };
        tabs.forEach((tab, index) => {
            tab.addEventListener("click", () => activate(index));
            tab.addEventListener("keydown", (event) => {
                let next = index;
                if (event.key === "ArrowDown" || event.key === "ArrowRight") next = (index + 1) % tabs.length;
                else if (event.key === "ArrowUp" || event.key === "ArrowLeft") next = (index - 1 + tabs.length) % tabs.length;
                else if (event.key === "Home") next = 0;
                else if (event.key === "End") next = tabs.length - 1;
                else return;
                event.preventDefault();
                activate(next);
                tabs[next].focus();
            });
        });
    });
})();

