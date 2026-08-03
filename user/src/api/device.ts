import { get, put, del } from '@/utils/request'
import type { PageQuery, PageResult, Device } from './types'

// 用户端设备列表
export function getDeviceListApi(params: PageQuery) {
  return get<PageResult<Device>>('/user-api/devices', params)
}

// 用户端设备详情
export function getDeviceDetailApi(id: number) {
  return get<Device>(`/user-api/devices/${id}`)
}

// 切换设备状态（启用/禁用）
export function updateDeviceStatusApi(id: number, status: 1 | 2) {
  return put<{ message: string }>(`/user-api/devices/${id}/status`, { status })
}

// 删除设备
export function deleteDeviceApi(id: number) {
  return del<{ message: string }>(`/user-api/devices/${id}`)
}
