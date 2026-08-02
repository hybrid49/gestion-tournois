<script setup>
import { ref } from 'vue'
import { formatName } from '../utils/formatName'

const props = defineProps({
  matches: { type: Array, required: true },
  scoreDraft: { type: Object, required: true },
  currentId: { type: Number, default: null },
})

const emit = defineEmits(['winner', 'current'])

/** matchId en cours de correction */
const editingId = ref(null)

function startEdit(match) {
  editingId.value = match.id
  props.scoreDraft[match.id] = {
    home: match.scoreHome ?? '',
    away: match.scoreAway ?? '',
  }
}

function cancelEdit() {
  editingId.value = null
}

function isEditing(match) {
  return editingId.value === match.id
}

function showEntry(match) {
  return match.status !== 'done' || isEditing(match)
}

function submitWinner(match, teamId) {
  emit('winner', match, teamId)
  editingId.value = null
}
</script>

<template>
  <div class="stack">
    <div
      v-for="match in matches"
      :key="match.id"
      class="match-row"
      :class="{ editable: match.editable && match.status === 'done' }"
    >
      <div class="match-meta">
        <span class="badge">{{ match.phase }}</span>
        <span class="muted">R{{ match.round }} · #{{ match.slot }}</span>
        <span v-if="match.id === currentId" class="live">LIVE</span>
        <span v-if="match.editable && match.status === 'done' && !isEditing(match)" class="hint">
          corrigible
        </span>
      </div>
      <div class="match-teams">
        <strong>{{ formatName(match.homeTeam?.name) || 'TBD' }}</strong>
        <span class="muted"> vs </span>
        <strong>{{ formatName(match.awayTeam?.name) || 'TBD' }}</strong>
      </div>

      <div v-if="match.status === 'done' && !isEditing(match)" class="done-row">
        <p class="success">
          Vainqueur : {{ formatName(match.winner?.name) }}
          <template v-if="match.scoreHome != null">
            ({{ match.scoreHome }} - {{ match.scoreAway }})
          </template>
        </p>
        <button
          v-if="match.editable"
          type="button"
          class="ghost"
          @click="startEdit(match)"
        >
          Corriger
        </button>
      </div>

      <div v-if="showEntry(match)" class="row entry">
        <input
          type="number"
          placeholder="Score A"
          style="width: 90px"
          :value="scoreDraft[match.id]?.home ?? ''"
          @input="
            scoreDraft[match.id] = {
              ...(scoreDraft[match.id] || {}),
              home: $event.target.value,
            }
          "
        />
        <input
          type="number"
          placeholder="Score B"
          style="width: 90px"
          :value="scoreDraft[match.id]?.away ?? ''"
          @input="
            scoreDraft[match.id] = {
              ...(scoreDraft[match.id] || {}),
              away: $event.target.value,
            }
          "
        />
        <template v-if="match.homeTeam && match.awayTeam">
          <button
            type="button"
            class="primary"
            @click="submitWinner(match, match.homeTeam.id)"
          >
            {{ formatName(match.homeTeam.name) }} gagne
          </button>
          <button
            type="button"
            class="primary"
            @click="submitWinner(match, match.awayTeam.id)"
          >
            {{ formatName(match.awayTeam.name) }} gagne
          </button>
          <button
            v-if="match.status !== 'done'"
            type="button"
            @click="emit('current', match.id)"
          >
            Projeter
          </button>
          <button v-if="isEditing(match)" type="button" class="ghost" @click="cancelEdit">
            Annuler
          </button>
        </template>
        <span v-else class="muted">En attente des équipes</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.match-row {
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 0.8rem;
  display: grid;
  gap: 0.5rem;
  background: rgba(255, 255, 255, 0.015);
}

.match-row.editable {
  border-color: rgba(245, 213, 143, 0.35);
}

.match-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}

.live {
  color: var(--accent);
  font-weight: 700;
  font-size: 0.8rem;
}

.hint {
  color: var(--muted);
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.done-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.done-row .success {
  margin: 0;
}

.entry {
  padding-top: 0.15rem;
}
</style>
