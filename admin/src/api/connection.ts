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

// 僵尸订阅列表（Redis 订阅关系还在，但 devices 表已无该设备）
export function getZombieSubscriptionsApi() {
  return get<{ list: any[]; total: number }>('/admin/zombie-subscriptions')
}

// 删除单个僵尸订阅
export function deleteZombieSubscriptionApi(deviceId: string) {
  return del(`/admin/zombie-subscriptions/${encodeURIComponent(deviceId)}`)
}

// 一键清理所有僵尸（连接 + 订阅）
export function cleanupZombieConnectionsApi(threshold?: number) {
  return post<{
    removed: number
    checked: number
    threshold: number
    subscriptions_removed: number
    subscriptions_checked: number
  }>('/admin/zombie-connections/cleanup', threshold ? { threshold } : undefined)
}
