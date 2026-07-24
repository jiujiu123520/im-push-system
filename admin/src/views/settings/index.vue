<template>
  <div class="page-container settings-page">
    <!-- 页头 -->
    <div class="page-hero">
      <div class="hero-bg">
        <div class="hero-blob blob-a"></div>
        <div class="hero-blob blob-b"></div>
      </div>
      <div class="hero-content">
        <div>
          <h2 class="hero-title">系统设置</h2>
          <p class="hero-sub">配置服务器、推送、验证码与安全策略</p>
        </div>
        <div class="hero-stats">
          <div class="stat-mini">
            <span class="stat-label">配置项</span>
            <span class="stat-value">{{ configCount }}</span>
          </div>
          <div class="stat-divider"></div>
          <div class="stat-mini">
            <span class="stat-label">系统状态</span>
            <span class="stat-value status-ok">
              <el-icon><CircleCheckFilledIcon /></el-icon>
              正常
            </span>
          </div>
        </div>
      </div>
    </div>

    <div class="settings-grid">
      <!-- a) 服务器配置 -->
      <div class="setting-card" v-loading="loading">
        <div class="card-head">
          <div class="head-icon icon-server">
            <el-icon><MonitorIcon /></el-icon>
          </div>
          <div class="head-text">
            <h3 class="card-title">服务器配置</h3>
            <p class="card-sub">HTTP API 与 WebSocket 连接地址</p>
          </div>
        </div>

        <el-form
          ref="serverFormRef"
          :model="serverForm"
          :rules="serverRules"
          label-position="top"
          class="setting-form"
        >
          <el-form-item label="启用 SSL（HTTPS / WSS）">
            <div class="ssl-toggle-row">
              <el-switch v-model="serverForm.sslEnabled" @change="onSslToggle" />
              <span class="ssl-toggle-hint">
                开启后地址自动使用 https:// 和 wss:// 协议
              </span>
              <el-tag v-if="serverForm.sslEnabled" type="success" size="small" effect="dark">
                <el-icon><LockIcon /></el-icon> SSL 已启用
              </el-tag>
              <el-tag v-else type="info" size="small">未启用</el-tag>
            </div>
            <div class="ssl-tip">
              提示：启用前请先在「域名与SSL」页面申请并部署 SSL 证书，否则 HTTPS 访问会失败
            </div>
          </el-form-item>

          <div class="form-row">
            <el-form-item label="前端访问地址（管理后台）" prop="frontendUrl">
              <el-input
                v-model="serverForm.frontendUrl"
                :placeholder="serverForm.sslEnabled ? 'https://admin.push.com' : 'http://admin.push.com'"
                clearable
              />
              <div class="form-tip">管理后台页面的访问地址（可与后端 API 分开绑定域名）</div>
            </el-form-item>
            <el-form-item label="前端端口" prop="frontendPort">
              <el-input-number
                v-model="serverForm.frontendPort"
                :min="0"
                :max="65535"
                controls-position="right"
                style="width: 100%"
                @change="(v: any) => onPortChange('frontend', v)"
              />
              <div class="form-tip">0=默认端口（80/443），Nginx 反向代理时通常用 80/443</div>
              <div v-if="portCheck.frontend" class="port-check-result" :class="{ 'port-ok': portCheck.frontend.available, 'port-bad': !portCheck.frontend.available }">
                <el-icon v-if="portCheck.frontend.available"><CircleCheckIcon /></el-icon>
                <el-icon v-else><CircleCloseIcon /></el-icon>
                {{ portCheck.frontend.message }}
              </div>
            </el-form-item>
          </div>

          <div class="form-row">
            <el-form-item label="后端 API 地址" prop="httpApiUrl">
              <el-input
                v-model="serverForm.httpApiUrl"
                :placeholder="serverForm.sslEnabled ? 'https://api.push.com' : 'http://api.push.com'"
                clearable
              />
              <div class="form-tip">后端 API 接口地址（可与前端分开绑定域名/端口）</div>
            </el-form-item>
            <el-form-item label="后端 API 端口" prop="httpPort">
              <el-input-number
                v-model="serverForm.httpPort"
                :min="0"
                :max="65535"
                controls-position="right"
                style="width: 100%"
                @change="(v: any) => onPortChange('http', v)"
              />
              <div class="form-tip">0=默认端口（80/443），Swoole HTTP 默认 9501</div>
              <div v-if="portCheck.http" class="port-check-result" :class="{ 'port-ok': portCheck.http.available, 'port-bad': !portCheck.http.available }">
                <el-icon v-if="portCheck.http.available"><CircleCheckIcon /></el-icon>
                <el-icon v-else><CircleCloseIcon /></el-icon>
                {{ portCheck.http.message }}
              </div>
            </el-form-item>
          </div>
          <div class="form-row">
            <el-form-item label="WebSocket 地址" prop="websocketUrl">
              <el-input
                v-model="serverForm.websocketUrl"
                :placeholder="serverForm.sslEnabled ? 'wss://ws.push.com' : 'ws://ws.push.com'"
                clearable
              />
            </el-form-item>
            <el-form-item label="WebSocket 端口" prop="websocketPort">
              <el-input-number
                v-model="serverForm.websocketPort"
                :min="0"
                :max="65535"
                controls-position="right"
                style="width: 100%"
                @change="(v: any) => onPortChange('websocket', v)"
              />
              <div class="form-tip">0=默认端口（80/443），Swoole WebSocket 默认 9502</div>
              <div v-if="portCheck.websocket" class="port-check-result" :class="{ 'port-ok': portCheck.websocket.available, 'port-bad': !portCheck.websocket.available }">
                <el-icon v-if="portCheck.websocket.available"><CircleCheckIcon /></el-icon>
                <el-icon v-else><CircleCloseIcon /></el-icon>
                {{ portCheck.websocket.message }}
              </div>
            </el-form-item>
          </div>

          <!-- 端口使用提示 -->
          <el-alert type="info" :closable="false" class="port-tip-alert">
            <template #title>
              <div class="port-tip-content">
                <strong>端口使用建议：</strong>
                <div>· <b>推荐使用</b>：9501-9999、10000-65535（高位端口无冲突风险）</div>
                <div>· <b>默认端口</b>：0 = 使用 80（HTTP）或 443（HTTPS），需 Nginx 反向代理</div>
                <div>· <b>避免使用</b>：22(SSH)、80(Nginx)、443(Nginx)、3306(MySQL)、6379(Redis)、8080、8443</div>
                <div>· <b>系统保留</b>：1-1023 需要 root 权限，不建议直接使用</div>
              </div>
            </template>
          </el-alert>
          <div class="form-actions">
            <el-button
              :icon="RefreshIcon"
              @click="autoDetectServer"
            >
              自动检测地址
            </el-button>
            <el-button
              type="primary"
              :icon="CheckIcon"
              :loading="saving.server"
              @click="saveSection('server')"
            >
              保存配置
            </el-button>
          </div>
        </el-form>
      </div>

      <!-- b) 推送配置 -->
      <div class="setting-card" v-loading="loading">
        <div class="card-head">
          <div class="head-icon icon-push">
            <el-icon><PromotionIcon /></el-icon>
          </div>
          <div class="head-text">
            <h3 class="card-title">推送配置</h3>
            <p class="card-sub">心跳、离线消息、连接限制</p>
          </div>
        </div>

        <el-form
          ref="pushFormRef"
          :model="pushForm"
          :rules="pushRules"
          label-position="top"
          class="setting-form"
        >
          <el-form-item label="默认心跳间隔（秒）" prop="heartbeatInterval">
            <div class="slider-wrap">
              <el-slider
                v-model="pushForm.heartbeatInterval"
                :min="10"
                :max="300"
                :step="5"
                show-input
                :show-input-controls="false"
                input-size="small"
              />
              <div class="slider-marks">
                <span>10s</span>
                <span>300s</span>
              </div>
            </div>
          </el-form-item>

          <div class="form-row">
            <el-form-item label="离线消息保留时长（小时）" prop="offlineRetention">
              <el-input-number
                v-model="pushForm.offlineRetention"
                :min="1"
                :max="720"
                controls-position="right"
                style="width: 100%"
              />
            </el-form-item>
            <el-form-item label="最大连接数限制" prop="maxConnections">
              <el-input-number
                v-model="pushForm.maxConnections"
                :min="100"
                :max="1000000"
                :step="100"
                controls-position="right"
                style="width: 100%"
              />
            </el-form-item>
          </div>

          <div class="form-actions">
            <el-button
              type="primary"
              :icon="CheckIcon"
              :loading="saving.push"
              @click="saveSection('push')"
            >
              保存配置
            </el-button>
          </div>
        </el-form>
      </div>

      <!-- c) 验证码开关（独立卡片） -->
      <div class="setting-card captcha-toggle-card" v-loading="loading">
        <div class="card-head">
          <div class="head-icon icon-captcha">
            <el-icon><KeyIcon /></el-icon>
          </div>
          <div class="head-text">
            <h3 class="card-title">验证码开关</h3>
            <p class="card-sub">控制注册与登录是否需要验证码验证</p>
          </div>
        </div>

        <div class="captcha-toggle-row">
          <div class="captcha-toggle-main">
            <el-switch
              v-model="captchaForm.enabled"
              :active-value="1"
              :inactive-value="0"
              size="large"
              @change="onCaptchaToggle"
            />
            <span class="captcha-toggle-text">
              {{ captchaForm.enabled === 1 ? '验证码已启用' : '验证码已关闭' }}
            </span>
          </div>
          <el-tag v-if="captchaForm.enabled === 1" type="success" effect="light" size="large">
            <el-icon><CircleCheckFilledIcon /></el-icon>
            注册 + 登录均需验证
          </el-tag>
          <el-tag v-else type="warning" effect="light" size="large">
            <el-icon><WarningFilledIcon /></el-icon>
            关闭后注册和登录无需验证码
          </el-tag>
        </div>

        <!-- 独立开关：短信验证 & 邮箱验证 -->
        <div v-if="captchaForm.enabled === 1" class="captcha-sub-toggles">
          <div class="sub-toggle-item">
            <div class="sub-toggle-left">
              <span class="sub-toggle-icon">📱</span>
              <div class="sub-toggle-text">
                <div class="sub-toggle-title">短信验证码</div>
                <div class="sub-toggle-desc">注册时通过短信验证码验证手机号</div>
              </div>
            </div>
            <el-switch
              v-model="captchaForm.smsEnabled"
              :active-value="1"
              :inactive-value="0"
              @change="onSmsCaptchaToggle"
            />
          </div>
          <div class="sub-toggle-item">
            <div class="sub-toggle-left">
              <span class="sub-toggle-icon">📧</span>
              <div class="sub-toggle-text">
                <div class="sub-toggle-title">邮箱验证码</div>
                <div class="sub-toggle-desc">注册时通过邮箱验证码验证邮箱</div>
              </div>
            </div>
            <el-switch
              v-model="captchaForm.emailEnabled"
              :active-value="1"
              :inactive-value="0"
              @change="onEmailCaptchaToggle"
            />
          </div>
        </div>

        <div class="form-actions captcha-toggle-actions">
          <el-button
            type="primary"
            :icon="CheckIcon"
            :loading="saving.captcha"
            @click="saveSection('captcha')"
          >
            保存开关
          </el-button>
        </div>
      </div>

      <!-- d) 验证码服务配置 -->
      <div class="setting-card" v-loading="loading">
        <div class="card-head">
          <div class="head-icon icon-captcha">
            <el-icon><MessageIcon /></el-icon>
          </div>
          <div class="head-text">
            <h3 class="card-title">验证码服务配置</h3>
            <p class="card-sub">短信与邮件验证码服务参数</p>
          </div>
          <el-tag
            v-if="captchaForm.enabled !== 1"
            type="info"
            effect="dark"
            size="small"
          >
            已停用
          </el-tag>
        </div>

        <el-form
          ref="captchaFormRef"
          :model="captchaForm"
          :rules="captchaRules"
          :disabled="captchaForm.enabled !== 1"
          label-position="top"
          class="setting-form"
        >
          <div class="sub-section-title">
            <span class="title-bar"></span>
            短信服务
          </div>
          <div class="form-row">
            <el-form-item label="短信 API Key" prop="smsApiKey">
              <el-input
                v-model="captchaForm.smsApiKey"
                :type="showSecret.smsApiKey ? 'text' : 'password'"
                placeholder="请输入短信服务 API Key"
              >
                <template #suffix>
                  <el-icon class="toggle-eye" @click="toggleSecret('smsApiKey')">
                    <ViewIcon v-if="!showSecret.smsApiKey" />
                    <HideIcon v-else />
                  </el-icon>
                </template>
              </el-input>
            </el-form-item>
            <el-form-item label="短信 API URL" prop="smsApiUrl">
              <el-input v-model="captchaForm.smsApiUrl" placeholder="https://sms.api.com/send" clearable />
            </el-form-item>
          </div>

          <div class="sub-section-title">
            <span class="title-bar"></span>
            邮件服务（SMTP）
          </div>
          <div class="form-row">
            <el-form-item label="SMTP 主机" prop="mailHost">
              <el-input v-model="captchaForm.mailHost" placeholder="smtp.exmail.qq.com" clearable />
            </el-form-item>
            <el-form-item label="SMTP 端口" prop="mailPort">
              <el-input-number
                v-model="captchaForm.mailPort"
                :min="1"
                :max="65535"
                controls-position="right"
                style="width: 100%"
              />
            </el-form-item>
          </div>
          <div class="form-row">
            <el-form-item label="SMTP 账号" prop="mailUsername">
              <el-input v-model="captchaForm.mailUsername" placeholder="noreply@push.com" clearable />
            </el-form-item>
            <el-form-item label="SMTP 密码" prop="mailPassword">
              <el-input
                v-model="captchaForm.mailPassword"
                :type="showSecret.mailPassword ? 'text' : 'password'"
                placeholder="请输入 SMTP 密码"
              >
                <template #suffix>
                  <el-icon class="toggle-eye" @click="toggleSecret('mailPassword')">
                    <ViewIcon v-if="!showSecret.mailPassword" />
                    <HideIcon v-else />
                  </el-icon>
                </template>
              </el-input>
            </el-form-item>
          </div>
          <el-form-item label="发件人地址" prop="mailFrom">
            <el-input v-model="captchaForm.mailFrom" placeholder="Push 推送服务 <noreply@push.com>" clearable />
          </el-form-item>

          <div class="form-actions">
            <el-button
              :icon="PromotionIcon"
              :loading="testing.mail"
              @click="testMail"
            >
              测试发送
            </el-button>
            <el-button
              type="primary"
              :icon="CheckIcon"
              :loading="saving.captcha"
              @click="saveSection('captcha')"
            >
              保存配置
            </el-button>
          </div>
        </el-form>
      </div>

      <!-- d) 邮件通知配置 -->
      <div class="setting-card" v-loading="loading">
        <div class="card-head">
          <div class="head-icon icon-mail">
            <el-icon><MessageIcon /></el-icon>
          </div>
          <div class="head-text">
            <h3 class="card-title">邮件通知配置</h3>
            <p class="card-sub">设备掉线邮箱通知（支持 QQ 邮箱）</p>
          </div>
        </div>

        <el-form
          ref="mailFormRef"
          :model="mailForm"
          label-position="top"
          class="setting-form"
        >
          <div class="form-row">
            <el-form-item label="启用邮件通知">
              <el-switch v-model="mailForm.enabled" />
            </el-form-item>
          </div>

          <div class="sub-section-title">
            <span class="title-bar"></span>
            SMTP 配置
          </div>

          <div class="form-row">
            <el-form-item label="SMTP 主机" prop="host">
              <el-select v-model="mailForm.host" placeholder="选择邮件服务商">
                <el-option label="QQ 邮箱" value="smtp.qq.com" />
                <el-option label="QQ 企业邮箱" value="smtp.exmail.qq.com" />
                <el-option label="163 邮箱" value="smtp.163.com" />
                <el-option label="Gmail" value="smtp.gmail.com" />
                <el-option label="自定义" value="custom" />
              </el-select>
            </el-form-item>
            <el-form-item label="SMTP 端口" prop="port">
              <el-select v-model="mailForm.port">
                <el-option label="587 (TLS)" value="587" />
                <el-option label="465 (SSL)" value="465" />
              </el-select>
            </el-form-item>
          </div>

          <div class="form-row">
            <el-form-item label="发件人邮箱" prop="username">
              <el-input v-model="mailForm.username" placeholder="your_email@qq.com" />
            </el-form-item>
            <el-form-item label="授权码" prop="password">
              <el-input
                v-model="mailForm.password"
                :type="showSecret.mailNotifyPassword ? 'text' : 'password'"
                placeholder="QQ 邮箱需填写授权码"
              >
                <template #suffix>
                  <el-icon class="toggle-eye" @click="toggleSecret('mailNotifyPassword')">
                    <ViewIcon v-if="!showSecret.mailNotifyPassword" />
                    <HideIcon v-else />
                  </el-icon>
                </template>
              </el-input>
            </el-form-item>
          </div>

          <div class="form-row">
            <el-form-item label="加密方式" prop="encryption">
              <el-select v-model="mailForm.encryption">
                <el-option label="TLS" value="tls" />
                <el-option label="SSL" value="ssl" />
                <el-option label="无" value="" />
              </el-select>
            </el-form-item>
            <el-form-item label="发件人名称" prop="sender_name">
              <el-input v-model="mailForm.sender_name" placeholder="Push 推送服务" />
            </el-form-item>
          </div>

          <!-- QQ 邮箱提示 -->
          <div v-if="mailForm.host === 'smtp.qq.com'" class="qq-mail-tip">
            <el-alert
              title="QQ 邮箱授权码获取方式"
              type="info"
              :closable="false"
              show-icon
            >
              <p>1. 登录 QQ 邮箱 → 设置 → 账户</p>
              <p>2. 开启「POP3/SMTP 服务」</p>
              <p>3. 点击「生成授权码」，按提示验证后获取</p>
              <p style="margin-top: 8px; font-size: 12px; color: #909399;">注意：授权码不是邮箱密码</p>
            </el-alert>
          </div>

          <div class="form-actions">
            <el-button
              :icon="PromotionIcon"
              :loading="testing.mailNotify"
              @click="testMailNotify"
              :disabled="!mailForm.enabled || !mailForm.username || !mailForm.password"
            >
              发送测试邮件
            </el-button>
            <el-button
              type="primary"
              :icon="CheckIcon"
              :loading="saving.mail"
              @click="saveMailConfig"
            >
              保存配置
            </el-button>
          </div>
        </el-form>
      </div>

      <!-- e) 安全配置 -->
      <div class="setting-card" v-loading="loading">
        <div class="card-head">
          <div class="head-icon icon-security">
            <el-icon><LockIcon /></el-icon>
          </div>
          <div class="head-text">
            <h3 class="card-title">安全配置</h3>
            <p class="card-sub">加密密钥、密码策略、登录锁定</p>
          </div>
        </div>

        <el-form
          ref="securityFormRef"
          :model="securityForm"
          :rules="securityRules"
          label-position="top"
          class="setting-form"
        >
          <el-form-item label="JWT 密钥" prop="jwtSecret">
            <el-input
              v-model="securityForm.jwtSecret"
              :type="showSecret.jwtSecret ? 'text' : 'password'"
              readonly
            >
              <template #suffix>
                <el-icon class="toggle-eye" @click="toggleSecret('jwtSecret')">
                    <ViewIcon v-if="!showSecret.jwtSecret" />
                    <HideIcon v-else />
                  </el-icon>
              </template>
              <template #append>
                <el-button :icon="RefreshIcon" @click="regenerateSecret('jwtSecret')">重新生成</el-button>
              </template>
            </el-input>
          </el-form-item>

          <el-form-item label="AES 加密密钥" prop="aesKey">
            <el-input
              v-model="securityForm.aesKey"
              :type="showSecret.aesKey ? 'text' : 'password'"
              readonly
            >
              <template #suffix>
                <el-icon class="toggle-eye" @click="toggleSecret('aesKey')">
                  <ViewIcon v-if="!showSecret.aesKey" />
                  <HideIcon v-else />
                </el-icon>
              </template>
              <template #append>
                <el-button :icon="RefreshIcon" @click="regenerateSecret('aesKey')">重新生成</el-button>
              </template>
            </el-input>
          </el-form-item>

          <div class="form-row">
            <el-form-item label="密码最小长度" prop="passwordMinLength">
              <el-input-number
                v-model="securityForm.passwordMinLength"
                :min="6"
                :max="32"
                controls-position="right"
                style="width: 100%"
              />
            </el-form-item>
            <el-form-item label="登录失败锁定次数" prop="loginFailLimit">
              <el-input-number
                v-model="securityForm.loginFailLimit"
                :min="3"
                :max="20"
                controls-position="right"
                style="width: 100%"
              />
            </el-form-item>
          </div>

          <div class="form-actions">
            <el-button
              type="primary"
              :icon="CheckIcon"
              :loading="saving.security"
              @click="saveSection('security')"
            >
              保存配置
            </el-button>
          </div>
        </el-form>
      </div>

      <!-- f) 并发压测推送 -->
      <div class="setting-card concurrent-test-card">
        <div class="card-head">
          <div class="head-icon icon-concurrent">
            <el-icon><PromotionIcon /></el-icon>
          </div>
          <div class="head-text">
            <h3 class="card-title">并发压测推送</h3>
            <p class="card-sub">测试推送系统并发承载能力（QPS / 平均耗时）</p>
          </div>
        </div>

        <el-form
          :model="concurrentForm"
          label-position="top"
          class="setting-form"
        >
          <div class="form-row">
            <el-form-item label="目标类型">
              <el-select v-model="concurrentForm.targetType" style="width: 100%">
                <el-option label="按推送 Key" value="key" />
                <el-option label="按设备 ID" value="device" />
              </el-select>
            </el-form-item>
            <el-form-item label="目标值" required>
              <el-input
                v-model="concurrentForm.targetValue"
                :placeholder="concurrentForm.targetType === 'key' ? '请输入推送 Key' : '请输入设备 ID'"
                clearable
              />
            </el-form-item>
          </div>

          <div class="form-row">
            <el-form-item label="标题（可选）">
              <el-input v-model="concurrentForm.title" placeholder="留空默认为【并发压测】" clearable />
            </el-form-item>
            <el-form-item label="内容（可选）">
              <el-input v-model="concurrentForm.content" placeholder="留空默认为并发压测消息" clearable />
            </el-form-item>
          </div>

          <div class="form-row">
            <el-form-item label="优先级">
              <el-select v-model="concurrentForm.priority" style="width: 100%">
                <el-option label="高（high）" value="high" />
                <el-option label="普通（normal）" value="normal" />
                <el-option label="低（low）" value="low" />
              </el-select>
            </el-form-item>
            <el-form-item label="并发数（1-1000）">
              <el-input-number
                v-model="concurrentForm.concurrency"
                :min="1"
                :max="1000"
                :step="1"
                controls-position="right"
                style="width: 100%"
              />
            </el-form-item>
          </div>

          <div class="form-row">
            <el-form-item label="总推送次数（0=只按并发数发一批，1-10000）">
              <el-input-number
                v-model="concurrentForm.total"
                :min="0"
                :max="10000"
                :step="10"
                controls-position="right"
                style="width: 100%"
              />
            </el-form-item>
            <el-form-item label="批次间隔（毫秒，0=不间隔）">
              <el-input-number
                v-model="concurrentForm.intervalMs"
                :min="0"
                :max="60000"
                :step="10"
                controls-position="right"
                style="width: 100%"
              />
            </el-form-item>
          </div>

          <el-alert type="warning" :closable="false" class="concurrent-tip">
            <template #title>
              <div class="concurrent-tip-content">
                <strong>注意事项：</strong>
                <div>· 总次数 = 并发数 × 批次数，建议从小到大逐步加压</div>
                <div>· 压测会真实推送消息到目标设备/Key，请注意影响</div>
                <div>· 间隔毫秒数越大对服务器冲击越小，建议初始 100ms</div>
              </div>
            </template>
          </el-alert>

          <div class="form-actions">
            <el-button
              type="primary"
              :icon="PromotionIcon"
              :loading="testing.concurrent"
              @click="runConcurrentTest"
            >
              开始压测
            </el-button>
          </div>

          <!-- 压测结果 -->
          <div v-if="concurrentResult" class="concurrent-result">
            <div class="result-header">
              <span class="result-title">压测结果</span>
              <el-tag
                :type="concurrentResult.fail_count === 0 ? 'success' : 'warning'"
                size="small"
              >
                {{ concurrentResult.fail_count === 0 ? '全部成功' : '部分失败' }}
              </el-tag>
            </div>
            <div class="result-grid">
              <div class="result-item">
                <span class="result-label">并发数</span>
                <span class="result-value mono">{{ concurrentResult.concurrency }}</span>
              </div>
              <div class="result-item">
                <span class="result-label">总推送数</span>
                <span class="result-value mono">{{ concurrentResult.total_sent }}</span>
              </div>
              <div class="result-item">
                <span class="result-label">成功数</span>
                <span class="result-value mono success">{{ concurrentResult.success_count }}</span>
              </div>
              <div class="result-item">
                <span class="result-label">失败数</span>
                <span class="result-value mono danger">{{ concurrentResult.fail_count }}</span>
              </div>
              <div class="result-item">
                <span class="result-label">总耗时</span>
                <span class="result-value mono">{{ concurrentResult.elapsed_ms }} ms</span>
              </div>
              <div class="result-item">
                <span class="result-label">平均耗时</span>
                <span class="result-value mono">{{ concurrentResult.avg_ms }} ms</span>
              </div>
              <div class="result-item highlight">
                <span class="result-label">QPS</span>
                <span class="result-value mono">{{ concurrentResult.qps }}</span>
              </div>
              <div class="result-item">
                <span class="result-label">批次数</span>
                <span class="result-value mono">{{ concurrentResult.batches }}</span>
              </div>
            </div>
            <div v-if="concurrentResult.detail && concurrentResult.detail.length > 0" class="result-detail">
              <div class="detail-title">失败详情（最多显示前 10 条）：</div>
              <div
                v-for="(item, idx) in concurrentResult.detail.slice(0, 10)"
                :key="idx"
                class="detail-line mono"
              >
                #{{ item.seq }} [批次 {{ item.batch }}] {{ item.reason }}
              </div>
            </div>
          </div>
        </el-form>
      </div>

      <!-- e) 系统信息（只读） -->
      <div class="setting-card system-info-card" v-loading="systemInfoLoading">
        <div class="card-head">
          <div class="head-icon icon-info">
            <el-icon><CpuIcon /></el-icon>
          </div>
          <div class="head-text">
            <h3 class="card-title">系统信息</h3>
            <p class="card-sub">运行环境与服务状态</p>
          </div>
          <el-button text :icon="RefreshIcon" :loading="systemInfoLoading" @click="fetchSystemInfo">
            刷新
          </el-button>
        </div>

        <div class="info-grid">
          <div class="info-item">
            <div class="info-label">
              <el-icon><InfoFilledIcon /></el-icon>
              系统版本
            </div>
            <div class="info-value mono">{{ systemInfo.version || '-' }}</div>
          </div>
          <div class="info-item">
            <div class="info-label">
              <el-icon><CpuIcon /></el-icon>
              PHP 版本
            </div>
            <div class="info-value mono">{{ systemInfo.phpVersion || '-' }}</div>
          </div>
          <div class="info-item">
            <div class="info-label">
              <el-icon><LightningIcon /></el-icon>
              Swoole 版本
            </div>
            <div class="info-value mono">{{ systemInfo.swooleVersion || '-' }}</div>
          </div>
          <div class="info-item">
            <div class="info-label">
              <el-icon><CoinIcon /></el-icon>
              Redis 状态
            </div>
            <div class="info-value">
              <span class="status-dot" :class="systemInfo.redisStatus"></span>
              {{ systemInfo.redisStatus === 'ok' ? '正常' : '异常' }}
            </div>
          </div>
          <div class="info-item">
            <div class="info-label">
              <el-icon><DataLineIcon /></el-icon>
              MySQL 状态
            </div>
            <div class="info-value">
              <span class="status-dot" :class="systemInfo.mysqlStatus"></span>
              {{ systemInfo.mysqlStatus === 'ok' ? '正常' : '异常' }}
            </div>
          </div>
          <div class="info-item">
            <div class="info-label">
              <el-icon><HistogramIcon /></el-icon>
              磁盘空间
            </div>
            <div class="info-value">
              <div class="disk-bar">
                <div
                  class="disk-used"
                  :style="{ width: diskPercent + '%' }"
                  :class="{ warn: diskPercent > 80, danger: diskPercent > 90 }"
                ></div>
              </div>
              <span class="disk-text">
                {{ formatBytes(systemInfo.diskUsed) }} / {{ formatBytes(systemInfo.diskTotal) }}
              </span>
            </div>
          </div>
        </div>

        <div class="uptime-row">
          <el-icon><ClockIcon /></el-icon>
          <span>系统已运行</span>
          <span class="uptime-value">{{ formatUptime(systemInfo.uptime) }}</span>
        </div>

        <!-- 版本检测与一键更新 -->
        <div class="version-check-section">
          <div class="version-check-header">
            <div class="version-check-title">
              <el-icon><DownloadIcon /></el-icon>
              <span>版本更新</span>
            </div>
            <div class="version-check-actions">
              <el-button
                size="small"
                :icon="RefreshIcon"
                :loading="versionChecking"
                @click="checkVersion"
              >
                检测新版本
              </el-button>
              <el-button
                size="small"
                type="primary"
                :icon="UploadIcon"
                :loading="updating"
                :disabled="versionInfo.status === 'up-to-date' || versionChecking"
                @click="startUpdate"
              >
                {{ updating ? '更新中...' : '一键更新' }}
              </el-button>
            </div>
          </div>

          <!-- 版本检测结果 -->
          <div v-if="versionInfo.status" class="version-result">
            <div class="version-compare">
              <div class="version-col">
                <span class="version-label">本地版本</span>
                <span class="version-hash mono">{{ versionInfo.local.short || '-' }}</span>
                <span class="version-date">{{ versionInfo.local.date || '' }}</span>
              </div>
              <div class="version-col">
                <span class="version-label">云端版本</span>
                <span class="version-hash mono">{{ versionInfo.remote.short || '-' }}</span>
                <span class="version-date">{{ versionInfo.remote.date || '' }}</span>
              </div>
            </div>
            <el-tag :type="versionStatusType" size="small" class="version-status-tag">
              {{ versionStatusText }}
            </el-tag>
          </div>

          <!-- 更新变更日志 -->
          <div v-if="versionInfo.changelog.length > 0" class="changelog-list">
            <div class="changelog-title">更新内容：</div>
            <div v-for="(log, idx) in versionInfo.changelog" :key="idx" class="changelog-item">
              <span class="changelog-arrow">&#8593;</span>
              <span class="mono">{{ log }}</span>
            </div>
          </div>

          <!-- 更新进度 -->
          <div v-if="updateProgress.status === 'running' || updateProgress.status === 'pending'" class="update-progress">
            <div class="progress-header">
              <span class="progress-step">{{ updateProgress.step || '准备中...' }}</span>
              <span class="progress-percent">{{ updateProgress.progress }}%</span>
            </div>
            <el-progress
              :percentage="updateProgress.progress"
              :stroke-width="8"
              :show-text="false"
              status=""
            />
            <div v-if="updateProgress.logs.length > 0" class="progress-logs">
              <div v-for="(log, idx) in updateProgress.logs.slice(-5)" :key="idx" class="log-line mono">
                {{ log }}
              </div>
            </div>
          </div>

          <!-- 更新结果 -->
          <div v-if="updateProgress.status === 'success'" class="update-result success">
            <el-icon><CircleCheckFilledIcon /></el-icon>
            <span>更新成功！系统已升级到最新版本</span>
          </div>
          <div v-if="updateProgress.status === 'failed'" class="update-result failed">
            <span>更新失败：{{ updateProgress.message }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
// 图标重命名导入（避免与 unplugin-vue-components 自动导入的组件名冲突）
import {
  Monitor as MonitorIcon,
  Promotion as PromotionIcon,
  Message as MessageIcon,
  Lock as LockIcon,
  Cpu as CpuIcon,
  Check as CheckIcon,
  Refresh as RefreshIcon,
  View as ViewIcon,
  Hide as HideIcon,
  InfoFilled as InfoFilledIcon,
  Lightning as LightningIcon,
  Coin as CoinIcon,
  DataLine as DataLineIcon,
  Histogram as HistogramIcon,
  Clock as ClockIcon,
  CircleCheckFilled as CircleCheckFilledIcon,
  WarningFilled as WarningFilledIcon,
  Key as KeyIcon,
  Upload as UploadIcon,
  Download as DownloadIcon,
  CircleCheck as CircleCheckIcon,
  CircleClose as CircleCloseIcon
} from '@element-plus/icons-vue'
import {
  getSettingsApi,
  updateSettingsApi,
  checkPortApi,
  getMailConfigApi,
  saveMailConfigApi,
  testMailConfigApi,
  getSystemInfoApi,
  checkVersionApi,
  systemUpdateApi,
  getUpdateProgressApi
} from '@/api/settings'
import { concurrentTestPushApi } from '@/api/push'
import type { ConcurrentTestResult } from '@/api/types'

const loading = ref(false)

// ---- 服务器配置 ----
const serverFormRef = ref<FormInstance>()
const serverForm = reactive({
  frontendUrl: '',
  frontendPort: 0,
  httpApiUrl: '',
  httpPort: 0,
  websocketUrl: '',
  websocketPort: 0,
  sslEnabled: false
})
const serverRules: FormRules = {
  frontendUrl: [
    { pattern: /^https?:\/\/.+/, message: '请输入合法地址（以 http:// 或 https:// 开头）', trigger: 'blur' }
  ],
  httpApiUrl: [
    { required: true, message: '请输入 HTTP API 地址', trigger: 'blur' },
    { pattern: /^https?:\/\/.+/, message: '请输入合法地址', trigger: 'blur' }
  ],
  httpPort: [{ required: true, message: '请输入端口', trigger: 'blur' }],
  websocketUrl: [
    { required: true, message: '请输入 WebSocket 地址', trigger: 'blur' },
    { pattern: /^wss?:\/\/.+/, message: '请输入合法 ws/wss 地址', trigger: 'blur' }
  ],
  websocketPort: [{ required: true, message: '请输入端口', trigger: 'blur' }]
}

// 端口检测结果
const portCheck = reactive<Record<string, { available: boolean; message: string } | null>>({
  frontend: null,
  http: null,
  websocket: null
})

// 端口变更时自动检测
async function onPortChange(type: 'frontend' | 'http' | 'websocket', port: number) {
  if (!port || port <= 0) {
    portCheck[type] = { available: true, message: '使用默认端口（80/443）' }
    return
  }
  try {
    const res: any = await checkPortApi(port)
    const data = res.data || res
    if (data.available) {
      if (data.is_privileged) {
        portCheck[type] = { available: false, message: `端口 ${port} 是系统保留端口（1-1023），需要 root 权限` }
      } else if (data.well_known) {
        portCheck[type] = { available: false, message: `端口 ${port} 通常被 ${data.well_known} 占用，不建议使用` }
      } else {
        portCheck[type] = { available: true, message: `端口 ${port} 可用` }
      }
    } else {
      const proc = data.process ? `（被 ${data.process} 占用）` : ''
      portCheck[type] = { available: false, message: `端口 ${port} 已被占用${proc}` }
    }
  } catch {
    portCheck[type] = null
  }
}

// ---- 推送配置 ----
const pushFormRef = ref<FormInstance>()
const pushForm = reactive({
  heartbeatInterval: 60,
  offlineRetention: 72,
  maxConnections: 100000
})
const pushRules: FormRules = {
  heartbeatInterval: [{ required: true, message: '请设置心跳间隔', trigger: 'change' }],
  offlineRetention: [{ required: true, message: '请设置保留时长', trigger: 'blur' }],
  maxConnections: [{ required: true, message: '请设置连接限制', trigger: 'blur' }]
}

// ---- 验证码配置 ----
const captchaFormRef = ref<FormInstance>()
const captchaForm = reactive({
  enabled: 1,
  smsEnabled: 1,
  emailEnabled: 1,
  smsApiKey: '',
  smsApiUrl: '',
  mailHost: '',
  mailPort: 465,
  mailUsername: '',
  mailPassword: '',
  mailFrom: ''
})
const captchaRules: FormRules = {
  mailHost: [{ required: true, message: '请输入 SMTP 主机', trigger: 'blur' }],
  mailPort: [{ required: true, message: '请输入端口', trigger: 'blur' }],
  mailUsername: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  mailFrom: [
    { required: true, message: '请输入发件人', trigger: 'blur' },
    { type: 'email', message: '邮箱格式不正确', trigger: 'blur' }
  ]
}

// ---- 安全配置 ----
const securityFormRef = ref<FormInstance>()
const securityForm = reactive({
  jwtSecret: '',
  aesKey: '',
  passwordMinLength: 8,
  loginFailLimit: 5
})
const securityRules: FormRules = {
  jwtSecret: [{ required: true, message: 'JWT 密钥不能为空', trigger: 'blur' }],
  aesKey: [{ required: true, message: 'AES 密钥不能为空', trigger: 'blur' }],
  passwordMinLength: [{ required: true, message: '请设置密码最小长度', trigger: 'blur' }],
  loginFailLimit: [{ required: true, message: '请设置锁定次数', trigger: 'blur' }]
}

// ---- 邮件通知配置 ----
const mailFormRef = ref<FormInstance>()
const mailForm = reactive({
  enabled: false,
  host: 'smtp.qq.com',
  port: '587',
  username: '',
  password: '',
  encryption: 'tls',
  sender_name: '',
  testEmail: ''
})

// 密码切换显示
const showSecret = reactive({
  smsApiKey: false,
  mailPassword: false,
  mailNotifyPassword: false,
  jwtSecret: false,
  aesKey: false
})
function toggleSecret(key: keyof typeof showSecret) {
  showSecret[key] = !showSecret[key]
}

// 重新生成密钥
function regenerateSecret(key: 'jwtSecret' | 'aesKey') {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
  const length = 32
  let result = ''
  for (let i = 0; i < length; i++) {
    result += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  securityForm[key] = result
  ElMessage.success('密钥已重新生成，请保存配置')
}

// ---- 系统信息 ----
const systemInfoLoading = ref(false)
const systemInfo = reactive({
  version: 'v2.5.0',
  phpVersion: '8.2.7',
  swooleVersion: '5.0.3',
  redisStatus: 'ok' as 'ok' | 'error',
  mysqlStatus: 'ok' as 'ok' | 'error',
  diskUsed: 0,
  diskTotal: 0,
  uptime: 0
})

const diskPercent = computed(() => {
  if (!systemInfo.diskTotal) return 0
  return Math.round((systemInfo.diskUsed / systemInfo.diskTotal) * 100)
})

function formatBytes(bytes: number): string {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let i = 0
  let n = bytes
  while (n >= 1024 && i < units.length - 1) {
    n /= 1024
    i++
  }
  return n.toFixed(i === 0 ? 0 : 1) + ' ' + units[i]
}

function formatUptime(seconds: number): string {
  if (!seconds) return '-'
  const d = Math.floor(seconds / 86400)
  const h = Math.floor((seconds % 86400) / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  if (d > 0) return `${d}天 ${h}小时 ${m}分钟`
  if (h > 0) return `${h}小时 ${m}分钟`
  return `${m}分钟`
}

async function fetchSystemInfo() {
  systemInfoLoading.value = true
  try {
    const res = await getSystemInfoApi()
    const d = res.data
    systemInfo.version = d.version || systemInfo.version
    systemInfo.diskUsed = d.disk?.used || 0
    systemInfo.diskTotal = d.disk?.total || 0
    systemInfo.uptime = d.uptime || 0
  } catch {
    // 接口未就绪时使用占位数据
    systemInfo.diskUsed = 18.6 * 1024 * 1024 * 1024
    systemInfo.diskTotal = 50 * 1024 * 1024 * 1024
    systemInfo.uptime = 86400 * 12 + 3600 * 5
  } finally {
    systemInfoLoading.value = false
  }
}

// ---- 版本检测与一键更新 ----
const versionChecking = ref(false)
const updating = ref(false)
const versionInfo = reactive({
  local: { commit: '', short: '', date: '' },
  remote: { commit: '', short: '', branch: '', date: '' },
  status: '' as '' | 'up-to-date' | 'behind' | 'ahead' | 'diverged' | 'unknown',
  aheadCount: 0,
  behindCount: 0,
  changelog: [] as string[]
})
const updateProgress = reactive({
  taskId: '',
  status: '' as '' | 'pending' | 'running' | 'success' | 'failed',
  step: '',
  progress: 0,
  message: '',
  logs: [] as string[]
})
let progressTimer: ReturnType<typeof setInterval> | null = null

// 版本检测
async function checkVersion() {
  versionChecking.value = true
  try {
    const res = await checkVersionApi({ ghProxy: true })
    const d = res.data
    versionInfo.local = d.local
    versionInfo.remote = d.remote
    versionInfo.status = d.status
    versionInfo.aheadCount = d.ahead_count
    versionInfo.behindCount = d.behind_count
    versionInfo.changelog = d.changelog || []

    if (d.status === 'up-to-date') {
      ElMessage.success('当前已是最新版本')
    } else if (d.status === 'behind') {
      ElMessage.warning(`有新版本可用，落后 ${d.ahead_count} 个提交`)
    } else if (d.status === 'ahead') {
      ElMessage.info(`本地版本领先 ${d.behind_count} 个提交`)
    } else if (d.status === 'diverged') {
      ElMessage.warning('本地与云端版本已分叉')
    }
  } catch {
    ElMessage.error('版本检测失败，请检查服务器网络连接')
  } finally {
    versionChecking.value = false
  }
}

// 一键更新
async function startUpdate() {
  updating.value = true
  updateProgress.status = 'pending'
  updateProgress.step = '准备中'
  updateProgress.progress = 0
  updateProgress.logs = []
  updateProgress.message = ''

  try {
    const res = await systemUpdateApi({ ghProxy: true })
    updateProgress.taskId = res.data.task_id
    updateProgress.status = 'running'
    ElMessage.info('更新已启动，请勿关闭页面...')

    // 轮询更新进度
    progressTimer = setInterval(pollUpdateProgress, 3000)
  } catch (err) {
    updateProgress.status = 'failed'
    updateProgress.message = err instanceof Error ? err.message : '启动更新失败'
    updating.value = false
    ElMessage.error(updateProgress.message)
  }
}

async function pollUpdateProgress() {
  if (!updateProgress.taskId) return
  try {
    const res = await getUpdateProgressApi(updateProgress.taskId)
    const d = res.data
    updateProgress.status = d.status
    updateProgress.step = d.step
    updateProgress.progress = d.progress
    updateProgress.message = d.message
    updateProgress.logs = d.logs || []

    if (d.status === 'success') {
      stopProgressPolling()
      updating.value = false
      ElMessage.success('系统更新完成！')
      fetchSystemInfo()
      checkVersion()
    } else if (d.status === 'failed') {
      stopProgressPolling()
      updating.value = false
      ElMessage.error('更新失败：' + (d.message || '未知错误'))
    }
  } catch {
    // 轮询失败不中断
  }
}

function stopProgressPolling() {
  if (progressTimer) {
    clearInterval(progressTimer)
    progressTimer = null
  }
}

// 版本状态文本与颜色
const versionStatusText = computed(() => {
  switch (versionInfo.status) {
    case 'up-to-date': return '已是最新版本'
    case 'behind': return `落后 ${versionInfo.aheadCount} 个提交`
    case 'ahead': return `领先 ${versionInfo.behindCount} 个提交`
    case 'diverged': return '版本已分叉'
    default: return '未检测'
  }
})

const versionStatusType = computed(() => {
  switch (versionInfo.status) {
    case 'up-to-date': return 'success'
    case 'behind': return 'warning'
    case 'ahead': return 'info'
    case 'diverged': return 'danger'
    default: return 'info'
  }
})

// ---- 保存状态 ----
const saving = reactive({
  server: false,
  push: false,
  captcha: false,
  mail: false,
  security: false
})
const testing = reactive({
  mail: false,
  mailNotify: false,
  concurrent: false
})

// ---- 并发压测推送 ----
const concurrentForm = reactive({
  targetType: 'key' as 'device' | 'key',
  targetValue: '',
  title: '',
  content: '',
  priority: 'high' as 'high' | 'normal' | 'low',
  concurrency: 10,
  total: 100,
  intervalMs: 0
})
const concurrentResult = ref<ConcurrentTestResult | null>(null)

async function runConcurrentTest() {
  if (!concurrentForm.targetValue.trim()) {
    ElMessage.warning('请输入目标设备 ID 或推送 Key')
    return
  }
  if (concurrentForm.concurrency < 1 || concurrentForm.concurrency > 1000) {
    ElMessage.warning('并发数范围为 1-1000')
    return
  }
  if (concurrentForm.total < 0 || concurrentForm.total > 10000) {
    ElMessage.warning('总推送次数范围为 0-10000')
    return
  }

  testing.concurrent = true
  concurrentResult.value = null
  try {
    const res = await concurrentTestPushApi({
      target_type: concurrentForm.targetType,
      target_value: concurrentForm.targetValue.trim(),
      title: concurrentForm.title || undefined,
      content: concurrentForm.content || undefined,
      priority: concurrentForm.priority,
      concurrency: concurrentForm.concurrency,
      total: concurrentForm.total,
      interval_ms: concurrentForm.intervalMs
    })
    concurrentResult.value = res.data
    if (res.data.fail_count === 0) {
      ElMessage.success(`并发压测完成，共推送 ${res.data.total_sent} 条，全部成功`)
    } else {
      ElMessage.warning(`并发压测完成：成功 ${res.data.success_count} / 失败 ${res.data.fail_count}`)
    }
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : '并发压测失败')
  } finally {
    testing.concurrent = false
  }
}

const configCount = computed(() => 4)

// 自动检测服务器地址
function detectServerUrls() {
  const protocol = window.location.protocol // http: or https:
  const host = window.location.hostname // e.g., 124.222.43.74
  const port = window.location.port || (protocol === 'https:' ? '443' : '80')

  const httpUrl = `${protocol}//${host}`
  const wsProtocol = protocol === 'https:' ? 'wss:' : 'ws:'
  const wsUrl = `${wsProtocol}//${host}`

  return {
    httpApiUrl: httpUrl,
    httpPort: parseInt(port),
    websocketUrl: wsUrl,
    websocketPort: parseInt(port)
  }
}

// SSL 开关切换：自动调整地址协议和端口
function onSslToggle(val: string | number | boolean) {
  const enabled = Boolean(val)
  if (enabled) {
    // 开启 SSL：http:// -> https://，ws:// -> wss://
    if (serverForm.frontendUrl && serverForm.frontendUrl.startsWith('http://')) {
      serverForm.frontendUrl = 'https://' + serverForm.frontendUrl.slice(7)
    }
    if (serverForm.httpApiUrl && serverForm.httpApiUrl.startsWith('http://')) {
      serverForm.httpApiUrl = 'https://' + serverForm.httpApiUrl.slice(7)
    }
    if (serverForm.websocketUrl && serverForm.websocketUrl.startsWith('ws://')) {
      serverForm.websocketUrl = 'wss://' + serverForm.websocketUrl.slice(5)
    }
    // 端口为 0（默认）或 80 时，切到 SSL 不需要改端口（仍由 Nginx 监听 443）
    ElMessage.success('SSL 已启用，地址已自动切换为 https/wss')
  } else {
    // 关闭 SSL：https:// -> http://，wss:// -> ws://
    if (serverForm.frontendUrl && serverForm.frontendUrl.startsWith('https://')) {
      serverForm.frontendUrl = 'http://' + serverForm.frontendUrl.slice(8)
    }
    if (serverForm.httpApiUrl && serverForm.httpApiUrl.startsWith('https://')) {
      serverForm.httpApiUrl = 'http://' + serverForm.httpApiUrl.slice(8)
    }
    if (serverForm.websocketUrl && serverForm.websocketUrl.startsWith('wss://')) {
      serverForm.websocketUrl = 'ws://' + serverForm.websocketUrl.slice(6)
    }
    ElMessage.info('SSL 已关闭，地址已切换为 http/ws')
  }
}

// 验证码开关切换提示
function onCaptchaToggle(val: string | number | boolean) {
  const enabled = val === 1 || val === true || val === '1'
  if (enabled) {
    ElMessage.success('验证码已启用，注册和登录将需要验证码验证')
  } else {
    ElMessage.warning('验证码已关闭，注册和登录无需验证码（请确认已保存生效）')
  }
}

// 短信验证码开关切换
function onSmsCaptchaToggle(val: string | number | boolean) {
  const enabled = val === 1 || val === true || val === '1'
  if (enabled) {
    ElMessage.success('短信验证码已启用')
  } else {
    ElMessage.warning('短信验证码已关闭，注册时手机号无需短信验证（请确认已保存生效）')
  }
}

// 邮箱验证码开关切换
function onEmailCaptchaToggle(val: string | number | boolean) {
  const enabled = val === 1 || val === true || val === '1'
  if (enabled) {
    ElMessage.success('邮箱验证码已启用')
  } else {
    ElMessage.warning('邮箱验证码已关闭，注册时邮箱无需邮件验证（请确认已保存生效）')
  }
}

// 手动触发自动检测并填充
function autoDetectServer() {
  const detected = detectServerUrls()
  serverForm.frontendUrl = detected.httpApiUrl
  serverForm.frontendPort = detected.httpPort
  serverForm.httpApiUrl = detected.httpApiUrl
  serverForm.httpPort = detected.httpPort
  serverForm.websocketUrl = detected.websocketUrl
  serverForm.websocketPort = detected.websocketPort
  ElMessage.success('服务器地址已自动检测并填充')
}

// 加载配置
async function fetchSettings() {
  loading.value = true
  try {
    const res = await getSettingsApi()
    const s = res.data

    // 如果后端返回了服务器配置，使用后端的值；否则自动检测
    if (s?.server) {
      serverForm.frontendUrl = s.server.frontendUrl || ''
      serverForm.frontendPort = s.server.frontendPort ?? 0
      serverForm.httpApiUrl = s.server.httpApiUrl || ''
      serverForm.httpPort = s.server.httpPort ?? 0
      serverForm.websocketUrl = s.server.websocketUrl || ''
      serverForm.websocketPort = s.server.websocketPort ?? 0
      serverForm.sslEnabled = s.server.sslEnabled ?? (serverForm.httpApiUrl.startsWith('https://'))
    }

    // 如果没有配置，自动检测并填充
    if (!serverForm.httpApiUrl) {
      const detected = detectServerUrls()
      serverForm.frontendUrl = detected.httpApiUrl
      serverForm.frontendPort = detected.httpPort
      serverForm.httpApiUrl = detected.httpApiUrl
      serverForm.httpPort = detected.httpPort
      serverForm.websocketUrl = detected.websocketUrl
      serverForm.websocketPort = detected.websocketPort
      serverForm.sslEnabled = detected.httpApiUrl.startsWith('https://')
    }

    // 加载推送配置
    if (s?.push) {
      pushForm.heartbeatInterval = s.push.heartbeatInterval || 60
      pushForm.offlineRetention = s.push.offlineRetention || 72
      pushForm.maxConnections = s.push.maxConnections || 100000
    }

    // 加载验证码配置
    if (s?.captcha) {
      captchaForm.enabled = s.captcha.enabled ?? 1
      captchaForm.smsApiKey = s.captcha.smsApiKey || ''
      captchaForm.smsApiUrl = s.captcha.smsApiUrl || ''
      captchaForm.mailHost = s.captcha.mailHost || ''
      captchaForm.mailPort = s.captcha.mailPort || 465
      captchaForm.mailUsername = s.captcha.mailUsername || ''
      captchaForm.mailPassword = s.captcha.mailPassword || ''
      captchaForm.mailFrom = s.captcha.mailFrom || ''
    }

    // 加载安全配置
    if (s?.security) {
      securityForm.jwtSecret = s.security.jwtSecret || ''
      securityForm.aesKey = s.security.aesKey || ''
      securityForm.passwordMinLength = s.security.passwordMinLength || 8
      securityForm.loginFailLimit = s.security.loginFailLimit || 5
    }
  } catch {
    // 接口未就绪时自动检测服务器地址
    const detected = detectServerUrls()
    serverForm.httpApiUrl = detected.httpApiUrl
    serverForm.httpPort = detected.httpPort
    serverForm.websocketUrl = detected.websocketUrl
    serverForm.websocketPort = detected.websocketPort
  } finally {
    loading.value = false
  }
}

// 加载邮件配置
async function fetchMailConfig() {
  try {
    const res = await getMailConfigApi()
    const config = res.data
    mailForm.enabled = config.enabled
    mailForm.host = config.host || 'smtp.qq.com'
    mailForm.port = config.port || '587'
    mailForm.username = config.username
    mailForm.password = config.password
    mailForm.encryption = config.encryption || 'tls'
    mailForm.sender_name = config.sender_name
  } catch {
    // 使用默认值
  }
}

// 保存各个分组
async function saveSection(section: 'server' | 'push' | 'captcha' | 'security') {
  const formRefMap = {
    server: serverFormRef,
    push: pushFormRef,
    captcha: captchaFormRef,
    security: securityFormRef
  }
  const formRef = formRefMap[section].value
  if (!formRef) return
  try {
    await formRef.validate()
  } catch {
    ElMessage.warning('请完善表单必填项')
    return
  }

  saving[section] = true
  try {
    const payload: Record<string, any> = {}
    if (section === 'server') {
      payload.server = { ...serverForm }
    } else if (section === 'push') {
      payload.push = { ...pushForm }
    } else if (section === 'captcha') {
      payload.captcha = { ...captchaForm }
    } else if (section === 'security') {
      payload.security = { ...securityForm }
    }
    const res = await updateSettingsApi(payload)
    // 端口变更需重启服务生效
    if (res.data?.need_restart) {
      ElMessageBox.alert(
        '端口配置已写入 .env 文件并自动重启了相关服务。\n\n如果服务未自动重启（权限不足），请手动执行：\nsudo systemctl restart push-http push-websocket',
        '端口已更新',
        {
          confirmButtonText: '我知道了',
          type: 'success',
          appendTo: 'body'
        }
      )
    } else {
      ElMessage.success('配置保存成功')
    }
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : '保存失败')
  } finally {
    saving[section] = false
  }
}

// 保存邮件通知配置
async function saveMailConfig() {
  saving.mail = true
  try {
    await saveMailConfigApi({
      enabled: mailForm.enabled,
      host: mailForm.host,
      port: mailForm.port,
      username: mailForm.username,
      password: mailForm.password,
      encryption: mailForm.encryption,
      sender_name: mailForm.sender_name
    })
    ElMessage.success('邮件配置已保存')
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : '保存失败')
  } finally {
    saving.mail = false
  }
}

