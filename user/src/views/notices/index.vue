<template>
  <div class="page">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <div class="title">
            系统公告
            <el-badge v-if="unread" :value="unread" class="ml" />
          </div>
          <div class="actions">
            <el-button :disabled="!unread" @click="markAllRead">全部标为已读</el-button>
          </div>
        </div>
      </template>
      <el-tabs v-model="tab">
        <el-tab-pane label="全部" name="all" />
        <el-tab-pane label="置顶" name="sticky" />
        <el-tab-pane label="紧急" name="urgent" />
      </el-tabs>
      <div v-for="n in filteredList" :key="n.id" class="notice-item"
           :class="{ unread: !isRead(n.id), sticky: n.is_sticky }">
        <div class="head">
          <div class="left">
            <el-tag v-if="n.is_sticky" type="danger" effect="dark" size="small">置顶</el-tag>
            <el-tag v-if="n.level === 3" type="danger" effect="light" size="small">紧急</el-tag>
            <el-tag v-else-if="n.level === 2" type="warning" effect="light" size="small">重要</el-tag>
            <el-tag v-else type="info" effect="plain" size="small">普通</el-tag>
            <div class="type-tags">
              <el-tag v-if="n.type === 2" type="warning" size="small">紧急公告</el-tag>
              <el-tag v-else-if="n.type === 3" type="info" size="small">维护公告</el-tag>
              <el-tag v-else-if="n.type === 4" type="success" size="small">新功能</el-tag>
            </div>
            <div class="ntitle" @click="openDetail(n)">{{ n.title }}</div>
          </div>
          <div class="right">
            <div class="time">{{ n.publish_at || n.created_at }}</div>
            <span v-if="!isRead(n.id)" class="dot" />
          </div>
        </div>
        <div v-if="n.summary || n.content" class="content-preview" v-html="stripHtml(n.summary || n.content || '').slice(0,120) + (stripHtml(n.summary || n.content || '').length > 120 ? '…' : '')"></div>
        <div class="foot">
          <el-button type="primary" link @click="openDetail(n)">查看详情</el-button>
          <el-button link @click.stop="markOneRead(n)">标为已读</el-button>
        </div>
      </div>
      <el-empty v-if="!loading && !filteredList.length" description="暂无公告" />
      <div class="pagination" v-if="total > q.pageSize">
        <el-pagination v-model:current-page="q.page" v-model:page-size="q.pageSize"
          :page-sizes="[10,20,50]" layout="total, prev, pager, next, jumper"
          :total="total" @current-change="loadList" @size-change="loadList(1)" />
      </div>
    </el-card>

    <el-drawer v-model="detailVisible" :title="current?.title || '公告详情'" size="min(640px, 92vw)">
      <template v-if="current">
        <div class="detail-meta">
          <el-tag v-if="current.level === 3" type="danger">紧急</el-tag>
          <el-tag v-else-if="current.level === 2" type="warning">重要</el-tag>
          <el-tag v-else type="info">普通</el-tag>
          <span class="meta-time">发布于 {{ current.publish_at || current.created_at }}</span>
        </div>
        <div class="detail-content" v-loading="detailLoading" v-html="current.content || current.summary || '（无详细内容）'"></div>
      </template>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getNoticeListApi, getNoticeDetailApi, markNoticeReadApi, markAllNoticeReadApi } from '@/api/notice'
import type { Notice } from '@/api/types'

const list = ref<Notice[]>([])
const total = ref(0)
const loading = ref(false)
const q = reactive({ page: 1, pageSize: 10 })
const tab = ref('all')
const detailVisible = ref(false)
const current = ref<Notice | null>(null)
const detailLoading = ref(false)
const readSet = reactive<Set<number>>(new Set(JSON.parse(localStorage.getItem('user_notice_read') || '[]')))

