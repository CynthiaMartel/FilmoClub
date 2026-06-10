<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'
import LoginModal from '@/components/LoginModal.vue'
import ShareButton from '@/components/ShareButton.vue'
import { avatarUrl } from '@/composables/useAvatar'
import DOMPurify from 'dompurify'

// --- SETUP ---
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

// --- ESTADO DEL POST ---
const post = ref(null)
const isLoading = ref(true)
const error = ref(null)

// --- ESTADO DE COMENTARIOS ---
const comments = ref([])
const newComment = ref('')
const isSending = ref(false)
const isLoginOpen = ref(false)
const type = 'post'

// --- COMPUTADOS ---
const editorInitials = computed(() => {
    const name = post.value?.editorName || 'CC';
    return name.substring(0, 2).toUpperCase();
});

const currentUserId = computed(() => auth.user?.id);
const isAuthenticated = computed(() => auth.isAuthenticated);

// Segunda línea de defensa contra XSS: aunque el servidor ya sanitiza con HTMLPurifier,
// DOMPurify en el cliente previene cualquier payload que pudiera llegar corrupto o
// provenir de una caché/migración de datos anterior.
const safeContent = computed(() =>
    DOMPurify.sanitize(post.value?.content ?? '', {
        ALLOWED_TAGS: ['p','br','strong','b','em','i','u','s','h2','h3','h4','blockquote','ul','ol','li','a','img','figure','figcaption'],
        ALLOWED_ATTR: ['href','title','target','rel','src','alt','width','height'],
        ALLOW_DATA_ATTR: false,
    })
);

// --- MÉTODOS ---
const fetchPost = async () => {
    isLoading.value = true
    try {
        const { data } = await api.get(`/post-show/${route.params.id}`)
        post.value = data.data || data
        fetchComments()
    } catch (e) {
        console.error("Error cargando post:", e)
        error.value = "No se pudo cargar la noticia."
    } finally {
        isLoading.value = false
    }
}

const formatDate = (dateString) => {
    if (!dateString) return ''
    return new Date(dateString).toLocaleDateString('es-ES', {
        day: '2-digit', month: 'long', year: 'numeric'
    })
}

const goBack = () => router.back()
const goProfile = (username) => {
    if (username) router.push({ name: 'user-profile', params: { username } })
}
const goFilm = (filmId) => router.push({ name: 'film-detail', params: { id: filmId } })

// --- MÉTODOS DE COMENTARIOS ---
const openLogin = () => { isLoginOpen.value = true }

const fetchComments = async () => {
    if (!post.value?.id) return;
    try {
        const { data } = await api.get(`/comments/${type}/${post.value.id}`);
        comments.value = data.data || data;
    } catch (e) {
        console.error("Error cargando comentarios:", e);
    }
};

const handlePostComment = async () => {
    if (!newComment.value.trim()) return;
    isSending.value = true;
    try {
        const { data } = await api.post(`/comments/${type}/${post.value.id}/create`, {
            comment: newComment.value
        });
        comments.value.unshift(data.data || data);
        newComment.value = '';
    } catch (e) {
        if(e.response?.status === 401) openLogin();
    } finally {
        isSending.value = false;
    }
};

const handleDeleteComment = async (id) => {
    if (!confirm('¿Borrar comentario?')) return;
    try {
        await api.delete(`/comments/${id}/delete`);
        comments.value = comments.value.filter(c => c.id !== id);
    } catch (e) {
        console.error("Error borrando comentario", e);
    }
};

const formatCommentDate = (date) => new Date(date).toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' });

onMounted(() => {
    fetchPost()
})

watch(() => route.params.id, () => {
    fetchPost()
})
</script>

