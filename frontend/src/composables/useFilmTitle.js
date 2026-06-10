/**
 * Returns the preferred display title for a film object:
 * Spanish title (alternative_titles.es) when available, original title as fallback.
 */
export function displayTitle(film) {
  return film?.alternative_titles?.es || film?.title || ''
}
