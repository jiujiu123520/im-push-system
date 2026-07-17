<template>
  <div class="page-container app-build-page">
    <!-- 顶部标题区 + 步骤指示器 -->
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
          <p class="hero-sub">配置应用参数，一键生成 Android / iOS 安装包</p>
        </div>
        <!-- 步骤指示器 -->
        <div class="step-indicator">
          <div
            v-for="(step, idx) in steps"
            :key="step.key"
            class="step-item"
            :class="{
              active: currentStep === idx,
              done: currentStep > idx
            }"
          >
            <div class="step-dot">
              <el-icon v-if="currentStep > idx"><CheckIcon /></el-icon>
              <span v-else>{{ idx + 1 }}</span>
            </div>
            <div class="step-label">{{ step.label }}</div>
            <div v-if="idx < steps.length - 1" class="step-line"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- GitHub Actions 配置提示面板 -->
    <el-collapse v-model="configCollapse" class="config-panel">
      <el-collapse-item name="config" :title="configPanelTitle">
        <div class="config-content">
          <!-- 配置状态总览 -->
          <el-alert
            :title="configStatus.available ? '✅ GitHub Actions 构建已就绪' : '⚠️ GitHub Actions 构建未配置,无法提交构建任务'"
            :type="configStatus.available ? 'success' : 'warning'"
            :closable="false"
            show-icon
            class="config-alert"
          />

          <!-- 服务器端 .env 配置状态 -->
          <div class="config-section">
            <h4 class="section-title">
              <el-icon><MonitorIcon /></el-icon>
              服务器端 .env 配置
              <el-tag v-if="configStatus.available" type="success" size="small" round>已配置</el-tag>
              <el-tag v-else type="danger" size="small" round>未配置</el-tag>
            </h4>
            <p class="section-desc">
              配置文件路径: <code class="code-text">/www/push-system/backend/.env</code>
            </p>
            <el-table :data="configStatus.required_env" size="small" border class="env-table">
              <el-table-column prop="name" label="环境变量" width="220">
                <template #default="{ row }">
                  <code class="code-text">{{ row.name }}</code>
                </template>
              </el-table-column>
              <el-table-column prop="description" label="说明" />
            </el-table>
            <div class="config-block">
              <p class="block-title">配置示例(追加到 .env 末尾):</p>
              <pre class="code-block">GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
GITHUB_OWNER=jiujiu123520
GITHUB_REPO=im-push-system
GITHUB_WORKFLOW_FILE=build-apk.yml
GITHUB_API_PROXY=https://gh.jasonzeng.dev/
GITHUB_API_TIMEOUT=30</pre>
            </div>
            <p class="section-tip">
              <el-icon><InfoFilledIcon /></el-icon>
              创建 Token: <el-link type="primary" :href="configStatus.token_create_url" target="_blank">{{ configStatus.token_create_url }}</el-link>
              <span class="tip-sep">|</span>
              <span class="tip-text">权限要求: <code class="code-text">repo</code> + <code class="code-text">workflow</code></span>
            </p>
          </div>

          <!-- GitHub 仓库 Secrets 配置 -->
          <div class="config-section">
            <h4 class="section-title">
              <el-icon><KeyIconComp /></el-icon>
              GitHub 仓库 Secrets
              <el-link
                v-if="configStatus.secrets_url"
                type="primary"
                :href="configStatus.secrets_url"
                target="_blank"
                class="config-link"
              >
                前往配置 →
              </el-link>
            </h4>
            <p class="section-desc">
              在 GitHub 仓库 <el-link v-if="configStatus.repo_url" type="primary" :href="configStatus.repo_url" target="_blank">{{ configStatus.owner }}/{{ configStatus.repo }}</el-link> <span v-else>owner/repo</span> 的 Settings → Secrets and variables → Actions 中添加以下 Secrets:
            </p>
            <el-table :data="configStatus.required_secrets" size="small" border class="env-table">
              <el-table-column prop="name" label="Secret 名称" width="220">
                <template #default="{ row }">
                  <code class="code-text">{{ row.name }}</code>
                </template>
              </el-table-column>
              <el-table-column prop="description" label="说明" />
            </el-table>
          </div>

          <!-- Keystore base64 获取命令 -->
          <div class="config-section">
            <h4 class="section-title">
              <el-icon><BoxIcon /></el-icon>
              Keystore base64 获取命令
            </h4>
            <p class="section-desc">
              在服务器执行以下命令获取 <code class="code-text">APK_KEYSTORE_BASE64</code> 的值(用于配置 GitHub Secret):
            </p>
            <div class="config-block">
              <pre class="code-block">base64 -w 0 /www/push-system/build/keystore/release.keystore</pre>
              <el-button size="small" :icon="DocumentCopyIcon" @click="copyCommand('base64 -w 0 /www/push-system/build/keystore/release.keystore')" class="copy-btn">复制命令</el-button>
            </div>
            <p class="section-tip">
              <el-icon><InfoFilledIcon /></el-icon>
              如未生成 keystore,可执行 <code class="code-text">bash build/generate_keystore.sh</code> 生成(在服务器项目根目录下)
            </p>
          </div>

          <!-- SSH 密钥配置 -->
          <div class="config-section">
            <h4 class="section-title">
              <el-icon><ConnectionIcon /></el-icon>
              SSH 密钥配置(供 GitHub Actions SCP 上传)
            </h4>
            <p class="section-desc">在服务器生成专用密钥对(如已有可跳过):</p>
            <div class="config-block">
              <pre class="code-block">ssh-keygen -t ed25519 -C "github-actions-build" -f ~/.ssh/github_actions_key -N ""
