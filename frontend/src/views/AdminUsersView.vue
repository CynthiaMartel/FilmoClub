<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import api from '@/services/api'

// ─── Pestañas ────────────────────────────────────────────────────────────────
const activeTab = ref('users') // 'users' | 'reports'

// ─── Stats globales ────────────────────────────────────────────────────────────
const stats = reactive({ total_users: 0, blocked_users: 0, flagged_users: 0, pending_reports: 0 })

async function fetchStats() {
  try {
    const { data } = await api.get('/admin/users/stats')
    Object.assign(stats, data.data)
  } catch {}
}

// ─── PESTAÑA USUARIOS ─────────────────────────────────────────────────────────
const users        = ref([])
const usersLoading = ref(false)
const currentPage  = ref(1)
const lastPage     = ref(1)
const total        = ref(0)

const filters = reactive({ q: '', role: '', status: '' })
let searchTimer = null

function onSearchInput() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { currentPage.value = 1; fetchUsers() }, 350)
}

async function fetchUsers() {
  usersLoading.value = true
  try {
    const { data } = await api.get('/admin/users', {
      params: { ...filters, page: currentPage.value },
    })
    users.value    = data.data.data
    lastPage.value = data.data.last_page
    total.value    = data.data.total
  } catch (e) {
    showToast('Error al cargar usuarios', 'error')
  } finally {
    usersLoading.value = false
  }
}

function applyFilters() { currentPage.value = 1; fetchUsers() }
function prevPage()     { if (currentPage.value > 1) { currentPage.value--; fetchUsers() } }
function nextPage()     { if (currentPage.value < lastPage.value) { currentPage.value++; fetchUsers() } }

// ─── Detalle / acciones sobre usuario ─────────────────────────────────────────
const selectedUser    = ref(null)
const userDetailOpen  = ref(false)
const userDetailLoad  = ref(false)
const roleChanging    = ref(false)
const newRoleId       = ref(null)
const blockingId      = ref(null)
const confirmBlockId  = ref(null)

async function openUser(id) {
  userDetailOpen.value = true
  userDetailLoad.value = true
  try {
    const { data } = await api.get(`/admin/users/${id}`)
    selectedUser.value = data.data
    newRoleId.value    = data.data.role_id
  } catch {
    showToast('Error al cargar el usuario', 'error')
    userDetailOpen.value = false
  } finally {
    userDetailLoad.value = false
  }
}

function closeDetail() { userDetailOpen.value = false; selectedUser.value = null }

async function toggleBlock(user) {
  if (confirmBlockId.value !== user.id) { confirmBlockId.value = user.id; return }
  confirmBlockId.value = null
  blockingId.value = user.id
  try {
    const endpoint = user.blocked ? `/users/${user.id}/unblock` : `/users/${user.id}/block`
    await api.post(endpoint)
    const msg = user.blocked ? 'Usuario desbloqueado.' : 'Usuario bloqueado.'
    showToast(msg)
    fetchUsers()
    fetchStats()
    if (selectedUser.value?.id === user.id) {
      selectedUser.value.blocked = !user.blocked
    }
  } catch (e) {
    showToast(e?.response?.data?.message || 'Error', 'error')
  } finally {
    blockingId.value = null
  }
}

async function changeRole() {
  if (!selectedUser.value || !newRoleId.value) return
  roleChanging.value = true
  try {
    const { data } = await api.patch(`/admin/users/${selectedUser.value.id}/role`, { role_id: Number(newRoleId.value) })
    selectedUser.value = data.data
    showToast('Rol actualizado.')
    fetchUsers()
  } catch (e) {
    showToast(e?.response?.data?.message || 'Error al cambiar rol', 'error')
  } finally {
    roleChanging.value = false
  }
}

// ─── PESTAÑA DENUNCIAS ────────────────────────────────────────────────────────
const reports        = ref([])
const reportsLoading = ref(false)
const reportsPage    = ref(1)
const reportsLastPage = ref(1)
const reportStatusFilter     = ref('pending')
const includeLowConf = ref(false)
const reviewingId    = ref(null)
const adminNoteInput = ref('')
const reviewPanelId  = ref(null)

