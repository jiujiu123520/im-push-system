<template>
  <div class="profile-page">
    <el-row :gutter="16">
      <el-col :xs="24" :sm="24" :md="8">
        <el-card shadow="never" class="info-card">
          <div class="header-bg"></div>
          <div class="avatar-row">
            <el-avatar :size="72" :src="userStore.avatar" />
            <div class="nm">
              <div class="name">{{ userStore.nickname || userStore.username }}</div>
              <div class="id">ID: {{ userStore.userId }} · 普通用户</div>
            </div>
          </div>
          <div class="meta-list">
            <div class="meta"><el-icon><User /></el-icon><span>用户名：</span><b>{{ userStore.username }}</b></div>
            <div class="meta"><el-icon><Message /></el-icon><span>邮箱：</span><b>{{ userStore.email || '未绑定' }}</b></div>
            <div class="meta"><el-icon><Iphone /></el-icon><span>手机号：</span><b>{{ userStore.phone || '未绑定' }}</b></div>
            <div class="meta"><el-icon><ChatDotRound /></el-icon><span>QQ：</span>
              <b>{{ maskedQq || '未绑定' }}</b>
              <el-button v-if="!userStore.qq" link type="primary" size="small" @click="bindQqDlg = true">绑定</el-button>
              <el-tooltip v-if="userStore.qq" content="如需解绑请联系管理员" placement="right">
                <el-tag size="small" type="warning" effect="light" style="margin-left:6px">不可自行解绑</el-tag>
              </el-tooltip>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="24" :md="16">
        <el-tabs v-model="tab" class="tabs">
          <el-tab-pane label="基本资料" name="basic">
            <el-form :model="form" label-width="100px" label-position="right">
              <el-form-item label="昵称">
                <el-input v-model="form.nickname" placeholder="给自己起个昵称" maxlength="30" />
              </el-form-item>
              <el-form-item label="邮箱">
                <el-row :gutter="12" style="width:100%">
                  <el-col :span="16"><el-input v-model="form.email" placeholder="新邮箱地址" maxlength="64" /></el-col>
                  <el-col :span="8">
                    <el-button :disabled="!canSendMail" @click="sendMailCode">
                      {{ mailCountdown > 0 ? mailCountdown + 's' : '获取验证码' }}
                    </el-button>
                  </el-col>
                </el-row>
              </el-form-item>
              <el-form-item label="邮箱验证码">
                <el-input v-model="form.email_code" placeholder="邮箱验证码" maxlength="6" />
              </el-form-item>
              <el-form-item>
                <el-button type="primary" :loading="savingBasic" @click="saveBasic">保存修改</el-button>
              </el-form-item>
            </el-form>
          </el-tab-pane>
          <el-tab-pane label="修改密码" name="pwd">
            <el-form :model="pwd" ref="pwdFormRef" :rules="pwdRules" label-width="120px" label-position="right">
              <el-form-item label="当前密码" prop="old_password">
                <el-input v-model="pwd.old_password" type="password" show-password maxlength="64" />
              </el-form-item>
              <el-form-item label="新密码" prop="new_password">
                <el-input v-model="pwd.new_password" type="password" show-password maxlength="64" />
              </el-form-item>
              <el-form-item label="确认新密码" prop="confirm_password">
                <el-input v-model="pwd.confirm_password" type="password" show-password maxlength="64" />
              </el-form-item>
              <el-form-item>
                <el-button type="primary" :loading="savingPwd" @click="savePwd">修改密码</el-button>
              </el-form-item>
            </el-form>
          </el-tab-pane>
          <el-tab-pane label="安全设置" name="sec">
            <el-descriptions :column="1" border>
              <el-descriptions-item label="登录令牌">
                <el-button type="danger" plain @click="logoutAll">
                  <el-icon><SwitchButton /></el-icon> 退出所有登录
                </el-button>
                <div class="hint">强制所有设备上的该账号立即重新登录。</div>
              </el-descriptions-item>
            </el-descriptions>
          </el-tab-pane>
        </el-tabs>
      </el-col>
    </el-row>

    <!-- 绑定 QQ Dialog -->
    <el-dialog v-model="bindQqDlg" title="绑定 QQ" width="min(420px,92vw)">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom:12px"
                title="绑定后不可自行解绑，如需解绑请联系管理员。" />
      <el-form :model="qqForm" label-width="80px">
        <el-form-item label="QQ 号" required>
          <el-input v-model="qqForm.qq" placeholder="请输入 QQ 号" maxlength="12" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="bindQqDlg = false">取消</el-button>
        <el-button type="primary" :loading="bindingQq" @click="submitBindQq">确认绑定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { User, Message, Iphone, ChatDotRound, SwitchButton } from '@element-plus/icons-vue'
import { useUserStore } from '@/stores/user'
import { getProfileApi, updateProfileApi, changePasswordApi, bindQqApi, logoutAllApi } from '@/api/profile'
import { sendCodeApi } from '@/api/auth'
import { validEmail, validPassword, validQq } from '@/utils/validate'

const userStore = useUserStore()
const tab = ref('basic')