cat ~/.ssh/github_actions_key.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
cat ~/.ssh/github_actions_key</pre>
              <el-button size="small" :icon="DocumentCopyIcon" @click="copySshKeygenCommand" class="copy-btn">复制命令</el-button>
            </div>
            <p class="section-tip">
              <el-icon><InfoFilledIcon /></el-icon>
              将 <code class="code-text">cat ~/.ssh/github_actions_key</code> 的完整输出(含 <code class="code-text">-----BEGIN/END OPENSSH PRIVATE KEY-----</code>)填入 GitHub Secret <code class="code-text">SERVER_SSH_KEY</code>
            </p>
          </div>

          <!-- 构建流程说明 -->
          <div class="config-section">
            <h4 class="section-title">
              <el-icon><CpuIcon /></el-icon>
              构建流程说明
            </h4>
            <el-timeline class="flow-timeline">
              <el-timeline-item type="primary" timestamp="1" placement="top">
                <p>前端提交构建任务 → 后端 <code class="code-text">/admin/app-build</code></p>
              </el-timeline-item>
              <el-timeline-item type="primary" timestamp="2" placement="top">
                <p>后端调用 GitHub API 触发 <code class="code-text">workflow_dispatch</code>(通过 gh.jasonzeng.dev 代理)</p>
              </el-timeline-item>
              <el-timeline-item type="primary" timestamp="3" placement="top">
                <p>GitHub Actions Runner 启动 → checkout 代码 → setup JDK 17 + Android SDK + Gradle</p>
              </el-timeline-item>
              <el-timeline-item type="primary" timestamp="4" placement="top">
                <p>解码 keystore Secret → 执行 <code class="code-text">build_apk.sh</code> 构建 APK</p>
              </el-timeline-item>
              <el-timeline-item type="success" timestamp="5" placement="top">
                <p>SCP 上传 APK 到服务器 <code class="code-text">/www/push-system/build/output/{build_id}/</code></p>
              </el-timeline-item>
              <el-timeline-item type="success" timestamp="6" placement="top">
                <p>SSH 调用 <code class="code-text">update_build_status.php</code> 更新 Redis 状态</p>
              </el-timeline-item>
              <el-timeline-item type="success" timestamp="7" placement="top">
                <p>前端轮询 list 接口获取最新状态 → 可下载 APK</p>
              </el-timeline-item>
            </el-timeline>
            <p class="section-tip">
              <el-icon><InfoFilledIcon /></el-icon>
              GitHub Actions 运行状态: <el-link v-if="configStatus.actions_url" type="primary" :href="configStatus.actions_url" target="_blank">{{ configStatus.actions_url }}</el-link>
            </p>
          </div>

          <!-- 刷新按钮 -->
          <div class="config-footer">
            <el-button :icon="RefreshIcon" @click="fetchConfigStatus">重新检测配置</el-button>
          </div>
        </div>
      </el-collapse-item>
    </el-collapse>

    <!-- 主体：左侧表单 + 右侧构建历史 -->
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
            <el-form-item label="平台" prop="platform">
              <el-radio-group v-model="form.platform" class="platform-radio">
                <el-radio-button value="android">
                  <el-icon><CellphoneIcon /></el-icon>
                  Android
                </el-radio-button>
                <el-radio-button value="ios">
                  <el-icon><MonitorIcon /></el-icon>
                  iOS
                </el-radio-button>
              </el-radio-group>
            </el-form-item>
          </div>

          <!-- 打包类型 -->
          <el-form-item label="打包类型" prop="buildType">
            <div class="build-type-group">
              <div
                v-for="opt in buildTypes"
                :key="opt.value"
                class="build-type-item"
                :class="{ active: form.buildType === opt.value }"
                @click="form.buildType = opt.value"
              >
                <div class="type-icon" :class="opt.value">
                  <el-icon><component :is="opt.icon" /></el-icon>
                </div>
                <div class="type-info">
                  <div class="type-name">{{ opt.label }}</div>
                  <div class="type-desc">{{ opt.desc }}</div>
                </div>
                <el-icon class="type-check"><CircleCheckFilledIcon /></el-icon>
              </div>
            </div>
          </el-form-item>
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
            {{ submitting ? '正在提交构建...' : '生成安装包' }}
          </el-button>
        </div>
      </div>

      <!-- 右侧：构建历史 -->
      <div class="history-card">
        <div class="card-header-row">
          <div class="header-icon history-icon">
            <el-icon><ClockIcon /></el-icon>
          </div>
          <div>
            <h3 class="card-title">构建历史</h3>
            <p class="card-sub">最近 {{ historyList.length }} 条构建记录</p>
          </div>
          <el-button
            class="refresh-btn"
            text
            :icon="RefreshIcon"
            :loading="historyLoading"
            @click="fetchHistory"
          >
            刷新
          </el-button>
        </div>

        <div v-loading="historyLoading" class="history-list">
          <div v-if="!historyList.length && !historyLoading" class="empty-state">
            <el-icon class="empty-icon"><FilesIcon /></el-icon>
            <p>暂无构建记录</p>
            <span>完成左侧配置后点击「生成安装包」</span>
          </div>

          <div
            v-for="item in historyList"
            :key="item.build_id"
            class="history-item"
            :class="`status-${item.status}`"
          >
            <div class="item-main">
              <div class="item-top">
                <span class="item-name">{{ item.app_name }}</span>
                <el-tag
                  :type="statusTagType(item.status)"
                  effect="light"
                  round
                  size="small"
                  class="status-tag"
                >
                  <el-icon
                    v-if="item.status === 'processing'"
                    class="loading-icon"
                  >
                    <LoadingIcon />
                  </el-icon>
                  {{ statusLabel(item.status) }}
                </el-tag>
              </div>
              <div class="item-meta">
                <span class="meta-item">
                  <el-icon><CellphoneIcon /></el-icon>
                  Android
                </span>
                <span class="meta-item">
                  <el-icon><ClockIcon /></el-icon>
                  {{ formatTime(item.created_at) }}
                </span>
              </div>
            </div>
            <div class="item-actions">
              <el-button
                v-if="item.status === 'success'"
                type="primary"
                size="small"
                :icon="DownloadIcon"
                round
                @click="handleDownload(item)"
              >
                下载
              </el-button>
              <el-button
                size="small"
                :icon="DocumentIcon"
                round
                @click="openLog(item)"
              >
                日志
              </el-button>
              <el-button
                v-if="item.status === 'failed'"
                size="small"
                :icon="RefreshRightIcon"
                round
                @click="handleRetry(item)"
              >
                重试
              </el-button>
              <el-button
                size="small"
                :icon="DeleteIcon"
                round
                type="danger"
                @click="handleDelete(item)"
              >
                删除
              </el-button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 构建日志抽屉 -->
    <el-drawer
      v-model="logDrawerVisible"
      title="构建日志"
      direction="rtl"
      size="560px"
      class="log-drawer"
    >
      <template #header>
        <div class="drawer-header">
          <div class="drawer-title">
            <el-icon class="title-icon"><DocumentIcon /></el-icon>
            <span>构建日志</span>
          </div>
          <div class="drawer-actions">
            <el-tag
              v-if="currentLogRecord"
              :type="statusTagType(currentLogRecord.status)"
              effect="light"
              round
              size="small"
            >
              {{ statusLabel(currentLogRecord.status) }}
            </el-tag>
            <el-button
              v-if="currentLogRecord"
              type="primary"
              size="small"
              :icon="DownloadIcon"
              @click="handleDownloadLog"
            >
              下载日志
            </el-button>
          </div>
        </div>
      </template>

      <div v-if="currentLogRecord" class="log-meta">
        <div class="meta-row">
          <span class="meta-label">应用名称</span>
          <span class="meta-value">{{ currentLogRecord.app_name }}</span>
        </div>
        <div class="meta-row">
          <span class="meta-label">构建ID</span>
          <span class="meta-value mono">{{ currentLogRecord.build_id }}</span>
        </div>
        <div class="meta-row">
          <span class="meta-label">包名</span>
          <span class="meta-value mono">{{ currentLogRecord.package_name || '-' }}</span>
        </div>
        <div class="meta-row">
          <span class="meta-label">开始时间</span>
          <span class="meta-value">{{ formatTime(currentLogRecord.created_at) }}</span>
        </div>
      </div>

      <div class="log-terminal">
        <div class="terminal-header">
          <span class="dot red"></span>
          <span class="dot yellow"></span>
          <span class="dot green"></span>
          <span class="terminal-title">build.log</span>
        </div>
        <div class="terminal-body">
          <pre v-if="logContent">{{ logContent }}</pre>
          <div v-else class="terminal-empty">
            <el-icon class="loading-icon"><LoadingIcon /></el-icon>
            <span>正在加载日志...</span>
          </div>
        </div>
      </div>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules, type UploadFile } from 'element-plus'
