const NODE_WIDTH = 220
const NODE_HEIGHT = 92
const NODE_GAP = 48
const RANK_GAP = 112
const CANVAS_PADDING_X = 48
const CANVAS_PADDING_Y = 56

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
    hasCenteredViewport: false,

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
        const maxColumns = Math.max(...[...grouped.values()].map((rank) => rank.length), 1)
        const reverseEdgeCount = this.edges.filter((edge) => {
            const fromRank = ranks.get(edge.from) ?? 0
            const toRank = ranks.get(edge.to) ?? 0

            return fromRank >= toRank
        }).length
        const viewportWidth = this.$refs.viewport?.clientWidth ?? 0
        const contentWidth = (maxColumns * NODE_WIDTH) + ((maxColumns - 1) * NODE_GAP)
        const returnLaneWidth = Math.min(reverseEdgeCount, 4) * 24

        this.canvasWidth = Math.max(
            viewportWidth,
            contentWidth + CANVAS_PADDING_X * 2 + returnLaneWidth,
        )
        this.canvasHeight = Math.max(
            300,
            ((maxRank + 1) * NODE_HEIGHT) + (maxRank * RANK_GAP) + (CANVAS_PADDING_Y * 2),
        )

        const activeIncoming = new Map(this.nodes.map((node) => [node.code, 0]))
        const activeOutgoing = new Map(this.nodes.map((node) => [node.code, 0]))

        activeEdges.forEach((edge) => {
            activeIncoming.set(edge.to, (activeIncoming.get(edge.to) ?? 0) + 1)
            activeOutgoing.set(edge.from, (activeOutgoing.get(edge.from) ?? 0) + 1)
        })

        this.layoutNodes = [...grouped.entries()].flatMap(([rank, row]) => {
            const rowWidth = row.length * NODE_WIDTH + (row.length - 1) * NODE_GAP
            const startX = Math.max(CANVAS_PADDING_X, (this.canvasWidth - returnLaneWidth - rowWidth) / 2)

            return row.map((node, index) => ({
                ...node,
                x: startX + index * (NODE_WIDTH + NODE_GAP),
                y: CANVAS_PADDING_Y + rank * (NODE_HEIGHT + RANK_GAP),
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

            if (source && target && target.y <= source.y) {
                returnLane += 1
            }

            return { ...edge, ...geometry }
        })

        this.$nextTick(() => {
            this.renderEdgePaths()

            if (!this.hasCenteredViewport && viewportWidth > 0 && this.canvasWidth > viewportWidth) {
                this.$refs.viewport.scrollLeft = (this.canvasWidth - viewportWidth) / 2
                this.hasCenteredViewport = true
            }
        })
    },

    renderEdgePaths() {
        const layer = this.$refs.edgePaths

        if (!layer) {
            return
        }

        layer.replaceChildren()

        for (const edge of this.layoutEdges) {
            const group = document.createElementNS('http://www.w3.org/2000/svg', 'g')
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path')
            const hitArea = document.createElementNS('http://www.w3.org/2000/svg', 'path')
            const emphasized = this.edgeIsEmphasized(edge)

            path.setAttribute('d', edge.path)
            path.setAttribute('fill', 'none')
            path.setAttribute('stroke', emphasized
                ? 'var(--color-accent)'
                : (edge.active ? 'var(--color-muted)' : 'var(--color-border-input)'))
            path.setAttribute('stroke-width', '2')
            path.setAttribute('stroke-linecap', 'round')
            path.setAttribute('stroke-linejoin', 'round')
            path.setAttribute('marker-end', emphasized
                ? 'url(#workflow-arrow-accent)'
                : (edge.active ? 'url(#workflow-arrow)' : 'url(#workflow-arrow-inactive)'))
            path.setAttribute('opacity', this.edgeIsDimmed(edge) ? '0.15' : '1')

            if (!edge.active) {
                path.setAttribute('stroke-dasharray', '6 6')
            }

            hitArea.setAttribute('d', edge.path)
            hitArea.setAttribute('fill', 'none')
            hitArea.setAttribute('stroke', 'transparent')
            hitArea.setAttribute('stroke-width', '20')
            hitArea.style.cursor = 'pointer'
            hitArea.addEventListener('click', () => this.selectEdge(edge.id))

            group.append(path, hitArea)
            layer.append(group)
        }
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

        const sourceCenterX = source.x + NODE_WIDTH / 2
        const targetCenterX = target.x + NODE_WIDTH / 2

        if (target.y > source.y) {
            const startY = source.y + NODE_HEIGHT
            const endY = target.y
            const control = Math.max(48, (endY - startY) * 0.45)
            const bend = offset * 22

            return {
                path: `M ${sourceCenterX} ${startY} C ${sourceCenterX + bend} ${startY + control}, ${targetCenterX + bend} ${endY - control}, ${targetCenterX} ${endY}`,
                labelX: (sourceCenterX + targetCenterX) / 2 + bend,
                labelY: (startY + endY) / 2,
            }
        }

        if (target.y === source.y) {
            const travelsRight = target.x > source.x
            const startX = travelsRight ? source.x + NODE_WIDTH : source.x
            const endX = travelsRight ? target.x : target.x + NODE_WIDTH
            const centerY = source.y + NODE_HEIGHT / 2
            const routeY = source.y + NODE_HEIGHT + 40 + Math.abs(offset) * 18

            return {
                path: `M ${startX} ${centerY} C ${startX} ${routeY}, ${endX} ${routeY}, ${endX} ${centerY}`,
                labelX: (startX + endX) / 2,
                labelY: routeY,
            }
        }

        const startX = source.x + NODE_WIDTH
        const endX = target.x + NODE_WIDTH
        const startY = source.y + NODE_HEIGHT / 2
        const endY = target.y + NODE_HEIGHT / 2
        const laneX = this.canvasWidth - 28 - (returnLane % 4) * 24

        return {
            path: `M ${startX} ${startY} C ${laneX} ${startY}, ${laneX} ${startY}, ${laneX} ${startY - 24} L ${laneX} ${endY + 24} C ${laneX} ${endY}, ${laneX} ${endY}, ${endX} ${endY}`,
            labelX: laneX,
            labelY: (startY + endY) / 2,
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
        this.$nextTick(() => this.renderEdgePaths())
    },

    selectEdge(id) {
        this.selected = this.selected?.type === 'edge' && this.selected.id === id
            ? null
            : { type: 'edge', id }
        this.$nextTick(() => this.renderEdgePaths())
    },

    clearSelection() {
        this.selected = null
        this.$nextTick(() => this.renderEdgePaths())
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

    nodeIsDimmed(node) {
        return this.selected !== null && !this.nodeIsEmphasized(node)
    },

    edgeIsDimmed(edge) {
        return this.selected !== null && !this.edgeIsEmphasized(edge)
    },

    selectedNode() {
        if (this.selected?.type !== 'node') {
            return null
        }

        return this.layoutNodes.find((node) => node.code === this.selected.id) ?? null
    },

    selectedEdge() {
        if (this.selected?.type !== 'edge') {
            return null
        }

        return this.edges.find((edge) => edge.id === this.selected.id) ?? null
    },

    nodeByCode(code) {
        return this.nodes.find((node) => node.code === code) ?? null
    },

    nodeLabel(code) {
        return this.nodeByCode(code)?.label ?? code
    },

    incomingEdges(code) {
        return this.edges.filter((edge) => edge.to === code)
    },

    outgoingEdges(code) {
        return this.edges.filter((edge) => edge.from === code)
    },

    connectionText(node) {
        const incoming = this.incomingEdges(node.code).length
        const outgoing = this.outgoingEdges(node.code).length

        return `${incoming} in · ${outgoing} out`
    },

    edgeRuleSummary(edge) {
        const rules = [
            edge.guard ? this.labels.guarded : null,
            edge.sla && edge.sla !== '—' ? `${this.labels.sla} ${edge.sla}` : null,
        ].filter(Boolean)

        return rules.join(' · ')
    },

    missingCount() {
        return this.nodes.filter((node) => node.missing).length
    },

    isolatedCount() {
        return this.layoutNodes.filter((node) => node.start && node.terminal && !node.missing).length
    },

    issueCount() {
        return this.missingCount() + this.isolatedCount()
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
})
