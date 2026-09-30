<template>
  <div class="page-container app-build-page">
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
            <span class="title-gradient">APP 在线构建</span>
          </h2>
          <p class="hero-sub">配置应用参数，生成工程包后导入 HBuilderX 云打包出 APK</p>
        </div>
      </div>
    </div>

    <!-- 主体：配置表单 -->
    <div class="build-grid">
      <!-- 左侧：配置表单 -->
      <div class="form-card">
        <div class="card-header-row">
          <div class="header-icon">
            <el-icon><EditPenIcon /></el-icon>
          </div>
          <div>
            <h3 class="card-title">应用配置</h3>
            <p class="card-sub">填写打包所需的应用信息</p>
          </div>
        </div>

        <el-form
          ref="formRef"
          :model="form"
          :rules="rules"
          label-position="top"
          class="build-form"
        >
          <!-- 应用名称 + 随机按钮 -->
          <el-form-item label="应用名称" prop="name">
            <div class="input-with-action">
              <el-input
                v-model="form.name"
                placeholder="例如：Push 推送客户端"
                :prefix-icon="CellphoneIcon"
                clearable
              />
              <el-button
                type="primary"
                plain
                :icon="MagicStickIcon"
                @click="randomizeName"
                title="随机名称"
              >
                随机
              </el-button>
            </div>
          </el-form-item>

          <!-- 包名 + 随机按钮 -->
          <el-form-item label="应用包名" prop="packageName">
            <div class="input-with-action">
              <el-input
                v-model="form.packageName"
                placeholder="例如：com.push.app"
                :prefix-icon="BoxIcon"
                clearable
              />
              <el-button
                type="primary"
                plain
                :icon="MagicStickIcon"
                @click="randomizePackageName"
                title="随机包名"
              >
                随机
              </el-button>
            </div>
            <div class="form-tip">
              包名需符合 Java 规范，如 com.example.app
            </div>
          </el-form-item>

          <!-- 默认 Key -->
          <el-form-item label="默认 Key" prop="defaultKey">
            <el-select
              v-model="form.defaultKey"
              placeholder="选择已有 Key 或输入新 Key，如 my_app_key"
              filterable
              allow-create
              default-first-option
              style="width: 100%"
              :prefix-icon="KeyIconComp"
            >
              <el-option
                v-for="k in keyOptions"
                :key="k.value"
                :label="k.label"
                :value="k.value"
              />
            </el-select>
            <div class="form-tip">
              APP 首次启动默认使用的推送 Key，可在「Key 管理」中预先创建。案例：<code>my_app_key</code>、<code>android_v2</code>
            </div>
          </el-form-item>

          <!-- 服务器地址 + WebSocket 地址 -->
          <div class="form-row">
            <el-form-item label="服务器地址（HTTP API）" prop="serverAddress">
              <el-input
                v-model="form.serverAddress"
                placeholder="例如：http://192.168.1.10:9501 或 https://api.push.com"
                :prefix-icon="LinkIcon"
                clearable
              />
              <div class="form-tip">
                根据系统设置的 HTTP 端口自动填写（含端口号）。案例：<code>http://192.168.1.10:9501</code>、<code>https://api.push.com</code>
              </div>
            </el-form-item>
            <el-form-item label="WebSocket 地址" prop="websocketAddress">
              <el-input
                v-model="form.websocketAddress"
                placeholder="例如：ws://192.168.1.10:9502 或 wss://ws.push.com"
                :prefix-icon="ConnectionIcon"
                clearable
              />
              <div class="form-tip">
                根据系统设置的 WebSocket 端口自动填写。案例：<code>ws://192.168.1.10:9502</code>、<code>wss://ws.push.com</code>
              </div>
            </el-form-item>
          </div>

          <!-- 应用图标 -->
          <el-form-item label="应用图标" prop="appIcon">
            <div class="icon-section">
              <div class="icon-mode-tabs">
                <div
                  class="mode-tab"
                  :class="{ active: iconMode === 'upload' }"
                  @click="iconMode = 'upload'"
                >
                  <el-icon><PictureIcon /></el-icon>
                  <span>自定义上传</span>
                </div>
                <div
                  class="mode-tab"
                  :class="{ active: iconMode === 'auto' }"
                  @click="iconMode = 'auto'"
                >
                  <el-icon><BrushIcon /></el-icon>
                  <span>自动生成</span>
                </div>
              </div>

              <div v-if="iconMode === 'upload'" class="icon-uploader-wrap">
                <el-upload
                  ref="uploadRef"
                  class="icon-uploader"
                  :show-file-list="false"
                  :auto-upload="false"
                  :on-change="handleIconChange"
                  accept="image/png,image/jpeg,image/svg+xml"
                >
                  <div v-if="form.appIcon" class="icon-preview">
                    <img :src="form.appIcon" alt="应用图标" />
                    <div class="icon-mask">
                      <el-icon><RefreshIcon /></el-icon>
                      <span>更换</span>
                    </div>
                  </div>
                  <div v-else class="icon-placeholder">
                    <el-icon class="upload-icon"><PlusIcon /></el-icon>
                    <span>上传图标</span>
                  </div>
                </el-upload>
                <div class="icon-tips">
                  <p>建议尺寸 512×512</p>
                  <p>支持 PNG / JPG / SVG</p>
                  <el-button
                    v-if="form.appIcon"
                    link
                    type="danger"
                    :icon="DeleteIcon"
                    @click="form.appIcon = ''"
                  >
                    移除
                  </el-button>
                </div>
              </div>

              <div v-else class="icon-auto-wrap">
                <div class="icon-preview-auto" :style="iconGradientStyle">
                  <span class="icon-char">{{ iconChar }}</span>
                </div>
                <div class="icon-tips">
                  <p>首字 + 渐变色风格</p>
                  <p>根据应用名称自动生成</p>
                  <el-button
                    link
                    type="primary"
                    :icon="RefreshIcon"
                    :loading="generatingIcon"
                    @click="generateIcon"
                  >
                    换一个
                  </el-button>
                </div>
              </div>
            </div>
          </el-form-item>

          <!-- 版本号 + 版本码 -->
          <div class="form-row">
            <el-form-item label="应用版本号" prop="version">
              <el-input
                v-model="form.version"
                placeholder="例如：2.5.0"
                :prefix-icon="PriceTagIcon"
                clearable
              />
            </el-form-item>
            <el-form-item label="打包方式" prop="buildMethod">
              <el-radio-group v-model="form.buildMethod" class="platform-radio">
                <el-radio-button value="hbuilderx">
                  <el-icon><MagicStickIcon /></el-icon>
                  HBuilderX
                </el-radio-button>
                <el-radio-button value="compose">
                  <el-icon><MagicStickIcon /></el-icon>
                  玻璃拟态全新 UI
                </el-radio-button>
              </el-radio-group>
            </el-form-item>
            <!-- HBuilderX 模板选择 -->
            <el-form-item v-if="form.buildMethod === 'hbuilderx'" label="APP 模板">
              <div class="hbx-tpl-list">
                <div
                  v-for="tpl in hbuilderxTemplates"
                  :key="tpl.id"
                  class="hbx-tpl-item"
                  :class="{ active: form.hbuilderxTemplate === tpl.id, disabled: !tpl.available }"
                  @click="tpl.available && (form.hbuilderxTemplate = tpl.id)"
                >
                  <div class="hbx-tpl-radio">
                    <span class="radio-outer">
                      <span v-if="form.hbuilderxTemplate === tpl.id" class="radio-inner"></span>
                    </span>
                  </div>
                  <div class="hbx-tpl-info">
                    <div class="hbx-tpl-name">
                      {{ tpl.name }}
                      <el-tag v-if="tpl.id === 'new'" size="small" type="success" effect="plain">推荐</el-tag>
                      <el-tag v-if="!tpl.available" size="small" type="info" effect="plain">未安装</el-tag>
                    </div>
                    <div class="hbx-tpl-desc">{{ tpl.description }}</div>
                    <div v-if="tpl.updated_at || tpl.commit" class="hbx-tpl-time">
                      最后更新：
                      <template v-if="tpl.updated_at">{{ formatTplTime(tpl.updated_at) }}</template>
                      <template v-else>未知</template>
                      <span v-if="tpl.commit" class="hbx-tpl-commit">{{ tpl.commit }}</span>
                      <el-tooltip content="时间为服务器上该模板源码最后一次修改（git 提交时间），据此判断下载的是否为最新源码">
                        <el-icon class="hbx-tpl-tip"><QuestionFilledIcon /></el-icon>
                      </el-tooltip>
                    </div>
                  </div>
                </div>
              </div>
            </el-form-item>
            <el-alert
              v-if="form.buildMethod === 'compose'"
              type="success"
              :closable="false"
              show-icon
              style="margin-top: 8px;"
            >
              <template #title>
                <span style="font-size: 12px;">
                  uni-app 玻璃拟态全新 UI。ZIP 解压后用 HBuilderX → 文件 → 打开目录 → 发行 → 原生 App-云打包，深色主题 + 6 页面完整功能，服务器配置已注入。
                </span>
              </template>
            </el-alert>
          </div>

        </el-form>

        <!-- 一键随机按钮 -->
        <div class="quick-actions">
          <el-button
            class="random-all-btn"
            type="success"
            plain
            :icon="MagicStickIcon"
            :loading="randomizing"
            @click="randomizeAll"
          >
            🎲 一键随机生成所有参数
          </el-button>
        </div>

        <!-- 生成按钮 -->
        <div class="submit-bar">
          <el-button
            class="generate-btn"
            type="primary"
            size="large"
            :loading="submitting"
            :icon="PromotionIcon"
            @click="handleGenerate"
          >
            {{ submitting ? '正在生成...' : '生成工程包' }}
          </el-button>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, type FormInstance, type FormRules, type UploadFile } from 'element-plus'