// 测试邮件通知
async function testMailNotify() {
  const to = mailForm.username
  if (!to || !mailForm.enabled) {
    ElMessage.warning('请先启用邮件通知并填写发件人邮箱')
    return
  }
  testing.mailNotify = true
  try {
    const res = await testMailConfigApi({
      to,
      host: mailForm.host,
      port: mailForm.port,
      username: mailForm.username,
      password: mailForm.password,
      encryption: mailForm.encryption,
      sender_name: mailForm.sender_name
    })
    ElMessage.success(res.data?.message || '测试邮件发送成功')
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : '测试发送失败')
  } finally {
    testing.mailNotify = false
  }
}

// 测试邮件
async function testMail() {
  if (!captchaForm.mailHost || !captchaForm.mailPort || !captchaForm.mailFrom) {
    ElMessage.warning('请先填写 SMTP 主机、端口和发件人')
    return
  }
  testing.mail = true
  try {
    const res = await testMailConfigApi({
      to: captchaForm.mailFrom,
      host: captchaForm.mailHost,
      port: String(captchaForm.mailPort),
      username: captchaForm.mailUsername || '',
      password: captchaForm.mailPassword || '',
      encryption: 'ssl',
      sender_name: captchaForm.mailFrom
    })
    ElMessage.success(res.data?.message || '测试邮件已发送，请查收')
  } catch (err) {
    ElMessage.error(err instanceof Error ? err.message : '测试发送失败')
  } finally {
    testing.mail = false
  }
}

