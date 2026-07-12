# IM Push 即时消息推送系统 - API 对接文档

## 目录

- [概述](#概述)
- [基础信息](#基础信息)
- [统一响应格式](#统一响应格式)
- [开放推送 API（X-Api-Key 鉴权）](#开放推送-apix-api-key-鉴权)
  - [消息推送接口](#消息推送接口)
- [用户认证接口](#用户认证接口)
  - [获取图形验证码](#获取图形验证码)
  - [发送短信/邮箱验证码](#发送短信邮箱验证码)
  - [用户注册](#用户注册)
  - [用户登录](#用户登录)
- [管理员接口](#管理员接口)
  - [管理员登录](#管理员登录)
  - [管理员登出](#管理员登出)
  - [获取当前管理员信息](#获取当前管理员信息)
  - [管理员列表](#管理员列表)
  - [创建管理员](#创建管理员)
  - [更新管理员](#更新管理员)
  - [删除管理员](#删除管理员)
  - [修改自己的密码](#修改自己的密码)
  - [操作日志](#操作日志)
- [API Key 管理接口](#api-key-管理接口)
  - [API Key 列表](#api-key-列表)
  - [创建 API Key](#创建-api-key)
  - [更新 API Key](#更新-api-key)
  - [删除 API Key](#删除-api-key)
- [Push Key 管理接口](#push-key-管理接口)
  - [Push Key 列表](#push-key-列表)
  - [Push Key 详情](#push-key-详情)
  - [创建 Push Key](#创建-push-key)
  - [更新 Push Key](#更新-push-key)
  - [删除 Push Key](#删除-push-key)
  - [更新 Push Key 状态](#更新-push-key-状态)
- [设备管理接口](#设备管理接口)
  - [设备列表](#设备列表)
  - [设备详情](#设备详情)
- [黑名单管理接口](#黑名单管理接口)
  - [黑名单列表](#黑名单列表)
  - [添加黑名单](#添加黑名单)
  - [删除黑名单](#删除黑名单)
- [消息记录接口](#消息记录接口)
  - [消息记录列表](#消息记录列表)
  - [导出消息记录](#导出消息记录)
  - [推送日志列表](#推送日志列表)
  - [导出推送日志](#导出推送日志)
- [仪表盘统计接口](#仪表盘统计接口)
  - [概览数据](#概览数据)
  - [在线设备趋势](#在线设备趋势)
  - [今日推送量](#今日推送量)
  - [Key 状态分布](#key-状态分布)
  - [设备平台分布](#设备平台分布)
  - [最新推送记录](#最新推送记录)
- [测试调试接口](#测试调试接口)
  - [管理员测试推送](#管理员测试推送)
  - [检查设备在线状态](#检查设备在线状态)
  - [APP 端自测推送](#app-端自测推送)
- [系统设置接口](#系统设置接口)
  - [获取邮件配置](#获取邮件配置)
  - [保存邮件配置](#保存邮件配置)
  - [测试邮件配置](#测试邮件配置)
- [WebSocket 接入协议](#websocket-接入协议)
  - [连接地址](#连接地址)
  - [鉴权消息（auth）](#鉴权消息auth)
  - [心跳（ping/pong）](#心跳pingpong)
  - [推送消息格式](#推送消息格式)
- [错误码说明](#错误码说明)

---

## 概述

本文档为 IM Push 即时消息推送系统的完整 API 对接文档，包含：
- 开放推送 API（第三方系统对接）
- 用户端 API（APP/H5 使用）
- 管理后台 API
- WebSocket 接入协议

---

## 基础信息

| 项目 | 说明 |
|------|------|
| Base URL | `http://your-domain.com/` （部署后替换为实际域名） |
| 协议 | HTTP / HTTPS |
| 请求格式 | `application/json`（POST/PUT），表单格式也兼容 |
| 字符编码 | UTF-8 |
| 鉴权方式 | 开放 API：`X-Api-Key` 请求头；管理端：`Authorization: Bearer <token>` |

**Nginx 部署说明**：
- 管理后台 API 路径前缀：`/api`（Nginx 需重写去掉 `/api` 前缀后转发到后端）
- 后端服务默认端口：`9501`（HTTP）、`9502`（WebSocket）

---

## 统一响应格式

所有接口返回统一的 JSON 格式：

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| code | number | 状态码：0=成功，其他=失败 |
| message | string | 提示信息 |
| data | any | 响应数据（成功时返回，失败时可能为 null） |

---

## 开放推送 API（X-Api-Key 鉴权）

第三方业务系统通过 API Key 调用推送接口。在管理后台「开放 API 管理」中创建 API Key。

### 消息推送接口

向指定设备或 Key 推送消息。

- **接口地址**：`POST /api/push`
- **鉴权方式**：请求头 `X-Api-Key: <你的APIKey>`
- **请求参数（Body）**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| target_type | string | 是 | 目标类型：`device`（按设备ID推送）、`key`（按Key推送） |
| target_value | string | 是 | 目标值，多个用英文逗号分隔，如 `"device1,device2"` |
| title | string | 是 | 消息标题 |
| content | string | 否 | 消息内容 |
| payload | object | 否 | 附加数据（自定义JSON，APP端可解析） |
| priority | string | 否 | 优先级：`high` / `normal` / `low`，默认 `normal` |

**请求示例**：

```bash
curl -X POST http://your-domain.com/api/push \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: your_api_key_here" \
  -d '{
    "target_type": "key",
    "target_value": "my_push_key",
    "title": "系统通知",
    "content": "您有一条新消息",
    "payload": {
      "order_id": "123456",
      "type": "order_notify"
    },
    "priority": "high"
  }'
```

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "success_count": 5,
    "fail_count": 1,
    "detail": [
      { "device_id": "dev_001", "success": true },
      { "device_id": "dev_002", "success": false, "reason": "offline" }
    ]
  }
}
```

---

## 用户认证接口

APP / H5 端用户注册登录相关接口。

### 获取图形验证码

获取登录/注册时使用的图形验证码。

- **接口地址**：`GET /captcha/image`
- **无需鉴权**

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "token": "captcha_token_xxx",
    "image": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA..."
  }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| token | string | 验证码 Token（后续接口需传入） |
| image | string | Base64 编码的验证码图片 |

---

### 发送短信/邮箱验证码

注册时发送短信或邮箱验证码。

- **接口地址**：`POST /auth/send-code`
- **无需鉴权**

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| type | string | 是 | `sms`（短信）或 `email`（邮箱） |
| target | string | 是 | 手机号或邮箱地址 |

**响应示例**：

```json
{
  "code": 0,
  "message": "验证码已发送",
  "data": {
    "sent": true,
    "message": "验证码已发送，5分钟内有效"
  }
}
```

---

### 用户注册

- **接口地址**：`POST /auth/register`
- **无需鉴权**

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 用户名 |
| phone | string | 否 | 手机号 |
| email | string | 否 | 邮箱 |
| password | string | 是 | 密码 |
| code_type | string | 是 | 验证码类型：`sms` / `email` |
| code_target | string | 是 | 验证码目标（手机号或邮箱） |
| code_input | string | 是 | 用户输入的验证码 |

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "user_id": 1001
  }
}
```

---

### 用户登录

- **接口地址**：`POST /auth/login`
- **无需鉴权**

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| account | string | 是 | 账号（用户名/手机号/邮箱） |
| password | string | 是 | 密码 |
| captcha_token | string | 是 | 图形验证码 Token |
| captcha_input | string | 是 | 用户输入的图形验证码 |

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "user": {
      "id": 1001,
      "username": "testuser",
      "phone": "13800138000",
      "email": "test@example.com",
      "status": 1,
      "created_at": "2026-07-01 12:00:00"
    }
  }
}
```

---

## 管理员接口

管理后台使用的接口，需管理员鉴权。

### 管理员登录

- **接口地址**：`POST /admin/login`
- **无需鉴权**

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 管理员账号 |
| password | string | 是 | 密码 |
| captcha_token | string | 是 | 图形验证码 Token |
| captcha_input | string | 是 | 图形验证码内容 |

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "admin": {
      "id": 1,
      "username": "admin",
      "role": "super_admin",
      "status": 1
    }
  }
}
```

---

### 管理员登出

- **接口地址**：`POST /admin/logout`
- **鉴权**：管理员 Token
- **说明**：JWT 为无状态，登出仅记录日志，前端清除本地 Token 即可

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "message": "已登出"
  }
}
```

---

### 获取当前管理员信息

- **接口地址**：`GET /admin/info`
- **鉴权**：管理员 Token

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": 1,
    "username": "admin",
    "role": "super_admin",
    "status": 1,
    "created_at": "2026-01-01 00:00:00"
  }
}
```

---

### 管理员列表

- **接口地址**：`GET /admin/list`
- **鉴权**：管理员 Token（仅 super_admin 可查看）
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | number | 否 | 页码，默认 1 |
| keyword | string | 否 | 搜索关键词（用户名） |

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "list": [
      {
        "id": 1,
        "username": "admin",
        "role": "super_admin",
        "status": 1,
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 1,
    "page": 1,
    "page_size": 10
  }
}
```

---

### 创建管理员

- **接口地址**：`POST /admin/create`
- **鉴权**：超级管理员 Token

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 用户名 |
| password | string | 是 | 密码 |
| role | string | 否 | 角色：`admin` / `super_admin`，默认 `admin` |

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": 2
  }
}
```

---

### 更新管理员

- **接口地址**：`PUT /admin/update/{id}`
- **鉴权**：超级管理员 Token
- **路径参数**：`id` - 管理员ID

**请求参数（可选更新字段）**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 否 | 用户名 |
| password | string | 否 | 新密码 |
| role | string | 否 | 角色 |
| status | number | 否 | 状态：0=禁用，1=正常 |

---

### 删除管理员

- **接口地址**：`DELETE /admin/delete/{id}`
- **鉴权**：超级管理员 Token
- **路径参数**：`id` - 管理员ID
- **注意**：不能删除当前登录的管理员账号，不能删除最后一个 super_admin

---

### 修改自己的密码

- **接口地址**：`PUT /admin/change-password`
- **鉴权**：管理员 Token

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| old_password | string | 是 | 原密码 |
| new_password | string | 是 | 新密码 |

---

### 操作日志

- **接口地址**：`GET /admin/logs`
- **鉴权**：管理员 Token
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | number | 否 | 页码，默认 1 |

---

## API Key 管理接口

管理开放 API 的密钥。

### API Key 列表

- **接口地址**：`GET /admin/api-keys`
- **鉴权**：管理员 Token
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | number | 否 | 页码，默认 1 |
| keyword | string | 否 | 搜索关键词（名称/AccessKey） |

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "list": [
      {
        "id": 1,
        "name": "业务系统对接",
        "key_value": "ak_xxxxxxxxxxxx",
        "status": 1,
        "expire_at": "2026-12-31 23:59:59",
        "created_at": "2026-07-01 00:00:00"
      }
    ],
    "total": 1,
    "page": 1,
    "page_size": 10
  }
}
```

---

### 创建 API Key

- **接口地址**：`POST /admin/api-keys`
- **鉴权**：管理员 Token

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| name | string | 是 | Key 名称（备注） |
| expire_at | string | 否 | 过期时间，格式 `YYYY-MM-DD HH:mm:ss`，不传则永不过期 |

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": 1,
    "name": "业务系统对接",
    "access_key": "ak_xxxxxxxxxxxx",
    "secret_key": "sk_xxxxxxxxxxxx",
    "status": 1,
    "expire_at": null,
    "created_at": "2026-07-01 00:00:00"
  }
}
```

> **注意**：`secret_key` 仅在创建时返回一次，后续不再展示，请妥善保存。

---

### 更新 API Key

- **接口地址**：`PUT /admin/api-keys/{id}`
- **鉴权**：管理员 Token
- **路径参数**：`id` - API Key ID

**请求参数（可选更新字段）**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| name | string | 否 | 名称 |
| status | number | 否 | 状态：0=禁用，1=启用 |
| expire_at | string\|null | 否 | 过期时间，传 null 设为永久有效 |

---

### 删除 API Key

- **接口地址**：`DELETE /admin/api-keys/{id}`
- **鉴权**：管理员 Token
- **路径参数**：`id` - API Key ID

---

## Push Key 管理接口

管理用户的推送 Key。

### Push Key 列表

- **接口地址**：`GET /admin/keys`
- **鉴权**：管理员 Token
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | number | 否 | 页码，默认 1 |
| keyword | string | 否 | 搜索关键词（名称/Key值） |

---

### Push Key 详情

- **接口地址**：`GET /admin/keys/{id}`
- **鉴权**：管理员 Token
- **路径参数**：`id` - Key ID

---

### 创建 Push Key

- **接口地址**：`POST /admin/keys`
- **鉴权**：管理员 Token

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| name | string | 是 | Key 名称 |
| user_id | number | 是 | 所属用户ID |
| max_devices | number | 否 | 最大设备数，默认 10 |
| status | number | 否 | 状态，默认 1 |

---

### 更新 Push Key

- **接口地址**：`PUT /admin/keys/{id}`
- **鉴权**：管理员 Token
- **路径参数**：`id` - Key ID

**请求参数（可选更新字段）**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| name | string | 否 | 名称 |
| max_devices | number | 否 | 最大设备数 |
| status | number | 否 | 状态 |

---

### 删除 Push Key

- **接口地址**：`DELETE /admin/keys/{id}`
- **鉴权**：管理员 Token
- **路径参数**：`id` - Key ID

---

### 更新 Push Key 状态

- **接口地址**：`PUT /admin/keys/{id}/status`
- **鉴权**：管理员 Token
- **路径参数**：`id` - Key ID

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| status | number | 是 | 状态：0=禁用，1=启用 |

---

## 设备管理接口

### 设备列表

- **接口地址**：`GET /admin/devices`
- **鉴权**：管理员 Token
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | number | 否 | 页码，默认 1 |
| keyword | string | 否 | 搜索关键词（设备ID/设备名/IP） |

---

### 设备详情

- **接口地址**：`GET /admin/devices/{id}`
- **鉴权**：管理员 Token
- **路径参数**：`id` - 设备ID

---

## 黑名单管理接口

### 黑名单列表

- **接口地址**：`GET /admin/blacklist`
- **鉴权**：管理员 Token
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | number | 否 | 页码，默认 1 |
| keyword | string | 否 | 搜索关键词 |

---

### 添加黑名单

- **接口地址**：`POST /admin/blacklist`
- **鉴权**：管理员 Token

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| type | string | 是 | 类型：`ip` / `device` / `user` |
| value | string | 是 | 黑名单值（IP地址/设备ID/用户ID） |
| reason | string | 否 | 拉黑原因 |

---

### 删除黑名单

- **接口地址**：`DELETE /admin/blacklist/{id}`
- **鉴权**：管理员 Token
- **路径参数**：`id` - 黑名单记录ID

---

## 消息记录接口

### 消息记录列表

- **接口地址**：`GET /admin/messages`
- **鉴权**：管理员 Token
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | number | 否 | 页码，默认 1 |
| keyword | string | 否 | 搜索关键词（标题/内容） |

---

### 导出消息记录

- **接口地址**：`GET /admin/messages/export`
- **鉴权**：管理员 Token
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| format | string | 否 | 导出格式：`csv`（默认）/ `txt` / `json` |
| keyword | string | 否 | 搜索关键词 |

---

### 推送日志列表

- **接口地址**：`GET /admin/push-logs`
- **鉴权**：管理员 Token
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | number | 否 | 页码，默认 1 |
| keyword | string | 否 | 搜索关键词 |

---

### 导出推送日志

- **接口地址**：`GET /admin/push-logs/export`
- **鉴权**：管理员 Token
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| format | string | 否 | 导出格式：`csv` / `txt` / `json` |
| keyword | string | 否 | 搜索关键词 |

---

## 仪表盘统计接口

### 概览数据

- **接口地址**：`GET /admin/dashboard/overview`
- **鉴权**：管理员 Token

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "online_devices": 128,
    "today_push": 1520,
    "yesterday_push": 1340,
    "active_keys": 45,
    "total_keys": 60,
    "total_users": 1200,
    "today_new_users": 23,
    "today_new_devices": 45
  }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| online_devices | number | 当前在线设备数 |
| today_push | number | 今日推送总量 |
| yesterday_push | number | 昨日推送总量 |
| active_keys | number | 活跃 Key 数 |
| total_keys | number | Key 总数 |
| total_users | number | 注册用户总数 |
| today_new_users | number | 今日新增用户数 |
| today_new_devices | number | 今日新增设备数 |

---

### 在线设备趋势

- **接口地址**：`GET /admin/dashboard/online-trend`
- **鉴权**：管理员 Token
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| days | number | 否 | 天数，默认 7，最大 90 |

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "dates": ["2026-07-07", "2026-07-08", "2026-07-09", "..."],
    "values": [85, 92, 110, "..."]
  }
}
```

---

### 今日推送量

- **接口地址**：`GET /admin/dashboard/today-push`
- **鉴权**：管理员 Token

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "hours": ["00:00", "01:00", "...", "23:00"],
    "values": [12, 5, 0, "...", 80]
  }
}
```

---

### Key 状态分布

- **接口地址**：`GET /admin/dashboard/key-distribution`
- **鉴权**：管理员 Token

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "data": [
      { "name": "活跃", "value": 45 },
      { "name": "已禁用", "value": 15 }
    ]
  }
}
```

---

### 设备平台分布

- **接口地址**：`GET /admin/dashboard/device-platform`
- **鉴权**：管理员 Token

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "data": [
      { "name": "Android", "value": 800 },
      { "name": "iOS", "value": 300 },
      { "name": "Web", "value": 100 },
      { "name": "其他", "value": 50 }
    ]
  }
}
```

---

### 最新推送记录

- **接口地址**：`GET /admin/dashboard/recent-push`
- **鉴权**：管理员 Token
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| limit | number | 否 | 返回条数，默认 5，最大 50 |

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "list": [
      {
        "id": 1,
        "title": "系统通知",
        "target_type": "key",
        "target_value": "my_key",
        "success_count": 50,
        "fail_count": 2,
        "created_at": "2026-07-13 14:30:00"
      }
    ]
  }
}
```

---

## 测试调试接口

### 管理员测试推送

- **接口地址**：`POST /admin/test-push`
- **鉴权**：管理员 Token

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| target_type | string | 是 | 目标类型：`all` / `key` / `device` |
| target_value | string | 否 | 目标值（target_type 为 all 时可空） |
| title | string | 是 | 消息标题 |
| content | string | 否 | 消息内容 |

---

### 检查设备在线状态

- **接口地址**：`GET /admin/test-push/check`
- **鉴权**：管理员 Token
- **查询参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| type | string | 是 | 类型：`device` / `key` |
| value | string | 是 | 设备ID 或 Key 值 |

---

### APP 端自测推送

APP 端用于自测推送功能，无需管理员鉴权。

- **接口地址**：`POST /api/test-push-self`
- **无需鉴权**

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| push_key | string | 是 | Push Key 值 |
| device_id | string | 是 | 设备ID |
| title | string | 是 | 消息标题 |
| content | string | 否 | 消息内容 |

---

## 系统设置接口

### 获取邮件配置

- **接口地址**：`GET /admin/settings/mail`
- **鉴权**：管理员 Token

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "smtp_host": "smtp.qq.com",
    "smtp_port": 587,
    "smtp_user": "xxx@qq.com",
    "smtp_pass": "授权码",
    "from_name": "IM Push",
    "from_email": "xxx@qq.com",
    "smtp_secure": "tls"
  }
}
```

---

### 保存邮件配置

- **接口地址**：`POST /admin/settings/mail`
- **鉴权**：管理员 Token

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| smtp_host | string | 是 | SMTP 服务器地址 |
| smtp_port | number | 是 | SMTP 端口 |
| smtp_user | string | 是 | SMTP 用户名 |
| smtp_pass | string | 是 | SMTP 密码/授权码 |
| from_name | string | 是 | 发件人名称 |
| from_email | string | 是 | 发件人邮箱 |
| smtp_secure | string | 否 | 加密方式：`tls` / `ssl` / 空 |

---

### 测试邮件配置

发送测试邮件验证配置是否正确。

- **接口地址**：`POST /admin/settings/mail/test`
- **鉴权**：管理员 Token

**请求参数**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| to_email | string | 是 | 收件人邮箱 |

---

## WebSocket 接入协议

APP 端通过 WebSocket 长连接接收推送消息。

### 连接地址

```
ws://your-domain.com:9502/
```

> 使用 Nginx 反向代理时建议配置 WSS 协议（wss://）。

### 鉴权消息（auth）

连接建立后，客户端必须在 5 秒内发送鉴权消息，否则连接将被断开。

**客户端发送**：

```json
{
  "type": "auth",
  "data": {
    "key": "your_push_key",
    "device_id": "your_device_id",
    "device_name": "我的手机",
    "device_model": "Pixel 8",
    "os_version": "Android 14"
  }
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| type | string | 是 | 固定为 `auth` |
| data.key | string | 是 | Push Key 值 |
| data.device_id | string | 是 | 设备唯一标识（如 Android ID） |
| data.device_name | string | 否 | 设备名称 |
| data.device_model | string | 否 | 设备型号 |
| data.os_version | string | 否 | 系统版本 |

**服务端响应**：

```json
{
  "type": "auth",
  "code": 0,
  "message": "鉴权成功",
  "data": {
    "device_id": "your_device_id"
  }
}
```

---

### 心跳（ping/pong）

为保持长连接，客户端需定期发送心跳包。建议间隔 30 秒。

**客户端发送 ping**：

```json
{
  "type": "ping",
  "timestamp": 1718179200
}
```

**服务端响应 pong**：

```json
{
  "type": "pong",
  "timestamp": 1718179200
}
```

> 若连续 3 个心跳周期（约90秒）未收到客户端消息，服务端将主动断开连接。

---

### 推送消息格式

服务端向客户端推送消息的格式：

```json
{
  "type": "push",
  "message_id": "msg_6692a38f1c2d",
  "title": "系统通知",
  "content": "您有一条新消息",
  "payload": {
    "order_id": "123456",
    "type": "order_notify"
  },
  "priority": "high",
  "timestamp": 1718179200
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| type | string | 固定为 `push` |
| message_id | string | 消息唯一ID |
| title | string | 消息标题 |
| content | string | 消息内容 |
| payload | object | 附加自定义数据 |
| priority | string | 优先级：high/normal/low |
| timestamp | number | 发送时间戳（秒） |

---

## 错误码说明

| 错误码 | HTTP 状态码 | 说明 |
|--------|------------|------|
| 0 | 200 | 成功 |
| 400 | 400 | 请求参数错误 |
| 401 | 401 | 未登录 / Token 失效 / API Key 无效 |
| 403 | 403 | 无权限访问 |
| 404 | 404 | 资源不存在 / 路由不存在 |
| 500 | 500 | 服务器内部错误 |

---

## SDK 示例

### cURL

```bash
# 推送消息
curl -X POST http://your-domain.com/api/push \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: your_api_key" \
  -d '{
    "target_type": "key",
    "target_value": "my_key",
    "title": "测试消息",
    "content": "这是一条测试推送"
  }'
```

### Python (requests)

```python
import requests

API_URL = "http://your-domain.com/api/push"
API_KEY = "your_api_key"

payload = {
    "target_type": "key",
    "target_value": "my_key",
    "title": "测试消息",
    "content": "这是一条测试推送",
    "payload": {"type": "test"}
}

headers = {
    "Content-Type": "application/json",
    "X-Api-Key": API_KEY
}

response = requests.post(API_URL, json=payload, headers=headers)
print(response.json())
```

### Node.js (axios)

```javascript
const axios = require('axios');

const API_URL = 'http://your-domain.com/api/push';
const API_KEY = 'your_api_key';

async function pushMessage() {
  try {
    const res = await axios.post(API_URL, {
      target_type: 'key',
      target_value: 'my_key',
      title: '测试消息',
      content: '这是一条测试推送'
    }, {
      headers: {
        'X-Api-Key': API_KEY
      }
    });
    console.log(res.data);
  } catch (err) {
    console.error(err.response?.data || err.message);
  }
}

pushMessage();
```

### Java (OkHttp)

```java
import okhttp3.*;
import com.google.gson.Gson;
import java.io.IOException;
import java.util.HashMap;
import java.util.Map;

public class PushDemo {
    private static final String API_URL = "http://your-domain.com/api/push";
    private static final String API_KEY = "your_api_key";

    public static void main(String[] args) throws IOException {
        OkHttpClient client = new OkHttpClient();
        Gson gson = new Gson();

        Map<String, Object> payload = new HashMap<>();
        payload.put("target_type", "key");
        payload.put("target_value", "my_key");
        payload.put("title", "测试消息");
        payload.put("content", "这是一条测试推送");

        RequestBody body = RequestBody.create(
            gson.toJson(payload),
            MediaType.parse("application/json")
        );

        Request request = new Request.Builder()
            .url(API_URL)
            .post(body)
            .header("X-Api-Key", API_KEY)
            .build();

        try (Response response = client.newCall(request).execute()) {
            System.out.println(response.body().string());
        }
    }
}
```

---

## 常见问题

**Q: API Key 和 Push Key 有什么区别？**

A: 
- **API Key**：用于第三方业务系统调用开放推送 API，相当于系统间的对接密钥
- **Push Key**：用于 APP 端用户接收推送，每个用户可以有一个或多个 Push Key

**Q: WebSocket 断开后怎么办？**

A: APP 端应实现自动重连机制，建议采用指数退避策略（首次1秒，第二次2秒，最长30秒）。

**Q: 消息会丢失吗？**

A: 设备在线时实时推送；设备离线时消息会存储在服务器，设备重新连接后会收到离线消息。

**Q: 支持多少并发连接？**

A: 单台 4核8G 服务器可支持约 5-10 万并发 WebSocket 连接，支持水平扩展。
