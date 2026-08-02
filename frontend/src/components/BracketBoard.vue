<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { teamStyle } from '../utils/playerColor'
import { formatName } from '../utils/formatName'

const props = defineProps({
  matches: { type: Array, required: true },
  currentId: { type: Number, default: null },
  /** Mode projection : taille / disposition adaptées au nombre de joueurs */
  projection: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

const PAD_Y = 6
const HEADER_H = 22

const rootRef = ref(null)
const scale = ref(1)

function byPhase(phase) {
  return props.matches.filter((m) => m.phase === phase)
}

function roundsOf(list) {
  const map = new Map()
  for (const m of list) {
    if (!map.has(m.round)) map.set(m.round, [])
    map.get(m.round).push(m)
  }
  return [...map.entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([round, items]) => ({
      round,
      matches: items.sort((a, b) => a.slot - b.slot),
    }))
}

/** Nombre de matchs du 1er tour winners ≈ joueurs / 2 */
const firstRoundSize = computed(() => {
  const winners = byPhase('winner')
  if (!winners.length) return 0
  const minRound = Math.min(...winners.map((m) => m.round))
  return winners.filter((m) => m.round === minRound).length
})

/**
 * Densité selon le tableau :
 * - cozy : ≤ 8 joueurs (4 matchs WR1) → empilé, cases larges
 * - medium : ≤ 16 joueurs → côte à côte, cases moyennes
 * - compact : 32+ → côte à côte, cases serrées
 */
const density = computed(() => {
  if (!props.projection) return 'admin'
  const n = firstRoundSize.value
  if (n <= 4) return 'cozy'
  if (n <= 8) return 'medium'
  return 'compact'
})

/** Empiler winners / losers tant que le tableau reste lisible (≤ 8 joueurs) */
const stackBrackets = computed(
  () => props.projection && density.value === 'cozy',
)

const sizes = computed(() => {
  switch (density.value) {
    case 'cozy':
      return { h: 70, w: 210, gap: 72, rowGap: 28, font: 0.95 }
    case 'medium':
      return { h: 54, w: 168, gap: 48, rowGap: 16, font: 0.82 }
    case 'compact':
      return { h: 44, w: 136, gap: 34, rowGap: 10, font: 0.72 }
    default:
      return { h: 64, w: 180, gap: 64, rowGap: 36, font: 0.84 }
  }
})

const MATCH_H = computed(() => sizes.value.h)
const MATCH_W = computed(() => sizes.value.w)
const COL_GAP = computed(() => sizes.value.gap)

function labelFor(phase, round) {
  if (phase === 'winner') return `WR${round}`
  if (phase === 'loser') return `LR${round}`
  if (phase === 'final') return 'GF'
  return `R${round}`
}

function buildLayout(rounds) {
  const mh = MATCH_H.value
  const mw = MATCH_W.value
  const gap = COL_GAP.value
  const step0 = mh + sizes.value.rowGap

  if (!rounds.length) {
    return { width: 0, height: 0, nodes: [], lines: [], headers: [] }
  }

  const feeders = new Map()
  for (const col of rounds) {
    for (const m of col.matches) {
      if (!m.nextMatchId) continue
      if (!feeders.has(m.nextMatchId)) feeders.set(m.nextMatchId, [])
      feeders.get(m.nextMatchId).push(m)
    }
  }

  const matchPos = new Map()
  const headers = []
  const first = rounds[0]

  first.matches.forEach((match, idx) => {
    const slotIndex = Math.max(0, (match.slot || idx + 1) - 1)
    const y = PAD_Y + slotIndex * step0
    matchPos.set(match.id, {
      match,
      x: 0,
      y,
      cx: mw,
      cy: y + mh / 2,
    })
  })
  headers.push({
    key: `h-0-${first.round}`,
    x: mw / 2,
    label: labelFor(first.matches[0]?.phase || 'winner', first.round),
  })

  for (let c = 1; c < rounds.length; c++) {
    const col = rounds[c]
    const x = c * (mw + gap)
    headers.push({
      key: `h-${c}-${col.round}`,
      x: x + mw / 2,
      label: labelFor(col.matches[0]?.phase || 'winner', col.round),
    })

    const prevCount = rounds[c - 1].matches.length
    const curCount = Math.max(col.matches.length, 1)
    const step = (prevCount * step0) / curCount

    col.matches.forEach((match, idx) => {
      const sources = feeders.get(match.id) || []
      let y
      if (sources.length) {
        const centers = sources
          .map((s) => matchPos.get(s.id)?.cy)
          .filter((v) => v != null)
        if (centers.length) {
          const avg = centers.reduce((a, b) => a + b, 0) / centers.length
          y = avg - mh / 2
        }
      }
      if (y == null) {
        const slotIndex = Math.max(0, (match.slot || idx + 1) - 1)
        y = PAD_Y + slotIndex * step + (step - mh) / 2
      }
      matchPos.set(match.id, {
        match,
        x,
        y,
        cx: x + mw,
        cy: y + mh / 2,
      })
    })
  }

  const nodes = [...matchPos.values()]
  const lines = []

  for (const node of nodes) {
    const nextId = node.match.nextMatchId
    if (!nextId || !matchPos.has(nextId)) continue
    const target = matchPos.get(nextId)
    const mid = node.cx + (target.x - node.cx) / 2
    lines.push({
      key: `l-${node.match.id}-${nextId}`,
      d: `M ${node.cx} ${node.cy} H ${mid} V ${target.cy} H ${target.x}`,
    })
  }

  if (!lines.length) {
    for (let c = 0; c < rounds.length - 1; c++) {
      for (const match of rounds[c].matches) {
        const from = matchPos.get(match.id)
        const nextSlot = Math.ceil(match.slot / 2)
        const next = rounds[c + 1].matches.find((m) => m.slot === nextSlot)
        const target = next ? matchPos.get(next.id) : null
        if (!from || !target) continue
        const mid = from.cx + (target.x - from.cx) / 2
        lines.push({
          key: `ls-${match.id}-${next.id}`,
          d: `M ${from.cx} ${from.cy} H ${mid} V ${target.cy} H ${target.x}`,
        })
      }
    }
  }

  const bottom = Math.max(...nodes.map((n) => n.y + mh), step0)
  const height = bottom + PAD_Y
  const width = rounds.length * mw + Math.max(0, rounds.length - 1) * gap

  return { width, height, nodes, lines, headers }
}

function teamName(team) {
  if (!team) return 'BYE'
  return formatName(team.name) || 'BYE'
}

function isWinner(match, team) {
  return match.winner && team && match.winner.id === team.id
}

function score(match, side) {
  if (side === 'home') return match.scoreHome
  return match.scoreAway
}

const loserLayout = computed(() => buildLayout(roundsOf(byPhase('loser'))))
const finals = computed(() => byPhase('final').sort((a, b) => a.slot - b.slot))
const isDoubleElim = computed(() => byPhase('loser').length > 0)

const winnerTreeRounds = computed(() => {
  const w = roundsOf(byPhase('winner'))
  const f = finals.value
  if (!f.length) return w
  if (isDoubleElim.value) return w
  const lastRound = w.length ? w[w.length - 1].round : 0
  return [
    ...w,
    {
      round: lastRound + 1,
      matches: f.map((m) => ({ ...m, phase: 'final', round: lastRound + 1 })),
    },
  ]
})

const mainWinnerLayout = computed(() => buildLayout(winnerTreeRounds.value))
const showSeparateFinal = computed(
  () => isDoubleElim.value && finals.value.length > 0,
)

const useSplit = computed(
  () => props.projection && isDoubleElim.value && !stackBrackets.value,
)

function updateScale() {
  if (!props.projection || !rootRef.value) {
    scale.value = 1
    return
  }
  const el = rootRef.value
  const availW = el.clientWidth
  const availH = el.clientHeight
  if (!availW || !availH) return

  const content = el.querySelector('.board-inner')
  if (!content) return
  const prev = content.style.transform
  content.style.transform = 'none'
  const needW = content.scrollWidth
  const needH = content.scrollHeight
  content.style.transform = prev

  if (!needW || !needH) return
  // Laisser une petite marge, ne pas descendre trop bas (lisibilité)
  const s = Math.min(1, (availW - 8) / needW, (availH - 8) / needH)
  const floor = density.value === 'cozy' ? 0.72 : density.value === 'medium' ? 0.48 : 0.38
  scale.value = Math.max(floor, s)
}

let ro = null

onMounted(async () => {
  await nextTick()
  updateScale()
  if (typeof ResizeObserver !== 'undefined' && rootRef.value) {
    ro = new ResizeObserver(() => updateScale())
    ro.observe(rootRef.value)
  }
  window.addEventListener('resize', updateScale)
})

onUnmounted(() => {
  if (ro) ro.disconnect()
  window.removeEventListener('resize', updateScale)
})

watch(
  () => [
    props.matches,
    props.projection,
    density.value,
    stackBrackets.value,
    mainWinnerLayout.value.width,
    loserLayout.value.width,
  ],
  async () => {
    await nextTick()
    updateScale()
  },
)
</script>

<template>
  <div
    ref="rootRef"
    class="board"
    :class="[
      projection ? 'projection' : '',
      density !== 'admin' ? `density-${density}` : '',
      stackBrackets ? 'stacked' : '',
    ]"
  >
    <div
      class="board-inner"
      :style="
        projection
          ? {
              transform: `scale(${scale})`,
              transformOrigin: stackBrackets ? 'top center' : 'top center',
            }
          : undefined
      "
    >
      <div class="trees" :class="{ split: useSplit, stack: stackBrackets }">
        <section v-if="mainWinnerLayout.nodes.length" class="bracket-section">
          <h3>Winners bracket</h3>
          <div class="tree-scroll">
            <div
              class="tree"
              :style="{
                width: mainWinnerLayout.width + 'px',
                height: mainWinnerLayout.height + HEADER_H + 'px',
              }"
            >
              <div
                v-for="h in mainWinnerLayout.headers"
                :key="h.key"
                class="round-header"
                :style="{ left: h.x + 'px' }"
              >
                {{ h.label }}
              </div>
              <svg
                class="connectors"
                :width="mainWinnerLayout.width"
                :height="mainWinnerLayout.height"
                :viewBox="`0 0 ${mainWinnerLayout.width} ${mainWinnerLayout.height}`"
              >
                <path
                  v-for="line in mainWinnerLayout.lines"
                  :key="line.key"
                  :d="line.d"
                  class="connector"
                />
              </svg>
              <button
                v-for="node in mainWinnerLayout.nodes"
                :key="node.match.id"
                type="button"
                class="match-box"
                :class="{
                  live: node.match.id === currentId || node.match.status === 'live',
                  done: node.match.status === 'done',
                }"
                :style="{
                  left: node.x + 'px',
                  top: node.y + HEADER_H + 'px',
                  width: MATCH_W + 'px',
                  '--slot-fs': sizes.font + 'rem',
                }"
                @click="emit('select', node.match)"
              >
                <div class="match-label">
                  {{ labelFor(node.match.phase, node.match.round) }} · G{{ node.match.slot }}
                </div>
                <div
                  class="slot"
                  :class="{ win: isWinner(node.match, node.match.homeTeam), bye: !node.match.homeTeam }"
                  :style="teamStyle(node.match.homeTeam)"
                >
                  <i class="swatch" aria-hidden="true" />
                  <span>{{ teamName(node.match.homeTeam) }}</span>
                  <em>{{ score(node.match, 'home') ?? '' }}</em>
                </div>
                <div
                  class="slot"
                  :class="{ win: isWinner(node.match, node.match.awayTeam), bye: !node.match.awayTeam }"
                  :style="teamStyle(node.match.awayTeam)"
                >
                  <i class="swatch" aria-hidden="true" />
                  <span>{{ teamName(node.match.awayTeam) }}</span>
                  <em>{{ score(node.match, 'away') ?? '' }}</em>
                </div>
              </button>
            </div>
          </div>
        </section>

        <section v-if="loserLayout.nodes.length" class="bracket-section losers">
          <h3>Losers bracket</h3>
          <div class="tree-scroll">
            <div
              class="tree"
              :style="{
                width: loserLayout.width + 'px',
                height: loserLayout.height + HEADER_H + 'px',
              }"
            >
              <div
                v-for="h in loserLayout.headers"
                :key="h.key"
                class="round-header"
                :style="{ left: h.x + 'px' }"
              >
                {{ h.label }}
              </div>
              <svg
                class="connectors"
                :width="loserLayout.width"
                :height="loserLayout.height"
                :viewBox="`0 0 ${loserLayout.width} ${loserLayout.height}`"
              >
                <path
                  v-for="line in loserLayout.lines"
                  :key="line.key"
                  :d="line.d"
                  class="connector"
                />
              </svg>
              <button
                v-for="node in loserLayout.nodes"
                :key="node.match.id"
                type="button"
                class="match-box"
                :class="{
                  live: node.match.id === currentId || node.match.status === 'live',
                  done: node.match.status === 'done',
                }"
                :style="{
                  left: node.x + 'px',
                  top: node.y + HEADER_H + 'px',
                  width: MATCH_W + 'px',
                  '--slot-fs': sizes.font + 'rem',
                }"
                @click="emit('select', node.match)"
              >
                <div class="match-label">
                  {{ labelFor(node.match.phase, node.match.round) }} · G{{ node.match.slot }}
                </div>
                <div
                  class="slot"
                  :class="{ win: isWinner(node.match, node.match.homeTeam), bye: !node.match.homeTeam }"
                  :style="teamStyle(node.match.homeTeam)"
                >
                  <i class="swatch" aria-hidden="true" />
                  <span>{{ teamName(node.match.homeTeam) }}</span>
                  <em>{{ score(node.match, 'home') ?? '' }}</em>
                </div>
                <div
                  class="slot"
                  :class="{ win: isWinner(node.match, node.match.awayTeam), bye: !node.match.awayTeam }"
                  :style="teamStyle(node.match.awayTeam)"
                >
                  <i class="swatch" aria-hidden="true" />
                  <span>{{ teamName(node.match.awayTeam) }}</span>
                  <em>{{ score(node.match, 'away') ?? '' }}</em>
                </div>
              </button>
            </div>
          </div>
        </section>
      </div>

      <section v-if="showSeparateFinal" class="bracket-section finals">
        <h3>Grande finale</h3>
        <div class="finals-row">
          <button
            v-for="match in finals"
            :key="match.id"
            type="button"
            class="match-box static"
            :class="{
              live: match.id === currentId || match.status === 'live',
              done: match.status === 'done',
            }"
            :style="{ width: MATCH_W + 'px', '--slot-fs': sizes.font + 'rem' }"
            @click="emit('select', match)"
          >
            <div class="match-label">GF — Grande finale</div>
            <div
              class="slot"
              :class="{ win: isWinner(match, match.homeTeam), bye: !match.homeTeam }"
              :style="teamStyle(match.homeTeam)"
            >
              <i class="swatch" aria-hidden="true" />
              <span>{{ teamName(match.homeTeam) }}</span>
              <em>{{ score(match, 'home') ?? '' }}</em>
            </div>
            <div
              class="slot"
              :class="{ win: isWinner(match, match.awayTeam), bye: !match.awayTeam }"
              :style="teamStyle(match.awayTeam)"
            >
              <i class="swatch" aria-hidden="true" />
              <span>{{ teamName(match.awayTeam) }}</span>
              <em>{{ score(match, 'away') ?? '' }}</em>
            </div>
          </button>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.board {
  display: grid;
  gap: 1.75rem;
}

