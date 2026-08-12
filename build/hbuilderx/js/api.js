export function request(method, url, data, token) {
    return new Promise((resolve, reject) => {
        const header = { 'Content-Type': 'application/json' }
        if (token) header['Authorization'] = 'Bearer ' + token
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

export function login(baseUrl, email, password) {
    return request('POST', baseUrl + '/api/user/login', { email, password })
}

export function testPush(baseUrl, key) {
    return request('POST', baseUrl + '/api/user/test-push', { key })
}

export function fetchMessages(baseUrl, key, page) {
    return request('GET', baseUrl + '/api/user/messages?key=' + key + '&page=' + (page || 1))
}
