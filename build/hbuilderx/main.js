import { createSSRApp } from 'vue'
import App from './App.vue'
import { installToastPatch } from './js/toast.js'

// Toast 长文本适配：带图标超 7 字自动降级 icon:'none'，避免“测试推送已发送…”被截断
installToastPatch()

export function createApp() {
    const app = createSSRApp(App)
    return { app }
}
