<template>
  <div class="error-page">
    <div class="error-box">
      <div class="code">404</div>
      <div class="title">页面不存在</div>
      <div class="desc">你访问的页面可能已删除或链接错误</div>
      <div class="btns">
        <el-button type="primary" @click="router.replace('/dashboard')">返回首页</el-button>
        <el-button @click="router.back()">返回上一页</el-button>
      </div>
    </div>
  </div>
</template>
<script setup lang="ts">
import { useRouter } from 'vue-router'
const router = useRouter()
</script>
<style lang="scss" scoped>
.error-page {
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: $space-5;
  background:
    radial-gradient(circle at 30% 20%, rgba(14,165,233,0.16), transparent 45%),
    radial-gradient(circle at 70% 80%, rgba(34,197,94,0.12), transparent 50%),
    #f6f8fc;
}
.error-box { text-align: center;
  .code {
    font-size: 120px; font-weight: 800; line-height: 1;
    @include text-gradient;
    letter-spacing: -2px;
  }
  .title { margin-top: $space-3; font-size: $font-size-2xl; color: var(--text-primary); font-weight: 600; }
  .desc  { margin-top: $space-2; color: var(--text-secondary); }
  .btns  { margin-top: $space-8; display: flex; gap: $space-3; justify-content: center; }
}
</style>
