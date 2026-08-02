<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '../api/client'

const tournaments = ref([])
const error = ref('')
const form = ref({
  name: '',
  hasGroupStage: true,
  bracketType: 'single',
  teamMode: 'solo',
  groupCount: 2,
  qualifiersPerGroup: 2,
})

async function load() {
  try {
    tournaments.value = await api.listTournaments()
  } catch (e) {
    error.value = e.message
  }
}

async function create() {
  error.value = ''
  try {
    await api.createTournament({
      ...form.value,
      hasGroupStage: form.value.hasGroupStage === true || form.value.hasGroupStage === 'true',
      groupCount: Number(form.value.groupCount),
      qualifiersPerGroup: Number(form.value.qualifiersPerGroup),
    })
    form.value.name = ''
    await load()
  } catch (e) {
    error.value = e.message
  }
}

async function remove(id) {
  if (!confirm('Supprimer ce tournoi ?')) return
  await api.deleteTournament(id)
  await load()
}

function statusLabel(status) {
  const map = {
    draft: 'Brouillon',
    registration: 'Inscriptions',
    groups: 'Poules',
    tiebreaker: 'Barrages',
    bracket: 'Bracket',
    finished: 'Terminé',
  }
  return map[status] || status
}

onMounted(load)
</script>

<template>
  <div class="stack">
    <div>
      <h1>Tournois</h1>
      <p class="muted">La Routine · Nantes — créez un tournoi, inscrivez les joueurs, tirez au sort, jouez.</p>
    </div>

    <section class="panel stack">
      <h2>Nouveau tournoi</h2>
      <form class="stack" @submit.prevent="create">
        <div class="grid-2">
          <label>
            Nom
            <input v-model="form.name" required placeholder="Soirée La Routine #1" />
          </label>
          <label>
            Mode équipe
            <select v-model="form.teamMode">
              <option value="solo">Solo</option>
              <option value="duo">Duo</option>
            </select>
          </label>
          <label>
            Bracket
            <select v-model="form.bracketType">
              <option value="single">Simple élimination</option>
              <option value="double">Double élimination (looser bracket)</option>
            </select>
          </label>
          <label>
            Phase de poules
            <select v-model="form.hasGroupStage">
              <option :value="true">Oui</option>
              <option :value="false">Non</option>
            </select>
          </label>
          <label v-if="form.hasGroupStage">
            Nombre de poules
            <input v-model.number="form.groupCount" type="number" min="1" />
          </label>
          <label v-if="form.hasGroupStage">
            Qualifiés / poule
            <input v-model.number="form.qualifiersPerGroup" type="number" min="1" />
          </label>
        </div>
        <p v-if="error" class="error">{{ error }}</p>
        <div class="row">
          <button class="primary" type="submit">Créer</button>
        </div>
      </form>
    </section>

    <section class="panel list-panel">
      <div v-if="tournaments.length" class="tourney-list">
        <article v-for="t in tournaments" :key="t.id" class="tourney-card">
          <div class="tourney-info">
            <RouterLink class="tourney-name" :to="`/tournaments/${t.id}`">
              {{ t.name }}
            </RouterLink>
            <p class="tourney-meta">
              <span class="badge">{{ statusLabel(t.status) }}</span>
              <span>{{ t.teamMode }}{{ t.hasGroupStage ? ' · poules' : '' }}</span>
              <span>·</span>
              <span>{{ t.bracketType === 'double' ? 'double élim.' : 'simple élim.' }}</span>
            </p>
          </div>
          <div class="actions">
            <RouterLink class="btn primary" :to="`/tournaments/${t.id}`">
              Ouvrir
            </RouterLink>
            <RouterLink class="btn" :to="`/manage/${t.id}`">
              Téléphone
            </RouterLink>
            <RouterLink class="btn ghost" :to="`/display/${t.id}`" target="_blank">
              Projection
            </RouterLink>
            <button class="danger ghost" type="button" @click="remove(t.id)">
              Supprimer
            </button>
          </div>
        </article>
      </div>
      <p v-else class="muted">Aucun tournoi pour le moment.</p>
    </section>
  </div>
</template>

<style scoped>
.list-panel {
  padding: 0.65rem;
}

.tourney-list {
  display: grid;
  gap: 0.55rem;
}

.tourney-card {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.85rem 1rem;
  padding: 0.85rem 0.95rem;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.015);
}

.tourney-info {
  min-width: 0;
  display: grid;
  gap: 0.35rem;
}

.tourney-name {
  font-family: var(--display);
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--text);
  text-decoration: none;
}

.tourney-name:hover {
  color: var(--cream, var(--accent));
}

.tourney-meta {
  margin: 0;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.55rem;
  color: var(--muted);
  font-size: 0.9rem;
}

@media (max-width: 700px) {
  .tourney-card {
    flex-direction: column;
    align-items: stretch;
  }

  .actions {
    justify-content: stretch;
  }

  .actions :deep(.btn),
  .actions button {
    flex: 1 1 calc(50% - 0.25rem);
  }
}
</style>
