<template>
  <div class="page">
    <el-tabs v-model="tab">
      <el-tab-pane label="API 使用文档" name="docs">
        <el-card shadow="never">
          <template #header>
            <div class="card-header">
              <div class="title">开放 API 文档 · 推送接口</div>
              <el-button type="primary" size="small" @click="loadDocs">刷新文档</el-button>
            </div>
          </template>
          <el-alert type="info" :closable="false" style="margin-bottom:16px" show-icon>
            <template #title>
              <div>接口 Base URL：<code class="inline-code">{{ baseUrl }}</code>，请求头需携带
                <code class="inline-code">X-Api-Key: &lt;你的 API Key&gt;</code> 鉴权。
                <el-button link type="primary" @click="goApiKeys">前往创建 API Key →</el-button>
              </div>
            </template>
          </el-alert>
          <div v-for="(ep, idx) in docs.endpoints" :key="idx" class="endpoint">
            <div class="ep-head">
              <el-tag :type="methodColor(ep.method)" effect="dark" size="small">{{ ep.method }}</el-tag>
              <code class="path">{{ ep.path }}</code>
            </div>
            <div class="ep-desc">{{ ep.description }}</div>
            <div class="ep-example" v-if="docs.examples?.[ep.method + ' ' + ep.path]">
              <div class="lbl">示例 cURL</div>
              <pre class="code"><code>{{ curlExample(ep.method, ep.path, docs.examples[ep.method + ' ' + ep.path]) }}</code></pre>
            </div>
          </div>
          <div v-if="!docs.endpoints?.length" class="empty">
            <el-empty description="暂无文档" />
          </div>
        </el-card>
      </el-tab-pane>
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
                  <code>{{ row.api_key }}</code>
                  <el-button link type="primary" @click="copy(row.api_key)">复制</el-button>
                </div>
                <div v-if="row.api_secret" class="kv">
                  <span>Secret:</span>
                  <code>{{ showSecret[row.id] ? row.api_secret : '••••••••' }}</code>
                  <el-button link type="primary" @click="showSecret[row.id] = !showSecret[row.id]">
                    {{ showSecret[row.id] ? '隐藏' : '查看' }}
                  </el-button>
                  <el-button link type="primary" @click="copy(row.api_secret!)">复制</el-button>
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="call_count" label="调用次数" width="110" align="center" />
            <el-table-column prop="last_called_at" label="最近调用" width="170" align="center">
              <template #default="{ row }">{{ row.last_called_at || '-' }}</template>
            </el-table-column>
            <el-table-column prop="expires_at" label="过期时间" width="170" align="center">
              <template #default="{ row }">{{ row.expires_at || '永久有效' }}</template>
            </el-table-column>
            <el-table-column label="状态" width="100" align="center">
              <template #default="{ row }">
                <el-switch v-model="row.status" :active-value="1" :inactive-value="0" @change="toggleKeyStatus(row)" />
              </template>
            </el-table-column>
            <el-table-column prop="created_at" label="创建时间" width="170" align="center" />
            <el-table-column label="操作" width="100" align="center" fixed="right">
              <template #default="{ row }">
                <el-popconfirm title="确定删除？此 Key 立即失效" @confirm="delApiKey(row)">
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
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { useRouter } from 'vue-router'
import {
  getDocsIndexApi, getUserApiKeyListApi, createUserApiKeyApi, updateUserApiKeyStatusApi, deleteUserApiKeyApi
} from '@/api/docs'
import type { ApiKey } from '@/api/types'

const router = useRouter()
const tab = ref('docs')
const baseUrl = ref(location.origin + '/api')
const docs = ref<{ endpoints: any[]; examples: Record<string, any> }>({ endpoints: [], examples: {} })
async function loadDocs() {
  try {
    const r = await getDocsIndexApi()
    docs.value = Object.assign({ endpoints: [], examples: {} }, r.data || {})
  } catch {}
}
function methodColor(m: string) {
  return (m === 'POST' ? 'danger' : m === 'PUT' ? 'warning' : m === 'DELETE' ? 'info' : 'primary') as any
}
function curlExample(m: string, p: string, body?: any) {
  const b = body ? ` -H 'Content-Type: application/json' --data '${JSON.stringify(body)}'` : ''
  return `curl -X ${m} \\
  ${baseUrl.value}${p.replace(/^\/api/, '')} \\
  -H 'X-Api-Key: <YOUR_API_KEY>'${b}`
}
function goApiKeys() { tab.value = 'keys' }

// API Keys
const loadingKeys = ref(false)
const saving = ref(false)
const keyList = ref<ApiKey[]>([])
const kTotal = ref(0)
const qk = reactive({ page: 1, pageSize: 10 })
const showSecret = reactive<Record<number, boolean>>({})
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
    keyList.value = r.data?.items || []
    kTotal.value = r.data?.total || 0
  } finally { loadingKeys.value = false }
}
function openCreate() { dlgForm.name = ''; dlgForm.expires_days = 0; dlgVisible = true }
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
  navigator.clipboard.writeText(v).then(() => ElMessage.success('已复制')).catch(() => ElMessage.warning('请手动复制'))
}
onMounted(() => { loadDocs(); loadKeys(1) })
</script>

<style lang="scss" scoped>
.card-header { display: flex; justify-content: space-between; align-items: center;
  .title { font-weight: 600; font-size: $font-size-lg; } }
.inline-code {
  background: #f1f5f9; padding: 2px 6px; border-radius: 4px;
  font-family: ui-monospace, Menlo, monospace; font-size: 12px;
}
.endpoint { margin-bottom: $space-6; padding: $space-4; background: #f8fafc; border-radius: $radius-md; }
.ep-head { display: flex; align-items: center; gap: $space-3; }
.path {
  font-family: ui-monospace, Menlo, monospace; font-size: $font-size-sm;
  background: #0f172a; color: #e2e8f0; padding: 2px 8px; border-radius: 4px;
}
.ep-desc { margin-top: $space-2; color: var(--text-regular); font-size: $font-size-sm; }
.ep-example { margin-top: $space-3; }
.lbl { font-size: $font-size-xs; color: var(--text-secondary); margin-bottom: 4px; }
.code {
  background: #0f172a; color: #e2e8f0;
  padding: $space-3; border-radius: $radius-md; overflow-x: auto;
  font-family: ui-monospace, Menlo, monospace; font-size: $font-size-xs; line-height: 1.6;
  margin: 0;
}
.name { font-weight: 600; color: var(--text-primary); }
.kv { display: flex; align-items: center; gap: $space-2; margin-top: 4px;
  font-size: $font-size-xs; color: var(--text-secondary);
  span { min-width: 46px; }
  code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: var(--text-primary); } }
.hint { color: var(--text-secondary); font-size: $font-size-xs; margin-top: 4px; }
.pagination { margin-top: $space-4; display: flex; justify-content: flex-end; }
</style>
