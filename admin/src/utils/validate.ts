import type { FormItemRule } from 'element-plus'

// 是否为合法邮箱
export function isEmail(value: string): boolean {
  return /^[\w.-]+@[\w-]+(\.[\w-]+)+$/.test(value)
}

// 是否为合法手机号
export function isPhone(value: string): boolean {
  return /^1[3-9]\d{9}$/.test(value)
}

// 是否为合法 URL
export function isUrl(value: string): boolean {
  try {
    // eslint-disable-next-line no-new
    new URL(value)
    return true
  } catch {
    return false
  }
}

// 是否为合法 IPv4
export function isIPv4(value: string): boolean {
  return /^((25[0-5]|2[0-4]\d|[01]?\d?\d)\.){3}(25[0-5]|2[0-4]\d|[01]?\d?\d)$/.test(value)
}

// 是否为强密码（8-20 位，含字母和数字）
export function isStrongPassword(value: string): boolean {
  return /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*#?&_\-+.]{8,20}$/.test(value)
}

// 通用校验规则工厂
export const validators = {
  required(message = '该项不能为空'): FormItemRule {
    return { required: true, message, trigger: 'blur' }
  },
  email(): FormItemRule {
    return {
      required: true,
      trigger: ['blur', 'change'],
      validator: (_rule, value, callback) => {
        if (!value) return callback(new Error('请输入邮箱'))
        if (!isEmail(value)) return callback(new Error('邮箱格式不正确'))
        callback()
      }
    }
  },
  phone(): FormItemRule {
    return {
      required: true,
      trigger: ['blur', 'change'],
      validator: (_rule, value, callback) => {
        if (!value) return callback(new Error('请输入手机号'))
        if (!isPhone(value)) return callback(new Error('手机号格式不正确'))
        callback()
      }
    }
  },
  password(): FormItemRule {
    return {
      required: true,
      trigger: 'blur',
      validator: (_rule, value, callback) => {
        if (!value) return callback(new Error('请输入密码'))
        if (!isStrongPassword(value)) {
          return callback(new Error('密码 8-20 位，需包含字母和数字'))
        }
        callback()
      }
    }
  },
  length(min: number, max: number, label = '内容'): FormItemRule {
    return {
      trigger: 'blur',
      validator: (_rule, value, callback) => {
        if (value && (value.length < min || value.length > max)) {
          return callback(new Error(`${label}长度需在 ${min}-${max} 之间`))
        }
        callback()
      }
    }
  }
}

// 确认密码校验
export function confirmPassword(getPassword: () => string): FormItemRule {
  return {
    trigger: 'blur',
    validator: (_rule, value, callback) => {
      if (!value) return callback(new Error('请再次输入密码'))
      if (value !== getPassword()) return callback(new Error('两次输入的密码不一致'))
      callback()
    }
  }
}
