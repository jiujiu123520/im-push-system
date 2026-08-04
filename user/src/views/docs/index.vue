<template>
  <div class="page">
    <el-tabs v-model="tab">
      <!-- ===== API 使用文档（与管理端一致的静态文档）===== -->
      <el-tab-pane label="API 使用文档" name="docs">
        <div class="doc-content">
          <!-- 接口概览 -->
          <div class="doc-section">
            <div class="section-head">
              <el-icon class="section-icon"><Connection /></el-icon>
              <h3>接口概览</h3>
            </div>
            <div class="overview-grid">
              <div class="overview-item">
                <div class="item-label">接口地址</div>
                <div class="item-value mono">POST {{ baseUrl }}/push</div>
              </div>
              <div class="overview-item">
                <div class="item-label">鉴权方式</div>
                <div class="item-value">请求头 X-Api-Key</div>
              </div>
              <div class="overview-item">
                <div class="item-label">Content-Type</div>
                <div class="item-value mono">application/json</div>
              </div>
              <div class="overview-item">
                <div class="item-label">频率限制</div>
                <div class="item-value">根据 API Key 配置</div>
              </div>
            </div>
          </div>

          <!-- 请求头 -->
          <div class="doc-section">
            <div class="section-head">
              <el-icon class="section-icon"><Key /></el-icon>
              <h3>请求头</h3>
            </div>
            <el-table :data="headerParams" border size="small" class="param-table">
              <el-table-column prop="name" label="参数名" width="160">
                <template #default="{ row }"><code class="param-name">{{ row.name }}</code></template>
              </el-table-column>
              <el-table-column prop="required" label="必填" width="70" align="center">
                <template #default="{ row }">
                  <el-tag :type="row.required ? 'danger' : 'info'" effect="light" size="small">{{ row.required ? '是' : '否' }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="type" label="类型" width="100">
                <template #default="{ row }"><span class="param-type">{{ row.type }}</span></template>
              </el-table-column>
              <el-table-column prop="desc" label="说明" />
            </el-table>
          </div>

          <!-- 请求参数 -->
          <div class="doc-section">
            <div class="section-head">
              <el-icon class="section-icon"><EditPen /></el-icon>
              <h3>请求参数</h3>
            </div>
            <el-table :data="bodyParams" border size="small" class="param-table">
              <el-table-column prop="name" label="参数名" width="140">
                <template #default="{ row }"><code class="param-name">{{ row.name }}</code></template>
              </el-table-column>
              <el-table-column prop="required" label="必填" width="70" align="center">
                <template #default="{ row }">
                  <el-tag :type="row.required ? 'danger' : 'info'" effect="light" size="small">{{ row.required ? '是' : '否' }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="type" label="类型" width="100">
                <template #default="{ row }"><span class="param-type">{{ row.type }}</span></template>
              </el-table-column>
              <el-table-column prop="desc" label="说明" min-width="280">
                <template #default="{ row }"><div v-html="row.desc"></div></template>
              </el-table-column>
            </el-table>
          </div>

          <!-- 请求示例 -->
          <div class="doc-section">
            <div class="section-head">
              <el-icon class="section-icon"><Document /></el-icon>
              <h3>请求示例</h3>
            </div>
            <el-tabs v-model="activeDocTab" class="doc-tabs">
              <el-tab-pane label="cURL" name="curl">
                <div class="code-block">
                  <div class="code-header">
                    <span class="code-lang">bash</span>
                    <el-button link :icon="CopyDocument" @click="copyCode(docCurlExample)">复制</el-button>
                  </div>
                  <pre><code v-html="highlightBash(docCurlExample)"></code></pre>
                </div>
              </el-tab-pane>
              <el-tab-pane label="请求体" name="body">
                <div class="code-block">
                  <div class="code-header">
                    <span class="code-lang">json</span>
                    <el-button link :icon="CopyDocument" @click="copyCode(docBodyExample)">复制</el-button>
                  </div>
                  <pre><code v-html="highlightJson(docBodyExample)"></code></pre>
                </div>
              </el-tab-pane>
              <el-tab-pane label="JavaScript" name="js">
                <div class="code-block">
                  <div class="code-header">
                    <span class="code-lang">javascript</span>
                    <el-button link :icon="CopyDocument" @click="copyCode(docJsExample)">复制</el-button>
                  </div>
                  <pre><code v-html="highlightJs(docJsExample)"></code></pre>
                </div>
              </el-tab-pane>
            </el-tabs>
          </div>

          <!-- 响应格式 -->
          <div class="doc-section">
            <div class="section-head">
              <el-icon class="section-icon"><CircleCheckFilled /></el-icon>
              <h3>响应格式</h3>
            </div>
            <div class="code-block">
              <div class="code-header">
                <span class="code-lang">json</span>
                <el-button link :icon="CopyDocument" @click="copyCode(docResponseExample)">复制</el-button>
              </div>
              <pre><code v-html="highlightJson(docResponseExample)"></code></pre>
            </div>
            <el-table :data="responseFields" border size="small" class="param-table" style="margin-top: 12px;">
              <el-table-column prop="name" label="字段名" width="160">
                <template #default="{ row }"><code class="param-name">{{ row.name }}</code></template>
              </el-table-column>
              <el-table-column prop="type" label="类型" width="100">
                <template #default="{ row }"><span class="param-type">{{ row.type }}</span></template>
              </el-table-column>
              <el-table-column prop="desc" label="说明" />
            </el-table>
          </div>

          <!-- 错误码 -->
          <div class="doc-section">
            <div class="section-head">
              <el-icon class="section-icon"><WarningFilled /></el-icon>
              <h3>错误码说明</h3>
            </div>
            <el-table :data="errorCodes" border size="small" class="param-table">
              <el-table-column prop="code" label="HTTP 状态码" width="120" align="center">
                <template #default="{ row }">
                  <el-tag :type="row.code >= 500 ? 'danger' : row.code >= 400 ? 'warning' : 'success'" effect="light" size="small">{{ row.code }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="message" label="错误信息" width="240" />
              <el-table-column prop="desc" label="说明" />
            </el-table>
          </div>
        </div>
      </el-tab-pane>

      <!-- ===== API 密钥管理 ===== -->
      <el-tab-pane label="API 密钥管理" name="keys">
        <el-card shadow="never">
          <template #header>
            <div class="card-header">
              <div class="title">API 密钥（开放 API 调用凭证）</div>
              <el-button type="primary" @click="openCreate"><el-icon><Plus /></el-icon>新建 API Key</el-button>
            </div>
          </template>
          <el-alert type="warning" :closable="false" style="margin-bottom:16px" show-icon
                    title="警告：请妥善保管 API Key，不要泄露。若怀疑泄露请立即禁用或重新创建。" />
          <el-table :data="keyList" stripe v-loading="loadingKeys">
            <el-table-column prop="id" label="ID" width="70" align="center" />
            <el-table-column label="密钥信息" min-width="260">
              <template #default="{ row }">
                <div class="name">{{ row.name }}</div>
                <div class="kv">
                  <span>Key:</span>
                  <code>{{ row.key_value }}</code>
                  <el-button link type="primary" @click="copy(row.key_value)">复制</el-button>
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="expire_at" label="过期时间" width="170" align="center">
              <template #default="{ row }">{{ row.expire_at || '永久有效' }}</template>
            </el-table-column>
            <el-table-column label="状态" width="100" align="center">
              <template #default="{ row }">
                <el-switch v-model="row.status" :active-value="1" :inactive-value="0" @change="toggleKeyStatus(row as ApiKey)" />
              </template>
            </el-table-column>
            <el-table-column prop="created_at" label="创建时间" width="170" align="center" />
            <el-table-column label="操作" width="100" align="center" fixed="right">
              <template #default="{ row }">
                <el-popconfirm title="确定删除？此 Key 立即失效" @confirm="delApiKey(row as ApiKey)">
                  <template #reference><el-button link type="danger">删除</el-button></template>
                </el-popconfirm>
              </template>
            </el-table-column>
          </el-table>
          <div class="pagination">
            <el-pagination v-model:current-page="qk.page" v-model:page-size="qk.pageSize"
              :page-sizes="[10,20,50]" layout="total, sizes, prev, pager, next, jumper"
              :total="kTotal" @current-change="loadKeys" @size-change="loadKeys(1)" />
          </div>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <!-- 创建 API Key -->
    <el-dialog v-model="dlgVisible" title="新建 API Key" width="min(460px, 92vw)">
      <el-form ref="dlgFormRef" :model="dlgForm" :rules="dlgRules" label-width="100px">
        <el-form-item label="名称" prop="name">
          <el-input v-model="dlgForm.name" placeholder="给这个 Key 起个名字，例如 服务器脚本、CI/CD" maxlength="50" show-word-limit />
        </el-form-item>
        <el-form-item label="有效期(天)" prop="expires_days">
          <el-input-number v-model="dlgForm.expires_days" :min="0" :max="3650" />
          <div class="hint">0 = 永久有效</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dlgVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveApiKey">创建</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import {
  Plus, Connection, Key, EditPen, Document, CircleCheckFilled,
  WarningFilled, CopyDocument
} from '@element-plus/icons-vue'
import {
  getUserApiKeyListApi, createUserApiKeyApi, updateUserApiKeyStatusApi, deleteUserApiKeyApi
} from '@/api/docs'
import type { ApiKey } from '@/api/types'

const tab = ref('docs')
const baseUrl = location.origin + '/api'

// ---- API 文档静态数据（与管理端一致）----
const activeDocTab = ref('curl')

const headerParams = [
  { name: 'X-Api-Key', required: true, type: 'string', desc: '开放 API Key，在 API 密钥管理页面创建获取' },
  { name: 'Content-Type', required: true, type: 'string', desc: '请求内容类型，必须为 application/json' }
]

const bodyParams = [
  { name: 'target_type', required: true, type: 'string', desc: '推送目标类型：<code>device</code> 按设备ID推送，<code>key</code> 按Key值推送，<code>broadcast</code> 广播推送' },
  { name: 'target_value', required: true, type: 'string', desc: '推送目标值，多个用英文逗号分隔。device类型为设备ID，key类型为Key值。broadcast类型时传任意值即可' },
  { name: 'title', required: false, type: 'string', desc: '消息标题' },
  { name: 'content', required: false, type: 'string', desc: '消息内容' },
  { name: 'payload', required: false, type: 'object', desc: '附加数据，JSON对象，客户端可自定义解析' },
  { name: 'priority', required: false, type: 'string', desc: '消息优先级：<code>high</code> 高，<code>normal</code> 普通（默认），<code>low</code> 低' }
]

const responseFields = [
  { name: 'success_count', type: 'number', desc: '推送成功的设备数量' },
  { name: 'fail_count', type: 'number', desc: '推送失败的设备数量' },
  { name: 'stored_offline', type: 'boolean', desc: '是否有设备离线时消息已存为离线（重连后可拉取）' },
  { name: 'detail', type: 'array', desc: '推送详情列表，包含每个设备的推送结果' },
  { name: 'fail_reason', type: 'string', desc: '失败原因摘要（如有失败）' }
]

const errorCodes = [
  { code: 200, message: 'OK', desc: '请求成功（即使部分设备推送失败，HTTP 状态码仍为 200）' },
  { code: 400, message: 'Bad Request', desc: '请求参数错误，如 target_type 无效、target_value 为空等' },
  { code: 401, message: 'Unauthorized', desc: '鉴权失败，缺少 X-Api-Key 请求头或 API Key 无效/已禁用' },
  { code: 403, message: 'Forbidden', desc: '权限不足，API Key 无推送权限或 IP 不在白名单内' },
  { code: 404, message: 'Not Found', desc: '请求的接口不存在' },
  { code: 429, message: 'Too Many Requests', desc: '请求频率超过限制，请降低调用频率' },
  { code: 500, message: 'Internal Server Error', desc: '服务器内部错误' },
  { code: 503, message: 'Service Unavailable', desc: '服务不可用，推送服务可能未启动' }
]

const docCurlExample = computed(() => `curl -X POST ${baseUrl}/push \\
  -H "Content-Type: application/json" \\
  -H "X-Api-Key: your-api-key-here" \\
  -d '{
    "target_type": "device",
    "target_value": "device_id_1,device_id_2",
    "title": "消息标题",
    "content": "这是一条测试消息",
    "priority": "normal",
    "payload": {
      "type": "notification",
      "action": "open_page"
    }
  }'`)

const docBodyExample = `{
  "target_type": "key",
  "target_value": "key_value_1,key_value_2",
  "title": "欢迎使用推送服务",
  "content": "您有一条新消息",
  "priority": "high",
  "payload": {
    "order_id": "123456",
    "type": "order_notify"
  }
}`

const docJsExample = computed(() => `// 使用 fetch 调用推送 API
async function sendPush(apiKey, params) {
  const res = await fetch('${baseUrl}/push', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Api-Key': apiKey
    },
    body: JSON.stringify(params)
  })

  const data = await res.json()

  if (!res.ok) {
    throw new Error(data.message || '推送失败')
  }

  return data
}

// 使用示例
sendPush('your-api-key-here', {
  target_type: 'device',
  target_value: 'device001',
  title: '测试推送',
  content: 'Hello, World!',
  priority: 'normal'
}).then(result => {
  console.log('推送成功:', result)
}).catch(err => {
  console.error('推送失败:', err.message)
})`)

const docResponseExample = `{
  "success_count": 2,
  "fail_count": 1,
  "stored_offline": true,
  "fail_reason": "设备离线，APP未连接或已断开（消息已存为离线，设备重连后可拉取）",
  "detail": [
    {
      "device_id": "device001",
      "status": "success"
    },
    {
      "device_id": "device002",
      "status": "success"
    },
    {
      "device_id": "device003",
      "status": "offline",
      "message": "设备离线，APP未连接或已断开（消息已存为离线，设备重连后可拉取）"
    }
  ]
}`

// ---- 语法高亮 ----
function escapeHtml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}
function highlightBash(code: string): string {
  let s = escapeHtml(code)
  s = s.replace(/(&quot;|").*?(&quot;|")/g, '<span class="tok-str">$&</span>')
  s = s.replace(/'[^']*'/g, '<span class="tok-str">$&</span>')
  s = s.replace(/(#.*?$)/gm, '<span class="tok-com">$1</span>')
  s = s.replace(/\b(curl|POST|GET|PUT|DELETE)\b/g, '<span class="tok-kw">$1</span>')
  s = s.replace(/(^|\s)(-[a-zA-Z]+)/g, '$1<span class="tok-opt">$2</span>')
  return s
}
function highlightJson(code: string): string {
  let s = escapeHtml(code)
  s = s.replace(/("(?:[^"\\]|\\.)*")(\s*:)/g, '<span class="tok-key">$1</span>$2')
  s = s.replace(/:\s*("(?:[^"\\]|\\.)*")/g, ': <span class="tok-str">$1</span>')
  s = s.replace(/:\s*(-?\d+(?:\.\d+)?)/g, ': <span class="tok-num">$1</span>')
  s = s.replace(/\b(true|false|null)\b/g, '<span class="tok-bool">$1</span>')
  return s
}
function highlightJs(code: string): string {
  let s = escapeHtml(code)
  s = s.replace(/(\/\/.*?$)/gm, '<span class="tok-com">$1</span>')
  s = s.replace(/('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")/g, '<span class="tok-str">$1</span>')
  s = s.replace(/\b(import|from|const|let|var|function|async|await|return|new|export)\b/g, '<span class="tok-kw">$1</span>')
  s = s.replace(/\b([a-zA-Z_$][\w$]*)\s*\(/g, '<span class="tok-fn">$1</span>(')
  return s
}

// ---- 复制 ----
async function copyToClipboard(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch {}
  try {
    const textarea = document.createElement('textarea')
    textarea.value = text
    textarea.style.position = 'fixed'
    textarea.style.left = '-9999px'
    document.body.appendChild(textarea)
    textarea.focus()
    textarea.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(textarea)
    return ok
  } catch { return false }
}
async function copyCode(text: string) {
  const ok = await copyToClipboard(text)
  ok ? ElMessage.success('代码已复制') : ElMessage.error('复制失败，请手动复制')
}

// ---- API Keys ----
const loadingKeys = ref(false)
const saving = ref(false)
const keyList = ref<ApiKey[]>([])
const kTotal = ref(0)
const qk = reactive({ page: 1, pageSize: 10 })
const dlgVisible = ref(false)
const dlgFormRef = ref<FormInstance>()
const dlgForm = reactive({ name: '', expires_days: 0 })
const dlgRules: FormRules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' },
         { min: 1, max: 50, message: '长度 1-50', trigger: 'blur' }]
}
async function loadKeys(page = qk.page) {
  qk.page = page
  loadingKeys.value = true
  try {
    const r = await getUserApiKeyListApi({ page: qk.page, pageSize: qk.pageSize, per_page: qk.pageSize })
    keyList.value = r.data?.list || []
    kTotal.value = r.data?.total || 0
  } finally { loadingKeys.value = false }
}
function openCreate() { dlgForm.name = ''; dlgForm.expires_days = 0; dlgVisible.value = true }
async function saveApiKey() {
  await dlgFormRef.value?.validate(async (ok) => {
    if (!ok) return
    saving.value = true
    try {
      await createUserApiKeyApi({ ...dlgForm, expires_days: dlgForm.expires_days || undefined })
      ElMessage.success('创建成功')
      dlgVisible.value = false
      loadKeys(qk.page)
    } catch (e: any) { ElMessage.error(e?.message || '创建失败')
    } finally { saving.value = false }
  })
}
async function toggleKeyStatus(row: ApiKey) {
  try {
    await updateUserApiKeyStatusApi(row.id, row.status as any)
    ElMessage.success(row.status === 1 ? '已启用' : '已禁用')
  } catch (e: any) { ElMessage.error(e?.message || '操作失败'); row.status = row.status === 1 ? 0 : 1 }
}
async function delApiKey(row: ApiKey) {
  try { await deleteUserApiKeyApi(row.id); ElMessage.success('已删除'); loadKeys(qk.page) } catch {}
}
function copy(v: string) {
  copyToClipboard(v).then(ok => ok ? ElMessage.success('已复制') : ElMessage.warning('请手动复制'))
}

onMounted(() => { loadKeys(1) })
</script>

<style lang="scss" scoped>
@use '@/styles/mixins' as *;

.card-header { display: flex; justify-content: space-between; align-items: center;
  .title { font-weight: 600; font-size: $font-size-lg; } }

// ===== API 文档 =====
.doc-content {
  .doc-section { margin-bottom: $space-6; }

  .section-head {
    display: flex; align-items: center; gap: 10px; margin-bottom: $space-4;
    .section-icon { font-size: 20px; color: var(--color-primary); }
    h3 { margin: 0; font-size: $font-size-lg; font-weight: 700; color: var(--text-primary); }
  }

  .overview-grid {
    display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;
    .overview-item {
      padding: $space-4; background: #f8fafc; border-radius: $radius-md;
      border: 1px solid var(--border-light);
      .item-label { font-size: $font-size-xs; color: var(--text-secondary); margin-bottom: 6px; }
      .item-value { font-size: $font-size-md; font-weight: 600; color: var(--text-primary);
        &.mono { font-family: ui-monospace, Menlo, monospace; color: var(--color-primary); } }
    }
  }

  .param-table {
    :deep(.el-table__header th) { background: #f8fafc; font-weight: 600; }
    .param-name { font-family: ui-monospace, Menlo, monospace; font-size: 12px;
      color: var(--color-primary); background: var(--color-primary-light-9); padding: 2px 6px; border-radius: 4px; }
    .param-type { font-family: ui-monospace, Menlo, monospace; font-size: 12px; color: var(--text-secondary); }
    code { font-family: ui-monospace, Menlo, monospace; font-size: 12px;
      color: var(--color-primary); background: var(--color-primary-light-9); padding: 1px 5px; border-radius: 4px; }
  }

  .doc-tabs { :deep(.el-tabs__header) { margin-bottom: 12px; } }
}

.code-block {
  background: #0e1020; border-radius: $radius-md; overflow: hidden;
  border: 1px solid rgba(255,255,255,0.06);
  .code-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 14px; background: rgba(255,255,255,0.04);
    border-bottom: 1px solid rgba(255,255,255,0.06);
    .code-lang { color: rgba(255,255,255,0.55); font-size: 12px;
      font-family: ui-monospace, Menlo, monospace; text-transform: uppercase; letter-spacing: 1px; }
    :deep(.el-button) { color: rgba(255,255,255,0.7); &:hover { color: #fff; } }
  }
  pre {
    margin: 0; padding: $space-4; overflow-x: auto;
    @include scrollbar(6px, rgba(14,165,233,0.4));
    code { font-family: ui-monospace, Menlo, monospace; font-size: 13px; line-height: 1.7; color: #e8eaf6; white-space: pre; }
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

.name { font-weight: 600; color: var(--text-primary); }
.kv { display: flex; align-items: center; gap: $space-2; margin-top: 4px;
  font-size: $font-size-xs; color: var(--text-secondary);
  span { min-width: 46px; }
  code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: var(--text-primary); } }
.hint { color: var(--text-secondary); font-size: $font-size-xs; margin-top: 4px; }
.pagination { margin-top: $space-4; display: flex; justify-content: flex-end; }

@media (max-width: 768px) {
  .overview-grid { grid-template-columns: 1fr !important; }
}
</style>
