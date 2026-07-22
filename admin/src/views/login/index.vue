<template>
  <div class="login-page">
    <!-- 动态背景 -->
    <div class="bg-layer">
      <div class="bg-blob blob-1"></div>
      <div class="bg-blob blob-2"></div>
      <div class="bg-blob blob-3"></div>
      <div class="bg-grid"></div>
    </div>

    <!-- 左侧品牌展示 -->
    <div class="brand-panel">
      <div class="brand-logo">
        <svg viewBox="0 0 48 48" width="56" height="56">
          <defs>
            <linearGradient id="bGrad" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%" stop-color="#9b5cff" />
              <stop offset="100%" stop-color="#5cb8ff" />
            </linearGradient>
          </defs>
          <rect x="6" y="6" width="36" height="36" rx="12" fill="url(#bGrad)" />
          <path
            d="M17 24l5 5 9-11"
            fill="none"
            stroke="#fff"
            stroke-width="3.5"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </div>
      <h1 class="brand-title">Push<span>·</span>即时消息推送</h1>
      <p class="brand-desc">
        千万级设备触达 · 毫秒级送达保障<br />
        全平台覆盖的一站式推送运营管理平台
      </p>

      <div class="feature-list">
        <div class="feature-item">
          <div class="feature-icon"><el-icon><LightningIcon /></el-icon></div>
          <div>
            <div class="feature-name">毫秒级送达</div>
            <div class="feature-sub">长连接保活，平均送达 &lt; 300ms</div>
          </div>
        </div>
        <div class="feature-item">
          <div class="feature-icon"><el-icon><DataLineIcon /></el-icon></div>
          <div>
            <div class="feature-name">高并发支持</div>
            <div class="feature-sub">单集群可承载百万级并发推送</div>
          </div>
        </div>
        <div class="feature-item">
          <div class="feature-icon"><el-icon><MonitorIcon /></el-icon></div>
          <div>
            <div class="feature-name">全平台覆盖</div>
            <div class="feature-sub">Android · iOS · Web · HarmonyOS</div>
          </div>
        </div>
      </div>

      <div class="brand-footer">© 2026 Push Admin · 安全可靠的消息推送服务</div>
    </div>

    <!-- 右侧登录卡片 -->
    <div class="login-panel">
      <div class="login-card">
        <div class="card-header">
          <h2 class="card-title">欢迎回来 👋</h2>
          <p class="card-subtitle">请登录您的管理员账号</p>
        </div>

        <el-form
          ref="formRef"
          :model="form"
          :rules="rules"
          size="large"
          class="login-form"
          @keyup.enter="handleLogin"
        >
          <el-form-item prop="username">
            <el-input
              v-model="form.username"
              placeholder="请输入账号"
              :prefix-icon="UserIcon"
              clearable
            />
          </el-form-item>

          <el-form-item prop="password">
            <el-input
              v-model="form.password"
              type="password"
              placeholder="请输入密码"
              :prefix-icon="LockIcon"
              show-password
              clearable
            />
          </el-form-item>

          <el-form-item v-if="captchaEnabled" prop="captcha_input">
            <div class="captcha-row">
              <el-input
                v-model="form.captcha_input"
                placeholder="请输入验证码"
                :prefix-icon="KeyIcon"
                clearable
              />
              <div class="captcha-img" @click="refreshCaptcha" title="点击刷新">
                <img v-if="captchaImage" :src="captchaImage" alt="验证码" />
                <span v-else class="captcha-placeholder">
                  <el-icon><LoadingIcon /></el-icon>
                </span>
              </div>
            </div>
          </el-form-item>

          <div class="form-options">
          <el-checkbox v-model="form.remember">记住我</el-checkbox>
          <a class="forgot-link" @click="handleForgot">忘记密码？</a>
        </div>

          <el-button
            type="primary"
            class="login-btn"
            :loading="loading"
            @click="handleLogin"
          >
            <span v-if="!loading">登 录</span>
            <span v-else>登录中...</span>
          </el-button>
        </el-form>

        <div class="card-footer">
          <span>还没有账号？</span>
          <a @click="handleRegister">立即注册</a>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import {
  User as UserIcon,
  Lock as LockIcon,
  Key as KeyIcon,
  Loading as LoadingIcon,
  Lightning as LightningIcon,
  DataLine as DataLineIcon,
  Monitor as MonitorIcon
} from '@element-plus/icons-vue'
import { useUserStore } from '@/stores/user'
import { getCaptchaApi } from '@/api/auth'
import type { LoginParams } from '@/api/types'

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()

