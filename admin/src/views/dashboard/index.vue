<template>
  <div class="page-container dashboard">
    <!-- 页头 -->
    <div class="dashboard-header">
      <div>
        <h2 class="welcome-title">
          {{ greeting }}，{{ userStore.userInfo?.nickname || userStore.username || '管理员' }} 👋
        </h2>
        <p class="welcome-sub">欢迎使用 Push 即时消息推送管理平台</p>
      </div>
      <div class="header-actions">
        <el-button :icon="Refresh" round @click="refreshAll" :loading="refreshing">
          刷新数据
        </el-button>
        <el-button :icon="Monitor" round @click="testPushVisible = true">
          测试推送
        </el-button>
        <el-button type="primary" :icon="Promotion" round @click="goPush">
          发起推送
        </el-button>
      </div>
    </div>

    <!-- 数据卡片 -->
    <div class="stat-grid">
      <div
        v-for="(card, idx) in statCards"
        :key="card.title"
        class="stat-card"
        :class="`stat-${idx}`"
        :style="{ animationDelay: `${idx * 0.08}s` }"
      >
        <div class="stat-icon" :class="card.iconBg">
          <el-icon><component :is="card.icon" /></el-icon>
        </div>
        <div class="stat-content">
          <div class="stat-label">{{ card.title }}</div>
          <div class="stat-value">
            <span class="num">{{ formatNum(card.value) }}</span>
            <span v-if="card.unit" class="unit">{{ card.unit }}</span>
          </div>
          <div class="stat-trend">
            <el-icon :class="card.trend >= 0 ? 'up' : 'down'">
              <CaretTop v-if="card.trend >= 0" />
              <CaretBottom v-else />
            </el-icon>
            <span :class="card.trend >= 0 ? 'up' : 'down'">
              {{ Math.abs(card.trend) }}%
            </span>
            <span class="trend-label">较昨日</span>
          </div>
        </div>
        <div class="stat-spark"></div>
      </div>
    </div>

    <!-- 图表区 -->
    <div class="chart-grid">
      <!-- 在线设备趋势 -->
      <div class="chart-card chart-large">
        <div class="chart-header">
          <div>
            <h3 class="chart-title">在线设备趋势</h3>
            <p class="chart-sub">最近 7 天在线设备数量变化</p>
          </div>
          <el-radio-group v-model="onlineRange" size="small">
            <el-radio-button value="7">近7天</el-radio-button>
            <el-radio-button value="30">近30天</el-radio-button>
          </el-radio-group>
        </div>
        <v-chart :option="onlineOption" autoresize class="chart-body" />
      </div>

      <!-- 今日推送量 -->
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <h3 class="chart-title">今日推送量</h3>
            <p class="chart-sub">按小时统计</p>
          </div>
        </div>
        <v-chart :option="todayPushOption" autoresize class="chart-body" />
      </div>
    </div>

    <div class="chart-grid chart-grid-2">
      <!-- Key 数量分布 -->
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <h3 class="chart-title">Key 状态分布</h3>
            <p class="chart-sub">按使用状态统计</p>
          </div>
        </div>
        <v-chart :option="keyDistOption" autoresize class="chart-body" />
      </div>

      <!-- 设备平台分布 -->
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <h3 class="chart-title">设备平台分布</h3>
            <p class="chart-sub">按设备平台统计</p>
          </div>
        </div>
        <v-chart :option="platformOption" autoresize class="chart-body" />
      </div>
    </div>

    <!-- 最新推送记录 -->
    <div class="recent-card">
      <div class="chart-header">
        <div>
          <h3 class="chart-title">最新推送记录</h3>
          <p class="chart-sub">最近 5 条推送任务</p>
        </div>
        <el-button text type="primary" @click="$router.push('/push-logs')">
          查看全部<el-icon class="el-icon--right"><ArrowRight /></el-icon>
        </el-button>
      </div>
      <el-table :data="recentPush" style="width: 100%">
        <el-table-column prop="title" label="推送标题" min-width="180" show-overflow-tooltip />
        <el-table-column prop="platform" label="平台" width="100">
          <template #default="{ row }">
            <el-tag :type="platformTag(row.platform)" effect="light" round>
              {{ row.platform }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="送达" width="160">
          <template #default="{ row }">
            <span class="success-text">{{ row.successCount }}</span>
            <span class="muted"> / {{ row.successCount + row.failCount }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status)" effect="plain" round size="small">
              {{ statusLabel(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="createdAt" label="时间" width="180" />
      </el-table>
    </div>

    <!-- 测试推送对话框 -->
    <TestPushDialog v-model="testPushVisible" />
  </div>
</template>

<script setup lang="ts">
import { computed, markRaw, onMounted, ref, shallowRef } from 'vue'
import { useRouter } from 'vue-router'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart, BarChart, PieChart } from 'echarts/charts'
import {
  GridComponent,
  TooltipComponent,
  LegendComponent,
  TitleComponent,
  DataZoomComponent
} from 'echarts/components'
import VChart from 'vue-echarts'
import {
  Refresh,
  Promotion,
  ArrowRight,
  CaretTop,
  CaretBottom,
  Cellphone,
  Bell,
  Key,
  User,
  Monitor
} from '@element-plus/icons-vue'
import { useUserStore } from '@/stores/user'
import { useAppStore } from '@/stores/app'
import TestPushDialog from './TestPushDialog.vue'

// 注册 ECharts
use([
  CanvasRenderer,
  LineChart,
  BarChart,
  PieChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  TitleComponent,
  DataZoomComponent
])

const router = useRouter()
const userStore = useUserStore()
const appStore = useAppStore()

const refreshing = ref(false)

// 测试推送对话框
const testPushVisible = ref(false)
const onlineRange = ref<'7' | '30'>('7')

// 问候语
const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 6) return '凌晨好'
  if (h < 9) return '早上好'
  if (h < 12) return '上午好'
  if (h < 14) return '中午好'
  if (h < 18) return '下午好'
  return '晚上好'
})

