const API_BASE = import.meta.env.VITE_API_URL || ''

function getToken() {
  return localStorage.getItem('adminToken') || ''
}

export function setToken(token) {
  if (token) localStorage.setItem('adminToken', token)
  else localStorage.removeItem('adminToken')
}

async function request(path, options = {}) {
  const headers = {
    'Content-Type': 'application/json',
    ...(options.headers || {}),
  }
  const token = getToken()
  if (token) headers['X-Admin-Token'] = token

  const res = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers,
  })

  const text = await res.text()
  let data = null
  try {
    data = text ? JSON.parse(text) : null
  } catch {
    data = { error: text }
  }

  if (!res.ok) {
    const err = new Error(data?.error || `Erreur ${res.status}`)
    err.status = res.status
    err.data = data
    throw err
  }
  return data
}

export const api = {
  login: (username, password) =>
    request('/api/login', { method: 'POST', body: JSON.stringify({ username, password }) }),
  me: () => request('/api/me'),
  listTournaments: () => request('/api/tournaments'),
  getTournament: (id) => request(`/api/tournaments/${id}`),
  createTournament: (payload) =>
    request('/api/tournaments', { method: 'POST', body: JSON.stringify(payload) }),
  updateTournament: (id, payload) =>
    request(`/api/tournaments/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
  deleteTournament: (id) => request(`/api/tournaments/${id}`, { method: 'DELETE' }),
  register: (id, payload) =>
    request(`/api/tournaments/${id}/register`, { method: 'POST', body: JSON.stringify(payload) }),
  unregister: (tournamentId, teamId) =>
    request(`/api/tournaments/${tournamentId}/teams/${teamId}`, { method: 'DELETE' }),
  registerDuoDraw: (id, names) =>
    request(`/api/tournaments/${id}/register-players`, {
      method: 'POST',
      body: JSON.stringify({ names }),
    }),
  draw: (id) => request(`/api/tournaments/${id}/draw`, { method: 'POST' }),
  generateGroups: (id) => request(`/api/tournaments/${id}/generate-groups`, { method: 'POST' }),
  createTiebreakers: (id, payload) =>
    request(`/api/tournaments/${id}/tiebreakers`, { method: 'POST', body: JSON.stringify(payload) }),
  generateBracket: (id) => request(`/api/tournaments/${id}/generate-bracket`, { method: 'POST' }),
  resyncBracket: (id) => request(`/api/tournaments/${id}/resync-bracket`, { method: 'POST' }),
  rebuildBracket: (id) => request(`/api/tournaments/${id}/rebuild-bracket`, { method: 'POST' }),
  updateDisplay: (id, payload) =>
    request(`/api/tournaments/${id}/display`, { method: 'PATCH', body: JSON.stringify(payload) }),
  setResult: (matchId, payload) =>
    request(`/api/matches/${matchId}/result`, { method: 'POST', body: JSON.stringify(payload) }),
  listPlayers: () => request('/api/players'),
  searchPlayers: (q) =>
    request(`/api/players?q=${encodeURIComponent(q || '')}`),
  createPlayer: (name) =>
    request('/api/players', { method: 'POST', body: JSON.stringify({ name }) }),
  playerStats: (id) => request(`/api/players/${id}/stats`),
  resetPlayersHistory: () =>
    request('/api/players/reset-history', { method: 'POST' }),
  publicDisplay: (id) => request(`/api/public/tournaments/${id}/display`),
  publicTournaments: () => request('/api/public/tournaments'),
}
