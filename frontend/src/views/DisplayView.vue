<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api/client'
import BracketBoard from '../components/BracketBoard.vue'
import BrandLogo from '../components/BrandLogo.vue'
import { brand } from '../brand'
import { teamStyle } from '../utils/playerColor'
import { formatName } from '../utils/formatName'

const route = useRoute()
const data = ref(null)
const error = ref('')
let timer = null

const id = computed(() => Number(route.params.id))

async function load() {
  try {
    data.value = await api.publicDisplay(id.value)
    error.value = ''
  } catch (e) {
    error.value = e.message
  }
}

onMounted(() => {
  load()
  timer = setInterval(load, 3000)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})

watch(id, load)

const bracketReady = computed(() => (data.value?.bracketMatches?.length || 0) > 0)

/** Afficher les poules dès qu’elles existent et que le bracket n’est pas lancé */
const showGroups = computed(() => {
  const groups = data.value?.groups
  if (!Array.isArray(groups) || groups.length === 0) return false
  if (bracketReady.value) return false
  const status = data.value?.tournament?.status
  if (status === 'bracket' || status === 'finished') return false
  return true
})

const showTiebreakers = computed(() => {
  if (!data.value?.tiebreakerMatches?.length) return false
  return !bracketReady.value
})

const hasLiveMatch = computed(() => !!data.value?.currentMatch)
const liveGroupId = computed(() => data.value?.currentMatch?.groupId ?? null)

const groups = computed(() => data.value?.groups || [])

function groupNameById(groupId) {
  if (groupId == null) return null
  const g = groups.value.find((x) => Number(x.id) === Number(groupId))
  return g?.name || null
}

function groupNameForMatch(match) {
  return groupNameById(match?.groupId)
}

const liveGroupName = computed(() => groupNameForMatch(data.value?.currentMatch))

const champion = computed(() => {
  if (data.value?.champion) return data.value.champion
  const finals = (data.value?.bracketMatches || []).filter((m) => m.phase === 'final')
  if (!finals.length) return null
  if (!finals.every((m) => m.status === 'done' && m.winner)) return null
  const ordered = [...finals].sort(
    (a, b) => (a.sortOrder ?? 0) - (b.sortOrder ?? 0) || a.slot - b.slot,
  )
  return ordered[ordered.length - 1].winner
})

const isFinished = computed(() => !!champion.value)

const championPlayers = computed(() => {
  const c = champion.value
  if (!c) return ''
  return [c.player1?.name, c.player2?.name].filter(Boolean).map(formatName).join(' · ')
})

function scoreLabel(match) {
  if (!match) return ''
  if (match.scoreHome == null || match.scoreAway == null) return ''
  return `${match.scoreHome} — ${match.scoreAway}`
}

function phaseLabel(match) {
  if (!match) return ''
  if (match.phase === 'group') {
    const g = groupNameForMatch(match)
    return g ? `${g}` : 'Poules'
  }
  const map = { winner: 'WB', loser: 'LB', final: 'GF', tiebreaker: 'Barrage' }
  return `${map[match.phase] || match.phase} · R${match.round}`
}

function isPlayingTeam(team) {
  const cur = data.value?.currentMatch
  if (!cur || !team?.id) return false
  return cur.homeTeam?.id === team.id || cur.awayTeam?.id === team.id
}

function groupRows(group) {
  if (group?.standings?.length) return group.standings
  return (group?.teams || []).map((team, i) => ({
    id: `t-${team.id || i}`,
    team,
    points: 0,
    wins: 0,
    losses: 0,
  }))
}
</script>