onMounted(async () => {
  await fetchSettings()
  fetchMailConfig()
  fetchSystemInfo()
  // 加载完成后对已配置的端口做一次可用性检测
  if (serverForm.frontendPort && serverForm.frontendPort > 0) {
    onPortChange('frontend', serverForm.frontendPort)
  }
  if (serverForm.httpPort && serverForm.httpPort > 0) {
    onPortChange('http', serverForm.httpPort)
  }
  if (serverForm.websocketPort && serverForm.websocketPort > 0) {
    onPortChange('websocket', serverForm.websocketPort)
  }
})

onUnmounted(() => {
  stopProgressPolling()
})
</script>

<style lang="scss" scoped>
.settings-page {
  animation: fade-up 0.5s ease;
}

// ===== 并发压测推送卡片 =====
.concurrent-test-card {
  .icon-concurrent {
    background: $gradient-cyan;
    box-shadow: 0 6px 18px rgba(92, 184, 255, 0.32);
  }
}

.concurrent-tip {
  margin: 12px 0;

  .concurrent-tip-content {
    line-height: 1.8;
    font-size: 12px;

    strong {
      display: block;
      margin-bottom: 4px;
    }
  }
}

.concurrent-result {
  margin-top: 16px;
  padding: 16px;
  border-radius: $radius-md;
  background: var(--bg-page);
  border: 1px solid var(--border-light);

  .result-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;

    .result-title {
      font-size: 14px;
      font-weight: 700;
      color: var(--text-primary);
    }
  }

  .result-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
  }

  .result-item {
    padding: 10px 12px;
    border-radius: $radius-sm;
    background: var(--bg-card);
    border: 1px solid var(--border-light);
    display: flex;
    flex-direction: column;
    gap: 4px;

    &.highlight {
      background: linear-gradient(135deg, rgba(24, 194, 156, 0.1), rgba(92, 184, 255, 0.06));
      border-color: rgba(24, 194, 156, 0.3);

      .result-value {
        color: $color-success;
        font-size: 18px;
      }
    }

    .result-label {
      font-size: 11px;
      color: var(--text-secondary);
    }

    .result-value {
      font-size: 15px;
      font-weight: 700;
      color: var(--text-primary);

      &.success {
        color: $color-success;
      }
      &.danger {
        color: $color-danger;
      }
    }
  }

  .result-detail {
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: $radius-sm;
    background: rgba(255, 90, 110, 0.06);
    border: 1px solid rgba(255, 90, 110, 0.2);

    .detail-title {
      font-size: 12px;
      font-weight: 700;
      color: $color-danger;
      margin-bottom: 6px;
    }

    .detail-line {
      font-size: 11px;
      color: var(--text-regular);
      padding: 2px 0;
      word-break: break-all;
    }
  }
}

