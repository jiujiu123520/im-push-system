<template>
  <div class="page">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <div class="title">Push Key 管理</div>
          <div class="actions">
            <el-input v-model="query.keyword" placeholder="搜索" clearable style="width:200px"
                      @keyup.enter="loadList(1)" @clear="loadList(1)" />
            <el-button type="primary" @click="openCreate"><el-icon><Plus /></el-icon>新建 Key</el-button>
          </div>
        </div>
      </template>
      <el-alert type="info" :closable="false" style="margin-bottom:16px"
                title="提示：Push Key 用于 APP 端订阅推送，创建后请把 Key 值填入 APP 或 SDK 中。" show-icon />
      <el-table :data="list" stripe v-loading="loading">
        <el-table-column prop="id" label="ID" width="80" align="center" />
        <el-table-column label="Key 信息" min-width="260">
          <template #default="{ row }">
            <div class="key-name">{{ row.name }}</div>
            <div class="key-val">
              <span>{{ row.key_value }}</span>
              <el-button link type="primary" @click="copy(row.key_value)">复制</el-button>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="subscribed_total" label="订阅设备" width="110" align="center" />
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-switch v-model="row.status" :active-value="1" :inactive-value="0" @change="toggleStatus(row as PushKey)" />
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="180" align="center" />
        <el-table-column label="操作" width="200" align="center" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row as PushKey)">编辑</el-button>
            <el-popconfirm title="确定删除此 Key？删除后订阅的设备将无法接收推送" @confirm="del(row as PushKey)">
              <template #reference><el-button link type="danger">删除</el-button></template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.pageSize"
          :page-sizes="[10,20,50]" layout="total, sizes, prev, pager, next, jumper"
          :total="total" @current-change="loadList" @size-change="loadList(1)" />
      </div>
    </el-card>

    <!-- 创建/编辑 Dialog -->
    <el-dialog v-model="dialogVisible" :title="editId ? '编辑 Key' : '新建 Push Key'" width="min(480px,92vw)">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
        <el-form-item label="Key 名称" prop="name">
          <el-input v-model="form.name" placeholder="给 Key 起个名字，比如 业务告警、通知消息等" maxlength="50" show-word-limit />
        </el-form-item>
        <el-form-item label="最大设备数" prop="max_devices">
          <el-input-number v-model="form.max_devices" :min="1" :max="10000" />
          <div style="color:var(--text-secondary);font-size:12px;margin-top:4px">限制此 Key 可订阅的设备数量</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { createKeyApi, deleteKeyApi, getKeyListApi, updateKeyApi, updateKeyStatusApi } from '@/api/key'
import type { PushKey } from '@/api/types'

const loading = ref(false)
const saving = ref(false)
const list = ref<PushKey[]>([])
const total = ref(0)
const query = reactive({ page: 1, pageSize: 10, keyword: '' })

const dialogVisible = ref(false)
const editId = ref<number | null>(null)
const formRef = ref<FormInstance>()
const form = reactive({ name: '', max_devices: 10 })
const rules: FormRules = {
  name: [{ required: true, message: '请输入 Key 名称', trigger: 'blur' },
         { min: 1, max: 50, message: '长度 1-50', trigger: 'blur' }]
}

async function loadList(page = query.page) {
  query.page = page
  loading.value = true
  try {
    const r = await getKeyListApi({ page: query.page, pageSize: query.pageSize, per_page: query.pageSize, keyword: query.keyword })
    list.value = r.data?.list || []
    total.value = r.data?.total || 0
  } finally { loading.value = false }
}
function openCreate() { editId.value = null; form.name = ''; form.max_devices = 10; dialogVisible.value = true }
function openEdit(row: PushKey) { editId.value = row.id; form.name = row.name; form.max_devices = row.max_devices || 10; dialogVisible.value = true }
async function save() {
  await formRef.value?.validate(async (ok) => {
    if (!ok) return
    saving.value = true
    try {
      if (editId.value) {
        await updateKeyApi(editId.value, { name: form.name, max_devices: form.max_devices })
        ElMessage.success('编辑成功')
      } else {
        await createKeyApi({ name: form.name, max_devices: form.max_devices })
        ElMessage.success('创建成功')
      }
      dialogVisible.value = false
      loadList(query.page)
    } catch (e: any) { ElMessage.error(e?.message || '保存失败')
    } finally { saving.value = false }
  })
}
async function toggleStatus(row: PushKey) {
  try {
    await updateKeyStatusApi(row.id, row.status as 0 | 1)
    ElMessage.success((row.status === 1 ? '已启用' : '已禁用'))
  } catch (e: any) {
    ElMessage.error(e?.message || '操作失败')
    row.status = row.status === 1 ? 0 : 1
  }
}
async function del(row: PushKey) {
  try {
    await deleteKeyApi(row.id)
    ElMessage.success('已删除')
    loadList(query.page)
  } catch {}
}
function copy(v: string) {
  navigator.clipboard.writeText(v).then(() => ElMessage.success('已复制')).catch(() => {
    const ta = document.createElement('textarea'); ta.value = v; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); ElMessage.success('已复制') } catch {}
    document.body.removeChild(ta)
  })
}
onMounted(() => loadList(1))
</script>

<style lang="scss" scoped>
.card-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: $space-3;
  .title { font-weight: 600; font-size: $font-size-lg; }
  .actions { display: flex; gap: $space-2; } }
.key-name { font-weight: 600; color: var(--text-primary); }
.key-val { display: flex; align-items: center; gap: $space-2; margin-top: 4px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: $font-size-xs; color: var(--text-secondary); }
.desc { margin-top: 4px; font-size: $font-size-xs; color: var(--text-secondary); }
.pagination { margin-top: $space-4; display: flex; justify-content: flex-end; }
</style>