import {
  Cellphone as CellphoneIcon,
  Key as KeyIconComp,
  Link as LinkIcon,
  Connection as ConnectionIcon,
  Plus as PlusIcon,
  Refresh as RefreshIcon,
  Delete as DeleteIcon,
  EditPen as EditPenIcon,
  PriceTag as PriceTagIcon,
  Promotion as PromotionIcon,
  Clock as ClockIcon,
  Files as FilesIcon,
  Document as DocumentIcon,
  Download as DownloadIcon,
  RefreshRight as RefreshRightIcon,
  Check as CheckIcon,
  Loading as LoadingIcon,
  CircleCheckFilled as CircleCheckFilledIcon,
  Cpu as CpuIcon,
  Coin as CoinIcon,
  Monitor as MonitorIcon,
  MagicStick as MagicStickIcon,
  Box as BoxIcon,
  Picture as PictureIcon,
  Brush as BrushIcon,
  InfoFilled as InfoFilledIcon,
  DocumentCopy as DocumentCopyIcon
} from '@element-plus/icons-vue'
import {
  getAppBuildListApi,
  createAppBuildApi,
  getBuildLogApi,
  deleteAppBuildApi,
  getRandomConfigApi,
  generateIconApi,
  downloadApkApi,
  downloadBuildLogApi,
  getAppBuildConfigStatusApi
} from '@/api/appBuild'
import { getKeyListApi } from '@/api/key'
import { getSettingsApi } from '@/api/settings'
import type { AppBuildRecord } from '@/api/types'

