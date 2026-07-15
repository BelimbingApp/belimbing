const NODE_WIDTH = 184
const NODE_HEIGHT = 76
const COLUMN_GAP = 112
const ROW_GAP = 48
const CANVAS_PADDING_X = 40
const CANVAS_PADDING_Y = 40

const compareByPosition = (a, b) => {
    const position = Number(a.position ?? 0) - Number(b.position ?? 0)

    return position === 0
        ? String(a.code).localeCompare(String(b.code))
        : position
}

const assignRanks = (nodes, edges) => {
    const codes = new Set(nodes.map((node) => node.code))
    const activeEdges = edges.filter((edge) => edge.active && codes.has(edge.from) && codes.has(edge.to))
    const incoming = new Map(nodes.map((node) => [node.code, 0]))
    const outbound = new Map(nodes.map((node) => [node.code, []]))

    activeEdges.forEach((edge) => {
        incoming.set(edge.to, (incoming.get(edge.to) ?? 0) + 1)
        outbound.get(edge.from)?.push(edge.to)
    })

    const orderedNodes = [...nodes].sort(compareByPosition)
    const roots = orderedNodes.filter((node) => (incoming.get(node.code) ?? 0) === 0)
    const queue = (roots.length > 0 ? roots : orderedNodes.slice(0, 1))
        .map((node) => ({ code: node.code, rank: 0 }))
    const ranks = new Map()

    while (queue.length > 0) {
        const current = queue.shift()

        if (ranks.has(current.code)) {
            continue
        }

        ranks.set(current.code, current.rank)

        for (const target of outbound.get(current.code) ?? []) {
            if (!ranks.has(target)) {
                queue.push({ code: target, rank: current.rank + 1 })
            }
        }
    }

    let disconnectedRank = ranks.size > 0 ? Math.max(...ranks.values()) + 1 : 0

    for (const node of orderedNodes) {
        if (ranks.has(node.code)) {
            continue
        }

        queue.push({ code: node.code, rank: disconnectedRank })

        while (queue.length > 0) {
            const current = queue.shift()

            if (ranks.has(current.code)) {
                continue
            }

            ranks.set(current.code, current.rank)

            for (const target of outbound.get(current.code) ?? []) {
                if (!ranks.has(target)) {
                    queue.push({ code: target, rank: current.rank + 1 })
                }
            }
        }

        disconnectedRank = Math.max(...ranks.values()) + 1
    }

    return { ranks, activeEdges }
}

const parallelEdgeOffsets = (edges) => {
    const groups = new Map()

    edges.forEach((edge) => {
        const key = `${edge.from}\u0000${edge.to}`
        const siblings = groups.get(key) ?? []
        siblings.push(edge.id)
        groups.set(key, siblings)
    })

    return new Map(edges.map((edge) => {
        const siblings = groups.get(`${edge.from}\u0000${edge.to}`) ?? [edge.id]
        const index = siblings.indexOf(edge.id)

        return [edge.id, index - ((siblings.length - 1) / 2)]
    }))
}

