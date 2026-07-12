<template>
  <div class="page-container module-page">
    <!-- 页头 -->
    <div class="page-header">
      <div class="page-title">{{ moduleTitle }}</div>
      <div class="header-actions">
        <!-- 推送记录模块的导出按钮 -->
        <el-dropdown
          v-if="currentModule === 'push-logs'"
          @command="handleExport"
          style="margin-right: 12px"
        >
          <el-button :icon="Download" :loading="exporting">
            导出
            <el-icon class="el-icon--right"><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="csv">导出 CSV</el-dropdown-item>
              <el-dropdown-item command="json">导出 JSON</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
        <el-button type="primary" :icon="Plus" @click="openDialog()">
          新增{{ moduleTitle }}
        </el-button>
      </div>
    </div>

    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-input
        v-model="query.keyword"
        placeholder="搜索关键词"
        :prefix-icon="Search"
        clearable
        style="width: 220px"
        @keyup.enter="handleSearch"
      />
      <el-select v-model="query.status" placeholder="状态筛选" clearable style="width: 160px">
        <el-option label="启用" :value="1" />
        <el-option label="禁用" :value="0" />
      </el-select>
      <el-button type="primary" :icon="Search" @click="handleSearch">查询</el-button>
      <el-button :icon="RefreshLeft" @click="handleReset">重置</el-button>
    </div>

    <!-- 表格 -->
    <div class="table-container">
      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        style="width: 100%"
        row-key="id"
      >
        <el-table-column type="index" label="#" width="60" align="center" />
        <el-table-column
          v-for="col in columns"
          :key="col.prop"
          :prop="col.prop"
          :label="col.label"
          :min-width="col.width || 140"
          show-overflow-tooltip
        >
          <template v-if="col.slot === 'status'" #default="{ row }">
            <el-tag :type="row[col.prop] === 1 ? 'success' : 'info'" effect="light" round size="small">
              {{ row[col.prop] === 1 ? '启用' : '禁用' }}
            </el-tag>
          </template>
          <template v-else-if="col.slot === 'tag'" #default="{ row }">
            <el-tag
              v-for="t in (row[col.prop] || [])"
              :key="t"
              effect="plain"
              round
              size="small"
              style="margin-right: 4px"
            >{{ t }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <el-button text type="primary" :icon="Edit" @click="openDialog(row)">编辑</el-button>
            <el-button text type="danger" :icon="Delete" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.pageSize"
          :page-sizes="[10, 20, 50]"
          :total="total"
          layout="total, sizes, prev, pager, next, jumper"
          background
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </div>

    <!-- 新增/编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="560px"
      destroy-on-close
    >
      <el-form
        ref="dialogFormRef"
        :model="dialogForm"
        :rules="dialogRules"
        label-width="100px"
      >
        <el-form-item
          v-for="field in formFields"
          :key="field.prop"
          :label="field.label"
          :prop="field.prop"
        >
          <el-input
            v-if="field.type === 'input'"
            v-model="dialogForm[field.prop]"
            :placeholder="`请输入${field.label}`"
          />
          <el-input
            v-else-if="field.type === 'textarea'"
            v-model="dialogForm[field.prop]"
            type="textarea"
            :rows="3"
            :placeholder="`请输入${field.label}`"
          />
          <el-input-number
            v-else-if="field.type === 'number'"
            v-model="dialogForm[field.prop]"
            :min="0"
            controls-position="right"
            style="width: 100%"
          />
          <el-select
            v-else-if="field.type === 'select'"
            v-model="dialogForm[field.prop]"
            :placeholder="`请选择${field.label}`"
            style="width: 100%"
          >
            <el-option
              v-for="opt in field.options"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
          <el-switch
            v-else-if="field.type === 'switch'"
            v-model="dialogForm[field.prop]"
            :active-value="1"
            :inactive-value="0"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  Plus,
  Search,
  RefreshLeft,
  Edit,
  Delete,
  Download,
  ArrowDown
} from '@element-plus/icons-vue'
import { exportPushLogsApi } from '@/api/push'
import { getKeyListApi, createKeyApi, updateKeyApi, deleteKeyApi } from '@/api/key'

interface FieldConfig {
  prop: string
  label: string
  type: 'input' | 'textarea' | 'number' | 'select' | 'switch'
  options?: { label: string; value: any }[]
  required?: boolean
  placeholder?: string
}

