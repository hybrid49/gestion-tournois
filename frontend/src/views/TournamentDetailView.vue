<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { api } from '../api/client'
import MatchList from '../components/MatchList.vue'
import BracketBoard from '../components/BracketBoard.vue'
import PlayerAutocomplete from '../components/PlayerAutocomplete.vue'
import { formatName } from '../utils/formatName'

const route = useRoute()
const id = computed(() => Number(route.params.id))
const tournament = ref(null)
const error = ref('')
const message = ref('')
const playerName = ref('')
const playerId = ref(null)
const player1Name = ref('')
const player1Id = ref(null)
const player2Name = ref('')
const player2Id = ref(null)
const duoNames = ref('')
const scoreDraft = ref({})
const activeTab = ref('inscriptions')
let pollTimer = null

const groupMatches = computed(() =>
  (tournament.value?.matches || []).filter((m) => m.phase === 'group'),
)
const tiebreakerMatches = computed(() =>
  (tournament.value?.matches || []).filter((m) => m.phase === 'tiebreaker'),
)
const bracketMatches = computed(() =>
  (tournament.value?.matches || []).filter(
    (m) => m.phase !== 'group' && m.phase !== 'tiebreaker',
  ),
)
const unresolvedTies = computed(() => tournament.value?.groupStage?.ties || [])
const pendingMatches = computed(() =>
  (tournament.value?.matches || []).filter(
    (m) => m.status !== 'done' && m.homeTeam && m.awayTeam,
  ),
)
const currentMatch = computed(() =>
  (tournament.value?.matches || []).find((m) => m.id === tournament.value?.currentMatchId) || null,
)
const nextMatch = computed(() =>
  (tournament.value?.matches || []).find((m) => m.id === tournament.value?.nextMatchId) || null,
)
const groupStageComplete = computed(() => {
  if (!tournament.value?.hasGroupStage) return true
  return !!tournament.value?.groupStage?.complete
})
const canGenerateBracket = computed(() => {
  if (!tournament.value) return false
  if (tournament.value.hasGroupStage) {
    return !!tournament.value?.groupStage?.readyForBracket
  }
  return (tournament.value.teams?.length || 0) >= 2
})
const bracketBlockReason = computed(() => {
  if (!tournament.value?.hasGroupStage) return ''
  const gs = tournament.value.groupStage
  if (!gs?.complete) return 'Tous les matchs de poule doivent avoir un résultat.'
  if (!gs?.tiebreakersComplete) return 'Terminez les matchs de barrage.'
  if (gs?.ties?.length) return 'Résolvez les égalités via un barrage avant le bracket.'
  return ''
})

const tabs = computed(() => {
  const list = [
    {
      id: 'inscriptions',
      label: 'Inscriptions',
      hint: `${tournament.value?.teams?.length || 0} équipes`,
    },
  ]
  if (tournament.value?.hasGroupStage) {
    list.push({
      id: 'poules',
      label: 'Poules',
      hint: groupMatches.value.length
        ? `${tournament.value?.groupStage?.done || 0}/${tournament.value?.groupStage?.total || 0}`
        : 'à générer',
    })
  }
  list.push({
    id: 'bracket',
    label: 'Bracket',
    hint: bracketMatches.value.length ? `${bracketMatches.value.length} matchs` : 'à générer',
  })
  list.push({
    id: 'projection',
    label: 'Projection',
    hint: currentMatch.value ? 'live' : '—',
  })
  return list
})

function pickDefaultTab(t) {
  if (!t) return 'inscriptions'
  if (t.status === 'bracket' || t.status === 'finished') return 'bracket'
  if (t.hasGroupStage && (t.status === 'groups' || (t.groups?.length && !t.groupStage?.complete))) {
    return 'poules'
  }
  if (t.hasGroupStage && t.groupStage?.complete && !t.matches?.some((m) => m.phase !== 'group')) {
    return 'bracket'
  }
  return 'inscriptions'
}

async function load() {
  error.value = ''
  try {
    const data = await api.getTournament(id.value)
    const firstLoad = !tournament.value
    tournament.value = data
    if (firstLoad || !tabs.value.some((tab) => tab.id === activeTab.value)) {
      activeTab.value = pickDefaultTab(data)
    }
  } catch (e) {
    error.value = e.message
  }
}

async function run(action, successMsg, goToTab = null) {
  error.value = ''
  message.value = ''
  try {
    await action()
    message.value = successMsg
    await load()
    if (goToTab) activeTab.value = goToTab
  } catch (e) {
    error.value = e.message
  }
}

