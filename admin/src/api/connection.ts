import { get, post, del } from '@/utils/request'

// 在线连接列表
export function getAllConnectionsApi() {
  return get<{ list: any[]; total: number }>('/admin/connections')
}

// 僵尸连接列表
export function getZombieConnectionsApi(threshold?: number) {
  return get<{ list: any[]; total: number; threshold: number }>('/admin/zombie-connections', threshold ? { threshold } : undefined)
}

// 删除单个僵尸连接
export function deleteZombieConnectionApi(fd: number) {
  return del(`/admin/zombie-connections/${fd}`)
}

// 一键清理所有僵尸连接
export function cleanupZombieConnectionsApi(threshold?: number) {
  return post<{ removed: number; checked: number; threshold: number }>('/admin/zombie-connections/cleanup', threshold ? { threshold } : undefined)
}
