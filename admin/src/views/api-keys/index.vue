<template>
  <div class="page-container api-keys-page">
    <!-- 顶部说明卡片 -->
    <div class="intro-card">
      <div class="intro-bg">
        <div class="intro-blob blob-1"></div>
        <div class="intro-blob blob-2"></div>
        <div class="intro-grid"></div>
      </div>
      <div class="intro-content">
        <div class="intro-left">
          <div class="intro-tag">
            <el-icon><Connection /></el-icon>
            <span>OPEN API</span>
          </div>
          <h2 class="intro-title">开放 API 管理</h2>
          <p class="intro-desc">
            通过 API Key 调用推送、设备查询等开放接口，集成到您的业务系统。
            支持权限粒度控制、IP 白名单、限流策略。
          </p>
          <div class="intro-actions">
            <el-button
              type="primary"
              :icon="Plus"
              round
              @click="openCreateDialog"
            >
              创建 API Key
            </el-button>
            <el-button :icon="Document" round @click="scrollToExamples">
              查看示例
            </el-button>
            <el-button
              :icon="Link"
              round
              tag="a"
              href="https://element-plus.org"
              target="_blank"
            >
              API 文档
            </el-button>
          </div>
        </div>
        <div class="intro-stats">
          <div class="stat-pill">
            <div class="pill-value">{{ total }}</div>
            <div class="pill-label">API Key 总数</div>
          </div>
          <div class="stat-pill">
            <div class="pill-value">{{ activeCount }}</div>
            <div class="pill-label">启用中</div>
          </div>
        </div>
      </div>
    </div>

    <!-- 工具栏 -->
    <div class="toolbar-card">
      <div class="toolbar-left">
        <el-input
          v-model="query.keyword"
          placeholder="搜索名称 / AccessKey"
          :prefix-icon="Search"
          clearable
          class="search-input"
          @keyup.enter="handleSearch"
        />
        <el-select
          v-model="query.status"
          placeholder="状态筛选"
          clearable
          class="status-select"
        >
          <el-option label="启用" :value="1" />
          <el-option label="禁用" :value="0" />
        </el-select>
        <el-button type="primary" :icon="Search" @click="handleSearch">查询</el-button>
        <el-button :icon="RefreshLeft" @click="handleReset">重置</el-button>
      </div>
      <el-button type="primary" :icon="Plus" @click="openCreateDialog">
        新建 Key
      </el-button>
    </div>

    <!-- Key 列表 -->
    <div class="table-card">
      <el-table
        v-loading="loading"
        :data="tableData"
        style="width: 100%"
        row-key="id"
        stripe
      >
        <el-table-column type="index" label="#" width="60" align="center" />
        <el-table-column prop="name" label="名称" min-width="160">
          <template #default="{ row }">
            <div class="name-cell">
              <div class="name-icon">
                <el-icon><Key /></el-icon>
              </div>
              <span class="name-text">{{ row.name }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="AccessKey" min-width="240">
          <template #default="{ row }">
            <div class="key-cell">
              <code class="key-text">{{ maskKey(row.accessKey) }}</code>
              <el-button
                link
                :icon="CopyDocument"
                @click="copyKey(row.accessKey)"
              >
                复制
              </el-button>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="110" align="center">
          <template #default="{ row }">
            <span class="status-pill" :class="row.status === 1 ? 'on' : 'off'">
              <span class="dot"></span>
              {{ row.status === 1 ? '启用' : '禁用' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">
            <span class="time-text">{{ formatTime(row.createdAt) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="过期时间" width="170">
          <template #default="{ row }">
            <span class="time-text" :class="{ expired: isExpired(row.expiresAt) }">
              {{ row.expiresAt ? formatTime(row.expiresAt) : '永久有效' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button
              link
              type="primary"
              :icon="CopyDocument"
              @click="copyKey(row.accessKey)"
            >
              复制
            </el-button>
            <el-button
              link
              :type="row.status === 1 ? 'warning' : 'success'"
              :icon="row.status === 1 ? Lock : Unlock"
              @click="handleToggleStatus(row)"
            >
              {{ row.status === 1 ? '禁用' : '启用' }}
            </el-button>
            <el-button
              link
              type="danger"
              :icon="Delete"
              @click="handleDelete(row)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.pageSize"
          :page-sizes="[10, 20, 50]"
          :total="total"
          layout="total, sizes, prev, pager, next, jumper"
          background
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </div>

    <!-- 新建 API Key 对话框 -->
    <el-dialog
      v-model="dialogVisible"
      title="新建 API Key"
      width="520px"
      destroy-on-close
      class="create-dialog"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        class="create-form"
      >
        <el-form-item label="Key 名称" prop="name">
          <el-input
            v-model="form.name"
            placeholder="例如：移动端调用"
            :prefix-icon="EditPen"
            clearable
          />
        </el-form-item>
        <el-form-item label="过期时间" prop="expiresAt">
          <el-date-picker
            v-model="form.expiresAt"
            type="datetime"
            placeholder="选择过期时间，留空则永久"
            format="YYYY-MM-DD HH:mm"
            value-format="YYYY-MM-DD HH:mm:ss"
            :disabled-date="disabledDate"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input
            v-model="form.remark"
            type="textarea"
            :rows="3"
            placeholder="可选，描述该 Key 的用途"
          />
        </el-form-item>
      </el-form>

      <!-- 创建成功后展示密钥 -->
      <div v-if="createdSecret" class="secret-result">
        <div class="secret-head">
          <el-icon class="success-icon"><CircleCheckFilled /></el-icon>
          <span>创建成功，请妥善保存以下密钥信息</span>
        </div>
        <div class="secret-block">
          <div class="secret-row">
            <span class="label">AccessKey:</span>
            <code>{{ createdSecret.accessKey }}</code>
            <el-button link :icon="CopyDocument" @click="copyKey(createdSecret.accessKey)">复制</el-button>
          </div>
          <div class="secret-row">
            <span class="label">SecretKey:</span>
            <code>{{ createdSecret.secretKey }}</code>
            <el-button link :icon="CopyDocument" @click="copyKey(createdSecret.secretKey)">复制</el-button>
          </div>
          <div class="secret-warn">
            <el-icon><WarningFilled /></el-icon>
            SecretKey 仅在创建时显示一次，离开后将无法再次查看。
          </div>
        </div>
      </div>

      <template #footer>
        <el-button v-if="createdSecret" @click="dialogVisible = false">完成</el-button>
        <template v-else>
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitting" @click="handleSubmit">
            创建
          </el-button>
        </template>
      </template>
    </el-dialog>

    <!-- API 使用示例 -->
    <div ref="examplesRef" class="examples-card">
      <div class="examples-head" @click="examplesOpen = !examplesOpen">
        <div class="head-left">
          <div class="head-icon">
            <el-icon><Document /></el-icon>
          </div>
          <div>
            <h3 class="card-title">API 调用示例</h3>
            <p class="card-sub">使用 AccessKey / SecretKey 调用开放接口</p>
          </div>
        </div>
        <el-icon class="toggle-icon" :class="{ open: examplesOpen }">
          <ArrowDown />
        </el-icon>
      </div>

      <el-collapse-transition>
        <div v-show="examplesOpen" class="examples-body">
          <el-tabs v-model="activeExample" class="example-tabs">
            <el-tab-pane label="cURL" name="curl">
              <div class="code-block">
                <div class="code-header">
                  <span class="code-lang">bash</span>
                  <el-button link :icon="CopyDocument" @click="copyCode(curlExample)">复制</el-button>
                </div>
                <pre><code v-html="highlightBash(curlExample)"></code></pre>
              </div>
            </el-tab-pane>
            <el-tab-pane label="请求体" name="body">
              <div class="code-block">
                <div class="code-header">
                  <span class="code-lang">json</span>
                  <el-button link :icon="CopyDocument" @click="copyCode(jsonExample)">复制</el-button>
                </div>
                <pre><code v-html="highlightJson(jsonExample)"></code></pre>
              </div>
            </el-tab-pane>
            <el-tab-pane label="JavaScript" name="js">
              <div class="code-block">
                <div class="code-header">
                  <span class="code-lang">javascript</span>
                  <el-button link :icon="CopyDocument" @click="copyCode(jsExample)">复制</el-button>
                </div>
                <pre><code v-html="highlightJs(jsExample)"></code></pre>
              </div>
            </el-tab-pane>
          </el-tabs>

          <div class="example-tips">
            <div class="tip-item">
              <el-icon class="tip-icon"><InfoFilled /></el-icon>
              <span>请求头需携带 <code>X-Access-Key</code> 与 <code>X-Signature</code>（HMAC-SHA256 签名）</span>
            </div>
            <div class="tip-item">
              <el-icon class="tip-icon"><InfoFilled /></el-icon>
              <span>所有接口返回统一 JSON 结构：<code>{ code, message, data }</code></span>
            </div>
            <div class="tip-item">
              <el-icon class="tip-icon"><InfoFilled /></el-icon>
              <span>频率限制：默认 100 次/分钟，可在 Key 配置中调整</span>
            </div>
          </div>
        </div>
      </el-collapse-transition>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { useClipboard } from '@vueuse/core'
import {
  Connection,
  Plus,
  Document,
  Link,
  Search,
  RefreshLeft,
  Key,
  CopyDocument,
  Lock,
  Unlock,
  Delete,
  EditPen,
  CircleCheckFilled,
  WarningFilled,
  ArrowDown,
  InfoFilled
} from '@element-plus/icons-vue'
import {
  getApiKeyListApi,
  createApiKeyApi,
  deleteApiKeyApi,
  toggleApiKeyStatusApi
} from '@/api/apiKey'
import type { ApiKeyRecord } from '@/api/types'

// 剪贴板（Key 复制与代码复制共用）
const { copy } = useClipboard()

// 列表数据
const loading = ref(false)
const tableData = ref<ApiKeyRecord[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pageSize: 10,
  keyword: '',
  status: undefined as number | undefined
})

async function fetchData() {
  loading.value = true
  try {
    const res = await getApiKeyListApi({
      page: query.page,
      pageSize: query.pageSize,
      keyword: query.keyword,
      status: query.status
    })
    tableData.value = res.data.list || []
    total.value = res.data.total || 0
  } catch {
    tableData.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchData()
}

function handleReset() {
  query.keyword = ''
  query.status = undefined
  query.page = 1
  fetchData()
}

const activeCount = computed(
  () => tableData.value.filter((i) => i.status === 1).length
)

// 工具方法
function maskKey(key: string): string {
  if (!key || key.length < 12) return key
  return key.slice(0, 8) + '****' + key.slice(-4)
}

function formatTime(t: string): string {
  if (!t) return '-'
  return t.replace('T', ' ').slice(0, 19)
}

function isExpired(t?: string): boolean {
  if (!t) return false
  return new Date(t).getTime() < Date.now()
}

function disabledDate(d: Date) {
  return d.getTime() < Date.now() - 24 * 60 * 60 * 1000
}

async function copyKey(text: string) {
  if (!text) {
    ElMessage.warning('内容为空')
    return
  }
  try {
    await copy(text)
    ElMessage.success('已复制到剪贴板')
  } catch {
    ElMessage.error('复制失败，请手动复制')
  }
}

async function copyCode(text: string) {
  try {
    await copy(text)
    ElMessage.success('代码已复制')
  } catch {
    ElMessage.error('复制失败')
  }
}

// 状态切换
async function handleToggleStatus(row: ApiKeyRecord) {
  const next = row.status === 1 ? 0 : 1
  const action = next === 1 ? '启用' : '禁用'
  try {
    await ElMessageBox.confirm(`确定${action} Key「${row.name}」吗？`, '提示', {
      confirmButtonText: action,
      cancelButtonText: '取消',
      type: 'warning'
    })
    await toggleApiKeyStatusApi(row.id, next)
    ElMessage.success(`${action}成功`)
    fetchData()
  } catch {
    // 取消
  }
}

// 删除
async function handleDelete(row: ApiKeyRecord) {
  try {
    await ElMessageBox.confirm(`确定删除 Key「${row.name}」吗？此操作不可恢复`, '提示', {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning'
    })
    await deleteApiKeyApi(row.id)
    ElMessage.success('删除成功')
    fetchData()
  } catch {
    // 取消
  }
}

// ---- 新建对话框 ----
const dialogVisible = ref(false)
const submitting = ref(false)
const formRef = ref<FormInstance>()
const createdSecret = ref<{ accessKey: string; secretKey: string } | null>(null)

const form = reactive({
  name: '',
  expiresAt: '',
  remark: ''
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入 Key 名称', trigger: 'blur' }]
}

function openCreateDialog() {
  form.name = ''
  form.expiresAt = ''
  form.remark = ''
  createdSecret.value = null
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }
  submitting.value = true
  try {
    const res = await createApiKeyApi({
      name: form.name,
      permissions: ['push:send', 'device:query'],
      expiresAt: form.expiresAt || undefined,
      status: 1
    })
    createdSecret.value = {
      accessKey: res.data?.accessKey || '',
      secretKey: res.data?.secretKey || ''
    }
    ElMessage.success('创建成功')
    fetchData()
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : '创建失败')
  } finally {
    submitting.value = false
  }
}

// ---- 示例区 ----
const examplesRef = ref<HTMLElement>()
const examplesOpen = ref(true)
const activeExample = ref('curl')

function scrollToExamples() {
  examplesRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  examplesOpen.value = true
}

const curlExample = `curl -X POST https://api.push.com/v1/push/send \\
  -H "Content-Type: application/json" \\
  -H "X-Access-Key: AK_xxxxxxxxxxxx" \\
  -H "X-Signature: <HMAC-SHA256 签名>" \\
  -d '{
    "title": "欢迎使用推送服务",
    "content": "这是一条测试消息",
    "platform": "android",
    "targetType": "all"
  }'`

const jsonExample = `{
  "title": "欢迎使用推送服务",
  "content": "这是一条测试消息",
  "platform": "android",
  "targetType": "all",
  "pushType": "notification",
  "extras": {
    "sound": "default",
    "badge": 1
  }
}`

const jsExample = `import axios from 'axios'

const client = axios.create({
  baseURL: 'https://api.push.com/v1',
  timeout: 10000
})

// 发送推送
async function sendPush() {
  const res = await client.post('/push/send', {
    title: '欢迎使用推送服务',
    content: '这是一条测试消息',
    platform: 'android',
    targetType: 'all'
  }, {
    headers: {
      'X-Access-Key': 'AK_xxxxxxxxxxxx',
      'X-Signature': sign(secretKey, timestamp)
    }
  })
  console.log(res.data)
}`

// 简易语法高亮（无需依赖库，使用 span 着色）
function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

function highlightBash(code: string): string {
  let s = escapeHtml(code)
  // 字符串
  s = s.replace(/(&quot;|").*?(&quot;|")/g, '<span class="tok-str">$&</span>')
  s = s.replace(/'[^']*'/g, '<span class="tok-str">$&</span>')
  // 注释
  s = s.replace(/(#.*?$)/gm, '<span class="tok-com">$1</span>')
  // 关键字
  s = s.replace(/\b(curl|POST|GET|PUT|DELETE|X|H|d)\b/g, '<span class="tok-kw">$1</span>')
  // 选项
  s = s.replace(/(^|\s)(-[a-zA-Z]+)/g, '$1<span class="tok-opt">$2</span>')
  return s
}

function highlightJson(code: string): string {
  let s = escapeHtml(code)
  // 键名
  s = s.replace(/("(?:[^"\\]|\\.)*")(\s*:)/g, '<span class="tok-key">$1</span>$2')
  // 字符串值
  s = s.replace(/:\s*("(?:[^"\\]|\\.)*")/g, ': <span class="tok-str">$1</span>')
  // 数字
  s = s.replace(/:\s*(-?\d+(?:\.\d+)?)/g, ': <span class="tok-num">$1</span>')
  // 布尔/null
  s = s.replace(/\b(true|false|null)\b/g, '<span class="tok-bool">$1</span>')
  return s
}

function highlightJs(code: string): string {
  let s = escapeHtml(code)
  // 注释
  s = s.replace(/(\/\/.*?$)/gm, '<span class="tok-com">$1</span>')
  // 字符串
  s = s.replace(/('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")/g, '<span class="tok-str">$1</span>')
  // 关键字
  s = s.replace(
    /\b(import|from|const|let|var|function|async|await|return|new|export)\b/g,
    '<span class="tok-kw">$1</span>'
  )
  // 函数名
  s = s.replace(/\b([a-zA-Z_$][\w$]*)\s*\(/g, '<span class="tok-fn">$1</span>(')
  return s
}

onMounted(() => {
  fetchData()
})
</script>

<style lang="scss" scoped>
.api-keys-page {
  animation: fade-up 0.5s ease;
}

// ===== 介绍卡片 =====
.intro-card {
  position: relative;
  border-radius: $radius-xl;
  padding: 32px 36px;
  margin-bottom: 20px;
  overflow: hidden;
  background: $gradient-primary;
  box-shadow: $shadow-primary;

  .intro-bg {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
  }

  .intro-blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(60px);

    &.blob-1 {
      width: 320px;
      height: 320px;
      background: radial-gradient(circle, #ffffff, transparent 70%);
      top: -140px;
      right: -80px;
      opacity: 0.18;
    }
    &.blob-2 {
      width: 260px;
      height: 260px;
      background: radial-gradient(circle, #5cb8ff, transparent 70%);
      bottom: -100px;
      left: 30%;
      opacity: 0.35;
    }
  }

  .intro-grid {
    position: absolute;
    inset: 0;
    background-image: linear-gradient(rgba(255, 255, 255, 0.1) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(255, 255, 255, 0.1) 1px, transparent 1px);
    background-size: 32px 32px;
    mask-image: linear-gradient(135deg, black, transparent 75%);
  }

  .intro-content {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
  }

  .intro-left {
    flex: 1;
    min-width: 280px;
  }

  .intro-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: $radius-pill;
    background: rgba(255, 255, 255, 0.22);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 1px;
    backdrop-filter: blur(6px);
    margin-bottom: 14px;
  }

  .intro-title {
    margin: 0 0 10px;
    font-size: 28px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.3px;
  }

  .intro-desc {
    margin: 0 0 20px;
    color: rgba(255, 255, 255, 0.88);
    font-size: 14px;
    line-height: 1.7;
    max-width: 560px;
  }

  .intro-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;

    :deep(.el-button) {
      background: rgba(255, 255, 255, 0.95);
      border-color: rgba(255, 255, 255, 0.95);
      color: $color-primary;
      font-weight: 600;

      &:hover {
        background: #fff;
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
      }
      &.el-button--primary {
        background: #fff;
        color: $color-primary;
      }
    }
  }

  .intro-stats {
    display: flex;
    gap: 16px;

    .stat-pill {
      padding: 16px 22px;
      border-radius: $radius-lg;
      background: rgba(255, 255, 255, 0.18);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.25);
      text-align: center;
      min-width: 110px;

      .pill-value {
        font-size: 26px;
        font-weight: 800;
        color: #fff;
        font-family: $font-family-mono;
        line-height: 1;
      }
      .pill-label {
        margin-top: 6px;
        font-size: 12px;
        color: rgba(255, 255, 255, 0.85);
      }
    }
  }
}

// ===== 工具栏 =====
.toolbar-card {
  background: var(--bg-card);
  border-radius: $radius-lg;
  padding: 14px 18px;
  margin-bottom: 16px;
  box-shadow: $shadow-sm;
  border: 1px solid var(--border-light);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;

  .toolbar-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }

  .search-input {
    width: 240px;
  }
  .status-select {
    width: 140px;
  }
}

// ===== 表格卡片 =====
.table-card {
  background: var(--bg-card);
  border-radius: $radius-lg;
  padding: 18px;
  box-shadow: $shadow-md;
  border: 1px solid var(--border-light);
}

.name-cell {
  display: flex;
  align-items: center;
  gap: 10px;

  .name-icon {
    width: 32px;
    height: 32px;
    border-radius: $radius-sm;
    background: $color-primary-light-9;
    color: $color-primary;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
  }
  .name-text {
    font-weight: 600;
    color: var(--text-primary);
  }
}

.key-cell {
  display: flex;
  align-items: center;
  gap: 8px;

  .key-text {
    font-family: $font-family-mono;
    font-size: 13px;
    color: var(--text-regular);
    background: var(--bg-page);
    padding: 4px 10px;
    border-radius: $radius-xs;
    letter-spacing: 0.5px;
  }
}

.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  border-radius: $radius-pill;
  font-size: 12px;
  font-weight: 600;

  .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
  }

  &.on {
    color: $color-success;
    background: rgba(24, 194, 156, 0.12);
    .dot {
      background: $color-success;
      box-shadow: 0 0 0 3px rgba(24, 194, 156, 0.18);
      animation: pulse-dot 1.6s ease infinite;
    }
  }
  &.off {
    color: var(--text-secondary);
    background: var(--bg-page);
    .dot {
      background: var(--text-secondary);
    }
  }
}

.time-text {
  font-size: 13px;
  color: var(--text-secondary);
  font-family: $font-family-mono;

  &.expired {
    color: $color-danger;
    font-weight: 600;
  }
}

// ===== 新建对话框 =====
.create-dialog {
  :deep(.el-dialog__header) {
    padding: 20px 24px 12px;
    margin-bottom: 0;
  }
  :deep(.el-dialog__body) {
    padding: 8px 24px 20px;
  }
}

.create-form {
  :deep(.el-form-item__label) {
    font-weight: 600;
    color: var(--text-regular);
  }
}

.secret-result {
  margin-top: 16px;
  border-radius: $radius-md;
  background: linear-gradient(135deg, rgba(24, 194, 156, 0.08), rgba(92, 184, 255, 0.06));
  border: 1px solid rgba(24, 194, 156, 0.25);
  padding: 16px 18px;

  .secret-head {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 600;
    color: $color-success;
    margin-bottom: 14px;

    .success-icon {
      font-size: 20px;
    }
  }

  .secret-block {
    background: var(--bg-card);
    border-radius: $radius-sm;
    padding: 12px 14px;
    border: 1px solid var(--border-light);
  }

  .secret-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 0;
    font-size: 13px;

    .label {
      color: var(--text-secondary);
      width: 90px;
      flex-shrink: 0;
    }
    code {
      flex: 1;
      font-family: $font-family-mono;
      color: var(--text-primary);
      word-break: break-all;
      background: var(--bg-page);
      padding: 4px 8px;
      border-radius: $radius-xs;
    }
  }

  .secret-warn {
    margin-top: 10px;
    padding: 8px 10px;
    border-radius: $radius-xs;
    background: rgba(255, 90, 110, 0.08);
    color: $color-danger;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
  }
}

// ===== 示例卡片 =====
.examples-card {
  margin-top: 20px;
  background: var(--bg-card);
  border-radius: $radius-lg;
  box-shadow: $shadow-md;
  border: 1px solid var(--border-light);
  overflow: hidden;
  transition: box-shadow 0.3s ease;

  &:hover {
    box-shadow: $shadow-lg;
  }
}

.examples-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 22px;
  cursor: pointer;
  user-select: none;
  transition: background 0.2s ease;

  &:hover {
    background: var(--bg-page);
  }

  .head-left {
    display: flex;
    align-items: center;
    gap: 14px;
  }

  .head-icon {
    width: 42px;
    height: 42px;
    border-radius: $radius-md;
    background: $gradient-cyan;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 20px;
    box-shadow: 0 6px 18px rgba(92, 184, 255, 0.32);
  }

  .card-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
  }
  .card-sub {
    margin: 4px 0 0;
    font-size: 12px;
    color: var(--text-secondary);
  }

  .toggle-icon {
    color: var(--text-secondary);
    transition: transform 0.3s ease;
    font-size: 18px;

    &.open {
      transform: rotate(180deg);
    }
  }
}

.examples-body {
  padding: 0 22px 20px;
}

.example-tabs {
  :deep(.el-tabs__header) {
    margin-bottom: 12px;
  }
  :deep(.el-tabs__item) {
    font-weight: 600;
    padding: 8px 0;
    margin-right: 24px;
  }
}

.code-block {
  background: #0e1020;
  border-radius: $radius-md;
  overflow: hidden;
  border: 1px solid rgba(255, 255, 255, 0.06);

  .code-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 14px;
    background: rgba(255, 255, 255, 0.04);
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);

    .code-lang {
      color: rgba(255, 255, 255, 0.55);
      font-size: 12px;
      font-family: $font-family-mono;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    :deep(.el-button) {
      color: rgba(255, 255, 255, 0.7);
      &:hover {
        color: #fff;
      }
    }
  }

  pre {
    margin: 0;
    padding: 16px 18px;
    overflow-x: auto;
    @include scrollbar(6px, rgba(109, 92, 255, 0.4));

    code {
      font-family: $font-family-mono;
      font-size: 13px;
      line-height: 1.7;
      color: #e8eaf6;
      white-space: pre;
    }

    :deep(.tok-kw) { color: #c792ea; font-weight: 600; }
    :deep(.tok-str) { color: #c3e88d; }
    :deep(.tok-com) { color: #637777; font-style: italic; }
    :deep(.tok-num) { color: #f78c6c; }
    :deep(.tok-bool) { color: #ff9cac; }
    :deep(.tok-key) { color: #82aaff; }
    :deep(.tok-fn) { color: #82aaff; }
    :deep(.tok-opt) { color: #ffb547; }
  }
}

.example-tips {
  margin-top: 16px;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 10px;

  .tip-item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 14px;
    background: var(--bg-page);
    border-radius: $radius-sm;
    font-size: 12px;
    color: var(--text-regular);
    line-height: 1.6;

    .tip-icon {
      color: $color-primary;
      font-size: 16px;
      margin-top: 1px;
      flex-shrink: 0;
    }

    code {
      font-family: $font-family-mono;
      color: $color-primary;
      background: $color-primary-light-9;
      padding: 1px 6px;
      border-radius: $radius-xs;
      font-size: 11px;
    }
  }
}

// ===== 动画 =====
@keyframes fade-up {
  from {
    opacity: 0;
    transform: translateY(16px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes pulse-dot {
  0%, 100% {
    box-shadow: 0 0 0 0 rgba(24, 194, 156, 0.4);
  }
  50% {
    box-shadow: 0 0 0 4px rgba(24, 194, 156, 0);
  }
}

// ===== 响应式 =====
@media (max-width: 768px) {
  .intro-card {
    padding: 24px 22px;
  }
  .intro-content {
    flex-direction: column;
    align-items: flex-start;
  }
  .intro-stats {
    width: 100%;
    justify-content: space-between;

    .stat-pill {
      flex: 1;
    }
  }
  .toolbar-card {
    .search-input,
    .status-select {
      width: 100%;
    }
    .toolbar-left {
      width: 100%;
    }
  }
}

// ===== 暗色模式 =====
:global(html.dark) {
  .intro-card {
    background: linear-gradient(135deg, #4d38f0 0%, #7d4dff 100%);
  }
  .name-icon {
    background: rgba(138, 124, 255, 0.16);
    color: $color-primary-light-5;
  }
  .key-text {
    background: rgba(255, 255, 255, 0.04);
  }
  .status-pill.off {
    background: rgba(255, 255, 255, 0.04);
  }
}
</style>
