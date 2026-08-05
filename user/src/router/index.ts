import { createRouter, createWebHashHistory, type RouteRecordRaw } from 'vue-router'
import NProgress from 'nprogress'
import { ElMessage } from 'element-plus'
import { getToken } from '@/utils/auth'
import { setSilentAuth } from '@/utils/request'
import { useUserStore } from '@/stores/user'

const Layout = () => import('@/layout/index.vue')

export const constantRoutes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/login/index.vue'),
    meta: { title: '登录', hidden: true }
  },
  {
    path: '/register',
    name: 'Register',
    component: () => import('@/views/register/index.vue'),
    meta: { title: '用户注册', hidden: true }
  },
  {
    path: '/forgot-password',
    name: 'ForgotPassword',
    component: () => import('@/views/forgot-password/index.vue'),
    meta: { title: '找回密码', hidden: true }
  },
  {
    path: '/404',
    name: 'NotFound',
    component: () => import('@/views/error/404.vue'),
    meta: { title: '404', hidden: true }
  },
  {
    path: '/',
    component: Layout,
    redirect: '/dashboard',
    meta: { hidden: true },
    children: [
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: () => import('@/views/dashboard/index.vue'),
        meta: { title: '首页', icon: 'Odometer', affix: true, cache: true }
      }
    ]
  }
]

// 用户端异步路由（所有登录用户均可见，仅区分功能）
export const asyncRoutes: RouteRecordRaw[] = [
  {
    path: '/push',
    component: Layout,
    meta: { title: '推送消息', icon: 'Promotion' },
    children: [
      {
        path: '',
        name: 'PushSend',
        component: () => import('@/views/push/index.vue'),
        meta: { title: '推送消息', icon: 'Promotion', cache: true }
      }
    ]
  },
  {
    path: '/push-logs',
    component: Layout,
    meta: { title: '推送记录', icon: 'Document' },
    children: [
      {
        path: '',
        name: 'PushLogs',
        component: () => import('@/views/push-logs/index.vue'),
        meta: { title: '推送记录', icon: 'Document', cache: true }
      }
    ]
  },
  {
    path: '/keys',
    component: Layout,
    meta: { title: 'Key 管理', icon: 'Key' },
    children: [
      {
        path: '',
        name: 'Keys',
        component: () => import('@/views/keys/index.vue'),
        meta: { title: 'Key 管理', icon: 'Key', cache: true }
      }
    ]
  },
  {
    path: '/devices',
    component: Layout,
    meta: { title: '设备管理', icon: 'Cellphone' },
    children: [
      {
        path: '',
        name: 'Devices',
        component: () => import('@/views/devices/index.vue'),
        meta: { title: '设备管理', icon: 'Cellphone', cache: true }
      }
    ]
  },
  {
    path: '/docs',
    component: Layout,
    meta: { title: 'API 文档', icon: 'Reading' },
    children: [
      {
        path: '',
        name: 'Docs',
        component: () => import('@/views/docs/index.vue'),
        meta: { title: '我的 API 文档', icon: 'Reading', cache: true }
      }
    ]
  },
  {
    path: '/app',
    component: Layout,
    meta: { title: 'APP 下载', icon: 'Download' },
    children: [
      {
        path: '',
        name: 'AppDownload',
        component: () => import('@/views/app/index.vue'),
        meta: { title: 'APP 下载/生成', icon: 'Download', cache: true }
      }
    ]
  },
  {
    path: '/notices',
    component: Layout,
    meta: { title: '系统公告', icon: 'Bell' },
    children: [
      {
        path: '',
        name: 'Notices',
        component: () => import('@/views/notices/index.vue'),
        meta: { title: '系统公告', icon: 'Bell', cache: true }
      }
    ]
  },
  {
    path: '/profile',
    component: Layout,
    meta: { title: '个人中心', icon: 'User' },
    children: [
      {
        path: '',
        name: 'Profile',
        component: () => import('@/views/profile/index.vue'),
        meta: { title: '个人中心', icon: 'User', cache: true }
      }
    ]
  }
]

// 404 通配符必须在所有其他路由之后注册，确保精确路由优先匹配
const WILDCARD_ROUTE: RouteRecordRaw = {
  path: '/:pathMatch(.*)*',
  redirect: '/404',
  meta: { hidden: true }
}

const router = createRouter({
  history: createWebHashHistory(),
  routes: constantRoutes,
  scrollBehavior: () => ({ top: 0 })
})

const whiteList = ['/login', '/register', '/forgot-password', '/404']

let routesGenerated = false
let removeWildcard: (() => void) | null = null

router.beforeEach(async (to, _from, next) => {
  NProgress.start()
  document.title = to.meta.title
    ? `${to.meta.title} · Push 用户中心`
    : 'Push · 用户中心'

  const hasToken = getToken()

  if (hasToken) {
    if (to.path === '/login') {
      next({ path: '/' })
      return
    }

    if (routesGenerated) {
      next()
      return
    }

    try {
      const userStore = useUserStore()
      if (!userStore.userInfo) {
        // 静默模式：401 时不弹窗，由路由守卫自行处理跳转
        setSilentAuth(true)
        try {
          await userStore.getUserInfo()
        } finally {
          setSilentAuth(false)
        }
      }
      // 用户端路由简单：统一把 asyncRoutes 全量 addRoute
      asyncRoutes.forEach((route) => {
        router.addRoute(route)
      })
      // 404 通配符必须最后注册，避免抢占精确路由匹配
      removeWildcard = router.addRoute(WILDCARD_ROUTE)
      routesGenerated = true
      next({ path: to.path, query: to.query, hash: to.hash, replace: true })
    } catch (err) {
      routesGenerated = false
      useUserStore().resetState()
      next(`/login?redirect=${to.path}`)
    }
    return
  }

  if (whiteList.includes(to.path)) {
    next()
  } else {
    next(`/login?redirect=${to.path}`)
  }
})

router.afterEach(() => {
  NProgress.done()
})

export function resetRouter() {
  routesGenerated = false
  if (removeWildcard) {
    removeWildcard()
    removeWildcard = null
  }
  const names = new Set(constantRoutes.map((r) => r.name))
  router.getRoutes().forEach((r) => {
    if (r.name && !names.has(r.name)) {
      router.removeRoute(r.name)
    }
  })
}

export default router
