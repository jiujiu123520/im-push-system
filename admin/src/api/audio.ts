import { get, post, put, del } from '@/utils/request'

export interface AudioRecord {
  id: number
  title: string
  artist: string
  filename: string
  file_path: string
  file_size: number
  file_size_text: string
  duration: number
  duration_text: string
  mime_type: string
  is_default: number
  sort_order: number
  status: number
  play_count: number
  play_url: string
  created_at: string
  updated_at: string
}

export function getAudioListApi(params: { page?: number; page_size?: number } = {}) {
  return get<{ list: AudioRecord[]; total: number; page: number; page_size: number }>(
    '/admin/audio',
    params
  )
}

export function uploadAudioApi(formData: FormData) {
  return post<{
    success: boolean
    message: string
    id: number
    title: string
    filename: string
    file_size: number
    duration: number
  }>('/admin/audio/upload', formData, {
    headers: {
      'Content-Type': 'multipart/form-data'
    }
  })
}

export function updateAudioApi(id: number, data: { title?: string; artist?: string; sort_order?: number; status?: number }) {
  return put<{ message: string }>(`/admin/audio/${id}`, data)
}

export function deleteAudioApi(id: number) {
  return del<{ message: string }>(`/admin/audio/${id}`)
}

export function setDefaultAudioApi(id: number) {
  return post<{ message: string }>(`/admin/audio/${id}/default`)
}

export function getAudioListPublicApi() {
  return get<{ list: AudioRecord[]; total: number }>('/api/audio/list')
}
