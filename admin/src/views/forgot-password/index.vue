<template>
  <div class="forgot-page">
    <!-- 动态背景 -->
    <div class="bg-layer">
      <div class="bg-blob blob-1"></div>
      <div class="bg-blob blob-2"></div>
      <div class="bg-grid"></div>
    </div>

    <!-- 重置密码卡片 -->
    <div class="forgot-card">
      <div class="card-header">
        <h2 class="card-title">忘记密码</h2>
        <p class="card-subtitle">通过安全码重置密码</p>
      </div>

      <el-alert
        type="info"
        :closable="false"
        title="安全码是注册时系统生成的 8 位数字"
        description="如忘记安全码，请联系管理员。"
        show-icon
        class="info-alert"
      />

      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        size="large"
        label-position="top"
        class="forgot-form"
      >
        <el-form-item prop="account" label="账号">
          <el-input
            v-model="form.account"
            placeholder="用户名 / 手机号 / 邮箱"
            :prefix-icon="UserIcon"
            clearable
          />
        </el-form-item>

        <el-form-item prop="securityCode" label="安全码">
          <el-input
            v-model="form.securityCode"
            placeholder="8 位数字安全码"
            :prefix-icon="KeyIcon"
            maxlength="8"
            clearable
          />
        </el-form-item>

        <el-form-item prop="newPassword" label="新密码">
          <el-input
            v-model="form.newPassword"
            type="password"
            placeholder="6-64 位字符"
            :prefix-icon="LockIcon"
            show-password
            clearable
          />
        </el-form-item>

        <el-form-item prop="confirmPassword" label="确认新密码">
          <el-input
            v-model="form.confirmPassword"
            type="password"
            placeholder="再次输入新密码"
            :prefix-icon="LockIcon"
            show-password
            clearable
          />
        </el-form-item>

        <el-button
          type="primary"
          class="submit-btn"
          :loading="loading"
          @click="handleReset"
        >
          <span v-if="!loading">重置密码</span>
          <span v-else>重置中...</span>
        </el-button>
      </el-form>

      <div class="card-footer">
        <span>想起密码了？</span>
        <a @click="goLogin">返回登录</a>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import {
  User as UserIcon,
  Lock as LockIcon,
  Key as KeyIcon
} from '@element-plus/icons-vue'
import { resetPasswordApi } from '@/api/auth'

const router = useRouter()
const formRef = ref<FormInstance>()
const loading = ref(false)

const form = reactive({
  account: '',
  securityCode: '',
  newPassword: '',
  confirmPassword: ''
})

// 确认密码校验
function validateConfirm(_: any, value: string, callback: (err?: Error) => void) {
  if (value === '') {
    callback(new Error('请再次输入新密码'))
  } else if (value !== form.newPassword) {
    callback(new Error('两次输入的密码不一致'))
  } else {
    callback()
  }
}

// 安全码格式校验
function validateSecurityCode(_: any, value: string, callback: (err?: Error) => void) {
  if (value === '') {
    callback(new Error('请输入安全码'))
  } else if (!/^\d{8}$/.test(value)) {
    callback(new Error('安全码必须是 8 位数字'))
  } else {
    callback()
  }
}

const rules: FormRules = {
  account: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  securityCode: [{ required: true, validator: validateSecurityCode, trigger: 'blur' }],
  newPassword: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 6, max: 64, message: '密码长度需在 6-64 之间', trigger: 'blur' }
  ],
  confirmPassword: [{ required: true, validator: validateConfirm, trigger: 'blur' }]
}

async function handleReset() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }

  loading.value = true
  try {
    await resetPasswordApi({
      account: form.account,
      security_code: form.securityCode,
      new_password: form.newPassword
    })
    ElMessage.success('密码重置成功，请使用新密码登录')
    router.push('/login')
  } catch (err) {
    // request.ts 已统一弹错误
  } finally {
    loading.value = false
  }
}

function goLogin() {
  router.push('/login')
}
</script>

<style lang="scss" scoped>
.forgot-page {
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
    background: radial-gradient(circle, #ff7eb0, transparent 70%);
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

.forgot-card {
  position: relative;
  z-index: 1;
  width: 460px;
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
  margin-bottom: 20px;
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

.info-alert {
  margin-bottom: 20px;
}

.forgot-form {
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
  .forgot-card {
    padding: 32px 24px 24px;
    border-radius: 20px;
  }
}

:global(html.dark) {
  .forgot-page {
    background: linear-gradient(135deg, #0e1020 0%, #14122a 100%);
  }
  .forgot-card {
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