const registeredPlayerIds = computed(() => {
  const ids = []
  for (const team of tournament.value?.teams || []) {
    if (team.player1?.id) ids.push(team.player1.id)
    if (team.player2?.id) ids.push(team.player2.id)
  }
  return ids
})

async function registerSolo(override = null) {
  const name = formatName(override?.name ?? playerName.value)
  const pid = override?.id ?? playerId.value
  if (!name.trim()) return
  await run(
    () =>
      api.register(id.value, {
        playerName: name,
        playerId: pid || null,
      }),
    'Joueur inscrit',
  )
  playerName.value = ''
  playerId.value = null
}

async function registerDuo(override = null) {
  const p1Name = formatName(override?.player1Name ?? player1Name.value)
  const p1Id = override?.player1Id ?? player1Id.value
  const p2Name = formatName(override?.player2Name ?? player2Name.value)
  const p2Id = override?.player2Id ?? player2Id.value
  if (!p1Name.trim() || !p2Name.trim()) return
  await run(
    () =>
      api.register(id.value, {
        player1Name: p1Name,
        player1Id: p1Id || null,
        player2Name: p2Name,
        player2Id: p2Id || null,
      }),
    'Équipe inscrite',
  )
  player1Name.value = ''
  player1Id.value = null
  player2Name.value = ''
  player2Id.value = null
}

async function onPickSolo(player) {
  // Inscription immédiate au clic suggestion
  playerName.value = player.name
  playerId.value = player.id
  await registerSolo({ id: player.id, name: player.name })
}

async function onPickDuo1(player) {
  player1Name.value = player.name
  player1Id.value = player.id
  if (player2Name.value.trim()) {
    await registerDuo({
      player1Name: player.name,
      player1Id: player.id,
      player2Name: player2Name.value,
      player2Id: player2Id.value,
    })
  }
}

async function onPickDuo2(player) {
  player2Name.value = player.name
  player2Id.value = player.id
  if (player1Name.value.trim()) {
    await registerDuo({
      player1Name: player1Name.value,
      player1Id: player1Id.value,
      player2Name: player.name,
      player2Id: player.id,
    })
  }
}

async function randomDuos() {
  const names = duoNames.value
    .split(/\n|,/)
    .map((s) => s.trim())
    .filter(Boolean)
  await run(() => api.registerDuoDraw(id.value, names), 'Duos formés par tirage')
  duoNames.value = ''
}

async function removeRegistration(team) {
  const label = formatName(team.name || team.player1?.name) || 'cette inscription'
  if (!confirm(`Retirer ${label} du tournoi ?`)) return
  await run(() => api.unregister(id.value, team.id), 'Inscription retirée')
}

async function setWinner(match, winnerId) {
  const draft = scoreDraft.value[match.id] || {}
  const wasDone = match.status === 'done'
  error.value = ''
  message.value = ''
  try {
    await api.setResult(match.id, {
      winnerId,
      scoreHome: draft.home === '' || draft.home == null ? null : Number(draft.home),
      scoreAway: draft.away === '' || draft.away == null ? null : Number(draft.away),
    })
    await load()
    if (wasDone) {
      message.value = 'Résultat corrigé'
      return
    }
    const current = tournament.value?.matches?.find(
      (m) => m.id === tournament.value?.currentMatchId,
    )
    message.value = current
      ? `Résultat enregistré — projection : ${formatName(current.homeTeam?.name) || 'TBD'} vs ${formatName(current.awayTeam?.name) || 'TBD'}`
      : 'Résultat enregistré'
  } catch (e) {
    error.value = e.message
  }
}

async function setAsCurrent(matchId) {
  const pending = pendingMatches.value.filter((m) => m.id !== matchId)
  const next = pending[0]
  await run(
    () =>
      api.updateDisplay(id.value, {
        currentMatchId: matchId,
        nextMatchId: next?.id ?? null,
      }),
    'Projection mise à jour',
  )
}

