<template>
  <div class="page-container user-notices-page">
    <!-- 页头 -->
    <div class="page-hero">
      <div class="hero-bg">
        <div class="hero-blob blob-a"></div>
        <div class="hero-blob blob-b"></div>
      </div>
      <div class="hero-content">
        <div>
          <h2 class="hero-title">用户公告管理</h2>
          <p class="hero-sub">创建、发布并管理在用户端展示的系统公告</p>
        </div>
        <div class="hero-stats">
          <div class="stat-mini">
            <span class="stat-label">公告总数</span>
            <span class="stat-value">{{ total }}</span>
          </div>
          <div class="stat-divider"></div>
          <div class="stat-mini">
            <span class="stat-label">已发布</span>
            <span class="stat-value status-ok">{{ publishedCount }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- 操作栏 -->
    <div class="action-bar card">
      <div class="search-left">
        <el-input
          v-model="query.keyword"
          placeholder="搜索公告标题"
          :prefix-icon="SearchIcon"
          clearable
          style="width: 260px"
          @keyup.enter="fetchList"
        />
        <el-select
          v-model="query.type"
          placeholder="类型"
          clearable
          style="width: 140px"
        >
          <el-option label="普通公告" :value="1" />
          <el-option label="紧急公告" :value="2" />
          <el-option label="维护公告" :value="3" />
          <el-option label="新功能" :value="4" />
        </el-select>
        <el-select
          v-model="query.status"
          placeholder="状态"
          clearable
          style="width: 140px"
        >
          <el-option label="草稿" :value="0" />
          <el-option label="已发布" :value="1" />
        </el-select>
        <el-button :icon="SearchIcon" type="primary" @click="fetchList">搜索</el-button>
        <el-button :icon="RefreshIcon" @click="resetQuery">重置</el-button>
      </div>
      <div class="search-right">
        <el-button :icon="PlusIcon" type="primary" @click="openDialog()">
          新建公告
        </el-button>
      </div>
    </div>

    <!-- 列表 -->
    <div class="card list-card" v-loading="loading">
      <el-table :data="list" stripe style="width: 100%">
        <el-table-column prop="id" label="ID" width="80" align="center" />
        <el-table-column label="标题" min-width="240">
          <template #default="{ row }">
            <div class="title-cell">
              <el-icon v-if="row.is_sticky === 1" class="sticky-icon"><Top /></el-icon>
              <span class="ttl">{{ row.title }}</span>
              <el-tag
                v-if="row.type === 2"
                size="small"
                type="danger"
                effect="light"
                style="margin-left: 6px"
              >
                紧急
              </el-tag>
              <el-tag
                v-else-if="row.type === 3"
                size="small"
                type="warning"
                effect="light"
                style="margin-left: 6px"
              >
                维护
              </el-tag>
              <el-tag
                v-else-if="row.type === 4"
                size="small"
                type="success"
                effect="light"
                style="margin-left: 6px"
              >
                新功能
              </el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="等级" width="90" align="center">
          <template #default="{ row }">
            <el-tag
              v-if="row.level === 3"
              type="danger"
              effect="dark"
              size="small"
            >紧急</el-tag>
            <el-tag
              v-else-if="row.level === 2"
              type="warning"
              size="small"
            >重要</el-tag>
            <el-tag v-else size="small" type="info">普通</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="展示位置" width="150" align="center">
          <template #default="{ row }">
            <div class="show-tags">
              <el-tag v-if="row.show_dialog === 1" size="small" type="primary">登录弹窗</el-tag>
              <el-tag v-if="row.show_home === 1" size="small" effect="plain">首页</el-tag>
              <span v-if="row.show_dialog !== 1 && row.show_home !== 1" class="zero">-</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.status === 1" type="success" size="small">已发布</el-tag>
            <el-tag v-else type="info" size="small">草稿</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="置顶" width="80" align="center">
          <template #default="{ row }">
            <el-switch
              :model-value="row.is_sticky === 1"
              :loading="togglingStickyId === row.id"
              @change="(v) => onToggleSticky(row, v)"
            />
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="80" align="center" />
        <el-table-column label="发布时间" width="170" align="center">
          <template #default="{ row }">
            <span>{{ row.publish_at || row.created_at }}</span>
          </template>
        </el-table-column>
        <el-table-column label="展示时段" width="160" align="center">
          <template #default="{ row }">
            <span v-if="!row.start_at && !row.end_at">永久</span>
            <span v-else>
              {{ row.start_at?.slice(0, 10) || '立即' }}
              ~
              {{ row.end_at?.slice(0, 10) || '永久' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="260" align="center" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openDialog(row)">编辑</el-button>
            <el-button
              v-if="row.status !== 1"
              link
              type="success"
              size="small"
              @click="onPublish(row)"
            >发布</el-button>
            <el-button
              v-else
              link
              type="warning"
              size="small"
              @click="onWithdraw(row)"
            >撤回</el-button>
            <el-popconfirm
              title="确定要删除该公告吗？"
              confirm-button-text="删除"
              cancel-button-text="取消"
              confirm-button-type="danger"
              @confirm="onDelete(row)"
            >
              <template #reference>
                <el-button link type="danger" size="small">删除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination">
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          :page-size="query.pageSize"
          v-model:current-page="query.page"
          :page-sizes="[10, 20, 50, 100]"
          @size-change="onSizeChange"
          @current-change="onPageChange"
        />
      </div>
    </div>

    <!-- 新建/编辑 Dialog -->
    <el-dialog
      v-model="dialogVisible"
      :title="form.id ? '编辑公告' : '新建公告'"
      width="min(760px, 94vw)"
      top="4vh"
      destroy-on-close
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="formRules"
        label-width="110px"
        label-position="right"
      >
        <el-form-item label="公告标题" prop="title">
          <el-input
            v-model="form.title"
            maxlength="200"
            show-word-limit
            placeholder="请输入公告标题"
          />
        </el-form-item>

        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="公告类型" prop="type">
              <el-select v-model="form.type" style="width: 100%">
                <el-option label="普通公告" :value="1" />
                <el-option label="紧急公告" :value="2" />
                <el-option label="维护公告" :value="3" />
                <el-option label="新功能" :value="4" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="公告等级" prop="level">
              <el-select v-model="form.level" style="width: 100%">
                <el-option label="普通" :value="1" />
                <el-option label="重要" :value="2" />
                <el-option label="紧急" :value="3" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="排序值" prop="sort">
              <el-input-number
                v-model="form.sort"
                :min="0"
                :max="9999"
                controls-position="right"
                style="width: 100%"
              />
              <div class="form-hint">越大越靠前</div>
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="是否置顶">
              <el-switch
                v-model="form.is_sticky"
                :active-value="1"
                :inactive-value="0"
              />
              <span class="form-hint" style="margin-left: 8px">
                {{ form.is_sticky === 1 ? '置顶' : '不置顶' }}
              </span>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="登录弹窗展示">
              <el-switch
                v-model="form.show_dialog"
                :active-value="1"
                :inactive-value="0"
              />
              <span class="form-hint" style="margin-left: 8px">
                {{ form.show_dialog === 1 ? '弹窗' : '不弹窗' }}
              </span>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="首页展示">
              <el-switch
                v-model="form.show_home"
                :active-value="1"
                :inactive-value="0"
              />
              <span class="form-hint" style="margin-left: 8px">
                {{ form.show_home === 1 ? '展示' : '不展示' }}
              </span>
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="展示开始时间">
              <el-date-picker
                v-model="form.start_at"
                type="datetime"
                placeholder="留空=立即展示"
                value-format="YYYY-MM-DD HH:mm:ss"
                style="width: 100%"
              />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="展示结束时间">
              <el-date-picker
                v-model="form.end_at"
                type="datetime"
                placeholder="留空=永久展示"
                value-format="YYYY-MM-DD HH:mm:ss"
                style="width: 100%"
              />
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="0">草稿</el-radio>
            <el-radio :value="1">直接发布</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="公告内容" prop="content">
          <el-input
            v-model="form.content"
            type="textarea"
            :rows="12"
            maxlength="10000"
            show-word-limit
            placeholder="请输入公告正文内容，支持纯文本或 HTML"
          />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitForm">
          保存
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  Search as SearchIcon,
  Refresh as RefreshIcon,
  Plus as PlusIcon,
  Top
} from '@element-plus/icons-vue'
import {
  getUserNoticeListApi,
  createUserNoticeApi,
  updateUserNoticeApi,
  deleteUserNoticeApi,
  publishUserNoticeApi,
  withdrawUserNoticeApi,
  toggleUserNoticeStickyApi,
  type UserNoticeRecord,
  type UserNoticeForm
} from '@/api/settings'

const loading = ref(false)
const list = ref<UserNoticeRecord[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pageSize: 20,
  keyword: '',
  type: '' as '' | number,
  status: '' as '' | number
})

const publishedCount = computed(() =>
  list.value.filter((r) => r.status === 1).length
)

async function fetchList() {
  loading.value = true
  try {
    const res = await getUserNoticeListApi({ ...query })
    const d = res.data || {}
    list.value = d.list || []
    total.value = d.total || 0
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : '加载公告列表失败')
  } finally {
    loading.value = false
  }
}

