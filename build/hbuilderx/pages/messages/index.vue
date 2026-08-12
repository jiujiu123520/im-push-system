<template>
    <view class="glass-bg">
        <view class="top-bar">
            <view class="row-between" style="margin-top:60rpx;">
                <view class="top-bar-title">消息列表</view>
                <view class="text-muted" style="font-size:26rpx;">{{ messages.length }} 条</view>
            </view>
        </view>

        <view class="glass-card" style="margin-top:0;margin:20rpx 24rpx;">
            <view class="glass-input" style="padding:16rpx 24rpx;font-size:26rpx;">
                🔍 <input placeholder="搜索消息内容" placeholder-style="color:rgba(255,255,255,0.4)" v-model="keyword" style="background:transparent;border:none;color:white;font-size:26rpx;flex:1;outline:none;" />
            </view>
            <view class="row" style="gap:12rpx;margin-top:20rpx;">
                <text :class="['status-chip', curFilter === 'all' ? 'status-ok' : '']" @click="curFilter='all'">全部 {{ messages.length }}</text>
                <text :class="['status-chip', curFilter === 'high' ? 'status-bad' : '']" @click="curFilter='high'">高优先</text>
                <text :class="['status-chip', curFilter === 'system' ? 'status-warn' : '']" @click="curFilter='system'">系统</text>
                <text :class="['status-chip', curFilter === 'unread' ? 'status-ok' : '']" @click="curFilter='unread'">未读 {{ unreadCount }}</text>
            </view>
        </view>

        <view class="glass-card" v-if="filteredMessages.length === 0" style="text-align:center;padding:80rpx 30rpx;">
            <view style="font-size:80rpx;">📭</view>
            <view class="text-muted" style="font-size:26rpx;margin-top:16rpx;">暂无消息</view>
        </view>

        <view v-for="(m, i) in filteredMessages" :key="m.id" class="glass-card" style="padding:24rpx 30rpx;margin:12rpx 24rpx;">
            <view class="row-between">
                <view style="font-size:28rpx;font-weight:600;color:rgba(255,255,255,0.95);">{{ m.title || '推送消息' }}</view>
                <view :class="['status-chip', m.priority === 'high' ? 'status-bad' : (m.priority === 'system' ? 'status-warn' : 'status-ok')]" v-if="m.priority">
                    {{ priorityLabel(m.priority) }}
                </view>
            </view>
            <view class="text-secondary" style="font-size:26rpx;margin-top:10rpx;line-height:1.5;">{{ m.content }}</view>
            <view class="row-between mt-16">
                <view class="text-muted" style="font-size:22rpx;">{{ formatTime(m.timestamp) }}</view>
                <text class="text-accent" style="font-size:24rpx;" @click="markRead(m.id)">已读</text>
            </view>
        </view>
    </view>
</template>

<script>
import { getMessages, setMessages } from '../../js/storage.js'
import { on, off } from '../../js/ws.js'

export default {
    data() {
        return { messages: [], keyword: '', curFilter: 'all', unreadCount: 0 }
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
        this._refresh()
        on('message', this._onWsMsg)
    },
    onHide: function() { off('message', this._onWsMsg) },
    methods: {
        _refresh: function() {
            this.messages = getMessages()
            this.unreadCount = this.messages.filter(function(m){ return !m.read }).length
        },
        _onWsMsg: function() { this._refresh() },
        markRead: function(id) {
            var list = this.messages
            for (var i = 0; i < list.length; i++) {
                if (list[i].id === id) list[i].read = true
            }
            setMessages(list)
            this._refresh()
        },
        formatTime: function(ts) {
            if (!ts) return ''
            var d = new Date(ts)
            var pad = function(n){ return n < 10 ? '0' + n : '' + n }
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
