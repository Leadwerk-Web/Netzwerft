(function () {
    "use strict";
    const config = window.leadwerkImporter || {};
    const status = document.querySelector("[data-import-status]");
    const log = document.querySelector("[data-import-log]");
    let running = false;

    function render(state) {
        if (!state || !Object.keys(state).length) {
            status.textContent = "Noch nicht gestartet.";
            log.textContent = "";
            return;
        }
        status.textContent = `${state.status || "unbekannt"} · Schritt ${state.stage_index || 0}/10${state.dry_run ? " · Dry-run" : ""}`;
        const lines = [...(state.logs || [])];
        (state.warnings || []).forEach((item) => lines.push(`WARNUNG: ${item}`));
        (state.conflicts || []).forEach((item) => lines.push(`KONFLIKT (erhalten): ${item}`));
        if (state.error) lines.push(`FEHLER: ${state.error}`);
        log.textContent = lines.join("\n");
    }

    async function request(action, payload = {}) {
        const body = new URLSearchParams({ action, nonce: config.nonce, ...payload });
        const response = await fetch(config.ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
            body,
        });
        const json = await response.json();
        if (!json.success) throw new Error(json.data?.message || "Import-Anfrage fehlgeschlagen.");
        return json.data;
    }

    async function drive(state) {
        if (running) return;
        running = true;
        try {
            let current = state;
            while (current?.status === "running") {
                current = await request("leadwerk_import_step");
                render(current);
            }
        } catch (error) {
            status.textContent = error.message;
        } finally {
            running = false;
        }
    }

    document.addEventListener("click", async (event) => {
        const button = event.target.closest("[data-import-action]");
        if (!button || running) return;
        const action = button.dataset.importAction;
        try {
            if (action === "reset") {
                await request("leadwerk_import_reset");
                render({});
                return;
            }
            if (action === "rollback") {
                render(await request("leadwerk_import_rollback"));
                return;
            }
            if (action === "resume") {
                await drive(config.state);
                return;
            }
            const force = action === "force";
            const state = await request("leadwerk_import_start", {
                dry_run: action === "dry" ? "1" : "",
                force: force ? "1" : "",
                confirm_force: force ? document.querySelector("[data-force-confirm]").value : "",
            });
            render(state);
            await drive(state);
        } catch (error) {
            status.textContent = error.message;
        }
    });

    render(config.state);
})();