.board.projection {
  height: 100%;
  gap: 0;
  overflow: hidden;
  display: flex;
  justify-content: center;
  align-items: flex-start;
}

.board.projection.stacked {
  align-items: center;
}

.board-inner {
  display: grid;
  gap: 0.85rem;
  width: max-content;
}

.board.density-cozy .board-inner {
  gap: 1.15rem;
}

.board.projection .board-inner {
  will-change: transform;
}

.trees {
  display: grid;
  gap: 1.25rem;
  justify-items: center;
}

.trees.stack {
  grid-template-columns: 1fr;
  gap: 1.35rem;
}

.trees.split {
  grid-template-columns: auto auto;
  gap: 1.5rem;
  align-items: start;
  justify-items: start;
}

.bracket-section h3 {
  margin: 0 0 0.4rem;
  font-size: 0.95rem;
}

.board.projection .bracket-section h3 {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--muted);
  text-align: center;
}

.board.density-cozy .bracket-section h3 {
  font-size: 0.9rem;
  color: #d7dde6;
}

.tree-scroll {
  overflow: visible;
}

.tree {
  position: relative;
}

.round-header {
  position: absolute;
  top: 0;
  transform: translateX(-50%);
  color: var(--muted);
  font-size: 0.65rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  font-weight: 600;
  z-index: 2;
  white-space: nowrap;
}

