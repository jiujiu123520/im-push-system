<template>
  <div class="page">
    <el-row :gutter="16">
      <el-col :xs="24" :sm="24" :md="14">
        <!-- APP 下载卡 -->
        <el-card shadow="never" class="download-card">
          <template #header>
            <div class="card-header">
              <div class="title">APP 下载</div>
            </div>
          </template>
          <div v-if="info.download?.apk_download_url || info.download?.ipa_download_url" class="apps">
            <div v-if="info.download?.apk_download_url" class="app-card android">
              <div class="badge">Android</div>
              <div class="row">
                <div class="big-icon"><el-icon :size="34" color="#fff"><Cellphone /></el-icon></div>
                <div class="info">
                  <div class="name">
                    Android App
                    <el-tag v-if="info.download?.apk_version" size="small" type="success" effect="plain" style="margin-left:6px">v{{ info.download?.apk_version }}</el-tag>
                  </div>
                  <div class="sub">点击下方按钮下载 APK 安装包</div>
                </div>
              </div>
              <div class="btns">
                <el-button type="success" @click="downloadApk">
                  <el-icon><Download /></el-icon> 下载 APK
                </el-button>
              </div>
            </div>
            <div v-if="info.download?.ipa_download_url" class="app-card ios">
              <div class="badge ios">iOS</div>
              <div class="row">
                <div class="big-icon ios"><el-icon :size="34" color="#fff"><Iphone /></el-icon></div>
                <div class="info">
                  <div class="name">
                    iOS App
                    <el-tag v-if="info.download?.ipa_version" size="small" type="primary" effect="plain" style="margin-left:6px">v{{ info.download?.ipa_version }}</el-tag>
                  </div>
                  <div class="sub">需企业签名或自行编译</div>
                </div>
              </div>
            </div>
          </div>
          <el-empty v-else description="管理员暂未配置分发版本，可使用下方源码生成定制 APP" />
        </el-card>

        <!-- HBuilderX 源码生成卡 -->
        <el-card shadow="never" class="gen-card">
          <template #header>
            <div class="card-header">
              <div class="title">生成定制 APP 源码包</div>
              <el-tag v-if="info.download?.user_hbx_enabled" type="success" effect="light">功能已启用</el-tag>
            </div>
          </template>

          <!-- 模板选择 -->
          <div class="tpl-section">
            <div class="section-label">选择模板</div>
            <div class="tpl-list">
              <div v-for="tpl in templates" :key="tpl.id"
                   class="tpl-item" :class="{ active: hb.template === tpl.id, disabled: !tpl.available }"
                   @click="tpl.available && (hb.template = tpl.id)">
                <div class="tpl-radio">
                  <span class="radio-outer">
                    <span v-if="hb.template === tpl.id" class="radio-inner"></span>
                  </span>
                </div>
                <div class="tpl-info">
                  <div class="tpl-name">
                    {{ tpl.name }}
                    <el-tag v-if="tpl.id === 'new'" size="small" type="success" effect="plain">推荐</el-tag>
                    <el-tag v-if="!tpl.available" size="small" type="info" effect="plain">未安装</el-tag>
                  </div>
                  <div class="tpl-desc">{{ tpl.description }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- 参数表单 -->
          <el-alert type="info" :closable="false" show-icon class="gen-tip"
                    title="生成后下载 ZIP，解压后使用 HBuilderX 打开，连接手机即可云打包或运行。" />
          <el-form :model="hb" label-width="100px" label-position="right">
            <el-row :gutter="16">
              <el-col :xs="24" :sm="24" :md="12">
                <el-form-item label="APP 名称">
                  <el-input v-model="hb.app_name" placeholder="如：我的推送" maxlength="20" show-word-limit />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="24" :md="12">
                <el-form-item label="包名">
                  <el-input v-model="hb.package_id" placeholder="如：com.example.push" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item>
              <el-button type="primary" :loading="genLoading" @click="generate">
                <el-icon><MagicStick /></el-icon> 生成并下载 ZIP
              </el-button>
            </el-form-item>
          </el-form>
        </el-card>
      </el-col>

      <el-col :xs="24" :sm="24" :md="10">
        <!-- 扫码下载 -->
        <el-card shadow="never">
          <template #header><div class="title">扫码下载</div></template>
          <div class="qr-wrap">
            <div v-if="qrUrl" class="qr-info">
              <el-button type="primary" @click="openUrl(qrUrl)">下载 APK</el-button>
            </div>
            <div v-else class="qr-info">管理员未配置下载链接</div>
          </div>
        </el-card>

        <!-- 操作说明 -->
        <el-card shadow="never" style="margin-top:16px">
          <template #header><div class="title">操作说明</div></template>
          <ol class="howto">
            <li>① 安装 HBuilderX（4.0+），登录账号</li>
            <li>② 选择模板，填写 APP 名称和包名，生成 ZIP 并解压</li>
            <li>③ 在 HBuilderX 中：文件 → 打开目录 → 选择解压后的文件夹</li>
            <li>④ 菜单：发行 → 原生 App-云打包，或 运行 → 运行到手机或模拟器</li>
            <li>⑤ 打包完成后安装到手机，填入 Push Key 即可使用</li>
          </ol>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Cellphone, Iphone, Download, MagicStick } from '@element-plus/icons-vue'