async function fetchReports() {
  reportsLoading.value = true
  try {
    const { data } = await api.get('/admin/users/reports/list', {
      params: {
        status:                 reportStatusFilter.value || undefined,
        include_low_confidence: includeLowConf.value ? 1 : 0,
        page:                   reportsPage.value,
      },
    })
    reports.value        = data.data.data
    reportsLastPage.value = data.data.last_page
  } catch {
    showToast('Error al cargar denuncias', 'error')
  } finally {
    reportsLoading.value = false
  }
}

function openReviewPanel(report) {
  reviewPanelId.value  = report.id
  adminNoteInput.value = report.admin_note || ''
}

function closeReviewPanel() { reviewPanelId.value = null; adminNoteInput.value = '' }

async function submitReview(report, status) {
  reviewingId.value = report.id
  try {
    await api.patch(`/admin/users/reports/${report.id}/review`, {
      status,
      admin_note: adminNoteInput.value || null,
    })
    showToast(status === 'dismissed' ? 'Denuncia desestimada.' : 'Acción registrada.')
    closeReviewPanel()
    fetchReports()
    fetchStats()
  } catch (e) {
    showToast(e?.response?.data?.message || 'Error', 'error')
  } finally {
    reviewingId.value = null
  }
}

function prevReportsPage() { if (reportsPage.value > 1) { reportsPage.value--; fetchReports() } }
function nextReportsPage() { if (reportsPage.value < reportsLastPage.value) { reportsPage.value++; fetchReports() } }

watch(reportStatusFilter, () => { reportsPage.value = 1; fetchReports() })
watch(includeLowConf, () => { reportsPage.value = 1; fetchReports() })

// ─── Toast ────────────────────────────────────────────────────────────────────
const toast = ref(null)
function showToast(msg, type = 'ok') {
  toast.value = { msg, type }
  setTimeout(() => (toast.value = null), 3500)
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
const CATEGORY_LABELS = {
  spam:                 'Spam',
  harassment:           'Acoso',
  inappropriate_content:'Contenido inapropiado',
  impersonation:        'Suplantación de identidad',
  other:                'Otro',
}

const ROLE_OPTIONS = [
  { id: 1, label: 'Admin' },
  { id: 2, label: 'Editor' },
  { id: 3, label: 'Usuario' },
]

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
  fetchStats()
  fetchUsers()
})

watch(activeTab, (tab) => {
  if (tab === 'reports' && reports.value.length === 0) fetchReports()
})
</script>

