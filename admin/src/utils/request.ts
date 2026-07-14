import axios, {
  type AxiosInstance,
  type AxiosRequestConfig,
  type InternalAxiosRequestConfig
} from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import NProgress from 'nprogress'
import { getToken } from './auth'

NProgress.configure({ showSpinner: false, trickleSpeed: 100 })

export interface ApiResponse<T = any> {
  code: number
  message: string
  data: T
  [key: string]: any
}

const service: AxiosInstance = axios.create({
  baseURL: '/api',
  timeout: 15000,
  headers: { 'Content-Type': 'application/json;charset=utf-8' }
})

// 是否正在刷新 token，避免重复弹框
let isReloginShown = false

// 请求拦截器
service.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    NProgress.start()
    const token = getToken()
    if (token && config.headers) {
      config.headers['Authorization'] = `Bearer ${token}`
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

    // 二进制流直接返回
    if (response.config.responseType === 'blob') {
      return response
    }

    // 业务码处理：code === 0 表示成功，其余均为失败
    if (res.code !== 0) {
      // 401: token 失效 / 未登录 — 静默处理，由 handleRelogin 统一弹框
      if (res.code === 401) {
        handleRelogin()
        return Promise.reject(new Error(res.message || '登录已失效'))
      }
      // 登录接口失败时不在此处弹错误（避免与 login/index.vue 双重提示），
      // 其他接口在此统一弹出
      const isLoginApi = response.config.url?.includes('/admin/login')
      if (!isLoginApi) {
        ElMessage.error(res.message || '请求异常')
      }
      return Promise.reject(new Error(res.message || 'Error'))
    }
    return res as unknown as typeof response
  },
  (error) => {
    NProgress.done()
    const status = error.response?.status
    // 尝试从响应体中取出后端返回的 message
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

    // 登录接口的错误由 login/index.vue 统一提示，避免双重弹框
    const isLoginApi = error.config?.url?.includes('/admin/login')
    if (status !== 401 && !isLoginApi) {
      ElMessage.error(msg)
    }
    return Promise.reject(new Error(msg))
  }
)

// 处理重新登录
function handleRelogin() {
  if (isReloginShown) return
  isReloginShown = true
  ElMessageBox.confirm('登录状态已失效，请重新登录', '提示', {
    confirmButtonText: '重新登录',
    cancelButtonText: '取消',
    type: 'warning'
  })
    .then(() => {
      // 动态导入避免循环依赖
      import('@/stores/user').then(({ useUserStore }) => {
        const userStore = useUserStore()
        userStore.resetState()
        location.href = '/#/login'
      })
    })
    .catch(() => {
      // 用户点"取消"也清除 Token 并跳转登录页，避免死循环
      import('@/stores/user').then(({ useUserStore }) => {
        const userStore = useUserStore()
        userStore.resetState()
        location.href = '/#/login'
      })
    })
    .finally(() => {
      isReloginShown = false
    })
}

// 封装请求方法
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
