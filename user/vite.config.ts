import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import { resolve } from 'path'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd())
  return {
    // 用户端部署路径：与 Nginx 的 location /user/ 对应
    base: '/user/',
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
          additionalData(source: string, fp: string) {
            if (/[\\/]styles[\\/](variables|mixins|reset|index)\.scss$/.test(fp)) {
              return source
            }
            return `@use "@/styles/variables.scss" as *;\n@use "@/styles/mixins.scss" as *;\n${source}`
          },
          api: 'legacy'
        }
      }
    },
    server: {
      host: '0.0.0.0',
      port: 5174,
      open: true,
      proxy: {
        // 用户端 axios.baseURL = /api
        // 管理后台：/api/admin/* → /admin/*
        // 用户端：/api/user-api/* → /user-api/*
        // 公开接口：/api/* → /*（后端路由本身带 /api 前缀，如 /api/push）
        '/api': {
          target: 'http://localhost:9501',
          changeOrigin: true,
          // 统一 rewrite：/api/xxx → /xxx，由后端 Router 区分 /admin /user-api /api
          rewrite: (path) => path.replace(/^\/api/, '')
        },
        '/auth': {
          target: 'http://localhost:9501',
          changeOrigin: true
        },
        '/captcha': {
          target: 'http://localhost:9501',
          changeOrigin: true
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