<template>
  <div class="min-h-screen w-full bg-[#14181c] text-[#9ab] font-sans overflow-x-hidden selection:bg-[#BE2B0C]/40 pb-20">

    <nav class="sticky top-0 z-50 bg-[#14181c]/90 backdrop-blur-md border-b border-white/5 px-4 sm:px-6 py-4">
        <div class="content-wrap mx-auto max-w-[1100px] flex items-center justify-between">
            <button @click="goBack" class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500 hover:text-white transition-colors flex items-center gap-2">
                &larr; Volver
            </button>
            <span class="text-[10px] font-bold text-[#BE2B0C] uppercase tracking-widest">
                FilmoClub News
            </span>
        </div>
    </nav>

    <div v-if="isLoading" class="flex flex-col items-center justify-center min-h-[60vh] gap-4">
        <div class="w-12 h-12 border-4 border-slate-800 border-t-[#BE2B0C] rounded-full animate-spin"></div>
    </div>

    <div v-else-if="error" class="flex flex-col items-center justify-center min-h-[60vh] text-center px-6">
        <h2 class="text-2xl font-serif text-white mb-2">Error</h2>
        <p class="text-sm text-slate-500">{{ error }}</p>
        <button @click="goBack" class="mt-6 text-[#BE2B0C] hover:underline text-xs uppercase font-bold tracking-widest">Regresar</button>
    </div>

    <div v-else class="content-wrap mx-auto max-w-[1100px] px-6 md:px-10 lg:px-0 py-10 relative z-10">

        <div>

            <!-- ARTÍCULO PRINCIPAL -->
            <article>

                <header class="mb-10 text-left">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:gap-8 lg:gap-12">

                        <!-- Columna izquierda: meta + título + subtítulo + autor -->
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-col items-start gap-4 mb-6">
                                <span v-if="!post.visible" class="text-[9px] font-black bg-yellow-600 text-black px-3 py-1 rounded-full uppercase tracking-widest shadow-lg mb-2">
                                    Borrador Privado
                                </span>

                                <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full border border-[#BE2B0C]/30 bg-[#BE2B0C]/5 shadow-[0_0_20px_rgba(190,43,12,0.15)] backdrop-blur-sm">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#BE2B0C] animate-pulse"></span>
                                    <span class="text-[10px] font-black text-[#BE2B0C] uppercase tracking-[0.25em]">
                                        Noticia Destacada
                                    </span>
                                </div>

                                <span class="text-[10px] text-slate-500 font-bold uppercase tracking-widest mt-1">
                                    {{ formatDate(post.created_at) }}
                                </span>
                            </div>

                            <h1 class="text-3xl md:text-5xl lg:text-6xl font-serif font-black text-white leading-[1.1] mb-6 tracking-tight text-left">
                                {{ post.title }}
                            </h1>

                            <p class="text-lg md:text-xl text-slate-400 font-light leading-relaxed mb-8 text-left">
                                {{ post.subtitle }}
                            </p>

                            <!-- ESCRITO POR — con enlace a perfil de usuario -->
                            <div class="flex items-center justify-between pt-6 border-t border-white/5 w-full gap-4">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="w-10 h-10 rounded-full bg-slate-700 flex items-center justify-center text-xs font-bold text-white overflow-hidden border border-white/10 shadow-lg shrink-0"
                                        :class="post.user?.name ? 'cursor-pointer hover:border-[#BE2B0C]/50 transition-colors' : ''"
                                        @click="goProfile(post.user?.name)"
                                    >
                                        <img
                                            v-if="avatarUrl(post.user?.avatar)"
                                            :src="avatarUrl(post.user?.avatar)"
                                            class="w-full h-full object-cover"
                                        />
                                        <span v-else>{{ editorInitials }}</span>
                                    </div>
                                    <div class="text-left">
                                        <p class="text-[9px] text-slate-500 uppercase tracking-widest font-bold mb-0.5">Escrito por</p>
                                        <p
                                            class="text-sm text-white font-bold"
                                            :class="post.user?.name ? 'cursor-pointer hover:text-[#BE2B0C] transition-colors' : ''"
                                            @click="goProfile(post.user?.name)"
                                        >
                                            {{ post.editorName || post.user?.name || 'Redacción' }}
                                        </p>
                                    </div>
                                </div>
                                <ShareButton />
                            </div>
                        </div>

                        <!-- Columna derecha: miniaturas de películas asociadas -->
                        <div v-if="post.films?.length" class="shrink-0 sm:w-[140px] lg:w-[160px] mt-6 sm:mt-0 sm:sticky sm:top-24 self-start">
                            <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-2">
                                {{ post.films.length === 1 ? 'Película' : 'Películas' }}
                            </p>
                            <div class="grid grid-cols-3 sm:grid-cols-2 gap-1.5">
                                <div
                                    v-for="film in post.films.slice(0, 6)"
                                    :key="film.idFilm"
                                    class="group cursor-pointer"
                                    @click="goFilm(film.idFilm)"
                                >
                                    <div class="rounded overflow-hidden border border-white/10 bg-[#1b2228] transition-all duration-300 group-hover:border-[#BE2B0C]/40 group-hover:shadow-[0_0_12px_rgba(190,43,12,0.1)]">
                                        <img
                                            :src="film.frame || '/default-poster.webp'"
                                            :alt="film.title"
                                            class="w-full aspect-[2/3] object-cover transition-transform duration-500 group-hover:scale-105"
                                        />
                                    </div>
                                    <p class="mt-1 text-[8px] font-bold text-slate-500 group-hover:text-white transition-colors leading-tight line-clamp-1 text-center">{{ film.title }}</p>
                                </div>
                            </div>
                        </div>

                    </div>
                </header>

                <figure class="w-full aspect-video rounded-xl overflow-hidden shadow-2xl border border-white/10 mb-12 bg-[#1b2228] relative">
                    <img
                        :src="post.img || '/default-poster.webp'"
                        :alt="post.title"
                        class="w-full h-full object-cover"
                    >
                </figure>

                <div class="post-content text-slate-300 leading-relaxed text-lg mb-16 font-sans font-light text-left">
                    <div class="ck-content" v-html="safeContent"></div>
                </div>

                <section class="border-t border-slate-800/50 pt-12">
                    <div class="flex items-center gap-3 mb-10">
                        <span class="w-1.5 h-6 bg-[#BE2B0C] rounded-full shadow-[0_0_10px_#BE2B0C]"></span>
                        <h3 class="text-[18px] font-black uppercase tracking-[3px] text-slate-400">
                            Comunidad ({{ comments.length }})
                        </h3>
                    </div>

                    <div class="space-y-4">
                        <div v-if="isAuthenticated" class="mb-12 group">
                            <textarea
                                v-model="newComment"
                                placeholder="¿Qué te parece esta entrada?"
                                class="w-full bg-slate-900/40 border border-slate-800 rounded-2xl p-5 text-slate-200 focus:ring-2 focus:ring-[#BE2B0C]/30 focus:border-[#BE2B0C]/50 outline-none mb-4 resize-none transition-all text-sm shadow-inner"
                                rows="3"
                            ></textarea>
                            <div class="flex justify-end">
                                <button
                                    @click="handlePostComment"
                                    :disabled="isSending || !newComment.trim()"
                                    class="bg-[#BE2B0C] hover:bg-[#a02208] text-white font-black py-3 px-10 rounded-full text-[11px] uppercase tracking-[2px] transition-all shadow-xl cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {{ isSending ? 'Enviando...' : 'Publicar' }}
                                </button>
                            </div>
                        </div>
                        <div v-else class="bg-slate-800/30 p-10 rounded-2xl border border-dashed border-slate-800 text-center">
                            <p class="text-slate-500 font-black uppercase text-[10px] tracking-widest">
                                ¡Haz <span @click="openLogin" class="text-[#BE2B0C] cursor-pointer hover:underline">login</span> para participar!
                            </p>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <div v-for="comment in comments" :key="comment.id" class="flex gap-5 animate-fade-in group text-left">
                            <div @click="goProfile(comment.user.name)"
                                class="w-11 h-11 bg-slate-800 rounded-full flex items-center justify-center text-[#BE2B0C] font-black shrink-0 border border-slate-700 text-lg shadow-md cursor-pointer hover:scale-105 transition-transform overflow-hidden">
                                <img v-if="avatarUrl(comment.user?.avatar)" :src="avatarUrl(comment.user?.avatar)" class="w-full h-full object-cover">
                                <span v-else>{{ comment.user?.name?.charAt(0).toUpperCase() || 'U' }}</span>
                            </div>

                            <div class="flex-1">
                                <div class="bg-slate-800/30 border border-slate-800/60 p-5 rounded-2xl rounded-tl-none group-hover:border-slate-700 transition-colors">
                                    <div class="flex items-center justify-between mb-2">
                                        <span @click="goProfile(comment.user.name)" class="text-white font-bold text-sm cursor-pointer hover:text-[#BE2B0C] transition-colors">
                                            @{{ comment.user?.name }}
                                        </span>
                                        <span class="text-[9px] text-slate-500 uppercase font-black tracking-tighter">
                                            {{ formatCommentDate(comment.created_at) }}
                                        </span>
                                    </div>
                                    <p class="text-slate-300 text-sm leading-relaxed font-light">
                                        {{ comment.comment }}
                                    </p>
                                </div>

                                <button
                                    v-if="currentUserId === comment.user_id"
                                    @click="handleDeleteComment(comment.id)"
                                    class="ml-4 mt-2 text-[9px] text-slate-600 hover:text-red-500 font-black uppercase tracking-widest transition-colors cursor-pointer"
                                >
                                    Eliminar
                                </button>
                            </div>
                        </div>

                        <div v-if="comments.length === 0" class="py-10 text-center opacity-50">
                            <p class="text-[10px] text-slate-500 uppercase tracking-widest">Sé el primero en comentar.</p>
                        </div>
                    </div>
                </section>

            </article>

        </div>
    </div>

    <LoginModal v-model="isLoginOpen" />
  </div>
</template>

<style scoped>
.content-wrap {
    width: 100%;
    margin-left: auto;
    margin-right: auto;
}

.font-serif {
    font-family: 'Tiempos Headline', Georgia, serif;
}

/* --- ESTILOS PARA EL CONTENIDO DEL EDITOR (V-HTML) --- */
:deep(.post-content h2) {
    color: white;
    font-family: 'Tiempos Headline', Georgia, serif;
    font-size: 1.8rem;
    font-weight: 900;
    margin-top: 3rem;
    margin-bottom: 1.5rem;
    line-height: 1.2;
}

:deep(.post-content h3) {
    color: #e2e8f0;
    font-size: 1.4rem;
    font-weight: 700;
    margin-top: 2.5rem;
    margin-bottom: 1rem;
}

:deep(.post-content p) {
    margin-bottom: 1.5rem;
}

:deep(.post-content strong) {
    color: white;
    font-weight: 700;
}

:deep(.post-content a) {
    color: #BE2B0C;
    text-decoration: underline;
    text-underline-offset: 4px;
}
:deep(.post-content a:hover) {
    color: #fff;
}

:deep(.post-content ul) {
    list-style-type: disc;
    padding-left: 1.5rem;
    margin-bottom: 2rem;
    color: #cbd5e1;
}

:deep(.post-content ol) {
    list-style-type: decimal;
    padding-left: 1.5rem;
    margin-bottom: 2rem;
    color: #cbd5e1;
}

:deep(.post-content blockquote) {
    border-left: 4px solid #BE2B0C;
    padding-left: 1.5rem;
    margin: 2rem 0;
    font-style: italic;
    color: #94a3b8;
    background: rgba(255, 255, 255, 0.03);
    padding: 1.5rem;
    border-radius: 0 8px 8px 0;
}

:deep(.post-content img) {
    border-radius: 8px;
    margin: 2rem auto;
    max-width: 100%;
    box-shadow: 0 10px 30px -10px rgba(0,0,0,0.5);
    border: 1px solid rgba(255,255,255,0.1);
}

.animate-fade-in {
  animation: fadeIn 0.5s ease-out;
}
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
