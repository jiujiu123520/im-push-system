<template>
  <div class="register-page">
    <!-- 动态背景 -->
    <div class="bg-layer">
      <div class="bg-blob blob-1"></div>
      <div class="bg-blob blob-2"></div>
      <div class="bg-grid"></div>
    </div>

    <!-- 注册卡片 -->
    <div class="register-card">
      <div class="card-header">
        <h2 class="card-title">用户注册</h2>
        <p class="card-subtitle">注册后即可使用推送服务</p>
      </div>

      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        size="large"
        label-position="top"
        class="register-form"
      >
        <el-form-item prop="username" label="用户名">
          <el-input
            v-model="form.username"
            placeholder="3-64 位字符"
            :prefix-icon="UserIcon"
            clearable
          />
        </el-form-item>

        <el-form-item prop="password" label="密码">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="6-64 位字符"
            :prefix-icon="LockIcon"
            show-password
            clearable
          />
        </el-form-item>

        <el-form-item prop="phone" label="手机号">
          <el-input
            v-model="form.phone"
            placeholder="中国大陆手机号（与邮箱二选一）"
            :prefix-icon="PhoneIcon"
            clearable
          />
        </el-form-item>

        <el-form-item prop="email" label="邮箱">
          <el-input
            v-model="form.email"
            placeholder="邮箱（与手机号二选一）"
            :prefix-icon="MessageIcon"
            clearable
          />
        </el-form-item>

        <el-divider content-position="center">验证码</el-divider>

        <el-form-item prop="codeType" label="验证方式">
          <el-radio-group v-model="form.codeType" @change="onCodeTypeChange">
            <el-radio value="sms">短信验证</el-radio>
            <el-radio value="email">邮箱验证</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item prop="codeTarget" :label="form.codeType === 'sms' ? '接收手机号' : '接收邮箱'">
          <el-input
            v-model="form.codeTarget"
            :placeholder="form.codeType === 'sms' ? '请输入接收验证码的手机号' : '请输入接收验证码的邮箱'"
            clearable
          />
        </el-form-item>

        <el-form-item prop="codeInput" label="验证码">
          <div class="code-row">
            <el-input
              v-model="form.codeInput"
              placeholder="请输入收到的验证码"
              :prefix-icon="KeyIcon"
              clearable
            />
            <el-button
              type="primary"
              :disabled="sendCooldown > 0 || sendingCode"
              :loading="sendingCode"
              @click="handleSendCode"
            >
              {{ sendCooldown > 0 ? `${sendCooldown}s` : '发送验证码' }}
            </el-button>
          </div>
        </el-form-item>

        <el-button
          type="primary"
          class="submit-btn"
          :loading="loading"
          @click="handleRegister"
        >
          <span v-if="!loading">注 册</span>
          <span v-else>注册中...</span>
        </el-button>
      </el-form>

      <div class="card-footer">
        <span>已有账号？</span>
        <a @click="goLogin">返回登录</a>
      </div>

      <!-- 安全码展示对话框（注册成功后展示，仅一次） -->
      <el-dialog
        v-model="securityDialogVisible"
        title="安全码"
        width="440px"
        :close-on-click-modal="false"
        :close-on-press-escape="false"
        :show-close="false"
      >
        <div class="security-content">
          <el-alert
            type="warning"
            :closable="false"
            title="请妥善保存此安全码"
            description="安全码仅展示一次，忘记密码时需通过安全码重置，无法再次查看。"
            show-icon
          />
          <div class="security-code-box">
            <div class="security-code-label">您的安全码</div>
            <div class="security-code-value">{{ securityCode }}</div>
            <el-button type="primary" plain size="small" @click="copySecurityCode">
              <el-icon><CopyIcon /></el-icon>
              复制安全码
            </el-button>
          </div>
        </div>
        <template #footer>
          <el-button type="primary" @click="handleSecurityConfirm">我已保存，去登录</el-button>
        </template>
      </el-dialog>
    </div>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  User as UserIcon,
  Lock as LockIcon,
  Phone as PhoneIcon,
  Message as MessageIcon,
  Key as KeyIcon,
  CopyDocument as CopyIcon
} from '@element-plus/icons-vue'
import { registerApi, sendCodeApi } from '@/api/auth'
import type { RegisterParams } from '@/api/types'

const router = useRouter()
const formRef = ref<FormInstance>()
const loading = ref(false)
const sendingCode = ref(false)
const sendCooldown = ref(0)
let cooldownTimer: ReturnType<typeof setInterval> | null = null

