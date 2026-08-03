<template>
  <div class="login-page">
    <div class="bg-decoration"></div>
    <div class="login-card">
      <div class="brand">
        <div class="logo">IM</div>
        <div class="titles">
          <h1>Push 用户中心</h1>
          <p>登录账号管理你的推送服务</p>
        </div>
      </div>
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" size="large" @keyup.enter="submit">
        <el-form-item label="用户名" prop="username">
          <el-input v-model="form.username" placeholder="请输入用户名 / 邮箱 / 手机号"
            :prefix-icon="User" clearable />
        </el-form-item>
        <el-form-item label="密码" prop="password">
          <el-input v-model="form.password" type="password" show-password
            placeholder="请输入密码" :prefix-icon="Lock" @keyup.enter="submit" />
        </el-form-item>
        <el-form-item v-if="captchaEnabled" label="图形验证码" prop="captcha_input">
          <div class="captcha-row">
            <el-input v-model="form.captcha_input" placeholder="请输入验证码"
              :prefix-icon="Picture" clearable maxlength="4" />
            <div class="captcha-img-wrap" @click="refreshCaptcha" title="点击刷新">
              <img v-if="captchaImage" :src="captchaImage" class="captcha-img" alt="验证码"
                   @error="handleCaptchaImgError" />
              <span v-else class="captcha-placeholder">
                <el-icon v-if="captchaLoading"><Loading /></el-icon>
                <span v-else>加载失败，点此重试</span>
              </span>
            </div>
          </div>
        </el-form-item>
        <div class="row-between">
          <div class="links">
            <router-link to="/register">注册账号</router-link>
            <span class="sep">·</span>
            <router-link to="/forgot-password">忘记密码</router-link>
          </div>
        </div>
        <el-button type="primary" class="btn-login" :loading="loading" @click="submit">登 录</el-button>
      </el-form>
      <div class="footer-hint">
        还没有账号？<router-link to="/register">立即注册 →</router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { User, Lock, Picture, Loading } from '@element-plus/icons-vue'
import { getCaptchaApi } from '@/api/auth'
import { useUserStore } from '@/stores/user'
import { validUsername, validPassword } from '@/utils/validate'
import type { LoginParams } from '@/api/types'

const router = useRouter()
const route = useRoute()
const userStore = useUserStore()
const formRef = ref<FormInstance>()
const loading = ref(false)

// —— 图形验证码：参照 admin 登录页实现，通过 getCaptchaApi() 获取 JSON(token+image+开关) ——
const captchaImage = ref('')
const captchaToken = ref('')
const captchaEnabled = ref(true)      // 登录验证码开关（来自后端 loginEnabled）
const captchaLoading = ref(false)
const CAPTCHA_MAX_RETRY = 3
const CAPTCHA_TIMEOUT = 5000
const CAPTCHA_MIN_LEN = 200

async function fetchCaptchaWithRetry(): Promise<void> {
  let lastErr: unknown = null
  for (let attempt = 1; attempt <= CAPTCHA_MAX_RETRY; attempt++) {
    try {
      const res = await getCaptchaApi({ timeout: CAPTCHA_TIMEOUT })
      // 登录验证码关闭时：清空 token+image，隐藏验证码输入框
      captchaEnabled.value = res.data?.loginEnabled !== false && res.data?.enabled !== false
      if (!captchaEnabled.value) {
        captchaToken.value = ''
        captchaImage.value = ''
        form.captcha_token = ''
        form.captcha_input = ''
        return
      }
      const image = (res.data?.image as string) || ''
      const token = (res.data?.token as string) || ''
      if (image === '' || image.length < CAPTCHA_MIN_LEN) {
        throw new Error(`验证码图片数据异常，长度=${image.length}`)
      }
      if (token === '') throw new Error('验证码 token 为空')
      captchaToken.value = token
      captchaImage.value = image
      form.captcha_token = token
      return
    } catch (e) {
      lastErr = e
      if (attempt < CAPTCHA_MAX_RETRY) {
        await new Promise((r) => setTimeout(r, 300 * attempt))
      }
    }
  }
  // 全部重试失败：不阻塞账号密码输入，仅提示；用户可点图片区域手动刷新
  captchaImage.value = ''
  captchaToken.value = ''
  form.captcha_token = ''
  console.warn('[captcha] 验证码加载失败，点击图片区域可手动刷新：', lastErr)
}
async function fetchCaptcha() {
  captchaLoading.value = true
  try { await fetchCaptchaWithRetry() } finally { captchaLoading.value = false }
}
function handleCaptchaImgError() {
  console.warn('[captcha] 图片渲染失败，自动刷新')
  refreshCaptcha()
}
function refreshCaptcha() {
  form.captcha_input = ''
  fetchCaptcha()
}

