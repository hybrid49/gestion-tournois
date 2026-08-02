<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { api } from '../api/client'
import { brand } from '../brand'
import { formatName } from '../utils/formatName'
import { teamStyle } from '../utils/playerColor'

const route = useRoute()
const id = computed(() => Number(route.params.id))

const data = ref(null)
const error = ref('')
const message = ref('')
const loading = ref(false)
const pendingWinner = ref(null) // team object awaiting confirm
const scoreHome = ref('')
const scoreAway = ref('')
let timer = null
let msgTimer = null

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
  timer = setInterval(load, 2500)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
  if (msgTimer) clearTimeout(msgTimer)
})

watch(id, () => {
  pendingWinner.value = null
  scoreHome.value = ''
  scoreAway.value = ''
  load()
})

const current = computed(() => data.value?.currentMatch || null)
const next = computed(() => data.value?.nextMatch || null)
const tournament = computed(() => data.value?.tournament || null)
const champion = computed(() => data.value?.champion || null)
const finished = computed(() => !!data.value?.finished || !!champion.value)

const groups = computed(() => data.value?.groups || [])

function groupName(match) {
  if (!match?.groupId) return null
  return groups.value.find((g) => Number(g.id) === Number(match.groupId))?.name || null
}

function phaseLabel(match) {
  if (!match) return ''
  if (match.phase === 'group') return groupName(match) || 'Poules'
  const map = { winner: 'Winners', loser: 'Losers', final: 'Finale', tiebreaker: 'Barrage' }
  return `${map[match.phase] || match.phase} · R${match.round}`
}

function flash(msg) {
  message.value = msg
  if (msgTimer) clearTimeout(msgTimer)
  msgTimer = setTimeout(() => {
    message.value = ''
  }, 2200)
}

function askWinner(team) {
  if (!current.value || !team || loading.value) return
  pendingWinner.value = team
}

function cancelConfirm() {
  pendingWinner.value = null
}

