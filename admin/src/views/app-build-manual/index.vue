<template>
  <div class="manual-build-page">
    <!-- 顶部 Hero 区 -->
    <div class="hero-section">
      <div class="hero-content">
        <div class="hero-text">
          <h1 class="hero-title">
            <el-icon class="hero-icon"><CpuIcon /></el-icon>
            GitHub Actions 手动构建
          </h1>
          <p class="hero-desc">
            直接调用 GitHub API 触发 workflow_dispatch 构建 APK,不经过 Redis 队列。
            适用于测试 GitHub Actions 配置是否正常、紧急构建等场景。
          </p>
        </div>
        <div class="hero-actions">
          <el-button type="primary" :icon="RefreshIcon" @click="fetchRuns">刷新运行列表</el-button>
          <el-button :icon="LinkIcon" @click="openActions" :disabled="!configStatus.actions_url">GitHub Actions 页面</el-button>
        </div>
      </div>
    </div>

    <!-- 配置状态提示 -->
    <el-alert
      :title="configAlertTitle"
      :type="configStatus.available ? 'success' : 'warning'"
      :closable="false"
      show-icon
      class="config-alert"
    >
      <template v-if="!configStatus.available" #default>
        <div class="alert-content">
          <p>GitHub Actions 配置不完整,无法手动触发构建。</p>
          <p>
            请前往
            <el-link type="primary" @click="$router.push('/app-build')">APP 生成</el-link>
            页面展开「GitHub Actions 构建配置说明」面板查看配置步骤。
          </p>
        </div>
      </template>
      <template v-else #default>
        <div class="alert-content">
          <span>仓库: <el-link type="primary" :href="configStatus.repo_url" target="_blank">{{ configStatus.owner }}/{{ configStatus.repo }}</el-link></span>
          <el-divider direction="vertical" />
          <span>Workflow: <code>{{ configStatus.workflow_file }}</code></span>
          <el-divider direction="vertical" />
          <span>代理: <code>{{ configStatus.api_proxy || '直连' }}</code></span>
        </div>
      </template>
    </el-alert>

    <!-- 主体:左侧触发表单 + 右侧运行历史 -->
    <div class="main-grid">
      <!-- 左侧:触发表单 -->
      <div class="form-section">
        <el-card shadow="hover" class="form-card">
          <template #header>
            <div class="card-header">
              <el-icon><PromotionIcon /></el-icon>
              <span>触发构建</span>
            </div>
          </template>

          <el-form
            ref="formRef"
            :model="form"
            :rules="rules"
            label-position="top"
            class="trigger-form"
          >
            <el-form-item label="应用名称" prop="app_name">
              <el-input v-model="form.app_name" placeholder="如:推送大师">
                <template #append>
                  <el-button :icon="MagicStickIcon" @click="randomizeParams">随机</el-button>
                </template>
              </el-input>
            </el-form-item>

            <el-form-item label="包名(可选)" prop="package_name">
              <el-input v-model="form.package_name" placeholder="如:com.example.app(留空使用默认 com.push.app)" />
            </el-form-item>

            <el-form-item label="默认 Key" prop="default_key">
              <el-input v-model="form.default_key" placeholder="推送默认 Key">
                <template #append>
                  <el-button :icon="RefreshRightIcon" @click="generateKey">生成</el-button>
                </template>
              </el-input>
            </el-form-item>

            <el-form-item label="HTTP 服务器地址" prop="server_url">
              <el-input v-model="form.server_url" placeholder="如:http://124.220.64.209:7070" />
            </el-form-item>

            <el-form-item label="WebSocket 地址" prop="ws_url">
              <el-input v-model="form.ws_url" placeholder="如:ws://124.220.64.209:9393" />
            </el-form-item>

            <el-form-item>
              <el-button
                type="primary"
                :icon="PromotionIcon"
                :loading="triggering"
                :disabled="!configStatus.available"
                @click="handleTrigger"
                class="trigger-btn"
              >
                {{ triggering ? '正在触发...' : '触发 GitHub Actions 构建' }}
              </el-button>
            </el-form-item>

            <div class="form-tip">
              <el-icon><InfoFilledIcon /></el-icon>
              <span>手动触发不会在构建历史中显示(不经过 Redis 队列),请在右侧运行列表或 GitHub Actions 页面查看进度。</span>
            </div>
          </el-form>
        </el-card>
      </div>

      <!-- 右侧:运行历史 -->
      <div class="runs-section">
        <el-card shadow="hover" class="runs-card">
          <template #header>
            <div class="card-header">
              <el-icon><ClockIcon /></el-icon>
              <span>最近运行</span>
              <el-tag size="small" type="info" class="runs-count">共 {{ runsTotal }} 条</el-tag>
              <el-button
                text
                :icon="RefreshIcon"
                @click="fetchRuns"
                class="refresh-btn"
              >刷新</el-button>
            </div>
          </template>

          <div v-loading="loadingRuns" class="runs-list">
            <el-empty v-if="runs.length === 0 && !loadingRuns" description="暂无运行记录" />

            <div v-for="run in runs" :key="run.id" class="run-item">
              <div class="run-status-icon">
                <el-icon :class="statusIconClass(run.status, run.conclusion)">
                  <component :is="statusIcon(run.status, run.conclusion)" />
                </el-icon>
              </div>
              <div class="run-info">
                <div class="run-header">
                  <span class="run-name">{{ run.display_title || run.name || '未命名' }}</span>
                  <el-tag :type="statusTagType(run.status, run.conclusion)" size="small" round>
                    {{ statusText(run.status, run.conclusion) }}
                  </el-tag>
                </div>
                <div class="run-meta">
                  <span v-if="run.build_id" class="meta-item">
                    <el-icon><KeyIconComp /></el-icon>
                    <code>{{ run.build_id }}</code>
                  </span>
                  <span class="meta-item">
                    <el-icon><UserIcon /></el-icon>
                    {{ run.actor }}
                  </span>
                  <span class="meta-item">
                    <el-icon><ClockIcon /></el-icon>
                    {{ formatTime(run.created_at) }}
                  </span>
                  <span v-if="run.event" class="meta-item">
                    <el-icon><ConnectionIcon /></el-icon>
                    {{ run.event }}
                  </span>
                </div>
              </div>
              <el-link
                type="primary"
                :href="run.html_url"
                target="_blank"
                class="run-link"
              >
                查看 →
              </el-link>
            </div>
          </div>
        </el-card>
      </div>
    </div>

    <!-- 触发结果对话框 -->
    <el-dialog v-model="resultDialogVisible" title="触发结果" width="500px">
      <div v-if="triggerResult" class="result-content">
        <el-result
          :icon="triggerResult.dispatched ? 'success' : 'error'"
          :title="triggerResult.dispatched ? '触发成功' : '触发失败'"
          :sub-title="triggerResult.message"
        />
        <div v-if="triggerResult.dispatched" class="result-info">
          <el-descriptions :column="1" border>
            <el-descriptions-item label="构建 ID">
              <code>{{ triggerResult.build_id }}</code>
            </el-descriptions-item>
            <el-descriptions-item label="查看进度">
              <el-link type="primary" :href="triggerResult.actions_url" target="_blank">
                {{ triggerResult.actions_url }}
              </el-link>
            </el-descriptions-item>
          </el-descriptions>
        </div>
      </div>
      <template #footer>
        <el-button @click="resultDialogVisible = false">关闭</el-button>
        <el-button v-if="triggerResult?.dispatched" type="primary" @click="handleViewRuns">
          查看运行列表
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import {
  Cpu as CpuIcon,
  Promotion as PromotionIcon,
  Refresh as RefreshIcon,
  RefreshRight as RefreshRightIcon,
  Link as LinkIcon,
  Clock as ClockIcon,
  MagicStick as MagicStickIcon,
  InfoFilled as InfoFilledIcon,
  Key as KeyIconComp,
  Connection as ConnectionIcon,
  User as UserIcon,
  Check as CheckIcon,
  Close as CloseIcon,
  Loading as LoadingIcon
} from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  getAppBuildConfigStatusApi,
  manualTriggerBuildApi,
  getWorkflowRunsApi
} from '@/api/appBuild'