// ===== 验证码开关独立卡片 =====
.captcha-toggle-card {
  .captcha-toggle-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px 24px;
    background: linear-gradient(135deg, rgba(64, 158, 255, 0.08), rgba(103, 194, 58, 0.06));
    border-radius: 12px;
    margin-bottom: 16px;

    .captcha-toggle-main {
      display: flex;
      align-items: center;
      gap: 12px;

      .captcha-toggle-text {
        font-size: 15px;
        font-weight: 600;
        color: #303133;
      }
    }
  }

  .captcha-sub-toggles {
    padding: 0 24px 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;

    .sub-toggle-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 16px;
      background: #f8fafc;
      border-radius: 10px;
      border: 1px solid #eef0f3;
      transition: all 0.2s ease;

      &:hover {
        background: #f0f4ff;
        border-color: #d9e4ff;
      }

      .sub-toggle-left {
        display: flex;
        align-items: center;
        gap: 12px;

        .sub-toggle-icon {
          font-size: 22px;
        }

        .sub-toggle-text {
          .sub-toggle-title {
            font-size: 14px;
            font-weight: 600;
            color: #303133;
            margin-bottom: 2px;
          }

          .sub-toggle-desc {
            font-size: 12px;
            color: #909399;
          }
        }
      }
    }
  }

  .captcha-toggle-actions {
    margin-top: 0;
  }
}

