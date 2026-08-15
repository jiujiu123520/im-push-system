<template>
    <view :class="['glass-bg', themeClass]">
        <view class="top-bar">
            <view class="row" >
                <text class="icon-btn" @click="goBack" style="font-size:36rpx;width:72rpx;height:72rpx;">‹</text>
                <text class="top-bar-title" style="margin-left:20rpx;">使用帮助</text>
            </view>
            <view class="top-bar-subtitle" style="margin-top:12rpx;">常见问题与功能说明</view>
        </view>

        <view class="glass-card" v-for="(item, idx) in sections" :key="idx" style="margin-top:20rpx;">
            <view class="row-between" @click="toggle(idx)" style="padding:8rpx 0;">
                <view class="row">
                    <text style="font-size:32rpx;margin-right:16rpx;">{{ item.icon }}</text>
                    <text style="font-size:30rpx;font-weight:600;">{{ item.title }}</text>
                </view>
                <text class="text-muted" style="font-size:32rpx;transform:rotate(0deg);transition:transform .2s;" :style="{ transform: expanded[idx] ? 'rotate(90deg)' : 'rotate(0deg)' }">›</text>
            </view>
            <view v-if="expanded[idx]" style="margin-top:24rpx;font-size:26rpx;line-height:1.7;color:var(--text-secondary);">
                <view v-for="(p, pi) in item.body" :key="pi" style="margin-bottom:16rpx;" :class="p.type === 'tip' ? 'help-tip' : ''" v-html="p.text"></view>
            </view>
        </view>

        <view style="height:80rpx;"></view>
    </view>
</template>

<script>
import { getTheme, onThemeChange, offThemeChange } from '../../js/theme.js'
import { applySafeArea } from '../../js/safe-area.js'

export default {
    data() {
        return {
            themeClass: 'light',
            expanded: [true, false, false, false, false, false],
            sections: [
                {
                    icon: '🚀',
                    title: '快速开始（3 步连上服务器）',
                    body: [
                        { text: '<b>第 1 步：填写服务器配置</b><br/>进入「服务器配置」页，粘贴你的 Push Key 和服务器地址。WebSocket 地址留空时，会自动从 HTTP 地址推导。', type: '' },
                        { text: '<b>第 2 步：测试连接</b><br/>点击「测试连接」按钮，APP 会向服务器发起一次测试请求，成功返回绿色提示，失败会显示具体错误原因（地址错 / Key 错 / 网络不通等）。', type: '' },
                        { text: '<b>第 3 步：保存并自动连接</b><br/>点击「确认并连接」后自动跳回首页，APP 会立即和 WebSocket 服务器建立长连接。连接成功后顶部状态卡变成绿色「在线」。', type: 'tip' }
                    ]
                },
                {
                    icon: '🏠',
                    title: '首页功能说明',
                    body: [
                        { text: '<b>连接状态卡</b><br/>实时显示 WebSocket 连接状态、服务器地址和延迟时间。绿色 = 在线，黄色 = 连接中，红色 = 断线。', type: '' },
                        { text: '<b>🧪 测试推送</b><br/>向服务器发送一条测试推送指令，验证 WebSocket 连接和 Push Key 是否正常工作。服务器会回推一条通知。', type: '' },
                        { text: '<b>🔄 重新连接</b><br/>手动断开并重连 WebSocket。在断线后或切换网络时很有用。', type: '' },
                        { text: '<b>最近消息</b><br/>展示最新 3 条推送消息，点击可进入消息列表查看全部。' }
                    ]
                },
                {
                    icon: '📋',
                    title: '消息列表功能',
                    body: [
                        { text: '<b>筛选</b><br/>顶部 chips 可按「全部 / 高优先 / 系统 / 未读」快速过滤消息。未读数量会显示徽标。', type: '' },
                        { text: '<b>搜索</b><br/>搜索框支持按标题和正文内容模糊匹配。', type: '' },
                        { text: '<b>一键已读</b><br/>把所有未读消息标记为已读。只有存在未读时按钮才会显示。', type: '' },
                        { text: '<b>复制 / 删除</b><br/>每条消息底部有复制到剪贴板、标记已读（或已读后删除）操作。删除需要二次确认。' }
                    ]
                },
                {
                    icon: '🎨',
                    title: '主题切换',
                    body: [
                        { text: '设置页提供 <b>三种主题</b>：', type: '' },
                        { text: '• <b>浅色玻璃</b>（默认）— 清新优雅的浅色毛玻璃效果', type: '' },
                        { text: '• <b>深色玻璃</b> — 赛博霓虹感的深色毛玻璃效果', type: '' },
                        { text: '• <b>扁平渐变</b> — 放弃模糊，用实色渐变卡片表达层次，主色为暖橙', type: '' },
                        { text: '切换立即生效，会自动保存到本地，下次打开 APP 会恢复上次选择。', type: 'tip' }
                    ]
                },
                {
                    icon: '📶',
                    title: '网络设置',
                    body: [
                        { text: '<b>📶 仅 Wi-Fi 连接</b><br/>开启后，只有在 Wi-Fi 环境下才会建立 WebSocket 连接。在移动数据网络会提示切换 Wi-Fi。自动重连同样遵循此规则。', type: '' },
                        { text: '<b>🔄 自动重连</b><br/>断线后是否自动尝试重连。默认开启，建议保持开启以获得最佳体验。', type: '' },
                        { text: '<b>⚡ 心跳间隔</b><br/>WebSocket 连接的心跳保活周期，可选 15s / 30s / 60s。间隔越短越耗电但连接更稳定；60s 适合对延迟不敏感的场景。', type: '' }
                    ]
                },
                {
                    icon: '🔔',
                    title: '通知设置',
                    body: [
                        { text: '<b>📳 震动反馈</b><br/>收到推送时同时触发系统震动。关闭后仅有声音/横幅通知。', type: '' },
                        { text: '<b>🎵 通知铃声</b><br/>「默认」使用系统通知铃声；「静默」关闭声音和震动，仅有横幅通知弹出。', type: '' },
                        { text: '<b>Android 权限说明</b><br/>首次使用前请在系统设置中确保：通知权限已开启、电池优化白名单已加入、自启动已允许。否则可能收不到推送。', type: 'tip' }
                    ]
                }
            ]
        }
    },
    onShow: function() {
        applySafeArea()
        var self = this
        self.themeClass = getTheme()
        self._themeListener = function(t) { self.themeClass = t }
        onThemeChange(self._themeListener)
    },
    onUnload: function() {
        if (this._themeListener) { offThemeChange(this._themeListener); this._themeListener = null }
    },
    methods: {
        goBack: function() { uni.navigateBack({ delta: 1 }) },
        toggle: function(idx) {
            this.$set(this.expanded, idx, !this.expanded[idx])
        }
    }
}
</script>

<style>
.help-tip {
    background: rgba(99, 102, 241, 0.1);
    border-left: 3px solid #6366f1;
    padding: 16rpx 20rpx;
    border-radius: 0 12rpx 12rpx 0;
    font-size: 24rpx;
    color: var(--text-secondary);
}
</style>