import { getAppInfoApi, getAppDownloadQrApi, generateHBuilderXApi } from '@/api/app'
import { getToken } from '@/utils/auth'
import type { AppInfo, HBuilderXGenerateParams } from '@/api/types'

interface Template {
  id: string
  name: string
  description: string
  available: boolean
}

const info = ref<any>({})
const qrUrl = ref('')
const genLoading = ref(false)

// 模板列表
const templates = ref<Template[]>([
  { id: 'new', name: '新版模板', description: '推荐使用，基于最新 uni-app 架构，UI 更美观，性能更好', available: true },
  { id: 'old', name: '旧版模板', description: '兼容旧版 APP 源码，适合已使用旧版模板的用户', available: false },
])

const hb = reactive<any>({
  app_name: 'Push 用户端',
  package_id: 'com.push.user',
  template: 'new'
})

async function loadInfo() {
  try {
    const r = await getAppInfoApi(); info.value = r.data || {}
  } catch {}
  try {
    const r2 = await getAppDownloadQrApi()
    qrUrl.value = r2.data?.apk_url || ''
  } catch {}
}

function downloadApk() {
  if (info.value.download?.apk_download_url) window.open(info.value.download?.apk_download_url, '_blank')
  else ElMessage.warning('管理员尚未配置 APK 下载地址')
}

function openUrl(url: string) {
  window.open(url, '_blank')
}

async function generate() {
  if (!hb.app_name.trim()) return ElMessage.warning('请填写 APP 名称')
  if (!/^[a-zA-Z][a-zA-Z0-9_.]*$/.test(hb.package_id)) return ElMessage.warning('包名格式错误，需字母开头，仅允许字母数字下划线点')
  if (!hb.template) return ElMessage.warning('请选择模板')

  const selectedTpl = templates.value.find(t => t.id === hb.template)
  if (selectedTpl && !selectedTpl.available) return ElMessage.warning('该模板未安装，请选择其他模板')

  genLoading.value = true
  try {
    const token = getToken() || ''
    const resp = await fetch('/api/user-api/app/hbuilderx/generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ ...hb })
    })
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}))
      throw new Error(err.message || `HTTP ${resp.status}`)
    }
    const blob = await resp.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `hbuilderx-${hb.template}-${hb.package_id}.zip`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    ElMessage.success('生成成功，正在下载…')
  } catch (e: any) {
    ElMessage.error(e?.message || '生成失败')
  } finally {
    genLoading.value = false
  }
}