// ===== SSL 开关 =====
.ssl-toggle-row {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;

  .ssl-toggle-hint {
    color: #909399;
    font-size: 12px;
  }
}

.ssl-tip {
  margin-top: 6px;
  font-size: 12px;
  color: #e6a23c;
  line-height: 1.5;
}

// ===== Hero 区 =====
.page-hero {
  position: relative;
  border-radius: $radius-xl;
  padding: 24px 32px;
  margin-bottom: 20px;
  overflow: hidden;
  background: $gradient-primary;
  box-shadow: $shadow-primary;

  .hero-bg {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
  }

  .hero-blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(60px);

    &.blob-a {
      width: 280px;
      height: 280px;
      background: radial-gradient(circle, #ffffff, transparent 70%);
      top: -120px;
      right: -60px;
      opacity: 0.2;
    }
    &.blob-b {
      width: 240px;
      height: 240px;
      background: radial-gradient(circle, #5cb8ff, transparent 70%);
      bottom: -100px;
      left: 40%;
      opacity: 0.35;
    }
  }

  .hero-content {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
  }

  .hero-title {
    margin: 0;
    font-size: 24px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.3px;
  }
  .hero-sub {
    margin: 6px 0 0;
    color: rgba(255, 255, 255, 0.88);
    font-size: 13px;
  }

  .hero-stats {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 10px 20px;
    border-radius: $radius-lg;
    background: rgba(255, 255, 255, 0.18);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.25);

    .stat-mini {
      display: flex;
      flex-direction: column;
      gap: 4px;

      .stat-label {
        color: rgba(255, 255, 255, 0.75);
        font-size: 11px;
      }
      .stat-value {
        color: #fff;
        font-size: 16px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 4px;

        &.status-ok {
          color: #d4ffe9;
        }
      }
    }

    .stat-divider {
      width: 1px;
      height: 28px;
      background: rgba(255, 255, 255, 0.25);
    }
  }
}

