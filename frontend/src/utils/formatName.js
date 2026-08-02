/**
 * Affiche un prénom / nom avec majuscule initiale (et après espace / tiret).
 * Ex. "yann" → "Yann", "jean-pierre" → "Jean-Pierre"
 */
export function formatName(name) {
  if (name == null || name === '') return ''
  return String(name)
    .trim()
    .split(/(\s+|-)/)
    .map((part) => {
      if (!part || /^\s+$/.test(part) || part === '-') return part
      return part.charAt(0).toLocaleUpperCase('fr-FR') + part.slice(1).toLocaleLowerCase('fr-FR')
    })
    .join('')
}