const formRef = ref<FormInstance>()
const captchaImage = ref('')
const captchaToken = ref('')
const captchaEnabled = ref(true) // 验证码开关（由后端 /captcha/image 返回的 enabled 字段控制）
const loading = ref(false)

const form = reactive<LoginParams & { remember: boolean }>({
  username: import.meta.env.DEV ? 'admin' : '',
  password: import.meta.env.DEV ? 'admin123' : '',
  captcha_token: '',
  captcha_input: '',
  remember: true
})

const rules = computed<FormRules>(() => ({
  username: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
  // 验证码关闭时不强制必填
  captcha_input: captchaEnabled.value
    ? [{ required: true, message: '请输入验证码', trigger: 'blur' }]
    : []
}))

// 从后端获取图形验证码（返回 base64 图片 + token + enabled 开关）
async function fetchCaptcha() {
  try {
    const res = await getCaptchaApi()
    // 验证码开关：后端关闭时不显示验证码输入框
    captchaEnabled.value = res.data?.enabled !== false
    if (!captchaEnabled.value) {
      // 验证码关闭，清空相关字段
      captchaToken.value = ''
      captchaImage.value = ''
      form.captcha_token = ''
      form.captcha_input = ''
      return
    }
    captchaToken.value = res.data?.token || ''
    captchaImage.value = res.data?.image || ''
    form.captcha_token = captchaToken.value
  } catch {
    ElMessage.error('获取验证码失败，请刷新页面重试')
  }
}

function refreshCaptcha() {
  form.captcha_input = ''
  fetchCaptcha()
}

async function handleLogin() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }

  // 验证码启用时确保已加载 token；关闭时跳过
  if (captchaEnabled.value && !form.captcha_token) {
    ElMessage.error('验证码加载中，请稍后重试')
    refreshCaptcha()
    return
  }

  loading.value = true
  try {
    await userStore.login({
      username: form.username,
      password: form.password,
      // 验证码关闭时传空字符串
      captcha_token: captchaEnabled.value ? form.captcha_token : '',
      captcha_input: captchaEnabled.value ? form.captcha_input : ''
    })
    ElMessage.success('登录成功')
    let redirect = (route.query.redirect as string) || '/'
    // 防止开放重定向：只允许站内跳转
    if (!redirect.startsWith('/') || redirect.startsWith('//')) {
      redirect = '/'
    }
    router.replace(redirect)
  } catch (err) {
    // 验证码启用时登录失败刷新验证码
    if (captchaEnabled.value) {
      refreshCaptcha()
    }
    // request.ts 已对登录接口跳过自动弹框，这里统一弹出错误提示
    ElMessage.error(err instanceof Error ? err.message : '登录失败，请重试')
  } finally {
    loading.value = false
  }
}

function handleForgot() {
  router.push('/forgot-password')
}

function handleRegister() {
  router.push('/register')
}

onMounted(() => {
  fetchCaptcha()
})
</script>