// ---- 表单数据 ----
interface BuildForm {
  name: string
  packageName: string
  defaultKey: string
  serverAddress: string
  websocketAddress: string
  appIcon: string
  version: string
  platform: 'android' | 'ios'
  buildType: 'release' | 'debug'
}

const formRef = ref<FormInstance>()
const uploadRef = ref()
const submitting = ref(false)
const randomizing = ref(false)
const generatingIcon = ref(false)
const iconMode = ref<'upload' | 'auto'>('auto')
const iconChar = ref('推')
const iconGradient = reactive({ start: '#667eea', end: '#764ba2' })

// ---- GitHub Actions 配置状态 ----
const configCollapse = ref<string[]>([])  // 默认折叠
const configStatusLoaded = ref(false)
const configStatus = reactive<{
  available: boolean
  token_configured: boolean
  owner: string
  repo: string
  workflow_file: string
  api_proxy: string
  repo_url: string
  actions_url: string
  secrets_url: string
  token_create_url: string
  required_secrets: Array<{ name: string; description: string; required: boolean }>
  required_env: Array<{ name: string; description: string }>
}>({
  available: false,
  token_configured: false,
  owner: '',
  repo: '',
  workflow_file: 'build-apk.yml',
  api_proxy: '',
  repo_url: '',
  actions_url: '',
  secrets_url: '',
  token_create_url: 'https://github.com/settings/tokens',
  required_secrets: [],
  required_env: []
})

const configPanelTitle = computed(() => {
  if (!configStatusLoaded.value) return 'GitHub Actions 构建配置说明(点击展开)'
  return configStatus.available
    ? '✅ GitHub Actions 构建已就绪(点击折叠)'
    : '⚠️ GitHub Actions 构建未配置(点击展开查看配置说明)'
})

