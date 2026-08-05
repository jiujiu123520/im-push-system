<template>
  <div class="register-page">
    <div class="register-card">
      <div class="brand">
        <div class="logo">IM</div>
        <div class="titles">
          <h1>用户注册</h1>
          <p>创建账号，立即使用消息推送服务</p>
        </div>
      </div>
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" size="default">
        <el-form-item label="用户名" prop="username">
          <el-input v-model="form.username" placeholder="4-20 位字母/数字/下划线/中文"
            :prefix-icon="User" maxlength="20" clearable />
        </el-form-item>
        <el-form-item label="密码" prop="password">
          <el-input v-model="form.password" type="password" show-password
            placeholder="6-64 位" :prefix-icon="Lock" maxlength="64" />
        </el-form-item>
        <el-form-item label="确认密码" prop="confirmPassword">
          <el-input v-model="form.confirmPassword" type="password" show-password
            placeholder="再次输入密码" :prefix-icon="Lock" maxlength="64" />
        </el-form-item>
        <el-row :gutter="12">
          <el-col :span="12">
            <el-form-item label="手机号（选填）" prop="phone">
              <el-input v-model="form.phone" placeholder="选填"
                :prefix-icon="Iphone" maxlength="11" clearable />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="邮箱（选填）" prop="email">
              <el-input v-model="form.email" placeholder="选填"
                :prefix-icon="Message" maxlength="64" clearable />
            </el-form-item>
          </el-col>
        </el-row>
        <div v-if="showCodeField" class="code-field">
          <el-row :gutter="12">
            <el-col :span="16">
              <el-form-item :label="codeTypeLabel + '验证码'" prop="code">
                <el-input v-model="form.code" placeholder="请输入验证码"
                  :prefix-icon="Key" maxlength="6" clearable />
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="&nbsp;">
                <el-button :disabled="!canSendCode || sendingCode" @click="sendCode" style="width:100%">
                  {{ sendingCode ? '发送中' : (countdown > 0 ? countdown + 's' : '获取验证码') }}
                </el-button>
              </el-form-item>
            </el-col>
          </el-row>
        </div>
        <el-form-item label="图形验证码" prop="captcha">
          <div class="captcha-row">
            <el-input v-model="form.captcha" placeholder="请输入图形验证码"
              :prefix-icon="Picture" maxlength="4" clearable />
            <img :src="captchaSrc" @click="refreshCaptcha" class="captcha-img"
                 title="点击刷新" alt="图形验证码" />
          </div>
        </el-form-item>
        <el-button type="primary" class="btn-submit" :loading="loading" @click="submit">注 册 账 号</el-button>
      </el-form>
      <div class="footer-hint">
        已有账号？<router-link to="/login">立即登录 →</router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { User, Lock, Iphone, Message, Key, Picture } from '@element-plus/icons-vue'
import { captchaImageUrl, getSecurityConfigApi, registerApi, sendCodeApi } from '@/api/auth'
import { validEmail, validPassword, validPhone, validUsername } from '@/utils/validate'
import { useUserStore } from '@/stores/user'
import { resetReloginFlag } from '@/utils/request'

onMounted(() => { resetReloginFlag() })

const router = useRouter()
const userStore = useUserStore()
const formRef = ref<FormInstance>()
const loading = ref(false)
const sendingCode = ref(false)
const countdown = ref(0)
let timer: any = null
const captchaT = ref(Date.now())
const captchaSrc = computed(() => captchaImageUrl() + '&r=' + captchaT.value)
function refreshCaptcha() { captchaT.value = Date.now() }
const secCfg = ref<any>({})
getSecurityConfigApi().then((r) => { secCfg.value = r.data || {} }).catch(() => {})

const form = reactive({
  username: '', password: '', confirmPassword: '',
  phone: '', email: '', code: '', captcha: '',
  code_type: '' as '' | 'sms' | 'email'
})
const showCodeField = computed(() => form.code_type !== '' && (form.phone || form.email))
const canSendCode = computed(() => {
  if (!showCodeField.value) return false
  if (form.code_type === 'sms') return validPhone(form.phone)
  if (form.code_type === 'email') return validEmail(form.email)
  return false
})
const codeTypeLabel = computed(() => form.code_type === 'sms' ? '短信' : form.code_type === 'email' ? '邮箱' : '')

