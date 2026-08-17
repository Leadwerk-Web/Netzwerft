(() => {
    "use strict";
    const config = window.leadwerkT2med || {};
    const values = { situation: "", location: "", start: "", scope: "", source: "" };

    const painRoot = document.querySelector("[data-pain-focus]");
    if (painRoot) {
        const tabs = Array.from(painRoot.querySelectorAll("[data-pain-focus-tab]"));
        const panels = Array.from(painRoot.querySelectorAll("[data-pain-focus-panel]"));
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
    }

    const setRadio = (key, value) => {
        const id = config.fieldMap?.[key];
        if (id === undefined || !config.formId || !value) return;
        const selector = `#wpforms-${config.formId}-field_${id}-container input`;
        document.querySelectorAll(selector).forEach((input) => {
            const label = input.closest("li, label")?.textContent?.trim() || input.value;
            if (input.value === value || label.includes(value)) {
                input.checked = true;
                input.dispatchEvent(new Event("change", { bubbles: true }));
            }
        });
    };

    const setText = (key, value) => {
        const id = config.fieldMap?.[key];
        if (id === undefined || !config.formId) return;
        const input = document.querySelector(`#wpforms-${config.formId}-field_${id}`);
        if (!input) return;
        input.value = value || "";
        input.dispatchEvent(new Event("input", { bubbles: true }));
        input.dispatchEvent(new Event("change", { bubbles: true }));
    };

    const transfer = (source) => {
        values.source = source || "t2med-landingpage";
        setRadio("situation", values.situation);
        setText("location", values.location);
        setRadio("start", values.start);
        setRadio("scope", values.scope);
        setText("source", values.source);
    };

    document.querySelectorAll("[data-prequal-group]").forEach((group) => {
        group.querySelectorAll(".funnel-chip").forEach((chip) => {
            chip.addEventListener("click", () => {
                group.querySelectorAll(".funnel-chip").forEach((item) => item.setAttribute("aria-pressed", "false"));
                chip.setAttribute("aria-pressed", "true");
                values[group.dataset.prequalGroup] = chip.dataset.value || chip.textContent.trim();
            });
        });
    });

    const location = document.querySelector("[data-prequal-location]");
    location?.addEventListener("input", () => {
        values.location = location.value.trim();
    });

    document.querySelectorAll("[data-conversion='appointment_start']").forEach((button) => {
        button.addEventListener("click", () => {
            window.setTimeout(() => transfer(button.dataset.cta || "t2med-landingpage"), 0);
        });
    });

    if (window.jQuery && config.formId) {
        window.jQuery(`#wpforms-form-${config.formId}`).on("wpformsAjaxSubmitSuccess", () => {
            if (config.thankYouUrl) window.location.assign(config.thankYouUrl);
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        const consentId = config.fieldMap?.consent;
        if (consentId === undefined || !config.formId || !config.privacyUrl) return;
        const container = document.querySelector(`#wpforms-${config.formId}-field_${consentId}-container`);
        const label = container?.querySelector("li label, .wpforms-field-label-inline");
        if (!label) return;

        const prepareLink = (link) => {
            link.href = config.privacyUrl;
            link.target = "_blank";
            link.rel = "noopener noreferrer";
            link.classList.add("leadwerk-privacy-link");
            link.setAttribute("aria-label", "Datenschutzerklärung (öffnet in neuem Tab)");
            return link;
        };

        const existingLink = Array.from(label.querySelectorAll("a")).find((link) =>
            link.textContent.includes("Datenschutzerklärung")
        );
        if (existingLink) {
            prepareLink(existingLink);
            return;
        }

        const walker = document.createTreeWalker(label, NodeFilter.SHOW_TEXT);
        let textNode = walker.nextNode();
        while (textNode) {
            const text = textNode.nodeValue || "";
            const index = text.indexOf("Datenschutzerklärung");
            if (index !== -1) {
                const fragment = document.createDocumentFragment();
                fragment.append(document.createTextNode(text.slice(0, index)));
                const link = prepareLink(document.createElement("a"));
                link.textContent = "Datenschutzerklärung";
                fragment.append(link, document.createTextNode(text.slice(index + "Datenschutzerklärung".length)));
                textNode.replaceWith(fragment);
                return;
            }
            textNode = walker.nextNode();
        }

        const link = prepareLink(document.createElement("a"));
        link.textContent = "Datenschutzerklärung";
        label.append(document.createTextNode(" "), link);
    });
})();
