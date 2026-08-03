<template>
  <div class="page">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <div class="title">设备管理</div>
          <div class="actions">
            <el-input v-model="query.keyword" placeholder="搜索设备名/ID" clearable style="width:220px"
                      @keyup.enter="loadList(1)" @clear="loadList(1)" />
            <el-select v-model="query.platform" placeholder="平台" clearable style="width:110px" @change="loadList(1)">
              <el-option label="Android" value="android" />
              <el-option label="iOS" value="ios" />
              <el-option label="未知" value="unknown" />
            </el-select>
            <el-select v-model="query.status" placeholder="状态" clearable style="width:110px" @change="loadList(1)">
              <el-option label="已启用" :value="1" />
              <el-option label="已禁用" :value="2" />
            </el-select>
          </div>
        </div>
      </template>
      <el-table :data="list" stripe v-loading="loading">
        <el-table-column prop="id" label="ID" width="70" align="center" />
        <el-table-column label="设备" min-width="220">
          <template #default="{ row }">
            <div class="row">
              <el-tag size="small" class="tag"
                :type="row.platform === 'android' ? 'success' : row.platform === 'ios' ? 'primary' : 'info'">
                {{ row.platform === 'ios' ? 'iOS' : row.platform === 'android' ? 'Android' : '未知' }}
              </el-tag>
              <span class="name">{{ row.device_name || '未命名设备' }}</span>
            </div>
            <div class="sub">ID: {{ row.device_id }}</div>
            <div class="sub2" v-if="row.device_model || row.os_version">
              {{ [row.device_model, row.os_version].filter(Boolean).join(' · ') }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="在线" width="90" align="center">
          <template #default="{ row }">
            <el-dot :type="row.online === 1 ? 'success' : 'info'" />
            <span class="ml">{{ row.online === 1 ? '在线' : '离线' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-switch v-model="row.status" :active-value="1" :inactive-value="2" @change="toggleStatus(row as Device)" />
          </template>
        </el-table-column>
        <el-table-column label="订阅 Key" width="160" align="center">
          <template #default="{ row }">
            <span v-if="!row.push_key_name" class="zero">-</span>
            <el-tag v-else size="small" effect="plain">{{ row.push_key_name }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="last_connect_at" label="最后在线" width="170" align="center">
          <template #default="{ row }">{{ row.last_connect_at || '-' }}</template>
        </el-table-column>
        <el-table-column prop="created_at" label="添加时间" width="170" align="center" />
        <el-table-column label="操作" width="160" align="center" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="showDetail(row as Device)">详情</el-button>
            <el-popconfirm title="确定删除该设备？" @confirm="del(row as Device)">
              <template #reference><el-button link type="danger">删除</el-button></template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.pageSize"
          :page-sizes="[10,20,50,100]" layout="total, sizes, prev, pager, next, jumper"
          :total="total" @current-change="loadList" @size-change="loadList(1)" />
      </div>
    </el-card>

    <el-drawer v-model="detailVisible" title="设备详情" size="min(480px, 92vw)">
      <template v-if="current">
        <el-descriptions :column="1" border>
          <el-descriptions-item label="设备 ID">{{ current.device_id }}</el-descriptions-item>
          <el-descriptions-item label="名称">{{ current.device_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="平台">
            <el-tag>{{ current.platform === 'ios' ? 'iOS' : current.platform === 'android' ? 'Android' : '未知' }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="型号">{{ current.device_model || '-' }}</el-descriptions-item>
          <el-descriptions-item label="系统版本">{{ current.os_version || '-' }}</el-descriptions-item>
          <el-descriptions-item label="在线状态">
            <el-tag :type="current.online === 1 ? 'success' : 'info'">
              {{ current.online === 1 ? '在线' : '离线' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="启用状态">
            <el-tag :type="current.status === 1 ? 'success' : 'danger'">
              {{ current.status !== 2 ? '已启用' : '已禁用' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="最后在线">{{ current.last_connect_at || '-' }}</el-descriptions-item>
          <el-descriptions-item label="添加时间">{{ current.created_at }}</el-descriptions-item>
        </el-descriptions>
      </template>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  getDeviceListApi, getDeviceDetailApi, updateDeviceStatusApi, deleteDeviceApi
} from '@/api/device'
import type { Device } from '@/api/types'

// 补一个 element-plus 未提供的组件，避免未使用变量告警
const ElDot = {
  props: { type: { type: String, default: 'primary' } },
  template: `<span :class="['el-badge__dot','mt-1','is-dot', {[type]: type}]" style="position:static;display:inline-block;width:8px;height:8px;vertical-align:middle"></span>`
}

const loading = ref(false)
const list = ref<Device[]>([])
const total = ref(0)
const query = reactive({ page: 1, pageSize: 10, keyword: '', platform: '', status: '' as '' | 1 | 2 })

const detailVisible = ref(false)
const current = ref<Device | null>(null)

async function loadList(page = query.page) {
  query.page = page
  loading.value = true
  try {
    const r = await getDeviceListApi({
      page: query.page, pageSize: query.pageSize, per_page: query.pageSize,
      keyword: query.keyword, platform: query.platform || undefined,
      status: query.status === '' ? undefined : Number(query.status)
    })
    list.value = r.data?.list || []
    total.value = r.data?.total || 0
  } finally { loading.value = false }
}
async function toggleStatus(row: Device) {
  try {
    await updateDeviceStatusApi(row.id, row.status)
    ElMessage.success(row.status === 1 ? '已启用' : '已禁用')
  } catch (e: any) {
    ElMessage.error(e?.message || '操作失败')
    row.status = row.status === 1 ? 2 : 1
  }
}
async function del(row: Device) {
  try { await deleteDeviceApi(row.id); ElMessage.success('已删除'); loadList(query.page) } catch {}
}
async function showDetail(row: Device) {
  detailVisible.value = true
  try { const r = await getDeviceDetailApi(row.id); current.value = r.data } catch { current.value = row }
}
onMounted(() => loadList(1))
</script>

<style lang="scss" scoped>
.card-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: $space-3;
  .title { font-weight: 600; font-size: $font-size-lg; }
  .actions { display: flex; gap: $space-2; flex-wrap: wrap; } }
.row { display: flex; align-items: center; gap: $space-2; }
.tag { }
.name { font-weight: 500; color: var(--text-primary); }
.sub { margin-top: 4px; font-size: $font-size-xs; color: var(--text-secondary);
       font-family: ui-monospace, Menlo, monospace; }
.sub2 { margin-top: 2px; font-size: $font-size-xs; color: var(--text-secondary); }
.ml { margin-left: 6px; font-size: $font-size-xs; vertical-align: middle; }
.zero { color: var(--text-secondary); }
.more { color: var(--text-secondary); font-size: $font-size-xs; margin-left: 4px; }
.pagination { margin-top: $space-4; display: flex; justify-content: flex-end; }
</style>
