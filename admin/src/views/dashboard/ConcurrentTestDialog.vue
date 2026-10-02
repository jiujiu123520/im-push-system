<template>
  <el-dialog
    v-model="visible"
    title="并发压测推送"
    width="720px"
    :close-on-click-modal="false"
    class="concurrent-test-dialog"
  >
    <el-alert type="warning" :closable="false" class="concurrent-tip">
      <template #title>
        <div class="concurrent-tip-content">
          <strong>注意事项：</strong>
          <div>· 总次数 = 每批条数 × 批次数，建议从小到大逐步加压</div>
          <div>· 压测会真实推送消息到目标设备/Key，请注意影响</div>
          <div>· 间隔毫秒数越大对服务器冲击越小，建议初始 100ms</div>
          <div>· 「受理成功」指服务端已把指令可靠投进推送队列，真实投递由推送进程异步完成</div>
        </div>
      </template>
    </el-alert>

    <el-form :model="form" label-width="110px" class="concurrent-form">
      <div class="form-row">
        <el-form-item label="目标类型">
          <el-select v-model="form.targetType" style="width: 100%">
            <el-option label="按推送 Key" value="key" />
            <el-option label="按设备 ID" value="device" />
          </el-select>
        </el-form-item>
        <el-form-item label="目标值" required>
          <el-input
            v-model="form.targetValue"
            :placeholder="form.targetType === 'key' ? '请输入推送 Key' : '请输入设备 ID'"
            clearable
            @keyup.enter="runTest"
          />
        </el-form-item>
      </div>

      <div class="form-row">
        <el-form-item label="标题（可选）">
          <el-input v-model="form.title" placeholder="留空默认为【并发压测】" clearable />
        </el-form-item>
        <el-form-item label="内容（可选）">
          <el-input v-model="form.content" placeholder="留空默认为并发压测消息" clearable />
        </el-form-item>
      </div>

      <div class="form-row">
        <el-form-item label="优先级">
          <el-select v-model="form.priority" style="width: 100%">
            <el-option label="高（high）" value="high" />
            <el-option label="普通（normal）" value="normal" />
            <el-option label="低（low）" value="low" />
          </el-select>
        </el-form-item>
        <el-form-item label="每批条数">
          <el-input-number
            v-model="form.concurrency"
            :min="1"
            :max="1000"
            :step="1"
            controls-position="right"
            style="width: 100%"
          />
        </el-form-item>
      </div>

      <div class="form-row">
        <el-form-item label="总推送次数">
          <el-input-number
            v-model="form.total"
            :min="0"
            :max="10000"
            :step="10"
            controls-position="right"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="批次间隔(ms)">
          <el-input-number
            v-model="form.intervalMs"
            :min="0"
            :max="60000"
            :step="10"
            controls-position="right"
            style="width: 100%"
          />
        </el-form-item>
      </div>
    </el-form>

    <div class="form-actions">
      <el-button
        type="primary"
        :icon="PromotionIcon"
        :loading="running"
        @click="runTest"
      >
        开始压测
      </el-button>
    </div>

    <!-- 压测结果 -->
    <div v-if="result" class="concurrent-result">
      <div class="result-header">
        <span class="result-title">压测结果</span>
        <el-tag :type="result.fail_count === 0 ? 'success' : 'warning'" size="small">
          {{ result.fail_count === 0 ? '全部受理' : '部分失败' }}
        </el-tag>
      </div>
      <div class="result-grid">
        <div class="result-item">
          <span class="result-label">每批条数</span>
          <span class="result-value mono">{{ result.concurrency }}</span>
        </div>
        <div class="result-item">
          <span class="result-label">总推送数</span>
          <span class="result-value mono">{{ result.total_sent }}</span>
        </div>
        <div class="result-item">
          <span class="result-label">受理成功</span>
          <span class="result-value mono success">{{ result.success_count }}</span>
        </div>
        <div class="result-item">
          <span class="result-label">失败数</span>
          <span class="result-value mono danger">{{ result.fail_count }}</span>
        </div>
        <div class="result-item">
          <span class="result-label">总耗时</span>
          <span class="result-value mono">{{ result.elapsed_ms }} ms</span>
        </div>
        <div class="result-item">
          <span class="result-label">平均耗时</span>
          <span class="result-value mono">{{ result.avg_ms }} ms</span>
        </div>
        <div class="result-item highlight">
          <span class="result-label">QPS</span>
          <span class="result-value mono">{{ result.qps }}</span>
        </div>
        <div class="result-item">
          <span class="result-label">批次数</span>
          <span class="result-value mono">{{ result.batches }}</span>
        </div>
      </div>
      <div v-if="result.detail && result.detail.length > 0" class="result-detail">
        <div class="detail-title">失败详情（最多显示前 10 条）：</div>
        <div
          v-for="(item, idx) in result.detail.slice(0, 10)"
          :key="idx"
          class="detail-line mono"
        >
          #{{ item.seq }} [批次 {{ item.batch }}] {{ item.reason }}
        </div>
      </div>
    </div>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Promotion as PromotionIcon } from '@element-plus/icons-vue'