function detectCodeType() {
  if (validEmail(form.email)) form.code_type = 'email'
  else if (validPhone(form.phone)) form.code_type = 'sms'
  else form.code_type = ''
}
const rules: FormRules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' },
             { validator: (_r, v, cb) => validUsername(v) ? cb() : cb(new Error('4-20 位字母/数字/下划线/中文')), trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' },
             { validator: (_r, v, cb) => validPassword(v) ? cb() : cb(new Error('密码长度 6-64 位')), trigger: 'blur' }],
  confirmPassword: [{ required: true, message: '请再次输入密码', trigger: 'blur' },
    { validator: (_r, v, cb) => v === form.password ? cb() : cb(new Error('两次密码不一致')), trigger: 'blur' }],
  phone: [{ validator: (_r, v, cb) => { if (!v) return cb(); validPhone(v) ? cb() : cb(new Error('手机号格式不正确')) }, trigger: 'blur' }],
  email: [{ validator: (_r, v, cb) => { if (!v) return cb(); validEmail(v) ? cb() : cb(new Error('邮箱格式不正确')) }, trigger: 'blur' }],
  captcha: [{ required: true, message: '请输入图形验证码', trigger: 'blur' }]
}

async function sendCode() {
  if (!canSendCode.value) return
  sendingCode.value = true
  try {
    const target = form.code_type === 'sms' ? form.phone : form.email
    await sendCodeApi({ type: form.code_type as 'sms' | 'email', target, usage: 'register', captcha: form.captcha })
    ElMessage.success('验证码已发送，请注意查收')
    countdown.value = 60
    timer = setInterval(() => {
      countdown.value -= 1
      if (countdown.value <= 0 && timer) { clearInterval(timer); timer = null }
    }, 1000)
  } catch (e: any) {
    ElMessage.error(e?.message || '验证码发送失败')
    refreshCaptcha()
  } finally { sendingCode.value = false }
}

async function submit() {
  detectCodeType()
  await formRef.value?.validate(async (ok) => {
    if (!ok) return
    loading.value = true
    try {
      const payload: any = {
        username: form.username, password: form.password,
        code_type: form.code_type, code: form.code || undefined,
        captcha: form.captcha
      }
      if (form.phone) payload.phone = form.phone
      if (form.email) payload.email = form.email
      await userStore.register(payload)
      ElMessage.success('注册成功')
      router.replace('/dashboard')
    } catch (e: any) {
      ElMessage.error(e?.message || '注册失败')
      refreshCaptcha()
      form.captcha = ''
    } finally { loading.value = false }
  })
}
onBeforeUnmount(() => { if (timer) clearInterval(timer) })
</script>

<style lang="scss" scoped>
.register-page {
  min-height: 100vh;
  padding: $space-6; overflow-y: auto;
  display: flex; align-items: flex-start; justify-content: center;
  background:
    radial-gradient(circle at 20% 20%, rgba(34,197,94,0.16), transparent 45%),
    radial-gradient(circle at 80% 70%, rgba(14,165,233,0.16), transparent 50%),
    #f6f8fc;
}
.register-card {
  width: 100%; max-width: 520px;
  background: #fff; border-radius: $radius-xl;
  box-shadow: $shadow-xl;
  padding: $space-7 $space-7 $space-5;
  margin: $space-4 0;
}
.brand { display: flex; align-items: center; gap: $space-4; margin-bottom: $space-6; }
.logo {
  width: 46px; height: 46px; border-radius: 12px;
  background: $gradient-primary; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-weight: 800; box-shadow: $shadow-primary;
}
.titles { h1 { margin: 0; font-size: $font-size-xl; color: var(--text-primary); }
           p  { margin: 4px 0 0; color: var(--text-secondary); font-size: $font-size-sm; } }
.captcha-row { display: flex; gap: $space-3; align-items: center;
  .captcha-img { height: 40px; border-radius: $radius-sm; cursor: pointer;
                 border: 1px solid var(--border-light); background: #f8fafc; } }
.btn-submit { width: 100%; height: 44px; font-size: $font-size-md; border-radius: $radius-md; }
.footer-hint {
  text-align: center; margin-top: $space-5; padding-top: $space-4;
  border-top: 1px dashed var(--border-light);
  font-size: $font-size-sm; color: var(--text-secondary);
  a { color: var(--color-primary); font-weight: 500; }
}
</style>
