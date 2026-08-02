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
            :placeholder="'至少 8 位，必须包含大小写字母和数字'"
            :prefix-icon="LockIcon"
            show-password
            clearable
          />
          <!-- 密码规则实时提示 & 强度条 -->
          <div v-if="form.password" class="password-rules">
            <div class="password-strength" aria-label="密码强度">
              <div
                v-for="i in 4"
                :key="i"
                class="strength-bar"
                :class="{ active: passwordStrength >= i, [strengthColorClass]: passwordStrength >= i }"
              />
            </div>
            <div class="rule-list">
              <div
                class="rule-item"
                :class="{ ok: form.password.length >= 8 && form.password.length <= 64 }"
              >
                <el-icon v-if="form.password.length >= 8 && form.password.length <= 64"><CircleCheckFilled /></el-icon>
                <el-icon v-else><CircleCloseFilled /></el-icon>
                <span>长度 8-64 位</span>
              </div>
              <div
                class="rule-item"
                :class="{ ok: /[a-z]/.test(form.password) }"
              >
                <el-icon v-if="/[a-z]/.test(form.password)"><CircleCheckFilled /></el-icon>
                <el-icon v-else><CircleCloseFilled /></el-icon>
                <span>包含小写字母</span>
              </div>
              <div
                class="rule-item"
                :class="{ ok: /[A-Z]/.test(form.password) }"
              >
                <el-icon v-if="/[A-Z]/.test(form.password)"><CircleCheckFilled /></el-icon>
                <el-icon v-else><CircleCloseFilled /></el-icon>
                <span>包含大写字母</span>
              </div>
              <div
                class="rule-item"
                :class="{ ok: /\d/.test(form.password) }"
              >
                <el-icon v-if="/\d/.test(form.password)"><CircleCheckFilled /></el-icon>
                <el-icon v-else><CircleCloseFilled /></el-icon>
                <span>包含数字</span>
              </div>
            </div>
          </div>
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

        <template v-if="captchaEnabled && (smsEnabled || emailEnabled)">
          <el-form-item prop="codeType" label="验证方式" v-if="smsEnabled && emailEnabled">
            <el-radio-group v-model="form.codeType" @change="onCodeTypeChange">
              <el-radio value="sms">短信验证</el-radio>
              <el-radio value="email">邮箱验证</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-alert
            v-else-if="smsEnabled && !emailEnabled"
            type="info"
            :closable="false"
            title="当前仅启用短信验证"
            description="邮箱验证码已关闭，将使用短信验证码验证手机号。"
            show-icon
            style="margin-bottom: 16px;"
          />
          <el-alert
            v-else-if="!smsEnabled && emailEnabled"
            type="info"
            :closable="false"
            title="当前仅启用邮箱验证"
            description="短信验证码已关闭，将使用邮箱验证码验证邮箱。"
            show-icon
            style="margin-bottom: 16px;"
          />

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
        </template>

        <el-alert
          v-else-if="!captchaEnabled"
          type="success"
          :closable="false"
          title="验证码功能已关闭"
          description="当前无需填写验证码即可完成注册。"
          show-icon
          style="margin-bottom: 16px;"
        />

        <el-alert
          v-else
          type="success"
          :closable="false"
          title="短信和邮箱验证均已关闭"
          description="当前无需填写验证码即可完成注册。"
          show-icon
          style="margin-bottom: 16px;"
        />

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
          <el-button type="primary" @click="handleSecurityConfirm">我已保存，自动登录</el-button>
        </template>
      </el-dialog>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  User as UserIcon,
  Lock as LockIcon,
  Phone as PhoneIcon,
  Message as MessageIcon,
  Key as KeyIcon,
  CopyDocument as CopyIcon,
  CircleCheckFilled,
  CircleCloseFilled
} from '@element-plus/icons-vue'
import { registerApi, sendCodeApi, getCaptchaApi } from '@/api/auth'
import type { RegisterParams } from '@/api/types'
import { setToken, removeToken } from '@/utils/auth'
import { useUserStore } from '@/stores/user'

