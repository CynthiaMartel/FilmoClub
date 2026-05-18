<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

const router = useRouter()
const auth   = useAuthStore()

const proposals   = ref([])
const isLoading   = ref(true)
const currentPage = ref(1)
const lastPage    = ref(1)
const total       = ref(0)
const statusFilter = ref('pending')

const toast = ref(null)
function showToast(msg, type = 'ok') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 3000)
}

const rejectTarget  = ref(null)
const rejectNote    = ref('')
const isProcessing  = ref(null) // proposal id being processed

async function fetchProposals(page = 1) {
  isLoading.value = true
  try {
    const { data } = await api.get('/admin/film-proposals', {
      params: { status: statusFilter.value, page },
    })
    proposals.value = data.data
    currentPage.value = data.current_page
    lastPage.value    = data.last_page
    total.value       = data.total
  } catch {
    showToast('Error al cargar propuestas', 'err')
  } finally {
    isLoading.value = false
  }
}

async function approve(proposal) {
  if (isProcessing.value) return
  isProcessing.value = proposal.id
  try {
    await api.patch(`/admin/film-proposals/${proposal.id}/approve`)
    proposals.value = proposals.value.filter(p => p.id !== proposal.id)
    total.value = Math.max(0, total.value - 1)
    showToast(`"${proposal.tmdb_snapshot?.title}" importada correctamente`)
  } catch (e) {
    showToast(e.response?.data?.message ?? 'Error al aprobar', 'err')
  } finally {
    isProcessing.value = null
  }
}

function askReject(proposal) {
  rejectTarget.value = proposal
  rejectNote.value   = ''
}

async function confirmReject() {
  if (!rejectTarget.value || isProcessing.value) return
  isProcessing.value = rejectTarget.value.id
  try {
    await api.patch(`/admin/film-proposals/${rejectTarget.value.id}/reject`, {
      admin_notes: rejectNote.value,
    })
    proposals.value = proposals.value.filter(p => p.id !== rejectTarget.value.id)
    total.value = Math.max(0, total.value - 1)
    showToast('Propuesta rechazada')
    rejectTarget.value = null
  } catch (e) {
    showToast(e.response?.data?.message ?? 'Error al rechazar', 'err')
  } finally {
    isProcessing.value = null
  }
}

function changeFilter(f) {
  statusFilter.value = f
  fetchProposals(1)
}

onMounted(() => {
  if (!auth.user || auth.user.idRol != 1) {
    router.replace({ name: 'home' })
    return
  }
  fetchProposals()
})
</script>

