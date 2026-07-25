<template>
  <div class="page-container audio-page">
    <div class="page-hero">
      <div class="hero-bg">
        <div class="hero-blob blob-a"></div>
        <div class="hero-blob blob-b"></div>
      </div>
      <div class="hero-content">
        <div>
          <h2 class="hero-title">音频管理</h2>
          <p class="hero-sub">管理推送提示音，支持上传、编辑、设置默认播放音频</p>
        </div>
        <div class="hero-stats">
          <div class="stat-mini">
            <span class="stat-label">音频总数</span>
            <span class="stat-value">{{ total }}</span>
          </div>
          <div class="stat-divider"></div>
          <div class="stat-mini">
            <span class="stat-label">已启用</span>
            <span class="stat-value status-ok">{{ activeCount }}</span>
          </div>
        </div>
      </div>
    </div>

    <div class="upload-card">
      <el-upload
        class="audio-uploader"
        drag
        :auto-upload="false"
        :show-file-list="false"
        accept=".mp3,.wav,.ogg,.flac,.aac,.m4a"
        :on-change="handleFileChange"
        :before-upload="beforeUpload"
      >
        <el-icon class="upload-icon"><UploadIcon /></el-icon>
        <div class="el-upload__text">
          将音频文件拖到此处，或<em>点击上传</em>
        </div>
        <template #tip>
          <div class="el-upload__tip">
            支持 MP3 / WAV / OGG / FLAC / AAC / M4A 格式，单文件最大 50MB
          </div>
        </template>
      </el-upload>
    </div>

    <div class="audio-card" v-loading="loading">
      <div class="card-head">
        <div class="head-icon icon-audio">
          <el-icon><VideoPlayIcon /></el-icon>
        </div>
        <div class="head-text">
          <h3 class="card-title">音频列表</h3>
          <p class="card-sub">管理所有上传的音频文件，设置默认播放音频</p>
        </div>
        <div class="head-actions">
          <el-button :icon="RefreshIcon" @click="fetchList">刷新</el-button>
          <el-button type="primary" :icon="UploadIcon" @click="triggerUpload">
            上传音频
          </el-button>
        </div>
      </div>

      <el-table :data="tableData" stripe style="width: 100%" row-key="id">
        <el-table-column type="index" label="#" width="60" align="center" />
        <el-table-column prop="title" label="标题" min-width="160">
          <template #default="{ row }">
            <div class="title-cell">
              <div class="title-icon">
                <el-icon><VideoPlayIcon /></el-icon>
              </div>
              <span class="title-text">{{ row.title }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="artist" label="艺术家" min-width="120" />
        <el-table-column prop="duration_text" label="时长" width="100" align="center">
          <template #default="{ row }">
            <span class="mono-text">{{ row.duration_text }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="file_size_text" label="文件大小" width="110" align="center">
          <template #default="{ row }">
            <span class="mono-text">{{ row.file_size_text }}</span>
          </template>
        </el-table-column>
        <el-table-column label="默认播放" width="100" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.is_default === 1" type="warning" effect="dark" size="small">
              <el-icon><StarIcon /></el-icon> 默认
            </el-tag>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-switch
              :model-value="row.status === 1"
              @change="(val) => toggleStatus(row, val)"
            />
          </template>
        </el-table-column>
        <el-table-column prop="play_count" label="播放次数" width="100" align="center">
          <template #default="{ row }">
            <span class="mono-text">{{ row.play_count }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="170">
          <template #default="{ row }">
            <span class="time-text">{{ formatTime(row.created_at) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="240" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.is_default !== 1"
              link
              type="warning"
              size="small"
              :icon="StarIcon"
              @click="handleSetDefault(row)"
            >
              设为默认
            </el-button>
            <el-button
              link
              type="primary"
              size="small"
              :icon="EditIcon"
              @click="openEditDialog(row)"
            >
              编辑
            </el-button>
            <el-button
              link
              type="danger"
              size="small"
              :icon="DeleteIcon"
              @click="handleDelete(row as AudioRecord)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-empty v-if="!loading && tableData.length === 0" description="暂无音频，点击「上传音频」开始" />

      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.page_size"
          :page-sizes="[10, 20, 50]"
          :total="total"
          layout="total, sizes, prev, pager, next, jumper"
          background
          @size-change="fetchList"
          @current-change="fetchList"
        />
      </div>
    </div>

    <el-dialog
      v-model="dialogVisible"
      title="编辑音频"
      width="480px"
      destroy-on-close
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="formRules"
        label-position="top"
      >
        <el-form-item label="标题" prop="title">
          <el-input v-model="form.title" placeholder="请输入音频标题" clearable />
        </el-form-item>
        <el-form-item label="艺术家" prop="artist">
          <el-input v-model="form.artist" placeholder="请输入艺术家名称" clearable />
        </el-form-item>
        <el-form-item label="排序" prop="sort_order">
          <el-input-number
            v-model="form.sort_order"
            :min="0"
            :max="9999"
            controls-position="right"
            style="width: 100%"
          />
          <div class="form-tip">数值越小排序越靠前</div>
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">禁用</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>

    <input
      ref="fileInputRef"
      type="file"
      accept=".mp3,.wav,.ogg,.flac,.aac,.m4a"
      style="display: none"
      @change="handleFileInputChange"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules, UploadFile } from 'element-plus'
import {
  Upload as UploadIcon,
  Refresh as RefreshIcon,
  Delete as DeleteIcon,
  Edit as EditIcon,
  Star as StarIcon,
  VideoPlay as VideoPlayIcon
} from '@element-plus/icons-vue'
import {
  getAudioListApi,
  uploadAudioApi,
  updateAudioApi,
  deleteAudioApi,
  setDefaultAudioApi
} from '@/api/audio'
import type { AudioRecord } from '@/api/audio'

const loading = ref(false)
const tableData = ref<AudioRecord[]>([])
const total = ref(0)
const uploading = ref(false)
const submitting = ref(false)
const dialogVisible = ref(false)
const editingId = ref<number>(0)
const formRef = ref<FormInstance>()
const fileInputRef = ref<HTMLInputElement>()

const query = reactive({
  page: 1,
  page_size: 10
})

const form = reactive({
  title: '',
  artist: '',
  sort_order: 0,
  status: 1
})

const formRules: FormRules = {
  title: [{ required: true, message: '请输入音频标题', trigger: 'blur' }]
}

const activeCount = computed(
  () => tableData.value.filter((i) => i.status === 1).length
)

function formatTime(t: string): string {
  if (!t) return '-'
  return t.replace('T', ' ').slice(0, 19)
}

async function fetchList() {
  loading.value = true
  try {
    const res = await getAudioListApi({
      page: query.page,
      page_size: query.page_size
    })
    tableData.value = res.data?.list || []
    total.value = res.data?.total || 0
  } catch (e: any) {
    ElMessage.error(e.message || '加载失败')
  } finally {
    loading.value = false
  }
}

function triggerUpload() {
  fileInputRef.value?.click()
}

function handleFileInputChange(e: Event) {
  const target = e.target as HTMLInputElement
  const file = target.files?.[0]
  if (file) {
    uploadFile(file)
  }
  target.value = ''
}

function beforeUpload(file: File) {
  const validTypes = ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/flac', 'audio/aac', 'audio/mp4', 'audio/x-m4a']
  const validExtensions = ['.mp3', '.wav', '.ogg', '.flac', '.aac', '.m4a']
  const fileName = file.name.toLowerCase()
  const isValidExt = validExtensions.some(ext => fileName.endsWith(ext))
  
  if (!isValidExt && !validTypes.includes(file.type)) {
    ElMessage.error('仅支持 MP3 / WAV / OGG / FLAC / AAC / M4A 格式')
    return false
  }
  
  const maxSize = 50 * 1024 * 1024
  if (file.size > maxSize) {
    ElMessage.error('文件大小不能超过 50MB')
    return false
  }
  
  return true
}

function handleFileChange(file: UploadFile) {
  if (file.raw) {
    const valid = beforeUpload(file.raw)
    if (valid) {
      uploadFile(file.raw)
    }
  }
}

async function uploadFile(file: File) {
  if (uploading.value) {
    ElMessage.warning('正在上传中，请稍候...')
    return
  }

  uploading.value = true
  try {
    const formData = new FormData()
    formData.append('file', file)
    
    const res = await uploadAudioApi(formData)
    ElMessage.success(res.data?.message || '上传成功')
    query.page = 1
    fetchList()
  } catch (e: any) {
    ElMessage.error(e.message || '上传失败')
  } finally {
    uploading.value = false
  }
}

async function toggleStatus(row: AudioRecord, val: boolean) {
  try {
    await updateAudioApi(row.id, { status: val ? 1 : 0 })
    row.status = val ? 1 : 0
    ElMessage.success(val ? '已启用' : '已禁用')
  } catch (e: any) {
    ElMessage.error(e.message || '操作失败')
    row.status = val ? 0 : 1
  }
}

function openEditDialog(row: AudioRecord) {
  editingId.value = row.id
  form.title = row.title
  form.artist = row.artist
  form.sort_order = row.sort_order
  form.status = row.status
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await updateAudioApi(editingId.value, {
        title: form.title,
        artist: form.artist,
        sort_order: form.sort_order,
        status: form.status
      })
      ElMessage.success('更新成功')
      dialogVisible.value = false
      fetchList()
    } catch (e: any) {
      ElMessage.error(e.message || '操作失败')
    } finally {
      submitting.value = false
    }
  })
}

async function handleSetDefault(row: AudioRecord) {
  try {
    await ElMessageBox.confirm(
      `确认将「${row.title}」设为默认播放音频？`,
      '设为默认',
      { type: 'warning' }
    )
  } catch {
    return
  }
  try {
    await setDefaultAudioApi(row.id)
    ElMessage.success('设置成功')
    fetchList()
  } catch (e: any) {
    ElMessage.error(e.message || '设置失败')
  }
}

async function handleDelete(row: AudioRecord) {
  try {
    await ElMessageBox.confirm(
      `确认删除音频「${row.title}」？此操作不可恢复。`,
      '删除音频',
      { type: 'warning', confirmButtonText: '删除' }
    )
  } catch {
    return
  }
  try {
    await deleteAudioApi(row.id)
    ElMessage.success('删除成功')
    fetchList()
  } catch (e: any) {
    ElMessage.error(e.message || '删除失败')
  }
}

onMounted(() => {
  fetchList()
})
</script>

<style scoped>
.audio-page {
  padding: 20px;
}

.page-hero {
  position: relative;
  overflow: hidden;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 16px;
  padding: 28px 32px;
  margin-bottom: 20px;
  color: #fff;
}

.hero-bg {
  position: absolute;
  inset: 0;
  overflow: hidden;
}

.hero-blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(60px);
  opacity: 0.3;
}