globalThis.blbWorkflowGraph = (config = {}) => ({
    nodes: Array.isArray(config.nodes) ? config.nodes : [],
    edges: Array.isArray(config.edges) ? config.edges : [],
    labels: config.labels ?? {},
    layoutNodes: [],
    layoutEdges: [],
    canvasWidth: 0,
    canvasHeight: 0,
    selected: null,
    resizeObserver: null,

    init() {
        this.layout()
        this.resizeObserver = new ResizeObserver(() => this.layout())
        this.resizeObserver.observe(this.$refs.viewport)
    },

    destroy() {
        this.resizeObserver?.disconnect()
    },

    layout() {
        if (this.nodes.length === 0) {
            return
        }

        const { ranks, activeEdges } = assignRanks(this.nodes, this.edges)
        const grouped = new Map()

        for (const node of [...this.nodes].sort(compareByPosition)) {
            const rank = ranks.get(node.code) ?? 0
            const column = grouped.get(rank) ?? []
            column.push(node)
            grouped.set(rank, column)
        }

        const maxRank = Math.max(...grouped.keys(), 0)
        const maxRows = Math.max(...[...grouped.values()].map((column) => column.length), 1)
        const reverseEdgeCount = this.edges.filter((edge) => {
            const fromRank = ranks.get(edge.from) ?? 0
            const toRank = ranks.get(edge.to) ?? 0

            return fromRank >= toRank
        }).length
        const contentHeight = (maxRows * NODE_HEIGHT) + ((maxRows - 1) * ROW_GAP)
        const returnLaneHeight = Math.min(reverseEdgeCount, 4) * 24
        const viewportWidth = this.$refs.viewport?.clientWidth ?? 0

        this.canvasWidth = Math.max(
            viewportWidth,
            (maxRank + 1) * NODE_WIDTH + maxRank * COLUMN_GAP + CANVAS_PADDING_X * 2,
        )
        this.canvasHeight = Math.max(
            300,
            contentHeight + CANVAS_PADDING_Y * 2 + returnLaneHeight,
        )

        const activeIncoming = new Map(this.nodes.map((node) => [node.code, 0]))
        const activeOutgoing = new Map(this.nodes.map((node) => [node.code, 0]))

        activeEdges.forEach((edge) => {
            activeIncoming.set(edge.to, (activeIncoming.get(edge.to) ?? 0) + 1)
            activeOutgoing.set(edge.from, (activeOutgoing.get(edge.from) ?? 0) + 1)
        })

        this.layoutNodes = [...grouped.entries()].flatMap(([rank, column]) => {
            const columnHeight = column.length * NODE_HEIGHT + (column.length - 1) * ROW_GAP
            const startY = CANVAS_PADDING_Y + Math.max(0, (contentHeight - columnHeight) / 2)

            return column.map((node, index) => ({
                ...node,
                x: CANVAS_PADDING_X + rank * (NODE_WIDTH + COLUMN_GAP),
                y: startY + index * (NODE_HEIGHT + ROW_GAP),
                start: (activeIncoming.get(node.code) ?? 0) === 0,
                terminal: (activeOutgoing.get(node.code) ?? 0) === 0,
            }))
        })

        const nodesByCode = new Map(this.layoutNodes.map((node) => [node.code, node]))
        const offsets = parallelEdgeOffsets(this.edges)
        let returnLane = 0

        this.layoutEdges = this.edges.map((edge) => {
            const source = nodesByCode.get(edge.from)
            const target = nodesByCode.get(edge.to)
            const offset = offsets.get(edge.id) ?? 0
            const geometry = this.edgeGeometry(source, target, offset, returnLane)

            if (source && target && target.x <= source.x) {
                returnLane += 1
            }

            return { ...edge, ...geometry }
        })
    },

    edgeGeometry(source, target, offset, returnLane) {
        if (!source || !target) {
            return { path: '', labelX: 0, labelY: 0 }
        }

        if (source.code === target.code) {
            const x = source.x + NODE_WIDTH
            const y = source.y + NODE_HEIGHT / 2
            const loopX = x + 52 + Math.abs(offset) * 16

            return {
                path: `M ${x} ${y - 14} C ${loopX} ${y - 36}, ${loopX} ${y + 36}, ${x} ${y + 14}`,
                labelX: loopX,
                labelY: y - 42,
            }
        }

        const sourceCenterY = source.y + NODE_HEIGHT / 2
        const targetCenterY = target.y + NODE_HEIGHT / 2

        if (target.x > source.x) {
            const startX = source.x + NODE_WIDTH
            const endX = target.x
            const control = Math.max(48, (endX - startX) * 0.45)
            const bend = offset * 22

            return {
                path: `M ${startX} ${sourceCenterY} C ${startX + control} ${sourceCenterY + bend}, ${endX - control} ${targetCenterY + bend}, ${endX} ${targetCenterY}`,
                labelX: (startX + endX) / 2,
                labelY: (sourceCenterY + targetCenterY) / 2 + bend - 12,
            }
        }

        if (target.x === source.x) {
            const x = source.x + NODE_WIDTH
            const routeX = x + 48 + Math.abs(offset) * 18

            return {
                path: `M ${x} ${sourceCenterY} C ${routeX} ${sourceCenterY}, ${routeX} ${targetCenterY}, ${x} ${targetCenterY}`,
                labelX: routeX,
                labelY: (sourceCenterY + targetCenterY) / 2 - 10,
            }
        }

        const startX = source.x
        const endX = target.x + NODE_WIDTH
        const laneY = this.canvasHeight - 34 - (returnLane % 4) * 24

        return {
            path: `M ${startX} ${sourceCenterY} C ${startX - 48} ${sourceCenterY}, ${startX - 48} ${laneY}, ${startX - 24} ${laneY} L ${endX + 24} ${laneY} C ${endX + 48} ${laneY}, ${endX + 48} ${targetCenterY}, ${endX} ${targetCenterY}`,
            labelX: (startX + endX) / 2,
            labelY: laneY - 10,
        }
    },

    nodeStyle(node) {
        return `width: ${NODE_WIDTH}px; height: ${NODE_HEIGHT}px; transform: translate(${node.x}px, ${node.y}px);`
    },

    edgeLabelStyle(edge) {
        return `transform: translate(calc(${edge.labelX}px - 50%), calc(${edge.labelY}px - 50%));`
    },

    selectNode(code) {
        this.selected = this.selected?.type === 'node' && this.selected.id === code
            ? null
            : { type: 'node', id: code }
    },

    selectEdge(id) {
        this.selected = this.selected?.type === 'edge' && this.selected.id === id
            ? null
            : { type: 'edge', id }
    },

    clearSelection() {
        this.selected = null
    },

    nodeIsEmphasized(node) {
        if (!this.selected) {
            return false
        }

        if (this.selected.type === 'node') {
            if (this.selected.id === node.code) {
                return true
            }

            return this.edges.some((edge) => (
                (edge.from === this.selected.id && edge.to === node.code)
                || (edge.to === this.selected.id && edge.from === node.code)
            ))
        }

        const edge = this.edges.find((candidate) => candidate.id === this.selected.id)

        return edge?.from === node.code || edge?.to === node.code
    },

    edgeIsEmphasized(edge) {
        if (!this.selected) {
            return false
        }

        if (this.selected.type === 'edge') {
            return this.selected.id === edge.id
        }

        return edge.from === this.selected.id || edge.to === this.selected.id
    },

    nodeStateLabel(node) {
        if (node.missing) {
            return this.labels.missing
        }

        if (!node.active) {
            return this.labels.inactive
        }

        if (node.start && node.terminal) {
            return this.labels.isolated
        }

        if (node.start) {
            return this.labels.start
        }

        if (node.terminal) {
            return this.labels.end
        }

        return ''
    },

    selectionText() {
        if (!this.selected) {
            return this.labels.selectionHint
        }

        if (this.selected.type === 'node') {
            const node = this.nodes.find((candidate) => candidate.code === this.selected.id)

            return node
                ? `${this.labels.selectedStatus}: ${node.label} (${node.code})`
                : this.labels.selectionHint
        }

        const edge = this.edges.find((candidate) => candidate.id === this.selected.id)

        if (!edge) {
            return this.labels.selectionHint
        }

        const details = [
            edge.capability,
            edge.guarded ? this.labels.guarded : null,
            edge.sla && edge.sla !== '—' ? `${this.labels.sla}: ${edge.sla}` : null,
        ].filter(Boolean)
        const suffix = details.length > 0 ? ` · ${details.join(' · ')}` : ''

        return `${this.labels.selectedTransition}: ${edge.from} → ${edge.to} — ${edge.label}${suffix}`
    },
})
