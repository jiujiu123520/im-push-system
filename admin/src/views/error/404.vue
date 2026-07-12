<template>
  <div class="not-found">
    <div class="nf-bg">
      <div class="nf-blob blob-a"></div>
      <div class="nf-blob blob-b"></div>
    </div>

    <div class="nf-content">
      <div class="nf-code">
        <span class="digit">4</span>
        <span class="digit orb">
          <svg viewBox="0 0 80 80" class="orb-svg">
            <defs>
              <linearGradient id="nfGrad" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#9b5cff" />
                <stop offset="100%" stop-color="#5cb8ff" />
              </linearGradient>
            </defs>
            <circle cx="40" cy="40" r="32" fill="url(#nfGrad)" />
            <path
              d="M28 40l8 8 16-16"
              fill="none"
              stroke="#fff"
              stroke-width="4"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </span>
        <span class="digit">4</span>
      </div>
      <h1 class="nf-title">页面走丢了</h1>
      <p class="nf-desc">抱歉，您访问的页面不存在或已被移除</p>
      <div class="nf-actions">
        <el-button type="primary" round size="large" @click="goHome">
          <el-icon><HomeFilled /></el-icon>返回首页
        </el-button>
        <el-button round size="large" @click="goBack">
          <el-icon><Back /></el-icon>返回上一页
        </el-button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router'
import { HomeFilled, Back } from '@element-plus/icons-vue'

const router = useRouter()

function goHome() {
  router.push('/dashboard')
}

function goBack() {
  router.go(-1)
}
</script>

<style lang="scss" scoped>
.not-found {
  position: relative;
  width: 100%;
  height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg-page);
  overflow: hidden;
}

.nf-bg {
  position: absolute;
  inset: 0;
  z-index: 0;

  .nf-blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(90px);
    opacity: 0.4;
    animation: float 8s ease-in-out infinite;
  }
  .blob-a {
    width: 420px;
    height: 420px;
    background: radial-gradient(circle, #9b5cff, transparent 70%);
    top: -100px;
    left: 10%;
  }
  .blob-b {
    width: 460px;
    height: 460px;
    background: radial-gradient(circle, #5cb8ff, transparent 70%);
    bottom: -120px;
    right: 8%;
    animation-delay: -4s;
  }
}

.nf-content {
  position: relative;
  z-index: 1;
  text-align: center;
  animation: fade-up 0.6s ease;
}

.nf-code {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 24px;

  .digit {
    font-size: 120px;
    font-weight: 900;
    line-height: 1;
    background: $gradient-primary;
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    font-family: $font-family-mono;
  }

  .orb {
    display: flex;
    animation: float 3s ease-in-out infinite;
  }
  .orb-svg {
    width: 100px;
    height: 100px;
    filter: drop-shadow(0 12px 32px rgba(109, 92, 255, 0.4));
  }
}

.nf-title {
  font-size: 32px;
  font-weight: 800;
  color: var(--text-primary);
  margin-bottom: 12px;
}

.nf-desc {
  font-size: 15px;
  color: var(--text-secondary);
  margin-bottom: 32px;
}

.nf-actions {
  display: flex;
  gap: 14px;
  justify-content: center;
}

@keyframes float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-18px); }
}

@keyframes fade-up {
  from {
    opacity: 0;
    transform: translateY(24px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (max-width: 480px) {
  .nf-code .digit {
    font-size: 80px;
  }
  .orb-svg {
    width: 70px;
    height: 70px;
  }
  .nf-title {
    font-size: 24px;
  }
}
</style>
