<template>
  <div class="page-container domains-page">
    <!-- 页头 -->
    <div class="page-hero">
      <div class="hero-bg">
        <div class="hero-blob blob-a"></div>
        <div class="hero-blob blob-b"></div>
      </div>
      <div class="hero-content">
        <div>
          <h2 class="hero-title">域名与 SSL</h2>
          <p class="hero-sub">绑定域名、自动申请 Let's Encrypt 证书、部署 Nginx</p>
        </div>
        <div class="hero-stats">
          <div class="stat-mini">
            <span class="stat-label">绑定域名</span>
            <span class="stat-value">{{ list.length }}</span>
          </div>
          <div class="stat-divider"></div>
          <div class="stat-mini">
            <span class="stat-label">SSL 就绪</span>
            <span class="stat-value" :class="env.ready ? 'status-ok' : 'status-warn'">
              <el-icon><component :is="env.ready ? 'CircleCheckFilledIcon' : 'WarningFilledIcon'" /></el-icon>
              {{ env.ready ? '正常' : '未就绪' }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- SSL 环境检查 -->
    <div class="env-card" v-loading="envLoading">
      <div class="env-head">
        <div class="env-title">
          <el-icon class="env-icon"><SetUpIcon /></el-icon>
          <span>SSL 环境状态</span>
        </div>
        <el-button
          :icon="RefreshIcon"
          size="small"
          @click="checkEnvironment"
        >
          重新检查
        </el-button>
      </div>
      <div class="env-grid">
        <div class="env-item" :class="{ ok: env.acme_sh, fail: !env.acme_sh }">
          <el-icon><component :is="env.acme_sh ? 'CircleCheckFilledIcon' : 'CircleCloseFilledIcon'" /></el-icon>
          <div class="env-text">
            <div class="env-name">acme.sh</div>
            <div class="env-desc">{{ env.acme_sh ? '已安装' : '未安装' }}</div>
          </div>
        </div>
        <div class="env-item" :class="{ ok: env.nginx, fail: !env.nginx }">
          <el-icon><component :is="env.nginx ? 'CircleCheckFilledIcon' : 'CircleCloseFilledIcon'" /></el-icon>
          <div class="env-text">
            <div class="env-name">nginx</div>
            <div class="env-desc">{{ env.nginx ? '可用' : '不可用' }}</div>
          </div>
        </div>
        <div class="env-item" :class="{ ok: env.curl, fail: !env.curl }">
          <el-icon><component :is="env.curl ? 'CircleCheckFilledIcon' : 'CircleCloseFilledIcon'" /></el-icon>
          <div class="env-text">
            <div class="env-name">curl</div>
            <div class="env-desc">{{ env.curl ? '可用' : '不可用' }}</div>
          </div>
        </div>
        <div class="env-item" :class="{ ok: env.openssl, fail: !env.openssl }">
          <el-icon><component :is="env.openssl ? 'CircleCheckFilledIcon' : 'CircleCloseFilledIcon'" /></el-icon>
          <div class="env-text">
            <div class="env-name">openssl</div>
            <div class="env-desc">{{ env.openssl ? '可用' : '不可用' }}</div>
          </div>
        </div>
        <div class="env-item" :class="{ ok: env.ssl_dir, fail: !env.ssl_dir }">
          <el-icon><component :is="env.ssl_dir ? 'CircleCheckFilledIcon' : 'CircleCloseFilledIcon'" /></el-icon>
          <div class="env-text">
            <div class="env-name">证书目录</div>
            <div class="env-desc">{{ env.ssl_dir ? '/etc/nginx/ssl' : '不存在' }}</div>
          </div>
        </div>
        <div class="env-item" :class="{ ok: env.sudoers, fail: !env.sudoers }">
          <el-icon><component :is="env.sudoers ? 'CircleCheckFilledIcon' : 'CircleCloseFilledIcon'" /></el-icon>
          <div class="env-text">
            <div class="env-name">sudoers</div>
            <div class="env-desc">{{ env.sudoers ? '已配置' : '未配置' }}</div>
          </div>
        </div>
      </div>
      <div v-if="!env.acme_sh" class="env-action">
        <el-alert type="warning" :closable="false" show-icon>
          <span>acme.sh 未安装，证书申请功能不可用</span>
          <template #append>
            <el-button type="primary" size="small" :loading="installing" @click="installAcme">
              一键安装 acme.sh
            </el-button>
          </template>
        </el-alert>
      </div>
    </div>

    <!-- 域名列表 -->
    <div class="domains-card" v-loading="loading">
      <div class="card-head">
        <div class="head-icon icon-domain">
          <el-icon><LinkIcon /></el-icon>
        </div>
        <div class="head-text">
          <h3 class="card-title">域名绑定</h3>
          <p class="card-sub">管理绑定的域名与 SSL 证书</p>
        </div>
        <div class="head-actions">
          <el-button :icon="RefreshIcon" @click="loadList">刷新</el-button>
          <el-button :icon="PromotionIcon" :loading="syncing" @click="syncNginx">
            同步 Nginx
          </el-button>
          <el-button type="primary" :icon="PlusIcon" @click="openAddDialog">
            添加域名
          </el-button>
        </div>
      </div>

      <el-table :data="list" stripe style="width: 100%">
        <el-table-column label="域名" min-width="200">
          <template #default="{ row }">
            <div class="domain-cell">
              <el-icon class="domain-icon"><LinkIcon /></el-icon>
              <span class="domain-name">{{ row.domain }}</span>
              <el-tag v-if="row.is_primary" type="warning" size="small" effect="dark">
                <el-icon><StarIcon /></el-icon> 主域名
              </el-tag>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="用途" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="typeTagMap[row.type]" size="small">
              {{ typeTextMap[row.type] }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="SSL 状态" width="130" align="center">
          <template #default="{ row }">
            <el-tag
              v-if="row.ssl_status === 'issued'"
              type="success"
              size="small"
              effect="dark"
            >
              <el-icon><LockIcon /></el-icon> 已签发
            </el-tag>
            <el-tag v-else-if="row.ssl_status === 'pending'" type="warning" size="small">
              申请中
            </el-tag>
            <el-tag v-else-if="row.ssl_status === 'failed'" type="danger" size="small">
              失败
            </el-tag>
            <el-tag v-else-if="row.ssl_status === 'expired'" type="danger" size="small" effect="dark">
              已过期
            </el-tag>
            <el-tag v-else type="info" size="small">未申请</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="证书过期时间" min-width="170">
          <template #default="{ row }">
            <template v-if="row.ssl_expire_at">
              <div>{{ row.ssl_expire_at }}</div>
              <el-text v-if="row.days_left !== undefined" :type="row.days_left < 7 ? 'danger' : row.days_left < 30 ? 'warning' : 'success'" size="small">
                剩余 {{ row.days_left }} 天
              </el-text>
            </template>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>

        <el-table-column label="Nginx" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="row.nginx_deployed ? 'success' : 'info'" size="small">
              {{ row.nginx_deployed ? '已部署' : '未部署' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-switch
              :model-value="row.status === 1"
              @change="(val: boolean) => toggleStatus(row, val)"
            />
          </template>
        </el-table-column>

        <el-table-column label="操作" width="280" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="!row.is_primary"
              link
              type="warning"
              size="small"
              :icon="StarIcon"
              @click="setPrimary(row)"
            >
              设主
            </el-button>
            <el-button
              link
              type="success"
              size="small"
              :icon="LockIcon"
              :loading="actionLoading[row.id] === 'ssl'"
              @click="applySsl(row)"
            >
              申请SSL
            </el-button>
            <el-button
              link
              type="primary"
              size="small"
              :icon="PromotionIcon"
              :loading="actionLoading[row.id] === 'deploy'"
              @click="deployNginx(row)"
            >
              部署
            </el-button>
            <el-button
              link
              type="danger"
              size="small"
              :icon="DeleteIcon"
              @click="remove(row)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-empty v-if="!loading && list.length === 0" description="暂无绑定域名，点击「添加域名」开始" />
    </div>

    <!-- 添加域名弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      title="添加域名"
      width="480px"
      :close-on-click-modal="false"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="formRules"
        label-position="top"
      >
        <el-form-item label="域名" prop="domain">
          <el-input
            v-model="form.domain"
            placeholder="push.example.com"
            clearable
          />
          <div class="form-tip">不含 http:// 和端口，仅域名</div>
        </el-form-item>
        <el-form-item label="用途" prop="type">
          <el-select v-model="form.type" style="width: 100%">
            <el-option label="管理后台" value="admin" />
            <el-option label="开放 API" value="api" />
            <el-option label="WebSocket" value="ws" />
          </el-select>
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input
            v-model="form.remark"
            placeholder="可选备注"
            clearable
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitAdd">
          确定
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  Refresh as RefreshIcon,
  Plus as PlusIcon,
  Promotion as PromotionIcon,
  Delete as DeleteIcon,
  Star as StarIcon,
  SetUp as SetUpIcon,
  Link as LinkIcon,
  Lock as LockIcon,
  CircleCheckFilled as CircleCheckFilledIcon,
  CircleCloseFilled as CircleCloseFilledIcon,
  WarningFilled as WarningFilledIcon
} from '@element-plus/icons-vue'
import type { FormInstance, FormRules } from 'element-plus'
import {
  getDomainListApi,
  createDomainApi,
  deleteDomainApi,
  setPrimaryDomainApi,
  applySslApi,
  deployNginxApi,
  syncNginxApi,
  getSslEnvironmentApi,
  installAcmeApi,
  updateDomainApi
} from '@/api/domain'
import type { DomainRecord, SslEnvironment } from '@/api/domain'

// 数据
const list = ref<DomainRecord[]>([])
const loading = ref(false)
const envLoading = ref(false)
const installing = ref(false)
const syncing = ref(false)
const submitting = ref(false)
const dialogVisible = ref(false)
const formRef = ref<FormInstance>()
const actionLoading = reactive<Record<number, string>>({})

const env = reactive<SslEnvironment>({
  acme_sh: false,
  nginx: false,
  curl: false,
  openssl: false,
  ssl_dir: false,
  webroot_dir: false,
  sudoers: false,
  ready: false
})

const form = reactive({
  domain: '',
  type: 'admin',
  remark: ''
})

const formRules: FormRules = {
  domain: [
    { required: true, message: '请输入域名', trigger: 'blur' },
    {
      pattern: /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/,
      message: '域名格式不正确',
      trigger: 'blur'
    }
  ],
  type: [{ required: true, message: '请选择用途', trigger: 'change' }]
}

const typeTextMap: Record<string, string> = {
  admin: '管理后台',
  api: '开放API',
  ws: 'WebSocket'
}

const typeTagMap: Record<string, '' | 'success' | 'warning' | 'info' | 'danger'> = {
  admin: 'warning',
  api: 'success',
  ws: 'info'
}

// 加载域名列表
async function loadList() {
  loading.value = true
  try {
    const res = await getDomainListApi()
    list.value = res.data?.list || []
  } catch (e: any) {
    ElMessage.error(e.message || '加载失败')
  } finally {
    loading.value = false
  }
}

// 检查 SSL 环境
async function checkEnvironment() {
  envLoading.value = true
  try {
    const res = await getSslEnvironmentApi()
    Object.assign(env, res.data)
  } catch (e: any) {
    ElMessage.error(e.message || '环境检查失败')
  } finally {
    envLoading.value = false
  }
}

// 安装 acme.sh
async function installAcme() {
  try {
    await ElMessageBox.confirm(
      '将执行 acme.sh 安装脚本（需 root 权限），是否继续？',
      '安装确认',
      { type: 'warning' }
    )
  } catch {
    return
  }
  installing.value = true
  try {
    const res = await installAcmeApi()
    ElMessage.success(res.data?.message || '安装成功')
    await checkEnvironment()
  } catch (e: any) {
    ElMessage.error(e.message || '安装失败')
  } finally {
    installing.value = false
  }
}

// 打开添加弹窗
function openAddDialog() {
  form.domain = ''
  form.type = 'admin'
  form.remark = ''
  dialogVisible.value = true
}

// 提交添加
async function submitAdd() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      const res = await createDomainApi({
        domain: form.domain.toLowerCase().trim(),
        type: form.type,
        remark: form.remark
      })
      ElMessage.success(res.data?.message || '添加成功')
      dialogVisible.value = false
      await loadList()
    } catch (e: any) {
      ElMessage.error(e.message || '添加失败')
    } finally {
      submitting.value = false
    }
  })
}

