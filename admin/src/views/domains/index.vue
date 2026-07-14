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
          <p class="hero-sub">独立绑定域名、独立端口访问、自动申请/续费 Let's Encrypt 证书</p>
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
        <div class="env-actions">
          <el-button :icon="RefreshIcon" size="small" @click="checkEnvironment">重新检查</el-button>
          <el-button
            type="warning"
            size="small"
            :icon="RefreshRightIcon"
            :loading="renewAllLoading"
            @click="renewAll"
          >
            批量续费
          </el-button>
        </div>
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
          <p class="card-sub">前端/后端可独立绑定域名与端口，支持 IP+端口 直连</p>
        </div>
        <div class="head-actions">
          <el-button :icon="RefreshIcon" @click="loadList">刷新</el-button>
          <el-button :icon="PromotionIcon" :loading="syncing" @click="syncNginx">同步 Nginx</el-button>
          <el-button type="primary" :icon="PlusIcon" @click="openAddDialog">添加域名</el-button>
        </div>
      </div>

      <el-table :data="list" stripe style="width: 100%">
        <el-table-column label="域名 / 端口" min-width="220">
          <template #default="{ row }">
            <div class="domain-cell">
              <el-icon class="domain-icon"><LinkIcon /></el-icon>
              <span class="domain-name">{{ row.domain }}</span>
              <el-tag v-if="row.listen_port > 0" type="primary" size="small">
                :{{ row.listen_port }}
              </el-tag>
              <el-tag v-else type="info" size="small">默认80/443</el-tag>
              <el-tag v-if="row.is_primary" type="warning" size="small" effect="dark">
                <el-icon><StarIcon /></el-icon> 主域名
              </el-tag>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="目标类型" width="110" align="center">
          <template #default="{ row }">
            <el-tag :type="targetTypeTagMap[row.target_type]" size="small">
              {{ targetTypeTextMap[row.target_type] || row.target_type }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="后端目标" min-width="140">
          <template #default="{ row }">
            <span v-if="row.target_type === 'frontend'" class="text-muted">-</span>
            <span v-else class="mono-text">{{ row.target_host }}</span>
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

        <el-table-column label="证书过期" min-width="170">
          <template #default="{ row }">
            <template v-if="row.ssl_expire_at">
              <div>{{ row.ssl_expire_at }}</div>
              <el-text
                v-if="row.days_left !== undefined"
                :type="row.days_left < 7 ? 'danger' : row.days_left < 30 ? 'warning' : 'success'"
                size="small"
              >
                剩余 {{ row.days_left }} 天
              </el-text>
            </template>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>

        <el-table-column label="自动续费" width="90" align="center">
          <template #default="{ row }">
            <el-switch
              :model-value="row.ssl_auto_renew === 1"
              :disabled="row.ssl_status !== 'issued'"
              @change="(val: boolean) => toggleAutoRenew(row, val)"
            />
          </template>
        </el-table-column>

        <el-table-column label="Nginx" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="row.nginx_deployed ? 'success' : 'info'" size="small">
              {{ row.nginx_deployed ? '已部署' : '未部署' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="70" align="center">
          <template #default="{ row }">
            <el-switch
              :model-value="row.status === 1"
              @change="(val: boolean) => toggleStatus(row, val)"
            />
          </template>
        </el-table-column>

        <el-table-column label="操作" width="340" fixed="right">
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
              v-if="row.ssl_status === 'issued' || row.ssl_status === 'expired'"
              link
              type="primary"
              size="small"
              :icon="RefreshRightIcon"
              :loading="actionLoading[row.id] === 'renew'"
              @click="renewSsl(row)"
            >
              续费
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

    <!-- 添加/编辑域名弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="editingId ? '编辑域名' : '添加域名'"
      width="520px"
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

        <el-form-item label="目标类型" prop="target_type">
          <el-radio-group v-model="form.target_type">
            <el-radio-button value="frontend">管理后台（前端）</el-radio-button>
            <el-radio-button value="backend">后端 API</el-radio-button>
            <el-radio-button value="ws">WebSocket</el-radio-button>
            <el-radio-button value="all">全部（前端+后端+WS）</el-radio-button>
          </el-radio-group>
          <div class="form-tip">
            <span v-if="form.target_type === 'frontend'">仅提供管理后台静态文件 + /api/admin/ 代理</span>
            <span v-else-if="form.target_type === 'backend'">仅提供 /api/ /admin /auth /captcha /health 代理</span>
            <span v-else-if="form.target_type === 'ws'">仅提供 /ws WebSocket 代理</span>
            <span v-else>前端+后端+WebSocket 全部代理（最常用）</span>
          </div>
        </el-form-item>

        <div class="form-row">
          <el-form-item label="监听端口" prop="listen_port">
            <el-input-number
              v-model="form.listen_port"
              :min="0"
              :max="65535"
              controls-position="right"
              style="width: 100%"
            />
            <div class="form-tip">0=默认（80/443），>0=指定端口</div>
          </el-form-item>
          <el-form-item v-if="form.target_type !== 'frontend'" label="后端目标地址" prop="target_host">
            <el-input
              v-model="form.target_host"
              placeholder="127.0.0.1:9501"
              clearable
            />
            <div class="form-tip">后端 API 地址（IP:端口）</div>
          </el-form-item>
        </div>

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
  RefreshRight as RefreshRightIcon,
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
  updateDomainApi,
  deleteDomainApi,
  setPrimaryDomainApi,
  applySslApi,
  renewSslApi,
  deployNginxApi,
  syncNginxApi,
  renewAllApi,
  toggleAutoRenewApi,
  getSslEnvironmentApi,
  installAcmeApi
} from '@/api/domain'
import type { DomainRecord, SslEnvironment, TargetType } from '@/api/domain'

// 数据
const list = ref<DomainRecord[]>([])
const loading = ref(false)
const envLoading = ref(false)
const installing = ref(false)
const syncing = ref(false)
const submitting = ref(false)
const renewAllLoading = ref(false)
const dialogVisible = ref(false)
const editingId = ref<number>(0)
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
  target_type: 'all' as TargetType,
  listen_port: 0,
  target_host: '127.0.0.1:9501',
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
  target_type: [{ required: true, message: '请选择目标类型', trigger: 'change' }]
}

const targetTypeTextMap: Record<string, string> = {
  frontend: '管理后台',
  backend: '后端API',
  ws: 'WebSocket',
  all: '全部'
}

const targetTypeTagMap: Record<string, '' | 'success' | 'warning' | 'info' | 'danger'> = {
  frontend: 'warning',
  backend: 'success',
  ws: 'info',
  all: 'danger'
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
  editingId.value = 0
  form.domain = ''
  form.target_type = 'all'
  form.listen_port = 0
  form.target_host = '127.0.0.1:9501'
  form.remark = ''
  dialogVisible.value = true
}

// 提交添加/编辑
async function submitAdd() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      const payload = {
        domain: form.domain.toLowerCase().trim(),
        target_type: form.target_type,
        listen_port: form.listen_port,
        target_host: form.target_host,
        remark: form.remark
      }
      if (editingId.value) {
        await updateDomainApi(editingId.value, payload)
        ElMessage.success('更新成功')
      } else {
        const res = await createDomainApi(payload)
        ElMessage.success(res.data?.message || '添加成功')
      }
      dialogVisible.value = false
      await loadList()
    } catch (e: any) {
      ElMessage.error(e.message || '操作失败')
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

// 切换自动续费
async function toggleAutoRenew(row: DomainRecord, val: boolean) {
  try {
    const res = await toggleAutoRenewApi(row.id, val)
    row.ssl_auto_renew = val ? 1 : 0
    ElMessage.success(res.data?.message || '操作成功')
  } catch (e: any) {
    ElMessage.error(e.message || '操作失败')
  }
}

// 申请 SSL 证书
async function applySsl(row: DomainRecord) {
  try {
    await ElMessageBox.confirm(
      `将为「${row.domain}」申请 Let's Encrypt 免费证书。\n\n请确保：\n1. 域名已解析到本服务器\n2. 80 端口可被外网访问（ACME 验证用）\n3. Nginx 已运行\n\n是否继续？`,
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

// 续费 SSL 证书
async function renewSsl(row: DomainRecord) {
  actionLoading[row.id] = 'renew'
  try {
    ElMessage.info('证书续费中，请稍候...')
    const res = await renewSslApi(row.id)
    ElMessage.success(res.data?.message || '证书续费成功')
    await loadList()
  } catch (e: any) {
    ElMessage.error(e.message || '证书续费失败')
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

// 批量续费
async function renewAll() {
  try {
    await ElMessageBox.confirm(
      '将批量续费所有 30 天内即将过期的证书，是否继续？',
      '批量续费',
      { type: 'info', confirmButtonText: '开始续费' }
    )
  } catch {
    return
  }
  renewAllLoading.value = true
  try {
    ElMessage.info('批量续费中，请稍候...')
    const res = await renewAllApi()
    ElMessage.success(res.data?.message || '批量续费完成')
    await loadList()
  } catch (e: any) {
    ElMessage.error(e.message || '批量续费失败')
  } finally {
    renewAllLoading.value = false
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
.env-actions { display: flex; gap: 8px; }
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

.domain-cell { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.domain-icon { color: #909399; }
.domain-name { font-weight: 500; }
.text-muted { color: #c0c4cc; }
.mono-text { font-family: 'Courier New', monospace; font-size: 12px; color: #606266; }
.form-tip { font-size: 12px; color: #909399; margin-top: 4px; }
.form-row { display: flex; gap: 12px; }
.form-row > * { flex: 1; }
</style>
