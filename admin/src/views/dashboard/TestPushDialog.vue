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
            <el-icon><SearchIcon /></el-icon>
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
              <span>在线设备：{{ deviceCount }}</span>
              <span>连接数：{{ connectionCount }}</span>
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

      <!-- 在线设备列表（按 Key 查询时显示） -->
      <el-form-item v-if="onlineChecked && onlineStatus && onlineDeviceDetails.length > 0" label=" ">
        <div class="online-device-list">
          <div class="list-title">在线设备详情（可禁用/踢出）</div>
          <el-table :data="onlineDeviceDetails" size="small" border max-height="280">
            <el-table-column prop="device_id" label="设备 ID" min-width="160">
              <template #default="{ row }">
                <div style="display: flex; align-items: center; gap: 4px;">
                  <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ row.device_id }}</span>
                  <el-button text type="primary" size="small" @click="copyText(row.device_id)">
                    <el-icon><CopyDocumentIcon /></el-icon>
                  </el-button>
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="device_model" label="型号" min-width="100" />
            <el-table-column prop="platform" label="平台" width="90">
              <template #default="{ row }">
                <el-tag v-if="row.platform" size="small" effect="plain" round>{{ row.platform }}</el-tag>
                <span v-else style="color: var(--el-text-color-secondary);">-</span>
              </template>
            </el-table-column>
            <el-table-column prop="fd_count" label="连接数" width="70" align="center" />
            <el-table-column prop="last_active_at" label="最后活跃" min-width="140" />
            <el-table-column label="操作" width="140" fixed="right">
              <template #default="{ row }">
                <el-button
                  v-if="row.db_id"
                  text type="warning" size="small"
                  :loading="kickingId === row.db_id"
                  @click="kickDevice(row)"
                >
                  踢出
                </el-button>
                <el-button
                  v-if="row.db_id && row.status !== 2"
                  text type="danger" size="small"
                  :loading="disablingId === row.db_id"
                  @click="disableDevice(row)"
                >
                  禁用
                </el-button>
              </template>
            </el-table-column>
          </el-table>
        </div>
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
        <el-icon><PromotionIcon /></el-icon>
        发送测试推送
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { reactive, ref, computed, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  Search as SearchIcon,
  Promotion as PromotionIcon,
  CopyDocument as CopyDocumentIcon,
} from '@element-plus/icons-vue'
import { sendTestPushApi, checkOnlineApi } from '@/api/push'
import { kickDeviceApi, toggleDeviceStatusApi } from '@/api/device'
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
const deviceCount = ref(0)
const connectionCount = ref(0)
const onlineDetail = ref<any>(null)
const onlineDeviceDetails = ref<any[]>([])
const result = ref<TestPushResult | null>(null)
const kickingId = ref(0)
const disablingId = ref(0)

// 切换目标类型时重置检查状态
watch(() => form.target_type, () => {
  onlineChecked.value = false
  onlineStatus.value = false
  onlineCount.value = 0
  deviceCount.value = 0
  connectionCount.value = 0
  onlineDetail.value = null
  onlineDeviceDetails.value = []
  result.value = null
})

const onlineAlertText = computed(() => {
  if (!onlineChecked.value) return ''
  if (onlineStatus.value) {
    return `目标在线，${deviceCount.value} 个设备，${connectionCount.value} 个连接`
  }
  return '目标离线，设备未连接或 Key 无订阅'
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
    onlineStatus.value = res.data.online
    onlineCount.value = res.data.online_count
    deviceCount.value = res.data.device_count ?? res.data.online_count ?? 0
    connectionCount.value = res.data.connection_count ?? 0
    onlineDetail.value = res.data.detail
    onlineDeviceDetails.value = res.data.detail?.online_device_details ?? []
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

/** 踢出设备（断开连接但不禁用） */
async function kickDevice(row: any) {
  if (!row.db_id) return
  try {
    await ElMessageBox.confirm(
      `确定要踢出设备 ${row.device_id} 吗？将断开其所有在线连接，设备可重新连接。`,
      '踢出确认',
      { type: 'warning' }
    )
  } catch {
    return
  }
  kickingId.value = row.db_id
  try {
    const res = await kickDeviceApi(row.db_id)
    ElMessage.success(res.data?.message || '已踢出')
    // 刷新在线状态
    await checkOnline()
  } catch (err) {
    ElMessage.error('踢出失败')
  } finally {
    kickingId.value = 0
  }
}

/** 禁用设备 */
async function disableDevice(row: any) {
  if (!row.db_id) return
  try {
    await ElMessageBox.confirm(
      `确定要禁用设备 ${row.device_id} 吗？将断开连接且设备无法重连。`,
      '禁用确认',
      { type: 'warning' }
    )
  } catch {
    return
  }
  disablingId.value = row.db_id
  try {
    await toggleDeviceStatusApi(row.db_id, 2)
    ElMessage.success('设备已禁用')
    await checkOnline()
  } catch (err) {
    ElMessage.error('禁用失败')
  } finally {
    disablingId.value = 0
  }
}

/** 复制文本到剪贴板 */
function copyText(text: string) {
  navigator.clipboard.writeText(text).then(() => {
    ElMessage.success('已复制')
  }).catch(() => {
    ElMessage.warning('复制失败')
  })
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

.online-device-list {
  width: 100%;

  .list-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--el-text-color-primary);
    margin-bottom: 8px;
  }
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
