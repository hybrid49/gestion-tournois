<script setup>
import { computed } from 'vue'
import { useRoute, useRouter, RouterLink, RouterView } from 'vue-router'
import { setToken } from './api/client'
import BrandLogo from './components/BrandLogo.vue'

const route = useRoute()
const router = useRouter()
const isDisplay = computed(() => route.name === 'display')
const isManage = computed(() => route.name === 'manage')
const isLogin = computed(() => route.name === 'login')
const loggedIn = computed(() => !!localStorage.getItem('adminToken'))
const bareLayout = computed(() => isDisplay.value || isManage.value || isLogin.value)

function logout() {
  setToken(null)
  router.push({ name: 'login' })
}
</script>

<template>
  <div v-if="bareLayout">
    <RouterView />
  </div>
  <div v-else class="layout">
    <header class="topbar">
      <RouterLink to="/" class="brand-link">
        <BrandLogo variant="compact" />
      </RouterLink>
      <nav class="nav">
        <RouterLink to="/">Tournois</RouterLink>
        <RouterLink to="/players">Joueurs</RouterLink>
        <button v-if="loggedIn" class="ghost" type="button" @click="logout">Déconnexion</button>
      </nav>
    </header>
    <main class="page">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.brand-link {
  color: inherit;
  text-decoration: none;
}
</style>
