<template>
  <div class="config-page">
    <div class="hero-section">
      <div class="hero-content">
        <div class="hero-text">
          <h1 class="hero-title">
            <el-icon class="hero-icon"><SettingIcon /></el-icon>
            GitHub Actions 全自动配置
          </h1>
          <p class="hero-desc">
            配置 GitHub Token 和仓库信息，一键生成 Keystore、SSH 密钥并自动配置 Secrets，
            无需手动操作 GitHub 后台即可完成 APP 构建环境部署。
          </p>
        </div>
        <div class="hero-actions">
          <el-button :icon="RefreshIcon" @click="fetchConfig">重新加载</el-button>
          <el-button type="primary" :icon="ViewIcon" @click="openRepo" :disabled="!config.owner || !config.repo">
            打开仓库
          </el-button>
        </div>
      </div>
    </div>

    <el-row :gutter="20">
      <el-col :span="14">
        <el-card shadow="hover" class="config-card">
          <template #header>
            <div class="card-header">
              <el-icon><KeyIconComp /></el-icon>
              <span>基础配置</span>
              <el-tag :type="configValidated ? 'success' : 'info'" size="small" round>
                {{ configValidated ? '已验证' : '未验证' }}
              </el-tag>
            </div>
          </template>

          <el-form
            ref="formRef"
            :model="config"
            :rules="rules"
            label-position="top"
            class="config-form"
          >
            <el-form-item label="GitHub Token" prop="token">
              <el-input
                v-model="config.token"
                type="password"
                show-password
                placeholder="ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
              >
                <template #append>
                  <el-button
                    :icon="MagicStickIcon"
                    @click="openTokenCreate"
                  >创建 Token</el-button>
                </template>
              </el-input>
              <div class="form-tip">
                需要 <code>repo</code> 和 <code>workflow</code> 权限。
                <el-link type="primary" :href="tokenCreateUrl" target="_blank">前往创建 →</el-link>
              </div>
            </el-form-item>

            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="仓库所有者 (Owner)" prop="owner">
                  <el-input v-model="config.owner" placeholder="如: jiujiu123520" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="仓库名 (Repo)" prop="repo">
                  <el-input v-model="config.repo" placeholder="如: im-push-system" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="Workflow 文件名">
                  <el-input v-model="config.workflow_file" placeholder="build-apk.yml" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="分支 (Ref)">
                  <el-input v-model="config.ref" placeholder="main" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="16">
              <el-col :span="18">
                <el-form-item label="API 代理 (国内服务器推荐)">
                  <el-input v-model="config.api_proxy" placeholder="https://gh.jasonzeng.dev/" :disabled="!config.proxy_enabled" />
                  <div class="form-tip">国内服务器建议配置代理以加速 GitHub API 访问</div>
                </el-form-item>
              </el-col>
              <el-col :span="6">
                <el-form-item label="启用代理">
                  <el-switch v-model="config.proxy_enabled" active-text="开" inactive-text="关" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="超时时间(秒)">
                  <el-input-number v-model="config.timeout" :min="5" :max="120" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="连接测试">
                  <div class="proxy-test-buttons">
                    <el-button :icon="ConnectionIcon" :loading="testingProxy" @click="handleTestProxy" size="default">
                      {{ testingProxy ? '测试中...' : '测试当前模式' }}
                    </el-button>
                    <el-button :icon="DataAnalysisIcon" :loading="comparingProxy" @click="handleCompareProxy" size="default">
                      {{ comparingProxy ? '对比中...' : '对比测试' }}
                    </el-button>
                  </div>
                </el-form-item>
              </el-col>
            </el-row>

            <div v-if="proxyTestResult" class="proxy-test-result">
              <el-alert
                :title="proxyTestResult.message"
                :type="proxyTestResult.success ? 'success' : 'error'"
                :closable="false"
                show-icon
              >
                <template #default v-if="proxyCompareResult">
                  <div class="compare-detail">
                    <div class="compare-item">
                      <span class="compare-label">直连:</span>
                      <span :class="proxyCompareResult.direct.success ? 'text-success' : 'text-error'">
                        {{ proxyCompareResult.direct.latency_ms }}ms
                      </span>
                    </div>
                    <div class="compare-item">
                      <span class="compare-label">代理:</span>
                      <span :class="proxyCompareResult.proxy.success ? 'text-success' : 'text-error'">
                        {{ proxyCompareResult.proxy.latency_ms }}ms
                      </span>
                    </div>
                    <div class="compare-item compare-recommendation">
                      <span class="compare-label">建议:</span>
                      <span>{{ proxyCompareResult.recommendation }}</span>
                    </div>
                  </div>
                </template>
              </el-alert>
            </div>

            <div class="form-actions">
              <el-button
                type="warning"
                plain
                :icon="ViewIcon"
                :loading="validating"
                @click="handleValidate"
              >
                {{ validating ? '验证中...' : '验证配置' }}
              </el-button>
              <el-button
                type="primary"
                :icon="CheckIcon"
                :loading="saving"
                @click="handleSave"
              >
                {{ saving ? '保存中...' : '保存配置' }}
              </el-button>
            </div>
          </el-form>
        </el-card>

        <el-card shadow="hover" class="auto-setup-card" v-loading="autoSettingUp">
          <template #header>
            <div class="card-header">
              <el-icon><MagicStickIcon /></el-icon>
              <span>一键自动配置</span>
              <el-tag type="success" size="small" round>推荐</el-tag>
            </div>
          </template>

          <el-alert
            title="自动生成 Keystore、SSH 密钥并配置 GitHub Secrets"
            type="info"
            :closable="false"
            show-icon
            class="setup-alert"
          >
            <template #default>
              <p>一键配置将自动完成以下步骤：</p>
              <ol class="step-list">
                <li>生成 Android 签名 Keystore</li>
                <li>生成 SSH 密钥对（用于 SCP 上传 APK）</li>
                <li>自动加密并配置 8 个 GitHub Secrets</li>
              </ol>
            </template>
          </el-alert>

          <el-form label-position="top" class="ssh-form">
            <el-row :gutter="16">
              <el-col :span="8">
                <el-form-item label="SSH 端口">
                  <el-input v-model="sshConfig.port" placeholder="22" />
                </el-form-item>
              </el-col>
              <el-col :span="16">
                <el-form-item label="SSH 用户名">
                  <el-input v-model="sshConfig.user" placeholder="如: ubuntu" />
                  <div class="form-tip">GitHub Actions 用此账号 SCP 上传 APK 到服务器</div>
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>

          <div v-if="autoSetupSteps.length > 0" class="steps-panel">
            <el-steps :active="currentStepIndex" direction="vertical">
              <el-step
                v-for="(step, idx) in autoSetupSteps"
                :key="idx"
                :title="step.step"
                :status="stepStatus(step.status)"
                :description="step.message || ''"
              >
                <template v-if="step.failed" #extra>
                  <div class="step-failed-detail">
                    <div v-for="(msg, name) in step.failed" :key="name" class="failed-item">
                      <code>{{ name }}</code>: {{ msg }}
                    </div>
                  </div>
                </template>
              </el-step>
            </el-steps>
          </div>

          <div v-if="setupResult" class="result-panel">
            <el-result
              :icon="setupResult.success ? 'success' : 'warning'"
              :title="setupResult.success ? '配置完成' : '配置部分失败'"
              :sub-title="setupResult.message"
            />
            <div v-if="setupResult.ssh_pub_key" class="ssh-pub-key">
              <h4>SSH 公钥（需添加到服务器）</h4>
              <el-input
                type="textarea"
                :model-value="setupResult.ssh_pub_key"
                :rows="4"
                readonly
                resize="none"
              />
              <div class="form-tip">
                将上面的公钥添加到服务器的 <code>~/.ssh/authorized_keys</code> 文件中，
                确保 GitHub Actions 能通过 SSH 上传 APK。
              </div>
              <el-button type="primary" plain :icon="DocumentCopyIcon" @click="copySshPubKey">
                复制公钥
              </el-button>
            </div>
            <div v-if="setupResult.next_step" class="next-step-tip">
              <el-icon><InfoFilledIcon /></el-icon>
              {{ setupResult.next_step }}
            </div>
          </div>

          <div class="auto-setup-actions">
            <el-button
              type="success"
              size="large"
              :icon="MagicStickIcon"
              :loading="autoSettingUp"
              :disabled="!configValidated"
              @click="handleAutoSetup"
              class="auto-setup-btn"
            >
              {{ autoSettingUp ? '正在配置...' : '🚀 开始一键配置' }}
            </el-button>
            <el-tooltip content="需要先验证配置有效性" placement="bottom">
              <div class="tip-wrapper">
                <el-icon><InfoFilledIcon /></el-icon>
                <span>请先验证基础配置后再使用一键配置</span>
              </div>
            </el-tooltip>
          </div>
        </el-card>
      </el-col>

      <el-col :span="10">
        <el-card shadow="hover" class="check-card">
          <template #header>
            <div class="card-header">
              <el-icon><MonitorIcon /></el-icon>
              <span>配置检测</span>
              <el-button
                text
                :icon="RefreshIcon"
                :loading="checking"
                @click="handleCheck"
                class="refresh-btn"
              >检测</el-button>
            </div>
          </template>

          <div v-loading="checking" class="check-list">
            <div v-if="!checkResult && !checking" class="empty-check">
              <el-empty description="点击上方「检测」按钮查看配置状态" :image-size="80" />
            </div>

            <div v-else class="check-items">
              <div
                v-for="(check, key) in checkResult?.checks || []"
                :key="key"
                class="check-item"
                :class="`status-${check.status}`"
              >
                <div class="check-icon">
                  <el-icon v-if="check.status === 'ok'"><CircleCheckFilledIcon /></el-icon>
                  <el-icon v-else-if="check.status === 'warning'"><WarningFilledIcon /></el-icon>
                  <el-icon v-else><CircleCloseFilledIcon /></el-icon>
                </div>
                <div class="check-info">
                  <div class="check-name">{{ checkLabel(key) }}</div>
                  <div class="check-desc">{{ check.message }}</div>
                  <div v-if="check.missing && check.missing.length" class="missing-list">
                    <span class="missing-title">缺少：</span>
                    <el-tag
                      v-for="name in check.missing"
                      :key="name"
                      size="small"
                      type="danger"
                      effect="light"
                    >
                      {{ name }}
                    </el-tag>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="checkResult" class="check-summary">
              <el-alert
                :title="checkResult.summary"
                :type="checkResult.status === 'ready' ? 'success' : 'warning'"
                :closable="false"
                show-icon
              />
            </div>
          </div>
        </el-card>

        <el-card shadow="hover" class="secrets-card">
          <template #header>
            <div class="card-header">
              <el-icon><LockIcon /></el-icon>
              <span>必需 Secrets 清单</span>
            </div>
          </template>

          <el-table :data="requiredSecrets" size="small" border class="secrets-table">
            <el-table-column prop="name" label="Secret 名称" width="220">
              <template #default="{ row }">
                <code class="code-text">{{ row.name }}</code>
              </template>
            </el-table-column>
            <el-table-column prop="description" label="说明" />
            <el-table-column label="状态" width="80" align="center">
              <template #default="{ row }">
                <el-icon
                  v-if="existingSecrets.includes(row.name)"
                  class="status-ok"
                ><CircleCheckFilledIcon /></el-icon>
                <el-icon v-else class="status-missing">
                  <CircleCloseFilledIcon />
                </el-icon>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import {
  Setting as SettingIcon,
  Refresh as RefreshIcon,
  View as ViewIcon,
  Key as KeyIconComp,
  MagicStick as MagicStickIcon,
  Check as CheckIcon,
  DocumentCopy as DocumentCopyIcon,
  InfoFilled as InfoFilledIcon,
  Monitor as MonitorIcon,
  CircleCheckFilled as CircleCheckFilledIcon,
  WarningFilled as WarningFilledIcon,
  CircleCloseFilled as CircleCloseFilledIcon,
  Lock as LockIcon,
  Connection as ConnectionIcon,
  DataAnalysis as DataAnalysisIcon
} from '@element-plus/icons-vue'
import {
  getGithubConfigApi,
  saveGithubConfigApi,
  validateGithubConfigApi,
  checkGithubConfigApi,
  autoSetupGithubApi,
  testProxyApi,
  compareProxyApi
} from '@/api/appBuild'

