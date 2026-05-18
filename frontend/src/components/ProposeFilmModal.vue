<script setup>
import { ref, watch } from 'vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
})
const emit = defineEmits(['update:modelValue'])

const auth = useAuthStore()

const step       = ref(1)
const tmdbIdInput = ref('')
const preview    = ref(null)
const isLoading  = ref(false)
const errorMsg   = ref(null)
const isSubmitting = ref(false)

const TMDB_SEARCH_URL = 'https://www.themoviedb.org/search'

watch(() => props.modelValue, (open) => {
  if (open) reset()
})

function reset() {
  step.value        = 1
  tmdbIdInput.value = ''
  preview.value     = null
  errorMsg.value    = null
  isLoading.value   = false
  isSubmitting.value = false
}

const close = () => emit('update:modelValue', false)

async function fetchPreview() {
  const id = parseInt(tmdbIdInput.value, 10)
  if (!id || id < 1) {
    errorMsg.value = 'Introduce un ID válido (número entero positivo).'
    return
  }

  isLoading.value = true
  errorMsg.value  = null
  preview.value   = null

  try {
    const { data } = await api.post('/film-proposals/preview', { tmdb_id: id })
    preview.value = data.data
    step.value = 3
  } catch (e) {
    const code = e.response?.data?.code
    if (code === 'already_exists') {
      errorMsg.value = 'Esta película ya está en nuestra base de datos.'
    } else if (code === 'already_proposed') {
      errorMsg.value = 'Ya existe una propuesta pendiente para esta película. ¡Gracias por tu interés!'
    } else if (e.response?.status === 404) {
      errorMsg.value = 'No se encontró ninguna película con ese ID en TMDB.'
    } else if (e.response?.status === 429) {
      errorMsg.value = 'Has realizado demasiadas consultas. Espera un momento e inténtalo de nuevo.'
    } else {
      errorMsg.value = e.response?.data?.message ?? 'Error al consultar TMDB.'
    }
  } finally {
    isLoading.value = false
  }
}