// 设为主域名
async function setPrimary(row: DomainRecord) {
  try {
    await ElMessageBox.confirm(
      `确认将「${row.domain}」设为主域名？`,
      '设为主域名',
      { type: 'warning' }
    )
  } catch {
    return
  }
  try {
    await setPrimaryDomainApi(row.id)
    ElMessage.success('设置成功')
    await loadList()
  } catch (e: any) {
    ElMessage.error(e.message || '设置失败')
  }
}

// 切换状态
async function toggleStatus(row: DomainRecord, val: boolean) {
  try {
    await updateDomainApi(row.id, { status: val ? 1 : 0 })
    row.status = val ? 1 : 0
    ElMessage.success(val ? '已启用' : '已禁用')
  } catch (e: any) {
    ElMessage.error(e.message || '操作失败')
  }
}

// 申请 SSL 证书
async function applySsl(row: DomainRecord) {
  try {
    await ElMessageBox.confirm(
      `将为「${row.domain}」申请 Let's Encrypt 免费证书。\n请确保：\n1. 域名已解析到本服务器\n2. 80 端口可被外网访问\n3. Nginx 已运行\n\n是否继续？`,
      '申请 SSL 证书',
      { type: 'info', confirmButtonText: '开始申请' }
    )
  } catch {
    return
  }
  actionLoading[row.id] = 'ssl'
  try {
    ElMessage.info('证书申请中，请稍候...')
    const res = await applySslApi(row.id)
    ElMessage.success(res.data?.message || '证书申请成功')
    await loadList()
  } catch (e: any) {
    ElMessage.error(e.message || '证书申请失败')
    await loadList()
  } finally {
    delete actionLoading[row.id]
  }
}

