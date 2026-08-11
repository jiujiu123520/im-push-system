function request(method, url, data, token) {
    return new Promise(function(resolve, reject) {
        var header = { 'Content-Type': 'application/json' }
        if (token) header['Authorization'] = 'Bearer ' + token
        uni.request({
            url: url,
            method: method,
            data: data,
            header: header,
            timeout: 10000,
            success: function(res) {
                if (res.statusCode === 200 && res.data) {
                    resolve(res.data)
                } else {
                    reject(new Error('HTTP ' + res.statusCode))
                }
            },
            fail: function(err) {
                reject(err)
            }
        })
    })
}

function login(baseUrl, email, password) {
    return request('POST', baseUrl + '/api/user/login', { email: email, password: password })
}

function testPush(baseUrl, key) {
    return request('POST', baseUrl + '/api/user/test-push', { key: key })
}

function fetchMessages(baseUrl, key, page) {
    return request('GET', baseUrl + '/api/user/messages?key=' + key + '&page=' + (page || 1))
}

module.exports = {
    request: request,
    login: login,
    testPush: testPush,
    fetchMessages: fetchMessages
}
