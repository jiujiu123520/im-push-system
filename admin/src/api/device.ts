import { get, post, put, del } from '@/utils/request'
import type { PageQuery, PageResult, DeviceRecord, DeviceForm } from './types'

// 设备列表
export function getDeviceListApi(params: PageQuery) {
  return get<PageResult<DeviceRecord>>('/admin/devices', params)
}

// 设备详情
export function getDeviceDetailApi(id: number) {
  return get<DeviceRecord>(`/admin/devices/${id}`)
}

// 新增设备
export function createDeviceApi(data: DeviceForm) {
  return post('/admin/devices', data)
}

// 更新设备
export function updateDeviceApi(id: number, data: DeviceForm) {
  return put(`/admin/devices/${id}`, data)
}

// 删除设备
export function deleteDeviceApi(id: number) {
  return del(`/admin/devices/${id}`)
}

// 切换设备状态
export function toggleDeviceStatusApi(id: number, status: number) {
  return put(`/admin/devices/${id}/status`, { status })
}

// 更新设备标签
export function updateDeviceTagsApi(id: number, tags: string[]) {
  return put(`/admin/devices/${id}/tags`, { tags })
}

// 解除设备绑定
export function unbindDeviceApi(id: number) {
  return post(`/admin/devices/${id}/unbind`)
}

// 批量删除设备
export function batchDeleteDevicesApi(ids: number[]) {
  return del('/admin/devices/batch', { ids })
}