.blob-a {
  width: 300px;
  height: 300px;
  background: #fff;
  top: -100px;
  right: -80px;
}

.blob-b {
  width: 200px;
  height: 200px;
  background: #f093fb;
  bottom: -60px;
  left: 30%;
}

.hero-content {
  position: relative;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
}

.hero-title {
  font-size: 26px;
  font-weight: 700;
  margin: 0 0 6px;
}

.hero-sub {
  font-size: 14px;
  opacity: 0.9;
  margin: 0;
}

.hero-stats {
  display: flex;
  align-items: center;
  gap: 24px;
}

.stat-mini {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.stat-label {
  font-size: 12px;
  opacity: 0.8;
}

.stat-value {
  font-size: 20px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 4px;
}

.status-ok {
  color: #67c23a;
}

.stat-divider {
  width: 1px;
  height: 32px;
  background: rgba(255, 255, 255, 0.3);
}

.upload-card {
  background: #fff;
  border-radius: 12px;
  padding: 20px 24px;
  margin-bottom: 20px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
}

.audio-uploader {
  :deep(.el-upload-dragger) {
    padding: 30px 20px;
  }
}

.upload-icon {
  font-size: 48px;
  color: #409eff;
  margin-bottom: 16px;
}

.audio-card {
  background: #fff;
  border-radius: 12px;
  padding: 20px 24px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
}

.card-head {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
}

.head-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  color: #fff;
}

.icon-audio {
  background: linear-gradient(135deg, #667eea, #764ba2);
}

.head-text {
  flex: 1;
}

.card-title {
  font-size: 16px;
  font-weight: 600;
  margin: 0;
}

.card-sub {
  font-size: 12px;
  color: #909399;
  margin: 4px 0 0;
}

.head-actions {
  display: flex;
  gap: 8px;
}

.title-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.title-icon {
  width: 32px;
  height: 32px;
  border-radius: 6px;
  background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
  color: #667eea;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
}

.title-text {
  font-weight: 600;
  color: #303133;
}

.mono-text {
  font-family: 'Courier New', monospace;
  font-size: 12px;
  color: #606266;
}

.time-text {
  font-size: 13px;
  color: #909399;
  font-family: 'Courier New', monospace;
}

.text-muted {
  color: #c0c4cc;
}

.form-tip {
  font-size: 12px;
  color: #909399;
  margin-top: 4px;
}

.pagination-wrapper {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}
</style>
