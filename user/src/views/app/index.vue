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
          <div v-if="info.apk_name || info.ipa_name" class="apps">
            <div v-if="info.apk_download_url" class="app-card">
              <div class="badge android">Android</div>
              <div class="row">
                <div class="big-icon"><el-icon :size="34" color="#fff"><Cellphone /></el-icon></div>
                <div class="info">
                  <div class="name">{{ info.apk_name || 'Push Android App' }}
                    <el-tag v-if="info.apk_version" size="small" type="primary" effect="plain" style="margin-left:6px">v{{ info.apk_version }}</el-tag>
                  </div>
                  <div class="sub">
                    <span v-if="info.apk_size">大小: {{ info.apk_size }}</span>
                    <span v-if="info.apk_updated_at"> · 更新于: {{ info.apk_updated_at }}</span>
                  </div>
                </div>
              </div>
              <div class="btns">
                <el-button type="primary" @click="downloadApk">
                  <el-icon><Download /></el-icon> 下载 APK
                </el-button>
              </div>
            </div>
            <div v-if="info.ipa_name" class="app-card ios">
              <div class="badge ios">iOS</div>
              <div class="row">
                <div class="big-icon ios"><el-icon :size="34" color="#fff"><Iphone /></el-icon></div>
                <div class="info">
                  <div class="name">{{ info.ipa_name }}
                    <el-tag v-if="info.ipa_version" size="small" type="success" effect="plain" style="margin-left:6px">v{{ info.ipa_version }}</el-tag>
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
              <el-tag v-if="info.hbuilderx_enabled" type="success" effect="light">功能已启用</el-tag>
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
                  <el-input v-model="hb.package_name" placeholder="如：com.example.push" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="24" :md="12">
                <el-form-item label="版本名">
                  <el-input v-model="hb.version_name" placeholder="如：1.0.0" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="24" :md="12">
                <el-form-item label="版本号">
                  <el-input-number v-model="hb.version_code" :min="1" :max="99999" />
                </el-form-item>
              </el-col>
              <el-col :span="24">
                <el-form-item label="APP 描述">
                  <el-input v-model="hb.app_description" type="textarea" :rows="2" maxlength="120" show-word-limit />
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
            <div v-html="qrSvg || placeholderSvg" class="qr-svg"></div>
            <div class="qr-sub">
              <div v-if="qrUrl">扫码或 <el-button link type="primary" @click="openUrl(qrUrl)">点击下载</el-button></div>
              <div v-else>管理员未配置下载链接</div>
            </div>
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
import { onMounted, reactive, ref, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { Cellphone, Iphone, Download, MagicStick } from '@element-plus/icons-vue'
import { getAppInfoApi, getAppDownloadQrApi, generateHBuilderXApi } from '@/api/app'
import type { AppInfo, HBuilderXGenerateParams } from '@/api/types'

const info = ref<AppInfo>({})
const qrSvg = ref('')
const qrUrl = ref('')
const genLoading = ref(false)

const hb = reactive<HBuilderXGenerateParams>({
  app_name: 'Push 用户端', package_name: 'com.push.user',
  app_description: '自定义推送用户端',
  version_name: '1.0.0', version_code: 1
})

const placeholderSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="220" height="220" viewBox="0 0 220 220">
  <rect width="220" height="220" fill="#f1f5f9" rx="10"/>
  <text x="110" y="118" text-anchor="middle" fill="#94a3b8" font-size="14" font-family="sans-serif">暂无二维码</text>
</svg>`

async function loadInfo() {
  try {
    const r = await getAppInfoApi(); info.value = r.data || {}
  } catch {}
  try {
    const r2 = await getAppDownloadQrApi()
    qrSvg.value = r2.data?.qr_svg || ''
    qrUrl.value = r2.data?.download_url || ''
  } catch {}
}
function downloadApk() {
  if (info.value.apk_download_url) window.open(info.value.apk_download_url, '_blank')
  else ElMessage.warning('管理员尚未配置 APK 下载地址')
}
function openUrl(url: string) {
  window.open(url, '_blank')
}
async function generate() {
  if (!hb.app_name.trim()) return ElMessage.warning('请填写 APP 名称')
  if (!/^[a-zA-Z][a-zA-Z0-9_.]*$/.test(hb.package_name)) return ElMessage.warning('包名格式错误，需字母开头，仅允许字母数字下划线点')
  genLoading.value = true
  try {
    const r = await generateHBuilderXApi({ ...hb })
    if (r.data?.download_url) {
      ElMessage.success('生成成功，正在下载…')
      setTimeout(() => window.open(r.data!.download_url, '_blank'), 200)
    } else {
      ElMessage.success('生成成功，请从下载列表获取')
    }
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
