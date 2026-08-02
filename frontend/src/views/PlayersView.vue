<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api/client'

const players = ref([])
const selected = ref(null)
const stats = ref(null)
const name = ref('')
const error = ref('')
const message = ref('')
const resetting = ref(false)

async function load() {
  players.value = await api.listPlayers()
}

async function create() {
  error.value = ''
  message.value = ''
  try {
    await api.createPlayer(name.value)
    name.value = ''
    await load()
  } catch (e) {
    error.value = e.message
  }
}

async function showStats(player) {
  selected.value = player
  stats.value = await api.playerStats(player.id)
}

async function resetHistory() {
  error.value = ''
  message.value = ''
  const ok = confirm(
    'Réinitialiser tout l’historique ?\n\n' +
      '• Victoires / défaites / tournois remis à 0\n' +
      '• Tous les tournois et matchs seront supprimés\n' +
      '• Les noms des joueurs sont conservés\n\n' +
      'Cette action est irréversible.',
  )
  if (!ok) return

  resetting.value = true
  try {
    const result = await api.resetPlayersHistory()
    selected.value = null
    stats.value = null
    await load()
    message.value = `Historique vidé : ${result.playersReset} joueur(s), ${result.tournamentsDeleted} tournoi(s) supprimé(s).`
  } catch (e) {
    error.value = e.message
  } finally {
    resetting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="stack">
    <div class="row header">
      <div>
        <h1>Joueurs</h1>
        <p class="muted">Historique et statistiques globales.</p>
      </div>
      <button
        class="danger ghost"
        type="button"
        :disabled="resetting || !players.length"
        @click="resetHistory"
      >
        {{ resetting ? 'Réinitialisation…' : 'Vider l’historique' }}
      </button>
    </div>

    <section class="panel stack">
      <form class="row" @submit.prevent="create">
        <label style="flex: 1">
          Nouveau joueur
          <input v-model="name" required placeholder="Nom" />
        </label>
        <button class="primary" type="submit">Ajouter</button>
      </form>
      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="message" class="success">{{ message }}</p>
    </section>

    <div class="grid-2">
      <section class="panel">
        <table class="table" v-if="players.length">
          <thead>
            <tr>
              <th>Nom</th>
              <th>V</th>
              <th>D</th>
              <th>Tournois</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="p in players"
              :key="p.id"
              style="cursor: pointer"
              @click="showStats(p)"
            >
              <td>{{ p.name }}</td>
              <td>{{ p.wins }}</td>
              <td>{{ p.losses }}</td>
              <td>{{ p.tournamentsPlayed }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">Aucun joueur.</p>
      </section>

      <section class="panel stack" v-if="stats">
        <h2>{{ stats.player.name }}</h2>
        <p class="muted">
          {{ stats.player.wins }} victoires · {{ stats.player.losses }} défaites ·
          {{ stats.player.tournamentsPlayed }} tournois
        </p>
        <div v-for="item in stats.history" :key="item.match.id" class="hist">
          <strong>{{ item.tournamentName }}</strong>
          <span :class="item.won ? 'success' : 'error'">{{ item.won ? 'Victoire' : 'Défaite' }}</span>
          <span class="muted">
            {{ item.match.homeTeam?.name }} vs {{ item.match.awayTeam?.name }}
          </span>
        </div>
        <p v-if="!stats.history.length" class="muted">Pas encore de matchs.</p>
      </section>
    </div>
  </div>
</template>

<style scoped>
.header {
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
}

.hist {
  display: grid;
  gap: 0.15rem;
  padding: 0.55rem 0;
  border-bottom: 1px solid var(--line);
}
</style>
