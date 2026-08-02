<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, setToken } from '../api/client'
import BrandLogo from '../components/BrandLogo.vue'

const username = ref('admin')
const password = ref('admin')
const error = ref('')
const loading = ref(false)
const router = useRouter()
const route = useRoute()

async function submit() {
  error.value = ''
  loading.value = true
  try {
    const data = await api.login(username.value, password.value)
    setToken(data.token)
    router.replace(route.query.redirect || '/')
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login">
    <div class="login-panel">
      <BrandLogo variant="hero" />
      <p class="lede">Gestion des tournois · espace admin</p>
      <form class="stack" @submit.prevent="submit">
        <label>
          Identifiant
          <input v-model="username" autocomplete="username" required />
        </label>
        <label>
          Mot de passe
          <input v-model="password" type="password" autocomplete="current-password" required />
        </label>
        <p v-if="error" class="error">{{ error }}</p>
        <button class="primary" type="submit" :disabled="loading">
          {{ loading ? 'Connexion…' : 'Entrer' }}
        </button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.login {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 1.5rem;
  background:
    radial-gradient(900px 480px at 50% -5%, rgba(245, 213, 143, 0.14), transparent 55%),
    radial-gradient(700px 400px at 80% 100%, rgba(90, 70, 40, 0.16), transparent 50%),
    var(--bg);
}

.login-panel {
  width: min(420px, 100%);
  padding: 2.1rem 2rem 2rem;
  border: 1px solid var(--line);
  border-radius: 16px;
  background: linear-gradient(180deg, rgba(245, 213, 143, 0.04), transparent 40%), var(--bg-elevated);
  animation: rise 0.45s ease;
  display: grid;
  gap: 1.25rem;
  justify-items: center;
}

.lede {
  margin: 0;
  color: var(--muted);
  font-size: 0.92rem;
  text-align: center;
}

.login-panel form {
  width: 100%;
}

@keyframes rise {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: none;
  }
}
</style>