function resetQuery() {
  query.page = 1
  query.keyword = ''
  query.type = ''
  query.status = ''
  fetchList()
}

function onPageChange(p: number) {
  query.page = p
  fetchList()
}
function onSizeChange(s: number) {
  query.pageSize = s
  query.page = 1
  fetchList()
}

// --- Dialog 表单 ---
const dialogVisible = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()
const defaultForm: UserNoticeForm = {
  title: '',
  content: '',
  type: 1,
  level: 1,
  show_dialog: 1,
  show_home: 1,
  is_sticky: 0,
  sort: 0,
  status: 0,
  start_at: '',
  end_at: ''
}
const form = reactive<UserNoticeForm>({ ...defaultForm })
const formRules: FormRules = {
  title: [
    { required: true, message: '请输入公告标题', trigger: 'blur' },
    { min: 2, max: 200, message: '标题长度为 2-200 字符', trigger: 'blur' }
  ],
  type: [{ required: true, message: '请选择公告类型', trigger: 'change' }],
  level: [{ required: true, message: '请选择公告等级', trigger: 'change' }],
  status: [{ required: true, message: '请选择状态', trigger: 'change' }]
}

function openDialog(row?: UserNoticeRecord) {
  Object.assign(form, defaultForm)
  if (row) {
    form.id = row.id
    form.title = row.title
    form.content = row.content || ''
    form.type = row.type
    form.level = row.level
    form.show_dialog = row.show_dialog
    form.show_home = row.show_home
    form.is_sticky = row.is_sticky
    form.sort = row.sort
    form.status = row.status
    form.start_at = row.start_at || ''
    form.end_at = row.end_at || ''
  }
  dialogVisible.value = true
}

