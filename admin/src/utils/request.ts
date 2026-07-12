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

    // 业务码处理
    if (res.code !== 0 && res.code !== 200) {
      ElMessage.error(res.message || '请求异常')
      // 401: token 失效 / 未登录
      if (res.code === 401 || res.code === 40101) {
        handleRelogin()
      }
      return Promise.reject(new Error(res.message || 'Error'))
    }
    return res
  },
  (error) => {
    NProgress.done()
    const status = error.response?.status
    let msg = error.message || '网络异常'

    if (status === 401) {
      handleRelogin()
      msg = '登录状态已失效，请重新登录'
    } else if (status === 403) {
      msg = '没有权限访问该资源'
    } else if (status === 404) {
      msg = '请求的资源不存在'
    } else if (status === 500) {
      msg = '服务器内部错误'
    } else if (error.code === 'ECONNABORTED') {
      msg = '请求超时，请稍后重试'
    }

    ElMessage.error(msg)
    return Promise.reject(error)
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
