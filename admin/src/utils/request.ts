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

const service: AxiosInstance = axios.create({
  baseURL: '/api',
  timeout: 15000,
  headers: { 'Content-Type': 'application/json;charset=utf-8' }
})

// 是否已触发重新登录（永久锁，防止并发 401 反复弹框 / 重复跳转）
// 触发一次后本页生命周期内不再重复执行，直到用户刷新页面重新登录
let isReloginTriggered = false

// 请求拦截器
service.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    // 已触发重登：阻断后续所有请求（避免继续携带失效 token、避免 NProgress 卡死）
    if (isReloginTriggered) {
      return Promise.reject(new Error('正在重新登录'))
    }
    NProgress.start()
    const token = getToken()
    if (token && config.headers) {
      config.headers['Authorization'] = `Bearer ${token}`
    }
    // FormData 上传时删除默认 Content-Type，让浏览器自动设置带 boundary 的 multipart 头
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

    // 二进制流直接返回
    if (response.config.responseType === 'blob') {
      return response
    }

    // 业务码处理：code === 0 表示成功，其余均为失败
    if (res.code !== 0) {
      // 401: token 失效 / 未登录 — 由 handleRelogin 统一处理
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
  // 单例锁：一旦触发永远不再重复执行
  if (isReloginTriggered) return
  isReloginTriggered = true

  // 1. 立即关闭 NProgress，避免进度条残留导致页面"卡着不能输入"
  NProgress.done()
  // 2. 立即关闭所有已打开的 Element Plus 消息 / 弹框（防止卡在弹框下不能输入）
  try {
    ElMessage.closeAll()
    ElMessageBox.close()
  } catch {}
  // 3. 立即清除 Token
  removeToken()

  // 4. 弹框提示用户（唯一一次），之后立即跳转；取消也直接跳转
  ElMessageBox.confirm('登录状态已失效，请重新登录', '提示', {
    confirmButtonText: '重新登录',
    cancelButtonText: '取消',
    type: 'warning',
    closeOnClickModal: false,
    closeOnPressEscape: false
  })
    .then(() => {
      // 使用 router.replace 而非 location.href，避免全量刷新时的重复跳转
      // 这里延迟一帧执行，确保 ElMessageBox 完全关闭
      setTimeout(() => {
        location.href = '/admin/#/login'
      }, 50)
    })
    .catch(() => {
      setTimeout(() => {
        location.href = '/admin/#/login'
      }, 50)
    })
  // ⚠ 重要：不再 reset isReloginTriggered 为 false
  // 之前的 finally 重置后，并发 401 触发第二次弹框 = 表现为"登录两次"
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