async function submitForm() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    ElMessage.warning('请完善必填项')
    return
  }
  saving.value = true
  try {
    if (form.id) {
      const res = await updateUserNoticeApi(form.id, { ...form })
      ElMessage.success(res.data?.message || '更新成功')
    } else {
      const res = await createUserNoticeApi({ ...form })
      ElMessage.success(res.data?.message || '创建成功')
    }
    dialogVisible.value = false
    fetchList()
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : '保存失败')
  } finally {
    saving.value = false
  }
}

// --- 行操作 ---
const togglingStickyId = ref<number | null>(null)
async function onToggleSticky(row: UserNoticeRecord, val: boolean) {
  togglingStickyId.value = row.id
  const target = val ? 1 : 0
  try {
    const res = await toggleUserNoticeStickyApi(row.id, target)
    row.is_sticky = target
    ElMessage.success(res.data?.message || '已更新置顶状态')
  } catch (err) {
    // 恢复原值
    ;(row as any).is_sticky = target === 1 ? 0 : 1
    ElMessage.error(err instanceof Error ? err.message : '操作失败')
  } finally {
    togglingStickyId.value = null
  }
}

async function onPublish(row: UserNoticeRecord) {
  try {
    await ElMessageBox.confirm(
      `发布后用户端将立即看到该公告，确认发布「${row.title}」？`,
      '发布公告',
      { confirmButtonText: '发布', cancelButtonText: '取消', type: 'success' }
    )
  } catch {
    return
  }
  try {
    const res = await publishUserNoticeApi(row.id)
    row.status = 1
    ElMessage.success(res.data?.message || '发布成功')
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : '发布失败')
  }
}

