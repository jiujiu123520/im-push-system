import { createRouter, createWebHashHistory, type RouteRecordRaw } from 'vue-router'
import NProgress from 'nprogress'
import { ElMessage } from 'element-plus'
import { getToken } from '@/utils/auth'
import { useUserStore } from '@/stores/user'
import { usePermissionStore } from '@/stores/permission'

// 布局组件
const Layout = () => import('@/layout/index.vue')
// 通用模块占位组件（已实现，非空占位）
const ModuleView = () => import('@/views/module/index.vue')

// 常量路由 - 无需鉴权
export const constantRoutes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/login/index.vue'),
    meta: { title: '登录', hidden: true }
  },
  {
    path: '/redirect',
    component: Layout,
    meta: { hidden: true },
    children: [
      {
        path: '/redirect/:path(.*)',
        name: 'Redirect',
        component: () => import('@/views/redirect/index.vue'),
        meta: { hidden: true }
      }
    ]
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
    children: [
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: () => import('@/views/dashboard/index.vue'),
        meta: { title: '仪表盘', icon: 'Odometer', affix: true, cache: true }
      }
    ]
  }
]

// 异步路由 - 需鉴权
// 注：多数业务模块统一使用 ModuleView 通用组件呈现；
//     app-build / api-keys / settings 三个模块已实现专属页面
export const asyncRoutes: RouteRecordRaw[] = [
  {
    path: '/users',
    component: Layout,
    meta: { title: '用户管理', icon: 'User', roles: ['admin', 'super_admin'] },
    children: [
      {
        path: '',
        name: 'Users',
        component: ModuleView,
        meta: { title: '用户管理', icon: 'User', cache: true, module: 'users' }
      }
    ]
  },
  {
    path: '/keys',
    component: Layout,
    meta: { title: 'Key管理', icon: 'Key', roles: ['admin', 'super_admin'] },
    children: [
      {
        path: '',
        name: 'Keys',
        component: ModuleView,
        meta: { title: 'Key管理', icon: 'Key', cache: true, module: 'keys' }
      }
    ]
  },
  {
    path: '/devices',
    component: Layout,
    meta: { title: '设备管理', icon: 'Cellphone', roles: ['admin', 'super_admin'] },
    children: [
      {
        path: '',
        name: 'Devices',
        component: ModuleView,
        meta: { title: '设备管理', icon: 'Cellphone', cache: true, module: 'devices' }
      }
    ]
  },
  {
    path: '/push-logs',
    component: Layout,
    meta: { title: '推送记录', icon: 'Promotion', roles: ['admin', 'super_admin'] },
    children: [
      {
        path: '',
        name: 'PushLogs',
        component: ModuleView,
        meta: { title: '推送记录', icon: 'Promotion', cache: true, module: 'push-logs' }
      }
    ]
  },
  {
    path: '/blacklist',
    component: Layout,
    meta: { title: '黑名单管理', icon: 'WarningFilled', roles: ['admin', 'super_admin'] },
    children: [
      {
        path: '',
        name: 'Blacklist',
        component: ModuleView,
        meta: { title: '黑名单管理', icon: 'WarningFilled', cache: true, module: 'blacklist' }
      }
    ]
  },
  {
    path: '/admins',
    component: Layout,
    meta: { title: '管理员管理', icon: 'Avatar', roles: ['super_admin'] },
    children: [
      {
        path: '',
        name: 'Admins',
        component: ModuleView,
        meta: { title: '管理员管理', icon: 'Avatar', cache: true, module: 'admins' }
      }
    ]
  },
  {
    path: '/app-build',
    component: Layout,
    meta: { title: 'APP生成', icon: 'Cellphone', roles: ['admin', 'super_admin'] },
    children: [
      {
        path: '',
        name: 'AppBuild',
        component: () => import('@/views/app-build/index.vue'),
        meta: { title: 'APP生成', icon: 'Cellphone', cache: true, module: 'app-build' }
      }
    ]
  },
  {
    path: '/api-keys',
    component: Layout,
    meta: { title: '开放API管理', icon: 'Connection', roles: ['admin', 'super_admin'] },
    children: [
      {
        path: '',
        name: 'ApiKeys',
        component: () => import('@/views/api-keys/index.vue'),
        meta: { title: '开放API管理', icon: 'Connection', cache: true, module: 'api-keys' }
      }
    ]
  },
  {
    path: '/settings',
    component: Layout,
    meta: { title: '系统设置', icon: 'Setting', roles: ['admin', 'super_admin'] },
    children: [
      {
        path: '',
        name: 'Settings',
        component: () => import('@/views/settings/index.vue'),
        meta: { title: '系统设置', icon: 'Setting', cache: true, module: 'settings' }
      }
    ]
  },
  // 404 兜底
  {
    path: '/:pathMatch(.*)*',
    redirect: '/404',
    meta: { hidden: true }
  }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes: constantRoutes,
  scrollBehavior: () => ({ top: 0 })
})

// 白名单
const whiteList = ['/login', '/404']

// 是否已生成路由
let routesGenerated = false

// 前置守卫
router.beforeEach(async (to, _from, next) => {
  NProgress.start()
  document.title = to.meta.title
    ? `${to.meta.title} · Push 管理后台`
    : 'Push · 即时消息推送管理后台'

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
      const permissionStore = usePermissionStore()

      // 获取用户信息
      if (!userStore.roles.length) {
        await userStore.getUserInfo()
      }

      // 生成路由
      const accessRoutes = permissionStore.generateRoutes(userStore.roles)
      routesGenerated = true

      accessRoutes.forEach((route) => {
        if (route.name && !router.hasRoute(route.name)) {
          router.addRoute(route)
        }
      })

      // 重新跳转以确保动态路由生效
      next({ ...to, replace: true })
    } catch (err) {
      routesGenerated = false
      useUserStore().resetState()
      ElMessage.error(err instanceof Error ? err.message : '路由初始化失败')
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

// 重置路由（登出时调用）
export function resetRouter() {
  const userStore = useUserStore()
  const permissionStore = usePermissionStore()
  routesGenerated = false
  permissionStore.routes.forEach((route) => {
    if (route.name && !constantRoutes.some((c) => c.name === route.name)) {
      router.removeRoute(route.name)
    })
  })
  userStore.roles = []
}

export default router