function startPolling() {
  stopPolling()
  pollTimer = setInterval(() => {
    if (document.visibilityState === 'visible') load()
  }, 3000)
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

watch(id, () => {
  tournament.value = null
  activeTab.value = 'inscriptions'
  load()
})
onMounted(() => {
  load()
  startPolling()
})
onUnmounted(stopPolling)
</script>

<template>
  <div v-if="tournament" class="stack detail">
    <div class="row header">
      <div>
        <p class="muted"><RouterLink to="/">← Tournois</RouterLink></p>
        <h1>{{ tournament.name }}</h1>
        <p class="muted">
          <span class="badge">{{ tournament.status }}</span>
          · {{ tournament.teamMode }}
          · {{ tournament.bracketType }}
          <template v-if="tournament.hasGroupStage"> · poules</template>
        </p>
      </div>
      <div class="actions">
        <RouterLink class="btn primary" :to="`/manage/${tournament.id}`">
          Téléphone
        </RouterLink>
        <RouterLink class="btn" :to="`/display/${tournament.id}`" target="_blank">
          Projection
        </RouterLink>
      </div>
    </div>

    <nav class="tabs" aria-label="Étapes du tournoi">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        type="button"
        class="tab"
        :class="{ active: activeTab === tab.id }"
        @click="activeTab = tab.id"
      >
        <span class="tab-label">{{ tab.label }}</span>
        <span class="tab-hint">{{ tab.hint }}</span>
      </button>
    </nav>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="message" class="success">{{ message }}</p>

    <!-- Inscriptions -->
    <section v-show="activeTab === 'inscriptions'" class="panel stack">
      <div>
        <h2>Inscriptions</h2>
        <p class="muted">Ajoutez les participants, puis lancez le tirage au sort.</p>
      </div>

      <form v-if="tournament.teamMode === 'solo'" class="row" @submit.prevent="registerSolo()">
        <PlayerAutocomplete
          v-model="playerName"
          v-model:player-id="playerId"
          label="Joueur"
          placeholder="Nom du joueur"
          required
          style="flex: 1"
          :exclude-ids="registeredPlayerIds"
          @pick="onPickSolo"
        />
        <button class="primary" type="submit">Inscrire</button>
      </form>
      <template v-else>
        <form class="grid-2" @submit.prevent="registerDuo()">
          <PlayerAutocomplete
            v-model="player1Name"
            v-model:player-id="player1Id"
            label="Joueur 1"
            required
            :exclude-ids="registeredPlayerIds"
            @pick="onPickDuo1"
          />
          <PlayerAutocomplete
            v-model="player2Name"
            v-model:player-id="player2Id"
            label="Joueur 2"
            required
            :exclude-ids="registeredPlayerIds"
            @pick="onPickDuo2"
          />
          <button class="primary" type="submit">Inscrire le duo</button>
        </form>
        <label>
          Ou liste de joueurs (tirage des duos)
          <textarea v-model="duoNames" rows="4" placeholder="Un nom par ligne — réutilise les joueurs existants si le nom correspond" />
        </label>
        <button type="button" @click="randomDuos">Former les duos au hasard</button>
      </template>

      <table class="table" v-if="tournament.teams?.length">
        <thead>
          <tr>
            <th>Seed</th>
            <th>Équipe</th>
            <th>Joueurs</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="team in tournament.teams" :key="team.id">
            <td>{{ team.seed || '—' }}</td>
            <td>{{ formatName(team.name) }}</td>
            <td>
              {{ formatName(team.player1?.name) }}
              <template v-if="team.player2"> / {{ formatName(team.player2.name) }}</template>
            </td>
            <td>
              <button
                type="button"
                class="danger ghost"
                :disabled="(tournament.matches?.length || 0) > 0"
                :title="
                  (tournament.matches?.length || 0) > 0
                    ? 'Des matchs existent déjà — impossible de retirer'
                    : 'Retirer l’inscription'
                "
                @click="removeRegistration(team)"
              >
                Retirer
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">Aucune inscription pour le moment.</p>

      <div class="row actions">
        <button type="button" @click="run(() => api.draw(id), 'Tirage effectué')">
          Tirage au sort
        </button>
        <button
          v-if="tournament.hasGroupStage"
          type="button"
          class="primary"
          :disabled="(tournament.teams?.length || 0) < 2"
          @click="run(() => api.generateGroups(id), 'Poules générées', 'poules')"
        >
          Générer les poules →
        </button>
        <button
          v-else
          type="button"
          class="primary"
          :disabled="!canGenerateBracket"
          @click="run(() => api.generateBracket(id), 'Bracket généré', 'bracket')"
        >
          Générer le bracket →
        </button>
      </div>
    </section>

    <!-- Poules -->
    <section v-show="activeTab === 'poules'" class="panel stack">
      <div class="row header-inline">
        <div>
          <h2>Phase de poules</h2>
          <p class="muted">Saisissez les résultats. Le bracket se débloque à la fin.</p>
        </div>
        <button type="button" @click="run(() => api.generateGroups(id), 'Poules régénérées')">
          Régénérer les poules
        </button>
      </div>

      <p v-if="groupMatches.length" class="progress">
        Progression :
        <strong>{{ tournament.groupStage?.done || 0 }}</strong> /
        {{ tournament.groupStage?.total || groupMatches.length }}
        <span v-if="groupStageComplete" class="success"> — poules terminées</span>
      </p>

      <div v-if="unresolvedTies.length" class="tie-box stack">
        <h3>Égalités à trancher</h3>
        <p class="muted">
          Des équipes sont à égalité pour la qualification. Choisissez un format de barrage.
        </p>
        <div v-for="tie in unresolvedTies" :key="tie.groupId" class="tie-card stack">
          <p>
            <strong>{{ tie.groupName }}</strong>
            —
            {{ tie.spots }} place{{ tie.spots > 1 ? 's' : '' }}
            pour
            {{ tie.teams.length }} équipes :
            {{ tie.teams.map((t) => t?.name).filter(Boolean).join(', ') }}
          </p>
          <div class="row">
            <button
              v-for="mode in tie.modes"
              :key="mode.id"
              type="button"
              class="primary"
              @click="
                run(
                  () => api.createTiebreakers(id, { groupId: tie.groupId, mode: mode.id }),
                  'Barrage créé',
                )
              "
            >
              {{ mode.label }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="tournament.groups?.length" class="grid-2">
        <div v-for="group in tournament.groups" :key="group.id" class="mini">
          <h3>{{ group.name }}</h3>
          <table class="table">
            <thead>
              <tr>
                <th>Équipe</th>
                <th>Pts</th>
                <th>V</th>
                <th>D</th>
                <th>+/−</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in group.standings" :key="s.id">
                <td>{{ s.team?.name }}</td>
                <td>{{ s.points }}</td>
                <td>{{ s.wins }}</td>
                <td>{{ s.losses }}</td>
                <td>{{ s.scoreDiff }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <p v-else class="muted">
        Aucune poule. Retournez à Inscriptions pour les générer.
      </p>

      <template v-if="groupMatches.length">
        <h3>Matchs de poules</h3>
        <MatchList
          :matches="groupMatches"
          :score-draft="scoreDraft"
          :current-id="tournament.currentMatchId"
          @winner="setWinner"
          @current="setAsCurrent"
        />
      </template>

      <template v-if="tiebreakerMatches.length">
        <h3>Barrages</h3>
        <MatchList
          :matches="tiebreakerMatches"
          :score-draft="scoreDraft"
          :current-id="tournament.currentMatchId"
          @winner="setWinner"
          @current="setAsCurrent"
        />
      </template>

      <div class="row actions">
        <button
          type="button"
          class="primary"
          :disabled="!canGenerateBracket"
          :title="bracketBlockReason"
          @click="run(() => api.generateBracket(id), 'Bracket généré', 'bracket')"
        >
          Générer le bracket →
        </button>
        <p v-if="bracketBlockReason" class="muted">{{ bracketBlockReason }}</p>
      </div>
    </section>

    <!-- Bracket -->
    <section v-show="activeTab === 'bracket'" class="panel stack">
      <div class="row header-inline">
        <div>
          <h2>Bracket</h2>
          <p class="muted">
            {{ tournament.bracketType === 'double' ? 'Double élimination' : 'Élimination directe' }}
          </p>
        </div>
        <div class="row">
          <button
            type="button"
            class="primary"
            :disabled="!canGenerateBracket"
            @click="run(() => api.generateBracket(id), 'Bracket généré')"
          >
            {{ bracketMatches.length ? 'Régénérer' : 'Générer' }} le bracket
          </button>
          <button
            v-if="bracketMatches.length && tournament.bracketType === 'double'"
            type="button"
            @click="run(() => api.rebuildBracket(id), 'Bracket reconstruit')"
          >
            Réparer looser bracket
          </button>
        </div>
      </div>

      <template v-if="bracketMatches.length">
        <BracketBoard
          :matches="bracketMatches"
          :current-id="tournament.currentMatchId"
          @select="(m) => m.homeTeam && m.awayTeam && setAsCurrent(m.id)"
        />
        <details class="match-details">
          <summary>Liste des matchs (saisie)</summary>
          <MatchList
            :matches="bracketMatches"
            :score-draft="scoreDraft"
            :current-id="tournament.currentMatchId"
            @winner="setWinner"
            @current="setAsCurrent"
          />
        </details>
      </template>
      <p v-else class="muted">
        <template v-if="tournament.hasGroupStage && !groupStageComplete">
          Terminez d’abord tous les matchs de poule.
        </template>
        <template v-else>
          Aucun bracket pour le moment.
        </template>
      </p>
    </section>

    <!-- Projection -->
    <section v-show="activeTab === 'projection'" class="panel stack">
      <div class="row header-inline">
        <div>
          <h2>Contrôle projection</h2>
          <p class="muted">Choisissez le match affiché sur l’écran public.</p>
        </div>
        <RouterLink class="btn primary" :to="`/display/${tournament.id}`" target="_blank">
          Ouvrir l’écran
        </RouterLink>
      </div>

      <div class="grid-2">
        <div class="spotlight">
          <p class="label">Match en cours</p>
          <p v-if="currentMatch" class="spotlight-teams">
            {{ formatName(currentMatch.homeTeam?.name) || 'TBD' }}
            <span class="muted">vs</span>
            {{ formatName(currentMatch.awayTeam?.name) || 'TBD' }}
          </p>
          <p v-else class="muted">Aucun</p>
        </div>
        <div class="spotlight">
          <p class="label">Prochain</p>
          <p v-if="nextMatch" class="spotlight-teams">
            {{ formatName(nextMatch.homeTeam?.name) || 'TBD' }}
            <span class="muted">vs</span>
            {{ formatName(nextMatch.awayTeam?.name) || 'TBD' }}
          </p>
          <p v-else class="muted">Non défini</p>
        </div>
      </div>

      <h3>Matchs prêts à projeter</h3>
      <div v-if="pendingMatches.length" class="stack">
        <div v-for="m in pendingMatches" :key="m.id" class="proj-row">
          <div>
            <span class="badge">{{ m.phase }}</span>
            <strong>
              {{ formatName(m.homeTeam?.name) }} vs {{ formatName(m.awayTeam?.name) }}
            </strong>
          </div>
          <button
            type="button"
            class="primary"
            :disabled="m.id === tournament.currentMatchId"
            @click="setAsCurrent(m.id)"
          >
            {{ m.id === tournament.currentMatchId ? 'En cours' : 'Projeter' }}
          </button>
        </div>
      </div>
      <p v-else class="muted">Aucun match en attente avec deux équipes.</p>
    </section>
  </div>
  <p v-else class="muted">Chargement…</p>
</template>

<style scoped>
.detail {
  gap: 1.1rem;
}

.header {
  justify-content: space-between;
  align-items: start;
}

.header-inline {
  justify-content: space-between;
  align-items: start;
}

.tabs {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
  border-bottom: 1px solid var(--line);
  padding-bottom: 0.35rem;
}

.tab {
  display: grid;
  gap: 0.1rem;
  text-align: left;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 8px 8px 0 0;
  padding: 0.55rem 0.85rem;
  color: var(--muted);
}

.tab:hover {
  color: var(--text);
  border-color: var(--line);
}

.tab.active {
  color: var(--text);
  background: var(--bg-elevated);
  border-color: var(--line);
  border-bottom-color: var(--bg-elevated);
  box-shadow: inset 0 -2px 0 var(--accent);
}

.tab-label {
  font-weight: 600;
  font-size: 0.95rem;
}

.tab-hint {
  font-size: 0.72rem;
  opacity: 0.8;
}

.actions {
  align-items: center;
  padding-top: 0.25rem;
}

.progress {
  margin: 0;
}

.mini {
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 0.75rem;
  background: rgba(255, 255, 255, 0.015);
}

.primary-link {
  display: inline-flex;
  align-items: center;
  padding: 0.55rem 0.9rem;
  border-radius: 8px;
  background: var(--accent);
  color: #16120a;
  font-weight: 600;
}

.match-details {
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 0.75rem 1rem;
  background: rgba(255, 255, 255, 0.015);
}

.match-details summary {
  cursor: pointer;
  font-weight: 600;
  margin-bottom: 0.5rem;
}

.spotlight {
  border: 1px solid var(--line);
  border-radius: 10px;
  padding: 1rem;
  background: rgba(255, 255, 255, 0.02);
}

.label {
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.72rem;
  color: var(--accent);
  margin: 0 0 0.4rem;
  font-weight: 700;
}

.spotlight-teams {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 600;
}

.proj-row {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: center;
  padding: 0.65rem 0.75rem;
  border: 1px solid var(--line);
  border-radius: 8px;
}

.proj-row .badge {
  margin-right: 0.5rem;
}

.tie-box {
  border: 1px solid rgba(232, 168, 56, 0.45);
  background: rgba(232, 168, 56, 0.08);
  border-radius: 10px;
  padding: 1rem;
}

.tie-card {
  border-top: 1px solid rgba(232, 168, 56, 0.25);
  padding-top: 0.75rem;
}

.tie-card:first-of-type {
  border-top: none;
  padding-top: 0;
}

@media (max-width: 700px) {
  .header-inline {
    flex-direction: column;
  }

  .tab {
    flex: 1 1 calc(50% - 0.4rem);
  }
}
</style>
