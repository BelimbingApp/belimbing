// shift-builder.js — Interactive shift builder: clock dial with draggable
// shift/break/grace handles. Integrated with Livewire 3 via $wire from Alpine.js.

// ── Shared helpers ───────────────────────────────────────────────────────────

function sbHhmmToMins(hhmm) {
    if (!hhmm || typeof hhmm !== 'string') return 0
    const parts = hhmm.split(':')
    const h = parseInt(parts[0], 10) || 0
    const m = parseInt(parts[1], 10) || 0
    return ((h * 60 + m) % 1440 + 1440) % 1440
}

function sbMinsToHhmm(mins) {
    const m = ((mins % 1440) + 1440) % 1440
    return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`
}

function sbFmtDur(mins) {
    const h = Math.floor(mins / 60)
    const m = mins % 60
    return h === 0 ? `${m}m` : m === 0 ? `${h}h` : `${h}h ${m}m`
}

function sbSpan(start, end) {
    const s = end - start
    return s <= 0 ? s + 1440 : s
}

// Noon (12:00) sits at top (12 o'clock). Midnight is at the bottom.
function sbTimeToRad(mins) {
    return ((mins - 720) / 1440) * Math.PI * 2 - Math.PI / 2
}

function sbPolar(cx, cy, r, mins) {
    const a = sbTimeToRad(mins)
    return { x: cx + r * Math.cos(a), y: cy + r * Math.sin(a) }
}

function sbArcPath(cx, cy, r, start, end) {
    const span = sbSpan(start, end)
    if (span <= 0) return ''
    const p1 = sbPolar(cx, cy, r, start)
    const p2 = sbPolar(cx, cy, r, end)
    const large = span > 720 ? 1 : 0
    return `M ${p1.x} ${p1.y} A ${r} ${r} 0 ${large} 1 ${p2.x} ${p2.y}`
}

const SVG_NS = 'http://www.w3.org/2000/svg'

function mkSvg(tag, attrs, parent) {
    const el = document.createElementNS(SVG_NS, tag)
    if (attrs) for (const [k, v] of Object.entries(attrs)) el.setAttributeNS(null, k, v)
    if (parent) parent.appendChild(el)
    return el
}

function sa(el, attrs) {
    for (const [k, v] of Object.entries(attrs)) el.setAttributeNS(null, k, String(v ?? ''))
}

// ── ShiftDial ────────────────────────────────────────────────────────────────

class ShiftDial {
    constructor(container, getState, onUpdate, onDragEnd, size = 320) {
        this.container = container
        this.getState = getState
        this.onUpdate = onUpdate
        this.onDragEnd = onDragEnd
        this.sz = size
        this.dragging = null
        this.hovered = null
        this.d = {}
        this._build()
    }

    _dim() {
        if (!this._dims) {
            const sz = this.sz
            const R = sz * 0.35
            const rW = sz * 0.05
            const ts = R + rW / 2 + sz * 0.012
            this._dims = {
                cx: sz / 2, cy: sz / 2,
                R, rW,
                Rt: sz * 0.43,
                tW: sz * 0.05,
                ts,
                dr: sz * 0.49,
                lr: R - rW / 2 - sz * 0.055,
            }
        }
        return this._dims
    }

    _mkHandle(svg, key, { dotR = 3.2, notchBgW = 2.5, hidden = false, color = 'var(--color-accent,#b5622f)' } = {}) {
        const sz = this.sz
        const hg = mkSvg('g', { style: `cursor:grab;touch-action:none${hidden ? ';display:none' : ''}` }, svg)
        const hit = mkSvg('line', { stroke: 'transparent', 'stroke-width': sz * 0.06 }, hg)
        const notchBg = mkSvg('line', { stroke: 'var(--color-surface-card,#faf9f5)', 'stroke-width': notchBgW, 'stroke-linecap': 'butt' }, hg)
        const notchFg = mkSvg('line', { stroke: color, 'stroke-width': 1, 'stroke-linecap': 'butt' }, hg)
        const dot = mkSvg('circle', { r: dotR, fill: 'var(--color-surface-card,#faf9f5)', stroke: color, 'stroke-width': 1.5 }, hg)
        const tg = mkSvg('g', { style: 'pointer-events:none;display:none' }, hg)
        mkSvg('rect', { x: -sz * 0.08, y: -sz * 0.026, width: sz * 0.16, height: sz * 0.052, rx: sz * 0.026, fill: 'var(--color-ink,#2c2418)' }, tg)
        const tText = mkSvg('text', { x: 0, y: sz * 0.014, 'text-anchor': 'middle', 'font-size': sz * 0.038, 'font-weight': 600, fill: 'var(--color-surface-card,#faf9f5)', 'font-variant-numeric': 'tabular-nums', 'font-family': 'inherit' }, tg)
        hg.addEventListener('pointerdown', (e) => { e.preventDefault(); hg.setPointerCapture(e.pointerId); this.dragging = key; this.draw() })
        hg.addEventListener('pointerenter', () => { this.hovered = key; this.draw() })
        hg.addEventListener('pointerleave', () => { if (this.hovered === key) { this.hovered = null; this.draw() } })
        return { hg, hit, notchBg, notchFg, dot, tg, tText }
    }

    _build() {
        this.container.innerHTML = ''
        const { sz } = this
        const { cx, cy, R, rW, Rt, tW, ts, dr, lr } = this._dim()
        const mL = sz * 0.014

        const svg = mkSvg('svg', {
            width: sz, height: sz,
            viewBox: `0 0 ${sz} ${sz}`,
            style: 'display:block;overflow:visible;user-select:none;touch-action:none',
        }, this.container)
        this.svg = svg

        // Defs
        const defs = mkSvg('defs', {}, svg)
        // Terracotta stripe — before-boundary (graceIn, outBefore)
        const patBef = mkSvg('pattern', { id: 'sbDialTolStripeBef', patternUnits: 'userSpaceOnUse', width: 6, height: 6, patternTransform: 'rotate(45)' }, defs)
        mkSvg('rect', { width: 6, height: 6, fill: 'rgba(181,98,47,0.10)' }, patBef)
        mkSvg('line', { x1: 0, y1: 0, x2: 0, y2: 6, stroke: 'rgba(181,98,47,0.50)', 'stroke-width': 1.5 }, patBef)
        // Olive stripe — after-boundary (inAfter, graceOut)
        const patAft = mkSvg('pattern', { id: 'sbDialTolStripeAft', patternUnits: 'userSpaceOnUse', width: 6, height: 6, patternTransform: 'rotate(45)' }, defs)
        mkSvg('rect', { width: 6, height: 6, fill: 'rgba(122,117,72,0.10)' }, patAft)
        mkSvg('line', { x1: 0, y1: 0, x2: 0, y2: 6, stroke: 'rgba(122,117,72,0.55)', 'stroke-width': 1.5 }, patAft)
        const clip = mkSvg('clipPath', { id: 'sbDialClip' }, defs)
        mkSvg('circle', { cx, cy, r: dr }, clip)

        // Disc face: day (top) / night (bottom), hard split at equator
        const discG = mkSvg('g', { 'clip-path': 'url(#sbDialClip)' }, svg)
        mkSvg('rect', { x: cx - dr, y: cy - dr, width: dr * 2, height: dr, fill: 'var(--sb-dial-day, #faf9f5)' }, discG)
        mkSvg('rect', { x: cx - dr, y: cy, width: dr * 2, height: dr, fill: 'var(--sb-dial-night, #dfd9cf)' }, discG)
        mkSvg('line', { x1: cx - dr, y1: cy, x2: cx + dr, y2: cy, stroke: 'var(--color-border-default, #ddd8cf)', 'stroke-width': 1 }, svg)
        mkSvg('circle', { cx, cy, r: dr, fill: 'none', stroke: 'var(--color-border-default, #ddd8cf)', 'stroke-width': 1 }, svg)

        // Hour indicators — major (every 3h) carry their number, minor get a tick
        for (let h = 0; h < 24; h++) {
            const mins = h * 60
            if (h % 3 === 0) {
                const p = sbPolar(cx, cy, lr, mins)
                const el = mkSvg('text', {
                    x: p.x, y: p.y + sz * 0.012,
                    'text-anchor': 'middle',
                    'font-size': sz * 0.034,
                    'font-weight': 600,
                    fill: 'var(--color-muted, #6b6057)',
                    'font-variant-numeric': 'tabular-nums',
                    'font-family': 'inherit',
                    'letter-spacing': '0.04em',
                }, svg)
                el.textContent = String(h).padStart(2, '0')
            } else {
                const inner = sbPolar(cx, cy, ts, mins)
                const outer = sbPolar(cx, cy, ts + mL, mins)
                mkSvg('line', {
                    x1: inner.x, y1: inner.y, x2: outer.x, y2: outer.y,
                    stroke: 'var(--color-muted, #6b6057)', 'stroke-width': 0.7, 'stroke-opacity': 0.4,
                }, svg)
            }
        }

        // Dynamic: four tolerance arcs on the outer ring (Rt).
        // Terracotta = outer legs (graceIn before start, graceOut after end — outside the shift block).
        // Olive      = inner legs (inAfter after start, outBefore before end — inside the shift block).
        this.d.tolIn        = mkSvg('path', { fill: 'none', stroke: 'url(#sbDialTolStripeBef)', 'stroke-width': tW, 'stroke-linecap': 'round' }, svg)
        this.d.tolInAfter   = mkSvg('path', { fill: 'none', stroke: 'url(#sbDialTolStripeAft)', 'stroke-width': tW, 'stroke-linecap': 'round' }, svg)
        this.d.tolOutBefore = mkSvg('path', { fill: 'none', stroke: 'url(#sbDialTolStripeAft)', 'stroke-width': tW, 'stroke-linecap': 'round' }, svg)
        this.d.tolOut       = mkSvg('path', { fill: 'none', stroke: 'url(#sbDialTolStripeBef)', 'stroke-width': tW, 'stroke-linecap': 'round' }, svg)

        // Work / break arc segments
        this.d.w1 = mkSvg('path', { fill: 'none', stroke: 'var(--color-accent, #b5622f)', 'stroke-width': rW, 'stroke-linecap': 'butt' }, svg)
        this.d.br = mkSvg('path', { fill: 'none', stroke: 'var(--sb-break, #7a7548)', 'stroke-width': rW, 'stroke-linecap': 'butt' }, svg)
        this.d.w2 = mkSvg('path', { fill: 'none', stroke: 'var(--color-accent, #b5622f)', 'stroke-width': rW, 'stroke-linecap': 'butt' }, svg)
        // Tea break — same ring width as main break, solid olive covers the terracotta arc beneath
        this.d.br2 = mkSvg('path', { fill: 'none', stroke: 'var(--sb-tea,#5c7f99)', 'stroke-width': rW, 'stroke-linecap': 'butt', display: 'none' }, svg)

        // Separator hairlines between segments
        this.d.seps = [0, 1, 2, 3].map(() => mkSvg('line', { stroke: 'var(--color-surface-card, #faf9f5)', 'stroke-width': 1 }, svg))

        // Center readout
        this.d.dur = mkSvg('text', {
            x: cx, y: cy - sz * 0.005, 'text-anchor': 'middle',
            'font-size': sz * 0.105, 'font-weight': 500, fill: 'var(--color-ink, #2c2418)',
            'font-variant-numeric': 'tabular-nums', 'letter-spacing': '-0.02em', 'font-family': 'inherit',
        }, svg)
        const lbl = mkSvg('text', {
            x: cx, y: cy + sz * 0.07, 'text-anchor': 'middle',
            'font-size': sz * 0.034, 'font-weight': 600, fill: 'var(--color-muted, #6b6057)',
            'letter-spacing': '0.12em', 'font-family': 'inherit',
        }, svg)
        lbl.textContent = 'paid work'

        // Tea break handles — smaller notch+dot, hidden until break2 enabled
        const teaColor = 'var(--sb-tea,#5c7f99)'
        this.d.teaHandles = {}
        for (const key of ['break2Start', 'break2End']) {
            this.d.teaHandles[key] = this._mkHandle(svg, key, { dotR: 2.5, notchBgW: 2, hidden: true, color: teaColor })
        }

        // Main handles: shiftStart, shiftEnd, breakStart, breakEnd
        this.d.handles = {}
        for (const { key, work } of [
            { key: 'shiftStart', work: true }, { key: 'shiftEnd', work: true },
            { key: 'breakStart', work: false }, { key: 'breakEnd', work: false },
        ]) {
            this.d.handles[key] = this._mkHandle(svg, key, { color: work ? 'var(--color-accent,#b5622f)' : 'var(--sb-break,#7a7548)' })
        }

        // Grace handles on the Rt (tolerance) ring
        this.d.gHandles = {}
        for (const { key, color } of [
            { key: 'graceIn',   color: 'var(--color-accent,#b5622f)' },
            { key: 'inAfter',   color: 'var(--sb-break,#7a7548)' },
            { key: 'outBefore', color: 'var(--sb-break,#7a7548)' },
            { key: 'graceOut',  color: 'var(--color-accent,#b5622f)' },
        ]) {
            this.d.gHandles[key] = this._mkHandle(svg, key, { color })
        }

        this._onMove = (e) => {
            if (!this.dragging) return
            const s = { ...this.getState() }
            let t = this._pxToTime(e.clientX, e.clientY)
            const snap = s.snap || 5
            t = Math.round(t / snap) * snap % 1440
            const clampSnap = (v, max) => Math.max(0, Math.min(max, Math.round(v / snap) * snap))
            if (this.dragging === 'graceIn') {
                s.graceIn   = clampSnap(((s.shiftStart - t) + 1440) % 1440, 120)
            } else if (this.dragging === 'inAfter') {
                s.inAfter   = clampSnap(((t - s.shiftStart) + 1440) % 1440, 60)
            } else if (this.dragging === 'outBefore') {
                s.outBefore = clampSnap(((s.shiftEnd - t) + 1440) % 1440, 60)
            } else if (this.dragging === 'graceOut') {
                s.graceOut  = clampSnap(((t - s.shiftEnd) + 1440) % 1440, 240)
            } else {
                s[this.dragging] = t
            }
            this.onUpdate(s)
            this.draw()
        }
        this._onUp = () => {
            if (this.dragging) {
                this.dragging = null
                this.onDragEnd(this.getState())
                this.draw()
            }
        }
        window.addEventListener('pointermove', this._onMove)
        window.addEventListener('pointerup', this._onUp)
        window.addEventListener('pointercancel', this._onUp)
    }

    _pxToTime(clientX, clientY) {
        const { cx, cy } = this._dim()
        const rect = this.svg.getBoundingClientRect()
        const sx = rect.width / this.sz
        const x = (clientX - rect.left) / sx - cx
        const y = (clientY - rect.top) / sx - cy
        const a = Math.atan2(y, x) + Math.PI / 2
        const norm = ((a % (Math.PI * 2)) + Math.PI * 2) % (Math.PI * 2)
        return ((norm / (Math.PI * 2)) * 1440 + 720) % 1440
    }

    draw() {
        const s = this.getState()
        const { cx, cy, R, rW, Rt, tW } = this._dim()
        const { sz } = this

        // Four tolerance arcs on the outer ring
        const tolBef         = ((s.shiftStart - s.graceIn) % 1440 + 1440) % 1440
        const tolInAfterEnd  = (s.shiftStart + (s.inAfter || 0)) % 1440
        const tolOutBeforeStart = ((s.shiftEnd - (s.outBefore || 0)) % 1440 + 1440) % 1440
        const tolAft         = (s.shiftEnd + s.graceOut) % 1440

        sa(this.d.tolIn,       { d: s.graceIn > 0   ? sbArcPath(cx, cy, Rt, tolBef,            s.shiftStart) : '' })
        sa(this.d.tolInAfter,  { d: s.inAfter > 0   ? sbArcPath(cx, cy, Rt, s.shiftStart,      tolInAfterEnd) : '' })
        sa(this.d.tolOutBefore,{ d: s.outBefore > 0 ? sbArcPath(cx, cy, Rt, tolOutBeforeStart, s.shiftEnd) : '' })
        sa(this.d.tolOut,      { d: s.graceOut > 0  ? sbArcPath(cx, cy, Rt, s.shiftEnd,        tolAft) : '' })

        // Segment arcs
        sa(this.d.w1, { d: sbArcPath(cx, cy, R, s.shiftStart, s.breakStart) })
        sa(this.d.br, { d: sbArcPath(cx, cy, R, s.breakStart, s.breakEnd) })
        sa(this.d.w2, { d: sbArcPath(cx, cy, R, s.breakEnd, s.shiftEnd) })

        // Separators
        const times = [s.shiftStart, s.breakStart, s.breakEnd, s.shiftEnd]
        this.d.seps.forEach((sep, i) => {
            const p1 = sbPolar(cx, cy, R - rW / 2, times[i])
            const p2 = sbPolar(cx, cy, R + rW / 2, times[i])
            sa(sep, { x1: p1.x, y1: p1.y, x2: p2.x, y2: p2.y })
        })

        // Center
        // Only unpaid breaks are deducted from paid work.
        const totalBreak = (s.break1Paid ? 0 : sbSpan(s.breakStart, s.breakEnd))
            + (s.hasBreak2 && !s.break2Paid ? sbSpan(s.break2Start, s.break2End) : 0)
        const paid = Math.max(0, sbSpan(s.shiftStart, s.shiftEnd) - totalBreak)
        this.d.dur.textContent = sbFmtDur(paid)

        // Handles
        for (const { key, work } of [
            { key: 'shiftStart', work: true }, { key: 'shiftEnd', work: true },
            { key: 'breakStart', work: false }, { key: 'breakEnd', work: false },
        ]) {
            const h = this.d.handles[key]
            const mins = s[key]
            const color = work ? 'var(--color-accent,#b5622f)' : 'var(--sb-break,#7a7548)'
            const active = this.dragging === key
            const shown = active || this.hovered === key
            const pI = sbPolar(cx, cy, R - rW / 2 - 2, mins)
            const pO = sbPolar(cx, cy, R + rW / 2 + 2, mins)
            const pD = sbPolar(cx, cy, R, mins)
            const pL = sbPolar(cx, cy, R + rW / 2 + sz * 0.055, mins)
            sa(h.hit, { x1: pI.x, y1: pI.y, x2: pO.x, y2: pO.y })
            sa(h.notchBg, { x1: pI.x, y1: pI.y, x2: pO.x, y2: pO.y, 'stroke-width': active ? 3 : 2.5 })
            sa(h.notchFg, { x1: pI.x, y1: pI.y, x2: pO.x, y2: pO.y, stroke: color })
            sa(h.dot, { cx: pD.x, cy: pD.y, r: active ? 4 : 3.2, stroke: color })
            h.tg.style.display = shown ? '' : 'none'
            h.tg.setAttribute('transform', `translate(${pL.x},${pL.y})`)
            h.tText.textContent = sbMinsToHhmm(mins)
        }

        // Grace handles on Rt ring
        const graceHandlePositions = [
            { key: 'graceIn',   mins: ((s.shiftStart - s.graceIn) + 1440) % 1440,             val: s.graceIn,   color: 'var(--color-accent,#b5622f)', show: s.graceIn > 0 },
            { key: 'inAfter',   mins: (s.shiftStart + (s.inAfter || 0)) % 1440,               val: s.inAfter,   color: 'var(--sb-break,#7a7548)',     show: s.inAfter > 0 },
            { key: 'outBefore', mins: ((s.shiftEnd - (s.outBefore || 0)) + 1440) % 1440,      val: s.outBefore, color: 'var(--sb-break,#7a7548)',     show: s.outBefore > 0 },
            { key: 'graceOut',  mins: (s.shiftEnd + s.graceOut) % 1440,                       val: s.graceOut,  color: 'var(--color-accent,#b5622f)', show: s.graceOut > 0 },
        ]
        for (const { key, mins, val, color, show } of graceHandlePositions) {
            const h = this.d.gHandles[key]
            h.hg.style.display = show ? '' : 'none'
            if (!show) continue
            const active = this.dragging === key
            const shown  = active || this.hovered === key
            const pI = sbPolar(cx, cy, Rt - tW / 2 - 2, mins)
            const pO = sbPolar(cx, cy, Rt + tW / 2 + 2, mins)
            const pD = sbPolar(cx, cy, Rt, mins)
            const pL = sbPolar(cx, cy, Rt + tW / 2 + sz * 0.055, mins)
            sa(h.hit, { x1: pI.x, y1: pI.y, x2: pO.x, y2: pO.y })
            sa(h.notchBg, { x1: pI.x, y1: pI.y, x2: pO.x, y2: pO.y, 'stroke-width': active ? 3 : 2.5 })
            sa(h.notchFg, { x1: pI.x, y1: pI.y, x2: pO.x, y2: pO.y, stroke: color })
            sa(h.dot, { cx: pD.x, cy: pD.y, r: active ? 4 : 3.2, stroke: color })
            h.tg.style.display = shown ? '' : 'none'
            h.tg.setAttribute('transform', `translate(${pL.x},${pL.y})`)
            h.tText.textContent = sbFmtDur(val)
        }

        // Tea break: arc + draggable handles
        if (s.hasBreak2) {
            sa(this.d.br2, { d: sbArcPath(cx, cy, R, s.break2Start, s.break2End), display: '' })
            for (const [key, h] of Object.entries(this.d.teaHandles)) {
                const mins   = s[key]
                const active = this.dragging === key
                const shown  = active || this.hovered === key
                h.hg.style.display = ''
                const pI = sbPolar(cx, cy, R - rW / 2 - 2, mins)
                const pO = sbPolar(cx, cy, R + rW / 2 + 2, mins)
                const pD = sbPolar(cx, cy, R, mins)
                const pL = sbPolar(cx, cy, R + rW / 2 + sz * 0.055, mins)
                sa(h.hit,     { x1: pI.x, y1: pI.y, x2: pO.x, y2: pO.y })
                sa(h.notchBg, { x1: pI.x, y1: pI.y, x2: pO.x, y2: pO.y, 'stroke-width': active ? 2.5 : 2 })
                sa(h.notchFg, { x1: pI.x, y1: pI.y, x2: pO.x, y2: pO.y })
                sa(h.dot,     { cx: pD.x, cy: pD.y, r: active ? 3.2 : 2.5 })
                h.tg.style.display = shown ? '' : 'none'
                h.tg.setAttribute('transform', `translate(${pL.x},${pL.y})`)
                h.tText.textContent = sbMinsToHhmm(mins)
            }
        } else {
            sa(this.d.br2, { display: 'none' })
            for (const h of Object.values(this.d.teaHandles)) h.hg.style.display = 'none'
        }
    }

    destroy() {
        window.removeEventListener('pointermove', this._onMove)
        window.removeEventListener('pointerup', this._onUp)
        window.removeEventListener('pointercancel', this._onUp)
    }
}

// ── Alpine component ─────────────────────────────────────────────────────────

document.addEventListener('alpine:init', () => {
    Alpine.data('shiftBuilder', () => ({
        shiftStart: 480,
        shiftEnd: 1020,
        breakStart: 720,
        breakEnd: 780,
        graceIn: 60,      // shiftInWindowBeforeMinutes
        inAfter: 15,      // shiftInWindowAfterMinutes
        outBefore: 15,    // shiftOutWindowBeforeMinutes
        graceOut: 120,    // shiftOutWindowAfterMinutes
        break2Start: 0,
        break2End: 0,
        hasBreak2: false,
        break1Paid: false,
        break2Paid: false,
        snap: 5,
        sbHelpOpen: false,

        _dial: null,
        _fromWire: false,
        _redrawTimer: null,

        get _state() {
            return {
                shiftStart: this.shiftStart,
                shiftEnd: this.shiftEnd,
                breakStart: this.breakStart,
                breakEnd: this.breakEnd,
                break2Start: this.break2Start,
                break2End: this.break2End,
                hasBreak2: this.hasBreak2,
                break1Paid: this.break1Paid,
                break2Paid: this.break2Paid,
                graceIn: this.graceIn,
                inAfter: this.inAfter,
                outBefore: this.outBefore,
                graceOut: this.graceOut,
                snap: this.snap,
            }
        },

        get totalBreakMins() {
            // Paid breaks count as paid time, so only unpaid breaks are deducted.
            return (this.break1Paid ? 0 : sbSpan(this.breakStart, this.breakEnd))
                + (this.hasBreak2 && !this.break2Paid ? sbSpan(this.break2Start, this.break2End) : 0)
        },
        get paidWork() {
            return Math.max(0, sbSpan(this.shiftStart, this.shiftEnd) - this.totalBreakMins)
        },
        get shiftDurStr()   { return sbFmtDur(sbSpan(this.shiftStart, this.shiftEnd)) },
        get break1DurStr()  { return sbFmtDur(sbSpan(this.breakStart, this.breakEnd)) },
        get break2DurStr()  { return sbFmtDur(sbSpan(this.break2Start, this.break2End)) },
        get paidStr()       { return sbFmtDur(this.paidWork) },
        get shiftStartHhmm() { return sbMinsToHhmm(this.shiftStart) },
        get shiftEndHhmm()   { return sbMinsToHhmm(this.shiftEnd) },
        get breakStartHhmm()  { return sbMinsToHhmm(this.breakStart) },
        get breakEndHhmm()    { return sbMinsToHhmm(this.breakEnd) },
        get break2StartHhmm() { return sbMinsToHhmm(this.break2Start) },
        get break2EndHhmm()   { return sbMinsToHhmm(this.break2End) },
        get crossesMidnight() { return this.shiftEnd <= this.shiftStart },

        init() {
            this._readFromWire()

            // Watch Livewire for external changes (template selection, edit load)
            this.$watch(() => this.$wire.shiftStartsAt, v => {
                if (this._fromWire) return
                this.shiftStart = sbHhmmToMins(v || '08:00')
                this._scheduleRedraw()
            })
            this.$watch(() => this.$wire.shiftEndsAt, v => {
                if (this._fromWire) return
                this.shiftEnd = sbHhmmToMins(v || '17:00')
                this._scheduleRedraw()
            })
            this.$watch(() => this.$wire.shiftBreaks?.[0]?.starts_at, v => {
                if (this._fromWire || !v) return
                this.breakStart = sbHhmmToMins(v)
                this._scheduleRedraw()
            })
            this.$watch(() => this.$wire.shiftBreaks?.[0]?.ends_at, v => {
                if (this._fromWire || !v) return
                this.breakEnd = sbHhmmToMins(v)
                this._scheduleRedraw()
            })
            this.$watch(() => this.$wire.shiftBreaks?.[1]?.starts_at, v => {
                if (this._fromWire) return
                const ends = this.$wire.shiftBreaks?.[1]?.ends_at
                this.hasBreak2   = !!(v && ends)
                this.break2Start = v ? sbHhmmToMins(v) : 0
                this._scheduleRedraw()
            })
            this.$watch(() => this.$wire.shiftBreaks?.[1]?.ends_at, v => {
                if (this._fromWire) return
                const starts = this.$wire.shiftBreaks?.[1]?.starts_at
                this.hasBreak2 = !!(starts && v)
                this.break2End = v ? sbHhmmToMins(v) : 0
                this._scheduleRedraw()
            })
            this.$watch(() => this.$wire.shiftBreaks?.[0]?.paid, v => {
                if (this._fromWire) return
                this.break1Paid = !!v
                this.$wire.shiftExpectedWorkMinutes = String(this.paidWork)
                this._scheduleRedraw()
            })
            this.$watch(() => this.$wire.shiftBreaks?.[1]?.paid, v => {
                if (this._fromWire) return
                this.break2Paid = !!v
                this.$wire.shiftExpectedWorkMinutes = String(this.paidWork)
                this._scheduleRedraw()
            })
            this.$watch(() => this.$wire.shiftInWindowBeforeMinutes, v => {
                if (this._fromWire) return
                this.graceIn = parseInt(v) || 0
                this._scheduleRedraw()
            })
            this.$watch(() => this.$wire.shiftInWindowAfterMinutes, v => {
                if (this._fromWire) return
                this.inAfter = parseInt(v) || 0
                this._scheduleRedraw()
            })
            this.$watch(() => this.$wire.shiftOutWindowBeforeMinutes, v => {
                if (this._fromWire) return
                this.outBefore = parseInt(v) || 0
                this._scheduleRedraw()
            })
            this.$watch(() => this.$wire.shiftOutWindowAfterMinutes, v => {
                if (this._fromWire) return
                this.graceOut = parseInt(v) || 0
                this._scheduleRedraw()
            })

            this.$nextTick(() => this._initSvgs())
        },

        _readFromWire() {
            this.shiftStart = sbHhmmToMins(this.$wire.shiftStartsAt || '08:00')
            this.shiftEnd   = sbHhmmToMins(this.$wire.shiftEndsAt   || '17:00')
            const brks = this.$wire.shiftBreaks || []
            const br   = brks[0]
            this.breakStart = sbHhmmToMins(br?.starts_at || '12:00')
            this.breakEnd   = sbHhmmToMins(br?.ends_at   || '13:00')
            const br2 = brks[1]
            this.hasBreak2   = !!(br2?.starts_at && br2?.ends_at)
            this.break2Start = this.hasBreak2 ? sbHhmmToMins(br2.starts_at) : 0
            this.break2End   = this.hasBreak2 ? sbHhmmToMins(br2.ends_at)   : 0
            this.break1Paid  = !!(br?.paid)
            this.break2Paid  = !!(br2?.paid)
            this.graceIn    = parseInt(this.$wire.shiftInWindowBeforeMinutes  || '60')  || 0
            this.inAfter    = parseInt(this.$wire.shiftInWindowAfterMinutes   || '15')  || 0
            this.outBefore  = parseInt(this.$wire.shiftOutWindowBeforeMinutes || '15')  || 0
            this.graceOut   = parseInt(this.$wire.shiftOutWindowAfterMinutes  || '120') || 0
        },

        _syncToWire() {
            this._fromWire = true
            this.$wire.shiftStartsAt              = sbMinsToHhmm(this.shiftStart)
            this.$wire.shiftEndsAt                = sbMinsToHhmm(this.shiftEnd)
            this.$wire.shiftInWindowBeforeMinutes  = String(this.graceIn)
            this.$wire.shiftInWindowAfterMinutes   = String(this.inAfter)
            this.$wire.shiftOutWindowBeforeMinutes = String(this.outBefore)
            this.$wire.shiftOutWindowAfterMinutes  = String(this.graceOut)
            this.$wire.shiftExpectedWorkMinutes    = String(this.paidWork)
            const brks = [...(this.$wire.shiftBreaks || [])]
            brks[0] = {
                label:     brks[0]?.label || 'Break',
                starts_at: sbMinsToHhmm(this.breakStart),
                ends_at:   sbMinsToHhmm(this.breakEnd),
                paid:      brks[0]?.paid ?? false,
            }
            if (this.hasBreak2 || brks.length > 1) {
                brks[1] = {
                    label:     brks[1]?.label || 'Break',
                    starts_at: sbMinsToHhmm(this.break2Start),
                    ends_at:   sbMinsToHhmm(this.break2End),
                    paid:      brks[1]?.paid ?? false,
                }
            }
            this.$wire.shiftBreaks = brks
            this.$nextTick(() => { this._fromWire = false })
        },

        _applyState(s) {
            if ('shiftStart'  in s) this.shiftStart  = s.shiftStart
            if ('shiftEnd'    in s) this.shiftEnd    = s.shiftEnd
            if ('breakStart'  in s) this.breakStart  = s.breakStart
            if ('breakEnd'    in s) this.breakEnd    = s.breakEnd
            if ('break2Start' in s) this.break2Start = s.break2Start
            if ('break2End'   in s) this.break2End   = s.break2End
            if ('hasBreak2'   in s) this.hasBreak2   = s.hasBreak2
            if ('graceIn'     in s) this.graceIn     = s.graceIn
            if ('inAfter'     in s) this.inAfter     = s.inAfter
            if ('outBefore'   in s) this.outBefore   = s.outBefore
            if ('graceOut'    in s) this.graceOut    = s.graceOut
        },

        _initSvgs() {
            const onUpdate  = (s) => { this._applyState(s); this._redraw() }
            const onDragEnd = (s) => { this._applyState(s); this._syncToWire() }

            if (this.$refs.dialContainer) {
                this._dial?.destroy()
                this._dial = new ShiftDial(this.$refs.dialContainer, () => this._state, onUpdate, onDragEnd, 320)
                this._dial.draw()
            }
        },

        _redraw() {
            this._dial?.draw()
        },

        _scheduleRedraw() {
            clearTimeout(this._redrawTimer)
            this._redrawTimer = setTimeout(() => this._redraw(), 0)
        },

        stepTime(field, delta) {
            this[field] = ((this[field] + delta * this.snap) % 1440 + 1440) % 1440
            if (field.startsWith('break2')) this.hasBreak2 = true
            this._redraw()
            this._syncToWire()
        },

        parseTime(field, val) {
            const parts = (val || '').trim().split(':')
            const h = parseInt(parts[0], 10)
            const m = parseInt(parts[1], 10) || 0
            if (!Number.isNaN(h)) {
                this[field] = ((h * 60 + m) % 1440 + 1440) % 1440
                if (field.startsWith('break2')) this.hasBreak2 = true
                this._redraw()
                this._syncToWire()
            }
        },

        // Numeric grace inputs. Maxes mirror the drag clamps in ShiftDial/ShiftStrip
        // so typing and dragging stay in lock-step.
        setGrace(field, val) {
            const max = { graceIn: 120, inAfter: 60, outBefore: 60, graceOut: 240 }[field]
            if (max === undefined) return
            let v = parseInt(val, 10)
            if (Number.isNaN(v)) v = 0
            this[field] = Math.max(0, Math.min(max, v))
            this._redraw()
            this._syncToWire()
        },

    }))
})
