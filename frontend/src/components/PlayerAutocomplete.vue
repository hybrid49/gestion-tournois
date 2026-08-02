<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { api } from '../api/client'
import { formatName } from '../utils/formatName'

const props = defineProps({
  modelValue: { type: String, default: '' },
  playerId: { type: Number, default: null },
  placeholder: { type: String, default: 'Nom du joueur' },
  required: { type: Boolean, default: false },
  label: { type: String, default: 'Joueur' },
  /** ids déjà inscrits à masquer des suggestions */
  excludeIds: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue', 'update:playerId', 'pick'])

const query = ref(props.modelValue || '')
const suggestions = ref([])
const open = ref(false)
const loading = ref(false)
const suppressWatch = ref(false)
let timer = null

const filteredSuggestions = computed(() =>
  suggestions.value.filter((p) => !props.excludeIds.includes(p.id)),
)

const hint = computed(() => {
  if (props.playerId) return `Joueur connu · stats conservées`
  if (query.value.trim().length >= 1 && filteredSuggestions.value.length) {
    return 'Cliquez une suggestion pour l’ajouter directement'
  }
  return 'Les joueurs déjà inscrits apparaîtront en suggestion'
})

watch(
  () => props.modelValue,
  (v) => {
    if (v !== query.value) query.value = v || ''
  },
)

watch(query, (v) => {
  if (suppressWatch.value) return
  emit('update:modelValue', v)
  if (props.playerId) {
    emit('update:playerId', null)
  }
  scheduleSearch(v)
})

function scheduleSearch(v) {
  if (timer) clearTimeout(timer)
  const term = (v || '').trim()
  if (term.length < 1) {
    suggestions.value = []
    open.value = false
    return
  }
  timer = setTimeout(() => search(term), 180)
}

async function search(term) {
  loading.value = true
  try {
    suggestions.value = await api.searchPlayers(term)
    open.value = filteredSuggestions.value.length > 0
  } catch {
    suggestions.value = []
    open.value = false
  } finally {
    loading.value = false
  }
}

async function selectPlayer(player) {
  open.value = false
  suppressWatch.value = true
  const name = formatName(player.name)
  query.value = name
  emit('update:modelValue', name)
  emit('update:playerId', player.id)
  await nextTick()
  emit('pick', { id: player.id, name })
  suppressWatch.value = false
}

function onBlur() {
  setTimeout(() => {
    open.value = false
  }, 180)
}

function onFocus() {
  if (query.value.trim().length >= 1) {
    scheduleSearch(query.value)
  }
}

onMounted(async () => {
  try {
    suggestions.value = await api.listPlayers()
  } catch {
    suggestions.value = []
  }
})

onUnmounted(() => {
  if (timer) clearTimeout(timer)
})

defineExpose({ clear: () => {
  suppressWatch.value = true
  query.value = ''
  emit('update:modelValue', '')
  emit('update:playerId', null)
  suppressWatch.value = false
} })
</script>

<template>
  <label class="ac">
    {{ label }}
    <div class="ac-wrap">
      <input
        v-model="query"
        :placeholder="placeholder"
        :required="required"
        autocomplete="off"
        @focus="onFocus"
        @blur="onBlur"
        @keydown.escape="open = false"
      />
      <ul v-if="open && filteredSuggestions.length" class="ac-list">
        <li
          v-for="p in filteredSuggestions"
          :key="p.id"
          @mousedown.prevent="selectPlayer(p)"
        >
          <strong>{{ formatName(p.name) }}</strong>
          <span class="muted">{{ p.wins }}V · {{ p.losses }}D · {{ p.tournamentsPlayed }} tournois</span>
        </li>
      </ul>
    </div>
    <span class="ac-hint" :class="{ known: !!playerId }">{{ hint }}</span>
  </label>
</template>

<style scoped>
.ac {
  display: grid;
  gap: 0.35rem;
}

.ac-wrap {
  position: relative;
}

.ac-list {
  position: absolute;
  z-index: 20;
  left: 0;
  right: 0;
  top: calc(100% + 4px);
  margin: 0;
  padding: 0.35rem 0;
  list-style: none;
  background: #14181e;
  border: 1px solid var(--line);
  border-radius: 8px;
  max-height: 220px;
  overflow: auto;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
}

.ac-list li {
  display: grid;
  gap: 0.1rem;
  padding: 0.5rem 0.75rem;
  cursor: pointer;
}

.ac-list li:hover {
  background: rgba(232, 168, 56, 0.12);
}

.ac-list .muted {
  font-size: 0.75rem;
}

.ac-hint {
  font-size: 0.75rem;
  color: var(--muted);
}

.ac-hint.known {
  color: var(--ok);
}
</style>