// ---- 配置状态 ----
const configStatus = reactive<{
  available: boolean
  owner: string
  repo: string
  workflow_file: string
  api_proxy: string
  repo_url: string
  actions_url: string
  secrets_url: string
}>({
  available: false,
  owner: '',
  repo: '',
  workflow_file: 'build-apk.yml',
  api_proxy: '',
  repo_url: '',
  actions_url: '',
  secrets_url: ''
})

const configAlertTitle = computed(() => {
  return configStatus.available
    ? '✅ GitHub Actions 配置已就绪'
    : '⚠️ GitHub Actions 配置不完整'
})

async function fetchConfigStatus() {
  try {
    const res = await getAppBuildConfigStatusApi()
    Object.assign(configStatus, res)
  } catch (e) {
    console.warn('获取配置状态失败', e)
  }
}

// ---- 触发表单 ----
const formRef = ref<FormInstance>()
const triggering = ref(false)

const form = reactive({
  app_name: '',
  package_name: '',
  default_key: 'default_key',
  server_url: 'http://124.220.64.209:7070',
  ws_url: 'ws://124.220.64.209:9393'
})

const rules: FormRules = {
  app_name: [{ required: true, message: '请输入应用名称', trigger: 'blur' }],
  server_url: [{ required: true, message: '请输入服务器地址', trigger: 'blur' }],
  ws_url: [{ required: true, message: '请输入 WebSocket 地址', trigger: 'blur' }]
}