<template>
  <div
    class="proj"
    :class="{
      'is-groups': showGroups,
      'is-bracket': bracketReady,
      'is-finished': isFinished && champion,
    }"
  >
    <Transition name="finale">
      <section v-if="isFinished && champion" class="finale" :style="teamStyle(champion)">
        <div class="finale-glow" aria-hidden="true" />
        <img :src="brand.logo" :alt="brand.logoAlt" class="finale-logo" />
        <p class="finale-eyebrow">{{ brand.name }} · Tournoi terminé</p>
        <h1 class="finale-title">{{ data?.tournament?.name || brand.name }}</h1>
        <p class="finale-label">Vainqueur</p>
        <p class="finale-name">
          <i class="swatch big" aria-hidden="true" />
          {{ formatName(champion.name) }}
        </p>
        <p
          v-if="championPlayers && championPlayers !== formatName(champion.name)"
          class="finale-players"
        >
          {{ championPlayers }}
        </p>
      </section>
    </Transition>

    <template v-if="!(isFinished && champion)">
      <!-- Bandeau compact fixe -->
      <header class="proj-bar">
        <div class="proj-brand">
          <BrandLogo variant="display" :show-text="false" />
          <div>
            <p class="eyebrow">{{ brand.name }} · Projection</p>
            <h1>{{ data?.tournament?.name || brand.product }}</h1>
          </div>
        </div>

        <div class="proj-live" :class="{ on: hasLiveMatch }">
          <div class="proj-live-meta">
            <span class="live-dot" :class="{ on: hasLiveMatch }" />
            <span>{{ hasLiveMatch ? 'Match en cours' : 'En attente' }}</span>
            <span v-if="data?.currentMatch" class="pill">{{ phaseLabel(data.currentMatch) }}</span>
          </div>
          <div v-if="data?.currentMatch" class="proj-live-teams">
            <span :style="teamStyle(data.currentMatch.homeTeam)">
              <i class="swatch" />
              {{ formatName(data.currentMatch.homeTeam?.name) || 'TBD' }}
            </span>
            <em>vs</em>
            <span :style="teamStyle(data.currentMatch.awayTeam)">
              <i class="swatch" />
              {{ formatName(data.currentMatch.awayTeam?.name) || 'TBD' }}
            </span>
            <strong v-if="scoreLabel(data.currentMatch)" class="score">
              {{ scoreLabel(data.currentMatch) }}
            </strong>
          </div>
          <p v-else class="muted">En attente du prochain match…</p>
        </div>

        <div class="proj-next">
          <p class="label">Prochain</p>
          <template v-if="data?.nextMatch">
            <p class="proj-next-teams">
              <span :style="teamStyle(data.nextMatch.homeTeam)">
                <i class="swatch tiny" />
                {{ formatName(data.nextMatch.homeTeam?.name) || 'TBD' }}
              </span>
              <em>vs</em>
              <span :style="teamStyle(data.nextMatch.awayTeam)">
                <i class="swatch tiny" />
                {{ formatName(data.nextMatch.awayTeam?.name) || 'TBD' }}
              </span>
            </p>
            <p v-if="groupNameForMatch(data.nextMatch)" class="muted small">
              {{ groupNameForMatch(data.nextMatch) }}
            </p>
          </template>
          <p v-else class="muted">—</p>
        </div>
      </header>

      <p v-if="error" class="error">{{ error }}</p>

      <!-- Zone principale : poules en priorité -->
      <main class="proj-main">
        <section v-if="showGroups" class="poules">
          <div class="poules-title">
            <h2>Classements des poules</h2>
            <p v-if="liveGroupName">
              Match en cours dans <strong>{{ liveGroupName }}</strong>
            </p>
          </div>

          <div class="poules-grid" :data-count="groups.length">
            <article
              v-for="group in groups"
              :key="group.id"
              class="poule-card"
              :class="{ active: Number(group.id) === Number(liveGroupId) }"
            >
              <header>
                <h3>{{ group.name }}</h3>
                <span v-if="Number(group.id) === Number(liveGroupId)" class="live-tag">
                  En cours
                </span>
              </header>
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Équipe</th>
                    <th>Pts</th>
                    <th>V</th>
                    <th>D</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(row, idx) in groupRows(group)"
                    :key="row.id"
                    :class="{ playing: isPlayingTeam(row.team) }"
                  >
                    <td>{{ idx + 1 }}</td>
                    <td>
                      <span class="team-cell" :style="teamStyle(row.team)">
                        <i class="swatch tiny" />
                        {{ formatName(row.team?.name) }}
                      </span>
                    </td>
                    <td>{{ row.points }}</td>
                    <td>{{ row.wins }}</td>
                    <td>{{ row.losses }}</td>
                  </tr>
                </tbody>
              </table>
            </article>
          </div>
        </section>

        <section v-if="showTiebreakers" class="tiebreakers">
          <h2>Barrages</h2>
          <div
            v-for="m in data.tiebreakerMatches"
            :key="m.id"
            class="tb-row"
            :class="{ live: m.status === 'live', done: m.status === 'done' }"
          >
            <span class="pill">Barrage</span>
            <span>{{ formatName(m.homeTeam?.name) || 'TBD' }}</span>
            <em>vs</em>
            <span>{{ formatName(m.awayTeam?.name) || 'TBD' }}</span>
            <span v-if="m.winner" class="success">→ {{ formatName(m.winner.name) }}</span>
          </div>
        </section>

        <section v-if="bracketReady" class="bracket-wrap">
          <BracketBoard
            projection
            :matches="data.bracketMatches"
            :current-id="data.currentMatch?.id ?? null"
          />
        </section>
      </main>
    </template>
  </div>
</template>

