import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import TournamentsView from '../views/TournamentsView.vue'
import TournamentDetailView from '../views/TournamentDetailView.vue'
import PlayersView from '../views/PlayersView.vue'
import DisplayView from '../views/DisplayView.vue'
import ManagerView from '../views/ManagerView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: LoginView },
    { path: '/', name: 'tournaments', component: TournamentsView, meta: { auth: true } },
    { path: '/tournaments/:id', name: 'tournament', component: TournamentDetailView, meta: { auth: true } },
    { path: '/players', name: 'players', component: PlayersView, meta: { auth: true } },
    { path: '/display/:id', name: 'display', component: DisplayView },
    { path: '/manage/:id', name: 'manage', component: ManagerView, meta: { auth: true } },
  ],
})

router.beforeEach((to) => {
  if (to.meta.auth && !localStorage.getItem('adminToken')) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  return true
})

export default router