import {
  Cellphone as CellphoneIcon,
  Key as KeyIconComp,
  Link as LinkIcon,
  Connection as ConnectionIcon,
  Plus as PlusIcon,
  Refresh as RefreshIcon,
  EditPen as EditPenIcon,
  PriceTag as PriceTagIcon,
  Promotion as PromotionIcon,
  QuestionFilled as QuestionFilledIcon,
  MagicStick as MagicStickIcon,
  Box as BoxIcon,
  Picture as PictureIcon,
  Brush as BrushIcon,
  Delete as DeleteIcon
} from '@element-plus/icons-vue'
import {
  getRandomConfigApi,
  generateIconApi,
  generateHBuilderXProjectApi,
  getHBuilderXTemplatesApi,
  generateComposeSourceApi
} from '@/api/appBuild'
import type { HBuilderXTemplate } from '@/api/appBuild'
import { getKeyListApi } from '@/api/key'
import { getSettingsApi } from '@/api/settings'

// ---- 表单数据 ----
interface BuildForm {
  name: string
  packageName: string
  defaultKey: string
  serverAddress: string
  websocketAddress: string
  appIcon: string
  version: string
  buildMethod: 'hbuilderx' | 'compose'
  hbuilderxTemplate: string
}

const formRef = ref<FormInstance>()
const uploadRef = ref()
const submitting = ref(false)
const randomizing = ref(false)
const generatingIcon = ref(false)
const iconMode = ref<'upload' | 'auto'>('auto')
const iconChar = ref('推')
const iconGradient = reactive({ start: '#667eea', end: '#764ba2' })

