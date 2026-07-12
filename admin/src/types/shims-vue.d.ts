declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<{}, {}, any>
  export default component
}

declare module 'nprogress'

declare module 'vue-router' {
  interface RouteMeta {
    title?: string
    icon?: string
    roles?: string[]
    affix?: boolean
    hidden?: boolean
    cache?: boolean
    activeMenu?: string
    breadcrumb?: boolean
    module?: string
  }
}
