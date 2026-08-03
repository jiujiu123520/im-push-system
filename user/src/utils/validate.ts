/**
 * @param {string} path
 * @returns {Boolean}
 */
export function isExternal(path: string): boolean {
  return /^(https?:|mailto:|tel:)/.test(path)
}

/**
 * 验证用户名
 * 4-20位字母、数字、下划线、中文
 */
export function validUsername(username: string): boolean {
  return /^[A-Za-z0-9_\u4e00-\u9fa5]{4,20}$/.test(username)
}

/**
 * 验证手机号（中国大陆）
 */
export function validPhone(phone: string): boolean {
  return /^1[3-9]\d{9}$/.test(phone)
}

/**
 * 验证邮箱
 */
export function validEmail(email: string): boolean {
  return /^[\w.+-]+@[\w-]+\.[\w.-]+$/.test(email)
}

/**
 * 验证 QQ 号
 */
export function validQq(qq: string): boolean {
  return /^[1-9]\d{4,11}$/.test(qq)
}

/**
 * 验证密码强度（6-64位）
 */
export function validPassword(pwd: string): boolean {
  return pwd.length >= 6 && pwd.length <= 64
}

/**
 * 验证 Push Key 名称
 */
export function validKeyName(name: string): boolean {
  return name.length >= 1 && name.length <= 50
}
