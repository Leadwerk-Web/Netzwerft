/* =========================================================
   Event Speyer — Anmeldeformular
   Zuerst die Teilnahmeart, darunter immer das Anmeldeformular.
   Bei "online" erscheint zusätzlich der Teams-Link.
   Die Anmeldung prüft Pflichtfelder und öffnet das E-Mail-Programm
   mit vorausgefüllter Nachricht (wie Bewerbungsformular Karriere)
   ========================================================= */

(() => {
    "use strict";

    const form = document.getElementById("ev-register-form");
    const status = document.getElementById("ev-register-status");
    if (!form) return;

    const RECIPIENT = "vertrieb@dienetzwerft.de";
    const EVENT_LABEL = "Event Speyer, 04.11.2026";

    const choiceGroup = form.querySelector(".ev-choice-group");
    const radios = Array.from(form.querySelectorAll('input[name="teilnahme"]'));
    const steps = Array.from(form.querySelectorAll("[data-ev-step]"));

    // Bereiche mit data-ev-step (Teams-Link) nur bei passender Teilnahmeart zeigen
    const showStep = (choice) => {
        steps.forEach((step) => {
            step.hidden = step.dataset.evStep !== choice;
        });
        if (status) status.hidden = true;
    };

    const checked = radios.find((radio) => radio.checked);
    showStep(checked ? checked.dataset.evChoice : null);

    const showStatus = (text, ok) => {
        if (!status) return;
        status.hidden = false;
        status.className = "ev-form__status " + (ok ? "ev-form__status--ok" : "ev-form__status--err");
        status.textContent = text;
    };

    const markFields = () => {
        form.querySelectorAll(".funnel-input").forEach((input) => {
            input.setAttribute("aria-invalid", String(!input.checkValidity()));
        });
        const chosen = radios.some((radio) => radio.checked);
        choiceGroup?.setAttribute("aria-invalid", String(!chosen));
    };

    // Markierung zurücksetzen, sobald ein Feld korrigiert wird
    form.addEventListener("input", (event) => {
        const input = event.target.closest(".funnel-input");
        if (input && input.checkValidity()) input.removeAttribute("aria-invalid");
    });
    radios.forEach((radio) => {
        radio.addEventListener("change", () => {
            choiceGroup?.removeAttribute("aria-invalid");
            showStep(radio.dataset.evChoice);
        });
    });

    form.addEventListener("submit", (event) => {
        event.preventDefault();
        markFields();

        if (!form.checkValidity()) {
            showStatus("Bitte füllen Sie alle Pflichtfelder aus und wählen Sie, wie Sie teilnehmen.", false);
            form.reportValidity();
            return;
        }

        const valueOf = (name) => {
            const field = form.elements.namedItem(name);
            if (!field) return "";
            return String(field.value || "").trim();
        };

        const lines = [
            "Anmeldung zum " + EVENT_LABEL,
            "Digitale Praxisorganisation - effizient, persönlich, regional",
            "",
            "Teilnahme: " + valueOf("teilnahme"),
            "Name: " + valueOf("name"),
            "Praxis: " + (valueOf("praxis") || "nicht angegeben"),
            "Position: " + (valueOf("position") || "nicht angegeben"),
            "E-Mail: " + valueOf("email"),
            "Telefon: " + (valueOf("telefon") || "nicht angegeben"),
            "",
            "Einwilligung Datenschutz: ja"
        ].join("\n");

        const subject = "Anmeldung " + EVENT_LABEL + ": " + (valueOf("praxis") || valueOf("name"));
        window.location.href = "mailto:" + RECIPIENT +
            "?subject=" + encodeURIComponent(subject) +
            "&body=" + encodeURIComponent(lines);

        showStatus(
            "Ihr E-Mail-Programm sollte sich geöffnet haben. Bitte senden Sie die Nachricht dort ab. " +
            "Falls nicht, schreiben Sie uns direkt an " + RECIPIENT + ".",
            true
        );
    });
})();
