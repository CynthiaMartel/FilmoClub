import api from '@/services/api'

export function useLikeToggle(endpoint) {
    const toggle = async (item) => {
        const { data } = await api.post(`${endpoint}/${item.id}/like`)
        item.likes_count = data.likes_count
        item.i_liked = data.i_liked
        return data
    }
    return { toggle }
}