// 数据卡片
const statCards = ref([
  {
    title: '在线设备',
    value: 12860,
    unit: '台',
    trend: 12.5,
    icon: markRaw(Cellphone),
    iconBg: 'bg-primary'
  },
  {
    title: '今日推送量',
    value: 86420,
    unit: '条',
    trend: 8.3,
    icon: markRaw(Bell),
    iconBg: 'bg-cyan'
  },
  {
    title: '活跃 Key 数',
    value: 356,
    unit: '个',
    trend: 5.2,
    icon: markRaw(Key),
    iconBg: 'bg-success'
  },
  {
    title: '注册用户',
    value: 24580,
    unit: '人',
    trend: -2.1,
    icon: markRaw(User),
    iconBg: 'bg-warm'
  }
])

// 主题色（响应暗色）
const isDark = computed(() => appStore.theme === 'dark')
const textColor = computed(() => (isDark.value ? '#b4b8d4' : '#5a5f78'))
const axisLineColor = computed(() => (isDark.value ? '#2a2d4a' : '#e8e9f2'))
const splitLineColor = computed(() =>
  isDark.value ? 'rgba(255,255,255,0.04)' : 'rgba(109,92,255,0.06)'
)

// 在线设备趋势数据
const onlineDates7 = ['周一', '周二', '周三', '周四', '周五', '周六', '周日']
const onlineData7 = [8200, 9320, 9016, 10340, 11890, 12900, 12860]
const onlineDates30 = Array.from({ length: 30 }, (_, i) => `${i + 1}日`)
const onlineData30 = Array.from({ length: 30 }, (_, i) =>
  Math.round(8000 + Math.random() * 6000 + i * 80)
)