const formRef = ref<FormInstance>()
const saving = ref(false)
const validating = ref(false)
const checking = ref(false)
const autoSettingUp = ref(false)
const configValidated = ref(false)

const config = reactive({
  token: '',
  owner: '',
  repo: '',
  workflow_file: 'build-apk.yml',
  ref: 'main',
  api_proxy: '',
  proxy_enabled: true,
  timeout: 30
})

const testingProxy = ref(false)
const comparingProxy = ref(false)
const proxyTestResult = ref<{
  success: boolean
  message: string
  latency_ms: number
} | null>(null)
const proxyCompareResult = ref<{
  direct: {
    success: boolean
    message: string
    latency_ms: number
  }
  proxy: {
    success: boolean
    message: string
    latency_ms: number
  }
  recommendation: string
} | null>(null)

const sshConfig = reactive({
  port: '22',
  user: ''
})

const rules: FormRules = {
  token: [{ required: true, message: '请输入 GitHub Token', trigger: 'blur' }],
  owner: [{ required: true, message: '请输入仓库所有者', trigger: 'blur' }],
  repo: [{ required: true, message: '请输入仓库名', trigger: 'blur' }]
}

const tokenCreateUrl = 'https://github.com/settings/tokens'

const checkResult = ref<{
  status: string
  checks: Record<string, {
    status: string
    message: string
    total?: number
    existing?: string[]
    missing?: string[]
    required?: string[]
  }>
  summary: string
} | null>(null)