// —— 表单 & 校验 ——
const form = reactive<LoginParams & { captcha_input: string }>({
  username: (route.query.username as string) || '',
  password: '',
  captcha_token: '',
  captcha_input: '',
  captcha: ''
})
const rules = computed<FormRules>(() => ({
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { validator: (_r, v, cb) => validPassword(v) ? cb() : cb(new Error('密码长度 6-64 位')), trigger: 'blur' }
  ],
  captcha_input: captchaEnabled.value
    ? [{ required: true, message: '请输入验证码', trigger: 'blur' }]
    : []
}))

async function submit() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch { return }

  // 验证码启用时：必须已成功加载 token（captchaEnabled=true 时才校验）
  if (captchaEnabled.value && !form.captcha_token) {
    if (captchaLoading.value) {
      ElMessage.error('验证码加载中，请稍后再试')
    } else {
      ElMessage.error('验证码加载失败，请点击验证码图片刷新后重试')
      refreshCaptcha()
    }
    return
  }

  loading.value = true
  try {
    await userStore.login({
      username: form.username,
      password: form.password,
      captcha_token: captchaEnabled.value ? form.captcha_token : '',
      captcha_input: captchaEnabled.value ? form.captcha_input : ''
    })
    ElMessage.success('登录成功')
    const redirect = (route.query.redirect as string) || '/dashboard'
    // 防止开放重定向：只允许站内路径
    const safeRedirect = redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/dashboard'
    router.replace(safeRedirect)
  } catch (e: any) {
    ElMessage.error(e?.message || '登录失败，请重试')
    // 登录失败：刷新验证码（如果开启），避免爆破尝试同一个验证码
    if (captchaEnabled.value) refreshCaptcha()
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchCaptcha()
})
</script>

<style lang="scss" scoped>
.login-page {
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: $space-5; position: relative; overflow: hidden;
  background:
    radial-gradient(circle at 15% 20%, rgba(34,197,94,0.18), transparent 45%),
    radial-gradient(circle at 85% 75%, rgba(14,165,233,0.18), transparent 50%),
    #f6f8fc;
}
.bg-decoration {
  position: absolute; inset: 0; pointer-events: none;
  background-image:
    radial-gradient(circle at 10% 10%, rgba(99,102,241,0.08), transparent 35%),
    radial-gradient(circle at 90% 30%, rgba(236,72,153,0.06), transparent 40%);
}
.login-card {
  position: relative; z-index: 1;
  width: 100%; max-width: 440px;
  background: #fff; border-radius: $radius-xl;
  box-shadow: $shadow-xl;
  padding: $space-8 $space-8 $space-6;
}
.brand { display: flex; align-items: center; gap: $space-4; margin-bottom: $space-6; }
.logo {
  width: 48px; height: 48px; border-radius: 12px;
  background: $gradient-primary; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-weight: 800; letter-spacing: 0.5px; box-shadow: $shadow-primary;
}
.titles { h1 { margin: 0; font-size: $font-size-2xl; color: var(--text-primary); }
           p  { margin: 4px 0 0; color: var(--text-secondary); font-size: $font-size-sm; } }
.captcha-row { display: flex; gap: $space-3; align-items: center; }
.captcha-img-wrap {
  height: 40px; width: 120px; flex-shrink: 0; border-radius: $radius-sm; cursor: pointer;
  border: 1px solid var(--border-light); background: #f8fafc;
  display: flex; align-items: center; justify-content: center; overflow: hidden;
  .captcha-img { display: block; width: 100%; height: 100%; object-fit: cover; }
  .captcha-placeholder { font-size: 11px; color: var(--color-primary); text-align: center; padding: 0 6px; line-height: 1.4; }
}
.row-between { display: flex; justify-content: space-between; align-items: center; margin-bottom: $space-4; }
.links { font-size: $font-size-sm;
  a { color: var(--color-primary); }
  .sep { margin: 0 $space-2; color: var(--border-dark); } }
.btn-login { width: 100%; height: 44px; font-size: $font-size-md; border-radius: $radius-md; }
.footer-hint {
  text-align: center; margin-top: $space-6; padding-top: $space-4;
  border-top: 1px dashed var(--border-light);
  font-size: $font-size-sm; color: var(--text-secondary);
  a { color: var(--color-primary); font-weight: 500; }
}
</style>
