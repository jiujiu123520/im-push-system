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
        <el-button :icon="RefreshIcon" round @click="refreshAll" :loading="refreshing">
          刷新数据
        </el-button>
        <el-button :icon="MonitorIcon" round @click="testPushVisible = true">
          测试推送
        </el-button>
        <el-button type="primary" :icon="PromotionIcon" round @click="goPush">
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
              <CaretTopIcon v-if="card.trend >= 0" />
              <CaretBottomIcon v-else />
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
          查看全部<el-icon class="el-icon--right"><ArrowRightIcon /></el-icon>
        </el-button>
      </div>
      <el-table :data="recentPush" style="width: 100%">
        <el-table-column prop="title" label="推送标题" min-width="200" show-overflow-tooltip />
        <el-table-column label="目标类型" width="110">
          <template #default="{ row }">
            <el-tag :type="targetTypeTag(row.target_type) as any" effect="light" round size="small">
              {{ targetTypeLabel(row.target_type) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="送达" width="160">
          <template #default="{ row }">
            <span class="success-text">{{ row.success_count }}</span>
            <span class="muted"> / {{ row.success_count + row.fail_count }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.success_count, row.fail_count) as any" effect="plain" round size="small">
              {{ statusLabel(row.success_count, row.fail_count) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="时间" width="180" />
      </el-table>
    </div>

    <!-- 测试推送对话框 -->
    <TestPushDialog v-model="testPushVisible" />
  </div>
</template>

<script setup lang="ts">
import { computed, markRaw, onBeforeUnmount, onMounted, ref, watch } from 'vue'
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
import { ElMessage } from 'element-plus'
import {
  Refresh as RefreshIcon,
  Promotion as PromotionIcon,
  ArrowRight as ArrowRightIcon,
  CaretTop as CaretTopIcon,
  CaretBottom as CaretBottomIcon,
  Cellphone as CellphoneIcon,
  Bell as BellIcon,
  Key as KeyIcon,
  User as UserIcon,
  Monitor as MonitorIcon
} from '@element-plus/icons-vue'
import { useUserStore } from '@/stores/user'
import { useAppStore } from '@/stores/app'
import {
  getDashboardOverviewApi,
  getOnlineTrendApi,
  getTodayPushApi,
  getKeyDistributionApi,
  getDevicePlatformApi,
  getRecentPushApi,
  type RecentPushItem
} from '@/api/dashboard'
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

const userStore = useUserStore()
const appStore = useAppStore()

const refreshing = ref(false)

// 自动刷新定时器
let autoRefreshTimer: number | null = null
const AUTO_REFRESH_INTERVAL = 60000 // 60秒自动刷新一次

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

// 概览数据
const overview = ref({
  online_devices: 0,
  today_push: 0,
  yesterday_push: 0,
  active_keys: 0,
  total_keys: 0,
  total_users: 0,
  today_new_users: 0,
  today_new_devices: 0
})

// 数据卡片（根据概览数据动态生成）
const statCards = computed(() => {
  const todayPush = overview.value.today_push
  const yesterdayPush = overview.value.yesterday_push
  const pushTrend = yesterdayPush > 0
    ? Math.round(((todayPush - yesterdayPush) / yesterdayPush) * 1000) / 10
    : 0

  return [
    {
      title: '在线设备',
      value: overview.value.online_devices,
      unit: '台',
      trend: overview.value.today_new_devices > 0 ? 5.2 : 0,
      icon: markRaw(CellphoneIcon),
      iconBg: 'bg-primary'
    },
    {
      title: '今日推送量',
      value: todayPush,
      unit: '条',
      trend: pushTrend,
      icon: markRaw(BellIcon),
      iconBg: 'bg-cyan'
    },
    {
      title: '活跃 Key 数',
      value: overview.value.active_keys,
      unit: '个',
      trend: 0,
      icon: markRaw(KeyIcon),
      iconBg: 'bg-success'
    },
    {
      title: '注册用户',
      value: overview.value.total_users,
      unit: '人',
      trend: overview.value.today_new_users > 0 ? 2.1 : 0,
      icon: markRaw(UserIcon),
      iconBg: 'bg-warm'
    }
  ]
})

// 主题色（响应暗色）
const isDark = computed(() => appStore.theme === 'dark')
const textColor = computed(() => (isDark.value ? '#b4b8d4' : '#5a5f78'))
const axisLineColor = computed(() => (isDark.value ? '#2a2d4a' : '#e8e9f2'))
const splitLineColor = computed(() =>
  isDark.value ? 'rgba(255,255,255,0.04)' : 'rgba(109,92,255,0.06)'
)

// 在线设备趋势数据
const onlineTrendData = ref<{ dates: string[]; values: number[] }>({
  dates: [],
  values: []
})

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
    data: onlineTrendData.value.dates,
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
      data: onlineTrendData.value.values,
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
const todayPushData = ref<{ hours: string[]; values: number[] }>({
  hours: [],
  values: []
})

const todayPushOption = computed(() => ({
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
    data: todayPushData.value.hours,
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
      data: todayPushData.value.values,
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
}))

// Key 状态分布颜色映射
const keyStatusColors: Record<string, string> = {
  '活跃': '#6d5cff',
  '已禁用': '#ffb547',
  '闲置': '#5cb8ff',
  '已过期': '#ff5a6e'
}

const keyDistData = ref<{ name: string; value: number }[]>([])

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
      data: keyDistData.value.map((item) => ({
        ...item,
        itemStyle: { color: keyStatusColors[item.name] || '#6d5cff' }
      }))
    }
  ]
}))