// ===== 设置网格 =====
.settings-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}

// ===== 设置卡片 =====
.setting-card {
  background: var(--bg-card);
  border-radius: $radius-xl;
  padding: 24px;
  border: 1px solid var(--border-light);
  box-shadow: $shadow-md;
  transition: box-shadow 0.3s ease, transform 0.3s ease;
  animation: fade-up 0.5s ease backwards;

  &:nth-child(1) { animation-delay: 0.05s; }
  &:nth-child(2) { animation-delay: 0.1s; }
  &:nth-child(3) { animation-delay: 0.15s; }
  &:nth-child(4) { animation-delay: 0.2s; }
  &:nth-child(5) { animation-delay: 0.25s; }
  &:nth-child(6) { animation-delay: 0.3s; }

  &:hover {
    box-shadow: $shadow-lg;
    transform: translateY(-2px);
  }

  // 第 5 张卡片跨两列
  &.system-info-card {
    grid-column: span 2;
  }
}

.card-head {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 22px;

  .head-icon {
    width: 46px;
    height: 46px;
    border-radius: $radius-md;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 22px;
    flex-shrink: 0;

    &.icon-server {
      background: $gradient-primary;
      box-shadow: 0 6px 18px rgba(109, 92, 255, 0.32);
    }
    &.icon-push {
      background: $gradient-cyan;
      box-shadow: 0 6px 18px rgba(92, 184, 255, 0.32);
    }
    &.icon-captcha {
      background: $gradient-warm;
      box-shadow: 0 6px 18px rgba(255, 181, 71, 0.32);
    }
    &.icon-security {
      background: $gradient-danger;
      box-shadow: 0 6px 18px rgba(255, 90, 110, 0.32);
    }
    &.icon-info {
      background: $gradient-success;
      box-shadow: 0 6px 18px rgba(24, 194, 156, 0.32);
    }
    &.icon-mail {
      background: $gradient-warm;
      box-shadow: 0 6px 18px rgba(255, 181, 71, 0.32);
    }
  }

  .head-text {
    flex: 1;
  }

  .card-title {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
    color: var(--text-primary);
  }
  .card-sub {
    margin: 4px 0 0;
    font-size: 12px;
    color: var(--text-secondary);
  }
}