const router = useRouter()
const formRef = ref<FormInstance>()
const loading = ref(false)
const sendingCode = ref(false)
const sendCooldown = ref(0)
let cooldownTimer: ReturnType<typeof setInterval> | null = null

// 验证码开关（由后端 /captcha/image 返回的 enabled 字段控制）
const captchaEnabled = ref(true)
// 短信/邮箱验证码独立开关
const smsEnabled = ref(true)
const emailEnabled = ref(true)

// 当前是否需要验证码（总开关开启 且 至少一个独立开关开启）
const needCaptcha = computed(() => captchaEnabled.value && (smsEnabled.value || emailEnabled.value))

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

// 密码强度：1=弱 2=中 3=强 4=很强
const passwordStrength = computed(() => {
  const pwd = form.password
  if (!pwd) return 0
  let score = 0
  if (pwd.length >= 8) score++
  if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) score++
  if (/\d/.test(pwd)) score++
  if (/[^a-zA-Z0-9]/.test(pwd) || pwd.length >= 14) score++
  return score
})
const strengthColorClass = computed(() => {
  if (passwordStrength.value <= 1) return 'weak'
  if (passwordStrength.value === 2) return 'medium'
  if (passwordStrength.value === 3) return 'strong'
  return 'very-strong'
})

const rules = computed<FormRules>(() => ({
  username: [
    { required: true, message: '请输入用户名', trigger: 'blur' },
    { min: 3, max: 64, message: '用户名长度需在 3-64 之间', trigger: 'blur' }
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    // 前端严格校验：8位+大小写字母+数字（与后端 validatePasswordStrength 保持一致）
    {
      validator: (_rule, value, callback) => {
        if (!value) return callback(new Error('请输入密码'))
        if (value.length < 8 || value.length > 64) {
          return callback(new Error('密码长度需在 8-64 之间'))
        }
        if (!/[a-z]/.test(value)) return callback(new Error('密码必须包含小写字母'))
        if (!/[A-Z]/.test(value)) return callback(new Error('密码必须包含大写字母'))
        if (!/\d/.test(value)) return callback(new Error('密码必须包含数字'))
        callback()
      },
      trigger: 'blur'
    }
  ],
  // 需要验证码时才强制必填
  codeInput: needCaptcha.value
    ? [{ required: true, message: '请输入验证码', trigger: 'blur' }]
    : []
}))

// onMounted 时获取验证码开关状态
async function fetchCaptchaStatus() {
  try {
    const res = await getCaptchaApi()
    captchaEnabled.value = res.data?.enabled !== false
    smsEnabled.value = res.data?.smsEnabled !== false
    emailEnabled.value = res.data?.emailEnabled !== false
    // 根据独立开关自动调整默认验证方式
    if (!smsEnabled.value && emailEnabled.value) {
      form.codeType = 'email'
    } else if (smsEnabled.value && !emailEnabled.value) {
      form.codeType = 'sms'
    }
  } catch {
    // 获取失败时保持默认开启，确保安全
  }
}

// 验证方式切换时同步 codeTarget
function onCodeTypeChange() {
  form.codeTarget = ''
  form.codeInput = ''
}

// 校验手机号或邮箱至少填写一项，且与验证方式一致（验证码关闭时跳过验证码目标校验）
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
  // 不需要验证码时跳过验证码目标与注册信息一致性校验
  if (!needCaptcha.value) {
    return null
  }
  // 验证码目标与注册信息一致
  if (form.codeType === 'sms') {
    if (!smsEnabled.value) return '短信验证码已关闭，请切换验证方式'
    if (form.phone === '') return '使用短信验证时需填写手机号'
    if (form.codeTarget !== form.phone) return '接收验证码的手机号与注册手机号不一致'
  } else {
    if (!emailEnabled.value) return '邮箱验证码已关闭，请切换验证方式'
    if (form.email === '') return '使用邮箱验证时需填写邮箱'
    if (form.codeTarget !== form.email) return '接收验证码的邮箱与注册邮箱不一致'
  }
  return null
}

