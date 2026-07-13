<template>
  <div class="page-container">
    <div class="page-header">
      <div class="page-title">管理员登录日志</div>
      <div class="header-actions">
        <el-button :icon="RefreshLeftIcon" @click="fetchData">刷新</el-button>
      </div>
    </div>

    <div class="search-bar">
      <el-input
        v-model="query.keyword"
        placeholder="搜索用户名或IP"
        :prefix-icon="SearchIcon"
        clearable
        style="width: 240px"
        @keyup.enter="handleSearch"
      />
      <el-button type="primary" :icon="SearchIcon" @click="handleSearch">查询</el-button>
    </div>

    <div class="table-container">
      <el-table v-loading="loading" :data="tableData" stripe style="width: 100%">
        <el-table-column type="index" label="#" width="60" align="center" />
        <el-table-column prop="username" label="用户名" width="120" />
        <el-table-column prop="ip" label="IP地址" width="140" />
        <el-table-column prop="device" label="设备类型" width="100">
          <template #default="{ row }">
            <el-tag :type="row.device === 'PC' ? 'info' : 'success'" size="small">{{ row.device }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="browser" label="浏览器" min-width="120" show-overflow-tooltip />
        <el-table-column prop="os" label="操作系统" min-width="120" show-overflow-tooltip />
        <el-table-column prop="status" label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'" size="small">
              {{ row.status === 1 ? '成功' : '失败' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="message" label="消息" min-width="120" show-overflow-tooltip />
        <el-table-column prop="created_at" label="登录时间" width="170" />
        <el-table-column label="操作" width="120" fixed="right">
          <template #default="{ row }">
            <el-button
              text
              type="danger"
              size="small"
              @click="handleBlacklist(row)"
              :disabled="!row.ip"
            >
              拉黑IP
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.pageSize"
          :total="total"
          layout="total, prev, pager, next"
          background
          @current-change="fetchData"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search as SearchIcon, RefreshLeft as RefreshLeftIcon } from '@element-plus/icons-vue'
import { getLoginLogsApi } from '@/api/admin'
import { createBlacklistApi } from '@/api/blacklist'

const loading = ref(false)
const tableData = ref<any[]>([])
const total = ref(0)
const query = reactive({
  page: 1,
  pageSize: 10,
  keyword: ''
})

async function fetchData() {
  loading.value = true
  try {
    const res = await getLoginLogsApi(query)
    tableData.value = res.data?.list || []
    total.value = res.data?.total || 0
  } catch {
    ElMessage.error('获取登录日志失败')
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchData()
}

async function handleBlacklist(row: any) {
  try {
    await ElMessageBox.confirm(
      `确定要拉黑 IP ${row.ip} 吗？拉黑后该 IP 将无法访问系统。`,
      '拉黑确认',
      {
        confirmButtonText: '确定拉黑',
        cancelButtonText: '取消',
        type: 'warning',
        appendTo: 'body'
      }
    )
    await createBlacklistApi({
      type: 'ip',
      value: row.ip,
      reason: `管理员登录日志拉黑 - ${row.username}`
    })
    ElMessage.success('已拉黑该 IP')
  } catch {
    // 取消
  }
}

onMounted(() => {
  fetchData()
})
</script>