.board.density-cozy .round-header {
  font-size: 0.75rem;
}

.connectors {
  position: absolute;
  left: 0;
  top: 22px;
  overflow: visible;
  pointer-events: none;
  z-index: 0;
}

.connector {
  fill: none;
  stroke: rgba(125, 170, 215, 0.65);
  stroke-width: 2;
}

.match-box {
  position: absolute;
  width: 180px;
  margin: 0;
  padding: 0;
  border: 1px solid #445061;
  border-radius: 6px;
  background: #141a22;
  overflow: hidden;
  cursor: pointer;
  z-index: 1;
  color: inherit;
  font: inherit;
  text-align: left;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.match-box.static {
  position: relative;
  left: auto;
  top: auto;
}

.match-box:hover {
  border-color: rgba(125, 170, 215, 0.9);
}

.match-box.live {
  border-color: var(--accent);
  box-shadow: 0 0 0 1px rgba(232, 168, 56, 0.35);
  animation: match-pulse 1.2s ease-in-out infinite;
}

.match-label {
  font-size: 0.62rem;
  color: var(--muted);
  padding: 0.15rem 0.4rem;
  border-bottom: 1px solid #2a323d;
  letter-spacing: 0.02em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.board.density-cozy .match-label {
  font-size: 0.72rem;
  padding: 0.25rem 0.5rem;
}

.slot {
  display: grid;
  grid-template-columns: 4px 1fr 28px;
  align-items: stretch;
  min-height: 1.35rem;
  font-size: var(--slot-fs, 0.78rem);
  border-bottom: 1px solid #222933;
  background: linear-gradient(90deg, var(--team-bg, transparent), transparent 70%);
}

.board.density-cozy .slot {
  min-height: 1.7rem;
  grid-template-columns: 5px 1fr 34px;
}

.board.density-medium .slot {
  min-height: 1.45rem;
}

.slot:last-child {
  border-bottom: none;
}

.slot .swatch {
  display: block;
  width: 100%;
  background: var(--team-solid, #445061);
}

.slot.bye .swatch {
  background: #3a424d;
}

.slot.bye {
  --team-bg: transparent;
  --team-fg: var(--muted);
}

.slot span {
  padding: 0.15rem 0.4rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: flex;
  align-items: center;
  color: var(--team-fg, inherit);
  font-weight: 600;
}

.slot em {
  font-style: normal;
  color: var(--muted);
  text-align: center;
  border-left: 1px solid #2a323d;
  display: grid;
  place-items: center;
  font-variant-numeric: tabular-nums;
  background: rgba(255, 255, 255, 0.02);
}

.slot.win span {
  color: var(--team-solid, var(--accent));
  font-weight: 700;
}

.slot.win em {
  color: var(--team-solid, var(--accent));
}

.losers .match-box {
  background: #10161d;
}

.finals-row {
  display: flex;
  gap: 1rem;
  justify-content: center;
}

.finals h3 {
  text-align: center;
}

@keyframes match-pulse {
  0%,
  100% {
    box-shadow: 0 0 0 1px rgba(232, 168, 56, 0.3), 0 0 0 0 rgba(232, 168, 56, 0.2);
  }
  50% {
    box-shadow: 0 0 0 1px rgba(232, 168, 56, 0.7), 0 0 16px 2px rgba(232, 168, 56, 0.35);
  }
}

@media (max-width: 900px) {
  .trees.split {
    grid-template-columns: 1fr;
  }
}
</style>