const autoSetupSteps = ref<Array<{
  step: string
  status: string
  message?: string
  failed?: Record<string, string>
}>>([])

const setupResult = ref<{
  success: boolean
  message: string
  ssh_pub_key?: string
  setup_info?: {
    keystore_path: string
    ssh_pub_path: string
    server_host: string
    ssh_port: string
    ssh_user: string
  }
  next_step?: string
} | null>(null)

const currentStepIndex = computed(() => {
  let idx = 0
  for (let i = 0; i < autoSetupSteps.value.length; i++) {
    const s = autoSetupSteps.value[i]
    if (s.status === 'running') {
      idx = i
      break
    }
    if (s.status === 'success' || s.status === 'warning') {
      idx = i + 1
    }
    if (s.status === 'failed') {
      idx = i
      break
    }
  }
  return idx
})

const requiredSecrets = [
  { name: 'APK_KEYSTORE_BASE64', description: 'keystore 文件 base64 编码' },
  { name: 'APK_KEYSTORE_PASSWORD', description: 'keystore 密码' },
  { name: 'APK_KEY_ALIAS', description: '密钥别名（通常 release）' },
  { name: 'APK_KEY_PASSWORD', description: '密钥密码' },
  { name: 'SERVER_SSH_HOST', description: '服务器 IP 地址' },
  { name: 'SERVER_SSH_PORT', description: 'SSH 端口（通常 22）' },
  { name: 'SERVER_SSH_USER', description: 'SSH 登录用户名' },
  { name: 'SERVER_SSH_KEY', description: 'SSH 私钥（完整内容）' }
]