const form = reactive<{
  username: string
  password: string
  phone: string
  email: string
  codeType: 'sms' | 'email'
  codeTarget: string
  codeInput: string
}>({
  username: '',
  password: '',
  phone: '',
  email: '',
  codeType: 'sms',
  codeTarget: '',
  codeInput: ''
})

const rules: FormRules = {
  username: [
    { required: true, message: '请输入用户名', trigger: 'blur' },
    { min: 3, max: 64, message: '用户名长度需在 3-64 之间', trigger: 'blur' }
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 6, max: 64, message: '密码长度需在 6-64 之间', trigger: 'blur' }
  ],
  codeInput: [{ required: true, message: '请输入验证码', trigger: 'blur' }]
}

// 验证方式切换时同步 codeTarget
function onCodeTypeChange() {
  form.codeTarget = ''
  form.codeInput = ''
}

// 校验手机号或邮箱至少填写一项，且与验证方式一致
function validateContact(): string | null {
  if (form.phone === '' && form.email === '') {
    return '手机号与邮箱至少填写一项'
  }
  if (form.phone !== '' && !/^1[3-9]\d{9}$/.test(form.phone)) {
    return '手机号格式不正确'
  }
  if (form.email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
    return '邮箱格式不正确'
  }
  // 验证码目标与注册信息一致
  if (form.codeType === 'sms') {
    if (form.phone === '') return '使用短信验证时需填写手机号'
    if (form.codeTarget !== form.phone) return '接收验证码的手机号与注册手机号不一致'
  } else {
    if (form.email === '') return '使用邮箱验证时需填写邮箱'
    if (form.codeTarget !== form.email) return '接收验证码的邮箱与注册邮箱不一致'
  }
  return null
}

// 发送验证码
async function handleSendCode() {
  if (form.codeTarget === '') {
    ElMessage.warning(form.codeType === 'sms' ? '请输入接收验证码的手机号' : '请输入接收验证码的邮箱')
    return
  }
  // 基础格式校验
  if (form.codeType === 'sms' && !/^1[3-9]\d{9}$/.test(form.codeTarget)) {
    ElMessage.error('手机号格式不正确')
    return
  }
  if (form.codeType === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.codeTarget)) {
    ElMessage.error('邮箱格式不正确')
    return
  }

  sendingCode.value = true
  try {
    const res = await sendCodeApi({ type: form.codeType, target: form.codeTarget })
    ElMessage.success(res.data?.message || '验证码已发送')
    // 启动 60 秒倒计时
    sendCooldown.value = 60
    cooldownTimer = setInterval(() => {
      sendCooldown.value--
      if (sendCooldown.value <= 0 && cooldownTimer) {
        clearInterval(cooldownTimer)
        cooldownTimer = null
      }
    }, 1000)
  } catch (err) {
    // request.ts 已统一弹错误
  } finally {
    sendingCode.value = false
  }
}

// 注册
async function handleRegister() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }

  const contactError = validateContact()
  if (contactError) {
    ElMessage.error(contactError)
    return
  }

  loading.value = true
  try {
    const params: RegisterParams = {
      username: form.username,
      phone: form.phone,
      email: form.email,
      password: form.password,
      code_type: form.codeType,
      code_target: form.codeTarget,
      code_input: form.codeInput
    }
    const res = await registerApi(params)
    securityCode.value = res.data?.security_code || ''
    securityDialogVisible.value = true
    ElMessage.success('注册成功')
  } catch (err) {
    // request.ts 已统一弹错误
  } finally {
    loading.value = false
  }
}

// 安全码展示对话框
const securityDialogVisible = ref(false)
const securityCode = ref('')

function copySecurityCode() {
  if (!navigator.clipboard) {
    ElMessage.warning('当前浏览器不支持自动复制，请手动选择并复制')
    return
  }
  navigator.clipboard.writeText(securityCode.value).then(() => {
    ElMessage.success('安全码已复制到剪贴板')
  }).catch(() => {
    ElMessage.warning('复制失败，请手动选择并复制')
  })
}

function handleSecurityConfirm() {
  securityDialogVisible.value = false
  ElMessageBox.alert(
    '请确认已妥善保存安全码，关闭后无法再次查看。如忘记密码需通过安全码重置。',
    '安全提示',
    { type: 'warning', confirmButtonText: '我已确认' }
  ).finally(() => {
    router.push('/login')
  })
}

function goLogin() {
  router.push('/login')
}

