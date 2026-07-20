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
          <el-button :icon="DownloadIcon" :loading="exporting">
            导出
            <el-icon class="el-icon--right"><ArrowDownIcon /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="csv">导出 CSV</el-dropdown-item>
              <el-dropdown-item command="json">导出 JSON</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
        <el-button
          v-if="currentModule === 'users' || currentModule === 'admins' || currentModule === 'keys'"
          type="danger"
          plain
          :icon="DeleteIcon"
          @click="handleClearAll"
        >
          一键清空
        </el-button>
        <el-button type="primary" :icon="PlusIcon" @click="openDialog()">
          新增{{ moduleTitle }}
        </el-button>
      </div>
    </div>

    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-input
        v-model="query.keyword"
        placeholder="搜索关键词"
        :prefix-icon="SearchIcon"
        clearable
        style="width: 220px"
        @keyup.enter="handleSearch"
      />
      <el-select v-model="query.status" placeholder="状态筛选" clearable style="width: 160px">
        <el-option label="启用" :value="1" />
        <el-option label="禁用" :value="0" />
      </el-select>
      <el-button type="primary" :icon="SearchIcon" @click="handleSearch">查询</el-button>
      <el-button :icon="RefreshLeftIcon" @click="handleReset">重置</el-button>
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
          <template v-else-if="col.prop === 'key_value'" #default="{ row }">
            <div style="display: flex; align-items: center; gap: 4px;">
              <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ row[col.prop] }}</span>
              <el-button text type="primary" size="small" @click="copyToClipboard(row[col.prop])">
                <el-icon><CopyDocumentIcon /></el-icon>
              </el-button>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="操作" :width="currentModule === 'users' ? 260 : 180" fixed="right">
          <template #default="{ row }">
            <el-button text type="primary" :icon="EditIcon" @click="openDialog(row)">编辑</el-button>
            <el-button v-if="currentModule === 'users'" text type="warning" :icon="KeyIcon" @click="openPasswordDialog(row)">修改密码</el-button>
            <el-button text type="danger" :icon="DeleteIcon" @click="handleDelete(row)">删除</el-button>
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
            :placeholder="field.placeholder || `请输入${field.label}`"
          />
          <el-input
            v-else-if="field.type === 'textarea'"
            v-model="dialogForm[field.prop]"
            type="textarea"
            :rows="3"
            :placeholder="field.placeholder || `请输入${field.label}`"
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
            :placeholder="field.placeholder || `请选择${field.label}`"
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
          <div v-if="field.tip" class="field-tip">{{ field.tip }}</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- 修改密码弹窗 -->
    <el-dialog
      v-model="passwordDialogVisible"
      title="修改密码"
      width="440px"
      destroy-on-close
    >
      <el-form
        ref="passwordFormRef"
        :model="passwordForm"
        :rules="passwordRules"
        label-width="100px"
      >
        <el-form-item label="新密码" prop="password">
          <el-input
            v-model="passwordForm.password"
            type="password"
            show-password
            placeholder="请输入新密码"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="passwordDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="resettingPassword" @click="handleResetPassword">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  Plus as PlusIcon,
  Search as SearchIcon,
  RefreshLeft as RefreshLeftIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Download as DownloadIcon,
  ArrowDown as ArrowDownIcon,
  CopyDocument as CopyDocumentIcon,
  Key as KeyIcon
} from '@element-plus/icons-vue'
import { exportPushLogsApi, getPushLogListApi, sendPushApi } from '@/api/push'
import { getKeyListApi, createKeyApi, updateKeyApi, deleteKeyApi } from '@/api/key'
import {
  getBlacklistApi,
  createBlacklistApi,
  deleteBlacklistApi
} from '@/api/blacklist'
import {
  getAdminListApi,
  createAdminApi,
  updateAdminApi,
  deleteAdminApi
} from '@/api/admin'
import { getDeviceListApi } from '@/api/device'
import { getUserListApi, createUserApi, updateUserApi, deleteUserApi, resetUserPasswordApi } from '@/api/user'
import type { KeyForm, BlacklistForm, AdminForm, UserForm } from '@/api/types'