const onlineOption = computed(() => ({
  tooltip: {
    trigger: 'axis',
    backgroundColor: isDark.value ? '#161830' : '#fff',
    borderColor: isDark.value ? '#2a2d4a' : '#e8e9f2',
    textStyle: { color: isDark.value ? '#e8eaf6' : '#1a1d2e' },
    valueFormatter: (v: any) => `${v} 台`
  },
  grid: { left: 50, right: 24, top: 30, bottom: 40 },
  xAxis: {
    type: 'category',
    data: onlineRange.value === '7' ? onlineDates7 : onlineDates30,
    axisLine: { lineStyle: { color: axisLineColor.value } },
    axisLabel: { color: textColor.value, fontSize: 12 },
    axisTick: { show: false }
  },
  yAxis: {
    type: 'value',
    axisLine: { show: false },
    axisTick: { show: false },
    axisLabel: { color: textColor.value, fontSize: 12 },
    splitLine: { lineStyle: { color: splitLineColor.value, type: 'dashed' } }
  },
  series: [
    {
      data: onlineRange.value === '7' ? onlineData7 : onlineData30,
      type: 'line',
      smooth: true,
      symbol: 'circle',
      symbolSize: 8,
      lineStyle: {
        width: 3,
        color: '#6d5cff'
      },
      itemStyle: {
        color: '#6d5cff',
        borderColor: '#fff',
        borderWidth: 2
      },
      areaStyle: {
        color: {
          type: 'linear',
          x: 0, y: 0, x2: 0, y2: 1,
          colorStops: [
            { offset: 0, color: 'rgba(109,92,255,0.35)' },
            { offset: 1, color: 'rgba(109,92,255,0.02)' }
          ]
        }
      }
    }
  ]
}))

// 今日推送量（柱状）
const todayPushOption = computed(() => {
  const hours = Array.from({ length: 24 }, (_, i) => `${i}:00`)
  const data = hours.map((_, i) => {
    // 模拟工作时段高峰
    const base = i >= 9 && i <= 22 ? 4000 : 800
    return Math.round(base + Math.random() * 3000)
  })
  return {
    tooltip: {
      trigger: 'axis',
      backgroundColor: isDark.value ? '#161830' : '#fff',
      borderColor: isDark.value ? '#2a2d4a' : '#e8e9f2',
      textStyle: { color: isDark.value ? '#e8eaf6' : '#1a1d2e' },
      axisPointer: { type: 'shadow' }
    },
    grid: { left: 50, right: 16, top: 20, bottom: 40 },
    xAxis: {
      type: 'category',
      data: hours,
      axisLine: { lineStyle: { color: axisLineColor.value } },
      axisLabel: { color: textColor.value, fontSize: 11, interval: 3 },
      axisTick: { show: false }
    },
    yAxis: {
      type: 'value',
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: { color: textColor.value, fontSize: 11 },
      splitLine: { lineStyle: { color: splitLineColor.value, type: 'dashed' } }
    },
    series: [
      {
        data,
        type: 'bar',
        barWidth: '60%',
        itemStyle: {
          borderRadius: [6, 6, 0, 0],
          color: {
            type: 'linear',
            x: 0, y: 0, x2: 0, y2: 1,
            colorStops: [
              { offset: 0, color: '#9b5cff' },
              { offset: 1, color: '#5cb8ff' }
            ]
          }
        }
      }
    ]
  }
})