// 发送验证码（验证码关闭时不可用）
async function handleSendCode() {
  if (!needCaptcha.value) {
    ElMessage.info('验证码功能已关闭，无需发送验证码')
    return
  }
  // 检查当前验证方式是否启用
  if (form.codeType === 'sms' && !smsEnabled.value) {
    ElMessage.warning('短信验证码已关闭，请使用邮箱验证')
    return
  }
  if (form.codeType === 'email' && !emailEnabled.value) {
    ElMessage.warning('邮箱验证码已关闭，请使用短信验证')
    return
  }
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
      // 不需要验证码时传空字符串
      code_type: needCaptcha.value ? form.codeType : '',
      code_target: needCaptcha.value ? form.codeTarget : '',
      code_input: needCaptcha.value ? form.codeInput : ''
    }
    const res = await registerApi(params)
    securityCode.value = res.data?.security_code || ''
    // 保存注册返回的 token，用于安全码确认后自动登录
    registeredToken.value = res.data?.token || ''
    registeredUsername.value = form.username
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
// 注册成功后返回的 token 和用户名，用于自动登录
const registeredToken = ref('')
const registeredUsername = ref('')

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
    // 自动登录：使用注册返回的 token 直接登录
    autoLoginAfterRegister()
  })
}

// 注册成功后自动登录
// 注册接口 /auth/register 返回的 token 是用户 token（type=user），
// 管理后台 /admin/info 需要管理员 token（type=admin）。
// 尝试用用户 token 访问后台，若失败则跳转登录页（用户名已预填）。
async function autoLoginAfterRegister() {
  if (registeredToken.value) {
    setToken(registeredToken.value)
    try {
      // 尝试获取用户信息，验证 token 是否可用于后台
      const userStore = useUserStore()
      await userStore.getUserInfo()
      // 成功：跳转到首页
      ElMessage.success('登录成功')
      router.replace('/')
      return
    } catch {
      // token 不兼容后台（普通用户 token），清除并跳转登录页
      removeToken()
    }
  }
  // 跳转到登录页，并传递用户名用于预填
  router.push({ path: '/login', query: { username: registeredUsername.value } })
}

function goLogin() {
  router.push('/login')
}

// 页面加载时获取验证码开关
onMounted(() => {
  fetchCaptchaStatus()
})

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
  /* 使用 auto 而非 center：内容超出视口时顶部不会被裁掉 */
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 40px 20px 80px;
  /* 关键：允许页面纵向滚动，避免表单内容超出视口被裁剪 */
  overflow-y: auto;
  overflow-x: hidden;
  background: linear-gradient(135deg, #f5f6fb 0%, #eceeff 100%);
}

/* 视口高度足够时垂直居中（通过媒体查询在大屏恢复居中效果） */
@media (min-height: 800px) {
  .register-page {
    align-items: center;
    padding: 40px 20px;
  }
}

.bg-layer {
  position: absolute;
  inset: 0;
  z-index: 0;
  overflow: hidden;
  /* 背景层不随页面滚动，避免拉伸 */
  pointer-events: none;
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

/* ---------- 密码规则 & 强度条 ---------- */
.password-rules {
  margin-top: 10px;
  padding: 12px 14px;
  background: rgba(109, 92, 255, 0.06);
  border: 1px solid rgba(109, 92, 255, 0.18);
  border-radius: 12px;

  .password-strength {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
    margin-bottom: 10px;
    .strength-bar {
      height: 5px;
      border-radius: 3px;
      background: rgba(140, 145, 175, 0.25);
      transition: all 0.25s;
      &.active.weak        { background: #f56c6c; }
      &.active.medium      { background: #e6a23c; }
      &.active.strong      { background: #67c23a; }
      &.active.very-strong { background: #409eff; }
    }
  }
  .rule-list {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 14px;
    .rule-item {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 12.5px;
      color: #8a8fb0;
      .el-icon { font-size: 13px; }
      &.ok {
        color: #67c23a;
      }
      &:not(.ok) {
        color: #c0c4dc;
      }
    }
  }
}
:global(html.dark) .password-rules {
  background: rgba(109, 92, 255, 0.1);
  border-color: rgba(109, 92, 255, 0.28);
  .rule-list .rule-item:not(.ok) { color: #9aa0c3; }
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