onUnmounted(() => {
  if (cooldownTimer) {
    clearInterval(cooldownTimer)
  }
})
</script>

<style lang="scss" scoped>
.register-page {
  position: relative;
  width: 100%;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
  overflow: hidden;
  background: linear-gradient(135deg, #f5f6fb 0%, #eceeff 100%);
}

.bg-layer {
  position: absolute;
  inset: 0;
  z-index: 0;
  overflow: hidden;
}

.bg-blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.55;
  animation: float 8s ease-in-out infinite;

  &.blob-1 {
    width: 480px;
    height: 480px;
    background: radial-gradient(circle, #9b5cff, transparent 70%);
    top: -120px;
    left: -100px;
  }
  &.blob-2 {
    width: 520px;
    height: 520px;
    background: radial-gradient(circle, #5cb8ff, transparent 70%);
    bottom: -160px;
    right: -120px;
    animation-delay: -3s;
  }
}

.bg-grid {
  position: absolute;
  inset: 0;
  background-image: linear-gradient(rgba(109, 92, 255, 0.04) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(109, 92, 255, 0.04) 1px, transparent 1px);
  background-size: 40px 40px;
  mask-image: radial-gradient(circle at center, black 30%, transparent 70%);
}

.register-card {
  position: relative;
  z-index: 1;
  width: 480px;
  max-width: 100%;
  background: rgba(255, 255, 255, 0.78);
  backdrop-filter: blur(24px) saturate(180%);
  -webkit-backdrop-filter: blur(24px) saturate(180%);
  border: 1px solid rgba(255, 255, 255, 0.6);
  border-radius: 28px;
  padding: 44px 40px 32px;
  box-shadow: 0 20px 60px rgba(109, 92, 255, 0.18),
              0 4px 16px rgba(31, 35, 64, 0.08);
  animation: zoom-in 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.card-header {
  margin-bottom: 24px;
  text-align: center;

  .card-title {
    font-size: 26px;
    font-weight: 800;
    color: #1a1d2e;
  }
  .card-subtitle {
    margin-top: 8px;
    font-size: 14px;
    color: #7e83a3;
  }
}

.register-form {
  :deep(.el-input__wrapper) {
    height: 44px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.7);
    box-shadow: 0 0 0 1px rgba(109, 92, 255, 0.12) inset;

    &.is-focus {
      box-shadow: 0 0 0 2px $color-primary inset;
    }
  }
}

.code-row {
  display: flex;
  gap: 12px;
  width: 100%;

  .el-input {
    flex: 1;
  }
}

.submit-btn {
  width: 100%;
  height: 48px;
  margin-top: 8px;
  border-radius: 14px;
  font-size: 16px;
  font-weight: 700;
  letter-spacing: 2px;
  background: $gradient-primary;
  border: none;
  box-shadow: $shadow-primary;
  transition: all 0.3s ease;

  &:hover {
    transform: translateY(-2px);
    box-shadow: $shadow-primary-lg;
  }
}

.card-footer {
  margin-top: 24px;
  text-align: center;
  font-size: 13px;
  color: #7e83a3;

  a {
    color: $color-primary;
    cursor: pointer;
    margin-left: 4px;
    font-weight: 600;
    &:hover {
      text-decoration: underline;
    }
  }
}

.security-content {
  .security-code-box {
    margin-top: 16px;
    padding: 20px;
    background: rgba(109, 92, 255, 0.06);
    border-radius: 12px;
    text-align: center;

    .security-code-label {
      font-size: 13px;
      color: #7e83a3;
      margin-bottom: 8px;
    }
    .security-code-value {
      font-size: 28px;
      font-weight: 800;
      color: $color-primary;
      letter-spacing: 4px;
      font-family: 'Courier New', monospace;
      margin-bottom: 16px;
    }
  }
}

@keyframes float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-16px); }
}

@keyframes zoom-in {
  from {
    opacity: 0;
    transform: scale(0.94) translateY(20px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

@media (max-width: 480px) {
  .register-card {
    padding: 32px 24px 24px;
    border-radius: 20px;
  }
}

:global(html.dark) {
  .register-page {
    background: linear-gradient(135deg, #0e1020 0%, #14122a 100%);
  }
  .register-card {
    background: rgba(22, 24, 48, 0.72);
    border-color: rgba(109, 92, 255, 0.2);
  }
  .card-title {
    color: #e8eaf6;
  }
  .card-subtitle,
  .card-footer {
    color: #7e83a3;
  }
}
</style>
