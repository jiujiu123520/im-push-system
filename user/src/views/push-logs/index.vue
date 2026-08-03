<template>
  <div class="page">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <div class="title">推送记录</div>
          <div class="filters">
            <el-input v-model="query.keyword" placeholder="搜索标题/内容" clearable style="width:240px"
                      @keyup.enter="loadList(1)" @clear="loadList(1)">
              <template #prefix><el-icon><Search /></el-icon></template>
            </el-input>
            <el-select v-model="query.status" placeholder="全部状态" clearable style="width:140px" @change="loadList(1)">
              <el-option label="失败" :value="0" />
              <el-option label="成功" :value="1" />
              <el-option label="部分成功" :value="2" />
              <el-option label="离线存储" :value="4" />
            </el-select>
            <el-button type="primary" @click="loadList(1)">查询</el-button>
          </div>
        </div>
      </template>
      <el-table :data="list" stripe v-loading="loading">
        <el-table-column prop="id" label="ID" width="80" align="center" />
        <el-table-column prop="title" label="标题" min-width="200">
          <template #default="{ row }">
            <div class="title-cell">{{ row.title }}</div>
            <div class="sub">Key ID: {{ row.api_key_id || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="结果" width="180" align="center">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" effect="light">{{ statusText(row.status) }}</el-tag>
            <div class="counts">
              <span class="ok">成功 {{ row.success_count }}</span>
              <span class="sep">/</span>
              <span class="fail" :class="{ zero: row.fail_count === 0 }">失败 {{ row.fail_count }}</span>
              <span class="sep">/</span>
              <span>总计 {{ row.success_count + row.fail_count }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="target_type" label="目标" width="100" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="row.target_type === 'broadcast' ? 'warning' : row.target_type === 'key' ? 'primary' : 'info'">
              {{ row.target_type === 'device' ? '设备' : row.target_type === 'key' ? 'Key' : '广播' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="180" align="center" />
        <el-table-column label="操作" width="160" align="center" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="showDetail(row as PushLog)">详情</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.pageSize"
          :page-sizes="[10,20,50,100]" layout="total, sizes, prev, pager, next, jumper"
          :total="total" @current-change="loadList" @size-change="loadList(1)" />
      </div>
    </el-card>

    <!-- 详情抽屉 -->
    <el-drawer v-model="detailVisible" title="推送详情" size="min(640px, 92vw)">
      <template v-if="current">
        <el-descriptions :column="1" border size="default">
          <el-descriptions-item label="记录 ID">{{ current.id }}</el-descriptions-item>
          <el-descriptions-item label="标题">{{ current.title }}</el-descriptions-item>
          <el-descriptions-item label="内容">{{ current.content }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag :type="statusType(current.status)">{{ statusText(current.status) }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="统计">
            成功 {{ current.success_count }} / 失败 {{ current.fail_count }} / 总计 {{ current.success_count + current.fail_count }}
            &nbsp;&nbsp;耗时：{{ current.elapsed_ms }}ms
          </el-descriptions-item>
          <el-descriptions-item label="目标">{{ current.target_type === 'device' ? '指定设备' : current.target_type === 'key' ? '指定Key' : '广播' }}</el-descriptions-item>
          <el-descriptions-item label="创建时间">{{ current.created_at }}</el-descriptions-item>
        </el-descriptions>
        <div class="section-title mt">推送明细</div>
        <el-empty v-if="!current.push_detail?.length && !current.fail_detail?.length" description="暂无明细" />
        <el-table v-else :data="(current.push_detail || []).concat(current.fail_detail || []).slice(0,200)" stripe size="small" max-height="360">
          <el-table-column prop="device_id" label="设备ID" min-width="180" />
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag size="small" :type="row.status === 'success' || row.status === 1 ? 'success' : 'danger'">
                {{ row.status === 'success' || row.status === 1 ? '成功' : '失败' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="原因" min-width="160">
            <template #default="{ row }">
              <span class="fail-reason">{{ row.reason || row.error || '-' }}</span>
            </template>
          </el-table-column>
        </el-table>
      </template>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Search } from '@element-plus/icons-vue'
import { getPushLogDetailApi, getPushLogListApi } from '@/api/pushLog'
import type { PushLog, PushLogDetail } from '@/api/types'

const loading = ref(false)
const list = ref<PushLog[]>([])
const total = ref(0)
const query = reactive({ page: 1, pageSize: 10, keyword: '', status: '' as '' | number })

const detailVisible = ref(false)
const current = ref<PushLogDetail | null>(null)

function statusText(s: number) {
  return ({ 0:'失败', 1:'成功', 2:'部分成功', 4:'离线存储' } as any)[s] || '未知'
}
function statusType(s: number) {
  return ({ 1:'success', 2:'warning', 0:'danger', 4:'info' } as any)[s] || 'info'
}
async function loadList(page = query.page) {
  query.page = page
  loading.value = true
  try {
    const r = await getPushLogListApi({
      page: query.page, pageSize: query.pageSize, per_page: query.pageSize,
      keyword: query.keyword, status: query.status === '' ? undefined : query.status
    })
    list.value = r.data?.list || []
    total.value = r.data?.total || 0
  } finally { loading.value = false }
}
async function showDetail(row: PushLog) {
  detailVisible.value = true
  try {
    const r = await getPushLogDetailApi(row.id)
    current.value = r.data
  } catch {
    current.value = row as any
  }
}
onMounted(() => loadList(1))
</script>

<style lang="scss" scoped>
.page { }
.card-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: $space-3;
  .title { font-weight: 600; font-size: $font-size-lg; }
  .filters { display: flex; gap: $space-2; flex-wrap: wrap; } }
.title-cell { font-weight: 500; color: var(--text-primary); }
.sub { margin-top: 2px; font-size: $font-size-xs; color: var(--text-secondary); }
.counts { margin-top: 6px; font-size: $font-size-xs; color: var(--text-secondary);
  .ok { color: var(--color-success); }
  .fail { color: var(--color-danger); &.zero { color: var(--text-secondary); } }
  .sep { margin: 0 4px; color: var(--border-dark); } }
.pagination { margin-top: $space-4; display: flex; justify-content: flex-end; }
.mt { margin-top: $space-5; }
.section-title { font-size: $font-size-md; font-weight: 600; margin: 0 0 $space-3; }
.fail-reason { font-size: $font-size-xs; color: var(--text-secondary); }
</style>