async function onWithdraw(row: UserNoticeRecord) {
  try {
    await ElMessageBox.confirm(
      `撤回后用户端将不再展示该公告，确认撤回「${row.title}」？`,
      '撤回公告',
      { confirmButtonText: '撤回', cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }
  try {
    const res = await withdrawUserNoticeApi(row.id)
    row.status = 0
    ElMessage.success(res.data?.message || '已撤回')
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : '撤回失败')
  }
}

async function onDelete(row: UserNoticeRecord) {
  try {
    const res = await deleteUserNoticeApi(row.id)
    ElMessage.success(res.data?.message || '已删除')
    fetchList()
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : '删除失败')
  }
}

onMounted(() => {
  fetchList()
})
</script>

<style lang="scss" scoped>
.user-notices-page {
  animation: fade-up 0.5s ease;
}

.page-hero {
  position: relative;
  padding: 24px 28px 22px;
  border-radius: 14px;
  margin-bottom: 16px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: #fff;
  overflow: hidden;
}
.hero-bg {
  position: absolute; inset: 0; pointer-events: none;
  .hero-blob {
    position: absolute; border-radius: 50%; filter: blur(40px); opacity: 0.35;
  }
  .blob-a { width: 260px; height: 260px; background: #fff; top: -120px; right: -60px; }
  .blob-b { width: 200px; height: 200px; background: #ffe29f; bottom: -90px; left: 20%; }
}
.hero-content {
  position: relative; z-index: 1;
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;
}
.hero-title { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: 0.5px; }
.hero-sub { margin: 6px 0 0; font-size: 13px; opacity: 0.9; }
.hero-stats {
  display: flex; align-items: center; gap: 14px;
  background: rgba(255,255,255,0.14); padding: 10px 18px; border-radius: 12px; backdrop-filter: blur(6px);
}
.stat-mini { display: flex; flex-direction: column; gap: 2px; }
.stat-label { font-size: 12px; opacity: 0.85; }
.stat-value { font-size: 20px; font-weight: 700; }
.status-ok { color: #d7ffe0; }
.stat-divider { width: 1px; height: 34px; background: rgba(255,255,255,0.3); }

.card {
  background: #fff;
  border-radius: 12px;
  padding: 16px 20px;
  box-shadow: 0 2px 12px rgba(15, 23, 42, 0.05);
  margin-bottom: 16px;
}

.action-bar {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.search-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.search-right { display: flex; gap: 8px; }

.list-card { padding: 8px 12px 16px; }

.title-cell { display: flex; align-items: center; }
.sticky-icon { color: #f56c6c; margin-right: 4px; }
.ttl { font-weight: 600; color: #1f2937; }

.show-tags { display: flex; flex-wrap: wrap; justify-content: center; gap: 4px; }
.zero { color: #c0c4cc; }

.pagination { display: flex; justify-content: flex-end; padding: 14px 12px 6px; }

.form-hint { color: #909399; font-size: 12px; }

@keyframes fade-up {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
