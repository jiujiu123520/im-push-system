<template>
  <el-dialog
    v-for="n in dialogNotices"
    :key="'dialog-' + n.id"
    v-model="visibleMap[n.id]"
    width="min(560px, 92vw)"
    :close-on-click-modal="false"
    append-to-body
    class="notice-dialog"
    @close="markRead(n)"
  >
    <template #header>
      <span>
        <span :style="{ color: levelColor(n.level), marginRight: '6px' }">[{{ n.level === 3 ? '紧急' : n.level === 2 ? '重要' : '普通' }}]</span>
        {{ n.title }}
      </span>
    </template>
    <div class="notice-body" v-html="n.content || '无内容'"></div>
    <template #footer>
      <el-button type="primary" @click="markRead(n)">我已阅读</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { getNoticeDialogsApi, markNoticeReadApi } from '@/api/notice'
import { useUserStore } from '@/stores/user'
import type { Notice } from '@/api/types'

const userStore = useUserStore()
const dialogNotices = ref<Notice[]>([])
const visibleMap = reactive<Record<number, boolean>>({})
const loadedOnce = ref(false)

function readLocal(): number[] {
  try { return JSON.parse(localStorage.getItem('user_notice_read') || '[]') } catch { return [] }
}
function saveLocal(ids: number[]) {
  localStorage.setItem('user_notice_read', JSON.stringify(ids))
}

function levelColor(v: number) {
  return v === 3 ? '#ef4444' : v === 2 ? '#f59e0b' : '#0ea5e9'
}

async function load() {
  if (!userStore.isLogin || loadedOnce.value) return
  try {
    const read = readLocal()
    const res = await getNoticeDialogsApi()
    const list = (res.data as Notice[] || []).filter((n) => !read.includes(n.id))
    dialogNotices.value = list
    list.forEach((n) => { visibleMap[n.id] = true })
  } catch {}
  loadedOnce.value = true
}

function markRead(n: Notice) {
  visibleMap[n.id] = false
  const read = readLocal()
  if (!read.includes(n.id)) {
    read.push(n.id)
    saveLocal(read)
  }
  markNoticeReadApi(n.id).catch(() => {})
}

watch(() => userStore.isLogin, (v) => {
  if (v && !loadedOnce.value) load()
})
onMounted(() => {
  if (userStore.isLogin) load()
})
</script>

<style lang="scss" scoped>
.notice-body {
  font-size: 14px; line-height: 1.75; color: var(--text-regular);
  max-height: 60vh; overflow-y: auto; padding: 4px 2px;
  :deep(img) { max-width: 100%; border-radius: 8px; margin: 8px 0; }
  :deep(h1), :deep(h2), :deep(h3), :deep(h4) { color: var(--text-primary); margin: 16px 0 8px; }
  :deep(p)  { margin: 8px 0; }
  :deep(ul), :deep(ol) { padding-left: 20px; margin: 8px 0; }
}
</style>
