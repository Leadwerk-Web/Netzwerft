/**
 * Hero / Section circuit traces — technical network / PCB schematic.
 * Supports #hero-particles and any canvas[data-circuit-traces].
 */
(function () {
    'use strict';

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function initCircuitCanvas(canvas) {
        if (!canvas || canvas.dataset.circuitInit === '1') return;
        canvas.dataset.circuitInit = '1';

        var ctx = canvas.getContext('2d');
        if (!ctx) return;

        var dpr = 1;
        var w = 0;
        var h = 0;
        var traces = [];
        var pads = [];
        var pulses = [];
        var dashOffset = 0;
        var raf = null;
        var visible = true;
        var lastTs = 0;

        function R(n) {
            return Math.min(22, Math.max(10, n));
        }

        /** Orthogonal polyline with rounded corners. pts: [{x,y}, ...] */
        function strokeTrace(pts, alpha, dashed) {
            if (!pts || pts.length < 2) return;

            var r = R(w * 0.012);
            ctx.strokeStyle = 'rgba(147, 197, 253, ' + alpha + ')';
            ctx.lineWidth = 1.3;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            if (dashed) {
                ctx.setLineDash([5, 8]);
                ctx.lineDashOffset = -dashOffset;
            } else {
                ctx.setLineDash([]);
                ctx.lineDashOffset = 0;
            }

            ctx.beginPath();
            ctx.moveTo(pts[0].x, pts[0].y);

            for (var i = 1; i < pts.length - 1; i++) {
                var prev = pts[i - 1];
                var cur = pts[i];
                var next = pts[i + 1];

                var dx1 = cur.x - prev.x;
                var dy1 = cur.y - prev.y;
                var dx2 = next.x - cur.x;
                var dy2 = next.y - cur.y;
                var len1 = Math.sqrt(dx1 * dx1 + dy1 * dy1) || 1;
                var len2 = Math.sqrt(dx2 * dx2 + dy2 * dy2) || 1;
                var rr = Math.min(r, len1 * 0.45, len2 * 0.45);

                ctx.lineTo(cur.x - (dx1 / len1) * rr, cur.y - (dy1 / len1) * rr);
                ctx.quadraticCurveTo(cur.x, cur.y, cur.x + (dx2 / len2) * rr, cur.y + (dy2 / len2) * rr);
            }

            var last = pts[pts.length - 1];
            ctx.lineTo(last.x, last.y);
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.lineDashOffset = 0;
        }

        function drawPad(x, y, hub) {
            var s = hub ? 4.5 : 2.8;
            ctx.beginPath();
            if (typeof ctx.roundRect === 'function') {
                ctx.roundRect(x - s, y - s, s * 2, s * 2, hub ? 2.5 : 1.5);
            } else {
                ctx.rect(x - s, y - s, s * 2, s * 2);
            }
            ctx.fillStyle = hub
                ? 'rgba(30, 136, 229, 0.55)'
                : 'rgba(100, 181, 246, 0.4)';
            ctx.fill();

            if (hub) {
                ctx.beginPath();
                ctx.arc(x, y, s + 5, 0, Math.PI * 2);
                ctx.strokeStyle = 'rgba(96, 165, 250, 0.28)';
                ctx.lineWidth = 1.1;
                ctx.stroke();
            }
        }

        function drawVia(x, y) {
            ctx.beginPath();
            ctx.arc(x, y, 2.2, 0, Math.PI * 2);
            ctx.strokeStyle = 'rgba(147, 197, 253, 0.35)';
            ctx.lineWidth = 1.1;
            ctx.stroke();
            ctx.beginPath();
            ctx.arc(x, y, 0.9, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(186, 230, 253, 0.45)';
            ctx.fill();
        }

        function drawChip(x, y, bw, bh) {
            ctx.strokeStyle = 'rgba(147, 197, 253, 0.22)';
            ctx.lineWidth = 1.15;
            ctx.beginPath();
            if (typeof ctx.roundRect === 'function') {
                ctx.roundRect(x, y, bw, bh, 4);
            } else {
                ctx.rect(x, y, bw, bh);
            }
            ctx.stroke();

            var pins = 4;
            var gap = bw / (pins + 1);
            for (var i = 1; i <= pins; i++) {
                var px = x + gap * i;
                ctx.beginPath();
                ctx.moveTo(px, y);
                ctx.lineTo(px, y - 6);
                ctx.moveTo(px, y + bh);
                ctx.lineTo(px, y + bh + 6);
                ctx.stroke();
            }
        }

        function buildLayout() {
            traces = [];
            pads = [];
            pulses = [];

            traces.push({
                pts: [
                    { x: -10, y: h * 0.36 },
                    { x: w * 0.14, y: h * 0.36 },
                    { x: w * 0.14, y: h * 0.12 },
                    { x: w * 0.22, y: h * 0.12 }
                ],
                alpha: 0.3,
                dashed: true
            });
            traces.push({
                pts: [
                    { x: -10, y: h * 0.22 },
                    { x: w * 0.1, y: h * 0.22 },
                    { x: w * 0.1, y: h * 0.58 },
                    { x: w * 0.2, y: h * 0.58 },
                    { x: w * 0.2, y: h * 0.78 }
                ],
                alpha: 0.26,
                dashed: false
            });
            traces.push({
                pts: [
                    { x: w * 0.08, y: h * 1.05 },
                    { x: w * 0.08, y: h * 0.72 },
                    { x: w * 0.18, y: h * 0.72 }
                ],
                alpha: 0.22,
                dashed: true
            });

            pads.push({ x: w * 0.22, y: h * 0.12, hub: true });
            pads.push({ x: w * 0.14, y: h * 0.36, hub: false });
            pads.push({ x: w * 0.2, y: h * 0.58, via: true });
            pads.push({ x: w * 0.18, y: h * 0.72, via: true });

            traces.push({
                pts: [
                    { x: w + 10, y: h * 0.28 },
                    { x: w * 0.86, y: h * 0.28 },
                    { x: w * 0.86, y: h * 0.52 },
                    { x: w * 0.72, y: h * 0.52 },
                    { x: w * 0.72, y: h * 0.78 }
                ],
                alpha: 0.28,
                dashed: false
            });
            traces.push({
                pts: [
                    { x: w + 10, y: h * 0.62 },
                    { x: w * 0.9, y: h * 0.62 },
                    { x: w * 0.9, y: h * 0.88 },
                    { x: w * 0.78, y: h * 0.88 }
                ],
                alpha: 0.24,
                dashed: true
            });
            traces.push({
                pts: [
                    { x: w * 0.94, y: -10 },
                    { x: w * 0.94, y: h * 0.14 },
                    { x: w * 0.82, y: h * 0.14 },
                    { x: w * 0.82, y: h * 0.06 },
                    { x: w * 0.7, y: h * 0.06 }
                ],
                alpha: 0.2,
                dashed: false
            });

            pads.push({ x: w * 0.7, y: h * 0.06, hub: true });
            pads.push({ x: w * 0.72, y: h * 0.42, hub: false });
            pads.push({ x: w * 0.78, y: h * 0.58, via: true });
            pads.push({ x: w * 0.82, y: h * 0.14, via: true });

            pads.push({ chip: true, x: w * 0.04, y: h * 0.4, bw: w * 0.07, bh: h * 0.045 });
            pads.push({ chip: true, x: w * 0.86, y: h * 0.7, bw: w * 0.08, bh: h * 0.05 });

            pulses.push({ trace: 0, t: 0.15, speed: 0.00018 });
            pulses.push({ trace: 3, t: 0.55, speed: 0.00014 });
            pulses.push({ trace: 1, t: 0.3, speed: 0.0002 });
        }

        function pointOnTrace(pts, t) {
            if (!pts || pts.length < 2) return { x: 0, y: 0 };
            var segs = [];
            var total = 0;
            var i;
            for (i = 0; i < pts.length - 1; i++) {
                var dx = pts[i + 1].x - pts[i].x;
                var dy = pts[i + 1].y - pts[i].y;
                var len = Math.sqrt(dx * dx + dy * dy);
                segs.push(len);
                total += len;
            }
            var dist = Math.max(0, Math.min(1, t)) * total;
            for (i = 0; i < segs.length; i++) {
                if (dist <= segs[i] || i === segs.length - 1) {
                    var u = segs[i] < 0.001 ? 0 : dist / segs[i];
                    return {
                        x: pts[i].x + (pts[i + 1].x - pts[i].x) * u,
                        y: pts[i].y + (pts[i + 1].y - pts[i].y) * u
                    };
                }
                dist -= segs[i];
            }
            return pts[pts.length - 1];
        }

        function resize() {
            var rect = canvas.parentElement
                ? canvas.parentElement.getBoundingClientRect()
                : canvas.getBoundingClientRect();

            dpr = Math.min(window.devicePixelRatio || 1, 2);
            w = Math.max(1, Math.floor(rect.width));
            h = Math.max(1, Math.floor(rect.height));

            canvas.width = Math.floor(w * dpr);
            canvas.height = Math.floor(h * dpr);
            canvas.style.width = w + 'px';
            canvas.style.height = h + 'px';
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

            buildLayout();
        }

        function step(dt) {
            dashOffset = (dashOffset + dt * 0.022) % 13;
            for (var i = 0; i < pulses.length; i++) {
                var p = pulses[i];
                p.t += p.speed * dt;
                if (p.t > 1) p.t = 0;
            }
        }

        function draw() {
            ctx.clearRect(0, 0, w, h);

            var i;
            for (i = 0; i < traces.length; i++) {
                strokeTrace(traces[i].pts, traces[i].alpha, traces[i].dashed);
            }

            for (i = 0; i < pads.length; i++) {
                var p = pads[i];
                if (p.chip) drawChip(p.x, p.y, p.bw, p.bh);
                else if (p.via) drawVia(p.x, p.y);
                else drawPad(p.x, p.y, p.hub);
            }

            for (i = 0; i < pulses.length; i++) {
                var pulse = pulses[i];
                var tr = traces[pulse.trace];
                if (!tr) continue;
                var pt = pointOnTrace(tr.pts, pulse.t);
                ctx.beginPath();
                ctx.arc(pt.x, pt.y, 2.2, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(125, 211, 252, 0.8)';
                ctx.fill();
            }
        }

        function frame(ts) {
            if (!visible) {
                raf = null;
                return;
            }

            if (!lastTs) lastTs = ts;
            var dt = Math.min(40, ts - lastTs);
            lastTs = ts;

            if (!reducedMotion) step(dt);
            draw();
            raf = window.requestAnimationFrame(frame);
        }

        function start() {
            if (raf != null) return;
            lastTs = 0;
            raf = window.requestAnimationFrame(frame);
        }

        function stop() {
            if (raf != null) {
                window.cancelAnimationFrame(raf);
                raf = null;
            }
            lastTs = 0;
        }

        resize();
        draw();

        if (!reducedMotion) {
            var host =
                canvas.closest('section') ||
                document.getElementById('hero') ||
                canvas.parentElement;
            if ('IntersectionObserver' in window && host) {
                var io = new IntersectionObserver(
                    function (entries) {
                        visible = entries[0] && entries[0].isIntersecting;
                        if (visible) start();
                        else stop();
                    },
                    { threshold: 0.05 }
                );
                io.observe(host);
            } else {
                start();
            }
        }

        var resizeTimer = null;
        window.addEventListener('resize', function () {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(function () {
                resize();
                draw();
            }, 120);
        });
    }

    function boot() {
        var nodes = document.querySelectorAll('#hero-particles, canvas[data-circuit-traces]');
        for (var i = 0; i < nodes.length; i++) {
            initCircuitCanvas(nodes[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