async function fetchConfigStatus() {
  try {
    const res = await getAppBuildConfigStatusApi()
    const data = res.data || res
    Object.assign(configStatus, data)
    configStatusLoaded.value = true
    // 未配置时自动展开
    if (!data.available && configCollapse.value.length === 0) {
      configCollapse.value = ['config']
    }
  } catch (e) {
    console.warn('获取 GitHub Actions 配置状态失败', e)
  }
}

async function copyCommand(cmd: string) {
  try {
    await navigator.clipboard.writeText(cmd)
    ElMessage.success('命令已复制到剪贴板')
  } catch {
    ElMessage.warning('复制失败,请手动选择复制')
  }
}

// SSH 密钥生成命令(单独函数避免模板中引号嵌套)
async function copySshKeygenCommand() {
  await copyCommand('ssh-keygen -t ed25519 -C "github-actions-build" -f ~/.ssh/github_actions_key -N ""')
}

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
  platform: 'android',
  buildType: 'release'
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

// 步骤指示器
const steps = [
  { key: 'config', label: '配置' },
  { key: 'build', label: '构建' },
  { key: 'download', label: '下载' }
]
const currentStep = computed(() => {
  // 有成功的构建记录则进入下载步骤
  const hasSuccess = historyList.value.some((i) => i.status === 'success')
  const hasBuilding = historyList.value.some(
    (i) => i.status === 'processing' || i.status === 'pending'
  )
  if (hasSuccess) return 2
  if (hasBuilding || submitting.value) return 1
  return 0
})

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

// 打包类型选项
const buildTypes = [
  { value: 'release', label: 'Release', desc: '正式版 · 优化性能', icon: CpuIcon },
  { value: 'debug', label: 'Debug', desc: '调试版 · 含日志输出', icon: CoinIcon }
] as const

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
    const res = await getRandomConfigApi()
    form.name = res.data.app_name
    form.packageName = res.data.package_name
    
    const detected = await detectServerUrls()
    form.serverAddress = detected.httpUrl
    form.websocketAddress = detected.wsUrl
    
    if (!form.defaultKey && keyOptions.value.length > 0) {
      form.defaultKey = keyOptions.value[0].value
    }
    
    if (iconMode.value === 'auto') {
      await generateIconWithText(res.data.app_name)
    }
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

// ---- 构建历史 ----
const historyList = ref<AppBuildRecord[]>([])
const historyLoading = ref(false)

async function fetchHistory() {
  historyLoading.value = true
  try {
    const res = await getAppBuildListApi({ page: 1, pageSize: 20 })
    historyList.value = (res.data as any).list || []
  } catch {
    // 接口异常时保持空列表
    historyList.value = []
  } finally {
    historyLoading.value = false
  }
}

// 状态映射
function statusTagType(status: string): 'primary' | 'success' | 'warning' | 'info' | 'danger' {
  const map: Record<string, 'primary' | 'success' | 'warning' | 'info' | 'danger'> = {
    pending: 'info',
    processing: 'primary',
    success: 'success',
    failed: 'danger'
  }
  return map[status] || 'info'
}

function statusLabel(status: string) {
  const map: Record<string, string> = {
    pending: '等待中',
    processing: '构建中',
    success: '成功',
    failed: '失败'
  }
  return map[status] || status
}

function formatTime(t: string): string {
  if (!t) return '-'
  return t.replace('T', ' ').slice(0, 19)
}

// ---- 提交构建 ----
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
    await createAppBuildApi({
      app_name: form.name,
      default_key: form.defaultKey,
      server_url: form.serverAddress,
      ws_url: form.websocketAddress,
      package_name: form.packageName,
      icon_path: form.appIcon,
      version: form.version,
      platform: form.platform,
      build_type: form.buildType
    })
    ElMessage.success('构建任务已提交，正在打包...')
    await fetchHistory()
    startPolling()
  } catch (err: any) {
    ElMessage.error(err?.message || '提交构建失败')
  } finally {
    submitting.value = false
  }
}

