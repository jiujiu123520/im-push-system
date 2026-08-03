<template>
  <div class="dashboard-page">
    <!-- 统计卡片 -->
    <el-row :gutter="16" class="stat-row">
      <el-col v-for="(s, i) in stats" :key="i" :xs="12" :sm="12" :md="6" :lg="6">
        <div class="stat-card" :style="{ background: s.bg }">
          <div class="stat-left">
            <div class="label">{{ s.label }}</div>
            <div class="value">{{ s.value }}</div>
            <div class="hint">{{ s.hint }}</div>
          </div>
          <div class="stat-icon" :style="{ background: s.iconBg }">
            <el-icon :size="24"><component :is="s.icon" /></el-icon>
          </div>
        </div>
      </el-col>
    </el-row>

    <el-row :gutter="16" class="charts-row">
      <el-col :xs="24" :sm="24" :md="24" :lg="24">
        <el-card shadow="never" class="card">
          <template #header><div class="card-header">
            <span class="title">近 7 天推送趋势</span>
          </div></template>
          <v-chart class="chart" :option="trendChartOption" autoresize />
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="16" class="charts-row">
      <el-col :xs="24" :sm="24" :md="24" :lg="24">
        <el-card shadow="never" class="card">
          <template #header><div class="card-header">
            <span class="title">快捷操作</span>
          </div></template>
          <div class="quick-actions">
            <el-button class="action-btn" type="primary" @click="$router.push('/push')">
              <el-icon :size="18"><Promotion /></el-icon><span>立即推送</span>
            </el-button>
            <el-button class="action-btn" type="success" @click="$router.push('/keys')">
              <el-icon :size="18"><Key /></el-icon><span>创建 Push Key</span>
            </el-button>
            <el-button class="action-btn" type="warning" @click="$router.push('/app')">
              <el-icon :size="18"><Download /></el-icon><span>下载 APP</span>
            </el-button>
            <el-button class="action-btn" type="info" @click="$router.push('/profile')">
              <el-icon :size="18"><User /></el-icon><span>个人中心</span>
            </el-button>
          </div>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { BarChart, LineChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, TitleComponent } from 'echarts/components'
import VChart from 'vue-echarts'
import { Promotion, Key, Download, User, Cellphone, Monitor, DataLine } from '@element-plus/icons-vue'
import { getDashboardOverviewApi } from '@/api/dashboard'
import type { DashboardOverview } from '@/api/types'
use([CanvasRenderer, BarChart, LineChart, GridComponent, TooltipComponent, TitleComponent])

const overview = ref<DashboardOverview>({
  total_devices: 0, online_devices: 0, total_keys: 0, active_keys: 0,
  today_push: 0, yesterday_push: 0, today_new_devices: 0,
  trend_7d: []
})

const stats = computed(() => [
  { label: '设备总数',    value: overview.value.total_devices,    hint: '累计接入', icon: Cellphone,
    bg: 'linear-gradient(135deg,#eff6ff,#dbeafe)',   iconBg: 'linear-gradient(135deg,#3b82f6,#60a5fa)' },
  { label: '在线设备',    value: overview.value.online_devices,    hint: '实时在线', icon: Monitor,
    bg: 'linear-gradient(135deg,#ecfdf5,#d1fae5)',   iconBg: 'linear-gradient(135deg,#10b981,#34d399)' },
  { label: '今日推送',    value: overview.value.today_push,hint: '较昨日 '+compareYesterday, icon: DataLine,
    bg: 'linear-gradient(135deg,#fef3c7,#fde68a)',   iconBg: 'linear-gradient(135deg,#f59e0b,#fbbf24)' },
  { label: 'Push Key',    value: overview.value.total_keys,       hint: '可用数量', icon: Key,
    bg: 'linear-gradient(135deg,#f0f9ff,#bae6fd)',   iconBg: 'linear-gradient(135deg,#0ea5e9,#38bdf8)' },
])
const compareYesterday = computed(() => {
  const y = overview.value.yesterday_push || 0
  const t = overview.value.today_push || 0
  const diff = t - y
  if (y === 0) return t === 0 ? '持平' : '+100%'
  const pct = ((diff / y) * 100).toFixed(0)
  return (diff >= 0 ? '+' : '') + pct + '%'
})

const trendChartOption = computed(() => {
  const data = overview.value.trend_7d || []
  return {
    grid: { left: 40, right: 16, top: 20, bottom: 30 },
    tooltip: { trigger: 'axis' },
    xAxis: { type: 'category', data: data.map((d: any) => d.date),
             axisLabel: { color: '#64748b', fontSize: 12 }, axisLine: { lineStyle: { color: '#e2e8f0' } } },
    yAxis: { type: 'value', axisLabel: { color: '#64748b' }, splitLine: { lineStyle: { color: '#f1f5f9' } } },
    series: [{ type: 'line', smooth: true, data: data.map((d: any) => d.count),
               symbol: 'circle', symbolSize: 8,
               lineStyle: { color: '#0ea5e9', width: 3 },
               itemStyle: { color: '#0ea5e9', borderColor: '#fff', borderWidth: 2 },
               areaStyle: { color: { type: 'linear', x:0,y:0,x2:0,y2:1,
                 colorStops:[{offset:0,color:'rgba(14,165,233,0.32)'},{offset:1,color:'rgba(14,165,233,0.02)'}] } } }]
  }
})

onMounted(async () => {
  try {
    const res = await getDashboardOverviewApi()
    overview.value = Object.assign(overview.value, res.data || {})
  } catch {}
})
</script>

<style lang="scss" scoped>
.dashboard-page { }
.stat-row { margin-bottom: 16px; }
.stat-card {
  position: relative; padding: 20px; border-radius: $radius-lg;
  display: flex; justify-content: space-between; align-items: center;
  overflow: hidden;
  @include hover-float(-2px);
}
.stat-left { .label { font-size: $font-size-sm; color: var(--text-secondary); }
             .value { margin-top: 6px; font-size: $font-size-3xl; font-weight: 700; color: var(--text-primary); }
             .hint  { margin-top: 4px; font-size: $font-size-xs; color: var(--text-secondary); } }
.stat-icon {
  width: 52px; height: 52px; border-radius: $radius-md;
  color: #fff; display: flex; align-items: center; justify-content: center;
  box-shadow: 0 6px 14px rgba(15,23,42,0.12);
}
.charts-row { margin-bottom: 16px; }
.card { border-radius: $radius-lg;
  .card-header { display: flex; justify-content: space-between; align-items: center;
    .title { font-size: $font-size-md; font-weight: 600; color: var(--text-primary); } } }
.chart { width: 100%; height: 320px; }
.quick-actions { display: grid; grid-template-columns: 1fr 1fr; gap: $space-3;
  .action-btn { height: 80px; display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: $space-2; border-radius: $radius-md; font-weight: 500; } }
</style>
