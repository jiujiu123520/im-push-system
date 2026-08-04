<template>
  <div class="page">
    <el-row :gutter="16">
      <el-col :xs="24" :sm="24" :md="14">
        <el-card shadow="never">
          <template #header>
            <div class="card-header">
              <div class="title">APP 下载 &amp; HBuilderX 源码生成</div>
            </div>
          </template>
          <div v-if="info.download?.apk_download_url || info.download?.ipa_download_url" class="apps">
            <div v-if="info.download?.apk_download_url" class="app-card">
              <div class="badge android">Android</div>
              <div class="row">
                <div class="big-icon"><el-icon :size="34" color="#fff"><Cellphone /></el-icon></div>
                <div class="info">
                  <div class="name">{{ 'Android App' }}
                    <el-tag v-if="info.download?.apk_version" size="small" type="primary" effect="plain" style="margin-left:6px">v{{ info.download?.apk_version }}</el-tag>
                  </div>
                  <div class="sub">
                  </div>
                </div>
              </div>
              <div class="btns">
                <el-button type="primary" @click="downloadApk">
                  <el-icon><Download /></el-icon> 下载 APK
                </el-button>
              </div>
            </div>
            <div v-if="info.download?.ipa_download_url" class="app-card ios">
              <div class="badge ios">iOS</div>
              <div class="row">
                <div class="big-icon ios"><el-icon :size="34" color="#fff"><Iphone /></el-icon></div>
                <div class="info">
                  <div class="name">{{ 'iOS App' }}
                    <el-tag v-if="info.download?.ipa_version" size="small" type="success" effect="plain" style="margin-left:6px">v{{ info.download?.ipa_version }}</el-tag>
                  </div>
                  <div class="sub">需企业签名或自行编译</div>
                </div>
              </div>
            </div>
          </div>
          <el-empty v-else description="管理员暂未配置分发版本，可使用下方 HBuilderX 自行生成定制 APP" />
        </el-card>

        <el-card shadow="never" style="margin-top:16px">
          <template #header>
            <div class="card-header">
              <div class="title">生成 HBuilderX 定制源码包</div>
              <el-tag v-if="info.download?.user_hbx_enabled" type="success" effect="light">功能已启用</el-tag>
            </div>
          </template>
          <el-alert type="info" :closable="false" show-icon style="margin-bottom:16px"
                    title="生成后下载 ZIP，解压后使用 HBuilderX 打开，连接手机即可云打包或离线打包 APK。" />
          <el-form :model="hb" label-width="120px" label-position="right">
            <el-row :gutter="16">
              <el-col :xs="24" :sm="24" :md="12">
                <el-form-item label="APP 名称">
                  <el-input v-model="hb.app_name" placeholder="如：我的推送" maxlength="20" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="24" :md="12">
                <el-form-item label="包名">
                  <el-input v-model="hb.package_id" placeholder="如：com.example.push" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-button type="primary" :loading="genLoading" @click="generate">
              <el-icon><MagicStick /></el-icon> 生成并下载 ZIP
            </el-button>
          </el-form>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="24" :md="10">
        <el-card shadow="never">
          <template #header><div class="title">扫码下载</div></template>
          <div class="qr-wrap">
            <div v-if="qrUrl" class="qr-info">
              <el-button type="primary" @click="openUrl(qrUrl)">下载 APK</el-button>
            </div>
            <div v-else class="qr-info">管理员未配置下载链接</div>
          </div>
        </el-card>
        <el-card shadow="never" style="margin-top:16px">
          <template #header><div class="title">操作说明</div></template>
          <ol class="howto">
            <li>① 安装 HBuilderX（HBuilderX.4+），登录账号</li>
            <li>② 点击上方按钮，填写参数，生成 ZIP 并解压</li>
            <li>③ 在 HBuilderX 中：文件 → 打开目录 → 选择解压后的文件夹</li>
            <li>④ 菜单：发行 → 原生 App-云打包，或 运行 → 运行到手机或模拟器</li>
            <li>⑤ 打包完成后安装到手机，填入 Push Key 使用。</li>
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

const info = ref<any>({})
const qrUrl = ref('')
const genLoading = ref(false)

const hb = reactive<any>({
  app_name: 'Push 用户端', package_id: 'com.push.user'
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
  genLoading.value = true
  try {
    // Backend returns ZIP binary, use fetch to get blob
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
    a.download = `hbuilderx-${hb.package_id}.zip`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    ElMessage.success('生成成功，正在下载…')
  } catch (e: any) { ElMessage.error(e?.message || '生成失败')
  } finally { genLoading.value = false }
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
.qr-wrap { display: flex; flex-direction: column; align-items: center; gap: $space-4;
  padding: $space-5 0; }
.qr-svg {
  width: 220px; height: 220px; border-radius: $radius-md;
  padding: 10px; background: #fff; box-shadow: 0 4px 16px rgba(15,23,42,0.06);
  :deep(svg) { width: 100%; height: 100%; }
}
.qr-sub { color: var(--text-secondary); font-size: $font-size-sm; }
.howto { margin: 0; padding-left: 20px; color: var(--text-regular);
  li { padding: 6px 0; font-size: $font-size-sm; line-height: 1.6; } }
</style>