<style scoped>
.proj {
  height: 100vh;
  max-height: 100vh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 0.75rem clamp(0.75rem, 2vw, 1.35rem);
  background:
    radial-gradient(900px 360px at 50% -10%, rgba(245, 213, 143, 0.12), transparent 55%),
    linear-gradient(180deg, #10141a 0%, #0b0d10 45%, #0b0d10 100%);
  color: var(--text);
}

/* Ne pas réutiliser .topbar global */
.proj-bar {
  flex: 0 0 auto;
  display: grid;
  grid-template-columns: minmax(140px, 0.8fr) minmax(0, 2.4fr) minmax(150px, 0.95fr);
  gap: 0.65rem;
  align-items: stretch;
}

.proj-brand {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  min-width: 0;
}

.proj-brand h1 {
  margin: 0;
  font-size: clamp(1.05rem, 2vw, 1.4rem);
  line-height: 1.1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.eyebrow {
  margin: 0 0 0.15rem;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-size: 0.65rem;
  color: var(--muted);
}

.proj-live,
.proj-next {
  border: 1px solid var(--line);
  border-radius: 12px;
  background: rgba(20, 24, 30, 0.92);
  padding: 0.55rem 0.8rem;
  min-width: 0;
}

.proj-live {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 0.3rem;
}

.proj-live.on {
  border-color: rgba(245, 213, 143, 0.7);
  box-shadow: 0 0 0 1px rgba(245, 213, 143, 0.2), 0 0 24px rgba(245, 213, 143, 0.12);
  animation: live-glow 1.4s ease-in-out infinite;
}

.proj-live-meta {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  flex-wrap: wrap;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.68rem;
  font-weight: 700;
  color: var(--muted);
}

.proj-live.on .proj-live-meta {
  color: var(--accent);
}

.live-dot {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  background: var(--muted);
}

.live-dot.on {
  background: var(--accent);
  animation: blink 1s ease-in-out infinite;
}

.pill {
  display: inline-flex;
  align-items: center;
  padding: 0.15rem 0.55rem;
  border-radius: 999px;
  border: 1px solid rgba(245, 213, 143, 0.35);
  background: var(--accent-soft);
  color: var(--cream, var(--accent));
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.proj-live-teams {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.55rem;
  font-family: var(--display);
  font-weight: 700;
  font-size: 1.05rem;
  line-height: 1.25;
}

.proj-live-teams > span,
.proj-next-teams > span,
.team-cell {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  color: var(--team-fg, inherit);
  min-width: 0;
}

.proj-live-teams em,
.proj-next-teams em {
  font-style: normal;
  color: var(--muted);
  font-size: 0.75rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.score {
  color: var(--accent);
}

.proj-next {
  display: grid;
  align-content: center;
  gap: 0.2rem;
}

.proj-next .label {
  margin: 0;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--accent);
  font-size: 0.65rem;
  font-weight: 700;
}

.proj-next-teams {
  margin: 0;
  font-size: 0.88rem;
  font-weight: 600;
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  align-items: center;
}

.small {
  font-size: 0.72rem;
  margin: 0;
}

.proj-main {
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

/* ——— Poules : zone principale ——— */
.poules {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  overflow: hidden;
}

.poules-title {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  flex: 0 0 auto;
}

.poules-title h2 {
  margin: 0;
  font-size: clamp(1.15rem, 2.2vw, 1.5rem);
}

.poules-title p {
  margin: 0;
  color: var(--muted);
}

.poules-title strong {
  color: var(--cream, var(--accent));
}

.poules-grid {
  flex: 1 1 auto;
  min-height: 0;
  display: grid;
  gap: 0.85rem;
  align-content: stretch;
  overflow: auto;
}

.poules-grid[data-count='1'] {
  grid-template-columns: minmax(0, 1fr);
}

.poules-grid[data-count='2'] {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.poules-grid[data-count='3'],
.poules-grid[data-count='4'] {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.poule-card {
  border: 1px solid var(--line);
  border-radius: 14px;
  background: rgba(20, 24, 30, 0.9);
  padding: 1rem 1.1rem;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.poule-card.active {
  border-color: rgba(245, 213, 143, 0.7);
  background:
    linear-gradient(180deg, rgba(245, 213, 143, 0.1), transparent 42%),
    rgba(20, 24, 30, 0.95);
  box-shadow: 0 0 0 1px rgba(245, 213, 143, 0.18), 0 0 30px rgba(245, 213, 143, 0.1);
}

.poule-card header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.poule-card h3 {
  margin: 0;
  font-size: 1.25rem;
  color: var(--cream, var(--accent));
}

.live-tag {
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.68rem;
  font-weight: 700;
  color: var(--accent);
}

.poule-card table {
  width: 100%;
  border-collapse: collapse;
}

.poule-card th,
.poule-card td {
  text-align: left;
  padding: 0.55rem 0.3rem;
  border-bottom: 1px solid var(--line);
  font-size: 1.05rem;
}

.poule-card th {
  color: var(--muted);
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.poule-card td:first-child,
.poule-card th:first-child {
  width: 2rem;
  color: var(--muted);
}

.poule-card th:nth-child(n + 3),
.poule-card td:nth-child(n + 3) {
  text-align: center;
  width: 2.8rem;
}

.poule-card tr.playing td {
  background: rgba(245, 213, 143, 0.12);
}

.poule-card tr.playing .team-cell {
  font-weight: 700;
}

.team-cell {
  font-weight: 600;
}

.swatch {
  display: inline-block;
  width: 0.65rem;
  height: 0.65rem;
  border-radius: 3px;
  background: var(--team-solid, var(--muted));
  box-shadow: 0 0 0 1px var(--team-border, transparent);
  flex-shrink: 0;
}

.swatch.tiny {
  width: 0.5rem;
  height: 0.5rem;
}

.swatch.big {
  width: 1.1rem;
  height: 1.1rem;
}

.bracket-wrap {
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

.tiebreakers {
  display: grid;
  gap: 0.5rem;
  overflow: auto;
}

.tiebreakers h2 {
  margin: 0;
}

.tb-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
  padding: 0.65rem 0.8rem;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: rgba(20, 24, 30, 0.75);
}

.tb-row.live {
  border-color: var(--accent);
}

.error {
  color: #ff8d8d;
  margin: 0;
}

.muted {
  color: var(--muted);
}

.success {
  color: var(--ok);
}

/* Finale */
.proj.is-finished {
  background:
    radial-gradient(700px 420px at 50% 35%, color-mix(in srgb, var(--team-solid, #e8a838) 28%, transparent), transparent 65%),
    linear-gradient(180deg, #121820 0%, #0b0d10 100%);
}

.finale {
  flex: 1;
  display: grid;
  place-content: center;
  justify-items: center;
  text-align: center;
  gap: 0.35rem;
  position: relative;
}

.finale-glow {
  position: absolute;
  inset: 20% 25%;
  border-radius: 50%;
  background: radial-gradient(circle, color-mix(in srgb, var(--team-solid, #e8a838) 35%, transparent), transparent 70%);
  filter: blur(10px);
  animation: pulse 2.4s ease-in-out infinite;
}

.finale-logo {
  width: min(120px, 28vw);
  position: relative;
  filter: drop-shadow(0 8px 24px rgba(245, 213, 143, 0.22));
}

.finale-eyebrow,
.finale-title,
.finale-label,
.finale-name,
.finale-players {
  position: relative;
  margin: 0;
}

.finale-eyebrow {
  text-transform: uppercase;
  letter-spacing: 0.2em;
  color: var(--muted);
  font-size: 0.8rem;
}

.finale-title {
  margin-bottom: 1rem;
  font-size: 1.35rem;
  color: #c9d0da;
}

.finale-label {
  text-transform: uppercase;
  letter-spacing: 0.25em;
  color: var(--accent);
  font-weight: 700;
}

.finale-name {
  display: inline-flex;
  align-items: center;
  gap: 0.7rem;
  font-family: var(--display);
  font-size: clamp(2.4rem, 7vw, 4.8rem);
  font-weight: 800;
  color: var(--team-fg, #fff);
}

.finale-players {
  color: var(--muted);
  margin-top: 0.35rem;
}

.finale-enter-active {
  transition: opacity 0.4s ease;
}
.finale-enter-from {
  opacity: 0;
}

@keyframes blink {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.35;
  }
}

@keyframes live-glow {
  0%,
  100% {
    box-shadow: 0 0 0 1px rgba(245, 213, 143, 0.2), 0 0 16px rgba(245, 213, 143, 0.08);
  }
  50% {
    box-shadow: 0 0 0 1px rgba(245, 213, 143, 0.5), 0 0 28px rgba(245, 213, 143, 0.22);
  }
}

@keyframes pulse {
  0%,
  100% {
    opacity: 0.55;
    transform: scale(0.96);
  }
  50% {
    opacity: 1;
    transform: scale(1.04);
  }
}

@media (max-width: 960px) {
  .proj-bar {
    grid-template-columns: 1fr 1fr;
  }

  .proj-live {
    grid-column: 1 / -1;
    order: -1;
  }

  .poules-grid[data-count='2'],
  .poules-grid[data-count='3'],
  .poules-grid[data-count='4'] {
    grid-template-columns: 1fr;
  }
}
</style>