async function confirmWinner() {
  const match = current.value
  const team = pendingWinner.value
  if (!match || !team) return
  loading.value = true
  error.value = ''
  try {
    await api.setResult(match.id, {
      winnerId: team.id,
      scoreHome: scoreHome.value === '' ? null : Number(scoreHome.value),
      scoreAway: scoreAway.value === '' ? null : Number(scoreAway.value),
    })
    pendingWinner.value = null
    scoreHome.value = ''
    scoreAway.value = ''
    flash(`${formatName(team.name)} gagne`)
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function startNext() {
  if (!next.value) return
  loading.value = true
  error.value = ''
  try {
    await api.updateDisplay(id.value, {
      currentMatchId: next.value.id,
      nextMatchId: null,
    })
    flash('Match lancé')
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="mgr">
    <header class="mgr-top">
      <div class="mgr-brand">
        <img :src="brand.logo" :alt="brand.logoAlt" class="logo" />
        <div>
          <p class="eyebrow">Gestion mobile</p>
          <h1>{{ tournament?.name || 'Tournoi' }}</h1>
        </div>
      </div>
      <RouterLink class="link" :to="`/tournaments/${id}`">Admin</RouterLink>
    </header>

    <p v-if="error" class="banner err">{{ error }}</p>
    <p v-if="message" class="banner ok">{{ message }}</p>

    <!-- Fin de tournoi -->
    <section v-if="finished && champion" class="card done">
      <p class="label">Tournoi terminé</p>
      <p class="winner" :style="teamStyle(champion)">
        <i class="swatch" />
        {{ formatName(champion.name) }}
      </p>
      <p class="muted">Vainqueur</p>
    </section>

    <template v-else>
      <!-- Match en cours -->
      <section v-if="current" class="card live">
        <div class="meta">
          <span class="live-badge"><i class="dot" /> Match en cours</span>
          <span class="pill">{{ phaseLabel(current) }}</span>
        </div>

        <div class="versus">
          <div class="side" :style="teamStyle(current.homeTeam)">
            <i class="swatch" />
            <strong>{{ formatName(current.homeTeam?.name) || 'TBD' }}</strong>
          </div>
          <span class="vs">VS</span>
          <div class="side" :style="teamStyle(current.awayTeam)">
            <i class="swatch" />
            <strong>{{ formatName(current.awayTeam?.name) || 'TBD' }}</strong>
          </div>
        </div>

        <div class="scores">
          <label>
            Score
            <input v-model="scoreHome" type="number" inputmode="numeric" min="0" placeholder="—" />
          </label>
          <span class="muted">:</span>
          <label>
            &nbsp;
            <input v-model="scoreAway" type="number" inputmode="numeric" min="0" placeholder="—" />
          </label>
        </div>

        <p class="hint">Qui gagne ?</p>
        <div class="actions" v-if="current.homeTeam && current.awayTeam">
          <button
            type="button"
            class="win"
            :style="teamStyle(current.homeTeam)"
            :disabled="loading"
            @click="askWinner(current.homeTeam)"
          >
            {{ formatName(current.homeTeam.name) }}
          </button>
          <button
            type="button"
            class="win"
            :style="teamStyle(current.awayTeam)"
            :disabled="loading"
            @click="askWinner(current.awayTeam)"
          >
            {{ formatName(current.awayTeam.name) }}
          </button>
        </div>
        <p v-else class="muted center">En attente des deux équipes</p>
      </section>

      <!-- Pas de match courant -->
      <section v-else class="card idle">
        <p class="label">Aucun match en cours</p>
        <template v-if="next">
          <p class="next-line">
            Prochain :
            <strong :style="teamStyle(next.homeTeam)">{{ formatName(next.homeTeam?.name) || 'TBD' }}</strong>
            vs
            <strong :style="teamStyle(next.awayTeam)">{{ formatName(next.awayTeam?.name) || 'TBD' }}</strong>
          </p>
          <button type="button" class="primary big" :disabled="loading" @click="startNext">
            Lancer ce match
          </button>
        </template>
        <p v-else class="muted">Rien à jouer pour le moment.</p>
      </section>

      <p v-if="next && current" class="footer-next">
        Ensuite :
        {{ formatName(next.homeTeam?.name) || 'TBD' }}
        vs
        {{ formatName(next.awayTeam?.name) || 'TBD' }}
        <template v-if="groupName(next)"> · {{ groupName(next) }}</template>
      </p>
    </template>

    <!-- Confirmation plein écran -->
    <div v-if="pendingWinner" class="confirm" role="dialog" aria-modal="true">
      <div class="confirm-card">
        <p class="label">Confirmer le résultat</p>
        <p class="confirm-name" :style="teamStyle(pendingWinner)">
          <i class="swatch" />
          {{ formatName(pendingWinner.name) }}
        </p>
        <p class="muted">est déclaré vainqueur ?</p>
        <div class="confirm-actions">
          <button type="button" class="ghost big" :disabled="loading" @click="cancelConfirm">
            Annuler
          </button>
          <button type="button" class="primary big" :disabled="loading" @click="confirmWinner">
            {{ loading ? 'Envoi…' : 'Confirmer' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.mgr {
  min-height: 100vh;
  min-height: 100dvh;
  padding: 0.85rem 0.9rem calc(1rem + env(safe-area-inset-bottom));
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  background:
    radial-gradient(700px 320px at 50% -10%, rgba(245, 213, 143, 0.14), transparent 55%),
    #0c0e12;
  color: var(--text);
}

.mgr-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.mgr-brand {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  min-width: 0;
}

.logo {
  width: 40px;
  height: 40px;
  object-fit: contain;
}

.eyebrow {
  margin: 0;
  font-size: 0.65rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--muted);
}

.mgr-top h1 {
  margin: 0;
  font-size: 1.1rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.link {
  color: var(--muted);
  font-size: 0.85rem;
  flex-shrink: 0;
}

.banner {
  margin: 0;
  padding: 0.55rem 0.75rem;
  border-radius: 10px;
  font-weight: 600;
}

.banner.err {
  background: rgba(232, 106, 90, 0.15);
  color: #ff8d8d;
}

.banner.ok {
  background: rgba(94, 207, 154, 0.15);
  color: var(--ok);
}

.card {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  border: 1px solid var(--line);
  border-radius: 16px;
  padding: 1.1rem 1rem;
  background: rgba(20, 24, 30, 0.95);
}

.card.live {
  border-color: rgba(245, 213, 143, 0.55);
  box-shadow: 0 0 0 1px rgba(245, 213, 143, 0.15), 0 0 28px rgba(245, 213, 143, 0.1);
}

.meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.live-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--accent);
}

.dot {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  background: var(--accent);
  animation: blink 1s ease-in-out infinite;
}

.pill {
  display: inline-flex;
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  background: var(--accent-soft);
  border: 1px solid rgba(245, 213, 143, 0.35);
  color: var(--cream, var(--accent));
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.versus {
  display: grid;
  gap: 0.65rem;
  margin: 0.25rem 0;
}

.side {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.85rem 0.9rem;
  border-radius: 12px;
  background: var(--team-bg, rgba(255, 255, 255, 0.03));
  border: 1px solid var(--team-border, var(--line));
  color: var(--team-fg, inherit);
}

.side strong {
  font-size: 1.25rem;
  font-family: var(--display);
  line-height: 1.2;
}

.vs {
  text-align: center;
  color: var(--muted);
  font-size: 0.75rem;
  letter-spacing: 0.2em;
  font-weight: 700;
}

.scores {
  display: flex;
  align-items: end;
  justify-content: center;
  gap: 0.5rem;
}

.scores label {
  width: 5.5rem;
  text-align: center;
}

.scores input {
  text-align: center;
  font-size: 1.2rem;
  font-weight: 700;
  padding: 0.55rem;
}

.hint {
  margin: 0;
  text-align: center;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--muted);
}

.actions {
  display: grid;
  gap: 0.75rem;
  margin-top: auto;
}

.win {
  min-height: 3.6rem;
  border-radius: 14px;
  border: 2px solid var(--team-border, var(--accent));
  background: linear-gradient(180deg, var(--team-bg, rgba(245, 213, 143, 0.12)), rgba(20, 24, 30, 0.95));
  color: var(--team-fg, var(--text));
  font-family: var(--display);
  font-size: 1.15rem;
  font-weight: 700;
  padding: 0.9rem 1rem;
  line-height: 1.2;
}

.win:active:not(:disabled) {
  transform: scale(0.98);
}

.primary.big,
.ghost.big {
  min-height: 3.2rem;
  font-size: 1.05rem;
  font-weight: 700;
  border-radius: 12px;
}

.idle {
  justify-content: center;
  text-align: center;
  gap: 1rem;
}

.label {
  margin: 0;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--accent);
}

.next-line {
  margin: 0;
  font-size: 1.05rem;
  line-height: 1.4;
}

.footer-next {
  margin: 0;
  text-align: center;
  color: var(--muted);
  font-size: 0.85rem;
}

.center {
  text-align: center;
}

.swatch {
  display: inline-block;
  width: 0.75rem;
  height: 0.75rem;
  border-radius: 3px;
  background: var(--team-solid, var(--muted));
  flex-shrink: 0;
}

.done {
  justify-content: center;
  align-items: center;
  text-align: center;
  gap: 0.5rem;
}

.winner {
  margin: 0.5rem 0 0;
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  font-family: var(--display);
  font-size: 1.8rem;
  font-weight: 800;
  color: var(--team-fg, #fff);
}

.confirm {
  position: fixed;
  inset: 0;
  z-index: 50;
  background: rgba(0, 0, 0, 0.72);
  display: grid;
  place-items: center;
  padding: 1rem;
}

.confirm-card {
  width: min(420px, 100%);
  border: 1px solid var(--line);
  border-radius: 16px;
  background: #151a21;
  padding: 1.35rem 1.1rem;
  display: grid;
  gap: 0.55rem;
  text-align: center;
}

.confirm-name {
  margin: 0.35rem 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  font-family: var(--display);
  font-size: 1.55rem;
  font-weight: 800;
  color: var(--team-fg, #fff);
}

.confirm-actions {
  display: grid;
  grid-template-columns: 1fr 1.2fr;
  gap: 0.65rem;
  margin-top: 0.75rem;
}

.muted {
  color: var(--muted);
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
</style>
