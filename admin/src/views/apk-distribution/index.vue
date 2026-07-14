<template>
  <div class="page-container apk-distribution-page">
    <!-- 顶部标题区 -->
    <div class="page-hero">
      <div class="hero-bg">
        <div class="hero-blob blob-a"></div>
        <div class="hero-blob blob-b"></div>
        <div class="hero-grid"></div>
      </div>
      <div class="hero-content">
        <div class="hero-left">
          <h2 class="hero-title">
            <span class="title-gradient">APK 分发管理</span>
          </h2>
          <p class="hero-sub">管理 APK 安装包分发，支持蓝奏云、自定义上传与二维码扫码下载</p>
        </div>
        <div class="hero-actions">
          <el-input
            v-model="query.keyword"
            placeholder="搜索应用名称 / 构建ID"
            :prefix-icon="SearchIcon"
            clearable
            class="search-input"
            @keyup.enter="handleSearch"
          />
          <el-button type="primary" :icon="SearchIcon" @click="handleSearch">查询</el-button>
          <el-button
            class="settings-btn"
            type="primary"
            plain
            :icon="SettingIcon"
            @click="openSettingsDrawer"
          >
            分发设置
          </el-button>
        </div>
      </div>
    </div>

    <!-- 分发记录列表 -->
    <el-card class="table-card" shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        style="width: 100%"
        row-key="id"
        stripe
      >
        <el-table-column type="index" label="#" width="60" align="center" />
        <el-table-column prop="app_name" label="应用名称" min-width="160">
          <template #default="{ row }">
            <div class="name-cell">
              <div class="name-icon">
                <el-icon><CellphoneIcon /></el-icon>
              </div>
              <div class="name-info">
                <span class="name-text">{{ row.app_name }}</span>
                <span class="name-sub">v{{ row.version_name }}</span>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="package_name" label="包名" min-width="180">
          <template #default="{ row }">
            <span class="mono-text">{{ row.package_name || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="version_name" label="版本" width="100" align="center">
          <template #default="{ row }">
            <el-tag type="primary" effect="plain" size="small" round>
              {{ row.version_name || '-' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="文件大小" width="110" align="center">
          <template #default="{ row }">
            <span class="size-text">{{ row.apk_size_text || formatSize(row.apk_size) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="MD5" width="120" align="center">
          <template #default="{ row }">
            <span class="mono-text md5-text" :title="row.md5">
              {{ row.md5 ? row.md5.slice(0, 8) : '-' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="上传状态" width="120" align="center">
          <template #default="{ row }">
            <el-tag
              :type="statusTagType(row.upload_status)"
              effect="light"
              round
              size="small"
            >
              <el-icon
                v-if="row.upload_status === 'uploading'"
                class="loading-icon"
              >
                <LoadingIcon />
              </el-icon>
              {{ statusLabel(row.upload_status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="下载链接" min-width="200">
          <template #default="{ row }">
            <div class="links-cell">
              <div v-if="row.lanzou_url" class="link-row">
                <el-icon class="link-icon lanzou"><LinkIcon /></el-icon>
                <a :href="row.lanzou_url" target="_blank" rel="noopener" class="link-text">
                  蓝奏云下载
                </a>
                <el-tag
                  v-if="row.lanzou_password"
                  type="warning"
                  size="small"
                  effect="plain"
                  class="pwd-tag"
                >
                  密码: {{ row.lanzou_password }}
                </el-tag>
              </div>
              <div v-if="row.custom_url" class="link-row">
                <el-icon class="link-icon custom"><LinkIcon /></el-icon>
                <a :href="row.custom_url" target="_blank" rel="noopener" class="link-text">
                  自定义下载
                </a>
              </div>
              <span v-if="!row.lanzou_url && !row.custom_url" class="link-empty">-</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">
            <span class="time-text">{{ formatTime(row.created_at) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="280" fixed="right">
          <template #default="{ row }">
            <el-button
              link
              type="primary"
              :icon="DownloadIcon"
              @click="handleDownload(row as ApkDistributionRecord)"
            >
              下载
            </el-button>
            <el-button
              link
              type="success"
              :icon="GridIcon"
              @click="openQrDialog(row as ApkDistributionRecord)"
            >
              二维码
            </el-button>
            <el-button
              v-if="!row.lanzou_url"
              link
              type="warning"
              :icon="UploadIcon"
              :loading="uploadingId === row.id && uploadType === 'lanzou'"
              :disabled="row.upload_status === 'uploading'"
              @click="handleUploadLanzou(row as ApkDistributionRecord)"
            >
              上传蓝奏云
            </el-button>
            <el-button
              v-if="!row.custom_url"
              link
              type="info"
              :icon="UploadIcon"
              :loading="uploadingId === row.id && uploadType === 'custom'"
              :disabled="row.upload_status === 'uploading'"
              @click="handleUploadCustom(row as ApkDistributionRecord)"
            >
              自定义上传
            </el-button>
            <el-popconfirm
              title="确定删除该分发记录吗？"
              confirm-button-text="删除"
              cancel-button-text="取消"
              @confirm="handleDelete(row as ApkDistributionRecord)"
            >
              <template #reference>
                <el-button link type="danger" :icon="DeleteIcon">删除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="query.page"
          :page-size="query.pageSize"
          :page-sizes="[10, 20, 50]"
          :total="total"
          layout="total, sizes, prev, pager, next, jumper"
          background
          @size-change="handleSizeChange"
          @current-change="fetchData"
        />
      </div>
    </el-card>

    <!-- 分发设置抽屉 -->
    <el-drawer
      v-model="settingsDrawerVisible"
      title="分发设置"
      direction="rtl"
      size="480px"
      class="settings-drawer"
    >
      <template #header>
        <div class="drawer-header">
          <div class="drawer-title">
            <el-icon class="title-icon"><SettingIcon /></el-icon>
            <span>分发设置</span>
          </div>
        </div>
      </template>

      <div v-loading="configLoading" class="settings-body">
        <el-form label-position="top" class="settings-form">
          <el-form-item label="启用自动分发">
            <el-switch v-model="config.enabled" />
            <div class="form-tip">构建成功后自动生成分发记录</div>
          </el-form-item>

          <el-form-item label="蓝奏云 Cookie">
            <el-input
              v-model="config.lanzou_cookie"
              type="textarea"
              :rows="4"
              placeholder="从浏览器开发者工具获取"
            />
            <div class="form-tip">登录蓝奏云后，从浏览器开发者工具 Network 中复制 Cookie</div>
          </el-form-item>

          <el-form-item label="自定义上传脚本路径">
            <el-input
              v-model="config.custom_script"
              placeholder="填写可执行脚本路径"
              :prefix-icon="DocumentIcon"
              clearable
            />
            <div class="form-tip">脚本接收 APK 路径作为参数，输出下载链接</div>
          </el-form-item>

          <el-form-item label="下载基础 URL">
            <el-input
              v-model="config.base_url"
              placeholder="用于生成完整下载链接，留空则使用当前域名"
              :prefix-icon="LinkIcon"
              clearable
            />
            <div class="form-tip">例如：https://download.example.com</div>
          </el-form-item>
        </el-form>
      </div>

      <template #footer>
        <div class="drawer-footer">
          <el-button @click="settingsDrawerVisible = false">取消</el-button>
          <el-button type="primary" :loading="configSaving" @click="saveConfig">
            保存
          </el-button>
        </div>
      </template>
    </el-drawer>

    <!-- 二维码对话框 -->
    <el-dialog
      v-model="qrDialogVisible"
      title="扫码下载 APP"
      width="400px"
      align-center
      class="qr-dialog"
    >
      <div class="qr-content">
        <div class="qr-tip">
          <el-icon><CellphoneIcon /></el-icon>
          <span>扫码下载 APP</span>
        </div>
        <div class="qr-canvas-wrap">
          <canvas ref="qrCanvasRef" class="qr-canvas"></canvas>
          <div v-if="qrLoading" class="qr-loading">
            <el-icon class="loading-icon"><LoadingIcon /></el-icon>
            <span>生成中...</span>
          </div>
        </div>
        <div class="qr-link-box">
          <span class="qr-link-label">下载链接：</span>
          <code class="qr-link-text">{{ currentQrUrl }}</code>
          <el-button link :icon="CopyDocumentIcon" @click="copyLink(currentQrUrl)">
            复制
          </el-button>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  Search as SearchIcon,
  Setting as SettingIcon,
  Cellphone as CellphoneIcon,
  Download as DownloadIcon,
  Grid as GridIcon,
  Upload as UploadIcon,
  Delete as DeleteIcon,
  Link as LinkIcon,
  Document as DocumentIcon,
  CopyDocument as CopyDocumentIcon,
  Loading as LoadingIcon
} from '@element-plus/icons-vue'
import {
  getDistributionListApi,
  getDistributionConfigApi,
  saveDistributionConfigApi,
  uploadToLanzouApi,
  uploadCustomApi,
  deleteDistributionApi,
  type ApkDistributionRecord,
  type ApkDistributionConfig
} from '@/api/apkDistribution'

// ---- 列表数据 ----
const loading = ref(false)
const tableData = ref<ApkDistributionRecord[]>([])
const total = ref(0)
const query = reactive({
  page: 1,
  pageSize: 10,
  keyword: ''
})

async function fetchData() {
  loading.value = true
  try {
    const res = await getDistributionListApi({
      page: query.page,
      keyword: query.keyword
    })
    const data: any = res.data
    tableData.value = data?.list || []
    total.value = data?.total || 0
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

function handleSizeChange(size: number) {
  query.pageSize = size
  query.page = 1
  fetchData()
}

// ---- 状态映射 ----
function statusTagType(
  status: string
): 'primary' | 'success' | 'warning' | 'info' | 'danger' {
  const map: Record<string, 'primary' | 'success' | 'warning' | 'info' | 'danger'> = {
    pending: 'info',
    uploading: 'warning',
    success: 'success',
    failed: 'danger',
    disabled: 'info'
  }
  return map[status] || 'info'
}

function statusLabel(status: string): string {
  const map: Record<string, string> = {
    pending: '待上传',
    uploading: '上传中',
    success: '已上传',
    failed: '失败',
    disabled: '已禁用'
  }
  return map[status] || status
}

function formatTime(t: string): string {
  if (!t) return '-'
  return t.replace('T', ' ').slice(0, 19)
}

function formatSize(bytes: number): string {
  if (!bytes) return '-'
  const mb = bytes / (1024 * 1024)
  if (mb >= 1) return `${mb.toFixed(2)} MB`
  const kb = bytes / 1024
  return `${kb.toFixed(1)} KB`
}

// ---- 下载链接 ----
function getDownloadUrl(row: ApkDistributionRecord): string {
  // 优先使用 base_url，否则使用当前域名
  const base = config.base_url || window.location.origin
  // self_hosted_url 形如 /api/apk-distribution/download/{token}
  if (row.self_hosted_url) {
    return row.self_hosted_url.startsWith('http')
      ? row.self_hosted_url
      : `${base}${row.self_hosted_url}`
  }
  // 兜底使用 token 拼接
  return `${base}/api/apk-distribution/download/${row.download_token}`
}

function handleDownload(row: ApkDistributionRecord) {
  const url = getDownloadUrl(row)
  window.open(url, '_blank')
}

// ---- 上传操作 ----
const uploadingId = ref<number | null>(null)
const uploadType = ref<'lanzou' | 'custom' | ''>('')

async function handleUploadLanzou(row: ApkDistributionRecord) {
  uploadingId.value = row.id
  uploadType.value = 'lanzou'
  try {
    await uploadToLanzouApi(row.id)
    ElMessage.success('已上传至蓝奏云')
    await fetchData()
  } catch (err: any) {
    ElMessage.error(err?.message || '上传蓝奏云失败')
  } finally {
    uploadingId.value = null
    uploadType.value = ''
  }
}

async function handleUploadCustom(row: ApkDistributionRecord) {
  uploadingId.value = row.id
  uploadType.value = 'custom'
  try {
    await uploadCustomApi(row.id)
    ElMessage.success('自定义上传成功')
    await fetchData()
  } catch (err: any) {
    ElMessage.error(err?.message || '自定义上传失败')
  } finally {
    uploadingId.value = null
    uploadType.value = ''
  }
}

// ---- 删除 ----
async function handleDelete(row: ApkDistributionRecord) {
  try {
    await deleteDistributionApi(row.id)
    ElMessage.success('删除成功')
    // 若当前页删空且非首页，回退一页
    if (tableData.value.length === 1 && query.page > 1) {
      query.page -= 1
    }
    await fetchData()
  } catch (err: any) {
    ElMessage.error(err?.message || '删除失败')
  }
}

// ---- 分发设置抽屉 ----
const settingsDrawerVisible = ref(false)
const configLoading = ref(false)
const configSaving = ref(false)
const config = reactive<ApkDistributionConfig>({
  enabled: false,
  lanzou_cookie: '',
  custom_script: '',
  base_url: ''
})

async function openSettingsDrawer() {
  settingsDrawerVisible.value = true
  configLoading.value = true
  try {
    const res = await getDistributionConfigApi()
    const data: any = res.data
    if (data) {
      config.enabled = !!data.enabled
      config.lanzou_cookie = data.lanzou_cookie || ''
      config.custom_script = data.custom_script || ''
      config.base_url = data.base_url || ''
    }
  } catch {
    // 接口未就绪时使用默认值
  } finally {
    configLoading.value = false
  }
}

async function saveConfig() {
  configSaving.value = true
  try {
    await saveDistributionConfigApi({
      enabled: config.enabled,
      lanzou_cookie: config.lanzou_cookie,
      custom_script: config.custom_script,
      base_url: config.base_url
    })
    ElMessage.success('保存成功')
    settingsDrawerVisible.value = false
  } catch (err: any) {
    ElMessage.error(err?.message || '保存失败')
  } finally {
    configSaving.value = false
  }
}

// ---- 二维码对话框 ----
const qrDialogVisible = ref(false)
const qrCanvasRef = ref<HTMLCanvasElement | null>(null)
const qrLoading = ref(false)
const currentQrUrl = ref('')
let qrScriptLoaded = false

function loadQrScript(): Promise<void> {
  if (qrScriptLoaded && (window as any).QRCode) {
    return Promise.resolve()
  }
  return new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = 'https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js'
    script.onload = () => {
      qrScriptLoaded = true
      resolve()
    }
    script.onerror = () => reject(new Error('加载二维码脚本失败'))
    document.head.appendChild(script)
  })
}

async function openQrDialog(row: ApkDistributionRecord) {
  currentQrUrl.value = getDownloadUrl(row)
  qrDialogVisible.value = true
  qrLoading.value = true
  try {
    await loadQrScript()
    // 等待 canvas 渲染
    await new Promise((resolve) => setTimeout(resolve, 50))
    const QRCode = (window as any).QRCode
    if (QRCode && qrCanvasRef.value) {
      QRCode.toCanvas(qrCanvasRef.value, currentQrUrl.value, {
        width: 240,
        margin: 2,
        color: {
          dark: '#1a1a2e',
          light: '#ffffff'
        }
      })
    }
  } catch (err: any) {
    ElMessage.error(err?.message || '二维码生成失败')
  } finally {
    qrLoading.value = false
  }
}

// ---- 复制链接 ----
async function copyLink(text: string) {
  if (!text) return
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制下载链接')
  } catch {
    // 降级方案
    const textarea = document.createElement('textarea')
    textarea.value = text
    document.body.appendChild(textarea)
    textarea.select()
    try {
      document.execCommand('copy')
      ElMessage.success('已复制下载链接')
    } catch {
      ElMessage.warning('复制失败，请手动复制')
    }
    document.body.removeChild(textarea)
  }
}

// ---- 初始化 ----
onMounted(() => {
  fetchData()
})
</script>

<style lang="scss" scoped>
.apk-distribution-page {
  animation: fade-up 0.5s ease;
}

// ===== 顶部 Hero 区 =====
.page-hero {
  position: relative;
  border-radius: $radius-xl;
  padding: 28px 32px;
  margin-bottom: 20px;
  overflow: hidden;
  background: $gradient-primary;
  box-shadow: $shadow-primary;

  .hero-bg {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
  }

  .hero-blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(60px);
    opacity: 0.55;

    &.blob-a {
      width: 280px;
      height: 280px;
      background: radial-gradient(circle, #ffffff, transparent 70%);
      top: -120px;
      right: -80px;
      opacity: 0.25;
    }
    &.blob-b {
      width: 240px;
      height: 240px;
      background: radial-gradient(circle, #5cb8ff, transparent 70%);
      bottom: -100px;
      left: 30%;
      opacity: 0.4;
    }
  }

  .hero-grid {
    position: absolute;
    inset: 0;
    background-image: linear-gradient(rgba(255, 255, 255, 0.1) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(255, 255, 255, 0.1) 1px, transparent 1px);
    background-size: 28px 28px;
    mask-image: linear-gradient(135deg, black, transparent 80%);
  }

  .hero-content {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
  }

  .hero-left {
    .hero-title {
      margin: 0;
      .title-gradient {
        font-size: 26px;
        font-weight: 800;
        color: #fff;
        letter-spacing: -0.3px;
        text-shadow: 0 2px 12px rgba(0, 0, 0, 0.15);
      }
    }
    .hero-sub {
      margin: 8px 0 0;
      color: rgba(255, 255, 255, 0.85);
      font-size: 14px;
    }
  }

  .hero-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;

    .search-input {
      width: 260px;
    }

    .settings-btn {
      font-weight: 600;
    }
  }
}

// ===== 表格卡片 =====
.table-card {
  border-radius: $radius-xl;
  border: 1px solid var(--border-light);
  box-shadow: $shadow-md;
  overflow: hidden;
  background: var(--bg-card);

  :deep(.el-card__body) {
    padding: 20px;
  }
}

.name-cell {
  display: flex;
  align-items: center;
  gap: 10px;

  .name-icon {
    width: 36px;
    height: 36px;
    border-radius: $radius-sm;
    background: $gradient-primary;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 18px;
    flex-shrink: 0;
    box-shadow: 0 4px 10px rgba(109, 92, 255, 0.25);
  }

  .name-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .name-text {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 14px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .name-sub {
    font-size: 12px;
    color: var(--text-secondary);
  }
}

.mono-text {
  font-family: $font-family-mono;
  font-size: 12px;
  color: var(--text-regular);
}

.md5-text {
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.size-text {
  font-size: 13px;
  color: var(--text-regular);
  font-weight: 500;
}

.time-text {
  font-size: 12px;
  color: var(--text-secondary);
}

.loading-icon {
  animation: rotating 1.2s linear infinite;
  margin-right: 2px;
}

// 下载链接单元格
.links-cell {
  display: flex;
  flex-direction: column;
  gap: 6px;

  .link-row {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }

  .link-icon {
    font-size: 14px;
    flex-shrink: 0;

    &.lanzou {
      color: #ff9500;
    }
    &.custom {
      color: $color-primary;
    }
  }

  .link-text {
    color: $color-primary;
    font-size: 13px;
    text-decoration: none;
    transition: opacity 0.2s ease;

    &:hover {
      opacity: 0.75;
      text-decoration: underline;
    }
  }

  .pwd-tag {
    font-size: 11px;
  }

  .link-empty {
    color: var(--text-secondary);
    font-size: 13px;
  }
}

// 分页
.pagination-wrapper {
  display: flex;
  justify-content: flex-end;
  padding-top: 16px;
}

// ===== 设置抽屉 =====
.settings-drawer {
  :deep(.el-drawer__header) {
    margin-bottom: 0;
    padding: 18px 24px;
    border-bottom: 1px solid var(--border-light);
  }
  :deep(.el-drawer__body) {
    padding: 0;
  }
  :deep(.el-drawer__footer) {
    border-top: 1px solid var(--border-light);
    padding: 16px 24px;
  }
}

.drawer-header {
  display: flex;
  align-items: center;

  .drawer-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);

    .title-icon {
      color: $color-primary;
      font-size: 18px;
    }
  }
}

.settings-body {
  padding: 24px;
}

.settings-form {
  :deep(.el-form-item__label) {
    font-weight: 600;
    color: var(--text-regular);
    padding-bottom: 6px;
  }

  .form-tip {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 4px;
  }
}

.drawer-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

// ===== 二维码对话框 =====
.qr-dialog {
  :deep(.el-dialog__body) {
    padding: 24px;
  }
}

.qr-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 18px;

  .qr-tip {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);

    .el-icon {
      font-size: 18px;
      color: $color-primary;
    }
  }

  .qr-canvas-wrap {
    position: relative;
    width: 240px;
    height: 240px;
    border-radius: $radius-md;
    overflow: hidden;
    border: 1px solid var(--border-light);
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;

    .qr-canvas {
      width: 240px;
      height: 240px;
    }

    .qr-loading {
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: var(--bg-card);
      color: var(--text-secondary);
      font-size: 13px;

      .loading-icon {
        font-size: 24px;
        color: $color-primary;
      }
    }
  }

  .qr-link-box {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 10px 12px;
    background: var(--bg-page);
    border-radius: $radius-sm;
    border: 1px solid var(--border-light);

    .qr-link-label {
      font-size: 12px;
      color: var(--text-secondary);
      flex-shrink: 0;
    }

    .qr-link-text {
      flex: 1;
      font-family: $font-family-mono;
      font-size: 11px;
      color: var(--text-regular);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      word-break: break-all;
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

@keyframes rotating {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

// ===== 响应式 =====
@media (max-width: 1100px) {
  .page-hero .hero-actions .search-input {
    width: 200px;
  }
}

@media (max-width: 768px) {
  .page-hero {
    padding: 20px;

    .hero-content {
      flex-direction: column;
      align-items: flex-start;
    }

    .hero-actions {
      width: 100%;
      .search-input {
        width: 100%;
        flex: 1;
      }
    }
  }
}

// ===== 暗色模式 =====
:global(html.dark) {
  .page-hero {
    background: linear-gradient(135deg, #4d38f0 0%, #7d4dff 100%);
  }
  .qr-canvas-wrap {
    background: #fff;
  }
}
</style>
