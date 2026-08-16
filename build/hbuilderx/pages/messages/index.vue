<template>
    <view :class="['glass-bg', themeClass]">
        <view class="top-bar">
            <view class="row-between" >
                <view class="top-bar-title">消息列表</view>
                <view class="text-muted" style="font-size:26rpx;">{{ messages.length }} 条</view>
            </view>
        </view>

        <view class="glass-card" style="margin-top:0;margin:20rpx 24rpx;">
            <view class="glass-input" style="padding:16rpx 24rpx;font-size:26rpx;">
                🔍 <input placeholder="搜索消息内容" v-model="keyword" style="background:transparent;border:none;color:var(--input-text);font-size:26rpx;flex:1;outline:none;" />
            </view>
            <view class="row" style="gap:12rpx;margin-top:20rpx;">
                <text :class="['status-chip', curFilter === 'all' ? 'status-ok' : '']" @click="curFilter='all'">全部 {{ messages.length }}</text>
                <text :class="['status-chip', curFilter === 'high' ? 'status-bad' : '']" @click="curFilter='high'">高优先</text>
                <text :class="['status-chip', curFilter === 'system' ? 'status-warn' : '']" @click="curFilter='system'">系统</text>
                <text :class="['status-chip', curFilter === 'unread' ? 'status-ok' : '']" @click="curFilter='unread'">未读 {{ unreadCount }}</text>
            </view>
            <view class="row-between" style="margin-top:16rpx;gap:16rpx;">
                <view class="row" style="gap:12rpx;">
                    <text class="chip-btn" @click="markAllRead" v-if="unreadCount > 0">✅ 一键已读</text>
                    <text class="chip-btn chip-btn-danger" @click="confirmClear" v-if="messages.length > 0">🗑 清空</text>
                </view>
            </view>
        </view>

        <view class="glass-card" v-if="filteredMessages.length === 0" style="text-align:center;padding:80rpx 30rpx;">
            <view style="font-size:80rpx;">📭</view>
            <view class="text-muted" style="font-size:26rpx;margin-top:16rpx;">暂无消息</view>
        </view>

        <view v-for="(m, i) in filteredMessages" :key="m.id" :class="['glass-card', m.read ? 'msg-read' : 'msg-unread']" style="padding:24rpx 30rpx;margin:12rpx 24rpx;">
            <view class="row-between">
                <view class="row" style="gap:10rpx;align-items:center;">
                    <view style="font-size:28rpx;font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" :style="{ color: m.read ? 'var(--text-read)' : 'var(--text-primary)' }">{{ m.title || '推送消息' }}</view>
                    <view v-if="!m.read" class="dot-unread"></view>
                </view>
                <view :class="['status-chip', m.priority === 'high' ? 'status-bad' : (m.priority === 'system' ? 'status-warn' : 'status-ok')]" v-if="m.priority">
                    {{ priorityLabel(m.priority) }}
                </view>
            </view>
            <view class="text-secondary" style="font-size:26rpx;margin-top:10rpx;line-height:1.5;" :style="{ color: m.read ? 'var(--text-read)' : 'var(--text-secondary)' }">{{ m.content }}</view>
            <view class="row-between mt-16">
                <view class="text-muted" style="font-size:22rpx;">{{ formatTime(m.timestamp) }}</view>
                <view class="row" style="gap:20rpx;">
                    <text class="link-btn" @click="copyMsg(m)">📋 复制</text>
                    <text v-if="!m.read" class="link-btn" @click="markRead(m.id)">已读</text>
                    <text class="link-btn link-btn-danger" @click="deleteMsg(m.id)">🗑 删除</text>
                </view>
            </view>
        </view>
    </view>
</template>

<script>
import { getMessages, markRead as markReadLocal, markAllRead as markAllReadLocal, deleteMessage as deleteMessageLocal, clearMessages as clearMessagesLocal } from '../../js/storage.js'
import { on, off } from '../../js/ws.js'
import { getTheme, applyTheme, onThemeChange, offThemeChange } from '../../js/theme.js'
import { applySafeArea } from '../../js/safe-area.js'

