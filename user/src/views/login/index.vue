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
        <el-form-item v-if="showCaptcha" label="图形验证码" prop="captcha">
          <div class="captcha-row">
            <el-input v-model="form.captcha" placeholder="请输入验证码"
              :prefix-icon="Picture" clearable maxlength="4" />
            <img :src="captchaSrc" @click="refreshCaptcha" class="captcha-img"
                 title="点击刷新" alt="验证码" />
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
import { onMounted, reactive, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { User, Lock, Picture } from '@element-plus/icons-vue'
import { captchaImageUrl } from '@/api/auth'
import { useUserStore } from '@/stores/user'
import { validUsername, validPassword } from '@/utils/validate'

const router = useRouter()
const route = useRoute()
const userStore = useUserStore()
const formRef = ref<FormInstance>()
const loading = ref(false)
const showCaptcha = ref(false)
const captchaT = ref(Date.now())
const captchaSrc = computed(() => captchaImageUrl() + '&r=' + captchaT.value)

const form = reactive({ username: '', password: '', captcha: '' })
const rules: FormRules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' },
             { validator: (_r, v, cb) => validPassword(v) ? cb() : cb(new Error('密码长度 6-64 位')), trigger: 'blur' }]
}
function refreshCaptcha() { captchaT.value = Date.now() }

async function submit() {
  if (!formRef.value) return
  await formRef.value.validate(async (ok) => {
    if (!ok) return
    loading.value = true
    try {
      await userStore.login({ ...form })
      ElMessage.success('登录成功')
      const redirect = (route.query.redirect as string) || '/dashboard'
      router.replace(redirect)
    } catch (e: any) {
      ElMessage.error(e?.message || '登录失败')
      showCaptcha.value = true
      refreshCaptcha()
      form.captcha = ''
    } finally { loading.value = false }
  })
}
onMounted(() => {})
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
.captcha-row { display: flex; gap: $space-3; align-items: center;
  .captcha-img { height: 40px; border-radius: $radius-sm; cursor: pointer;
                 border: 1px solid var(--border-light); background: #f8fafc; } }
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