// HBuilderX 模板列表
const hbuilderxTemplates = ref<HBuilderXTemplate[]>([])

const iconGradientStyle = computed(() => ({
  background: `linear-gradient(135deg, ${iconGradient.start}, ${iconGradient.end})`
}))

const form = reactive<BuildForm>({
  name: '',
  packageName: '',
  defaultKey: '',
  serverAddress: '',
  websocketAddress: '',
  appIcon: '',
  version: '1.0.0',
  buildMethod: 'hbuilderx',
  hbuilderxTemplate: 'new'
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入应用名称', trigger: 'blur' }],
  packageName: [
    { required: true, message: '请输入应用包名', trigger: 'blur' },
    {
      pattern: /^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/,
      message: '包名格式不正确，需符合 Java 包名规范',
      trigger: 'blur'
    }
  ],
  defaultKey: [{ required: true, message: '请选择或输入默认 Key', trigger: 'change' }],
  serverAddress: [
    { required: true, message: '请输入服务器地址', trigger: 'blur' },
    { pattern: /^https?:\/\/.+/, message: '请输入合法的 HTTP 地址', trigger: 'blur' }
  ],
  websocketAddress: [
    { required: true, message: '请输入 WebSocket 地址', trigger: 'blur' },
    { pattern: /^wss?:\/\/.+/, message: '请输入合法的 ws/wss 地址', trigger: 'blur' }
  ],
  version: [{ required: true, message: '请输入版本号', trigger: 'blur' }]
}

// Key 选项
const keyOptions = ref<{ label: string; value: string }[]>([])

async function fetchKeyOptions() {
  try {
    const res = await getKeyListApi({ page: 1, pageSize: 100 })
    keyOptions.value = (res.data.list || []).map((k) => ({
      label: `${k.title} (${k.appKey.slice(0, 12)}...)`,
      value: k.appKey
    }))
    // 自动填充第一个 Key（如果 defaultKey 为空）
    if (!form.defaultKey && keyOptions.value.length > 0) {
      form.defaultKey = keyOptions.value[0].value
    }
  } catch {
    // 接口未就绪时使用空列表
    keyOptions.value = []
  }
}

// 图标上传处理
async function handleIconChange(file: UploadFile) {
  if (!file.raw) return
  // 校验类型与大小
  const isImage = file.raw.type.startsWith('image/')
  if (!isImage) {
    ElMessage.error('请上传图片文件')
    return
  }
  const isLt500K = file.raw.size / 1024 / 1024 < 2
  if (!isLt500K) {
    ElMessage.error('图标大小不能超过 2MB')
    return
  }
  // 转 base64 预览
  const reader = new FileReader()
  reader.onload = (e) => {
    form.appIcon = e.target?.result as string
  }
  reader.readAsDataURL(file.raw)
}

// 随机应用名称
async function randomizeName() {
  try {
    const res = await getRandomConfigApi()
    form.name = res.data.app_name
    if (iconMode.value === 'auto') {
      updateIconChar(res.data.app_name)
    }
  } catch {
    ElMessage.warning('获取随机配置失败，请手动输入')
  }
}

// 随机包名
async function randomizePackageName() {
  try {
    const res = await getRandomConfigApi()
    form.packageName = res.data.package_name
  } catch {
    ElMessage.warning('获取随机配置失败，请手动输入')
  }
}

// 一键随机所有参数（包含自动检测服务器地址）
async function randomizeAll() {
  randomizing.value = true
  try {
    await autoFillAllParams()
    ElMessage.success('已随机生成所有参数，服务器地址已自动填充')
  } catch {
    ElMessage.warning('获取随机配置失败，请手动输入')
  } finally {
    randomizing.value = false
  }
}

// 更新图标字符
function updateIconChar(text: string) {
  if (text && text.length > 0) {
    iconChar.value = text.charAt(0)
  }
}

// 生成图标
async function generateIcon() {
  const text = form.name || '推'
  await generateIconWithText(text)
}

async function generateIconWithText(text: string) {
  generatingIcon.value = true
  try {
    const res = await generateIconApi(text)
    iconChar.value = res.data.text
    iconGradient.start = res.data.gradient.start
    iconGradient.end = res.data.gradient.end
    form.appIcon = res.data.icon_base64
  } catch {
    ElMessage.warning('生成图标失败')
  } finally {
    generatingIcon.value = false
  }
}

// 监听应用名称变化，自动更新图标字符
watch(
  () => form.name,
  (newVal) => {
    if (iconMode.value === 'auto' && newVal) {
      updateIconChar(newVal)
    }
  }
)

// 自动检测服务器地址（优先读取系统设置中的端口，拼接完整地址）
async function detectServerUrls() {
  // 默认使用浏览器当前访问的协议与主机
  const browserProtocol = window.location.protocol
  const browserHost = window.location.hostname
  const httpProtocol = browserProtocol === 'https:' ? 'https:' : 'http:'
  const wsProtocol = browserProtocol === 'https:' ? 'wss:' : 'ws:'

  let httpHost = browserHost
  let wsHost = browserHost
  let httpPort = 0
  let wsPort = 0
  let sslEnabled = false

  // 尝试从系统设置读取端口与地址
  try {
    const res: any = await getSettingsApi()
    const server = res?.data?.server || res?.server
    if (server) {
      sslEnabled = !!server.sslEnabled
      // 若系统设置已配置 sslEnabled，则以系统设置协议为准
      if (sslEnabled) {
        // 协议已通过 ssl 决定，无需再覆盖
      }
      // 优先采用系统设置里填写的地址（去掉协议前缀，只取 host）
      if (server.httpApiUrl) {
        httpHost = stripProtocol(server.httpApiUrl) || httpHost
      }
      if (server.websocketUrl) {
        wsHost = stripProtocol(server.websocketUrl) || wsHost
      }
      httpPort = Number(server.httpPort) || 0
      wsPort = Number(server.websocketPort) || 0
    }
  } catch {
    // 读取失败则使用浏览器地址
  }

  const httpUrl = buildUrl(sslEnabled ? 'https:' : httpProtocol, httpHost, httpPort)
  const wsUrl = buildUrl(sslEnabled ? 'wss:' : wsProtocol, wsHost, wsPort)

  return { httpUrl, wsUrl }
}

// 去掉 URL 的协议前缀，返回 host[:port]
function stripProtocol(url: string): string {
  if (!url) return ''
  return url.replace(/^https?:\/\//i, '').replace(/^wss?:\/\//i, '')
}

// 拼接带端口的完整 URL（端口为 0 或空则不加）
function buildUrl(protocol: string, host: string, port: number): string {
  if (port && port > 0 && port !== 80 && port !== 443) {
    return `${protocol}//${host}:${port}`
  }
  return `${protocol}//${host}`
}

// 监听图标模式切换
watch(iconMode, (newMode) => {
  if (newMode === 'auto' && form.name) {
    updateIconChar(form.name)
    generateIcon()
  }
})

// ---- 提交生成 ----
async function handleGenerate() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    ElMessage.warning('请完善表单必填项')
    return
  }

  submitting.value = true
  try {
    if (form.buildMethod === 'hbuilderx') {
      // HBuilderX 打包方式：生成项目压缩包
      const res: any = await generateHBuilderXProjectApi({
        app_name: form.name,
        default_key: form.defaultKey,
        server_url: form.serverAddress,
        ws_url: form.websocketAddress,
        package_name: form.packageName,
        icon_base64: form.appIcon,
        version: form.version,
        template: form.hbuilderxTemplate
      })
      const blob = new Blob([res.data], { type: 'application/zip' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `${form.name || 'app'}-hbuilderx.zip`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      URL.revokeObjectURL(url)
      ElMessage.success('HBuilderX 项目包已生成，正在下载...')
    } else if (form.buildMethod === 'compose') {
      // uni-app 玻璃拟态：生成 HBuilderX 可导入的 ZIP
      if (!form.serverAddress || !form.websocketAddress) {
        ElMessage.warning('玻璃拟态方式需要填写服务器地址和 WebSocket 地址')
        submitting.value = false
        return
      }
      const res: any = await generateComposeSourceApi({
        app_name: form.name,
        default_key: form.defaultKey,
        server_url: form.serverAddress,
        ws_url: form.websocketAddress,
        package_name: form.packageName,
        icon_base64: form.appIcon,
        version_name: form.version,
        version_code: Date.now() % 100000
      })
      const blob = new Blob([res.data], { type: 'application/zip' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `${form.name || 'PushApp'}-glass.zip`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      URL.revokeObjectURL(url)
      ElMessage.success('玻璃拟态源码包已生成，正在下载。用 HBuilderX 导入即可云打包出 APK。')
    }
  } catch (err: any) {
    ElMessage.error(err?.message || '生成失败')
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  // 并行加载基础数据
  await Promise.all([fetchKeyOptions(), fetchHBuilderXTemplates()])

  // 自动填充所有参数
  await autoFillAllParams()
})

// 加载 HBuilderX 模板列表
async function fetchHBuilderXTemplates() {
  try {
    const res = await getHBuilderXTemplatesApi()
    if (res.data?.templates && Array.isArray(res.data.templates)) {
      hbuilderxTemplates.value = res.data.templates
      // 如果当前选中的模板不可用，切换到第一个可用的
      const current = hbuilderxTemplates.value.find(t => t.id === form.hbuilderxTemplate)
      if (!current || !current.available) {
        const firstAvailable = hbuilderxTemplates.value.find(t => t.available)
        if (firstAvailable) form.hbuilderxTemplate = firstAvailable.id
      }
    }
  } catch {}
}

// 格式化模板最后更新时间（后端返回 unix 秒）
function formatTplTime(unixSec: number): string {
  const d = new Date(unixSec * 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

// 自动填充所有参数
async function autoFillAllParams() {
  try {
    // 1. 获取随机配置（应用名称 + 包名）
    const randomRes = await getRandomConfigApi()
    form.name = randomRes.data.app_name
    form.packageName = randomRes.data.package_name

    // 2. 自动检测并填充服务器地址
    const detected = await detectServerUrls()
    form.serverAddress = detected.httpUrl
    form.websocketAddress = detected.wsUrl

    // 3. 自动填充第一个 Key（如果还没有）
    if (!form.defaultKey && keyOptions.value.length > 0) {
      form.defaultKey = keyOptions.value[0].value
    }

    // 4. 自动生成图标
    if (iconMode.value === 'auto') {
      await generateIconWithText(randomRes.data.app_name)
    }
  } catch (e) {
    console.warn('自动填充参数失败，部分参数可能需要手动填写', e)
  }
}

</script>

<style lang="scss" scoped>
.app-build-page {
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
}

// ===== 主体网格 =====
.build-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 20px;
}

// 卡片通用样式
.form-card,
.card-header-row {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 22px;

  .header-icon {
    width: 44px;
    height: 44px;
    border-radius: $radius-md;
    background: $gradient-primary;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 22px;
    box-shadow: $shadow-primary;
    flex-shrink: 0;

    &.history-icon {
      background: $gradient-cyan;
      box-shadow: 0 8px 24px rgba(92, 184, 255, 0.32);
    }
  }

  .card-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
  }
  .card-sub {
    margin: 4px 0 0;
    font-size: 12px;
    color: var(--text-secondary);
  }
  .refresh-btn {
    margin-left: auto;
  }
}

// 表单
.build-form {
  :deep(.el-form-item__label) {
    font-weight: 600;
    color: var(--text-regular);
    padding-bottom: 6px;
  }

  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }

  .form-tip {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 4px;

    code {
      background: var(--bg-secondary, #f5f7fa);
      color: var(--color-primary, #409eff);
      padding: 1px 6px;
      border-radius: 4px;
      font-size: 11px;
      font-family: 'JetBrains Mono', Menlo, Consolas, monospace;
    }
  }
}

// 带操作按钮的输入框
.input-with-action {
  display: flex;
  gap: 8px;
  align-items: stretch;

  .el-input {
    flex: 1;
  }

  .el-button {
    flex-shrink: 0;
  }
}

// 图标区域
.icon-section {
  .icon-mode-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 14px;
    padding: 4px;
    background: var(--bg-page);
    border-radius: $radius-md;
    border: 1px solid var(--border-light);

    .mode-tab {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 8px 12px;
      border-radius: $radius-sm;
      font-size: 13px;
      color: var(--text-secondary);
      cursor: pointer;
      transition: all 0.25s ease;

      &:hover {
        color: var(--text-regular);
      }

      &.active {
        background: var(--bg-card);
        color: $color-primary;
        font-weight: 600;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
      }

      .el-icon {
        font-size: 16px;
      }
    }
  }

  .icon-auto-wrap {
    display: flex;
    align-items: center;
    gap: 18px;

    .icon-preview-auto {
      width: 96px;
      height: 96px;
      border-radius: $radius-lg;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);

      .icon-char {
        font-size: 44px;
        font-weight: 700;
        color: #fff;
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
      }
    }
  }
}

// 快捷操作区
.quick-actions {
  margin-top: 8px;
  padding: 12px 0;
  display: flex;
  justify-content: center;

  .random-all-btn {
    font-weight: 600;
    padding: 10px 24px;
    border-radius: $radius-md;
  }
}

// 图标上传
.icon-uploader-wrap {
  display: flex;
  align-items: center;
  gap: 18px;

  .icon-uploader {
    :deep(.el-upload) {
      width: 96px;
      height: 96px;
      border-radius: $radius-lg;
      overflow: hidden;
      border: 2px dashed var(--border-base);
      background: var(--bg-page);
      transition: all 0.25s ease;
      cursor: pointer;

      &:hover {
        border-color: $color-primary;
        background: $color-primary-light-9;
      }
    }
  }

  .icon-preview {
    width: 100%;
    height: 100%;
    position: relative;

    img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: $radius-md;
    }

    .icon-mask {
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.55);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 4px;
      color: #fff;
      font-size: 12px;
      opacity: 0;
      transition: opacity 0.2s ease;
      border-radius: $radius-md;
    }

    &:hover .icon-mask {
      opacity: 1;
    }
  }

  .icon-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    color: var(--text-secondary);
    font-size: 12px;

    .upload-icon {
      font-size: 22px;
      color: $color-primary;
    }
  }

  .icon-tips {
    p {
      margin: 0 0 4px;
      font-size: 12px;
      color: var(--text-secondary);
    }
  }
}

