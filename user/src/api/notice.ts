import { get, post } from '@/utils/request'
import type { PageQuery, PageResult, Notice } from './types'

// 用户端公告列表（首页展示）
export function getNoticeListApi(params: PageQuery) {
  return get<PageResult<Notice>>('/user-api/notices', params)
}

// 登录时需要弹窗展示的公告（未读）
export function getNoticeDialogsApi() {
  return get<Notice[]>('/user-api/notices/dialogs')
}

// 公告详情
export function getNoticeDetailApi(id: number) {
  return get<Notice>(`/user-api/notices/${id}`)
}

// 标记单条公告为已读
export function markNoticeReadApi(id: number) {
  return post<{ message: string }>(`/user-api/notices/${id}/read`)
}

// 标记全部公告为已读
export function markAllNoticeReadApi() {
  return post<{ message: string }>('/user-api/notices/read-all')
}