<template>
  <div class="min-h-screen w-full bg-[#14181c] text-slate-100 font-sans overflow-x-hidden pb-20">

    <!-- Toast -->
    <Transition name="fade">
      <div
        v-if="toast"
        :class="[
          'fixed top-5 right-5 z-50 px-5 py-3 rounded-lg text-sm font-medium shadow-xl',
          toast.type === 'err' ? 'bg-red-600 text-white' : 'bg-emerald-600 text-white'
        ]"
      >{{ toast.msg }}</div>
    </Transition>

    <!-- Reject modal -->
    <Transition name="fade">
      <div v-if="rejectTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm px-4">
        <div class="bg-[#1c2128] border border-slate-700 rounded-xl p-6 max-w-sm w-full space-y-4">
          <h3 class="text-base font-semibold text-white">Rechazar propuesta</h3>
          <p class="text-sm text-slate-400">
            Película: <span class="text-white font-medium">{{ rejectTarget.tmdb_snapshot?.title }}</span>
          </p>
          <div class="space-y-1">
            <label class="text-xs text-slate-500 uppercase tracking-widest font-semibold">Motivo (opcional, visible para el usuario)</label>
            <textarea
              v-model="rejectNote"
              rows="3"
              placeholder="Ej: duplicado, contenido inapropiado, fuera de catálogo..."
              class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder:text-slate-600 focus:outline-none focus:ring-2 focus:ring-brand/50 resize-none"
            />
          </div>
          <div class="flex gap-3 justify-end">
            <button @click="rejectTarget = null" class="px-4 py-2 rounded-lg text-sm text-slate-300 hover:text-white transition">Cancelar</button>
            <button
              @click="confirmReject"
              :disabled="!!isProcessing"
              class="px-4 py-2 rounded-lg text-sm font-semibold bg-red-600 hover:bg-red-500 text-white transition disabled:opacity-50"
            >Rechazar</button>
          </div>
        </div>
      </div>
    </Transition>

    <div class="content-wrap mx-auto max-w-[1100px] px-6 md:px-10 lg:px-0 py-10">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div>
          <span class="text-[10px] font-bold uppercase tracking-[0.25em] text-slate-500 block mb-1">Panel de administración</span>
          <h1 class="text-xl font-bold text-white">Propuestas de películas</h1>
          <p class="text-xs text-slate-500 mt-1">{{ total }} propuesta{{ total !== 1 ? 's' : '' }} en esta vista</p>
        </div>
        <button
          @click="router.push({ name: 'admin' })"
          class="flex items-center gap-2 px-4 py-2.5 rounded-lg bg-neutral-800 text-slate-300 text-sm font-medium hover:bg-neutral-700 transition-colors self-start"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          Volver al panel
        </button>
      </div>

      <!-- Filters -->
      <div class="flex gap-2 mb-6">
        <button
          v-for="f in ['pending', 'approved', 'rejected', 'all']"
          :key="f"
          @click="changeFilter(f)"
          :class="[
            'px-4 py-2 rounded-lg text-xs font-semibold uppercase tracking-wide transition-colors',
            statusFilter === f
              ? 'bg-brand text-white'
              : 'bg-neutral-800 text-slate-400 hover:text-white'
          ]"
        >
          {{ f === 'pending' ? 'Pendientes' : f === 'approved' ? 'Aprobadas' : f === 'rejected' ? 'Rechazadas' : 'Todas' }}
        </button>
      </div>

      <!-- Loading -->
      <div v-if="isLoading" class="flex justify-center py-20">
        <div class="w-8 h-8 border-2 border-brand/20 border-t-brand rounded-full animate-spin" />
      </div>

      <!-- Empty -->
      <div v-else-if="!proposals.length" class="text-center py-20 text-slate-500 text-sm">
        No hay propuestas en esta categoría.
      </div>

      <!-- Proposal list -->
      <div v-else class="space-y-4">
        <div
          v-for="proposal in proposals"
          :key="proposal.id"
          class="flex gap-4 rounded-xl border border-slate-800 bg-[#1b2228] p-4 hover:border-slate-700 transition-colors"
        >
          <!-- Poster -->
          <img
            v-if="proposal.tmdb_snapshot?.poster"
            :src="proposal.tmdb_snapshot.poster"
            :alt="proposal.tmdb_snapshot?.title"
            class="w-16 h-24 object-cover rounded-lg shadow-lg flex-shrink-0"
          />
          <div v-else class="w-16 h-24 rounded-lg bg-slate-800 flex-shrink-0 flex items-center justify-center text-slate-600 text-xl">🎬</div>

          <!-- Info -->
          <div class="flex-1 min-w-0 space-y-1">
            <div class="flex items-start justify-between gap-2">
              <h3 class="text-white font-bold text-sm leading-snug truncate">{{ proposal.tmdb_snapshot?.title }}</h3>
              <!-- Status badge -->
              <span :class="[
                'text-[10px] font-bold uppercase tracking-widest px-2 py-0.5 rounded-full flex-shrink-0',
                proposal.status === 'pending'  ? 'bg-amber-500/20 text-amber-400' :
                proposal.status === 'approved' ? 'bg-emerald-500/20 text-emerald-400' :
                'bg-red-500/20 text-red-400'
              ]">
                {{ proposal.status === 'pending' ? 'Pendiente' : proposal.status === 'approved' ? 'Aprobada' : 'Rechazada' }}
              </span>
            </div>

            <div class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-slate-400">
              <span v-if="proposal.tmdb_snapshot?.year">{{ proposal.tmdb_snapshot.year }}</span>
              <span v-if="proposal.tmdb_snapshot?.director">Dir. {{ proposal.tmdb_snapshot.director }}</span>
              <span v-if="proposal.tmdb_snapshot?.genre" class="text-brand/70">{{ proposal.tmdb_snapshot.genre }}</span>
            </div>

            <div class="text-xs text-slate-500">
              Propuesta por <span class="text-slate-300">{{ proposal.user?.name ?? 'Usuario' }}</span>
              · {{ new Date(proposal.created_at).toLocaleDateString('es-ES') }}
              · TMDB ID: <span class="text-slate-300 font-mono">{{ proposal.tmdb_id }}</span>
            </div>

            <p v-if="proposal.tmdb_snapshot?.overview" class="text-slate-500 text-xs line-clamp-2 mt-1">
              {{ proposal.tmdb_snapshot.overview }}
            </p>

            <p v-if="proposal.admin_notes" class="text-xs text-red-400 mt-1">
              Nota: {{ proposal.admin_notes }}
            </p>
          </div>

          <!-- Actions (only for pending) -->
          <div v-if="proposal.status === 'pending'" class="flex flex-col gap-2 flex-shrink-0">
            <button
              @click="approve(proposal)"
              :disabled="isProcessing === proposal.id"
              class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold transition-colors disabled:opacity-50 flex items-center gap-1.5"
            >
              <div v-if="isProcessing === proposal.id" class="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" />
              <span>{{ isProcessing === proposal.id ? '...' : 'Aprobar' }}</span>
            </button>
            <button
              @click="askReject(proposal)"
              :disabled="!!isProcessing"
              class="px-3 py-1.5 rounded-lg border border-slate-700 text-slate-400 hover:border-red-800 hover:text-red-400 text-xs font-semibold transition-colors disabled:opacity-50"
            >
              Rechazar
            </button>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="lastPage > 1" class="flex justify-center gap-2 mt-8">
        <button
          v-for="p in lastPage"
          :key="p"
          @click="fetchProposals(p)"
          :class="[
            'w-8 h-8 rounded-lg text-sm font-medium transition-colors',
            p === currentPage ? 'bg-brand text-white' : 'bg-neutral-800 text-slate-400 hover:text-white'
          ]"
        >{{ p }}</button>
      </div>

    </div>
  </div>
</template>

<style scoped>
.content-wrap { width: 100%; margin-left: auto; margin-right: auto; }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