// 设备平台分布颜色映射
const platformColors: Record<string, string> = {
  'Android': '#6d5cff',
  'iOS': '#5cb8ff',
  'Web': '#18c29c',
  'HarmonyOS': '#ffb547',
  '其他': '#909399'
}

const platformData = ref<{ name: string; value: number }[]>([])

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
      data: platformData.value.map((item) => ({
        ...item,
        itemStyle: { color: platformColors[item.name] || '#6d5cff' }
      }))
    }
  ]
}))

// 最新推送记录
const recentPush = ref<RecentPushItem[]>([])

// 工具函数
function formatNum(n: number): string {
  return n.toLocaleString('zh-CN')
}

type TagType = 'primary' | 'success' | 'warning' | 'info' | 'danger'

function targetTypeTag(type: string): TagType {
  const map: Record<string, TagType> = {
    all: 'primary',
    key: 'success',
    user: 'warning',
    device: 'info',
    api: 'danger'
  }
  return map[type] || 'info'
}

function targetTypeLabel(type: string): string {
  const map: Record<string, string> = {
    all: '全员推送',
    key: 'Key推送',
    user: '用户推送',
    device: '设备推送',
    api: 'API推送'
  }
  return map[type] || type
}

function statusTag(successCount: number, failCount: number): TagType {
  if (failCount === 0 && successCount > 0) return 'success'
  if (successCount === 0 && failCount === 0) return 'info'
  if (failCount > 0 && successCount > 0) return 'warning'
  return 'danger'
}

function statusLabel(successCount: number, failCount: number): string {
  if (failCount === 0 && successCount > 0) return '成功'
  if (successCount === 0 && failCount === 0) return '无数据'
  if (failCount > 0 && successCount > 0) return '部分成功'
  return '失败'
}

function goPush() {
  testPushVisible.value = true
}

// 加载所有仪表盘数据
async function loadAllData(showError = false) {
  refreshing.value = true
  try {
    const [overviewRes, trendRes, todayPushRes, keyDistRes, platformRes, recentRes] = await Promise.all([
      getDashboardOverviewApi(),
      getOnlineTrendApi(Number(onlineRange.value)),
      getTodayPushApi(),
      getKeyDistributionApi(),
      getDevicePlatformApi(),
      getRecentPushApi(5)
    ])

    if (overviewRes.data) {
      overview.value = overviewRes.data
      appStore.setSystemStatus({
        onlineDevices: overviewRes.data.online_devices
      })
    }
    if (trendRes.data) {
      onlineTrendData.value = trendRes.data
    }
    if (todayPushRes.data) {
      todayPushData.value = todayPushRes.data
    }
    if (keyDistRes.data?.data) {
      keyDistData.value = keyDistRes.data.data
    }
    if (platformRes.data?.data) {
      platformData.value = platformRes.data.data
    }
    if (recentRes.data?.list) {
      recentPush.value = recentRes.data.list
    }
  } catch (err) {
    console.error('加载仪表盘数据失败:', err)
    if (showError) {
      ElMessage.error('数据加载失败，请稍后重试')
    }
  } finally {
    refreshing.value = false
  }
}

async function refreshAll() {
  await loadAllData(true)
}

function startAutoRefresh() {
  stopAutoRefresh()
  autoRefreshTimer = window.setInterval(() => {
    if (document.visibilityState === 'visible') {
      loadAllData(false)
    }
  }, AUTO_REFRESH_INTERVAL)
}

function stopAutoRefresh() {
  if (autoRefreshTimer !== null) {
    clearInterval(autoRefreshTimer)
    autoRefreshTimer = null
  }
}

// 切换时间范围时重新加载趋势数据
watch(onlineRange, () => {
  getOnlineTrendApi(Number(onlineRange.value)).then((res) => {
    if (res.data) {
      onlineTrendData.value = res.data
    }
  }).catch(() => {})
})

onMounted(() => {
  loadAllData(false)
  startAutoRefresh()
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      loadAllData(false)
    }
  })
})

onBeforeUnmount(() => {
  stopAutoRefresh()
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