function randomizeParams() {
  const adjectives = ['快速', '智能', '安全', '极光', '闪电', '星辰', '云上', '灵动']
  const nouns = ['推送', '消息', '通知', '通讯', '盒子', '助手', '管家', '中心']
  const adj = adjectives[Math.floor(Math.random() * adjectives.length)]
  const noun = nouns[Math.floor(Math.random() * nouns.length)]
  form.app_name = adj + noun

  // 随机包名
  const prefixes = ['com', 'cn', 'org', 'net', 'io']
  const names = ['quick', 'bell', 'msg', 'fast', 'sky', 'cloud', 'star', 'link']
  const suffixes = ['app', 'client', 'push', 'box', 'im', 'chat', 'msg']
  const p1 = prefixes[Math.floor(Math.random() * prefixes.length)]
  const p2 = names[Math.floor(Math.random() * names.length)]
  const p3 = suffixes[Math.floor(Math.random() * suffixes.length)]
  form.package_name = `${p1}.${p2}.${p3}`
}

function generateKey() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
  let key = ''
  for (let i = 0; i < 32; i++) {
    key += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  form.default_key = key
  ElMessage.success('已生成随机 Key')
}

// 触发结果对话框
const resultDialogVisible = ref(false)
const triggerResult = ref<{
  build_id: string
  dispatched: boolean
  message: string
  actions_url: string
  query_url: string
} | null>(null)

async function handleTrigger() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    triggering.value = true
    try {
      const res = await manualTriggerBuildApi({
        app_name: form.app_name,
        package_name: form.package_name,
        default_key: form.default_key,
        server_url: form.server_url,
        ws_url: form.ws_url
      })
      triggerResult.value = res
      resultDialogVisible.value = true
      // 触发后延迟刷新运行列表(给 GitHub API 一些时间创建 run)
      setTimeout(() => fetchRuns(), 2000)
    } catch (e: any) {
      ElMessage.error(e.message || '触发失败')
    } finally {
      triggering.value = false
    }
  })
}

function handleViewRuns() {
  resultDialogVisible.value = false
  fetchRuns()
}

// ---- 运行列表 ----
const runs = ref<any[]>([])
const runsTotal = ref(0)
const loadingRuns = ref(false)
let refreshTimer: ReturnType<typeof setInterval> | null = null

async function fetchRuns() {
  loadingRuns.value = true
  try {
    const res = await getWorkflowRunsApi({ per_page: 20, page: 1 })
    runs.value = res.runs || []
    runsTotal.value = res.total || 0
  } catch (e: any) {
    ElMessage.error(e.message || '获取运行列表失败')
  } finally {
    loadingRuns.value = false
  }
}

// ---- 状态辅助函数 ----
function statusIcon(status: string, conclusion: string) {
  if (status === 'completed') {
    if (conclusion === 'success') return CheckIcon
    if (conclusion === 'failure' || conclusion === 'cancelled') return CloseIcon
  }
  if (status === 'in_progress' || status === 'queued') return LoadingIcon
  return ClockIcon
}

function statusIconClass(status: string, conclusion: string) {
  if (status === 'completed') {
    if (conclusion === 'success') return 'status-success'
    if (conclusion === 'failure' || conclusion === 'cancelled') return 'status-failed'
  }
  if (status === 'in_progress' || status === 'queued') return 'status-running'
  return 'status-pending'
}

function statusText(status: string, conclusion: string): string {
  if (status === 'completed') {
    if (conclusion === 'success') return '成功'
    if (conclusion === 'failure') return '失败'
    if (conclusion === 'cancelled') return '已取消'
    return conclusion || '完成'
  }
  if (status === 'in_progress') return '运行中'
  if (status === 'queued') return '排队中'
  return status
}

function statusTagType(status: string, conclusion: string): 'success' | 'danger' | 'warning' | 'info' {
  if (status === 'completed') {
    if (conclusion === 'success') return 'success'
    if (conclusion === 'failure') return 'danger'
    if (conclusion === 'cancelled') return 'info'
    return 'warning'
  }
  if (status === 'in_progress') return 'warning'
  if (status === 'queued') return 'info'
  return 'info'
}

function formatTime(iso: string): string {
  if (!iso) return ''
  try {
    const d = new Date(iso)
    return d.toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai' })
  } catch {
    return iso
  }
}

function openActions() {
  if (configStatus.actions_url) {
    window.open(configStatus.actions_url, '_blank')
  }
}