interface ColumnConfig {
  prop: string
  label: string
  width?: number
  slot?: 'status' | 'tag'
}

// 各模块配置
const moduleConfigs: Record<string, {
  title: string
  columns: ColumnConfig[]
  fields: FieldConfig[]
  mockRow: () => Record<string, any>
}> = {
  users: {
    title: '用户',
    columns: [
      { prop: 'userId', label: '用户ID', width: 120 },
      { prop: 'username', label: '用户名', width: 140 },
      { prop: 'nickname', label: '昵称' },
      { prop: 'phone', label: '手机号', width: 140 },
      { prop: 'deviceCount', label: '设备数', width: 90 },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'createdAt', label: '注册时间', width: 170 }
    ],
    fields: [
      { prop: 'username', label: '用户名', type: 'input', required: true },
      { prop: 'nickname', label: '昵称', type: 'input' },
      { prop: 'phone', label: '手机号', type: 'input' },
      { prop: 'status', label: '状态', type: 'switch' }
    ],
    mockRow: () => ({
      id: 0,
      userId: 'U' + Math.floor(Math.random() * 900000 + 100000),
      username: 'user_' + Math.floor(Math.random() * 9999),
      nickname: '用户' + Math.floor(Math.random() * 999),
      phone: '138' + String(Math.floor(Math.random() * 100000000)).padStart(8, '0'),
      deviceCount: Math.floor(Math.random() * 20),
      status: Math.random() > 0.2 ? 1 : 0,
      createdAt: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  keys: {
    title: 'Key',
    columns: [
      { prop: 'keyValue', label: 'AppKey', width: 180 },
      { prop: 'title', label: '名称' },
      { prop: 'platform', label: '平台', width: 100 },
      { prop: 'dailyLimit', label: '日限额', width: 100 },
      { prop: 'notifyEnabled', label: '掉线通知', width: 100, slot: 'status' },
      { prop: 'notifyEmail', label: '通知邮箱', width: 180 },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'createdAt', label: '创建时间', width: 170 }
    ],
    fields: [
      { prop: 'title', label: '名称', type: 'input', required: true },
      { prop: 'platform', label: '平台', type: 'select', required: true, options: [
        { label: '全平台', value: 'all' },
        { label: 'Android', value: 'android' },
        { label: 'iOS', value: 'ios' }
      ] },
      { prop: 'dailyLimit', label: '日限额', type: 'number' },
      { prop: 'status', label: '状态', type: 'switch' },
      { prop: 'notifyEnabled', label: '启用掉线通知', type: 'switch' },
      { prop: 'notifyEmail', label: '通知邮箱', type: 'input', placeholder: '多个邮箱用逗号分隔，支持QQ邮箱' },
      { prop: 'notifyInterval', label: '通知间隔(秒)', type: 'number' }
    ],
    mockRow: () => ({
      id: 0,
      keyValue: 'AK' + Math.floor(Math.random() * 9e15 + 1e15).toString(16),
      title: '应用Key ' + Math.floor(Math.random() * 99),
      platform: ['all', 'android', 'ios'][Math.floor(Math.random() * 3)],
      dailyLimit: 0,
      notifyEnabled: Math.random() > 0.5 ? 1 : 0,
      notifyEmail: Math.random() > 0.5 ? 'admin@example.com' : '',
      notifyInterval: 300,
      status: Math.random() > 0.15 ? 1 : 0,
      createdAt: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  devices: {
    title: '设备',
    columns: [
      { prop: 'deviceId', label: '设备ID', width: 180 },
      { prop: 'platform', label: '平台', width: 100 },
      { prop: 'model', label: '型号' },
      { prop: 'appVersion', label: 'App版本', width: 100 },
      { prop: 'online', label: '在线', width: 80, slot: 'status' },
      { prop: 'lastActiveAt', label: '最后活跃', width: 170 }
    ],
    fields: [
      { prop: 'deviceId', label: '设备ID', type: 'input', required: true },
      { prop: 'platform', label: '平台', type: 'select', required: true, options: [
        { label: 'Android', value: 'android' },
        { label: 'iOS', value: 'ios' },
        { label: 'Web', value: 'web' },
        { label: 'HarmonyOS', value: 'harmony' }
      ] },
      { prop: 'model', label: '型号', type: 'input' }
    ],
    mockRow: () => ({
      id: 0,
      deviceId: 'D' + Math.floor(Math.random() * 9e15 + 1e15).toString(16),
      platform: ['android', 'ios', 'web', 'harmony'][Math.floor(Math.random() * 4)],
      model: ['iPhone 15', 'Xiaomi 14', 'HUAWEI Mate60', 'Pixel 8'][Math.floor(Math.random() * 4)],
      appVersion: '2.' + Math.floor(Math.random() * 9) + '.' + Math.floor(Math.random() * 9),
      online: Math.random() > 0.4 ? 1 : 0,
      lastActiveAt: '2026-07-12 ' + String(Math.floor(Math.random() * 24)).padStart(2, '0') + ':' + String(Math.floor(Math.random() * 60)).padStart(2, '0')
    })
  },
  'push-logs': {
    title: '推送记录',
    columns: [
      { prop: 'messageId', label: '消息ID', width: 180 },
      { prop: 'title', label: '推送标题' },
      { prop: 'platform', label: '平台', width: 100 },
      { prop: 'successCount', label: '成功', width: 90 },
      { prop: 'failCount', label: '失败', width: 90 },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'createdAt', label: '时间', width: 170 }
    ],
    fields: [
      { prop: 'title', label: '标题', type: 'input', required: true },
      { prop: 'content', label: '内容', type: 'textarea', required: true },
      { prop: 'platform', label: '平台', type: 'select', required: true, options: [
        { label: '全平台', value: 'all' },
        { label: 'Android', value: 'android' }
      ] }
    ],
    mockRow: () => ({
      id: 0,
      messageId: 'M' + Math.floor(Math.random() * 9e15 + 1e15).toString(16),
      title: '推送消息 ' + Math.floor(Math.random() * 999),
      platform: ['all', 'android', 'ios'][Math.floor(Math.random() * 3)],
      successCount: Math.floor(Math.random() * 8000),
      failCount: Math.floor(Math.random() * 200),
      status: Math.random() > 0.2 ? 1 : 0,
      createdAt: '2026-07-12 ' + String(Math.floor(Math.random() * 24)).padStart(2, '0') + ':' + String(Math.floor(Math.random() * 60)).padStart(2, '0')
    })
  },
  blacklist: {
    title: '黑名单',
    columns: [
      { prop: 'type', label: '类型', width: 100 },
      { prop: 'value', label: '值' },
      { prop: 'reason', label: '原因' },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'createdAt', label: '创建时间', width: 170 }
    ],
    fields: [
      { prop: 'type', label: '类型', type: 'select', required: true, options: [
        { label: '用户', value: 'user' },
        { label: '设备', value: 'device' },
        { label: 'IP', value: 'ip' }
      ] },
      { prop: 'value', label: '值', type: 'input', required: true },
      { prop: 'reason', label: '原因', type: 'textarea', required: true }
    ],
    mockRow: () => ({
      id: 0,
      type: ['user', 'device', 'ip'][Math.floor(Math.random() * 3)],
      value: 'block_' + Math.floor(Math.random() * 99999),
      reason: ['违规操作', '异常请求', '安全风险'][Math.floor(Math.random() * 3)],
      status: 1,
      createdAt: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  admins: {
    title: '管理员',
    columns: [
      { prop: 'username', label: '账号', width: 140 },
      { prop: 'nickname', label: '昵称' },
      { prop: 'email', label: '邮箱', width: 200 },
      { prop: 'roles', label: '角色', width: 160, slot: 'tag' },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'lastLoginAt', label: '最后登录', width: 170 }
    ],
    fields: [
      { prop: 'username', label: '账号', type: 'input', required: true },
      { prop: 'nickname', label: '昵称', type: 'input', required: true },
      { prop: 'email', label: '邮箱', type: 'input' },
      { prop: 'status', label: '状态', type: 'switch' }
    ],
    mockRow: () => ({
      id: 0,
      username: 'admin_' + Math.floor(Math.random() * 99),
      nickname: '管理员' + Math.floor(Math.random() * 99),
      email: 'admin' + Math.floor(Math.random() * 999) + '@push.com',
      roles: ['super_admin', 'admin'].slice(0, Math.floor(Math.random() * 2) + 1),
      status: 1,
      lastLoginAt: '2026-07-12 ' + String(Math.floor(Math.random() * 24)).padStart(2, '0') + ':00'
    })
  },
  'app-build': {
    title: 'APP打包',
    columns: [
      { prop: 'buildId', label: '构建ID', width: 160 },
      { prop: 'name', label: '应用名称' },
      { prop: 'platform', label: '平台', width: 90 },
      { prop: 'version', label: '版本', width: 100 },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'createdAt', label: '创建时间', width: 170 }
    ],
    fields: [
      { prop: 'name', label: '应用名', type: 'input', required: true },
      { prop: 'platform', label: '平台', type: 'select', required: true, options: [
        { label: 'Android', value: 'android' },
        { label: 'iOS', value: 'ios' }
      ] },
      { prop: 'version', label: '版本号', type: 'input', required: true }
    ],
    mockRow: () => ({
      id: 0,
      buildId: 'B' + Math.floor(Math.random() * 90000 + 10000),
      name: 'PushApp',
      platform: Math.random() > 0.5 ? 'android' : 'ios',
      version: '2.' + Math.floor(Math.random() * 9) + '.' + Math.floor(Math.random() * 9),
      status: Math.random() > 0.3 ? 1 : 0,
      createdAt: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  'api-keys': {
    title: 'API Key',
    columns: [
      { prop: 'name', label: '名称' },
      { prop: 'accessKey', label: 'AccessKey', width: 220 },
      { prop: 'rateLimit', label: '限流/分', width: 100 },
      { prop: 'permissions', label: '权限', width: 200, slot: 'tag' },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'createdAt', label: '创建时间', width: 170 }
    ],
    fields: [
      { prop: 'name', label: '名称', type: 'input', required: true },
      { prop: 'rateLimit', label: '限流(/分)', type: 'number' },
      { prop: 'status', label: '状态', type: 'switch' }
    ],
    mockRow: () => ({
      id: 0,
      name: 'API客户端 ' + Math.floor(Math.random() * 99),
      accessKey: 'AK' + Math.floor(Math.random() * 9e15 + 1e15).toString(16),
      rateLimit: [60, 100, 600, 1000][Math.floor(Math.random() * 4)],
      permissions: ['push:send', 'device:query', 'user:read'].slice(0, Math.floor(Math.random() * 3) + 1),
      status: 1,
      createdAt: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  settings: {
    title: '系统设置',
    columns: [
      { prop: 'siteName', label: '站点名称' },
      { prop: 'siteDescription', label: '站点描述' },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'createdAt', label: '更新时间', width: 170 }
    ],
    fields: [
      { prop: 'siteName', label: '站点名称', type: 'input', required: true },
      { prop: 'siteDescription', label: '站点描述', type: 'textarea' }
    ],
    mockRow: () => ({
      id: 1,
      siteName: 'Push 推送平台',
      siteDescription: '即时消息推送管理系统',
      status: 1,
      createdAt: '2026-07-01 10:00:00'
    })
  }
}

const route = useRoute()
const loading = ref(false)
const submitting = ref(false)
const tableData = ref<Record<string, any>[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pageSize: 10,
  keyword: '',
  status: undefined as number | undefined
})

const currentModule = computed(() => (route.meta.module as string) || 'users')
const config = computed(() => moduleConfigs[currentModule.value] || moduleConfigs.users)
const moduleTitle = computed(() => config.value.title)
const columns = computed(() => config.value.columns)
const formFields = computed(() => config.value.fields)

// 弹窗
const dialogVisible = ref(false)
const dialogFormRef = ref<FormInstance>()
const dialogForm = reactive<Record<string, any>>({})
const isEdit = ref(false)

const dialogTitle = computed(() => `${isEdit.value ? '编辑' : '新增'}${moduleTitle.value}`)

const dialogRules = computed<FormRules>(() => {
  const rules: FormRules = {}
  formFields.value.forEach((f) => {
    if (f.required) {
      rules[f.prop] = [{ required: true, message: `请输入${f.label}`, trigger: 'blur' }]
    }
    if (f.prop === 'notifyEmail') {
      rules[f.prop] = [
        {
          validator: (rule, value, callback) => {
            if (!value) {
              callback()
              return
            }
            const emails = value.split(',').map((e: string) => e.trim()).filter((e: string) => e)
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
            for (const email of emails) {
              if (!emailRegex.test(email)) {
                callback(new Error(`邮箱格式不正确: ${email}`))
                return
              }
            }
            callback()
          },
          trigger: 'blur'
        }
      ]
    }
  })
  return rules
})

// 生成模拟数据
function generateMockData(): Record<string, any>[] {
  const list: Record<string, any>[] = []
  const count = 38
  for (let i = 0; i < count; i++) {
    const row = config.value.mockRow()
    row.id = i + 1
    list.push(row)
  }
  return list
}

let allData: Record<string, any>[] = []

async function fetchData() {
  loading.value = true
  try {
    if (currentModule.value === 'keys') {
      const res = await getKeyListApi(query)
      tableData.value = res.list || []
      total.value = res.total || 0
    } else {
      await new Promise((r) => setTimeout(r, 300))
      let list = [...allData]
      if (query.keyword) {
        list = list.filter((item) =>
          JSON.stringify(item).toLowerCase().includes(query.keyword.toLowerCase())
        )
      }
      if (query.status !== undefined) {
        list = list.filter((item) => item.status === query.status)
      }
      total.value = list.length
      const start = (query.page - 1) * query.pageSize
      tableData.value = list.slice(start, start + query.pageSize)
    }
  } catch (error) {
    ElMessage.error('获取数据失败')
  }
  loading.value = false
}

function handleSearch() {
  query.page = 1
  fetchData()
}

function handleReset() {
  query.keyword = ''
  query.status = undefined
  query.page = 1
  fetchData()
}

function openDialog(row?: Record<string, any>) {
  isEdit.value = !!row
  // 重置表单
  Object.keys(dialogForm).forEach((k) => delete dialogForm[k])
  if (row) {
    Object.assign(dialogForm, JSON.parse(JSON.stringify(row)))
  } else {
    formFields.value.forEach((f) => {
      dialogForm[f.prop] = f.type === 'switch' ? 1 : f.type === 'number' ? 0 : ''
    })
  }
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!dialogFormRef.value) return
  try {
    await dialogFormRef.value.validate()
  } catch {
    return
  }
  submitting.value = true
  try {
    if (currentModule.value === 'keys') {
      if (isEdit.value) {
        await updateKeyApi(dialogForm.id, dialogForm)
        ElMessage.success('更新成功')
      } else {
        await createKeyApi(dialogForm)
        ElMessage.success('新增成功')
      }
    } else {
      await new Promise((r) => setTimeout(r, 400))
      if (isEdit.value) {
        const idx = allData.findIndex((item) => item.id === dialogForm.id)
        if (idx > -1) {
          allData[idx] = { ...dialogForm }
        }
        ElMessage.success('更新成功')
      } else {
        const newId = Math.max(0, ...allData.map((i) => i.id)) + 1
        allData.unshift({ ...dialogForm, id: newId })
        ElMessage.success('新增成功')
      }
    }
  } catch (error) {
    ElMessage.error('操作失败')
  }
  submitting.value = false
  dialogVisible.value = false
  fetchData()
}

async function handleDelete(row: Record<string, any>) {
  try {
    await ElMessageBox.confirm(`确定要删除该${moduleTitle.value}吗？`, '提示', {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning'
    })
    if (currentModule.value === 'keys') {
      await deleteKeyApi(row.id)
    } else {
      allData = allData.filter((item) => item.id !== row.id)
    }
    ElMessage.success('删除成功')
    fetchData()
  } catch {
    // 取消
  }
}

// 模块切换时重新加载数据
watch(
  () => currentModule.value,
  () => {
    allData = generateMockData()
    query.page = 1
    query.keyword = ''
    query.status = undefined
    fetchData()
  },
  { immediate: true }
)

// ========== 导出功能 ==========

const exporting = ref(false)

/** 处理导出命令 */
async function handleExport(format: string) {
  exporting.value = true
  try {
    const res = await exportPushLogsApi({
      format: format as 'csv' | 'json',
      keyword: query.keyword || undefined,
    })
    // 从响应头解析文件名
    const disposition = (res as any).headers?.['content-disposition'] || ''
    let filename = `push_logs_${Date.now()}.${format}`
    const match = disposition.match(/filename="?([^"]+)"?/)
    if (match) {
      filename = match[1]
    }
    // 创建下载链接
    const blob = new Blob([(res as any).data], {
      type: format === 'csv' ? 'text/csv;charset=utf-8' : 'application/json;charset=utf-8',
    })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    ElMessage.success('导出成功')
  } catch (err) {
    ElMessage.error('导出失败')
  } finally {
    exporting.value = false
  }
}
</script>

<style lang="scss" scoped>
.module-page {
  animation: fade-up 0.4s ease;
}

.header-actions {
  display: flex;
  align-items: center;
}

@keyframes fade-up {
  from {
    opacity: 0;
    transform: translateY(16px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