const form = reactive({ nickname: '', email: '', email_code: '' })
const savingBasic = ref(false)
const savingPwd = ref(false)
const mailCountdown = ref(0)
let mailTimer: any = null
const canSendMail = computed(() => validEmail(form.email) && mailCountdown.value === 0)

const maskedQq = computed(() => {
  const q = userStore.qq
  if (!q) return ''
  return q.length <= 3 ? q : q.slice(0, 3) + '****' + q.slice(-1)
})

// 改密码
const pwdFormRef = ref<FormInstance>()
const pwd = reactive({ old_password: '', new_password: '', confirm_password: '' })
const pwdRules: FormRules = {
  old_password: [{ required: true, message: '请输入当前密码', trigger: 'blur' }],
  new_password: [{ required: true, message: '请输入新密码', trigger: 'blur' },
    { validator: (_r, v, cb) => validPassword(v) ? cb() : cb(new Error('6-64 位')), trigger: 'blur' }],
  confirm_password: [{ required: true, message: '请再次输入新密码', trigger: 'blur' },
    { validator: (_r, v, cb) => v === pwd.new_password ? cb() : cb(new Error('两次密码不一致')), trigger: 'blur' }]
}

// 绑定 QQ
const bindQqDlg = ref(false)
const bindingQq = ref(false)
const qqForm = reactive({ qq: '' })

async function load() {
  try {
    const r = await getProfileApi()
    const u = r.data
    if (u) {
      form.nickname = (u as any).nickname || ''
      form.email = u.email || ''
      userStore.userInfo = { ...(userStore.userInfo || {}), ...u } as any
    }
  } catch {}
}

async function sendMailCode() {
  if (!canSendMail.value) return
  try {
    await sendCodeApi({ type: 'email', target: form.email, usage: 'reset' })
    ElMessage.success('验证码已发送')
    mailCountdown.value = 60
    mailTimer = setInterval(() => {
      mailCountdown.value -= 1
      if (mailCountdown.value <= 0 && mailTimer) { clearInterval(mailTimer); mailTimer = null }
    }, 1000)
  } catch {}
}

async function saveBasic() {
  savingBasic.value = true
  try {
    const payload: any = {}
    if (form.nickname) payload.nickname = form.nickname
    if (form.email) { payload.email = form.email; payload.email_code = form.email_code }
    await updateProfileApi(payload)
    ElMessage.success('保存成功')
    await userStore.refreshUserInfo()
  } catch (e: any) { ElMessage.error(e?.message || '保存失败')
  } finally { savingBasic.value = false }
}

async function savePwd() {
  await pwdFormRef.value?.validate(async (ok) => {
    if (!ok) return
    savingPwd.value = true
    try {
      await changePasswordApi({ old_password: pwd.old_password, new_password: pwd.new_password, confirm_password: pwd.confirm_password })
      ElMessage.success('密码修改成功，请重新登录')
      pwd.old_password = pwd.new_password = pwd.confirm_password = ''
      setTimeout(() => userStore.logout(), 600)
    } catch (e: any) { ElMessage.error(e?.message || '修改失败')
    } finally { savingPwd.value = false }
  })
}

async function logoutAll() {
  try {
    await logoutAllApi()
    ElMessage.success('已退出所有登录')
    await userStore.logout()
  } catch (e: any) {
    ElMessage.error(e?.message || '操作失败')
  }
}

async function submitBindQq() {
  if (!validQq(qqForm.qq)) return ElMessage.warning('QQ 号格式错误')
  bindingQq.value = true
  try {
    await bindQqApi({ qq: qqForm.qq })
    ElMessage.success('QQ 绑定成功')
    bindQqDlg.value = false
    await userStore.refreshUserInfo()
  } catch (e: any) { ElMessage.error(e?.message || '绑定失败')
  } finally { bindingQq.value = false }
}

onMounted(() => load())
onBeforeUnmount(() => { if (mailTimer) clearInterval(mailTimer) })
</script>

<style lang="scss" scoped>
.info-card { position: relative; padding: 0; overflow: visible; }
.header-bg {
  height: 90px; border-radius: $radius-lg $radius-lg 0 0;
  background: $gradient-primary; opacity: 0.9;
}
.avatar-row {
  position: relative; padding: 0 $space-5 $space-4;
  margin-top: -36px;
  display: flex; align-items: flex-end; gap: $space-3;
  :deep(.el-avatar) { border: 4px solid #fff; box-shadow: $shadow-md; }
  .nm { padding-bottom: 6px;
    .name { font-weight: 700; font-size: $font-size-xl; color: var(--text-primary); }
    .id   { font-size: $font-size-xs; color: var(--text-secondary); margin-top: 2px; } }
}
.meta-list { padding: 0 $space-5 $space-5; }
.meta { display: flex; align-items: center; gap: $space-2; padding: $space-2 0; color: var(--text-regular);
  font-size: $font-size-sm;
  span { color: var(--text-secondary); }
  b { color: var(--text-primary); font-weight: 500; }
  .el-icon { color: var(--text-secondary); } }
.tabs { :deep(.el-tabs__nav-wrap::after) { background: var(--border-light); } }
.hint { margin-top: 6px; font-size: $font-size-xs; color: var(--text-secondary); }
</style>
