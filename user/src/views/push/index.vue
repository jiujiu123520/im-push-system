<template>
  <div class="push-page">
    <el-card shadow="never" class="card">
      <template #header>
        <div class="card-header"><span class="title">发送推送消息</span>
          <el-tag v-if="!keys.length" type="warning" effect="light">暂无可用 Key，请先<a @click="$router.push('/keys')">创建 Key</a></el-tag>
        </div>
      </template>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="120px" label-position="right">
        <el-row :gutter="16">
          <el-col :xs="24" :sm="24" :md="12" v-if="form.target_type === 'key'">
            <el-form-item label="选择 Key" prop="target_value">
              <el-select v-model="form.target_value" placeholder="请选择" style="width:100%">
                <el-option v-for="k in keys" :key="k.id" :label="k.name + '（' + k.key_value + '）'" :value="k.key_value" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :xs="24" :sm="24" :md="12">
            <el-form-item label="推送目标" prop="target_type">
              <el-radio-group v-model="form.target_type" @change="onTargetChange">
                <el-radio label="broadcast">广播（全部设备）</el-radio>
                <el-radio label="key">仅当前 Key 订阅者</el-radio>
                <el-radio label="device">指定设备</el-radio>
              </el-radio-group>
            </el-form-item>
          </el-col>
          <el-col v-if="form.target_type === 'device'" :xs="24" :sm="24" :md="12">
            <el-form-item label="选择设备" prop="target_value">
              <el-select v-model="form.target_value" filterable placeholder="请选择设备" style="width:100%">
                <el-option v-for="d in devices" :key="d.id"
                  :label="(d.device_name || d.device_id) + '（' + d.platform + '）'" :value="d.device_id" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="24">
            <el-form-item label="标题" prop="title">
              <el-input v-model="form.title" maxlength="64" show-word-limit placeholder="请输入推送标题" />
            </el-form-item>
          </el-col>
          <el-col :span="24">
            <el-form-item label="内容" prop="content">
              <el-input v-model="form.content" type="textarea" :rows="5" maxlength="500" show-word-limit placeholder="请输入推送内容" />
            </el-form-item>
          </el-col>
          <el-col :span="24">
            <el-form-item label="扩展载荷 (JSON, 选填)">
              <el-input v-model="payloadText" type="textarea" :rows="3" placeholder='例如：{"url": "https://example.com"}' />
              <div v-if="payloadError" class="err">{{ payloadError }}</div>
            </el-form-item>
          </el-col>
          <el-col :span="24">
            <el-form-item label="推送优先级">
              <el-radio-group v-model="form.priority">
                <el-radio label="normal">普通</el-radio>
                <el-radio label="high">高优先级</el-radio>
              </el-radio-group>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item>
          <el-button type="primary" :loading="loading" @click="submit">立即推送</el-button>
          <el-button @click="resetForm">重置</el-button>
          <el-button link @click="$router.push('/push-logs')">查看推送记录</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { getKeyListApi } from '@/api/key'
import { getDeviceListApi } from '@/api/device'
import { sendPushApi } from '@/api/push'
import type { PushKey, Device } from '@/api/types'

const formRef = ref<FormInstance>()
const loading = ref(false)
const keys = ref<PushKey[]>([])
const devices = ref<Device[]>([])
const payloadText = ref('')
const payloadError = ref('')

const form = reactive({
  title: '',
  content: '',
  target_type: 'broadcast' as 'broadcast' | 'key' | 'device',
  target_value: '',
  payload: undefined as Record<string, any> | undefined,
  priority: 'normal' as 'normal' | 'high'
})
const rules: FormRules = {
  title:  [{ required: true, message: '请输入标题', trigger: 'blur' },
           { min: 1, max: 64, message: '标题长度 1-64', trigger: 'blur' }],
  content:[{ required: true, message: '请输入内容', trigger: 'blur' },
           { min: 1, max: 500, message: '内容长度 1-500', trigger: 'blur' }],
  target_type: [{ required: true, message: '请选择推送目标', trigger: 'change' }],
  target_value: [{ validator: (_r, v, cb) =>
    form.target_type !== 'broadcast' && !v ? cb(new Error('请填写目标值')) : cb(), trigger: 'change' }]
}

function onTargetChange() { form.target_value = '' }

watch(payloadText, (v) => {
  payloadError.value = ''
  if (!v.trim()) { form.payload = undefined; return }
  try { form.payload = JSON.parse(v) } catch (e: any) { payloadError.value = 'JSON 格式错误: ' + e.message }
})

async function loadKeys() {
  try {
    const r = await getKeyListApi({ page: 1, pageSize: 200 })
    keys.value = (r.data?.list || []).filter((k) => k.status === 1)
  } catch {}
}
async function loadDevices() {
  try {
    const r = await getDeviceListApi({ page: 1, pageSize: 200 })
    devices.value = (r.data?.list || []).filter((d) => d.status === 1)
  } catch {}
}
function resetForm() {
  form.title = ''; form.content = ''
  form.target_type = 'broadcast'; form.target_value = ''
  form.priority = 'normal'
  payloadText.value = ''; payloadError.value = ''
  formRef.value?.resetFields()
}
async function submit() {
  await formRef.value?.validate(async (ok) => {
    if (!ok || payloadError.value) return
    loading.value = true
    try {
      const params: any = {
        target_type: form.target_type,
        target_value: form.target_value,
        title: form.title,
        content: form.content,
        payload: form.payload,
        priority: form.priority
      }
      const r = await sendPushApi(params)
      const d = r.data
      if (d?.status === 1) {
        ElMessage.success(`推送成功（成功 ${d.success_count} 台）`)
      } else if (d?.status === 2) {
        ElMessage.warning(`部分成功（成功 ${d.success_count}，失败 ${d.fail_count}）`)
      } else {
        ElMessage.error(d?.fail_reason || d?.message || '推送失败')
      }
    } catch (e: any) { ElMessage.error(e?.message || '推送失败')
    } finally { loading.value = false }
  })
}
onMounted(() => { loadKeys(); loadDevices() })
</script>

<style lang="scss" scoped>
.push-page { max-width: 960px; margin: 0 auto; }
.card { .card-header { display: flex; align-items: center; justify-content: space-between;
    .title { font-weight: 600; font-size: $font-size-lg; } } }
.err { margin-top: 4px; color: var(--color-danger); font-size: $font-size-xs; }
</style>