// 表单
.setting-form {
  :deep(.el-form-item__label) {
    font-weight: 600;
    color: var(--text-regular);
    padding-bottom: 6px;
    font-size: 13px;
  }

  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }
}

// 端口检测结果
.port-check-result {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  margin-top: 4px;
  padding: 4px 8px;
  border-radius: 4px;

  &.port-ok {
    color: #67c23a;
    background: #f0f9eb;
  }

  &.port-bad {
    color: #f56c6c;
    background: #fef0f0;
  }
}

// 端口提示
.port-tip-alert {
  margin: 12px 0;

  .port-tip-content {
    line-height: 1.8;
    font-size: 12px;

    strong {
      display: block;
      margin-bottom: 4px;
    }
  }
}

// 子标题
.sub-section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 8px 0 16px;
  font-size: 13px;
  font-weight: 700;
  color: var(--text-regular);

  .title-bar {
    width: 3px;
    height: 14px;
    border-radius: 2px;
    background: $gradient-primary;
  }
}

// 密码切换图标
.toggle-eye {
  cursor: pointer;
  color: var(--text-secondary);
  transition: color 0.2s ease;

  &:hover {
    color: $color-primary;
  }
}

// 滑块
.slider-wrap {
  width: 100%;

  :deep(.el-slider) {
    --el-slider-main-bg-color: #{$color-primary};
    margin-right: 16px;
  }

  .slider-marks {
    display: flex;
    justify-content: space-between;
    margin-top: 4px;
    font-size: 11px;
    color: var(--text-secondary);
  }
}

