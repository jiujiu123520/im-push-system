<template>
  <div class="page">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <div class="title">推送记录</div>
          <div class="filters">
            <el-input v-model="query.keyword" placeholder="搜索标题/内容/目标" clearable style="width:260px"
                      @keyup.enter="reload" @clear="reload">
              <template #prefix><el-icon><Search /></el-icon></template>
            </el-input>
            <el-select v-model="query.status" placeholder="全部状态" clearable style="width:130px" @change="reload">
              <el-option label="失败" :value="0" />
              <el-option label="成功" :value="1" />
              <el-option label="部分成功" :value="2" />
              <el-option label="离线存储" :value="4" />
            </el-select>
            <el-select v-model="query.target_type" placeholder="全部目标" clearable style="width:130px" @change="reload">
              <el-option label="设备" value="device" />
              <el-option label="Key" value="key" />
              <el-option label="广播" value="broadcast" />
            </el-select>
            <el-button type="primary" @click="reload">
              <el-icon><Search /></el-icon> 搜索
            </el-button>
          </div>
        </div>
      </template>

      <!-- 无限滚动列表 -->
      <div ref="scrollBoxRef" class="scroll-box" @scroll="onScroll">
        <el-table :data="list" stripe v-loading="loading" style="width:100%">
          <el-table-column prop="id" label="ID" width="80" align="center" />
          <el-table-column prop="title" label="标题" min-width="200">
            <template #default="{ row }">
              <div class="title-cell" v-html="highlight(row.title)"></div>
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

        <!-- 加载状态提示 -->
        <div class="load-status">
          <span v-if="loading" class="loading-tip">
            <el-icon class="is-loading"><Loading /></el-icon> 加载中...
          </span>
          <span v-else-if="noMore && list.length > 0" class="no-more">— 没有更多了 —</span>
          <span v-else-if="!loading && list.length === 0" class="no-more">暂无推送记录</span>
        </div>
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
import { onMounted, reactive, ref, nextTick } from 'vue'
import { Search, Loading } from '@element-plus/icons-vue'
import { getPushLogDetailApi, getPushLogListApi } from '@/api/pushLog'
import type { PushLog, PushLogDetail } from '@/api/types'

const loading = ref(false)
const list = ref<PushLog[]>([])
const total = ref(0)
const noMore = ref(false)
const scrollBoxRef = ref<HTMLElement>()

const query = reactive({
  page: 1,
  pageSize: 20,
  keyword: '',
  status: '' as '' | number,
  target_type: '' as string
})

const detailVisible = ref(false)
const current = ref<PushLogDetail | null>(null)

function statusText(s: number) {
  return ({ 0:'失败', 1:'成功', 2:'部分成功', 4:'离线存储' } as any)[s] || '未知'
}
function statusType(s: number) {
  return ({ 1:'success', 2:'warning', 0:'danger', 4:'info' } as any)[s] || 'info'
}

// 关键词高亮
function highlight(text: string): string {
  if (!query.keyword || !text) return text || ''
  const kw = query.keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  return text.replace(new RegExp(`(${kw})`, 'gi'), '<mark class="hl">$1</mark>')
}

// 重新加载（搜索/筛选变更时调用）
async function reload() {
  query.page = 1
  noMore.value = false
  list.value = []
  await loadList()
}

// 加载数据（追加模式）
async function loadList() {
  if (loading.value || noMore.value) return
  loading.value = true
  try {
    const r = await getPushLogListApi({
      page: query.page, pageSize: query.pageSize, per_page: query.pageSize,
      keyword: query.keyword,
      status: query.status === '' ? undefined : query.status,
      target_type: query.target_type || undefined
    })
    const newList = r.data?.list || []
    total.value = r.data?.total || 0
    if (newList.length < query.pageSize) {
      noMore.value = true
    }
    if (query.page === 1) {
      list.value = newList
    } else {
      list.value = [...list.value, ...newList]
    }
  } finally {
    loading.value = false
  }
}

// 滚动事件：接近底部时加载下一页
function onScroll() {
  const el = scrollBoxRef.value
  if (!el || loading.value || noMore.value) return
  const { scrollTop, scrollHeight, clientHeight } = el
  if (scrollHeight - scrollTop - clientHeight < 100) {
    query.page += 1
    loadList()
  }
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

onMounted(async () => {
  await loadList()
  await nextTick()
  // 如果数据不足一屏，自动加载下一页
  const el = scrollBoxRef.value
  if (el && el.scrollHeight <= el.clientHeight && !noMore.value) {
    query.page += 1
    loadList()
  }
})
</script>

<style lang="scss" scoped>
.page { }
.card-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: $space-3;
  .title { font-weight: 600; font-size: $font-size-lg; }
  .filters { display: flex; gap: $space-2; flex-wrap: wrap; } }

.scroll-box {
  max-height: calc(100vh - 280px);
  overflow-y: auto;
  @include scrollbar;
}

.title-cell { font-weight: 500; color: var(--text-primary); }
.sub { margin-top: 2px; font-size: $font-size-xs; color: var(--text-secondary); }
.counts { margin-top: 6px; font-size: $font-size-xs; color: var(--text-secondary);
  .ok { color: var(--color-success); }
  .fail { color: var(--color-danger); &.zero { color: var(--text-secondary); } }
  .sep { margin: 0 4px; color: var(--border-dark); } }

.load-status {
  padding: $space-4 0;
  text-align: center;
  .loading-tip {
    display: inline-flex; align-items: center; gap: 6px;
    color: var(--text-secondary); font-size: $font-size-sm;
  }
  .no-more { color: var(--text-placeholder); font-size: $font-size-sm; }
}

// 搜索关键词高亮
:deep(mark.hl) {
  background: rgba(14, 165, 233, 0.18);
  color: var(--color-primary, #0ea5e9);
  padding: 0 2px;
  border-radius: 3px;
}

.mt { margin-top: $space-5; }
.section-title { font-size: $font-size-md; font-weight: 600; margin: 0 0 $space-3; }
.fail-reason { font-size: $font-size-xs; color: var(--text-secondary); }
</style>