const unread = computed(() => list.value.filter((n) => !readSet.has(n.id)).length)
const filteredList = computed(() => {
  let l = list.value
  if (tab.value === 'sticky') l = l.filter((n) => n.is_sticky)
  else if (tab.value === 'urgent') l = l.filter((n) => n.level === 3 || n.type === 2)
  return l
})
function isRead(id: number) { return readSet.has(id) }
function stripHtml(s: string) {
  return (s || '').replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').trim()
}

async function loadList(page = q.page) {
  q.page = page
  loading.value = true
  try {
    const r = await getNoticeListApi({ page: q.page, pageSize: q.pageSize, per_page: q.pageSize })
    list.value = r.data?.list || []
    total.value = r.data?.total || 0
  } finally { loading.value = false }
}
function persist() {
  localStorage.setItem('user_notice_read', JSON.stringify([...readSet]))
}
async function openDetail(n: Notice) {
  current.value = n
  detailVisible.value = true
  markOneRead(n)
  // 列表只有 summary（截断），调用详情接口拿完整 content
  try {
    detailLoading.value = true
    const r = await getNoticeDetailApi(n.id)
    if (r.data) {
      current.value = { ...n, ...r.data }
    }
  } catch {
    // 详情接口失败时保留列表 summary
  } finally {
    detailLoading.value = false
  }
}
async function markOneRead(n: Notice) {
  if (readSet.has(n.id)) return
  readSet.add(n.id); persist()
  try { await markNoticeReadApi(n.id) } catch {}
}
async function markAllRead() {
  list.value.forEach((n) => readSet.add(n.id)); persist()
  try { await markAllNoticeReadApi(); ElMessage.success('已全部标记为已读') } catch {}
}
onMounted(() => loadList(1))
</script>

<style lang="scss" scoped>
.card-header { display: flex; justify-content: space-between; align-items: center;
  .title { font-weight: 600; font-size: $font-size-lg; display: flex; align-items: center; }
  .actions { display: flex; gap: $space-2; }
  .ml { margin-left: 8px; } }
.notice-item {
  position: relative; padding: $space-4; margin-bottom: $space-3;
  background: #fff; border: 1px solid var(--border-light);
  border-radius: $radius-md; transition: all 0.2s;
  &:hover { box-shadow: $shadow-sm; }
  &.sticky { border-left: 4px solid #ef4444; background: #fff7f7; }
  &.unread .ntitle { font-weight: 600; }
}
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: $space-3; }
.left { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 8px; flex: 1; min-width: 0; }
.ntitle {
  width: 100%; margin: 4px 0 0;
  font-size: $font-size-md; color: var(--text-primary); cursor: pointer;
  &:hover { color: var(--color-primary); }
}
.type-tags { display: inline-flex; gap: 4px; }
.right { display: flex; align-items: center; gap: $space-2; flex-shrink: 0; }
.time { font-size: $font-size-xs; color: var(--text-secondary); }
.dot { width: 8px; height: 8px; border-radius: 50%; background: #ef4444; flex-shrink: 0; }
.content-preview {
  margin-top: $space-3; padding: $space-3;
  background: #f8fafc; border-radius: $radius-sm;
  color: var(--text-regular); font-size: $font-size-sm; line-height: 1.6;
}
.foot { margin-top: $space-3; display: flex; gap: $space-4; }
.pagination { margin-top: $space-4; display: flex; justify-content: flex-end; }
.detail-meta { display: flex; align-items: center; gap: $space-3; padding-bottom: $space-3; border-bottom: 1px solid var(--border-light);
  .meta-time { color: var(--text-secondary); font-size: $font-size-sm; } }
.detail-content {
  padding: $space-4 0; font-size: $font-size-base; line-height: 1.8; color: var(--text-regular);
  :deep(img) { max-width: 100%; border-radius: 8px; margin: 8px 0; }
  :deep(h1),:deep(h2),:deep(h3) { color: var(--text-primary); margin: 16px 0 8px; }
  :deep(p) { margin: 8px 0; }
}
</style>