// 平台单选
.platform-radio {
  :deep(.el-radio-button__inner) {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    width: 100%;
  }
}

// 打包类型卡片
.submit-bar {
  margin-top: 8px;
  padding-top: 20px;
  border-top: 1px dashed var(--border-light);

  .generate-btn {
    width: 100%;
    height: 52px;
    border-radius: $radius-md;
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 1px;
    background: $gradient-primary;
    border: none;
    box-shadow: $shadow-primary;
    transition: all 0.3s ease;

    &:hover {
      transform: translateY(-2px);
      box-shadow: $shadow-primary-lg;
    }
    &:active {
      transform: translateY(0);
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

@keyframes slide-in {
  from {
    opacity: 0;
    transform: translateX(-12px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

@keyframes rotating {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

// ===== 响应式 =====
@media (max-width: 1100px) {
  .build-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 640px) {
  .page-hero {
    padding: 20px;
  }
  .build-form .form-row {
    grid-template-columns: 1fr;
  }
  .build-type-group {
    grid-template-columns: 1fr;
  }
  .history-item {
    flex-direction: column;
    align-items: flex-start;

    .item-actions {
      width: 100%;
      justify-content: flex-end;
    }
  }
}

// ===== 暗色模式 =====
:global(html.dark) {
  .page-hero {
    background: linear-gradient(135deg, #4d38f0 0%, #7d4dff 100%);
  }
  .icon-uploader-wrap {
    .icon-uploader :deep(.el-upload) {
      background: rgba(255, 255, 255, 0.03);
    }
    .icon-placeholder .upload-icon {
      color: $color-primary-light-5;
    }
  }
  .build-type-item {
    background: rgba(255, 255, 255, 0.02);
  }
  .log-meta {
    background: rgba(255, 255, 255, 0.02);
  }
}

/* HBuilderX 模板选择 */
.hbx-tpl-list {
  display: flex; flex-direction: column; gap: 12px; width: 100%;
}
.hbx-tpl-item {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 14px 16px; border-radius: 8px;
  border: 2px solid var(--el-border-color-lighter);
  background: var(--el-fill-color-light);
  cursor: pointer; transition: all 0.2s ease;
  &:hover:not(.disabled) {
    border-color: var(--el-color-primary);
    background: var(--el-fill-color);
  }
  &.active {
    border-color: var(--el-color-primary);
    background: var(--color-primary-light-9);
    box-shadow: 0 0 0 3px var(--color-primary-light-9);
  }
  &.disabled {
    opacity: 0.55; cursor: not-allowed;
  }
}
.hbx-tpl-radio {
  padding-top: 2px;
  .radio-outer {
    display: inline-flex; align-items: center; justify-content: center;
    width: 18px; height: 18px; border-radius: 50%;
    border: 2px solid var(--el-border-color);
    background: var(--el-bg-color);
    transition: border-color 0.2s;
  }
  .radio-inner {
    width: 10px; height: 10px; border-radius: 50%;
    background: var(--el-color-primary);
  }
  .hbx-tpl-item.active & .radio-outer {
    border-color: var(--el-color-primary);
  }
}
.hbx-tpl-info {
  flex: 1; min-width: 0;
  .hbx-tpl-name {
    font-weight: 600; font-size: 14px; color: var(--el-text-color-primary);
    display: flex; align-items: center; gap: 6px;
  }
  .hbx-tpl-desc {
    margin-top: 4px; font-size: 12px; color: var(--el-text-color-secondary);
    line-height: 1.5;
  }
  .hbx-tpl-time {
    margin-top: 4px; font-size: 12px; color: var(--el-text-color-secondary);
    display: flex; align-items: center; gap: 4px;
    .hbx-tpl-commit {
      padding: 0 6px; border-radius: 4px;
      background: var(--el-fill-color-light);
      color: var(--el-text-color-regular);
      font-family: monospace; font-size: 11px; line-height: 18px;
    }
    .hbx-tpl-tip {
      font-size: 13px; color: var(--el-text-color-placeholder); cursor: help;
    }
  }
}
</style>
