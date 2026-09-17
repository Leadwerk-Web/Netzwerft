(function () {
    "use strict";

    var ICON_SELECTOR = '[data-qa="input-translation-icon"],.dl-icon-circle.dl-icon[data-qa="input-translation-icon"],.dl-input-placeholder [data-tooltip*="Ctrl+Shift+Y"],.dl-input-placeholder [data-tooltip*="Translate"],.dl-input-placeholder [data-tooltip*="bersetzen"],.dl-input-placeholder [data-tooltip*="Übersetzen"]';
    var ICON_TRIGGER_SELECTOR = '[data-qa="input-translation-icon"],.dl-icon-circle.dl-icon[data-qa="input-translation-icon"]';
    var ICON_TOOLTIP_SELECTOR = '[data-tooltip*="Ctrl+Shift+Y"],[data-tooltip*="Translate"],[data-tooltip*="bersetzen"],[data-tooltip*="Übersetzen"]';
    var OVERLAY_SELECTOR = '.dl-input-positioner,.dl-input-placeholder,.dl-input-translation-container';
    var LOADING_SELECTOR = '.dl-loading';
    var LENGTH_LIMIT = 1500;
    var SAFE_BATCH_SIZE = 8;
    var ICON_WARMUP_WAIT_MS = 1800;
    var ICON_WAIT_MS = 900;
    var TRANSLATION_MIN_WAIT_MS = 4200;
    var TRANSLATION_MAX_WAIT_MS = 9500;
    var SETTLE_WAIT_MS = 300;
    var QUEUE_STORAGE_KEY = "leadwerkDeepLPageQueueV3";
    var QUEUE_MAX_AGE_MS = 2 * 60 * 60 * 1000;
    var extensionWarm = false;
    var state = { active: false, stop: false, form: null };

    function sleep(ms) {
        return new Promise(function (resolve) { window.setTimeout(resolve, ms); });
    }

    function randomBetween(min, max) {
        return min + Math.floor(Math.random() * ((max - min) + 1));
    }

    function normalize(value) {
        return (value || "").replace(/\s+/g, " ").trim();
    }

    function decodeTranslationEntities(value) {
        var decoded = String(value || "");
        for (var depth = 0; depth < 3 && /&(?:#[0-9]{1,7}|#x[0-9a-f]{1,6}|[a-z][a-z0-9]{1,31});/i.test(decoded); depth += 1) {
            var textarea = document.createElement("textarea");
            textarea.innerHTML = decoded;
            if (textarea.value === decoded) break;
            decoded = textarea.value;
        }
        return decoded;
    }

    function normalizeTranslationField(field) {
        if (!field) return;
        var decoded = decodeTranslationEntities(field.value);
        if (decoded !== field.value) field.value = decoded;
    }

    function sourceLengthBonus(length) {
        if (length < 280) return 0;
        if (length < 700) return randomBetween(50, 110);
        if (length < 1100) return randomBetween(100, 190);
        return randomBetween(170, 300);
    }

    function translationTimeout(length) {
        return Math.min(TRANSLATION_MAX_WAIT_MS, TRANSLATION_MIN_WAIT_MS + (length * 4));
    }

    function triggerUpdate(field) {
        field.dispatchEvent(new Event("input", { bubbles: true }));
        field.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function isVisible(element) {
        if (!element || !(element instanceof Element) || element.hidden || element.getAttribute("aria-hidden") === "true") return false;
        var style = window.getComputedStyle(element);
        return style.display !== "none" && style.visibility !== "hidden" && parseFloat(style.opacity || "1") !== 0 && element.getClientRects().length > 0;
    }

    function uniquePush(list, element) {
        if (element && element instanceof Element && list.indexOf(element) === -1) list.push(element);
    }

    function searchRoots() {
        var roots = [];
        var queue = [document];
        while (queue.length) {
            var root = queue.shift();
            if (!root || roots.indexOf(root) !== -1) continue;
            roots.push(root);
            if (!root.querySelectorAll) continue;
            Array.prototype.forEach.call(root.querySelectorAll("*"), function (node) {
                if (node.shadowRoot && roots.indexOf(node.shadowRoot) === -1) queue.push(node.shadowRoot);
            });
        }
        return roots;
    }

    function collect(selector) {
        var matches = [];
        searchRoots().forEach(function (root) {
            if (!root.querySelectorAll) return;
            try {
                Array.prototype.forEach.call(root.querySelectorAll(selector), function (node) { uniquePush(matches, node); });
            } catch (error) {}
        });
        return matches;
    }

    function tooltipText(node) {
        if (!node || !(node instanceof Element)) return "";
        if (node.getAttribute("data-tooltip")) return node.getAttribute("data-tooltip") || "";
        var owner = node.closest ? node.closest("[data-tooltip]") : null;
        return owner ? (owner.getAttribute("data-tooltip") || "") : "";
    }

    function isTranslateTooltip(text) {
        return /ctrl\s*\+\s*shift\s*\+\s*y|translate|ubersetzen|[Üü]bersetzen/i.test(text || "");
    }

    function normalizeIcon(node) {
        if (!node || !(node instanceof Element)) return null;
        if (node.matches(ICON_TRIGGER_SELECTOR)) return node;
        var direct = node.closest ? node.closest(ICON_TRIGGER_SELECTOR) : null;
        if (direct) return direct;
        var tooltip = node.matches(ICON_TOOLTIP_SELECTOR) ? node : (node.closest ? node.closest(ICON_TOOLTIP_SELECTOR) : null);
        if (!tooltip || !isTranslateTooltip(tooltipText(tooltip))) return null;
        return (tooltip.closest && (tooltip.closest(ICON_TRIGGER_SELECTOR) || tooltip.closest(".dl-icon-circle.dl-icon"))) || tooltip.parentElement;
    }

    function normalizeOverlay(node) {
        if (!node || !(node instanceof Element)) return null;
        if (node.matches(".dl-input-positioner")) return node;
        if (!node.closest) return null;
        var positioner = node.closest(".dl-input-positioner");
        if (positioner) return positioner;
        var container = node.closest(".dl-input-translation-container");
        if (container) return container.querySelector(".dl-input-positioner") || container;
        var placeholder = node.closest(".dl-input-placeholder");
        return placeholder ? (placeholder.closest(".dl-input-positioner") || placeholder) : null;
    }

    function verticalOverlap(a, b) {
        return a.bottom >= b.top && b.bottom >= a.top;
    }

    function targetScore(fieldRect, targetRect) {
        var desiredX = fieldRect.left + (fieldRect.width * 0.8);
        var score = Math.abs((fieldRect.top + fieldRect.height / 2) - (targetRect.top + targetRect.height / 2));
        score += Math.abs(desiredX - (targetRect.left + targetRect.width / 2));
        if (!verticalOverlap(fieldRect, targetRect)) score += 2500;
        if (targetRect.left < fieldRect.left + fieldRect.width * 0.45) score += 500;
        return score;
    }

    function overlayIcons(overlay) {
        var icons = [];
        if (!overlay || !overlay.querySelectorAll) return icons;
        Array.prototype.forEach.call(overlay.querySelectorAll(ICON_SELECTOR + "," + ICON_TOOLTIP_SELECTOR), function (node) {
            var icon = normalizeIcon(node);
            if (isVisible(icon)) uniquePush(icons, icon);
        });
        return icons;
    }

    function findTargets(field) {
        var fieldRect = field.getBoundingClientRect();
        var matches = [];
        var iconsSeen = [];
        collect(OVERLAY_SELECTOR).forEach(function (node) {
            var overlay = normalizeOverlay(node) || node;
            if (!isVisible(overlay)) return;
            overlayIcons(overlay).forEach(function (icon) {
                if (iconsSeen.indexOf(icon) !== -1) return;
                iconsSeen.push(icon);
                matches.push({ icon: icon, overlay: overlay, score: targetScore(fieldRect, overlay.getBoundingClientRect()) });
            });
        });
        collect(ICON_SELECTOR + "," + ICON_TOOLTIP_SELECTOR).forEach(function (node) {
            var icon = normalizeIcon(node);
            if (!isVisible(icon) || iconsSeen.indexOf(icon) !== -1) return;
            var overlay = normalizeOverlay(icon);
            var rect = overlay ? overlay.getBoundingClientRect() : icon.getBoundingClientRect();
            iconsSeen.push(icon);
            matches.push({ icon: icon, overlay: overlay, score: targetScore(fieldRect, rect) + (overlay ? 0 : 1200) });
        });
        matches.sort(function (a, b) { return a.score - b.score; });
        return matches.slice(0, 2);
    }

    async function waitForTarget(field, timeout) {
        var started = Date.now();
        while (Date.now() - started < timeout) {
            var targets = findTargets(field);
            if (targets.length) return targets;
            await sleep(150);
        }
        return [];
    }

    function scrollSnapshot() {
        var element = document.scrollingElement || document.documentElement;
        return { x: element.scrollLeft, y: element.scrollTop };
    }

    function restoreScroll(snapshot) {
        if (!snapshot) return;
        var element = document.scrollingElement || document.documentElement;
        element.scrollLeft = snapshot.x;
        element.scrollTop = snapshot.y;
        window.scrollTo(snapshot.x, snapshot.y);
    }

    function stabilizeScroll(snapshot) {
        restoreScroll(snapshot);
        window.setTimeout(function () { restoreScroll(snapshot); }, 40);
        window.setTimeout(function () { restoreScroll(snapshot); }, 160);
    }

    function pointerClick(target) {
        var rect = target.getBoundingClientRect();
        var init = { bubbles: true, cancelable: true, view: window, clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2, button: 0, buttons: 1 };
        try {
            ["pointerover", "pointerenter", "pointermove", "pointerdown", "pointerup"].forEach(function (type) { target.dispatchEvent(new PointerEvent(type, init)); });
        } catch (error) {}
        ["mouseover", "mouseenter", "mousemove", "mousedown", "mouseup", "click"].forEach(function (type) { target.dispatchEvent(new MouseEvent(type, init)); });
    }

    function nativeClick(icon, snapshot) {
        var target = normalizeIcon(icon) || icon;
        if (!isVisible(target)) return false;
        try { target.focus({ preventScroll: true }); } catch (error) { try { target.focus(); } catch (ignored) {} }
        try {
            if (typeof target.click === "function") target.click();
            else pointerClick(target);
        } catch (error) { pointerClick(target); }
        stabilizeScroll(snapshot);
        return true;
    }

    function prepareField(field) {
        field.scrollIntoView({ block: "center", inline: "nearest" });
        try { field.focus({ preventScroll: true }); } catch (error) { field.focus(); }
        var length = field.value.length;
        if (typeof field.setSelectionRange === "function") field.setSelectionRange(length, length);
        pointerClick(field);
        if (typeof field.select === "function") field.select();
        if (typeof field.setSelectionRange === "function") field.setSelectionRange(0, length);
    }

    function loading(overlay) {
        return !!overlay && !!overlay.querySelector && isVisible(overlay.querySelector(LOADING_SELECTOR));
    }

    function monitorFieldActivity(field) {
        var activity = { count: 0, lastAt: 0 };
        var record = function () {
            activity.count += 1;
            activity.lastAt = Date.now();
        };
        field.addEventListener("input", record);
        field.addEventListener("change", record);
        return {
            activity: activity,
            stop: function () {
                field.removeEventListener("input", record);
                field.removeEventListener("change", record);
            }
        };
    }

    async function waitForTranslation(field, baseline, overlay, timeout, monitor) {
        var original = normalize(baseline);
        var started = Date.now();
        var sawLoading = false;
        var loadingFinishedAt = 0;
        var candidate = "";
        var stableSince = 0;
        while (Date.now() - started < timeout) {
            var current = normalize(field.value);
            var loadingNow = loading(overlay);
            if (loadingNow) sawLoading = true;
            else if (sawLoading && !loadingFinishedAt) loadingFinishedAt = Date.now();
            if (current && current !== original) {
                if (current !== candidate) {
                    candidate = current;
                    stableSince = Date.now();
                } else if (Date.now() - stableSince >= SETTLE_WAIT_MS && !loadingNow) {
                    return "changed";
                }
            }
            if (
                current === original &&
                !loadingNow &&
                (
                    (monitor && monitor.activity.count > 0 && Date.now() - monitor.activity.lastAt >= SETTLE_WAIT_MS) ||
                    (loadingFinishedAt && Date.now() - loadingFinishedAt >= SETTLE_WAIT_MS)
                )
            ) {
                return "same";
            }
            await sleep(sawLoading ? 150 : 100);
        }
        return "";
    }

    function setStatus(form, active, message) {
        state.active = active;
        form.querySelectorAll("[data-lw-machine],[data-lw-machine-section],[data-lw-machine-all]").forEach(function (button) { button.disabled = active; });
        var cancel = form.querySelector("[data-lw-machine-cancel]");
        if (cancel) { cancel.hidden = !active; cancel.textContent = "Abbrechen"; }
        var status = form.querySelector(".lw-machine-progress");
        if (!status) {
            status = document.createElement("p");
            status.className = "lw-machine-progress";
            status.setAttribute("role", "status");
            form.prepend(status);
        }
        status.textContent = message || "";
    }

    function itemForSegment(segment) {
        return { segment: segment, source: segment.querySelector("[data-lw-source]"), target: segment.querySelector("[data-lw-target]") };
    }

    function collectItems(scope, includeFilled) {
        var items = Array.prototype.map.call(scope.querySelectorAll(".lw-segment"), itemForSegment).filter(function (item) {
            if (!item.source || !item.target || !item.source.value.trim()) return false;
            return includeFilled || !item.target.value.trim() || item.segment.dataset.status === "needs_review";
        });
        var titleSource = scope.querySelector("[data-lw-title-source]");
        var titleTarget = scope.querySelector("[data-lw-title-target]");
        var titleIsPlaceholder = titleSource && titleTarget && (titleTarget.value.trim() === titleSource.value.trim() || /\s*\(EN\)\s*$/.test(titleTarget.value));
        var titleNeedsReview = titleTarget && titleTarget.dataset.needsReview === "1";
        if (titleSource && titleTarget && titleSource.value.trim() && (includeFilled || !titleTarget.value.trim() || titleIsPlaceholder || titleNeedsReview)) {
            items.unshift({ segment: null, source: titleSource, target: titleTarget });
        }
        return items;
    }

    function restoreTarget(item, value) {
        item.target.value = value;
        triggerUpdate(item.target);
    }

    async function translateItem(item) {
        var source = item.source.value || "";
        if (source.length >= LENGTH_LIMIT) return "too_long";
        var previous = item.target.value;
        var snapshot;
        try {
            item.target.value = source;
            triggerUpdate(item.target);
            prepareField(item.target);
            snapshot = scrollSnapshot();
            await sleep(randomBetween(180, 420) + sourceLengthBonus(source.length));
            var targets = await waitForTarget(item.target, (extensionWarm ? ICON_WAIT_MS : ICON_WARMUP_WAIT_MS) + sourceLengthBonus(source.length));
            if (!targets.length) {
                restoreTarget(item, previous);
                return "no_icon";
            }
            extensionWarm = true;
            for (var index = 0; index < targets.length; index += 1) {
                prepareField(item.target);
                await sleep(randomBetween(180, 360));
                var monitor = monitorFieldActivity(item.target);
                var translationResult = "";
                try {
                    if (!nativeClick(targets[index].icon, snapshot)) continue;
                    translationResult = await waitForTranslation(item.target, source, targets[index].overlay, translationTimeout(source.length), monitor);
                } finally {
                    monitor.stop();
                }
                if (translationResult) {
                    normalizeTranslationField(item.target);
                    triggerUpdate(item.target);
                    stabilizeScroll(snapshot);
                    return "translated";
                }
            }
        } catch (error) {
            restoreTarget(item, previous);
            if (snapshot) stabilizeScroll(snapshot);
            throw error;
        }
        restoreTarget(item, previous);
        if (snapshot) stabilizeScroll(snapshot);
        return "timed_out";
    }

    async function cooldown(form, seconds, progress) {
        for (var remaining = seconds; remaining > 0; remaining -= 1) {
            if (state.stop) return;
            setStatus(form, true, progress + " · Sicherheits-Pause " + remaining + "s");
            await sleep(1000);
        }
    }

    async function run(form, items, options) {
        options = options || {};
        if (state.active) return null;
        if (!items.length) return { counts: { translated: 0, too_long: 0, no_icon: 0, timed_out: 0 }, stopped: false, extensionMissing: false };
        state.stop = false;
        state.form = form;
        var counts = { translated: 0, too_long: 0, no_icon: 0, timed_out: 0 };
        var extensionMissing = false;
        var pageLabel = options.pageLabel ? options.pageLabel + " · " : "";
        setStatus(form, true, "DeepL-Browsererweiterung wird vorbereitet …");
        for (var index = 0; index < items.length; index += 1) {
            if (state.stop) break;
            setStatus(form, true, pageLabel + "DeepL " + (index + 1) + "/" + items.length + " · übersetzt " + counts.translated);
            var result;
            try { result = await translateItem(items[index]); } catch (error) { result = "timed_out"; }
            counts[result] = (counts[result] || 0) + 1;
            if (result === "no_icon" && !counts.translated) {
                extensionMissing = true;
                break;
            }
            if ((index + 1) < items.length) await sleep(randomBetween(250, 550));
            if (!state.stop && (index + 1) < items.length && (index + 1) % SAFE_BATCH_SIZE === 0) {
                var failed = result !== "translated" && result !== "too_long";
                await cooldown(form, randomBetween(failed ? 6 : 2, failed ? 9 : 4), pageLabel + "DeepL " + (index + 1) + "/" + items.length);
            }
        }
        var stopped = state.stop;
        var message = (stopped ? "Gestoppt. " : "Fertig. ") + "Übersetzt: " + counts.translated + ", zu lang: " + counts.too_long + ", Icon fehlt: " + counts.no_icon + ", Timeout: " + counts.timed_out + ". Bitte prüfen und speichern.";
        if (counts.no_icon && !counts.translated) message += " Die offizielle DeepL-Browsererweiterung ist nicht aktiv oder darf auf dieser WordPress-Seite nicht ausgeführt werden.";
        setStatus(form, false, message);
        state.stop = false;
        state.form = null;
        return { counts: counts, stopped: stopped, extensionMissing: extensionMissing };
    }

    function readQueue() {
        var raw;
        try { raw = window.sessionStorage.getItem(QUEUE_STORAGE_KEY); } catch (error) { return null; }
        if (!raw) return null;
        try {
            var queue = JSON.parse(raw);
            if (!queue || queue.version !== 3 || !Array.isArray(queue.items) || !queue.items.length || !queue.startedAt || Date.now() - queue.startedAt > QUEUE_MAX_AGE_MS) {
                clearQueue();
                return null;
            }
            return queue;
        } catch (error) {
            clearQueue();
            return null;
        }
    }

    function writeQueue(queue) {
        try { window.sessionStorage.setItem(QUEUE_STORAGE_KEY, JSON.stringify(queue)); return true; } catch (error) { return false; }
    }

    function clearQueue() {
        try { window.sessionStorage.removeItem(QUEUE_STORAGE_KEY); } catch (error) {}
    }

    function queueUrl(url) {
        var target = new URL(url, window.location.href);
        target.searchParams.set("lw_deepl_auto", "1");
        return target.toString();
    }

    function finishQueue(queue) {
        clearQueue();
        var target = new URL(queue.dashboard, window.location.href);
        target.searchParams.set("lw_deepl_complete", "1");
        target.searchParams.set("lw_deepl_pages", String(queue.pages || 0));
        target.searchParams.set("lw_deepl_segments", String(queue.translated || 0));
        target.searchParams.set("lw_deepl_globals", String(queue.globals || 0));
        window.location.assign(target.toString());
    }

    function advanceQueue(queue) {
        if (queue.index >= queue.items.length) {
            finishQueue(queue);
            return;
        }
        queue.phase = "translate";
        writeQueue(queue);
        window.location.assign(queueUrl(queue.items[queue.index].url));
    }

    function saveEditor(form) {
        form.querySelectorAll("[data-lw-target],[data-lw-title-target]").forEach(normalizeTranslationField);
        var button = form.querySelector('button[name="leadwerk_editor_action"][value="save_draft"],input[type="submit"],button[type="submit"]');
        if (typeof form.requestSubmit === "function" && button) {
            form.requestSubmit(button);
            return;
        }
        var action = document.createElement("input");
        action.type = "hidden";
        action.name = "leadwerk_editor_action";
        action.value = "save_draft";
        form.appendChild(action);
        form.submit();
    }

    async function resumeQueue() {
        var queue = readQueue();
        if (!queue) return;
        var form = document.querySelector(".lw-segment-form");
        if (!form) return;
        if (queue.phase === "saved") {
            advanceQueue(queue);
            return;
        }
        var currentUrl = new URL(window.location.href);
        var sourceId = Number(currentUrl.searchParams.get("source_id") || 0);
        var current = queue.items[queue.index];
        if (!current) {
            finishQueue(queue);
            return;
        }
        var correctEditor = current.kind === "globals" ? currentUrl.searchParams.get("strings") === "1" : Number(current.sourceId) === sourceId;
        if (!correctEditor) {
            window.location.assign(queueUrl(current.url));
            return;
        }
        var items = collectItems(form, false);
        if (!items.length) {
            queue.index += 1;
            advanceQueue(queue);
            return;
        }
        var result = await run(form, items, "" === current.title ? {} : { pageLabel: "Seite " + (queue.index + 1) + "/" + queue.items.length });
        if (!result) return;
        if (result.extensionMissing) {
            clearQueue();
            return;
        }
        if (!result.counts.translated) {
            queue.index += 1;
            if (result.stopped) {
                finishQueue(queue);
                return;
            }
            advanceQueue(queue);
            return;
        }
        queue.translated = (queue.translated || 0) + result.counts.translated;
        if (current.kind === "globals") queue.globals = (queue.globals || 0) + 1;
        else queue.pages = (queue.pages || 0) + 1;
        queue.index = result.stopped ? queue.items.length : queue.index + 1;
        queue.phase = "saved";
        writeQueue(queue);
        setStatus(form, true, "Übersetzung abgeschlossen · Seite wird gespeichert …");
        saveEditor(form);
    }

    document.addEventListener("click", function (event) {
        var dashboardButton = event.target.closest("[data-lw-machine-dashboard-all]");
        if (dashboardButton) {
            event.preventDefault();
            var items;
            try { items = JSON.parse(dashboardButton.dataset.queue || "[]"); } catch (error) { items = []; }
            if (!items.length) return;
            var queue = { version: 3, items: items, index: 0, phase: "translate", dashboard: dashboardButton.dataset.dashboard || window.location.href, pages: 0, globals: 0, translated: 0, startedAt: Date.now() };
            if (!writeQueue(queue)) {
                window.alert("Die DeepL-Warteschlange konnte im Browser nicht gespeichert werden. Bitte Session Storage für diese Seite erlauben.");
                return;
            }
            dashboardButton.disabled = true;
            dashboardButton.textContent = "DeepL-Automation wird gestartet …";
            window.location.assign(queueUrl(items[0].url));
            return;
        }
        var cancel = event.target.closest("[data-lw-machine-cancel]");
        if (cancel) {
            event.preventDefault();
            state.stop = true;
            cancel.textContent = "Wird gestoppt …";
            return;
        }
        var button = event.target.closest("[data-lw-machine],[data-lw-machine-section],[data-lw-machine-all]");
        if (!button) return;
        event.preventDefault();
        var form = button.closest(".lw-segment-form");
        if (!form) return;
        var scope = button.matches("[data-lw-machine]") ? button.closest(".lw-segment") : (button.matches("[data-lw-machine-section]") ? button.closest(".lw-section") : form);
        var items = button.matches("[data-lw-machine]") ? [itemForSegment(scope)] : collectItems(scope, false);
        if (button.matches("[data-lw-machine]") && items[0].target.value.trim() && !window.confirm("Soll der vorhandene EN-Text durch die neue DeepL-Übersetzung ersetzt werden?")) return;
        run(form, items);
    });

    document.addEventListener("submit", function (event) {
        var form = event.target;
        if (!form || !form.matches || !form.matches(".lw-segment-form")) return;
        form.querySelectorAll("[data-lw-target],[data-lw-title-target]").forEach(normalizeTranslationField);
    }, true);

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", function () { window.setTimeout(resumeQueue, 350); });
    else window.setTimeout(resumeQueue, 350);
})();