// Key 状态分布（环形）
const keyDistOption = computed(() => ({
  tooltip: {
    trigger: 'item',
    backgroundColor: isDark.value ? '#161830' : '#fff',
    borderColor: isDark.value ? '#2a2d4a' : '#e8e9f2',
    textStyle: { color: isDark.value ? '#e8eaf6' : '#1a1d2e' }
  },
  legend: {
    bottom: 8,
    icon: 'circle',
    textStyle: { color: textColor.value, fontSize: 12 }
  },
  series: [
    {
      type: 'pie',
      radius: ['48%', '72%'],
      center: ['50%', '44%'],
      avoidLabelOverlap: false,
      itemStyle: {
        borderRadius: 8,
        borderColor: isDark.value ? '#161830' : '#fff',
        borderWidth: 3
      },
      label: { show: false },
      emphasis: {
        scale: true,
        scaleSize: 6,
        label: {
          show: true,
          fontSize: 16,
          fontWeight: 'bold',
          color: isDark.value ? '#e8eaf6' : '#1a1d2e'
        }
      },
      data: [
        { value: 356, name: '活跃', itemStyle: { color: '#6d5cff' } },
        { value: 82, name: '闲置', itemStyle: { color: '#5cb8ff' } },
        { value: 24, name: '已禁用', itemStyle: { color: '#ffb547' } },
        { value: 8, name: '已过期', itemStyle: { color: '#ff5a6e' } }
      ]
    }
  ]
}))

// 设备平台分布（玫瑰图）
const platformOption = computed(() => ({
  tooltip: {
    trigger: 'item',
    backgroundColor: isDark.value ? '#161830' : '#fff',
    borderColor: isDark.value ? '#2a2d4a' : '#e8e9f2',
    textStyle: { color: isDark.value ? '#e8eaf6' : '#1a1d2e' }
  },
  legend: {
    bottom: 8,
    icon: 'circle',
    textStyle: { color: textColor.value, fontSize: 12 }
  },
  series: [
    {
      type: 'pie',
      radius: ['30%', '70%'],
      center: ['50%', '44%'],
      roseType: 'radius',
      itemStyle: { borderRadius: 8 },
      label: { color: textColor.value, fontSize: 11 },
      labelLine: { lineStyle: { color: axisLineColor.value } },
      data: [
        { value: 6420, name: 'Android', itemStyle: { color: '#6d5cff' } },
        { value: 3850, name: 'iOS', itemStyle: { color: '#5cb8ff' } },
        { value: 1820, name: 'Web', itemStyle: { color: '#18c29c' } },
        { value: 770, name: 'HarmonyOS', itemStyle: { color: '#ffb547' } }
      ]
    }
  ]
}))

// 最新推送记录
const recentPush = shallowRef([
  {
    title: '【系统通知】v2.5.0 版本更新公告',
    platform: 'Android',
    successCount: 6120,
    failCount: 80,
    status: 'success',
    createdAt: '2026-07-12 14:23:08'
  },
  {
    title: '限时活动：夏季大促倒计时 3 天',
    platform: 'iOS',
    successCount: 3840,
    failCount: 12,
    status: 'success',
    createdAt: '2026-07-12 13:50:42'
  },
  {
    title: '安全提醒：检测到新设备登录',
    platform: 'Web',
    successCount: 1820,
    failCount: 0,
    status: 'success',
    createdAt: '2026-07-12 11:18:30'
  },
  {
    title: '运营推广：新功能邀您体验',
    platform: 'HarmonyOS',
    successCount: 540,
    failCount: 230,
    status: 'partial',
    createdAt: '2026-07-12 10:02:15'
  },
  {
    title: '紧急维护通知：今晚 23:00 停机升级',
    platform: 'Android',
    successCount: 0,
    failCount: 0,
    status: 'sending',
    createdAt: '2026-07-12 09:30:00'
  }
])

// 工具函数
function formatNum(n: number): string {
  return n.toLocaleString('zh-CN')
}

function platformTag(p: string): any {
  const map: Record<string, string> = {
    Android: 'primary',
    iOS: 'success',
    Web: 'info',
    HarmonyOS: 'warning'
  }
  return map[p] || ''
}

function statusTag(s: string): any {
  const map: Record<string, string> = {
    success: 'success',
    partial: 'warning',
    failed: 'danger',
    sending: 'primary',
    pending: 'info'
  }
  return map[s] || 'info'
}