async function submitProposal() {
  isSubmitting.value = true
  errorMsg.value     = null

  try {
    await api.post('/film-proposals', { tmdb_id: preview.value.tmdb_id })
    step.value = 4
  } catch (e) {
    const code = e.response?.data?.code
    if (code === 'already_exists') {
      errorMsg.value = 'Esta película ya está en nuestra base de datos.'
    } else if (code === 'already_proposed') {
      errorMsg.value = 'Ya existe una propuesta pendiente para esta película.'
    } else if (e.response?.status === 429) {
      errorMsg.value = 'Has alcanzado el límite de propuestas por hoy (máx. 3). Inténtalo mañana.'
    } else {
      errorMsg.value = e.response?.data?.message ?? 'Error al enviar la propuesta.'
    }
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="modelValue"
      class="fixed inset-0 z-[110] flex items-center justify-center px-4"
      role="dialog"
      aria-modal="true"
      aria-label="Proponer película"
    >
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-md" @click="close" />

      <!-- Modal -->
      <div class="relative w-full max-w-md rounded-2xl border border-slate-800 bg-[#1b2228] shadow-[0_30px_80px_rgba(0,0,0,0.6)] animate-fade-in overflow-hidden">

        <!-- Header -->
        <div class="flex items-center justify-between px-6 pt-5 pb-4 border-b border-slate-800">
          <div class="flex items-center gap-3">
            <span class="text-brand text-lg">🎬</span>
            <h2 class="text-sm font-bold text-white tracking-wide">Proponer película</h2>
          </div>
          <button @click="close" class="text-slate-500 hover:text-white transition-colors text-xl leading-none">&times;</button>
        </div>

        <!-- Step indicator -->
        <div v-if="step < 4" class="flex gap-1 px-6 pt-4">
          <div
            v-for="n in 3"
            :key="n"
            class="h-1 flex-1 rounded-full transition-colors duration-300"
            :class="n <= step ? 'bg-brand' : 'bg-slate-700'"
          />
        </div>

        <!-- ──── Step 1: Intro ──── -->
        <div v-if="step === 1" class="px-6 py-5 space-y-4">
          <p class="text-slate-300 text-sm leading-relaxed">
            ¿No encuentras la película que buscas? Puedes proponérnosla para que la añadamos a la base de datos.
          </p>
          <p class="text-slate-400 text-sm leading-relaxed">
            Usamos <strong class="text-white">The Movie Database (TMDB)</strong> como fuente de datos.
            Para proponer una película necesitas su <strong class="text-white">ID de TMDB</strong>.
          </p>

          <div class="rounded-xl border border-slate-700 bg-slate-900/50 p-4 space-y-2">
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-widest">Cómo obtener el ID</p>
            <ol class="text-slate-300 text-sm space-y-1 list-decimal list-inside">
              <li>Ve a TMDB y busca tu película.</li>
              <li>Abre la página de la película.</li>
              <li>Copia el número que aparece en la URL: <code class="text-brand text-xs bg-slate-800 px-1 rounded">/movie/<strong>12345</strong></code></li>
            </ol>
          </div>

          <a
            :href="TMDB_SEARCH_URL"
            target="_blank"
            rel="noopener noreferrer"
            class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl border border-slate-700 text-sm text-slate-300 hover:border-brand hover:text-brand transition-colors"
          >
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/><path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/></svg>
            Buscar en TMDB
          </a>

          <button
            @click="step = 2"
            class="w-full py-2.5 rounded-xl bg-brand text-white text-sm font-semibold hover:bg-brand/90 transition-colors"
          >
            Ya tengo el ID — continuar
          </button>
        </div>

        <!-- ──── Step 2: Input ID ──── -->
        <div v-else-if="step === 2" class="px-6 py-5 space-y-4">
          <p class="text-slate-300 text-sm">Introduce el ID de TMDB de la película que quieres proponer:</p>

          <div class="space-y-2">
            <label class="text-xs font-semibold text-slate-500 uppercase tracking-widest">ID de TMDB</label>
            <input
              v-model="tmdbIdInput"
              type="number"
              min="1"
              placeholder="Ej: 157336"
              @keyup.enter="fetchPreview"
              class="w-full bg-slate-900/60 border border-slate-700 rounded-xl px-4 py-3 text-sm text-white placeholder:text-slate-600 focus:outline-none focus:ring-2 focus:ring-brand/50 transition-all [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
            />
          </div>

          <div v-if="errorMsg" class="rounded-xl border border-red-900/50 bg-red-950/30 px-4 py-3 text-sm text-red-400">
            {{ errorMsg }}
          </div>

          <div class="flex gap-3">
            <button
              @click="step = 1"
              class="flex-1 py-2.5 rounded-xl border border-slate-700 text-sm text-slate-400 hover:text-white hover:border-slate-600 transition-colors"
            >
              Atrás
            </button>
            <button
              @click="fetchPreview"
              :disabled="isLoading || !tmdbIdInput"
              class="flex-1 py-2.5 rounded-xl bg-brand text-white text-sm font-semibold hover:bg-brand/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            >
              <div v-if="isLoading" class="w-4 h-4 border-2 border-white/20 border-t-white rounded-full animate-spin" />
              <span>{{ isLoading ? 'Buscando...' : 'Previsualizar' }}</span>
            </button>
          </div>
        </div>

        <!-- ──── Step 3: Preview + Confirm ──── -->
        <div v-else-if="step === 3 && preview" class="px-6 py-5 space-y-4">
          <p class="text-slate-400 text-xs">Confirma que es la película correcta antes de enviar tu propuesta:</p>

          <!-- Film preview card -->
          <div class="flex gap-4 rounded-xl border border-slate-700 bg-slate-900/50 p-4">
            <img
              v-if="preview.poster"
              :src="preview.poster"
              :alt="preview.title"
              class="w-20 h-28 object-cover rounded-lg shadow-lg flex-shrink-0"
            />
            <div v-else class="w-20 h-28 rounded-lg bg-slate-800 flex-shrink-0 flex items-center justify-center text-slate-600 text-2xl">🎬</div>
            <div class="flex-1 min-w-0 space-y-1">
              <h3 class="text-white font-bold text-sm leading-snug">{{ preview.title }}</h3>
              <p v-if="preview.original_title && preview.original_title !== preview.title" class="text-slate-500 text-xs truncate">{{ preview.original_title }}</p>
              <div class="flex flex-wrap gap-x-3 gap-y-1 mt-2">
                <span v-if="preview.year" class="text-xs text-slate-400">{{ preview.year }}</span>
                <span v-if="preview.duration" class="text-xs text-slate-400">{{ preview.duration }} min</span>
                <span v-if="preview.director" class="text-xs text-slate-400">Dir. {{ preview.director }}</span>
              </div>
              <p v-if="preview.genre" class="text-xs text-brand/80 font-medium mt-1">{{ preview.genre }}</p>
            </div>
          </div>

          <p v-if="preview.overview" class="text-slate-400 text-xs leading-relaxed line-clamp-3">{{ preview.overview }}</p>

          <div v-if="errorMsg" class="rounded-xl border border-red-900/50 bg-red-950/30 px-4 py-3 text-sm text-red-400">
            {{ errorMsg }}
          </div>

          <div class="flex gap-3">
            <button
              @click="step = 2; errorMsg = null"
              class="flex-1 py-2.5 rounded-xl border border-slate-700 text-sm text-slate-400 hover:text-white hover:border-slate-600 transition-colors"
            >
              Cambiar ID
            </button>
            <button
              @click="submitProposal"
              :disabled="isSubmitting"
              class="flex-1 py-2.5 rounded-xl bg-brand text-white text-sm font-semibold hover:bg-brand/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            >
              <div v-if="isSubmitting" class="w-4 h-4 border-2 border-white/20 border-t-white rounded-full animate-spin" />
              <span>{{ isSubmitting ? 'Enviando...' : 'Enviar propuesta' }}</span>
            </button>
          </div>
        </div>

        <!-- ──── Step 4: Success ──── -->
        <div v-else-if="step === 4" class="px-6 py-8 text-center space-y-4">
          <div class="text-5xl">🎉</div>
          <h3 class="text-white font-bold text-base">¡Propuesta enviada!</h3>
          <p class="text-slate-400 text-sm leading-relaxed">
            Gracias por tu colaboración. En cuanto nuestro equipo la revise, podrás ver el estado en tu perfil,
            en la sección <span class="text-white font-medium">«Películas propuestas a filmoclub»</span>,
            justo bajo tu Watchlist.
          </p>
          <p class="text-slate-600 text-xs">Gracias por ayudar a construir la comunidad 🙌</p>
          <button
            @click="close"
            class="w-full mt-2 py-2.5 rounded-xl bg-brand text-white text-sm font-semibold hover:bg-brand/90 transition-colors"
          >
            Cerrar
          </button>
        </div>

      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.animate-fade-in {
  animation: fadeIn 0.2s ease-out;
}
@keyframes fadeIn {
  from { opacity: 0; transform: scale(0.97) translateY(8px); }
  to   { opacity: 1; transform: scale(1) translateY(0); }
}
</style>