const existingSecrets = computed(() => {
  const secretsCheck = checkResult.value?.checks?.secrets
  return secretsCheck?.existing || []
})

async function fetchConfig() {
  try {
    const res = await getGithubConfigApi()
    const data = (res as any).data || res
    Object.assign(config, data)
  } catch (e) {
    console.warn('获取配置失败', e)
  }
}

function openTokenCreate() {
  window.open(tokenCreateUrl, '_blank')
}

function openRepo() {
  if (config.owner && config.repo) {
    window.open(`https://github.com/${config.owner}/${config.repo}`, '_blank')
  }
}

async function handleValidate() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    ElMessage.warning('请完善必填项')
    return
  }

  validating.value = true
  try {
    const res = await validateGithubConfigApi({
      token: config.token,
      owner: config.owner,
      repo: config.repo
    })
    const data = (res as any).data || res
    if (data.valid) {
      configValidated.value = true
      ElMessage.success(data.message || '配置验证通过')
    } else {
      configValidated.value = false
      ElMessage.error(data.message || '验证失败')
    }
  } catch (err: any) {
    configValidated.value = false
    ElMessage.error(err?.message || '验证失败')
  } finally {
    validating.value = false
  }
}

async function handleSave() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    ElMessage.warning('请完善必填项')
    return
  }

  saving.value = true
  try {
    const res = await saveGithubConfigApi({
      token: config.token,
      owner: config.owner,
      repo: config.repo,
      workflow_file: config.workflow_file,
      ref: config.ref,
      api_proxy: config.api_proxy,
      proxy_enabled: config.proxy_enabled,
      timeout: config.timeout
    })
    const data = (res as any).data || res
    ElMessage.success(data.message || '保存成功')
    configValidated.value = true
  } catch (err: any) {
    ElMessage.error(err?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

async function handleTestProxy() {
  testingProxy.value = true
  proxyCompareResult.value = null
  try {
    const res = await testProxyApi({
      api_proxy: config.api_proxy,
      proxy_enabled: config.proxy_enabled,
      timeout: Math.min(config.timeout, 15)
    })
    const result = (res as any).data || res
    proxyTestResult.value = result
    if (result.success) {
      ElMessage.success(result.message)
    } else {
      ElMessage.error(result.message)
    }
  } catch (err: any) {
    proxyTestResult.value = {
      success: false,
      message: err?.message || '测试失败',
      latency_ms: 0
    }
    ElMessage.error(err?.message || '测试失败')
  } finally {
    testingProxy.value = false
  }
}

async function handleCompareProxy() {
  comparingProxy.value = true
  proxyTestResult.value = null
  try {
    const res = await compareProxyApi({
      api_proxy: config.api_proxy,
      timeout: Math.min(config.timeout, 15)
    })
    const result = (res as any).data || res
    proxyCompareResult.value = result
    // 同时设置一个总结果用于显示
    const rec = result.recommendation
    proxyTestResult.value = {
      success: result.proxy.success || result.direct.success,
      message: rec,
      latency_ms: 0
    }
    ElMessage.info(rec)
  } catch (err: any) {
    proxyTestResult.value = {
      success: false,
      message: err?.message || '对比测试失败',
      latency_ms: 0
    }
    ElMessage.error(err?.message || '对比测试失败')
  } finally {
    comparingProxy.value = false
  }
}

async function handleCheck() {
  checking.value = true
  try {
    const res = await checkGithubConfigApi()
    checkResult.value = (res as any).data || res
  } catch (err: any) {
    ElMessage.error(err?.message || '检测失败')
  } finally {
    checking.value = false
  }
}

async function handleAutoSetup() {
  if (!configValidated.value) {
    ElMessage.warning('请先验证配置有效性')
    return
  }

  autoSettingUp.value = true
  autoSetupSteps.value = []
  setupResult.value = null

  try {
    const res = await autoSetupGithubApi({
      ssh_port: sshConfig.port,
      ssh_user: sshConfig.user
    })
    const data = (res as any).data || res
    autoSetupSteps.value = data.steps || []
    setupResult.value = {
      success: data.success,
      message: data.message,
      ssh_pub_key: data.ssh_pub_key,
      setup_info: data.setup_info,
      next_step: data.next_step
    }

    if (data.success) {
      ElMessage.success('一键配置完成！')
    } else {
      ElMessage.warning(data.message || '部分配置失败')
    }
  } catch (err: any) {
    ElMessage.error(err?.message || '一键配置失败')
  } finally {
    autoSettingUp.value = false
  }
}

function stepStatus(status: string): '' | 'wait' | 'process' | 'finish' | 'error' | 'success' {
  const map: Record<string, '' | 'wait' | 'process' | 'finish' | 'error' | 'success'> = {
    running: 'process',
    success: 'success',
    warning: 'finish',
    failed: 'error',
    pending: 'wait'
  }
  return map[status] || 'wait'
}

function checkLabel(key: string): string {
  const map: Record<string, string> = {
    repo: '仓库访问',
    workflow: 'Workflow 文件',
    secrets: 'Secrets 配置',
    sodium: 'PHP sodium 扩展'
  }
  return map[key] || key
}

async function copySshPubKey() {
  if (!setupResult.value?.ssh_pub_key) return
  try {
    await navigator.clipboard.writeText(setupResult.value.ssh_pub_key)
    ElMessage.success('公钥已复制到剪贴板')
  } catch {
    ElMessage.warning('复制失败，请手动复制')
  }
}

onMounted(() => {
  fetchConfig()
})
</script>

<style lang="scss" scoped>
.config-page {
  padding: 20px;
  animation: fade-up 0.4s ease;
}

@keyframes fade-up {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.hero-section {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 16px;
  padding: 32px;
  margin-bottom: 20px;
  color: #fff;
  position: relative;
  overflow: hidden;

  &::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(255,255,255,0.15), transparent 70%);
    border-radius: 50%;
  }
}

.hero-content {
  position: relative;
  z-index: 1;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
}

.hero-text {
  flex: 1;
  min-width: 300px;
}

.hero-title {
  font-size: 24px;
  font-weight: 700;
  margin: 0 0 10px 0;
  display: flex;
  align-items: center;
  gap: 12px;
}

.hero-icon {
  font-size: 28px;
}

.hero-desc {
  font-size: 14px;
  opacity: 0.9;
  margin: 0;
  line-height: 1.7;
  max-width: 600px;
}

.hero-actions {
  display: flex;
  gap: 12px;
}

.config-card,
.auto-setup-card,
.check-card,
.secrets-card {
  margin-bottom: 20px;

  :deep(.el-card__header) {
    padding: 16px 20px;
  }

  :deep(.el-card__body) {
    padding: 20px;
  }
}

.card-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  font-size: 15px;

  .el-icon {
    color: #409eff;
    font-size: 18px;
  }

  .el-tag {
    margin-left: auto;
  }

  .refresh-btn {
    margin-left: auto;
  }
}

