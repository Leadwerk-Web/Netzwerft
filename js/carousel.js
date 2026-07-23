/**
 * netzwerft 3D-Karussell (nw-karussell)
 */
(function () {
    'use strict';

    var root = document.getElementById('nw-karussell');
    var buehne = document.getElementById('nw-karussell-buehne');
    var gleis = document.getElementById('nw-karussell-gleis');
    var punkteWrap = document.getElementById('nw-karussell-punkte');

    if (!root || !buehne || !gleis) return;

    var kacheln = Array.prototype.slice.call(gleis.querySelectorAll('[data-nw-kachel]'));
    var anzahl = kacheln.length;
    if (!anzahl) return;

    var aktiv = 0;
    var gesamtDrehung = 0;
    var animiert = false;
    var animStartTime = 0;
    var animFromDreh = 0;
    var animToDreh = 0;
    var animRaf = null;
    var animDuration = 2800;
    var animLaufzeit = 2800;
    var DRAG_SNAP_MS = 1400;
    var BAND_SEKUNDEN_PRO_SCHRITT = 12;
    var laufbandAktiv = false;
    var laufbandRaf = null;
    var laufbandLetzteZeit = 0;
    var hoverPause = false;
    var hoverLeaveTimer = null;
    var HOVER_LEAVE_MS = 480;
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var dragAktiv = false;
    var dragStartX = 0;
    var dragStartDreh = 0;
    var dragStartAktiv = 0;
    var dragZielDreh = 0;
    var dragBewegt = false;
    var dragPointerTyp = 'mouse';
    var dragRaf = null;
    var DRAG_SCHWELLE = 16;
    var DRAG_PIXEL_MAUS = 400;
    var DRAG_PIXEL_TOUCH = 300;
    var DRAG_DAEMPFUNG = 0.55;
    var DRAG_COMMIT = 0.34;
    var DRAG_FOLLOW = 0.17;
    var DRAG_FOLLOW_END = 0.24;

    var KARTE_B = 720;
    var KARTE_H = 640;
    var KACHEL_GAP = 24;
    var SICHTBAR = 2;
    /* Max. gleichm??iger Schritt f?r 5 Kacheln: 2 * Schritt < 90? (Vorderseite + kein ?berlappen) */
    var ANZEIGE_SCHRITT = 36;

    function buehneOverhead() {
        var tiltExtra = Math.max(0, ANZEIGE_SCHRITT - 40);
        return Math.round(52 + tiltExtra * 5);
    }

    function heroFreiraumHoehe() {
        var steuerung = root.querySelector('.nw-karussell__steuerung');
        if (steuerung) {
            return Math.max(0, steuerung.offsetTop);
        }
        return Math.max(0, root.clientHeight);
    }

    function kartenMasseBerechnen() {
        var overhead = buehneOverhead();
        var idealBuehne = KARTE_H + overhead;
        var freiraum = heroFreiraumHoehe();
        var scale = 1;

        if (freiraum != null && freiraum > 0 && freiraum < idealBuehne) {
            scale = freiraum / idealBuehne;
        }

        scale = Math.max(0.45, Math.min(1, scale));

        return {
            karteB: Math.round(KARTE_B * scale),
            karteH: Math.round(KARTE_H * scale),
            buehneH: Math.round(KARTE_H * scale + overhead),
            gap: Math.round(KACHEL_GAP * scale)
        };
    }

    function schrittGrad() {
        return 360 / anzahl;
    }

    function aktivBerechnen() {
        return mod(Math.round(-gesamtDrehung / schrittGrad()), anzahl);
    }

    function mod(n, m) {
        return ((n % m) + m) % m;
    }

    function kachelDifferenz(von, nach) {
        var diff = nach - von;
        var half = Math.floor(anzahl / 2);
        if (diff > half) diff -= anzahl;
        if (diff < -half) diff += anzahl;
        return diff;
    }

    function radiusBerechnen(karteB, gap) {
        karteB = karteB != null ? karteB : KARTE_B;
        gap = gap != null ? gap : KACHEL_GAP;
        var halb = (ANZEIGE_SCHRITT * Math.PI) / 180 * 0.5;
        if (halb < 0.001) return -900;
        return -((karteB + gap) / (2 * Math.sin(halb)));
    }

    function winkelNorm(w) {
        w = w % 360;
        if (w > 180) w -= 360;
        if (w < -180) w += 360;
        return w;
    }

    function weltWinkel(physicalIndex) {
        return winkelNorm(physicalIndex * schrittGrad() + gesamtDrehung);
    }

    function slotOffset(physicalIndex) {
        return Math.round(-weltWinkel(physicalIndex) / schrittGrad());
    }

    function kanonischeDrehung() {
        return -aktiv * schrittGrad();
    }

    /** Gleiche Ansicht, Winkel nahe der aktuellen Drehung (Endlosschleife) */
    function kanonischeDrehungNahe(basis) {
        var step = schrittGrad();
        var kanonisch = -aktiv * step;
        var runden = basis != null ? basis : gesamtDrehung;
        var zyklen = Math.round((runden - kanonisch) / 360);
        return kanonisch + zyklen * 360;
    }

    function zielDrehungVon(startDreh, vonIndex, nachIndex) {
        return startDreh - kachelDifferenz(vonIndex, nachIndex) * schrittGrad();
    }

    function slotKontinu(physicalIndex) {
        return -weltWinkel(physicalIndex) / schrittGrad();
    }

    function anzeigeWinkel(physicalIndex) {
        var slot = slotKontinu(physicalIndex);
        if (Math.abs(slot) > SICHTBAR + 0.55) return weltWinkel(physicalIndex);
        return slot * ANZEIGE_SCHRITT;
    }

    function kachelTransform(physicalIndex, radius, isHover) {
        var visual = anzeigeWinkel(physicalIndex);
        var lokal = visual - gesamtDrehung;
        // Negativer Radius = nach hinten; Hover holt die Kachel Richtung Kamera
        var z = radius + (isHover ? 320 : 0);
        var scale = isHover ? ' scale(1.08)' : '';
        return 'rotateY(' + lokal + 'deg) translateZ(' + z + 'px)' + scale;
    }

    /** Weiches Ein- und Auslaufen f?r fl?ssige Rotation */
    function easeInOut(t) {
        return t < 0.5
            ? 16 * t * t * t * t * t
            : 1 - Math.pow(-2 * t + 2, 5) / 2;
    }

    function dragDrehungAusDx(dx) {
        var pixel = dragPointerTyp === 'mouse' ? DRAG_PIXEL_MAUS : DRAG_PIXEL_TOUCH;
        var norm = dx / pixel;
        var gekruemmt = Math.sign(norm) * Math.pow(Math.abs(norm), 0.96);
        return gekruemmt * schrittGrad() * DRAG_DAEMPFUNG;
    }

    function dragBegrenzt(dreh) {
        var step = schrittGrad();
        var min = dragStartDreh - step;
        var max = dragStartDreh + step;
        return Math.max(min, Math.min(max, dreh));
    }

    function dragZielAusDelta() {
        var step = schrittGrad();
        var delta = gesamtDrehung - dragStartDreh;

        if (delta <= -step * DRAG_COMMIT) {
            return mod(dragStartAktiv + 1, anzahl);
        }
        if (delta >= step * DRAG_COMMIT) {
            return mod(dragStartAktiv - 1, anzahl);
        }
        return dragStartAktiv;
    }

    function drehungNormalisieren() {
        if (Math.abs(gesamtDrehung) <= 1440) return;
        var aktivKeep = aktivBerechnen();
        gleisOhneTransition(function () {
            gesamtDrehung = kanonischeDrehungNahe(gesamtDrehung);
        });
        aktiv = aktivKeep;
    }

    function laufbandGeschwindigkeit() {
        return schrittGrad() / (BAND_SEKUNDEN_PRO_SCHRITT * 1000);
    }

    function laufbandStoppen() {
        if (laufbandRaf) {
            cancelAnimationFrame(laufbandRaf);
            laufbandRaf = null;
        }
        laufbandAktiv = false;
        laufbandLetzteZeit = 0;
        gleis.classList.remove('is-laufband');
    }

    function laufbandStarten() {
        if (reducedMotion || dragAktiv || animiert || laufbandAktiv || hoverPause) return;
        laufbandAktiv = true;
        laufbandLetzteZeit = 0;
        gleis.classList.add('is-laufband');
        laufbandRaf = requestAnimationFrame(laufbandTick);
    }

    function laufbandTick(ts) {
        if (!laufbandAktiv || dragAktiv || animiert || hoverPause) {
            laufbandStoppen();
            return;
        }

        if (!laufbandLetzteZeit) laufbandLetzteZeit = ts;
        var dt = Math.min(48, ts - laufbandLetzteZeit);
        laufbandLetzteZeit = ts;

        gesamtDrehung -= laufbandGeschwindigkeit() * dt;

        var neuerAktiv = aktivBerechnen();
        if (neuerAktiv !== aktiv) {
            aktiv = neuerAktiv;
            punkteAktualisieren();
        }

        drehungNormalisieren();
        zustandAktualisieren();
        laufbandRaf = requestAnimationFrame(laufbandTick);
    }

    function gleisOhneTransition(fn) {
        gleis.classList.add('is-sprung');
        fn();
        void gleis.offsetWidth;
        gleis.classList.remove('is-sprung');
    }

    function zyklusSnap() {
        var nahe = kanonischeDrehungNahe(gesamtDrehung);
        if (Math.abs(gesamtDrehung - nahe) < 0.01) return;
        gleisOhneTransition(function () {
            gesamtDrehung = nahe;
            gleis.style.setProperty('--nw-drehung', gesamtDrehung + 'deg');
        });
    }

    function geometrieAnwenden() {
        var step = schrittGrad();
        var masse = kartenMasseBerechnen();
        var radius = radiusBerechnen(masse.karteB, masse.gap);

        gleis.style.setProperty('--nw-step', step + 'deg');
        gleis.style.setProperty('--nw-radius', radius + 'px');
        gleis.style.setProperty('--nw-karte-b', masse.karteB + 'px');
        gleis.style.setProperty('--nw-karte-h', masse.karteH + 'px');
        root.style.setProperty('--nw-buehne-h', masse.buehneH + 'px');
        buehne.style.setProperty('--nw-perspektive', '120em');
    }

    function zustandAktualisieren() {
        var masse = kartenMasseBerechnen();
        var radius = radiusBerechnen(masse.karteB, masse.gap);

        gleis.style.setProperty('--nw-drehung', gesamtDrehung + 'deg');
        gleis.classList.toggle('is-animiert', animiert);

        kacheln.forEach(function (kachel, physicalIndex) {
            var slot = slotKontinu(physicalIndex);
            var slotAbs = Math.abs(slot);
            var offset = Math.round(slot);
            var visual = anzeigeWinkel(physicalIndex);
            var sichtbar = slotAbs <= SICHTBAR + 0.55;
            var tiefe = Math.cos(visual * Math.PI / 180);
            var isHover = kachel.classList.contains('is-hover');
            var isSettle = kachel.classList.contains('is-hover-settle');

            kachel.style.transform = kachelTransform(physicalIndex, radius, isHover);

            kachel.classList.toggle('is-fokus', slotAbs < 0.45);
            kachel.classList.toggle('is-seite', offset !== 0 && sichtbar);
            kachel.classList.toggle('is-seite-innen', sichtbar && Math.abs(offset) === 1);
            kachel.classList.toggle('is-seite-aussen', sichtbar && Math.abs(offset) === 2);
            kachel.classList.toggle('is-seite-links', sichtbar && offset < 0);
            kachel.classList.toggle('is-seite-rechts', sichtbar && offset > 0);
            kachel.classList.toggle('is-verdeckt', !sichtbar);

            kachel.style.zIndex = (isHover || isSettle)
                ? '9999'
                : String(Math.round(Math.max(0, tiefe) * 1000));
            kachel.setAttribute('aria-hidden', sichtbar ? 'false' : 'true');
            kachel.tabIndex = slotAbs < 0.45 ? 0 : -1;

            if (!sichtbar) {
                kachel.style.setProperty('--nw-opacity', '0');
            } else if (isHover) {
                kachel.style.setProperty('--nw-opacity', '1');
            } else if (root.classList.contains('is-hover-pause')) {
                kachel.style.setProperty('--nw-opacity', '0.55');
            } else if (slotAbs < 0.5) {
                kachel.style.setProperty('--nw-opacity', String(Math.max(0.88, 1 - slotAbs * 0.18)));
            } else if (slotAbs < 1.5) {
                kachel.style.setProperty('--nw-opacity', String(Math.max(0.78, 0.94 - (slotAbs - 0.5) * 0.12)));
            } else {
                kachel.style.setProperty('--nw-opacity', '0.8');
            }
        });
    }

    function animationStop() {
        if (animRaf) {
            cancelAnimationFrame(animRaf);
            animRaf = null;
        }
        animiert = false;
        animStartTime = 0;
        gleis.classList.remove('is-animiert');
    }

    function animationTick(ts) {
        if (!animStartTime) animStartTime = ts;
        var t = Math.min(1, (ts - animStartTime) / animLaufzeit);
        gesamtDrehung = animFromDreh + (animToDreh - animFromDreh) * easeInOut(t);
        zustandAktualisieren();
        if (t < 1) {
            animRaf = requestAnimationFrame(animationTick);
            return;
        }
        animRaf = null;
        animStartTime = 0;
        animiert = false;
        animationEnde();
    }

    function animationStart(dauer) {
        animationStop();
        laufbandStoppen();
        animLaufzeit = dauer || animDuration;
        animiert = true;
        animStartTime = 0;
        gleis.classList.add('is-animiert');
        animRaf = requestAnimationFrame(animationTick);
    }

    function animationEnde() {
        gleis.classList.remove('is-animiert');
        zyklusSnap();
        zustandAktualisieren();
        laufbandStarten();
    }

    function dragFollowStop() {
        if (dragRaf) {
            cancelAnimationFrame(dragRaf);
            dragRaf = null;
        }
    }

    function dragFollowTick(faktor, fertig) {
        var diff = dragZielDreh - gesamtDrehung;

        if (Math.abs(diff) > 0.04) {
            gesamtDrehung += diff * faktor;
        } else {
            gesamtDrehung = dragZielDreh;
        }

        aktiv = dragZielAusDelta();
        zustandAktualisieren();
        punkteAktualisieren();

        if (Math.abs(dragZielDreh - gesamtDrehung) > 0.04) {
            dragRaf = requestAnimationFrame(function () {
                dragFollowTick(faktor, fertig);
            });
            return;
        }

        dragRaf = null;
        if (typeof fertig === 'function') fertig();
    }

    function dragFollowStart(faktor, fertig) {
        dragFollowStop();
        dragRaf = requestAnimationFrame(function () {
            dragFollowTick(faktor, fertig);
        });
    }

    function punkteBauen() {
        if (!punkteWrap) return;
        punkteWrap.innerHTML = '';
        for (var i = 0; i < anzahl; i++) {
            (function (idx) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'nw-karussell__punkt';
                btn.setAttribute('role', 'tab');
                btn.setAttribute('aria-label', 'Leistung ' + (idx + 1));
                btn.setAttribute('aria-selected', idx === aktiv ? 'true' : 'false');
                btn.addEventListener('click', function () {
                    geheZu(idx, { einSchritt: false });
                });
                punkteWrap.appendChild(btn);
            })(i);
        }
    }

    function punkteAktualisieren() {
        if (!punkteWrap) return;
        punkteWrap.querySelectorAll('.nw-karussell__punkt').forEach(function (p, i) {
            p.setAttribute('aria-selected', i === aktiv ? 'true' : 'false');
            p.classList.toggle('is-aktiv', i === aktiv);
        });
    }

    function geheZu(index, optionen) {
        var ziel = mod(index, anzahl);
        if (ziel === aktiv) return;

        var diff = kachelDifferenz(aktiv, ziel);
        var einSchritt = !optionen || optionen.einSchritt !== false;

        if (einSchritt && Math.abs(diff) > 1) {
            ziel = mod(aktiv + (diff > 0 ? 1 : -1), anzahl);
        }

        diff = kachelDifferenz(aktiv, ziel);
        var vonIndex = aktiv;
        aktiv = ziel;
        animFromDreh = gesamtDrehung;
        animToDreh = zielDrehungVon(gesamtDrehung, vonIndex, ziel);
        punkteAktualisieren();

        if (reducedMotion) {
            gesamtDrehung = kanonischeDrehungNahe(gesamtDrehung);
            gleisOhneTransition(function () {
                gleis.style.setProperty('--nw-drehung', gesamtDrehung + 'deg');
            });
            zustandAktualisieren();
            laufbandStarten();
        } else {
            animationStart(optionen && optionen.dauer);
        }
    }

    function weiter() { geheZu(aktiv + 1); }
    function zurueck() { geheZu(aktiv - 1); }

    function dragSnap() {
        var ziel = dragZielAusDelta();
        aktiv = ziel;
        animFromDreh = gesamtDrehung;
        animToDreh = zielDrehungVon(dragStartDreh, dragStartAktiv, ziel);
        punkteAktualisieren();

        if (Math.abs(gesamtDrehung - animToDreh) < 0.01) {
            gesamtDrehung = kanonischeDrehungNahe(gesamtDrehung);
            zyklusSnap();
            zustandAktualisieren();
            laufbandStarten();
            return;
        }

        if (reducedMotion) {
            gesamtDrehung = kanonischeDrehungNahe(animToDreh);
            zyklusSnap();
            zustandAktualisieren();
            laufbandStarten();
        } else {
            animationStart(DRAG_SNAP_MS);
        }
    }

    function dragStart(e) {
        if (e.pointerType === 'mouse' && e.button !== 0) return;

        laufbandStoppen();
        animationStop();
        dragFollowStop();
        dragAktiv = true;
        dragBewegt = false;
        dragPointerTyp = e.pointerType || 'mouse';
        dragStartX = e.clientX;
        dragStartDreh = gesamtDrehung;
        dragZielDreh = gesamtDrehung;
        dragStartAktiv = aktiv;
        buehne.classList.add('is-zieht');
        buehne.setPointerCapture(e.pointerId);
    }

    function dragMove(e) {
        if (!dragAktiv) return;

        var dx = e.clientX - dragStartX;
        if (Math.abs(dx) > DRAG_SCHWELLE) dragBewegt = true;

        dragZielDreh = dragBegrenzt(dragStartDreh + dragDrehungAusDx(dx));

        if (!dragRaf) {
            dragFollowStart(DRAG_FOLLOW);
        }
    }

    function dragEnd(e) {
        if (!dragAktiv) return;

        dragAktiv = false;
        buehne.classList.remove('is-zieht');

        try {
            buehne.releasePointerCapture(e.pointerId);
        } catch (err) { /* ignore */ }

        if (dragBewegt) {
            dragFollowStart(DRAG_FOLLOW_END, dragSnap);
        } else {
            dragFollowStop();
            laufbandStarten();
        }

        window.setTimeout(function () {
            dragBewegt = false;
        }, 0);
    }

    kacheln.forEach(function (kachel, i) {
        kachel.style.setProperty('--nw-index', String(i));
        kachel.addEventListener('click', function (e) {
            if (e.target && e.target.closest && e.target.closest('.nw-karussell__kachel-link')) {
                return;
            }
            if (dragBewegt) return;
            if (Math.abs(slotKontinu(i)) >= 0.45) geheZu(i, { einSchritt: true });
        });
    });

    function kachelUnterPunkt(x, y) {
        var treffer = [];
        var i;
        var kachel;
        var rect;
        var z;

        for (i = 0; i < kacheln.length; i++) {
            kachel = kacheln[i];
            if (kachel.classList.contains('is-verdeckt')) continue;

            rect = kachel.getBoundingClientRect();
            if (
                x < rect.left ||
                x > rect.right ||
                y < rect.top ||
                y > rect.bottom
            ) {
                continue;
            }

            z = parseInt(kachel.style.zIndex, 10);
            if (isNaN(z)) z = 0;

            treffer.push({
                kachel: kachel,
                z: z,
                area: rect.width * rect.height
            });
        }

        if (!treffer.length) return null;

        treffer.sort(function (a, b) {
            if (b.z !== a.z) return b.z - a.z;
            return a.area - b.area;
        });

        return treffer[0].kachel;
    }

    function hoverLeaveTimerLoeschen() {
        if (!hoverLeaveTimer) return;
        clearTimeout(hoverLeaveTimer);
        hoverLeaveTimer = null;
    }

    function hoverSettleBeenden() {
        kacheln.forEach(function (kachel) {
            kachel.classList.remove('is-hover-settle');
        });
    }

    function hoverZielSetzen(ziel) {
        hoverLeaveTimerLoeschen();

        var vorherHover = null;
        kacheln.forEach(function (kachel) {
            if (kachel.classList.contains('is-hover')) vorherHover = kachel;
            kachel.classList.toggle('is-hover', kachel === ziel);
            if (ziel && kachel !== ziel) kachel.classList.remove('is-hover-settle');
        });

        root.classList.toggle('is-hover-pause', !!ziel);

        if (ziel) {
            hoverSettleBeenden();
            ziel.classList.remove('is-hover-settle');
            hoverPause = true;
            laufbandStoppen();
            zustandAktualisieren();
            return;
        }

        if (vorherHover) {
            vorherHover.classList.add('is-hover-settle');
        }

        hoverPause = false;
        zustandAktualisieren();

        if (!dragAktiv && !animiert) {
            hoverLeaveTimer = setTimeout(function () {
                hoverLeaveTimer = null;
                hoverSettleBeenden();
                zustandAktualisieren();
                if (!hoverPause && !dragAktiv && !animiert) {
                    laufbandStarten();
                }
            }, HOVER_LEAVE_MS);
        }
    }

    function hoverAusEvent(e) {
        if (dragAktiv) return;
        if (e.pointerType && e.pointerType !== 'mouse') return;
        hoverZielSetzen(kachelUnterPunkt(e.clientX, e.clientY));
    }

    buehne.addEventListener('pointermove', hoverAusEvent);
    buehne.addEventListener('mousemove', hoverAusEvent);

    function hoverVerlassen() {
        hoverZielSetzen(null);
    }

    buehne.addEventListener('pointerleave', hoverVerlassen);
    buehne.addEventListener('mouseleave', hoverVerlassen);

    buehne.addEventListener('pointerdown', dragStart);
    buehne.addEventListener('pointermove', dragMove);
    buehne.addEventListener('pointerup', dragEnd);
    buehne.addEventListener('pointercancel', dragEnd);

    root.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowLeft') { e.preventDefault(); zurueck(); }
        if (e.key === 'ArrowRight') { e.preventDefault(); weiter(); }
    });

    window.addEventListener('resize', function () {
        geometrieAnwenden();
        zustandAktualisieren();
    });

    window.addEventListener('load', function () {
        geometrieAnwenden();
        zustandAktualisieren();
    });

    geometrieAnwenden();
    punkteBauen();
    zustandAktualisieren();
    laufbandStarten();
})();