// 下载
async function handleDownload(item: AppBuildRecord) {
  if (!item.build_id) {
    ElMessage.warning('下载地址不存在')
    return
  }
  try {
    const res: any = await downloadApkApi(item.build_id)
    const blob = new Blob([res.data], { type: 'application/vnd.android.package-archive' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${item.app_name || 'app'}.apk`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    ElMessage.success('开始下载')
  } catch {
    ElMessage.error('下载失败')
  }
}

// 下载构建日志
async function handleDownloadLog() {
  if (!currentLogRecord.value?.build_id) {
    ElMessage.warning('构建ID不存在')
    return
  }
  try {
    const res: any = await downloadBuildLogApi(currentLogRecord.value.build_id)
    const blob = new Blob([res.data], { type: 'text/plain;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `build-${currentLogRecord.value.build_id}.log`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    ElMessage.success('日志下载开始')
  } catch {
    ElMessage.error('日志下载失败')
  }
}

// 重试
async function handleRetry(item: AppBuildRecord) {
  try {
    await ElMessageBox.confirm(`确定重新构建「${item.app_name}」吗？`, '提示', {
      confirmButtonText: '重新构建',
      cancelButtonText: '取消',
      type: 'warning'
    })
    submitting.value = true
    try {
      await createAppBuildApi({
        app_name: item.app_name,
        default_key: item.default_key,
        server_url: item.server_url,
        ws_url: item.ws_url,
        package_name: item.package_name,
        icon_path: item.icon_path,
        version: form.version,
        platform: form.platform,
        build_type: form.buildType
      })
      ElMessage.success('已重新提交构建')
      await fetchHistory()
      startPolling()
    } catch (err: any) {
      ElMessage.error(err?.message || '重新提交构建失败')
    } finally {
      submitting.value = false
    }
  } catch {
    // 取消
  }
}

// 删除
async function handleDelete(item: AppBuildRecord) {
  try {
    await ElMessageBox.confirm(`确定删除构建记录「${item.app_name}」吗？`, '提示', {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning'
    })
    await deleteAppBuildApi(item.build_id)
    ElMessage.success('删除成功')
    await fetchHistory()
  } catch {
    // 取消
  }
}

// ---- 轮询构建状态 ----
let pollTimer: ReturnType<typeof setInterval> | null = null

function startPolling() {
  if (pollTimer) return
  pollTimer = setInterval(async () => {
    const hasActive = historyList.value.some(
      (i) => i.status === 'processing' || i.status === 'pending'
    )
    if (!hasActive) {
      stopPolling()
      return
    }
    await fetchHistory()
  }, 3000)
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

// ---- 日志抽屉 ----
const logDrawerVisible = ref(false)
const currentLogRecord = ref<AppBuildRecord | null>(null)
const logContent = ref('')

async function openLog(item: AppBuildRecord) {
  currentLogRecord.value = item
  logContent.value = ''
  logDrawerVisible.value = true
  try {
    const res = await getBuildLogApi(item.build_id)
    const data: any = res.data
    logContent.value = data?.log || data || '暂无日志内容'
  } catch {
    logContent.value = '日志加载失败'
  }
}

onMounted(async () => {
  fetchKeyOptions()
  fetchHistory()
  fetchConfigStatus()

  // 根据系统设置中保存的端口自动填入服务器地址（含端口号）
  const detected = await detectServerUrls()
  // 仅在用户尚未手动填写时自动填充
  if (!form.serverAddress) form.serverAddress = detected.httpUrl
  if (!form.websocketAddress) form.websocketAddress = detected.wsUrl
})

onBeforeUnmount(() => {
  stopPolling()
})
</script>

<style lang="scss" scoped>
.app-build-page {
  animation: fade-up 0.5s ease;
}

// ===== GitHub Actions 配置提示面板 =====
.config-panel {
  margin-bottom: 20px;
  border: 1px solid var(--border-light);
  border-radius: $radius-lg;
  background: var(--bg-card);
  overflow: hidden;
  box-shadow: $shadow-sm;

  :deep(.el-collapse-item__header) {
    padding: 0 20px;
    height: 56px;
    font-size: 15px;
    font-weight: 600;
    background: var(--bg-card);
    border-bottom: 1px solid var(--border-light);
  }

  :deep(.el-collapse-item__wrap) {
    background: var(--bg-card);
  }

  :deep(.el-collapse-item__content) {
    padding: 0;
  }
}

.config-content {
  padding: 20px;
}

.config-alert {
  margin-bottom: 20px;
}

.config-section {
  margin-bottom: 24px;
  padding: 16px 20px;
  background: var(--el-fill-color-lighter, #fafafa);
  border-radius: $radius-md;
  border-left: 3px solid var(--el-color-primary, #409eff);

  &:last-of-type {
    margin-bottom: 0;
  }
}

.section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
  color: var(--el-text-color-primary);
  margin: 0 0 10px 0;

  .el-icon {
    color: var(--el-color-primary, #409eff);
    font-size: 16px;
  }

  .config-link {
    margin-left: auto;
    font-size: 12px;
    font-weight: 400;
  }
}

.section-desc {
  font-size: 13px;
  color: var(--el-text-color-regular);
  margin: 0 0 10px 0;
  line-height: 1.6;

  .el-link {
    vertical-align: baseline;
    font-size: 13px;
  }
}

.section-tip {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin: 10px 0 0 0;
  line-height: 1.6;

  .el-icon {
    color: var(--el-color-warning, #e6a23c);
    flex-shrink: 0;
  }

  .tip-sep {
    color: var(--el-text-color-placeholder);
    margin: 0 4px;
  }

  .tip-text {
    color: var(--el-text-color-regular);
  }
}

.code-text {
  font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
  font-size: 12px;
  padding: 2px 6px;
  background: var(--el-fill-color-dark, #f0f0f0);
  border-radius: 3px;
  color: var(--el-color-danger, #f56c6c);
}

.config-block {
  margin-top: 12px;
  position: relative;
}

.block-title {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin: 0 0 6px 0;
}

.code-block {
  font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
  font-size: 12px;
  line-height: 1.6;
  padding: 12px 14px;
  background: #1e1e1e;
  color: #d4d4d4;
  border-radius: $radius-sm;
  overflow-x: auto;
  white-space: pre;
  margin: 0;
}

.copy-btn {
  margin-top: 8px;
}

.env-table {
  margin-top: 8px;

  :deep(.el-table__cell) {
    padding: 6px 0;
  }
}

.flow-timeline {
  margin-top: 12px;
  padding-left: 8px;

  p {
    margin: 0;
    font-size: 13px;
    color: var(--el-text-color-regular);
    line-height: 1.6;
  }
}

.config-footer {
  margin-top: 20px;
  display: flex;
  justify-content: center;
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

// 步骤指示器
.step-indicator {
  display: flex;
  align-items: center;
  gap: 0;

  .step-item {
    display: flex;
    align-items: center;
    gap: 8px;

    .step-dot {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.18);
      border: 2px solid rgba(255, 255, 255, 0.35);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 13px;
      font-weight: 700;
      transition: all 0.3s ease;
      backdrop-filter: blur(4px);
    }

    .step-label {
      color: rgba(255, 255, 255, 0.85);
      font-size: 13px;
      font-weight: 600;
    }

    .step-line {
      width: 32px;
      height: 2px;
      background: rgba(255, 255, 255, 0.25);
      margin: 0 6px;
      border-radius: 1px;
    }

    &.active .step-dot {
      background: #fff;
      color: $color-primary;
      border-color: #fff;
      box-shadow: 0 0 0 6px rgba(255, 255, 255, 0.18);
      transform: scale(1.08);
    }
    &.done .step-dot {
      background: rgba(255, 255, 255, 0.9);
      color: $color-primary;
      border-color: #fff;
    }
    &.done .step-line {
      background: rgba(255, 255, 255, 0.7);
    }
  }
}

// ===== 主体网格 =====
.build-grid {
  display: grid;
  grid-template-columns: 1.4fr 1fr;
  gap: 20px;
}

// 卡片通用样式
.form-card,
.history-card {
  background: var(--bg-card);
  border-radius: $radius-xl;
  padding: 24px;
  border: 1px solid var(--border-light);
  box-shadow: $shadow-md;
  position: relative;
  overflow: hidden;
}

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
.build-type-group {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  width: 100%;

  .build-type-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border-radius: $radius-md;
    border: 1.5px solid var(--border-base);
    background: var(--bg-page);
    cursor: pointer;
    transition: all 0.25s ease;
    position: relative;

    &:hover {
      border-color: $color-primary-light-5;
      transform: translateY(-2px);
    }

    &.active {
      border-color: $color-primary;
      background: $color-primary-light-9;
      box-shadow: 0 4px 14px rgba(109, 92, 255, 0.18);

      .type-check {
        opacity: 1;
        transform: scale(1);
      }
      .type-icon {
        background: $gradient-primary;
        color: #fff;
      }
    }

    .type-icon {
      width: 38px;
      height: 38px;
      border-radius: $radius-sm;
      background: var(--bg-card);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-regular);
      font-size: 18px;
      transition: all 0.25s ease;
      flex-shrink: 0;
    }

    .type-info {
      flex: 1;
      min-width: 0;
    }
    .type-name {
      font-size: 14px;
      font-weight: 700;
      color: var(--text-primary);
    }
    .type-desc {
      font-size: 11px;
      color: var(--text-secondary);
      margin-top: 2px;
    }

    .type-check {
      position: absolute;
      top: 8px;
      right: 8px;
      color: $color-primary;
      font-size: 16px;
      opacity: 0;
      transform: scale(0.6);
      transition: all 0.25s ease;
    }
  }
}

// 生成按钮
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

// ===== 构建历史 =====
.history-list {
  min-height: 240px;
  max-height: 560px;
  overflow-y: auto;
  @include scrollbar;

  .empty-state {
    height: 240px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: var(--text-secondary);

    .empty-icon {
      font-size: 48px;
      color: var(--border-dark);
      margin-bottom: 4px;
    }
    p {
      margin: 0;
      font-size: 14px;
      color: var(--text-regular);
    }
    span {
      font-size: 12px;
    }
  }
}

.history-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-radius: $radius-md;
  border: 1px solid var(--border-light);
  background: var(--bg-card);
  margin-bottom: 10px;
  transition: all 0.25s ease;
  position: relative;
  overflow: hidden;
  animation: slide-in 0.35s ease;

  &::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--border-base);
  }

  &.status-pending::before { background: $color-info; }
  &.status-building::before { background: $color-primary; }
  &.status-success::before { background: $color-success; }
  &.status-failed::before { background: $color-danger; }

  &:hover {
    border-color: $color-primary-light-5;
    box-shadow: $shadow-sm;
    transform: translateX(2px);
  }

  .item-main {
    flex: 1;
    min-width: 0;
  }

  .item-top {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;

    .item-name {
      font-weight: 600;
      color: var(--text-primary);
      font-size: 14px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 180px;
    }

    .status-tag {
      flex-shrink: 0;
    }
  }

  .item-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 12px;
    color: var(--text-secondary);

    .meta-item {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      .el-icon {
        font-size: 12px;
      }
    }
  }

  .item-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
  }
}

.loading-icon {
  animation: rotating 1.2s linear infinite;
  margin-right: 2px;
}

// ===== 日志抽屉 =====
.log-drawer {
  :deep(.el-drawer__header) {
    margin-bottom: 0;
    padding: 18px 24px;
    border-bottom: 1px solid var(--border-light);
  }
  :deep(.el-drawer__body) {
    padding: 0;
  }
}

.drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  .drawer-actions {
    display: flex;
    align-items: center;
    gap: 8px;
  }

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

.log-meta {
  padding: 16px 24px;
  border-bottom: 1px solid var(--border-light);
  background: var(--bg-page);

  .meta-row {
    display: flex;
    padding: 4px 0;
    font-size: 13px;

    .meta-label {
      width: 110px;
      color: var(--text-secondary);
      flex-shrink: 0;
    }
    .meta-value {
      color: var(--text-primary);
      &.mono {
        font-family: $font-family-mono;
        font-size: 12px;
      }
    }
  }
}

.log-terminal {
  margin: 16px 24px 24px;
  border-radius: $radius-md;
  overflow: hidden;
  background: #0e1020;
  border: 1px solid rgba(255, 255, 255, 0.08);
  box-shadow: $shadow-md;

  .terminal-header {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 14px;
    background: rgba(255, 255, 255, 0.04);
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);

    .dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      &.red { background: #ff5a6e; }
      &.yellow { background: #ffb547; }
      &.green { background: #18c29c; }
    }
    .terminal-title {
      margin-left: 8px;
      color: rgba(255, 255, 255, 0.55);
      font-size: 12px;
      font-family: $font-family-mono;
    }
  }

  .terminal-body {
    padding: 16px;
    min-height: 320px;
    max-height: 540px;
    overflow-y: auto;
    @include scrollbar(6px, rgba(109, 92, 255, 0.4));

    pre {
      margin: 0;
      font-family: $font-family-mono;
      font-size: 13px;
      line-height: 1.7;
      color: #b4e8d4;
      white-space: pre-wrap;
      word-break: break-all;
    }

    .terminal-empty {
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      color: rgba(255, 255, 255, 0.4);
      font-size: 13px;

      .loading-icon {
        color: $color-primary-light-5;
      }
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
  .step-indicator .step-line {
    width: 20px;
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
</style>