.config-form {
  .form-tip {
    font-size: 12px;
    color: #909399;
    margin-top: 4px;

    code {
      background: #f5f7fa;
      padding: 1px 6px;
      border-radius: 4px;
      font-size: 11px;
      color: #f56c6c;
    }
  }
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 8px;
}

.setup-alert {
  margin-bottom: 20px;

  .step-list {
    margin: 8px 0 0 0;
    padding-left: 20px;

    li {
      margin-bottom: 4px;
      font-size: 13px;
    }
  }
}

.ssh-form {
  margin-bottom: 16px;

  .form-tip {
    font-size: 12px;
    color: #909399;
    margin-top: 4px;
  }
}

.steps-panel {
  margin: 20px 0;
  padding: 16px;
  background: #fafafa;
  border-radius: 8px;
  border: 1px solid #ebeef5;

  :deep(.el-step__title) {
    font-weight: 600;
  }
}

.step-failed-detail {
  margin-top: 8px;
  padding: 8px 12px;
  background: #fef0f0;
  border-radius: 4px;

  .failed-item {
    font-size: 12px;
    color: #f56c6c;

    code {
      background: #fff;
      padding: 1px 4px;
      border-radius: 3px;
      margin-right: 4px;
    }
  }
}

.result-panel {
  margin: 20px 0;

  .ssh-pub-key {
    margin-top: 16px;
    padding: 16px;
    background: #f4f9f4;
    border-radius: 8px;
    border: 1px solid #e1f3d8;

    h4 {
      margin: 0 0 10px 0;
      font-size: 14px;
      color: #67c23a;
    }

    .form-tip {
      font-size: 12px;
      color: #909399;
      margin: 8px 0;

      code {
        background: #fff;
        padding: 1px 6px;
        border-radius: 4px;
        font-size: 11px;
        color: #67c23a;
      }
    }
  }

  .next-step-tip {
    margin-top: 12px;
    padding: 10px 14px;
    background: #fdf6ec;
    border-radius: 6px;
    font-size: 13px;
    color: #e6a23c;
    display: flex;
    align-items: center;
    gap: 6px;
  }
}