// 部署 Nginx
async function deployNginx(row: DomainRecord) {
  actionLoading[row.id] = 'deploy'
  try {
    const res = await deployNginxApi(row.id)
    ElMessage.success(res.data?.message || 'Nginx 已部署')
    await loadList()
  } catch (e: any) {
    ElMessage.error(e.message || '部署失败')
  } finally {
    delete actionLoading[row.id]
  }
}

// 同步 Nginx
async function syncNginx() {
  syncing.value = true
  try {
    const res = await syncNginxApi()
    ElMessage.success(res.data?.message || '同步成功')
    await loadList()
  } catch (e: any) {
    ElMessage.error(e.message || '同步失败')
  } finally {
    syncing.value = false
  }
}

// 删除域名
async function remove(row: DomainRecord) {
  try {
    await ElMessageBox.confirm(
      `确认删除域名「${row.domain}」？关联的 SSL 证书也将被移除。`,
      '删除域名',
      { type: 'warning', confirmButtonText: '删除' }
    )
  } catch {
    return
  }
  try {
    await deleteDomainApi(row.id)
    ElMessage.success('删除成功')
    await loadList()
  } catch (e: any) {
    ElMessage.error(e.message || '删除失败')
  }
}

onMounted(() => {
  loadList()
  checkEnvironment()
})
</script>