<template>
  <div class="min-h-screen bg-neutral-950 text-neutral-100 pb-20">

    <!-- Toast -->
    <Transition name="fade">
      <div v-if="toast" :class="['fixed top-6 right-6 z-50 px-5 py-3 rounded-xl text-sm font-medium shadow-xl', toast.type === 'error' ? 'bg-red-600' : 'bg-emerald-600']">
        {{ toast.msg }}
      </div>
    </Transition>

    <div class="content-wrap mx-auto max-w-[1100px] px-6 md:px-10 lg:px-0 py-10">

    <!-- Header -->
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-neutral-100">Panel de usuarios</h1>
      <p class="text-neutral-500 text-sm mt-1">Gestión y moderación · Solo visible para administradores</p>
    </div>

    <!-- Stats cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
      <div class="bg-neutral-900 border border-neutral-800 rounded-xl p-4">
        <p class="text-xs text-neutral-500 mb-1">Total usuarios</p>
        <p class="text-2xl font-bold">{{ stats.total_users }}</p>
      </div>
      <div class="bg-neutral-900 border border-neutral-800 rounded-xl p-4">
        <p class="text-xs text-neutral-500 mb-1">Bloqueados</p>
        <p class="text-2xl font-bold text-red-400">{{ stats.blocked_users }}</p>
      </div>
      <div class="bg-neutral-900 border border-neutral-800 rounded-xl p-4">
        <p class="text-xs text-neutral-500 mb-1">Marcados (≥3 bloqueos)</p>
        <p class="text-2xl font-bold text-amber-400">{{ stats.flagged_users }}</p>
      </div>
      <div class="bg-neutral-900 border border-neutral-800 rounded-xl p-4 cursor-pointer hover:border-amber-500 transition-colors" @click="activeTab = 'reports'">
        <p class="text-xs text-neutral-500 mb-1">Denuncias pendientes</p>
        <p class="text-2xl font-bold text-amber-400">{{ stats.pending_reports }}</p>
      </div>
    </div>

    <!-- Pestañas -->
    <div class="flex gap-2 mb-4 border-b border-neutral-800">
      <button
        v-for="tab in [{ id: 'users', label: 'Usuarios' }, { id: 'reports', label: 'Denuncias' }]"
        :key="tab.id"
        @click="activeTab = tab.id"
        :class="['px-4 py-2 text-sm font-medium border-b-2 transition-colors -mb-px', activeTab === tab.id ? 'border-white text-white' : 'border-transparent text-neutral-500 hover:text-neutral-300']"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- ══════════════════ PESTAÑA USUARIOS ══════════════════ -->
    <div v-if="activeTab === 'users'">

      <!-- Filtros -->
      <div class="flex flex-wrap gap-2 mb-4">
        <input
          v-model="filters.q"
          @input="onSearchInput"
          placeholder="Buscar por nombre o email…"
          class="bg-neutral-900 border border-neutral-700 rounded-lg px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus:outline-none focus:border-neutral-500 flex-1 min-w-48"
        />
        <select v-model="filters.role" @change="applyFilters" class="bg-neutral-900 border border-neutral-700 rounded-lg px-3 py-2 text-sm text-neutral-300">
          <option value="">Todos los roles</option>
          <option value="1">Admin</option>
          <option value="2">Editor</option>
          <option value="3">Usuario</option>
        </select>
        <select v-model="filters.status" @change="applyFilters" class="bg-neutral-900 border border-neutral-700 rounded-lg px-3 py-2 text-sm text-neutral-300">
          <option value="">Todos los estados</option>
          <option value="active">Activos</option>
          <option value="blocked">Bloqueados</option>
          <option value="flagged">Marcados (≥3 bloqueos)</option>
        </select>
      </div>

      <!-- Tabla -->
      <div class="bg-neutral-900 border border-neutral-800 rounded-xl overflow-hidden">
        <div v-if="usersLoading" class="py-16 text-center text-neutral-500 text-sm">Cargando…</div>
        <div v-else-if="users.length === 0" class="py-16 text-center text-neutral-500 text-sm">No se encontraron usuarios.</div>
        <table v-else class="w-full text-sm">
          <thead class="border-b border-neutral-800">
            <tr class="text-left text-xs text-neutral-500">
              <th class="px-4 py-3 font-medium">Usuario</th>
              <th class="px-4 py-3 font-medium hidden md:table-cell">Rol</th>
              <th class="px-4 py-3 font-medium hidden lg:table-cell">Último acceso</th>
              <th class="px-4 py-3 font-medium text-center">Bloqueos recibidos</th>
              <th class="px-4 py-3 font-medium text-center">Denuncias</th>
              <th class="px-4 py-3 font-medium">Estado</th>
              <th class="px-4 py-3 font-medium text-right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="user in users"
              :key="user.id"
              :class="['border-b border-neutral-800 last:border-0 hover:bg-neutral-800/40 transition-colors', user.flagged ? 'bg-amber-950/20' : '']"
            >
              <!-- Nombre + email -->
              <td class="px-4 py-3">
                <div class="flex items-center gap-2">
                  <span v-if="user.flagged" title="Marcado por bloqueos recibidos" class="text-amber-400 text-xs">⚠</span>
                  <div>
                    <p class="font-medium text-neutral-100 truncate max-w-36">{{ user.name }}</p>
                    <p class="text-neutral-500 text-xs truncate max-w-36">{{ user.email }}</p>
                  </div>
                </div>
              </td>
              <!-- Rol -->
              <td class="px-4 py-3 hidden md:table-cell">
                <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', user.role === 'Admin' ? 'bg-violet-900 text-violet-300' : user.role === 'Editor' ? 'bg-blue-900 text-blue-300' : 'bg-neutral-800 text-neutral-400']">
                  {{ user.role }}
                </span>
              </td>
              <!-- Último acceso -->
              <td class="px-4 py-3 hidden lg:table-cell text-neutral-400 text-xs">{{ formatDate(user.last_access) }}</td>
              <!-- Bloqueos recibidos -->
              <td class="px-4 py-3 text-center">
                <span :class="['font-medium', user.blocks_received_count >= 3 ? 'text-amber-400' : 'text-neutral-400']">
                  {{ user.blocks_received_count }}
                </span>
              </td>
              <!-- Denuncias pendientes -->
              <td class="px-4 py-3 text-center">
                <span :class="['font-medium', user.pending_reports_count > 0 ? 'text-red-400' : 'text-neutral-400']">
                  {{ user.pending_reports_count }}
                </span>
              </td>
              <!-- Estado -->
              <td class="px-4 py-3">
                <span v-if="user.blocked" class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-900 text-red-300">Bloqueado</span>
                <span v-else class="px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-900/50 text-emerald-400">Activo</span>
              </td>
              <!-- Acciones -->
              <td class="px-4 py-3 text-right">
                <div class="flex items-center justify-end gap-2">
                  <button @click="openUser(user.id)" class="text-xs text-neutral-400 hover:text-white underline">Ver</button>
                  <button
                    v-if="blockingId !== user.id"
                    @click="toggleBlock(user)"
                    :class="['text-xs px-2 py-1 rounded-lg transition-colors', user.blocked ? 'bg-emerald-900/50 text-emerald-400 hover:bg-emerald-800' : confirmBlockId === user.id ? 'bg-red-600 text-white' : 'bg-neutral-800 text-neutral-400 hover:bg-red-900/50 hover:text-red-400']"
                  >
                    {{ user.blocked ? 'Desbloquear' : confirmBlockId === user.id ? '¿Confirmar?' : 'Bloquear' }}
                  </button>
                  <span v-else class="text-xs text-neutral-500">…</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Paginación -->
      <div v-if="lastPage > 1" class="flex items-center justify-between mt-4 text-sm text-neutral-500">
        <span>{{ total }} usuarios · pág. {{ currentPage }}/{{ lastPage }}</span>
        <div class="flex gap-2">
          <button @click="prevPage" :disabled="currentPage === 1" class="px-3 py-1 bg-neutral-800 rounded-lg disabled:opacity-40 hover:bg-neutral-700">←</button>
          <button @click="nextPage" :disabled="currentPage === lastPage" class="px-3 py-1 bg-neutral-800 rounded-lg disabled:opacity-40 hover:bg-neutral-700">→</button>
        </div>
      </div>
    </div>

    <!-- ══════════════════ PESTAÑA DENUNCIAS ══════════════════ -->
    <div v-if="activeTab === 'reports'">

      <!-- Filtros denuncias -->
      <div class="flex flex-wrap items-center gap-3 mb-4">
        <select v-model="reportStatusFilter" class="bg-neutral-900 border border-neutral-700 rounded-lg px-3 py-2 text-sm text-neutral-300">
          <option value="pending">Pendientes</option>
          <option value="reviewed">Revisadas</option>
          <option value="dismissed">Desestimadas</option>
          <option value="actioned">Con acción tomada</option>
          <option value="">Todas</option>
        </select>
        <label class="flex items-center gap-2 text-sm text-neutral-400 cursor-pointer">
          <input type="checkbox" v-model="includeLowConf" class="rounded" />
          Ver denuncias de baja confianza
        </label>
        <button @click="fetchReports" class="text-xs px-3 py-1.5 bg-neutral-800 text-neutral-300 rounded-lg hover:bg-neutral-700">Actualizar</button>
      </div>

      <!-- Lista de denuncias -->
      <div v-if="reportsLoading" class="py-16 text-center text-neutral-500 text-sm">Cargando…</div>
      <div v-else-if="reports.length === 0" class="py-16 text-center text-neutral-500 text-sm">No hay denuncias en este estado.</div>
      <div v-else class="flex flex-col gap-3">
        <div
          v-for="report in reports"
          :key="report.id"
          :class="['bg-neutral-900 border rounded-xl p-4', report.low_confidence ? 'border-neutral-700 opacity-75' : 'border-neutral-800']"
        >
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap gap-2 items-center mb-1">
                <span class="font-medium text-neutral-100 text-sm">{{ report.reported_user_name }}</span>
                <span v-if="report.reported_blocked" class="px-2 py-0.5 rounded-full text-xs bg-red-900 text-red-300">Bloqueado</span>
                <span v-if="report.low_confidence" class="px-2 py-0.5 rounded-full text-xs bg-neutral-800 text-neutral-500">Baja confianza</span>
              </div>
              <p class="text-xs text-neutral-500 mb-1">
                Denunciado por <span class="text-neutral-400">{{ report.reporter_name }}</span>
                · {{ formatDate(report.created_at) }}
              </p>
              <div class="flex flex-wrap gap-2 mt-2">
                <span class="px-2 py-0.5 rounded-full text-xs bg-neutral-800 text-neutral-300">{{ CATEGORY_LABELS[report.category] ?? report.category }}</span>
              </div>
              <p v-if="report.reason" class="text-neutral-400 text-sm mt-2 bg-neutral-800/50 rounded-lg px-3 py-2 italic">
                "{{ report.reason }}"
              </p>
              <p v-if="report.admin_note" class="text-neutral-500 text-xs mt-1">
                Nota admin: {{ report.admin_note }}
              </p>
            </div>

            <!-- Acciones (si está pendiente) -->
            <div v-if="report.status === 'pending'" class="flex flex-col gap-2 shrink-0">
              <button @click="openReviewPanel(report)" class="text-xs px-3 py-1.5 bg-neutral-800 text-neutral-300 rounded-lg hover:bg-neutral-700">Gestionar</button>
            </div>
            <div v-else class="shrink-0">
              <span :class="['px-2 py-1 rounded-full text-xs font-medium', report.status === 'dismissed' ? 'bg-neutral-800 text-neutral-400' : 'bg-emerald-900/50 text-emerald-400']">
                {{ { reviewed: 'Revisada', dismissed: 'Desestimada', actioned: 'Acción tomada' }[report.status] ?? report.status }}
              </span>
            </div>
          </div>

          <!-- Panel de revisión inline -->
          <div v-if="reviewPanelId === report.id" class="mt-4 border-t border-neutral-700 pt-4">
            <label class="block text-xs text-neutral-500 mb-1">Nota para el registro (opcional)</label>
            <textarea
              v-model="adminNoteInput"
              maxlength="500"
              rows="2"
              placeholder="Escribe una nota de moderación…"
              class="w-full bg-neutral-800 border border-neutral-700 rounded-lg px-3 py-2 text-sm text-neutral-100 placeholder-neutral-500 focus:outline-none focus:border-neutral-500 resize-none"
            />
            <div class="flex gap-2 mt-2">
              <button
                @click="submitReview(report, 'dismissed')"
                :disabled="reviewingId === report.id"
                class="text-xs px-3 py-1.5 bg-neutral-800 text-neutral-400 rounded-lg hover:bg-neutral-700 disabled:opacity-40"
              >
                Desestimar
              </button>
              <button
                @click="submitReview(report, 'actioned')"
                :disabled="reviewingId === report.id"
                class="text-xs px-3 py-1.5 bg-red-900/60 text-red-300 rounded-lg hover:bg-red-800 disabled:opacity-40"
              >
                Acción tomada
              </button>
              <button @click="closeReviewPanel" class="text-xs text-neutral-500 hover:text-neutral-300 ml-1">Cancelar</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Paginación denuncias -->
      <div v-if="reportsLastPage > 1" class="flex justify-end gap-2 mt-4">
        <button @click="prevReportsPage" :disabled="reportsPage === 1" class="px-3 py-1 text-sm bg-neutral-800 rounded-lg disabled:opacity-40 hover:bg-neutral-700">←</button>
        <button @click="nextReportsPage" :disabled="reportsPage === reportsLastPage" class="px-3 py-1 text-sm bg-neutral-800 rounded-lg disabled:opacity-40 hover:bg-neutral-700">→</button>
      </div>
    </div>

    <!-- ══════════════════ DRAWER DETALLE USUARIO ══════════════════ -->
    <Transition name="slide">
      <div v-if="userDetailOpen" class="fixed inset-0 z-40 flex justify-end">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeDetail" />
        <div class="relative z-10 w-full max-w-md bg-neutral-900 border-l border-neutral-800 flex flex-col overflow-y-auto">
          <div class="flex items-center justify-between p-5 border-b border-neutral-800 sticky top-0 bg-neutral-900">
            <h2 class="font-semibold text-neutral-100">Detalle de usuario</h2>
            <button @click="closeDetail" class="text-neutral-500 hover:text-white text-xl leading-none">×</button>
          </div>

          <div v-if="userDetailLoad" class="flex-1 flex items-center justify-center text-neutral-500 text-sm">Cargando…</div>

          <div v-else-if="selectedUser" class="p-5 flex flex-col gap-5">

            <!-- Info básica -->
            <div>
              <div class="flex items-center gap-2 mb-1">
                <p class="text-lg font-semibold text-neutral-100">{{ selectedUser.name }}</p>
                <span v-if="selectedUser.flagged" class="text-amber-400 text-xs" title="Marcado por bloqueos recibidos">⚠ Marcado</span>
              </div>
              <p class="text-neutral-500 text-sm">{{ selectedUser.email }}</p>
            </div>

            <!-- Badges de estado -->
            <div class="flex flex-wrap gap-2">
              <span :class="['px-2 py-1 rounded-full text-xs font-medium', selectedUser.role === 'Admin' ? 'bg-violet-900 text-violet-300' : selectedUser.role === 'Editor' ? 'bg-blue-900 text-blue-300' : 'bg-neutral-800 text-neutral-400']">
                {{ selectedUser.role }}
              </span>
              <span v-if="selectedUser.blocked" class="px-2 py-1 rounded-full text-xs font-medium bg-red-900 text-red-300">Bloqueado</span>
              <span v-if="selectedUser.two_factor_enabled" class="px-2 py-1 rounded-full text-xs font-medium bg-neutral-800 text-neutral-400">2FA activo</span>
              <span v-if="!selectedUser.email_verified" class="px-2 py-1 rounded-full text-xs font-medium bg-amber-900/50 text-amber-400">Email no verificado</span>
            </div>

            <!-- Datos de actividad (LOPD: mínimo necesario para moderación) -->
            <div class="bg-neutral-800/50 rounded-xl p-4 grid grid-cols-2 gap-3 text-sm">
              <div>
                <p class="text-neutral-500 text-xs mb-0.5">Registrado</p>
                <p class="text-neutral-300">{{ formatDate(selectedUser.created_at) }}</p>
              </div>
              <div>
                <p class="text-neutral-500 text-xs mb-0.5">Último acceso</p>
                <p class="text-neutral-300">{{ formatDate(selectedUser.last_access) }}</p>
              </div>
              <div>
                <p class="text-neutral-500 text-xs mb-0.5">Bloqueos recibidos</p>
                <p :class="['font-medium', selectedUser.blocks_received_count >= 3 ? 'text-amber-400' : 'text-neutral-300']">
                  {{ selectedUser.blocks_received_count }}
                </p>
              </div>
              <div>
                <p class="text-neutral-500 text-xs mb-0.5">Denuncias totales</p>
                <p class="text-neutral-300">{{ selectedUser.total_reports_count ?? 0 }}</p>
              </div>
            </div>

            <!-- Cambiar rol -->
            <div class="border border-neutral-800 rounded-xl p-4">
              <p class="text-xs text-neutral-500 mb-3">Cambiar rol</p>
              <div class="flex gap-2">
                <select v-model="newRoleId" class="flex-1 bg-neutral-800 border border-neutral-700 rounded-lg px-3 py-2 text-sm text-neutral-300">
                  <option v-for="r in ROLE_OPTIONS" :key="r.id" :value="r.id">{{ r.label }}</option>
                </select>
                <button
                  @click="changeRole"
                  :disabled="roleChanging || Number(newRoleId) === selectedUser.role_id"
                  class="px-4 py-2 bg-white text-black text-sm rounded-lg font-medium hover:bg-neutral-200 disabled:opacity-40 transition-colors"
                >
                  {{ roleChanging ? 'Guardando…' : 'Aplicar' }}
                </button>
              </div>
            </div>

            <!-- Bloquear / desbloquear -->
            <div class="border border-neutral-800 rounded-xl p-4">
              <p class="text-xs text-neutral-500 mb-3">Acceso a la plataforma</p>
              <button
                @click="toggleBlock(selectedUser)"
                :class="['w-full py-2 rounded-lg text-sm font-medium transition-colors', selectedUser.blocked ? 'bg-emerald-900/50 text-emerald-300 hover:bg-emerald-800' : confirmBlockId === selectedUser.id ? 'bg-red-600 text-white' : 'bg-red-900/30 text-red-400 hover:bg-red-900/60']"
              >
                {{ selectedUser.blocked ? 'Desbloquear cuenta' : confirmBlockId === selectedUser.id ? '¿Confirmar bloqueo?' : 'Bloquear cuenta' }}
              </button>
            </div>

            <!-- Denuncias recientes -->
            <div v-if="selectedUser.recent_reports?.length" class="border border-neutral-800 rounded-xl p-4">
              <p class="text-xs text-neutral-500 mb-3">Denuncias recientes ({{ selectedUser.recent_reports.length }})</p>
              <div class="flex flex-col gap-2">
                <div v-for="r in selectedUser.recent_reports" :key="r.id" class="bg-neutral-800/50 rounded-lg px-3 py-2 text-xs">
                  <div class="flex justify-between items-start gap-2">
                    <span class="text-neutral-300">{{ CATEGORY_LABELS[r.category] ?? r.category }}</span>
                    <span :class="['px-1.5 py-0.5 rounded text-xs', { pending:'bg-amber-900/50 text-amber-300', dismissed:'bg-neutral-700 text-neutral-400', actioned:'bg-red-900/50 text-red-300', reviewed:'bg-blue-900/50 text-blue-300' }[r.status] ?? '']">
                      {{ { pending:'Pendiente', dismissed:'Desestimada', actioned:'Acción', reviewed:'Revisada' }[r.status] ?? r.status }}
                    </span>
                  </div>
                  <p v-if="r.reason" class="text-neutral-500 mt-1 italic">{{ r.reason }}</p>
                  <p class="text-neutral-600 mt-1">{{ formatDate(r.created_at) }}</p>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </Transition>

    </div><!-- /content-wrap -->
  </div>
</template>

<style scoped>
.content-wrap { width: 100%; margin-left: auto; margin-right: auto; }

.fade-enter-active, .fade-leave-active { transition: opacity .25s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-enter-active, .slide-leave-active { transition: opacity .2s; }
.slide-enter-from, .slide-leave-to { opacity: 0; }
.slide-enter-active .relative, .slide-leave-active .relative { transition: transform .25s cubic-bezier(.4,0,.2,1); }
.slide-enter-from .relative, .slide-leave-to .relative { transform: translateX(100%); }
</style>
