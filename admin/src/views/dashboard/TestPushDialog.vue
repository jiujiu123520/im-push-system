<template>
  <el-dialog
    v-model="visible"
    title="测试调试推送"
    width="640px"
    :close-on-click-modal="false"
    class="test-push-dialog"
  >
    <!-- 推送表单 -->
    <el-form :model="form" label-width="100px" class="test-push-form">
      <el-form-item label="推送目标">
        <el-radio-group v-model="form.target_type">
          <el-radio-button value="device">按设备推送</el-radio-button>
          <el-radio-button value="key">按 Key 推送</el-radio-button>
        </el-radio-group>
      </el-form-item>

      <el-form-item :label="form.target_type === 'device' ? '设备 ID' : 'Key 值'">
        <div style="display: flex; gap: 8px; width: 100%">
          <el-input
            v-model="form.target_value"
            :placeholder="form.target_type === 'device' ? '请输入设备 ID' : '请输入 Key 值'"
            clearable
            @keyup.enter="checkOnline"
          />
          <el-button :loading="checking" @click="checkOnline">
            <el-icon><Search /></el-icon>
            检查在线
          </el-button>
        </div>
      </el-form-item>

      <!-- 在线状态提示 -->
      <el-form-item v-if="onlineChecked" label=" ">
        <el-alert
          :title="onlineAlertText"
          :type="onlineStatus ? 'success' : 'warning'"
          :closable="false"
          show-icon
        >
          <template #default>
            <div class="online-detail">
              <span>在线数：{{ onlineCount }}</span>
              <span v-if="onlineDetail?.subscribed_total !== undefined">
                订阅数：{{ onlineDetail.subscribed_total }}
              </span>
              <span v-if="onlineDetail?.key_value">
                关联 Key：{{ onlineDetail.key_value }}
              </span>
            </div>
          </template>
        </el-alert>
      </el-form-item>

      <el-form-item label="消息标题">
        <el-input
          v-model="form.title"
          placeholder="留空则使用默认：【测试推送】"
          clearable
        />
      </el-form-item>

      <el-form-item label="消息内容">
        <el-input
          v-model="form.content"
          type="textarea"
          :rows="3"
          placeholder="留空则使用默认测试内容"
        />
      </el-form-item>

      <el-form-item label="优先级">
        <el-select v-model="form.priority" style="width: 160px">
          <el-option label="高（顶部弹出）" value="high" />
          <el-option label="普通" value="normal" />
          <el-option label="低（静默）" value="low" />
        </el-select>
      </el-form-item>
    </el-form>

    <!-- 推送结果 -->
    <div v-if="result" class="push-result">
      <el-divider content-position="left">推送结果</el-divider>
      <div class="result-stats">
        <div class="result-stat success">
          <div class="stat-num">{{ result.success_count }}</div>
          <div class="stat-label">成功</div>
        </div>
        <div class="result-stat fail">
          <div class="stat-num">{{ result.fail_count }}</div>
          <div class="stat-label">失败</div>
        </div>
        <div class="result-stat online">
          <div class="stat-num">{{ result.online_count }}</div>
          <div class="stat-label">在线</div>
        </div>
        <div class="result-stat time">
          <div class="stat-num">{{ result.elapsed_ms }}</div>
          <div class="stat-label">耗时(ms)</div>
        </div>
      </div>

      <!-- 调试信息 -->
      <el-collapse class="debug-collapse">
        <el-collapse-item title="调试详情" name="debug">
          <div class="debug-info">
            <div class="debug-row">
              <span class="debug-key">目标类型</span>
              <span class="debug-val">{{ result.debug.target_type }}</span>
            </div>
            <div class="debug-row">
              <span class="debug-key">目标值</span>
              <span class="debug-val">{{ result.debug.target_value }}</span>
            </div>
            <div class="debug-row">
              <span class="debug-key">服务器时间</span>
              <span class="debug-val">{{ result.debug.server_time }}</span>
            </div>
            <div v-if="result.debug.device_online !== undefined" class="debug-row">
              <span class="debug-key">设备在线</span>
              <span class="debug-val" :class="result.debug.device_online ? 'text-success' : 'text-warning'">
                {{ result.debug.device_online ? '是' : '否' }}
              </span>
            </div>
            <div v-if="result.debug.online_fd_count !== undefined" class="debug-row">
              <span class="debug-key">在线 FD 数</span>
              <span class="debug-val">{{ result.debug.online_fd_count }}</span>
            </div>
            <div v-if="result.debug.subscribed_devices !== undefined" class="debug-row">
              <span class="debug-key">订阅设备数</span>
              <span class="debug-val">{{ result.debug.subscribed_devices }}</span>
            </div>
            <div v-if="result.debug.online_devices !== undefined" class="debug-row">
              <span class="debug-key">在线设备数</span>
              <span class="debug-val">{{ result.debug.online_devices }}</span>
            </div>
          </div>
        </el-collapse-item>

        <el-collapse-item v-if="result.detail && result.detail.length" title="设备明细" name="detail">
          <el-table :data="result.detail" size="small" border>
            <el-table-column prop="device_id" label="设备 ID" min-width="180" />
            <el-table-column prop="status" label="状态" width="100">
              <template #default="{ row }">
                <el-tag
                  :type="row.status === 'success' ? 'success' : row.status === 'offline' ? 'warning' : 'danger'"
                  size="small"
                >
                  {{ row.status === 'success' ? '成功' : row.status === 'offline' ? '离线' : '失败' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="reason" label="原因" min-width="150" />
          </el-table>
        </el-collapse-item>
      </el-collapse>
    </div>

    <template #footer>
      <el-button @click="visible = false">关闭</el-button>
      <el-button type="primary" :loading="sending" @click="sendTest">
        <el-icon><Promotion /></el-icon>
        发送测试推送
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { reactive, ref, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { Search, Promotion } from '@element-plus/icons-vue'
import { sendTestPushApi, checkOnlineApi } from '@/api/push'
import type { TestPushResult } from '@/api/types'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits(['update:modelValue'])

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const form = reactive({
  target_type: 'key' as 'device' | 'key',
  target_value: '',
  title: '',
  content: '',
  priority: 'high' as 'high' | 'normal' | 'low',
})

const sending = ref(false)
const checking = ref(false)
const onlineChecked = ref(false)
const onlineStatus = ref(false)
const onlineCount = ref(0)
const onlineDetail = ref<any>(null)
const result = ref<TestPushResult | null>(null)

// 切换目标类型时重置检查状态
watch(() => form.target_type, () => {
  onlineChecked.value = false
  onlineStatus.value = false
  onlineCount.value = 0
  onlineDetail.value = null
  result.value = null
})

const onlineAlertText = computed(() => {
  if (!onlineChecked.value) return ''
  return onlineStatus.value
    ? `目标在线，共 ${onlineCount.value} 个连接`
    : '目标离线，设备未连接或 Key 无订阅'
})

/** 检查在线状态 */
async function checkOnline() {
  if (!form.target_value.trim()) {
    ElMessage.warning('请先输入目标值')
    return
  }
  checking.value = true
  try {
    const res = await checkOnlineApi({
      type: form.target_type,
      value: form.target_value.trim(),
    })
    onlineChecked.value = true
    onlineStatus.value = res.online
    onlineCount.value = res.online_count
    onlineDetail.value = res.detail
  } catch (err) {
    ElMessage.error('检查失败')
  } finally {
    checking.value = false
  }
}

/** 发送测试推送 */
async function sendTest() {
  if (!form.target_value.trim()) {
    ElMessage.warning('请输入目标值')
    return
  }
  sending.value = true
  result.value = null
  try {
    const res = await sendTestPushApi({
      target_type: form.target_type,
      target_value: form.target_value.trim(),
      title: form.title || undefined,
      content: form.content || undefined,
      priority: form.priority,
    })
    result.value = res.data
    if (res.data?.success_count > 0) {
      ElMessage.success(`测试推送成功，送达 ${res.data.success_count} 台设备`)
    } else if (res.data?.online_count === 0) {
      ElMessage.warning('目标设备离线，消息已存为离线消息')
    } else {
      ElMessage.error('推送失败，请检查设备连接状态')
    }
  } catch (err) {
    ElMessage.error('推送请求失败')
  } finally {
    sending.value = false
  }
}
</script>

<style lang="scss" scoped>
.test-push-dialog {
  :deep(.el-dialog__body) {
    max-height: 60vh;
    overflow-y: auto;
  }
}

.test-push-form {
  margin-top: 8px;
}

.online-detail {
  display: flex;
  gap: 16px;
  font-size: 13px;
  margin-top: 4px;
}

.push-result {
  margin-top: 8px;
}

.result-stats {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}

.result-stat {
  flex: 1;
  text-align: center;
  padding: 16px 8px;
  border-radius: 12px;
  background: var(--el-fill-color-light);

  .stat-num {
    font-size: 28px;
    font-weight: 700;
    line-height: 1.2;
  }

  .stat-label {
    font-size: 12px;
    color: var(--el-text-color-secondary);
    margin-top: 4px;
  }

  &.success .stat-num { color: var(--el-color-success); }
  &.fail .stat-num { color: var(--el-color-danger); }
  &.online .stat-num { color: var(--el-color-primary); }
  &.time .stat-num { color: var(--el-color-warning); }
}

.debug-collapse {
  :deep(.el-collapse-item__content) {
    padding-bottom: 8px;
  }
}

.debug-info {
  background: var(--el-fill-color-lighter);
  border-radius: 8px;
  padding: 12px 16px;
  font-size: 13px;
}

.debug-row {
  display: flex;
  padding: 4px 0;

  .debug-key {
    width: 100px;
    color: var(--el-text-color-secondary);
  }

  .debug-val {
    flex: 1;
    word-break: break-all;
  }
}

.text-success { color: var(--el-color-success); font-weight: 600; }
.text-warning { color: var(--el-color-warning); font-weight: 600; }
</style>