<style scoped>
.domains-page {
  padding: 20px;
}

/* 页头复用 settings 风格 */
.page-hero {
  position: relative;
  overflow: hidden;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 16px;
  padding: 28px 32px;
  margin-bottom: 20px;
  color: #fff;
}
.hero-bg { position: absolute; inset: 0; overflow: hidden; }
.hero-blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(60px);
  opacity: 0.3;
}
.blob-a { width: 300px; height: 300px; background: #fff; top: -100px; right: -80px; }
.blob-b { width: 200px; height: 200px; background: #f093fb; bottom: -60px; left: 30%; }
.hero-content {
  position: relative;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
}
.hero-title { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.hero-sub { font-size: 14px; opacity: 0.9; margin: 0; }
.hero-stats { display: flex; align-items: center; gap: 24px; }
.stat-mini { display: flex; flex-direction: column; align-items: center; }
.stat-label { font-size: 12px; opacity: 0.8; }
.stat-value { font-size: 20px; font-weight: 700; display: flex; align-items: center; gap: 4px; }
.status-ok { color: #67c23a; }
.status-warn { color: #e6a23c; }
.stat-divider { width: 1px; height: 32px; background: rgba(255,255,255,0.3); }

/* 环境检查卡片 */
.env-card {
  background: #fff;
  border-radius: 12px;
  padding: 20px 24px;
  margin-bottom: 20px;
  box-shadow: 0 2px 12px rgba(0,0,0,0.04);
}
.env-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}
.env-title { display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 600; }
.env-icon { color: #409eff; }
.env-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
}
.env-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid #ebeef5;
}
.env-item.ok { background: #f0f9eb; border-color: #c2e7b0; }
.env-item.ok .el-icon { color: #67c23a; font-size: 18px; }
.env-item.fail { background: #fef0f0; border-color: #fbc4c4; }
.env-item.fail .el-icon { color: #f56c6c; font-size: 18px; }
.env-text { flex: 1; }
.env-name { font-size: 13px; font-weight: 600; color: #303133; }
.env-desc { font-size: 11px; color: #909399; }
.env-action { margin-top: 16px; }

/* 域名列表卡片 */
.domains-card {
  background: #fff;
  border-radius: 12px;
  padding: 20px 24px;
  box-shadow: 0 2px 12px rgba(0,0,0,0.04);
}
.card-head {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
}
.head-icon {
  width: 40px; height: 40px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; color: #fff;
}
.icon-domain { background: linear-gradient(135deg, #667eea, #764ba2); }
.head-text { flex: 1; }
.card-title { font-size: 16px; font-weight: 600; margin: 0; }
.card-sub { font-size: 12px; color: #909399; margin: 4px 0 0; }
.head-actions { display: flex; gap: 8px; }

.domain-cell { display: flex; align-items: center; gap: 8px; }
.domain-icon { color: #909399; }
.domain-name { font-weight: 500; }
.text-muted { color: #c0c4cc; }
.form-tip { font-size: 12px; color: #909399; margin-top: 4px; }
</style>