<style lang="scss" scoped>
.login-page {
  position: relative;
  width: 100%;
  height: 100vh;
  display: flex;
  overflow: hidden;
  background: linear-gradient(135deg, #f5f6fb 0%, #eceeff 100%);
}

// 动态背景
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
  &.blob-3 {
    width: 360px;
    height: 360px;
    background: radial-gradient(circle, #ff7eb0, transparent 70%);
    top: 40%;
    left: 45%;
    animation-delay: -5s;
    opacity: 0.35;
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

// 左侧品牌
.brand-panel {
  position: relative;
  z-index: 1;
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 80px;
  max-width: 620px;

  .brand-logo {
    margin-bottom: 28px;
    filter: drop-shadow(0 8px 24px rgba(109, 92, 255, 0.4));
    animation: float 6s ease-in-out infinite;
  }

  .brand-title {
    font-size: 42px;
    font-weight: 800;
    color: #1a1d2e;
    letter-spacing: -0.5px;
    span {
      color: #9b5cff;
      margin: 0 4px;
    }
  }

  .brand-desc {
    margin-top: 16px;
    font-size: 16px;
    line-height: 1.7;
    color: #5a5f78;
  }
}

.feature-list {
  margin-top: 48px;
  display: flex;
  flex-direction: column;
  gap: 22px;

  .feature-item {
    display: flex;
    align-items: center;
    gap: 16px;
    animation: fade-up 0.6s ease backwards;

    &:nth-child(1) { animation-delay: 0.1s; }
    &:nth-child(2) { animation-delay: 0.2s; }
    &:nth-child(3) { animation-delay: 0.3s; }
  }

  .feature-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: $gradient-primary;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 22px;
    box-shadow: $shadow-primary;
    flex-shrink: 0;
  }

  .feature-name {
    font-size: 16px;
    font-weight: 700;
    color: #1a1d2e;
  }
  .feature-sub {
    font-size: 13px;
    color: #7e83a3;
    margin-top: 2px;
  }
}

.brand-footer {
  margin-top: 60px;
  font-size: 12px;
  color: #9ba0b8;
}

// 右侧登录卡片
.login-panel {
  position: relative;
  z-index: 1;
  width: 520px;
  max-width: 92vw;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px;
}

.login-card {
  width: 100%;
  background: rgba(255, 255, 255, 0.72);
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
  margin-bottom: 32px;

  .card-title {
    font-size: 28px;
    font-weight: 800;
    color: #1a1d2e;
  }
  .card-subtitle {
    margin-top: 8px;
    font-size: 14px;
    color: #7e83a3;
  }
}

.login-form {
  :deep(.el-input__wrapper) {
    height: 48px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.7);
    box-shadow: 0 0 0 1px rgba(109, 92, 255, 0.12) inset;
    transition: all 0.2s ease;

    &:hover {
      box-shadow: 0 0 0 1px rgba(109, 92, 255, 0.3) inset;
    }
    &.is-focus {
      box-shadow: 0 0 0 2px $color-primary inset;
    }
  }

  :deep(.el-input__inner) {
    height: 48px;
  }

  :deep(.el-input__prefix-inner) {
    color: $color-primary;
    font-size: 18px;
  }
}

.captcha-row {
  display: flex;
  gap: 12px;
  width: 100%;

  .captcha-img {
    width: 120px;
    height: 48px;
    border-radius: 12px;
    overflow: hidden;
    cursor: pointer;
    flex-shrink: 0;
    position: relative;
    border: 1px solid rgba(109, 92, 255, 0.18);
    transition: all 0.2s ease;

    &:hover {
      border-color: $color-primary;
      box-shadow: 0 4px 12px rgba(109, 92, 255, 0.2);
    }

    canvas {
      display: block;
      width: 100%;
      height: 100%;
    }

    img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .captcha-placeholder {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      color: $color-primary;
      animation: rotate-slow 1s linear infinite;
    }
  }
}

.form-options {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 24px;

  :deep(.el-checkbox__label) {
    color: #5a5f78;
  }

  .forgot-link {
    font-size: 13px;
    color: $color-primary;
    cursor: pointer;
    &:hover {
      text-decoration: underline;
    }
  }
}

.login-btn {
  width: 100%;
  height: 50px;
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
  &:active {
    transform: translateY(0);
  }
}

.tip-text {
  margin-top: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 12px;
  color: #9ba0b8;
}

.card-footer {
  margin-top: 28px;
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

// 动画
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

@keyframes fade-up {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

// 响应式
@media (max-width: 1024px) {
  .brand-panel {
    display: none;
  }
  .login-panel {
    width: 100%;
    max-width: 460px;
  }
}

@media (max-width: 480px) {
  .login-panel {
    padding: 20px;
  }
  .login-card {
    padding: 32px 24px 24px;
    border-radius: 20px;
  }
  .card-header .card-title {
    font-size: 22px;
  }
}

// 暗色模式适配
:global(html.dark) {
  .login-page {
    background: linear-gradient(135deg, #0e1020 0%, #14122a 100%);
  }
  .brand-title,
  .feature-name {
    color: #e8eaf6;
  }
  .brand-desc,
  .feature-sub {
    color: #9ba0b8;
  }
  .login-card {
    background: rgba(22, 24, 48, 0.72);
    border-color: rgba(109, 92, 255, 0.2);
  }
  .card-title {
    color: #e8eaf6;
  }
  .card-subtitle,
  .tip-text,
  .card-footer {
    color: #7e83a3;
  }
  .bg-grid {
    background-image: linear-gradient(rgba(138, 124, 255, 0.06) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(138, 124, 255, 0.06) 1px, transparent 1px);
  }
}
</style>