import { concurrentTestPushApi } from '@/api/push'
import type { ConcurrentTestResult } from '@/api/types'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits(['update:modelValue'])

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const form = reactive({
  targetType: 'key' as 'device' | 'key',
  targetValue: '',
  title: '',
  content: '',
  priority: 'high' as 'high' | 'normal' | 'low',
  concurrency: 10,
  total: 100,
  intervalMs: 0,
})

const running = ref(false)
const result = ref<ConcurrentTestResult | null>(null)

async function runTest() {
  if (!form.targetValue.trim()) {
    ElMessage.warning('请输入目标设备 ID 或推送 Key')
    return
  }
  if (form.concurrency < 1 || form.concurrency > 1000) {
    ElMessage.warning('每批条数范围为 1-1000')
    return
  }
  if (form.total < 0 || form.total > 10000) {
    ElMessage.warning('总推送次数范围为 0-10000')
    return
  }

  running.value = true
  result.value = null
  try {
    const res = await concurrentTestPushApi({
      target_type: form.targetType,
      target_value: form.targetValue.trim(),
      title: form.title || undefined,
      content: form.content || undefined,
      priority: form.priority,
      concurrency: form.concurrency,
      total: form.total,
      interval_ms: form.intervalMs,
    })
    result.value = res.data
    if (res.data.fail_count === 0) {
      ElMessage.success(`并发压测完成，共推送 ${res.data.total_sent} 条，全部受理`)
    } else {
      ElMessage.warning(`并发压测完成：受理 ${res.data.success_count} / 失败 ${res.data.fail_count}`)
    }
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : '并发压测失败')
  } finally {
    running.value = false
  }
}
</script>

<style lang="scss" scoped>
.concurrent-tip {
  margin-bottom: 16px;

  .concurrent-tip-content {
    line-height: 1.8;
    font-size: 12px;

    strong {
      display: block;
      margin-bottom: 4px;
    }
  }
}

.concurrent-form {
  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 16px;
  }
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 4px;
}

.concurrent-result {
  margin-top: 16px;
  padding: 16px;
  border-radius: $radius-md;
  background: var(--bg-page);
  border: 1px solid var(--border-light);

  .result-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;

    .result-title {
      font-size: 14px;
      font-weight: 700;
      color: var(--text-primary);
    }
  }

  .result-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
  }

  .result-item {
    padding: 10px 12px;
    border-radius: $radius-sm;
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    display: flex;
    flex-direction: column;
    gap: 4px;

    &.highlight {
      background: linear-gradient(135deg, rgba(24, 194, 156, 0.1), rgba(92, 184, 255, 0.06));
      border-color: rgba(24, 194, 156, 0.3);

      .result-value {
        color: $color-success;
        font-size: 18px;
      }
    }

    .result-label {
      font-size: 11px;
      color: var(--text-secondary);
    }

    .result-value {
      font-size: 15px;
      font-weight: 700;
      color: var(--text-primary);

      &.success {
        color: $color-success;
      }
      &.danger {
        color: $color-danger;
      }
    }
  }

  .result-detail {
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: $radius-sm;
    background: rgba(255, 90, 110, 0.06);
    border: 1px solid rgba(255, 90, 110, 0.2);

    .detail-title {
      font-size: 12px;
      font-weight: 700;
      color: $color-danger;
      margin-bottom: 6px;
    }

    .detail-line {
      font-size: 11px;
      color: var(--text-regular);
      padding: 2px 0;
      word-break: break-all;
    }
  }
}
</style>
