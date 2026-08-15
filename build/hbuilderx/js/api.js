function _deviceId() {
    let id = ''
    try { id = uni.getStorageSync('push_device_id') || '' } catch(e) {}
    if (!id) {
        id = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = Math.random() * 16 | 0
            const v = c === 'x' ? r : (r & 0x3 | 0x8)
            return v.toString(16)
        })
        try { uni.setStorageSync('push_device_id', id) } catch(e) {}
    }
    return id
}

export function request(method, url, data) {
    return new Promise((resolve, reject) => {
        const header = { 'Content-Type': 'application/json' }
        uni.request({
            url, method, data, header, timeout: 10000,
            success: (res) => {
                if (res.statusCode === 200 && res.data) {
                    resolve(res.data)
                } else {
                    reject(new Error('HTTP ' + res.statusCode))
                }
            },
            fail: (err) => reject(err)
        })
    })
}

export function testPush(baseUrl, key, deviceId) {
    const dev = deviceId || _deviceId()
    const url = (baseUrl || '').trim().replace(/\/+$/, '') + '/api/test-push-self'
    return request('POST', url, { key, device_id: dev })
}

export function checkUpdate(baseUrl, currentVersion, platform) {
    platform = platform || 'android'
    var url = baseUrl + '/api/check-update?platform=' + platform + '&current_version=' + encodeURIComponent(currentVersion)
    return new Promise(function(resolve, reject) {
        uni.request({
            url: url,
            method: 'GET',
            timeout: 10000,
            success: function(res) {
                if (res.statusCode === 200 && res.data && res.data.code === 0) {
                    resolve(res.data.data)
                } else {
                    reject(res.data || { message: '检查更新失败' })
                }
            },
            fail: function(err) { reject(err) }
        })
    })
}