.auto-setup-actions {
  text-align: center;
  padding-top: 8px;

  .auto-setup-btn {
    padding: 0 40px;
    margin-bottom: 8px;
  }

  .tip-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    font-size: 12px;
    color: #909399;
  }
}

.check-list {
  min-height: 200px;
}

.empty-check {
  padding: 20px 0;
}

.check-items {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 16px;
}

.check-item {
  display: flex;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 8px;
  background: #fafafa;
  border: 1px solid #ebeef5;

  &.status-ok {
    background: #f0f9eb;
    border-color: #e1f3d8;
  }

  &.status-warning {
    background: #fdf6ec;
    border-color: #faecd8;
  }

  &.status-error {
    background: #fef0f0;
    border-color: #fbc4c4;
  }
}

.check-icon {
  flex-shrink: 0;
  font-size: 20px;

  .status-ok & {
    color: #67c23a;
  }

  .status-warning & {
    color: #e6a23c;
  }

  .status-error & {
    color: #f56c6c;
  }
}

.check-info {
  flex: 1;
}

.check-name {
  font-weight: 600;
  font-size: 14px;
  margin-bottom: 4px;
}

.check-desc {
  font-size: 13px;
  color: #606266;
}

.missing-list {
  margin-top: 8px;

  .missing-title {
    font-size: 12px;
    color: #f56c6c;
    margin-right: 6px;
  }

  .el-tag {
    margin-right: 4px;
    margin-bottom: 4px;
  }
}

.check-summary {
  margin-top: 12px;
}

.secrets-table {
  .code-text {
    font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
    font-size: 12px;
    padding: 2px 6px;
    background: #f5f7fa;
    border-radius: 3px;
    color: #f56c6c;
  }

  .status-ok {
    color: #67c23a;
  }

  .status-missing {
    color: #c0c4cc;
  }
}

.proxy-test-buttons {
  display: flex;
  gap: 8px;
}

.proxy-test-result {
  margin-top: 8px;
  margin-bottom: 16px;
}

.compare-detail {
  margin-top: 8px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 13px;

  .compare-item {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .compare-label {
    font-weight: 600;
    min-width: 50px;
  }

  .text-success {
    color: #67c23a;
    font-weight: 600;
  }

  .text-error {
    color: #f56c6c;
    font-weight: 600;
  }

  .compare-recommendation {
    margin-top: 4px;
    padding-top: 6px;
    border-top: 1px dashed rgba(0, 0, 0, 0.1);
  }
}
</style>
