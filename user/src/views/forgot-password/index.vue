<template>
  <div class="forgot-page">
    <div class="forgot-card">
      <h1>找回密码</h1>
      <p class="sub">选择找回方式，按提示操作即可重置密码</p>
      <el-tabs v-model="tab" class="mode-tabs">
        <el-tab-pane label="通过 QQ 号 + 邮箱找回" name="qq">
          <el-form ref="qqFormRef" :model="qqForm" :rules="qqRules" label-position="top" size="default">
            <el-form-item label="绑定的 QQ 号" prop="qq">
              <el-input v-model="qqForm.qq" placeholder="请输入绑定的 QQ 号"
                :prefix-icon="ChatDotRound" maxlength="12" clearable />
            </el-form-item>
            <el-form-item label="绑定的账号（用户名/邮箱/手机号，选填）" prop="account">
              <el-input v-model="qqForm.account" placeholder="用于匹配该 QQ 绑定的账号" clearable />
            </el-form-item>
            <el-form-item v-if="needEmail" label="绑定的邮箱" prop="email">
              <el-input v-model="qqForm.email" placeholder="账号绑定的邮箱" :prefix-icon="Message" maxlength="64" clearable />
            </el-form-item>
            <el-row v-if="needEmail" :gutter="12">
              <el-col :span="16">
                <el-form-item label="邮箱验证码" prop="email_code">
                  <el-input v-model="qqForm.email_code" :prefix-icon="Key" maxlength="6" clearable />
                </el-form-item>
              </el-col>
              <el-col :span="8">
                <el-form-item label="&nbsp;">
                  <el-button :disabled="!canSendMail || sendingMail" @click="sendMail">
                    {{ sendingMail ? '发送中' : (mailCountdown > 0 ? mailCountdown + 's' : '获取验证码') }}
                  </el-button>
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item label="新密码" prop="new_password">
              <el-input v-model="qqForm.new_password" type="password" show-password
                :prefix-icon="Lock" maxlength="64" />
            </el-form-item>
            <el-button type="primary" class="btn" :loading="loading" @click="submitQq">确认重置</el-button>
          </el-form>
        </el-tab-pane>
      </el-tabs>
      <div class="footer-hint">
        想起密码了？<router-link to="/login">返回登录 →</router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { ChatDotRound, Message, Key, Lock } from '@element-plus/icons-vue'
import { getSecurityConfigApi, resetPasswordByQqApi, sendCodeApi } from '@/api/auth'
import { validEmail, validPassword, validQq } from '@/utils/validate'
import { resetReloginFlag } from '@/utils/request'

onMounted(() => { resetReloginFlag() })

const router = useRouter()
const tab = ref('qq')
const loading = ref(false)
const sendingMail = ref(false)
const mailCountdown = ref(0)
let mailTimer: any = null
const secCfg = ref<any>({ qq_bind_enabled: true, password_reset_mode: 'both', require_email_for_reset: true })
getSecurityConfigApi().then((r) => { secCfg.value = r.data || secCfg.value }).catch(() => {})
const needEmail = computed(() => !!secCfg.value.require_email_for_reset)

const qqForm = reactive({ qq: '', account: '', email: '', email_code: '', new_password: '' })
const canSendMail = computed(() => needEmail.value && validEmail(qqForm.email))
const qqRules: FormRules = {
  qq: [{ required: true, message: '请输入 QQ 号', trigger: 'blur' },
       { validator: (_r, v, cb) => validQq(v) ? cb() : cb(new Error('QQ 号格式不正确')), trigger: 'blur' }],
  email: [{ validator: (_r, v, cb) => {
    if (!needEmail.value) return cb()
    if (!validEmail(v)) return cb(new Error('请输入绑定的邮箱'))
    cb()
  }, trigger: 'blur' }],
  email_code: [{ validator: (_r, v, cb) => {
    if (!needEmail.value) return cb()
    if (!v || v.length < 4) return cb(new Error('请输入邮箱验证码'))
    cb()
  }, trigger: 'blur' }],
  new_password: [{ required: true, message: '请输入新密码', trigger: 'blur' },
                 { validator: (_r, v, cb) => validPassword(v) ? cb() : cb(new Error('密码长度 6-64 位')), trigger: 'blur' }]
}
async function sendMail() {
  if (!canSendMail.value) return
  sendingMail.value = true
  try {
    await sendCodeApi({ type: 'email', target: qqForm.email!, usage: 'reset' })
    ElMessage.success('验证码已发送')
    mailCountdown.value = 60
    mailTimer = setInterval(() => {
      mailCountdown.value -= 1
      if (mailCountdown.value <= 0 && mailTimer) { clearInterval(mailTimer); mailTimer = null }
    }, 1000)
  } catch (e: any) { ElMessage.error(e?.message || '发送失败')
  } finally { sendingMail.value = false }
}
async function submitQq() {
  const ok = await (qqFormRef.value as FormInstance)?.validate().catch(() => false)
  if (ok === false) return
  loading.value = true
  try {
    await resetPasswordByQqApi({
      qq: qqForm.qq, account: qqForm.account,
      email: needEmail.value ? qqForm.email : '',
      email_code: needEmail.value ? qqForm.email_code : '',
      new_password: qqForm.new_password
    })
    ElMessage.success('密码重置成功')
    setTimeout(() => router.replace('/login'), 500)
  } catch (e: any) { ElMessage.error(e?.message || '重置失败')
  } finally { loading.value = false }
}
const qqFormRef = ref<FormInstance>()
onBeforeUnmount(() => { if (mailTimer) clearInterval(mailTimer) })
</script>

<style lang="scss" scoped>
.forgot-page {
  min-height: 100vh; padding: $space-6; overflow-y: auto;
  display: flex; align-items: flex-start; justify-content: center;
  background:
    radial-gradient(circle at 20% 20%, rgba(34,197,94,0.16), transparent 45%),
    radial-gradient(circle at 80% 70%, rgba(14,165,233,0.16), transparent 50%),
    #f6f8fc;
}
.forgot-card {
  width: 100%; max-width: 500px;
  background: #fff; border-radius: $radius-xl; box-shadow: $shadow-xl;
  padding: $space-7 $space-7 $space-5; margin: $space-4 0;
  h1  { margin: 0 0 4px; font-size: $font-size-xl; color: var(--text-primary); }
  .sub { margin: 0 0 $space-5; color: var(--text-secondary); font-size: $font-size-sm; }
}
.mode-tabs { :deep(.el-tabs__nav-wrap::after) { background: var(--border-light); } }
.btn { width: 100%; height: 42px; border-radius: $radius-md; }
.footer-hint {
  text-align: center; margin-top: $space-5; padding-top: $space-4;
  border-top: 1px dashed var(--border-light);
  font-size: $font-size-sm; color: var(--text-secondary);
  a { color: var(--color-primary); font-weight: 500; }
}
</style>