// ---- 生命周期 ----
onMounted(() => {
  fetchConfigStatus()
  fetchRuns()
  // 每 15 秒自动刷新运行列表(监控进行中的任务)
  refreshTimer = setInterval(() => {
    // 只在有进行中/排队中的任务时自动刷新
    const hasActive = runs.value.some(r => r.status === 'in_progress' || r.status === 'queued')
    if (hasActive) fetchRuns()
  }, 15000)
})

onUnmounted(() => {
  if (refreshTimer) clearInterval(refreshTimer)
})
</script>

<style lang="scss" scoped>
.manual-build-page {
  padding: 20px;
  animation: fade-up 0.5s ease;
}

@keyframes fade-up {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

// ===== Hero 区 =====
.hero-section {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 16px;
  padding: 32px;
  margin-bottom: 20px;
  color: #fff;
  box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);

  .hero-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
  }

  .hero-text {
    flex: 1;
    min-width: 300px;
  }

  .hero-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 8px 0;

    .hero-icon {
      font-size: 28px;
    }
  }

  .hero-desc {
    font-size: 14px;
    opacity: 0.9;
    line-height: 1.6;
    margin: 0;
  }

  .hero-actions {
    display: flex;
    gap: 10px;

    :deep(.el-button) {
      background: rgba(255, 255, 255, 0.2);
      border-color: rgba(255, 255, 255, 0.3);
      color: #fff;

      &:hover {
        background: rgba(255, 255, 255, 0.3);
        border-color: rgba(255, 255, 255, 0.5);
      }
    }
  }
}

// ===== 配置提示 =====
.config-alert {
  margin-bottom: 20px;

  .alert-content {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 4px;

    p {
      margin: 2px 0;
    }

    code {
      font-family: 'SF Mono', Monaco, Consolas, monospace;
      padding: 2px 6px;
      background: var(--el-fill-color-dark, #f0f0f0);
      border-radius: 3px;
      font-size: 12px;
    }
  }
}

// ===== 主体网格 =====
.main-grid {
  display: grid;
  grid-template-columns: 1fr 1.2fr;
  gap: 20px;

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
}

// ===== 卡片通用 =====
.form-card,
.runs-card {
  border-radius: 12px;
  border: none;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);

  :deep(.el-card__header) {
    padding: 16px 20px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  :deep(.el-card__body) {
    padding: 20px;
  }
}

.card-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 16px;
  font-weight: 600;

  .el-icon {
    color: var(--el-color-primary);
  }

  .runs-count {
    margin-left: auto;
  }

  .refresh-btn {
    margin-left: 8px;
  }
}

// ===== 触发表单 =====
.trigger-form {
  .trigger-btn {
    width: 100%;
    height: 44px;
    font-size: 15px;
    font-weight: 600;
  }
}

.form-tip {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  padding: 10px 12px;
  background: var(--el-fill-color-lighter, #fafafa);
  border-radius: 6px;
  border-left: 3px solid var(--el-color-info, #909399);
  font-size: 12px;
  color: var(--el-text-color-secondary);
  line-height: 1.6;

  .el-icon {
    color: var(--el-color-info);
    flex-shrink: 0;
    margin-top: 2px;
  }
}

// ===== 运行列表 =====
.runs-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-height: 600px;
  overflow-y: auto;
}

.run-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px;
  background: var(--el-fill-color-lighter, #fafafa);
  border-radius: 8px;
  transition: all 0.2s ease;

  &:hover {
    background: var(--el-fill-color, #f5f5f5);
    transform: translateX(2px);
  }
}

.run-status-icon {
  flex-shrink: 0;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: var(--el-bg-color, #fff);
  border: 1px solid var(--el-border-color, #e0e0e0);

  .el-icon {
    font-size: 16px;
  }

  .status-success {
    color: var(--el-color-success, #67c23a);
  }

  .status-failed {
    color: var(--el-color-danger, #f56c6c);
  }

  .status-running {
    color: var(--el-color-warning, #e6a23c);
    animation: spin 1.5s linear infinite;
  }

  .status-pending {
    color: var(--el-color-info, #909399);
  }
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.run-info {
  flex: 1;
  min-width: 0;

  .run-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
  }

  .run-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--el-text-color-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .run-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 12px;
    color: var(--el-text-color-secondary);

    .meta-item {
      display: flex;
      align-items: center;
      gap: 4px;

      .el-icon {
        font-size: 12px;
      }

      code {
        font-family: 'SF Mono', Monaco, Consolas, monospace;
        font-size: 11px;
        padding: 1px 4px;
        background: var(--el-fill-color-dark, #f0f0f0);
        border-radius: 3px;
        color: var(--el-color-danger);
      }
    }
  }
}

.run-link {
  flex-shrink: 0;
  font-size: 12px;
}

// ===== 结果对话框 =====
.result-content {
  .result-info {
    margin-top: 16px;
  }
}
</style>