function statusLabel(s: string): string {
  const map: Record<string, string> = {
    success: '成功',
    partial: '部分成功',
    failed: '失败',
    sending: '发送中',
    pending: '等待中'
  }
  return map[s] || s
}

function goPush() {
  router.push('/push-logs')
}

async function refreshAll() {
  refreshing.value = true
  // 模拟刷新
  await new Promise((r) => setTimeout(r, 800))
  statCards.value = statCards.value.map((c) => ({
    ...c,
    value: c.value + Math.round(Math.random() * 200)
  }))
  refreshing.value = false
}

onMounted(() => {
  // 数据初始化已完成
})
</script>

<style lang="scss" scoped>
.dashboard {
  animation: fade-up 0.5s ease;
}

.dashboard-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 24px;
  flex-wrap: wrap;
  gap: 16px;

  .welcome-title {
    font-size: 26px;
    font-weight: 800;
    color: var(--text-primary);
    letter-spacing: -0.3px;
  }
  .welcome-sub {
    margin-top: 6px;
    font-size: 14px;
    color: var(--text-secondary);
  }
  .header-actions {
    display: flex;
    gap: 10px;
  }
}

// 数据卡片
.stat-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 18px;
  margin-bottom: 18px;
}

.stat-card {
  background: var(--bg-card);
  border-radius: 16px;
  padding: 22px;
  display: flex;
  align-items: center;
  gap: 16px;
  box-shadow: $shadow-md;
  border: 1px solid var(--border-light);
  position: relative;
  overflow: hidden;
  animation: fade-up 0.6s ease backwards;
  transition: transform 0.3s ease, box-shadow 0.3s ease;

  &:hover {
    transform: translateY(-4px);
    box-shadow: $shadow-lg;
  }

  &::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 120px;
    height: 120px;
    border-radius: 50%;
    transform: translate(40px, -40px);
    opacity: 0.08;
    transition: transform 0.4s ease;
  }

  &.stat-0::before { background: $color-primary; }
  &.stat-1::before { background: $color-info; }
  &.stat-2::before { background: $color-success; }
  &.stat-3::before { background: $color-warning; }

  &:hover::before {
    transform: translate(30px, -30px) scale(1.2);
  }

  .stat-content {
    flex: 1;
    min-width: 0;
  }

  .stat-label {
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 8px;
  }

  .stat-value {
    display: flex;
    align-items: baseline;
    gap: 4px;
    margin-bottom: 8px;

    .num {
      font-size: 28px;
      font-weight: 800;
      color: var(--text-primary);
      font-family: $font-family-mono;
      line-height: 1;
    }
    .unit {
      font-size: 13px;
      color: var(--text-secondary);
    }
  }

  .stat-trend {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;

    .el-icon {
      font-size: 12px;
    }

    .up { color: $color-success; }
    .down { color: $color-danger; }
    .trend-label {
      color: var(--text-secondary);
      margin-left: 4px;
    }
  }
}

// 图表区
.chart-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 18px;
  margin-bottom: 18px;
}

.chart-grid-2 {
  grid-template-columns: 1fr 1fr;
}

.chart-card {
  background: var(--bg-card);
  border-radius: 16px;
  padding: 20px 22px;
  box-shadow: $shadow-md;
  border: 1px solid var(--border-light);
}

.chart-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 16px;

  .chart-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
  }
  .chart-sub {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 4px;
  }
}

.chart-body {
  height: 280px;
  width: 100%;
}

.recent-card {
  background: var(--bg-card);
  border-radius: 16px;
  padding: 20px 22px;
  box-shadow: $shadow-md;
  border: 1px solid var(--border-light);

  .success-text {
    color: $color-success;
    font-weight: 600;
  }
  .muted {
    color: var(--text-secondary);
  }
}

@keyframes fade-up {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (max-width: 1200px) {
  .stat-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .chart-grid,
  .chart-grid-2 {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 600px) {
  .stat-grid {
    grid-template-columns: 1fr;
  }
}
</style>