onMounted(loadInfo)
</script>

<style lang="scss" scoped>
.title { font-weight: 600; font-size: $font-size-lg; }
.card-header { display: flex; align-items: center; justify-content: space-between; }

.apps { display: flex; flex-direction: column; gap: $space-4; }
.app-card {
  position: relative; padding: $space-5; border-radius: $radius-lg;
  background: linear-gradient(135deg, #f0fdf4, #ecfeff);
  border: 1px solid #bbf7d0;
  &.ios { background: linear-gradient(135deg,#eff6ff,#eef2ff); border-color:#bfdbfe; }
}
.badge {
  position: absolute; top: 12px; right: 12px;
  font-size: $font-size-xs; padding: 2px 8px; border-radius: 999px;
  background: #22c55e; color: #fff;
  &.ios { background: #0ea5e9; }
}
.row { display: flex; align-items: center; gap: $space-4; }
.big-icon {
  width: 56px; height: 56px; border-radius: 14px;
  background: linear-gradient(135deg,#22c55e,#16a34a);
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 8px 20px rgba(34,197,94,0.28);
  &.ios { background: linear-gradient(135deg,#0ea5e9,#6366f1); box-shadow: 0 8px 20px rgba(14,165,233,0.28); }
}
.info { flex: 1; min-width: 0;
  .name { font-weight: 600; font-size: $font-size-lg; color: var(--text-primary); }
  .sub { margin-top: 4px; font-size: $font-size-xs; color: var(--text-secondary); }
}
.btns { margin-top: $space-4; }

// 模板选择区
.tpl-section {
  margin-bottom: $space-4;
  .section-label {
    font-size: $font-size-sm; font-weight: 600; color: var(--text-primary);
    margin-bottom: $space-3;
  }
}
.tpl-list {
  display: flex; flex-direction: column; gap: $space-3;
}
.tpl-item {
  display: flex; align-items: flex-start; gap: $space-3;
  padding: $space-4; border-radius: $radius-md;
  border: 2px solid var(--border-light);
  background: var(--bg-secondary, #f9fafb);
  cursor: pointer; transition: all 0.2s ease;
  &:hover:not(.disabled) {
    border-color: var(--color-primary, #0ea5e9);
    background: rgba(14, 165, 233, 0.04);
  }
  &.active {
    border-color: var(--color-primary, #0ea5e9);
    background: rgba(14, 165, 233, 0.06);
    box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
  }
  &.disabled {
    opacity: 0.55; cursor: not-allowed;
  }
}
.tpl-radio {
  padding-top: 2px;
  .radio-outer {
    display: inline-flex; align-items: center; justify-content: center;
    width: 18px; height: 18px; border-radius: 50%;
    border: 2px solid var(--border-dark, #d1d5db);
    background: #fff; transition: border-color 0.2s;
  }
  .radio-inner {
    width: 10px; height: 10px; border-radius: 50%;
    background: var(--color-primary, #0ea5e9);
  }
  .tpl-item.active & .radio-outer {
    border-color: var(--color-primary, #0ea5e9);
  }
}
.tpl-info {
  flex: 1; min-width: 0;
  .tpl-name {
    font-weight: 600; font-size: $font-size-sm; color: var(--text-primary);
    display: flex; align-items: center; gap: 6px;
  }
  .tpl-desc {
    margin-top: 4px; font-size: $font-size-xs; color: var(--text-secondary);
    line-height: 1.5;
  }
}

.gen-tip { margin-bottom: $space-4; }

.qr-wrap { display: flex; flex-direction: column; align-items: center; gap: $space-4; padding: $space-5 0; }
.qr-info { color: var(--text-secondary); font-size: $font-size-sm; }
.howto { margin: 0; padding-left: 20px; color: var(--text-regular);
  li { padding: 6px 0; font-size: $font-size-sm; line-height: 1.6; } }
</style>
