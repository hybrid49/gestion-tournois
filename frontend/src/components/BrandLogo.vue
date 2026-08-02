<script setup>
import { brand } from '../brand'

defineProps({
  /** compact = header admin · hero = login · display = projection */
  variant: { type: String, default: 'compact' },
  showText: { type: Boolean, default: true },
})
</script>

<template>
  <div class="brand-logo" :class="variant">
    <img :src="brand.logo" :alt="brand.logoAlt" class="mark" draggable="false" />
    <div v-if="showText" class="copy">
      <span class="name">{{ brand.name }}</span>
      <span class="meta">{{ brand.tagline }} · {{ brand.product }}</span>
    </div>
  </div>
</template>

<style scoped>
.brand-logo {
  display: inline-flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.mark {
  display: block;
  object-fit: contain;
  user-select: none;
}

.copy {
  display: grid;
  gap: 0.1rem;
  min-width: 0;
}

.name {
  font-family: var(--display);
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--cream);
  line-height: 1.1;
}

.meta {
  color: var(--muted);
  font-size: 0.72rem;
  letter-spacing: 0.04em;
}

.compact .mark {
  width: 42px;
  height: 42px;
}

.compact .name {
  font-size: 1.05rem;
}

.hero {
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  gap: 0.85rem;
  width: 100%;
}

.hero .mark {
  display: block;
  width: min(168px, 48vw);
  height: auto;
  margin: 0 auto;
  filter: drop-shadow(0 10px 28px rgba(245, 213, 143, 0.18));
}

.hero .copy {
  justify-items: center;
  width: 100%;
}

.hero .name {
  font-size: 1.55rem;
}

.hero .meta {
  font-size: 0.8rem;
}

.display .mark {
  width: 52px;
  height: 52px;
}

.display .name {
  font-size: 0.95rem;
}

.display .meta {
  font-size: 0.65rem;
}

@media (max-width: 700px) {
  .compact .meta,
  .display .meta {
    display: none;
  }
}
</style>
