import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import { resolve } from 'path'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd())
  return {
    // 使用相对路径，一次构建即可适配任意管理后台路径（如 /admin/、/admin-9f7k2p8x/）
    // 修改管理后台路径只需更新 Nginx location + 后端 settings_paths.admin_path，无需重新构建前端
    // Vue Router 使用 createWebHashHistory()，自动适配当前路径，无尾斜杠问题
    base: './',
    plugins: [
      vue(),
      AutoImport({
        imports: ['vue', 'vue-router', 'pinia', '@vueuse/core'],
        resolvers: [ElementPlusResolver()],
        dts: 'src/types/auto-imports.d.ts',
        eslintrc: { enabled: false }
      }),
      Components({
        resolvers: [ElementPlusResolver({ importStyle: 'sass' })],
        dts: 'src/types/components.d.ts'
      })
    ],
    resolve: {
      alias: {
        '@': resolve(__dirname, 'src')
      }
    },
    css: {
      preprocessorOptions: {
        scss: {
          // 为组件 <style> 块自动注入变量与混入；跳过 styles 目录核心文件以避免自引用
          additionalData(source: string, fp: string) {
            if (/[\\/]styles[\\/](variables|mixins|reset|dark|transition|index)\.scss$/.test(fp)) {
              return source
            }
            return `@use "@/styles/variables.scss" as *;\n@use "@/styles/mixins.scss" as *;\n${source}`
          },
          // 使用 legacy API 确保兼容性（modern-compiler 需 sass 1.77+）
          api: 'legacy'
        }
      }
    },
    server: {
      host: '0.0.0.0',
      port: 5173,
      open: true,
      proxy: {
        '/api': {
          target: 'http://localhost:9501',
          changeOrigin: true,
          rewrite: (path) => path.replace(/^\/api/, '')
        },
        '/ws': {
          target: 'ws://localhost:9502',
          ws: true,
          changeOrigin: true
        }
      }
    },
    build: {
      target: 'es2015',
      outDir: 'dist',
      sourcemap: false,
      chunkSizeWarningLimit: 1500,
      rollupOptions: {
        output: {
          chunkFileNames: 'assets/js/[name]-[hash].js',
          entryFileNames: 'assets/js/[name]-[hash].js',
          assetFileNames: 'assets/[ext]/[name]-[hash].[ext]',
          manualChunks: {
            vue: ['vue', 'vue-router', 'pinia'],
            element: ['element-plus', '@element-plus/icons-vue'],
            echarts: ['echarts', 'vue-echarts']
          }
        }
      }
    }
  }
})
