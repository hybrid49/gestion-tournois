/**
 * Couleur stable pour un joueur / une équipe.
 * Même id → même teinte pendant tout le tournoi (et entre tournois).
 */

function hashString(input) {
  let h = 2166136261
  const s = String(input)
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }
  return h >>> 0
}

/** Palette HSL lisible sur fond sombre */
export function colorFromSeed(seed) {
  const n = typeof seed === 'number' && Number.isFinite(seed) ? seed : hashString(seed)
  // Angle d'or → teintes bien espacées
  const hue = (n * 137.508) % 360
  const sat = 62 + (n % 18) // 62–79
  const light = 58 + (n % 10) // 58–67

  return {
    hue,
    solid: `hsl(${hue} ${sat}% ${light}%)`,
    soft: `hsl(${hue} ${sat}% ${Math.max(22, light - 36)}% / 0.55)`,
    bg: `hsl(${hue} 42% 16%)`,
    fg: `hsl(${hue} ${Math.min(85, sat + 12)}% ${Math.min(78, light + 14)}%)`,
    border: `hsl(${hue} ${sat}% ${Math.max(38, light - 12)}%)`,
  }
}

/** Couleur d'un joueur (id prioritaire) */
export function playerColor(player) {
  if (!player) return null
  if (player.id != null) return colorFromSeed(player.id)
  if (player.name) return colorFromSeed(`name:${player.name.toLowerCase()}`)
  return null
}

/**
 * Couleur affichée pour une équipe dans le bracket :
 * - solo → couleur du joueur
 * - duo → teinte dérivée des deux ids (stable pour le duo)
 */
export function teamColor(team) {
  if (!team) return null
  const p1 = team.player1?.id
  const p2 = team.player2?.id
  if (p1 != null && p2 != null) {
    const [a, b] = [p1, p2].sort((x, y) => x - y)
    return colorFromSeed(`duo:${a}:${b}`)
  }
  if (p1 != null) return colorFromSeed(p1)
  if (team.id != null) return colorFromSeed(`team:${team.id}`)
  if (team.name) return colorFromSeed(`name:${team.name.toLowerCase()}`)
  return null
}

export function teamStyle(team) {
  const c = teamColor(team)
  if (!c) {
    return {
      '--team-bg': 'transparent',
      '--team-fg': 'inherit',
      '--team-border': '#445061',
      '--team-solid': 'var(--muted)',
    }
  }
  return {
    '--team-bg': c.bg,
    '--team-fg': c.fg,
    '--team-border': c.border,
    '--team-solid': c.solid,
  }
}