// 操作按钮区
.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px dashed var(--border-light);
}

// ===== 系统信息卡片 =====
.info-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 14px;
}

.info-item {
  padding: 14px 16px;
  border-radius: $radius-md;
  background: var(--bg-page);
  border: 1px solid var(--border-light);
  transition: all 0.25s ease;

  &:hover {
    border-color: $color-primary-light-5;
    background: $color-primary-light-9;
  }

  .info-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--text-secondary);
    margin-bottom: 8px;

    .el-icon {
      color: $color-primary;
      font-size: 14px;
    }
  }

  .info-value {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 6px;

    &.mono {
      font-family: $font-family-mono;
      font-size: 15px;
    }
  }
}

.status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  display: inline-block;

  &.ok {
    background: $color-success;
    box-shadow: 0 0 0 3px rgba(24, 194, 156, 0.2);
    animation: pulse-dot 1.6s ease infinite;
  }
  &.error {
    background: $color-danger;
    box-shadow: 0 0 0 3px rgba(255, 90, 110, 0.2);
  }
}

.disk-bar {
  width: 100%;
  height: 6px;
  background: var(--border-base);
  border-radius: $radius-pill;
  overflow: hidden;
  margin-bottom: 4px;

  .disk-used {
    height: 100%;
    background: $gradient-success;
    border-radius: $radius-pill;
    transition: width 0.5s ease;

    &.warn {
      background: $gradient-warm;
    }
    &.danger {
      background: $gradient-danger;
    }
  }
}

.disk-text {
  font-size: 12px;
  color: var(--text-secondary);
  font-family: $font-family-mono;
}

.uptime-row {
  margin-top: 18px;
  padding: 12px 16px;
  border-radius: $radius-md;
  background: linear-gradient(135deg, rgba(109, 92, 255, 0.06), rgba(92, 184, 255, 0.04));
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--text-regular);

  .el-icon {
    color: $color-primary;
  }

  .uptime-value {
    font-weight: 700;
    color: $color-primary;
    font-family: $font-family-mono;
    margin-left: 4px;
  }
}

// ===== 版本检测与更新 =====
.version-check-section {
  margin-top: 18px;
  padding: 16px;
  border-radius: $radius-md;
  background: var(--bg-page);
  border: 1px solid var(--border-light);
}

.version-check-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.version-check-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 700;
  color: var(--text-primary);

  .el-icon {
    color: $color-primary;
    font-size: 18px;
  }
}

.version-check-actions {
  display: flex;
  gap: 8px;
}

.version-result {
  margin-bottom: 12px;
}

.version-compare {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 10px;
}

.version-col {
  padding: 10px 14px;
  border-radius: $radius-sm;
  background: var(--bg-card);
  border: 1px solid var(--border-light);
  display: flex;
  flex-direction: column;
  gap: 4px;

  .version-label {
    font-size: 11px;
    color: var(--text-secondary);
  }

  .version-hash {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
  }

  .version-date {
    font-size: 11px;
    color: var(--text-secondary);
    font-family: $font-family-mono;
  }
}

.version-status-tag {
  font-weight: 600;
}

.changelog-list {
  margin-top: 12px;
  padding: 10px 14px;
  border-radius: $radius-sm;
  background: var(--bg-card);
  border: 1px solid var(--border-light);

  .changelog-title {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-regular);
    margin-bottom: 6px;
  }

  .changelog-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 3px 0;
    font-size: 12px;
    color: var(--text-regular);

    .changelog-arrow {
      color: $color-warning;
      font-weight: 700;
    }
  }
}

.update-progress {
  margin-top: 12px;
  padding: 14px;
  border-radius: $radius-sm;
  background: var(--bg-card);
  border: 1px solid var(--border-light);

  .progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
    font-size: 13px;

    .progress-step {
      font-weight: 600;
      color: $color-primary;
    }

    .progress-percent {
      font-family: $font-family-mono;
      color: var(--text-secondary);
    }
  }

  .progress-logs {
    margin-top: 10px;
    max-height: 120px;
    overflow-y: auto;
    font-size: 11px;
    color: var(--text-secondary);
    background: var(--bg-page);
    border-radius: $radius-sm;
    padding: 8px;

    .log-line {
      padding: 2px 0;
      white-space: pre-wrap;
      word-break: break-all;
    }
  }
}

.update-result {
  margin-top: 12px;
  padding: 10px 14px;
  border-radius: $radius-sm;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;

  &.success {
    background: rgba(24, 194, 156, 0.08);
    color: $color-success;
    border: 1px solid rgba(24, 194, 156, 0.2);
  }

  &.failed {
    background: rgba(255, 90, 110, 0.08);
    color: $color-danger;
    border: 1px solid rgba(255, 90, 110, 0.2);
  }
}

// ===== 动画 =====
@keyframes fade-up {
  from {
    opacity: 0;
    transform: translateY(16px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes pulse-dot {
  0%, 100% {
    box-shadow: 0 0 0 0 rgba(24, 194, 156, 0.4);
  }
  50% {
    box-shadow: 0 0 0 4px rgba(24, 194, 156, 0);
  }
}

// ===== 响应式 =====
@media (max-width: 1100px) {
  .settings-grid {
    grid-template-columns: 1fr;
  }
  .setting-card.system-info-card {
    grid-column: span 1;
  }
  .info-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 640px) {
  .page-hero {
    padding: 20px;
  }
  .setting-form .form-row {
    grid-template-columns: 1fr;
  }
  .info-grid {
    grid-template-columns: 1fr;
  }
  .form-actions {
    flex-direction: column;

    .el-button {
      width: 100%;
    }
  }
  .concurrent-result .result-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

// ===== 暗色模式 =====
:global(html.dark) {
  .page-hero {
    background: linear-gradient(135deg, #4d38f0 0%, #7d4dff 100%);
  }
  .info-item {
    background: rgba(255, 255, 255, 0.02);

    &:hover {
      background: rgba(138, 124, 255, 0.08);
    }
  }
  .uptime-row {
    background: linear-gradient(135deg, rgba(109, 92, 255, 0.12), rgba(92, 184, 255, 0.08));
  }
  .version-check-section {
    background: rgba(255, 255, 255, 0.02);
  }
  .version-col {
    background: rgba(255, 255, 255, 0.04);
  }
  .changelog-list {
    background: rgba(255, 255, 255, 0.04);
  }
  .update-progress {
    background: rgba(255, 255, 255, 0.04);

    .progress-logs {
      background: rgba(0, 0, 0, 0.2);
    }
  }
}
</style>
