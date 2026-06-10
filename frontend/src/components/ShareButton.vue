<script setup>
import { ref } from 'vue'

const props = defineProps({
  url: { type: String, default: '' },
  variant: { type: String, default: 'pill' } // 'pill' | 'icon'
})

const copied = ref(false)

const share = async () => {
  const raw = props.url || window.location.href
  const urlToCopy = raw.startsWith('http') ? raw : window.location.origin + raw
  try {
    await navigator.clipboard.writeText(urlToCopy)
  } catch {
    try {
      const el = document.createElement('input')
      el.value = urlToCopy
      document.body.appendChild(el)
      el.select()
      document.execCommand('copy')
      document.body.removeChild(el)
    } catch { /* silent */ }
  }
  copied.value = true
  setTimeout(() => { copied.value = false }, 2000)
}
</script>

<template>
  <!-- Icon-only variant: para el panel de acciones de FilmDetailView -->
  <button
    v-if="variant === 'icon'"
    @click.stop="share"
    class="group/share aspect-square flex items-center justify-center rounded transition-all relative text-[#9ab]"
    :class="copied ? 'text-emerald-400 bg-emerald-400/10' : 'hover:text-white'"
  >
    <svg v-if="!copied" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/>
      <polyline points="16 6 12 2 8 6"/>
      <line x1="12" y1="2" x2="12" y2="15"/>
    </svg>
    <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <polyline points="20 6 9 17 4 12"/>
    </svg>
    <span class="pointer-events-none absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 whitespace-nowrap rounded-[6px] border border-white/10 bg-[#0d1117] px-2 py-1 text-[9px] font-black uppercase tracking-[0.15em] text-[#e2e8f0] opacity-0 transition-opacity duration-150 group-hover/share:opacity-100 z-50">
      {{ copied ? '¡Copiado!' : 'Compartir' }}
      <span class="absolute -bottom-[5px] left-1/2 -translate-x-1/2 h-2 w-2 rotate-45 border-b border-r border-white/10 bg-[#0d1117]"></span>
    </span>
  </button>

  <!-- Pill variant: para páginas de entry y post -->
  <button
    v-else
    @click.stop="share"
    :class="[
      'flex items-center gap-2 px-5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all duration-300 border',
      copied
        ? 'bg-emerald-500/10 border-emerald-500/50 text-emerald-500'
        : 'bg-[#14181c] border-white/10 text-slate-400 hover:border-white/30 hover:text-white'
    ]"
  >
    <svg v-if="!copied" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/>
      <polyline points="16 6 12 2 8 6"/>
      <line x1="12" y1="2" x2="12" y2="15"/>
    </svg>
    <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <polyline points="20 6 9 17 4 12"/>
    </svg>
    {{ copied ? '¡Copiado!' : 'Compartir' }}
  </button>
</template>
