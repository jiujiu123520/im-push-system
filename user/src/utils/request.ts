import axios, {
  type AxiosInstance,
  type AxiosRequestConfig,
  type InternalAxiosRequestConfig
} from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import NProgress from 'nprogress'
import { getToken, removeToken } from './auth'

NProgress.configure({ showSpinner: false, trickleSpeed: 100 })

export interface ApiResponse<T = any> {
  code: number
  message: string
  data: T
  [key: string]: any
}

// 用户端 baseURL = /api，通过 Nginx rewrite 后：
//   /api/user-api/* -> /user-api/* （用户私有接口，走 UserApiAuth）
//   /api/auth/*     -> /auth/*     （公开认证接口：登录/注册/改密）
//   /api/captcha/*  -> /captcha/*  （公开验证码接口）
const service: AxiosInstance = axios.create({
  baseURL: '/api',
  timeout: 15000,
  headers: { 'Content-Type': 'application/json;charset=utf-8' }
})

let isReloginTriggered = false

// 静默认证标志：路由守卫调用 getUserInfo() 时设为 true，
// 401 时不弹 ElMessageBox，由路由守卫自行处理跳转
let isSilentAuth = false

// 公开接口白名单：不需要 token，也不受 isReloginTriggered 拦截
const PUBLIC_URLS = ['/captcha', '/auth/login', '/auth/register', '/auth/send-code',
  '/auth/reset-password', '/auth/reset-password-by-qq', '/auth/security-config']

// 对外暴露：重置登录重入标志（登录页挂载/刷新验证码时调用）
export function resetReloginFlag() {
  isReloginTriggered = false
}

// 对外暴露：设置静默认证标志（路由守卫 getUserInfo 前调用）
export function setSilentAuth(v: boolean) {
  isSilentAuth = v
}

// 请求拦截器
service.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    const url = config.url || ''
    const isPublic = PUBLIC_URLS.some((u) => url.includes(u))
    // 访问公开接口（登录/验证码/注册）时：自动解除 isReloginTriggered 锁，
    // 避免 401 → 跳登录页 → 验证码/登录请求被自己的拦截器拒掉 的死循环
    if (isPublic && isReloginTriggered) {
      isReloginTriggered = false
    }
    if (isReloginTriggered && !isPublic) {
      return Promise.reject(new Error('正在重新登录'))
    }
    NProgress.start()
    const token = getToken()
    if (token && config.headers) {
      config.headers['Authorization'] = `Bearer ${token}`
    }
    if (config.data instanceof FormData && config.headers) {
      delete config.headers['Content-Type']
    }
    return config
  },
  (error) => {
    NProgress.done()
    return Promise.reject(error)
  }
)

// 响应拦截器
service.interceptors.response.use(
  (response) => {
    NProgress.done()
    const res = response.data as ApiResponse
    if (response.config.responseType === 'blob') {
      return response
    }
    if (res.code !== 0) {
      if (res.code === 401) {
        handleRelogin()
        return Promise.reject(new Error(res.message || '登录已失效'))
      }
      // 登录/注册接口错误由页面自己提示
      const skipUrls = ['/auth/login', '/auth/register', '/auth/send-code',
                        '/auth/reset-password', '/auth/reset-password-by-qq']
      const isAuthApi = skipUrls.some((u) => response.config.url?.includes(u))
      if (!isAuthApi) {
        ElMessage.error(res.message || '请求异常')
      }
      return Promise.reject(new Error(res.message || 'Error'))
    }
    return res as unknown as typeof response
  },
  (error) => {
    NProgress.done()
    const status = error.response?.status
    const backendMsg = error.response?.data?.message
    let msg = backendMsg || error.message || '网络异常'

    if (status === 401) {
      handleRelogin()
      return Promise.reject(new Error(msg || '登录状态已失效'))
    } else if (status === 403) {
      msg = '没有权限访问该资源'
    } else if (status === 404) {
      msg = '请求的资源不存在'
    } else if (status === 500) {
      msg = '服务器内部错误'
    } else if (error.code === 'ECONNABORTED') {
      msg = '请求超时，请稍后重试'
    }

    if (status !== 401) {
      ElMessage.error(msg)
    }
    return Promise.reject(new Error(msg))
  }
)

function handleRelogin() {
  if (isReloginTriggered) return
  isReloginTriggered = true
  NProgress.done()
  try {
    ElMessage.closeAll()
    ElMessageBox.close()
  } catch {}
  removeToken()

  // 静默认证模式（路由守卫 getUserInfo 401）：不弹窗，由路由守卫自行跳转
  if (isSilentAuth) {
    return
  }

  ElMessageBox.confirm('登录状态已失效，请重新登录', '提示', {
    confirmButtonText: '重新登录',
    cancelButtonText: '取消',
    type: 'warning',
    closeOnClickModal: false,
    closeOnPressEscape: false
  })
    .then(() => {
      isReloginTriggered = false
      setTimeout(() => {
        location.href = '#/login'
      }, 50)
    })
    .catch(() => {
      isReloginTriggered = false
      setTimeout(() => {
        location.href = '#/login'
      }, 50)
    })
}

export function request<T = any>(config: AxiosRequestConfig): Promise<ApiResponse<T>> {
  return service(config) as unknown as Promise<ApiResponse<T>>
}

export function get<T = any>(
  url: string,
  params?: Record<string, any>,
  config?: AxiosRequestConfig
): Promise<ApiResponse<T>> {
  return request<T>({ method: 'get', url, params, ...config })
}

export function post<T = any>(
  url: string,
  data?: Record<string, any>,
  config?: AxiosRequestConfig
): Promise<ApiResponse<T>> {
  return request<T>({ method: 'post', url, data, ...config })
}

export function put<T = any>(
  url: string,
  data?: Record<string, any>,
  config?: AxiosRequestConfig
): Promise<ApiResponse<T>> {
  return request<T>({ method: 'put', url, data, ...config })
}

export function del<T = any>(
  url: string,
  params?: Record<string, any>,
  config?: AxiosRequestConfig
): Promise<ApiResponse<T>> {
  return request<T>({ method: 'delete', url, params, ...config })
}

export default service
