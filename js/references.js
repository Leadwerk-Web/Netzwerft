/**
 * Referenzlogos — 3 Zeilen, Kategorie-Farben
 */
(() => {
    "use strict";

    const MARQUEE_MIN = 3;
    const ROW_COUNT = 3;

    const TRUST_REFERENCES = [
        { src: "Fotos/ref-14.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-15.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-17.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-18.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-19.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-20.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-21.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-22.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-23.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-24.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-25.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-26.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-27.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-28.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-29.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-30.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-31.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-32.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-33.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-34.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-35.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-36.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-91.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-92.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-93.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-94.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-95.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-96.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-97.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-98.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-99.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-100.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-101.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-102.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-103.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-104.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-140.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-141.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-142.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-143.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-144.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-145.webp", category: "aerzte", alt: "Kundenreferenz" },
        { src: "Fotos/ref-37.webp", category: "zahn", alt: "Kundenreferenz Zahnarztpraxis" },
        { src: "Fotos/ref-38.webp", category: "zahn", alt: "Kundenreferenz Zahnarztpraxis" },
        { src: "Fotos/ref-39.webp", category: "zahn", alt: "Kundenreferenz Zahnarztpraxis" },
        { src: "Fotos/ref-40.webp", category: "zahn", alt: "Kundenreferenz Zahnarztpraxis" },
        { src: "Fotos/ref-41.webp", category: "zahn", alt: "Kundenreferenz Zahnarztpraxis" },
        { src: "Fotos/ref-42.webp", category: "zahn", alt: "Kundenreferenz Zahnarztpraxis" },
        { src: "Fotos/ref-43.webp", category: "zahn", alt: "Kundenreferenz Zahnarztpraxis" },
        { src: "Fotos/ref-79.webp", category: "radiologie", alt: "Kundenreferenz Radiologie" },
        { src: "Fotos/ref-80.webp", category: "radiologie", alt: "Kundenreferenz Radiologie" },
        { src: "Fotos/ref-55.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-56.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-57.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-58.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-59.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-61.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-62.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-63.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-65.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-66.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-67.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-68.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-69.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-70.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-71.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-72.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-73.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-74.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
        { src: "Fotos/ref-76.webp", category: "gewerbe", alt: "Kundenreferenz Gewerbe" },
    ];

    function refSortKey(src) {
        const match = src.match(/ref-(\d+)/);
        return match ? parseInt(match[1], 10) : 0;
    }

    function sortReferences(items) {
        return [...items].sort(
            (a, b) => refSortKey(a.src) - refSortKey(b.src) || a.src.localeCompare(b.src)
        );
    }

    function shuffleInPlace(arr) {
        for (let i = arr.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [arr[i], arr[j]] = [arr[j], arr[i]];
        }
        return arr;
    }

    function bucketByCategory(items) {
        const buckets = { aerzte: [], gewerbe: [], zahn: [], radiologie: [] };
        items.forEach((entry) => {
            if (buckets[entry.category]) buckets[entry.category].push(entry);
        });
        Object.keys(buckets).forEach((key) => shuffleInPlace(buckets[key]));
        return buckets;
    }

    /** Seltene Kategorien zuerst, damit sie den meisten Abstand bekommen */
    function sparseFirst(a, b) {
        const rank = { radiologie: 0, zahn: 1, gewerbe: 2, aerzte: 3 };
        return (rank[a.category] ?? 9) - (rank[b.category] ?? 9);
    }

    function minDistanceToCategory(list, index, category) {
        let min = Infinity;
        for (let i = 0; i < list.length; i++) {
            if (list[i].category !== category) continue;
            min = Math.min(min, Math.abs(i - index));
        }
        return min;
    }

    /**
     * Basis (meist Ärzte) mit sparse Items so füllen, dass gleiche Kategorien
     * möglichst nie nebeneinander liegen und maximal verteilt sind.
     */
    function weaveIntoBase(base, sparse) {
        const result = base.slice();
        const ordered = sparse.slice().sort(sparseFirst);

        ordered.forEach((entry) => {
            if (!result.length) {
                result.push(entry);
                return;
            }

            let bestIndex = result.length;
            let bestScore = -Infinity;

            for (let i = 0; i <= result.length; i++) {
                const left = result[i - 1];
                const right = result[i];
                if (left?.category === entry.category) continue;
                if (right?.category === entry.category) continue;

                const dist = minDistanceToCategory(result, i, entry.category);
                // Bevorzuge Mitte der Lücken + Abstand zur eigenen Kategorie
                const edgePenalty = i === 0 || i === result.length ? 0.25 : 0;
                const score = dist * 10 + (1 - Math.abs(i - result.length / 2) / (result.length + 1)) - edgePenalty;

                if (score > bestScore) {
                    bestScore = score;
                    bestIndex = i;
                }
            }

            // Fallback, falls nur noch Nachbarschaft zu eigener Kategorie möglich
            if (bestScore === -Infinity) {
                bestIndex = Math.round(result.length / 2);
            }

            result.splice(bestIndex, 0, entry);
        });

        return result;
    }

    /** Endlosschleife: erste/letzte Kachel derselben Rare-Kategorie vermeiden */
    function avoidLoopClash(row) {
        if (row.length < 3) return row;
        const rare = { radiologie: true, gewerbe: true, zahn: true };
        const rotated = row.slice();
        for (let tries = 0; tries < rotated.length; tries++) {
            const first = rotated[0];
            const last = rotated[rotated.length - 1];
            if (!(rare[first.category] && first.category === last.category)) {
                return rotated;
            }
            rotated.push(rotated.shift());
        }
        return rotated;
    }

    /**
     * Kategorien gleichmäßig auf Zeilen verteilen, innerhalb der Zeile weben.
     * Verhindert Klumpen durch i % rowCount.
     */
    function buildBalancedRows(items, rowCount) {
        const buckets = bucketByCategory(items);
        const rows = Array.from({ length: rowCount }, () => ({
            aerzte: [],
            gewerbe: [],
            zahn: [],
            radiologie: []
        }));

        // Jede Kategorie round-robin auf Zeilen — so landen beide Radiologien selten in einer Zeile
        ["aerzte", "gewerbe", "zahn", "radiologie"].forEach((cat) => {
            buckets[cat].forEach((entry, i) => {
                rows[i % rowCount][cat].push(entry);
            });
        });

        return rows.map((rowBuckets) => {
            const base = rowBuckets.aerzte;
            const sparse = [
                ...rowBuckets.radiologie,
                ...rowBuckets.zahn,
                ...rowBuckets.gewerbe
            ];
            return avoidLoopClash(weaveIntoBase(base, sparse));
        });
    }

    function logoItemHTML(entry, hidden) {
        return `<div class="logo-wall__item logo-wall__item--${entry.category}"${
            hidden ? ' aria-hidden="true"' : ""
        }><img src="${entry.src}" alt="${hidden ? "" : entry.alt}" loading="lazy" decoding="async" /></div>`;
    }

    function buildRow(items, index, withClone) {
        const original = items.map((entry) => logoItemHTML(entry, false)).join("");
        const clone =
            withClone !== false ? items.map((entry) => logoItemHTML(entry, true)).join("") : "";
        const duration = Math.max(60, items.length * 8);
        const direction = index % 2 === 1 ? "reverse" : "normal";
        return `
            <div class="logo-marquee__row">
                <div class="logo-marquee__track" style="--marquee-duration:${duration}s;--marquee-dir:${direction};">
                    ${original}${clone}
                </div>
            </div>`;
    }

    function rowCountFor(category, itemCount) {
        if (category === "all") return ROW_COUNT;
        if (itemCount <= 10) return 1;
        if (itemCount <= 22) return 2;
        return ROW_COUNT;
    }

    function shouldUseGrid(category, itemCount) {
        if (itemCount < MARQUEE_MIN) return true;
        if (category === "zahn") return true;
        return false;
    }

    function splitRows(items, rowCount) {
        const rows = Array.from({ length: rowCount }, () => []);
        items.forEach((entry, i) => rows[i % rowCount].push(entry));
        return rows;
    }

    function renderTrustLogos(category) {
        const wall = document.getElementById("logo-wall");
        const legend = document.querySelector(".trust__legend");
        if (!wall) return;

        const filtered =
            category === "all"
                ? TRUST_REFERENCES
                : sortReferences(TRUST_REFERENCES.filter((entry) => entry.category === category));

        wall.classList.remove("logo-wall--marquee", "logo-wall--grid", "logo-wall--all");
        legend?.classList.toggle("is-hidden", category !== "all");

        if (!filtered.length) {
            wall.innerHTML =
                '<p class="logo-wall__empty">F&uuml;r diese Kategorie sind noch keine Referenzlogos hinterlegt.</p>';
            return;
        }

        if (shouldUseGrid(category, filtered.length)) {
            wall.classList.add("logo-wall--grid");
            wall.innerHTML = filtered.map((entry) => logoItemHTML(entry, false)).join("");
            return;
        }

        if (category === "all") {
            wall.classList.add("logo-wall--marquee", "logo-wall--all");
        } else {
            wall.classList.add("logo-wall--marquee");
        }

        const rowCount = rowCountFor(category, filtered.length);
        const rows =
            category === "all"
                ? buildBalancedRows(filtered, rowCount)
                : splitRows(filtered, rowCount);
        const withClone = category === "all" || filtered.length > 10;
        wall.innerHTML = rows.map((row, i) => buildRow(row, i, withClone)).join("");
    }

    function initTrustReferences() {
        const wall = document.getElementById("logo-wall");
        if (!wall) return;

        const tabs = document.querySelectorAll(".trust__tab");
        if (!tabs.length) {
            renderTrustLogos("all");
            return;
        }

        tabs.forEach((tab) => {
            tab.addEventListener("click", () => {
                tabs.forEach((t) => t.setAttribute("aria-selected", "false"));
                tab.setAttribute("aria-selected", "true");
                renderTrustLogos(tab.dataset.trustCategory || "all");
            });
        });

        const active = document.querySelector('.trust__tab[aria-selected="true"]');
        renderTrustLogos(active?.dataset.trustCategory || "all");
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initTrustReferences);
    } else {
        initTrustReferences();
    }
})();