export default {
    data() {
        return {
            themeClass: 'theme-dark',
            messages: [],
            keyword: '',
            curFilter: 'all',
            unreadCount: 0
        }
    },
    computed: {
        filteredMessages: function() {
            var arr = this.messages
            if (this.curFilter === 'high') arr = arr.filter(function(m){ return m.priority === 'high' })
            if (this.curFilter === 'system') arr = arr.filter(function(m){ return m.priority === 'system' })
            if (this.curFilter === 'unread') arr = arr.filter(function(m){ return !m.read })
            if (this.keyword) {
                var kw = this.keyword.toLowerCase()
                arr = arr.filter(function(m){ return (m.title + ' ' + m.content).toLowerCase().indexOf(kw) >= 0 })
            }
            return arr
        }
    },
    onShow: function() {
        applySafeArea()
        var self = this
        self.themeClass = 'theme-' + getTheme()
        self._themeListener = function(t) { self.themeClass = 'theme-' + t }
        onThemeChange(self._themeListener)
        applyTheme()
        self._refresh()
        on('message', self._onWsMsg)
    },
    onHide: function() {
        off('message', this._onWsMsg)
    },
    onUnload: function() {
        off('message', this._onWsMsg)
        if (this._themeListener) { offThemeChange(this._themeListener); this._themeListener = null }
    },
    methods: {
        _refresh: function() {
            this.messages = getMessages()
            this.unreadCount = this.messages.filter(function(m){ return !m.read }).length
        },
        _onWsMsg: function() { this._refresh() },
        markRead: function(id) {
            markReadLocal(id)
            this._refresh()
        },
        markAllRead: function() {
            markAllReadLocal()
            this._refresh()
            uni.showToast({ title: '已全部标记为已读', icon: 'none' })
        },
        deleteMsg: function(id) {
            var self = this
            uni.showModal({
                title: '确认删除',
                content: '确定删除这条消息吗？',
                success: function(res) {
                    if (res.confirm) {
                        deleteMessageLocal(id)
                        self._refresh()
                    }
                }
            })
        },
        confirmClear: function() {
            var self = this
            uni.showModal({
                title: '确认清空',
                content: '确定清空全部消息吗？此操作不可恢复',
                confirmColor: '#ff4d4f',
                success: function(res) {
                    if (res.confirm) {
                        clearMessagesLocal()
                        self._refresh()
                        uni.showToast({ title: '已清空', icon: 'none' })
                    }
                }
            })
        },
        copyMsg: function(m) {
            var text = (m.title ? m.title + '\n' : '') + (m.content || '')
            uni.setClipboardData({
                data: text,
                success: function() {
                    uni.showToast({ title: '已复制到剪贴板', icon: 'success' })
                },
                fail: function() {
                    uni.showToast({ title: '复制失败', icon: 'none' })
                }
            })
        },
        formatTime: function(ts) {
            if (!ts) return ''
            var n = Number(ts)
            if (!n || n <= 0) return ''
            if (n < 1e12) n = n * 1000
            var d = new Date(n)
            if (isNaN(d.getTime())) return ''
            var pad = function(n2){ return n2 < 10 ? '0' + n2 : '' + n2 }
            return pad(d.getMonth()+1) + '/' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
        },
        priorityLabel: function(p) {
            if (p === 'high') return '🔴 高优先'
            if (p === 'system') return '⚙ 系统'
            return '普通'
        }
    }
}
</script>

<style>
.msg-unread { border-left: 6rpx solid rgba(80,180,255,0.9); }
.msg-read { opacity: 0.85; }
.dot-unread { width: 14rpx; height: 14rpx; border-radius: 50%; background: #ff4d4f; display: inline-block; }
.chip-btn { padding: 10rpx 20rpx; border-radius: 30rpx; font-size: 24rpx; background: rgba(80,180,255,0.25); color: #54b4ff; }
.chip-btn:active { opacity: 0.7; }
.chip-btn-danger { background: rgba(255,77,79,0.2); color: #ff7875; }
</style>