interface FieldConfig {
  prop: string
  label: string
  type: 'input' | 'textarea' | 'number' | 'select' | 'switch'
  options?: { label: string; value: any }[]
  required?: boolean
  placeholder?: string
  tip?: string
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
      { prop: 'id', label: '用户ID', width: 120 },
      { prop: 'username', label: '用户名', width: 140 },
      { prop: 'email', label: '邮箱' },
      { prop: 'phone', label: '手机号', width: 140 },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'created_at', label: '注册时间', width: 170 }
    ],
    fields: [
      { prop: 'username', label: '用户名', type: 'input', required: true },
      { prop: 'phone', label: '手机号', type: 'input' },
      { prop: 'email', label: '邮箱', type: 'input' },
      { prop: 'status', label: '状态', type: 'switch' }
    ],
    mockRow: () => ({
      id: 0,
      username: 'user_' + Math.floor(Math.random() * 9999),
      email: 'user' + Math.floor(Math.random() * 9999) + '@example.com',
      phone: '138' + String(Math.floor(Math.random() * 100000000)).padStart(8, '0'),
      status: Math.random() > 0.2 ? 1 : 0,
      created_at: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  keys: {
    title: 'Key',
    columns: [
      { prop: 'key_value', label: 'AppKey', width: 180 },
      { prop: 'name', label: '名称' },
      { prop: 'max_devices', label: '最大设备数', width: 100 },
      { prop: 'notify_enabled', label: '掉线通知', width: 100, slot: 'status' },
      { prop: 'notify_email', label: '通知邮箱', width: 180 },
      { prop: 'notify_interval', label: '通知间隔', width: 100 },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'created_at', label: '创建时间', width: 170 }
    ],
    fields: [
      { prop: 'name', label: '名称', type: 'input', required: true },
      { prop: 'max_devices', label: '最大设备数', type: 'number' },
      { prop: 'status', label: '状态', type: 'switch' },
      { prop: 'notify_enabled', label: '启用掉线通知', type: 'switch' },
      { prop: 'notify_email', label: '通知邮箱', type: 'input', placeholder: '多个邮箱用逗号分隔，支持QQ邮箱' },
      { prop: 'notify_interval', label: '通知间隔(秒)', type: 'number' }
    ],
    mockRow: () => ({
      id: 0,
      key_value: 'AK' + Math.floor(Math.random() * 9e15 + 1e15).toString(16),
      name: '应用Key ' + Math.floor(Math.random() * 99),
      max_devices: 0,
      notify_enabled: Math.random() > 0.5 ? 1 : 0,
      notify_email: Math.random() > 0.5 ? 'admin@example.com' : '',
      notify_interval: 300,
      status: Math.random() > 0.15 ? 1 : 0,
      created_at: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  devices: {
    title: '设备',
    columns: [
      { prop: 'device_id', label: '设备ID', width: 180 },
      { prop: 'platform', label: '平台', width: 100 },
      { prop: 'model', label: '型号' },
      { prop: 'app_version', label: 'App版本', width: 100 },
      { prop: 'online', label: '在线', width: 80, slot: 'status' },
      { prop: 'last_active_at', label: '最后活跃', width: 170 }
    ],
    fields: [
      { prop: 'device_id', label: '设备ID', type: 'input', required: true },
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
      device_id: 'D' + Math.floor(Math.random() * 9e15 + 1e15).toString(16),
      platform: ['android', 'ios', 'web', 'harmony'][Math.floor(Math.random() * 4)],
      model: ['iPhone 15', 'Xiaomi 14', 'HUAWEI Mate60', 'Pixel 8'][Math.floor(Math.random() * 4)],
      app_version: '2.' + Math.floor(Math.random() * 9) + '.' + Math.floor(Math.random() * 9),
      online: Math.random() > 0.4 ? 1 : 0,
      last_active_at: '2026-07-12 ' + String(Math.floor(Math.random() * 24)).padStart(2, '0') + ':' + String(Math.floor(Math.random() * 60)).padStart(2, '0')
    })
  },
  'push-logs': {
    title: '推送记录',
    columns: [
      { prop: 'id', label: 'ID', width: 80 },
      { prop: 'title', label: '推送标题' },
      { prop: 'content', label: '内容' },
      { prop: 'target_type', label: '目标类型', width: 100 },
      { prop: 'target_value', label: '目标值', width: 160 },
      { prop: 'success_count', label: '成功', width: 80 },
      { prop: 'fail_count', label: '失败', width: 80 },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'created_at', label: '时间', width: 170 }
    ],
    fields: [
      { prop: 'title', label: '标题', type: 'input', required: true, placeholder: '例如：系统维护通知', tip: '推送消息的标题，会显示在设备通知栏' },
      { prop: 'content', label: '内容', type: 'textarea', required: true, placeholder: '例如：系统将于今晚 22:00-23:00 进行维护升级，期间推送服务可能短暂不可用', tip: '推送消息正文，支持纯文本' },
      { prop: 'target_type', label: '目标类型', type: 'select', required: true, options: [
        { label: '设备', value: 'device' },
        { label: 'Key', value: 'key' }
      ], placeholder: '选择推送目标类型：设备=指定 device_id，Key=按 key 分组推送', tip: '设备：精确推送到指定 device_id；Key：按 key 分组推送给该 key 下所有设备' },
      { prop: 'target_value', label: '目标值', type: 'input', required: true, placeholder: 'device_id 或 key_value，多个用英文逗号分隔，如：dev_001,dev_002', tip: '案例：device 类型填 dev_001,dev_002；key 类型填 my_app_key' }
    ],
    mockRow: () => ({
      id: 0,
      title: '推送消息 ' + Math.floor(Math.random() * 999),
      content: '这是一条测试推送消息',
      target_type: ['device', 'key'][Math.floor(Math.random() * 2)],
      target_value: 'target_' + Math.floor(Math.random() * 99999),
      success_count: Math.floor(Math.random() * 8000),
      fail_count: Math.floor(Math.random() * 200),
      status: Math.random() > 0.2 ? 1 : 0,
      created_at: '2026-07-12 ' + String(Math.floor(Math.random() * 24)).padStart(2, '0') + ':' + String(Math.floor(Math.random() * 60)).padStart(2, '0')
    })
  },
  blacklist: {
    title: '黑名单',
    columns: [
      { prop: 'type', label: '类型', width: 100 },
      { prop: 'value', label: '值' },
      { prop: 'reason', label: '原因' },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'created_at', label: '创建时间', width: 170 }
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
      created_at: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  admins: {
    title: '管理员',
    columns: [
      { prop: 'username', label: '账号', width: 140 },
      { prop: 'role', label: '角色', width: 140 },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'created_at', label: '创建时间', width: 170 }
    ],
    fields: [
      { prop: 'username', label: '账号', type: 'input', required: true },
      { prop: 'password', label: '密码', type: 'input', required: true },
      { prop: 'role', label: '角色', type: 'select', required: true, options: [
        { label: '超级管理员', value: 'super_admin' },
        { label: '管理员', value: 'admin' }
      ] },
      { prop: 'status', label: '状态', type: 'switch' }
    ],
    mockRow: () => ({
      id: 0,
      username: 'admin_' + Math.floor(Math.random() * 99),
      role: ['super_admin', 'admin'][Math.floor(Math.random() * 2)],
      status: 1,
      created_at: '2026-07-12 ' + String(Math.floor(Math.random() * 24)).padStart(2, '0') + ':00'
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
    if (f.prop === 'notify_email') {
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

// 修改密码弹窗
const passwordDialogVisible = ref(false)
const passwordFormRef = ref<FormInstance>()
const resettingPassword = ref(false)
const passwordForm = reactive<{ id: number | null; password: string }>({
  id: null,
  password: ''
})
const passwordRules: FormRules = {
  password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 6, message: '密码长度不能少于6位', trigger: 'blur' }
  ]
}

function openPasswordDialog(row: Record<string, any>) {
  passwordForm.id = row.id
  passwordForm.password = ''
  passwordDialogVisible.value = true
}

async function handleResetPassword() {
  if (!passwordFormRef.value) return
  try {
    await passwordFormRef.value.validate()
  } catch {
    return
  }
  const userId = passwordForm.id
  if (userId === null) return
  resettingPassword.value = true
  try {
    await resetUserPasswordApi(userId, passwordForm.password)
    ElMessage.success('密码修改成功')
    passwordDialogVisible.value = false
  } catch (error) {
    ElMessage.error('密码修改失败')
  }
  resettingPassword.value = false
}

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
    const mod = currentModule.value
    if (mod === 'keys') {
      const res = await getKeyListApi(query)
      tableData.value = res.data?.list || []
      total.value = res.data?.total || 0
    } else if (mod === 'blacklist') {
      const res = await getBlacklistApi(query)
      tableData.value = res.data?.list || []
      total.value = res.data?.total || 0
    } else if (mod === 'users') {
      const res = await getUserListApi(query)
      tableData.value = res.data?.list || []
      total.value = res.data?.total || 0
    } else if (mod === 'admins') {
      const res = await getAdminListApi(query)
      tableData.value = res.data?.list || []
      total.value = res.data?.total || 0
    } else if (mod === 'devices') {
      const res = await getDeviceListApi(query)
      tableData.value = res.data?.list || []
      total.value = res.data?.total || 0
    } else if (mod === 'push-logs') {
      const res = await getPushLogListApi(query)
      tableData.value = res.data?.list || []
      total.value = res.data?.total || 0
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
  Object.keys(dialogForm).forEach((k) => delete dialogForm[k])
  if (row) {
    Object.assign(dialogForm, JSON.parse(JSON.stringify(row)))
    if (currentModule.value === 'admins' && isEdit.value) {
      delete dialogForm.password
    }
  } else {
    formFields.value.forEach((f) => {
      if (f.type === 'switch') {
        dialogForm[f.prop] = 1
      } else if (f.type === 'number') {
        dialogForm[f.prop] = f.prop === 'max_devices' ? 10 : f.prop === 'notify_interval' ? 300 : 0
      } else if (f.prop === 'username') {
        dialogForm[f.prop] = `user_${Math.floor(Math.random() * 9000 + 1000)}`
      } else if (f.prop === 'nickname') {
        dialogForm[f.prop] = `用户${Math.floor(Math.random() * 900 + 100)}`
      } else if (f.prop === 'phone') {
        dialogForm[f.prop] = `138${String(Math.floor(Math.random() * 100000000)).padStart(8, '0')}`
      } else if (f.prop === 'name') {
        dialogForm[f.prop] = currentModule.value === 'keys'
          ? `应用Key_${Math.floor(Math.random() * 900 + 100)}`
          : `项目${Math.floor(Math.random() * 900 + 100)}`
      } else if (f.prop === 'password') {
        dialogForm[f.prop] = `Admin@${Math.floor(Math.random() * 9000 + 1000)}`
      } else if (f.prop === 'role' && f.options && f.options.length > 0) {
        dialogForm[f.prop] = 'admin'
      } else if (f.prop === 'value') {
        dialogForm[f.prop] = 'block_' + Math.floor(Math.random() * 99999)
      } else if (f.prop === 'reason') {
        dialogForm[f.prop] = ['违规操作', '异常请求', '安全风险'][Math.floor(Math.random() * 3)]
      } else if (f.prop === 'content') {
        dialogForm[f.prop] = '这是一条测试推送消息内容'
      } else if (f.prop === 'target_value') {
        dialogForm[f.prop] = 'device_' + Math.floor(Math.random() * 99999)
      } else if (f.prop === 'type' && f.options && f.options.length > 0) {
        dialogForm[f.prop] = f.options[0].value
      } else if (f.prop === 'target_type' && f.options && f.options.length > 0) {
        dialogForm[f.prop] = f.options[0].value
      } else if (f.prop === 'platform' && f.options && f.options.length > 0) {
        dialogForm[f.prop] = 'all'
      } else {
        dialogForm[f.prop] = ''
      }
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
    const mod = currentModule.value
    if (mod === 'users') {
      if (isEdit.value) {
        await updateUserApi(dialogForm.id, dialogForm as unknown as UserForm)
        ElMessage.success('更新成功')
      } else {
        await createUserApi(dialogForm as unknown as UserForm)
        ElMessage.success('新增成功')
      }
    } else if (mod === 'keys') {
      if (isEdit.value) {
        await updateKeyApi(dialogForm.id, dialogForm as unknown as KeyForm)
        ElMessage.success('更新成功')
      } else {
        await createKeyApi(dialogForm as unknown as KeyForm)
        ElMessage.success('新增成功')
      }
    } else if (mod === 'blacklist') {
      if (isEdit.value) {
        ElMessage.info('黑名单暂不支持编辑')
      } else {
        await createBlacklistApi(dialogForm as unknown as BlacklistForm)
        ElMessage.success('新增成功')
      }
    } else if (mod === 'admins') {
      if (isEdit.value) {
        const updateData = { ...dialogForm }
        if (!updateData.password) delete updateData.password
        await updateAdminApi(dialogForm.id, updateData as unknown as AdminForm)
        ElMessage.success('更新成功')
      } else {
        await createAdminApi(dialogForm as unknown as AdminForm)
        ElMessage.success('新增成功')
      }
    } else if (mod === 'push-logs') {
      // 推送记录不支持编辑，仅支持新增（即发起一次推送）
      if (isEdit.value) {
        ElMessage.info('推送记录为历史记录，不支持编辑')
        return
      }
      // 后端 /admin/push/send 兼容 target_type/target_value 字段名
      const payload = {
        title: dialogForm.title,
        content: dialogForm.content,
        target_type: dialogForm.target_type,   // 'device' | 'key'
        target_value: dialogForm.target_value,  // device_id 或 key_value（可逗号分隔多个）
        pushType: 'notification',
      }
      const res = await sendPushApi(payload as any)
      if (res.success) {
        ElMessage.success(res.message || `推送成功（成功 ${res.success_count}，失败 ${res.fail_count}）`)
      } else {
        ElMessage.warning(res.message || '推送失败，可能没有在线设备')
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

// 复制到剪贴板（兼容HTTP环境）
async function copyToClipboard(text: string) {
  if (!text) return
  try {
    // 优先使用现代 Clipboard API
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      ElMessage.success('已复制到剪贴板')
      return
    }
    // 回退方案：使用 textarea + execCommand
    const textarea = document.createElement('textarea')
    textarea.value = text
    textarea.style.position = 'fixed'
    textarea.style.left = '-9999px'
    textarea.style.top = '0'
    document.body.appendChild(textarea)
    textarea.focus()
    textarea.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(textarea)
    if (ok) {
      ElMessage.success('已复制到剪贴板')
    } else {
      ElMessage.warning('复制失败，请手动复制')
    }
  } catch {
    ElMessage.warning('复制失败，请手动复制')
  }
}

async function handleDelete(row: Record<string, any>) {
  try {
    await ElMessageBox.confirm(`确定要删除该${moduleTitle.value}吗？`, '提示', {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning',
      appendTo: 'body'
    })
    const mod = currentModule.value
    if (mod === 'users') {
      await deleteUserApi(row.id)
    } else if (mod === 'keys') {
      await deleteKeyApi(row.id)
    } else if (mod === 'blacklist') {
      await deleteBlacklistApi(row.id)
    } else if (mod === 'admins') {
      await deleteAdminApi(row.id)
    } else {
      allData = allData.filter((item) => item.id !== row.id)
    }
    ElMessage.success('删除成功')
    fetchData()
  } catch {
    // 取消
  }
}

// 一键清空
async function handleClearAll() {
  try {
    await ElMessageBox.confirm(
      `确定要清空所有${moduleTitle.value}吗？此操作不可恢复！`,
      '危险操作',
      {
        confirmButtonText: '确定清空',
        cancelButtonText: '取消',
        type: 'error',
        confirmButtonClass: 'el-button--danger',
        appendTo: 'body'
      }
    )
    const mod = currentModule.value
    if (mod === 'users') {
      // 用户：逐条删除（或调用清空接口）
      const items = tableData.value
      for (const item of items) {
        try {
          await deleteUserApi(item.id)
        } catch {
          // 跳过删除失败的
        }
      }
      ElMessage.success('已清空当前页的用户')
    } else if (mod === 'admins') {
      // 管理员：逐条删除（保留当前登录的管理员）
      const items = tableData.value
      for (const item of items) {
        try {
          await deleteAdminApi(item.id)
        } catch {
          // 跳过删除失败的（如当前登录的管理员）
        }
      }
      ElMessage.success('已清空可删除的管理员')
    } else if (mod === 'keys') {
      // Key：逐条删除
      const items = tableData.value
      for (const item of items) {
        try {
          await deleteKeyApi(item.id)
        } catch {
          // 跳过删除失败的
        }
      }
      ElMessage.success('已清空可删除的Key')
    } else {
      // 用户等模拟数据模块：直接清空本地数据
      allData = []
      ElMessage.success('已清空全部数据')
    }
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

.field-tip {
  font-size: 12px;
  color: var(--el-text-color-secondary, #909399);
  margin-top: 4px;
  line-height: 1.5;
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
