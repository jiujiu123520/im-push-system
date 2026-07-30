<template>
  <div class="page-container module-page">
    <!-- 页头 -->
    <div class="page-header">
      <div class="page-title">{{ moduleTitle }}</div>
      <div class="header-actions">
        <!-- 推送记录模块的导出按钮 -->
        <el-dropdown
          v-if="currentModule === 'push-logs'"
          @command="handleExport"
          style="margin-right: 12px"
        >
          <el-button :icon="DownloadIcon" :loading="exporting">
            导出
            <el-icon class="el-icon--right"><ArrowDownIcon /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="csv">导出 CSV</el-dropdown-item>
              <el-dropdown-item command="json">导出 JSON</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
        <el-button
          v-if="currentModule === 'users' || currentModule === 'admins' || currentModule === 'keys'"
          type="danger"
          plain
          :icon="DeleteIcon"
          @click="handleClearAll"
        >
          一键清空
        </el-button>
        <el-button
          v-if="currentModule !== 'devices'"
          type="primary"
          :icon="PlusIcon"
          @click="openDialog()"
        >
          新增{{ moduleTitle }}
        </el-button>
      </div>
    </div>

    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-input
        v-model="query.keyword"
        placeholder="搜索关键词"
        :prefix-icon="SearchIcon"
        clearable
        style="width: 220px"
        @keyup.enter="handleSearch"
      />
      <!-- 设备模块：平台筛选 -->
      <el-select
        v-if="currentModule === 'devices'"
        v-model="query.platform"
        placeholder="平台"
        clearable
        style="width: 140px"
      >
        <el-option label="Android" value="android" />
        <el-option label="iOS" value="ios" />
        <el-option label="Web" value="web" />
        <el-option label="HarmonyOS" value="harmony" />
      </el-select>
      <!-- 设备模块：在线状态筛选 -->
      <el-select
        v-if="currentModule === 'devices'"
        v-model="query.online"
        placeholder="在线状态"
        clearable
        style="width: 140px"
      >
        <el-option label="在线" :value="1" />
        <el-option label="离线" :value="2" />
      </el-select>
      <el-select v-model="query.status" placeholder="状态筛选" clearable style="width: 160px">
        <template v-if="currentModule === 'push-logs'">
          <el-option label="失败" :value="0" />
          <el-option label="成功" :value="1" />
          <el-option label="部分成功" :value="2" />
          <el-option label="进行中" :value="3" />
          <el-option label="已存离线" :value="4" />
        </template>
        <template v-else>
          <el-option label="启用" :value="1" />
          <el-option label="禁用" :value="0" />
        </template>
      </el-select>
      <!-- 推送记录：目标类型筛选 -->
      <el-select
        v-if="currentModule === 'push-logs'"
        v-model="query.targetType"
        placeholder="目标类型"
        clearable
        style="width: 140px"
      >
        <el-option label="设备" value="device" />
        <el-option label="按Key" value="key" />
        <el-option label="广播" value="broadcast" />
        <el-option label="用户" value="user" />
      </el-select>
      <el-button type="primary" :icon="SearchIcon" @click="handleSearch">查询</el-button>
      <el-button :icon="RefreshLeftIcon" @click="handleReset">重置</el-button>
    </div>

    <!-- 表格 -->
    <div class="table-container">
      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        style="width: 100%"
        row-key="id"
      >
        <el-table-column type="index" label="#" width="60" align="center" />
        <el-table-column
          v-for="col in columns"
          :key="col.prop"
          :prop="col.prop"
          :label="col.label"
          :min-width="col.width || 140"
          show-overflow-tooltip
        >
          <template v-if="col.slot === 'status'" #default="{ row }">
            <!-- 推送记录：status 0=失败 1=成功 2=部分成功 3=进行中 4=已存离线 -->
            <template v-if="currentModule === 'push-logs'">
              <el-tag
                v-if="row.status === 1"
                type="success"
                effect="light"
                round
                size="small"
              >
                成功
              </el-tag>
              <el-tag
                v-else-if="row.status === 0"
                type="danger"
                effect="dark"
                round
                size="small"
              >
                失败
              </el-tag>
              <el-tag
                v-else-if="row.status === 2"
                type="warning"
                effect="dark"
                round
                size="small"
              >
                部分成功
              </el-tag>
              <el-tag
                v-else-if="row.status === 3"
                type="primary"
                effect="dark"
                round
                size="small"
              >
                进行中
              </el-tag>
              <el-tag
                v-else-if="row.status === 4"
                type="info"
                effect="dark"
                round
                size="small"
              >
                已存离线
              </el-tag>
              <el-tag v-else type="info" effect="plain" round size="small">
                未知
              </el-tag>
            </template>
            <!-- 其他模块：1=启用 0=禁用 -->
            <template v-else>
              <el-tag :type="row[col.prop] === 1 ? 'success' : 'info'" effect="light" round size="small">
                {{ row[col.prop] === 1 ? '启用' : '禁用' }}
              </el-tag>
            </template>
          </template>
          <template v-else-if="col.slot === 'tag'" #default="{ row }">
            <el-tag
              v-for="t in (row[col.prop] || [])"
              :key="t"
              effect="plain"
              round
              size="small"
              style="margin-right: 4px"
            >{{ t }}</el-tag>
          </template>
          <template v-else-if="col.slot === 'online'" #default="{ row }">
            <el-tag
              :type="row[col.prop] === 1 ? 'success' : 'info'"
              effect="dark"
              round
              size="small"
            >
              {{ row[col.prop] === 1 ? '在线' : '离线' }}
            </el-tag>
          </template>
          <template v-else-if="col.slot === 'platform'" #default="{ row }">
            <el-tag
              v-if="row[col.prop]"
              :type="platformTagType(row[col.prop])"
              effect="plain"
              round
              size="small"
            >
              {{ platformLabel(row[col.prop]) }}
            </el-tag>
            <span v-else style="color: #909399; font-size: 12px;">未知</span>
          </template>
          <!-- 设备模块：通用文本列（空值显示"未知"） -->
          <template v-else-if="col.slot === 'deviceText'" #default="{ row }">
            <span v-if="row[col.prop]" :title="String(row[col.prop])">{{ row[col.prop] }}</span>
            <span v-else style="color: #909399; font-size: 12px;">未知</span>
          </template>
          <!-- 推送记录：目标类型 -->
          <template v-else-if="col.slot === 'targetType'" #default="{ row }">
            <el-tag
              :type="targetTypeTagType(row.target_type)"
              effect="plain"
              round
              size="small"
            >
              {{ targetTypeLabel(row.target_type) }}
            </el-tag>
          </template>
          <!-- 推送记录：目标值 -->
          <template v-else-if="col.slot === 'targetValue'" #default="{ row }">
            <div style="display: flex; align-items: center; gap: 4px;">
              <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ row[col.prop] || '-' }}</span>
              <el-button v-if="row[col.prop]" text type="primary" size="small" @click="copyToClipboard(row[col.prop])">
                <el-icon><CopyDocumentIcon /></el-icon>
              </el-button>
            </div>
          </template>
          <!-- 推送记录：成功/失败计数 -->
          <template v-else-if="col.slot === 'count'" #default="{ row }">
            <span
              :class="{
                'text-green-600 font-semibold': col.prop === 'success_count' && row[col.prop] > 0,
                'text-red-600 font-semibold': col.prop === 'fail_count' && row[col.prop] > 0
              }"
            >
              {{ Number(row[col.prop]) || 0 }}
            </span>
          </template>
          <!-- 推送记录：失败原因 -->
          <template v-else-if="col.slot === 'failReason'" #default="{ row }">
            <el-tooltip
              v-if="row.fail_reason"
              :content="row.fail_reason"
              placement="top"
              :show-after="300"
            >
              <span style="color: #f56c6c; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; max-width: 100%;">
                {{ row.fail_reason }}
              </span>
            </el-tooltip>
            <span v-else style="color: #909399;">-</span>
          </template>
          <!-- 推送记录：耗时 -->
          <template v-else-if="col.slot === 'elapsedMs'" #default="{ row }">
            <span v-if="row.elapsed_ms != null && row.elapsed_ms > 0" style="font-family: monospace; font-size: 12px;">
              {{ row.elapsed_ms < 1000 ? row.elapsed_ms + ' ms' : (row.elapsed_ms / 1000).toFixed(2) + ' s' }}
            </span>
            <span v-else style="color: #909399;">-</span>
          </template>
          <!-- 用户：邮箱 -->
          <template v-else-if="col.slot === 'email'" #default="{ row }">
            <div v-if="row.email" style="display: flex; align-items: center; gap: 4px;">
              <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ row.email }}</span>
              <el-button text type="primary" size="small" @click="copyToClipboard(row.email)">
                <el-icon><CopyDocumentIcon /></el-icon>
              </el-button>
            </div>
            <span v-else style="color: #909399;">-</span>
          </template>
          <!-- 用户：手机号 -->
          <template v-else-if="col.slot === 'phone'" #default="{ row }">
            <div v-if="row.phone" style="display: flex; align-items: center; gap: 4px;">
              <span style="flex: 1;">{{ formatPhone(row.phone) }}</span>
              <el-button text type="primary" size="small" @click="copyToClipboard(row.phone)">
                <el-icon><CopyDocumentIcon /></el-icon>
              </el-button>
            </div>
            <span v-else style="color: #909399;">-</span>
          </template>
          <!-- Key：掉线通知 -->
          <template v-else-if="col.slot === 'notifyEnabled'" #default="{ row }">
            <el-tag
              v-if="row.notify_enabled === 1"
              type="warning"
              effect="dark"
              round
              size="small"
            >
              已开启
            </el-tag>
            <el-tag v-else type="info" effect="plain" round size="small">
              未开启
            </el-tag>
          </template>
          <!-- Key：通知邮箱 -->
          <template v-else-if="col.slot === 'notifyEmail'" #default="{ row }">
            <div v-if="row.notify_email" style="display: flex; align-items: center; gap: 4px;">
              <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ row.notify_email }}</span>
              <el-button text type="primary" size="small" @click="copyToClipboard(row.notify_email)">
                <el-icon><CopyDocumentIcon /></el-icon>
              </el-button>
            </div>
            <span v-else style="color: #909399;">未配置</span>
          </template>
          <!-- Key：通知间隔 -->
          <template v-else-if="col.slot === 'notifyInterval'" #default="{ row }">
            <span v-if="row.notify_interval">
              <span style="font-weight: 500;">{{ row.notify_interval }}</span> 秒
              <span style="color: #909399; margin-left: 6px;">（{{ formatDuration(row.notify_interval) }}）</span>
            </span>
            <span v-else style="color: #909399;">默认 5 分钟</span>
          </template>
          <template v-else-if="col.prop === 'key_value'" #default="{ row }">
            <div style="display: flex; align-items: center; gap: 4px;">
              <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ row[col.prop] }}</span>
              <el-button text type="primary" size="small" @click="copyToClipboard(row[col.prop])">
                <el-icon><CopyDocumentIcon /></el-icon>
              </el-button>
            </div>
          </template>
          <!-- API Key 模块：AccessKey 列（带复制按钮） -->
          <template v-else-if="currentModule === 'api-keys' && col.prop === 'accessKey'" #default="{ row }">
            <div style="display: flex; align-items: center; gap: 4px;">
              <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: monospace; font-size: 13px;">{{ row.accessKey || row.key_value || '-' }}</span>
              <el-button
                v-if="row.accessKey || row.key_value"
                text
                type="primary"
                size="small"
                @click="copyToClipboard(String(row.accessKey || row.key_value))"
              >
                <el-icon><CopyDocumentIcon /></el-icon>
              </el-button>
            </div>
          </template>
          <!-- API Key 模块：过期时间列 -->
          <template v-else-if="currentModule === 'api-keys' && col.prop === 'expireAt'" #default="{ row }">
            <span v-if="row.expireAt && row.expireAt !== '永不过期'" :style="isExpired(row.expireAt) ? 'color: #f56c6c; font-weight: 500;' : ''">
              {{ row.expireAt }}
              <el-tag v-if="isExpireSoon(row.expireAt)" type="warning" size="small" effect="dark" round style="margin-left: 4px;">即将过期</el-tag>
              <el-tag v-if="isExpired(row.expireAt)" type="danger" size="small" effect="dark" round style="margin-left: 4px;">已过期</el-tag>
            </span>
            <span v-else style="color: #67c23a;">永不过期</span>
          </template>
          <template v-else-if="currentModule === 'devices' && col.prop === 'device_id'" #default="{ row }">
            <div style="display: flex; align-items: center; gap: 4px;">
              <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ row[col.prop] }}</span>
              <el-button text type="primary" size="small" @click="copyToClipboard(row[col.prop])">
                <el-icon><CopyDocumentIcon /></el-icon>
              </el-button>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="操作" :width="(
          currentModule === 'push-logs' ? 230 :
          currentModule === 'users' ? 280 :
          currentModule === 'devices' ? 280 :
          currentModule === 'keys' ? 200 :
          180
        )" fixed="right">
          <template #default="{ row }">
            <!-- 推送记录：详情 + 重新推送 + 删除 -->
            <template v-if="currentModule === 'push-logs'">
              <el-button text type="primary" :icon="ViewIcon" @click="viewPushLogDetail(row)">
                详情
              </el-button>
              <el-button
                v-if="row.status !== 1 && row.status !== 3"
                text type="success"
                :icon="RefreshIcon"
                @click="retryPushLog(row)"
              >
                重新推送
              </el-button>
              <el-button text type="danger" :icon="DeleteIcon" @click="handleDelete(row)">删除</el-button>
            </template>
            <!-- 用户：编辑 + 修改密码 + 删除 -->
            <template v-else-if="currentModule === 'users'">
              <el-button text type="primary" :icon="EditIcon" @click="openDialog(row)">编辑</el-button>
              <el-button text type="warning" :icon="KeyIcon" @click="openPasswordDialog(row)">修改密码</el-button>
              <el-button text type="danger" :icon="DeleteIcon" @click="handleDelete(row)">删除</el-button>
            </template>
            <!-- 其他模块：编辑/禁用 + 删除 -->
            <template v-else>
              <el-button v-if="currentModule !== 'devices'" text type="primary" :icon="EditIcon" @click="openDialog(row)">编辑</el-button>
              <el-button
                v-if="currentModule === 'devices' && row.online === 1"
                text type="warning"
                :icon="SwitchButtonIcon"
                :loading="kickingDeviceId === row.id"
                @click="handleKickDevice(row)"
              >
                踢出
              </el-button>
              <el-button
                v-if="currentModule === 'devices'"
                text
                :type="(row._rawStatus ?? row.status[0] ?? 1) === 2 ? 'success' : 'warning'"
                :icon="(row._rawStatus ?? row.status[0] ?? 1) === 2 ? UnlockIcon : LockIcon"
                @click="handleToggleDeviceStatus(row)"
              >
                {{ (row._rawStatus ?? row.status[0] ?? 1) === 2 ? '启用' : '禁用' }}
              </el-button>
              <el-button text type="danger" :icon="DeleteIcon" @click="handleDelete(row)">删除</el-button>
            </template>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.pageSize"
          :page-sizes="[10, 20, 50]"
          :total="total"
          layout="total, sizes, prev, pager, next, jumper"
          background
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </div>

    <!-- 新增/编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="560px"
      destroy-on-close
    >
      <el-form
        ref="dialogFormRef"
        :model="dialogForm"
        :rules="dialogRules"
        label-width="100px"
      >
        <el-form-item
          v-for="field in formFields"
          :key="field.prop"
          :label="field.label"
          :prop="field.prop"
        >
          <el-input
            v-if="field.type === 'input'"
            v-model="dialogForm[field.prop]"
            :placeholder="field.placeholder || `请输入${field.label}`"
          />
          <el-input
            v-else-if="field.type === 'textarea'"
            v-model="dialogForm[field.prop]"
            type="textarea"
            :rows="3"
            :placeholder="field.placeholder || `请输入${field.label}`"
          />
          <el-input-number
            v-else-if="field.type === 'number'"
            v-model="dialogForm[field.prop]"
            :min="0"
            controls-position="right"
            style="width: 100%"
          />
          <el-select
            v-else-if="field.type === 'select'"
            v-model="dialogForm[field.prop]"
            :placeholder="field.placeholder || `请选择${field.label}`"
            style="width: 100%"
          >
            <el-option
              v-for="opt in field.options"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
          <el-switch
            v-else-if="field.type === 'switch'"
            v-model="dialogForm[field.prop]"
            :active-value="1"
            :inactive-value="0"
          />
          <div v-if="field.tip" class="field-tip">{{ field.tip }}</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- 修改密码弹窗 -->
    <el-dialog
      v-model="passwordDialogVisible"
      title="修改密码"
      width="440px"
      destroy-on-close
    >
      <el-form
        ref="passwordFormRef"
        :model="passwordForm"
        :rules="passwordRules"
        label-width="100px"
      >
        <el-form-item label="新密码" prop="password">
          <el-input
            v-model="passwordForm.password"
            type="password"
            show-password
            placeholder="请输入新密码"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="passwordDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="resettingPassword" @click="handleResetPassword">确定</el-button>
      </template>
    </el-dialog>

    <!-- 推送记录详情弹窗 -->
    <el-dialog
      v-model="pushDetailVisible"
      title="推送记录详情"
      width="680px"
      destroy-on-close
    >
      <div v-loading="pushDetailLoading">
        <template v-if="pushDetailData">
          <!-- 基础信息 -->
          <div class="detail-section">
            <div class="detail-title">基础信息</div>
            <el-descriptions :column="2" border size="small">
              <el-descriptions-item label="ID">
                {{ pushDetailData.id || '-' }}
              </el-descriptions-item>
              <el-descriptions-item label="状态">
                <el-tag :type="pushStatusType(Number(pushDetailData.status))" effect="light" round size="small">
                  {{ pushStatusLabel(Number(pushDetailData.status)) }}
                </el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="目标类型">
                <el-tag :type="targetTypeTagType(pushDetailData.target_type)" effect="plain" round size="small">
                  {{ targetTypeLabel(pushDetailData.target_type) }}
                </el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="目标值" :span="2">
                <span v-if="pushDetailData.target_value"
                  >{{ pushDetailData.target_value }}</span>
                <span v-else style="color: #909399;">-</span>
              </el-descriptions-item>
              <el-descriptions-item label="创建时间">
                {{ pushDetailData.created_at || '-' }}
              </el-descriptions-item>
              <el-descriptions-item label="耗时">
                {{ pushDetailData.elapsed_ms != null ? `${pushDetailData.elapsed_ms} ms` : '-' }}
              </el-descriptions-item>
            </el-descriptions>
          </div>

          <!-- 推送内容 -->
          <div class="detail-section">
            <div class="detail-title">推送内容</div>
            <el-descriptions :column="1" border size="small">
              <el-descriptions-item label="标题">
                {{ pushDetailData.title || '-' }}
              </el-descriptions-item>
              <el-descriptions-item label="内容">
                <div style="white-space: pre-wrap; word-break: break-all; line-height: 1.6;">
                  {{ pushDetailData.content || '-' }}
                </div>
              </el-descriptions-item>
              <el-descriptions-item v-if="pushDetailData.payload" label="Payload">
                <pre class="payload-pre">{{ JSON.stringify(
                  typeof pushDetailData.payload === 'string'
                    ? (() => {
                        try { return JSON.parse(pushDetailData.payload) } catch { return pushDetailData.payload }
                      })()
                    : pushDetailData.payload,
                  null,
                  2
                ) }}</pre>
              </el-descriptions-item>
            </el-descriptions>
          </div>

          <!-- 结果统计 -->
          <div class="detail-section">
            <div class="detail-title">结果统计</div>
            <el-descriptions :column="3" border size="small">
              <el-descriptions-item label="成功数">
                <span class="text-green-600 font-semibold text-lg">
                  {{ Number(pushDetailData.success_count) || 0 }}
                </span>
              </el-descriptions-item>
              <el-descriptions-item label="失败数">
                <span class="text-red-600 font-semibold text-lg">
                  {{ Number(pushDetailData.fail_count) || 0 }}
                </span>
              </el-descriptions-item>
              <el-descriptions-item label="总计">
                <span class="font-semibold text-lg">
                  {{ Number(pushDetailData.success_count || 0) + Number(pushDetailData.fail_count || 0) }}
                </span>
              </el-descriptions-item>
            </el-descriptions>
          </div>

          <!-- 失败原因摘要（若有） -->
          <div v-if="pushDetailData.fail_reason" class="detail-section">
            <div class="detail-title">失败原因</div>
            <el-alert
              :title="pushDetailData.fail_reason"
              type="error"
              :closable="false"
              show-icon
            >
              <template #default>
                <div style="white-space: pre-wrap; word-break: break-all; line-height: 1.6;">
                  {{ pushDetailData.fail_reason }}
                </div>
              </template>
            </el-alert>
          </div>

          <!-- 失败明细（若有） -->
          <div v-if="pushDetailData.fail_detail && pushDetailData.fail_detail.length > 0" class="detail-section">
            <div class="detail-title">失败明细（共 {{ pushDetailData.fail_detail.length }} 条）</div>
            <el-table :data="pushDetailData.fail_detail" size="small" border max-height="320">
              <el-table-column type="index" label="#" width="50" align="center" />
              <el-table-column prop="target" label="目标" width="180" show-overflow-tooltip>
                <template #default="{ row }">
                  <span style="font-family: monospace; font-size: 12px;">{{ row.target }}</span>
                </template>
              </el-table-column>
              <el-table-column prop="reason" label="失败原因" show-overflow-tooltip>
                <template #default="{ row }">
                  <span style="color: #f56c6c;">{{ row.reason }}</span>
                </template>
              </el-table-column>
            </el-table>
          </div>

          <!-- 推送详情明细（若有，调试用） -->
          <el-collapse v-if="pushDetailData.push_detail && pushDetailData.push_detail.length > 0" class="detail-section">
            <el-collapse-item title="推送详情明细（高级调试）" name="push_detail">
              <el-table :data="pushDetailData.push_detail" size="small" border max-height="320">
                <el-table-column type="index" label="#" width="50" align="center" />
                <el-table-column label="目标/FD" width="120" show-overflow-tooltip>
                  <template #default="{ row }">
                    <span style="font-family: monospace; font-size: 12px;">
                      {{ row.fd !== undefined ? 'fd:' + row.fd : (row.device_id || row.key || '-') }}
                    </span>
                  </template>
                </el-table-column>
                <el-table-column label="状态" width="100">
                  <template #default="{ row }">
                    <el-tag
                      :type="row.status === 'success' ? 'success' : (row.status === 'queued' ? 'primary' : 'danger')"
                      effect="plain"
                      round
                      size="small"
                    >
                      {{ row.status || '-' }}
                    </el-tag>
                  </template>
                </el-table-column>
                <el-table-column label="详情" show-overflow-tooltip>
                  <template #default="{ row }">
                    <span style="font-size: 12px; color: #606266;">{{ row.message || '-' }}</span>
                  </template>
                </el-table-column>
              </el-table>
            </el-collapse-item>
          </el-collapse>
        </template>
      </div>
      <template #footer>
        <el-button @click="pushDetailVisible = false">关闭</el-button>
        <el-button
          v-if="pushDetailData && Number(pushDetailData.status) !== 1 && Number(pushDetailData.status) !== 3"
          type="primary"
          :icon="RefreshIcon"
          :loading="retryPushLoading"
          @click="
            () => {
              pushDetailVisible = false
              if (pushDetailData) retryPushLog(pushDetailData)
            }
          "
        >
          重新推送
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  Plus as PlusIcon,
  Search as SearchIcon,
  RefreshLeft as RefreshLeftIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Download as DownloadIcon,
  ArrowDown as ArrowDownIcon,
  CopyDocument as CopyDocumentIcon,
  Key as KeyIcon,
  Lock as LockIcon,
  Unlock as UnlockIcon,
  View as ViewIcon,
  Refresh as RefreshIcon,
  SwitchButton as SwitchButtonIcon
} from '@element-plus/icons-vue'
import { exportPushLogsApi, getPushLogListApi, sendPushApi, retryPushApi, getPushLogDetailApi, deletePushLogApi } from '@/api/push'
import { getKeyListApi, createKeyApi, updateKeyApi, deleteKeyApi } from '@/api/key'
import { getDeviceListApi, deleteDeviceApi, toggleDeviceStatusApi, kickDeviceApi } from '@/api/device'
import {
  getBlacklistApi,
  createBlacklistApi,
  deleteBlacklistApi
} from '@/api/blacklist'
import {
  getAdminListApi,
  createAdminApi,
  updateAdminApi,
  deleteAdminApi
} from '@/api/admin'
import { getUserListApi, createUserApi, updateUserApi, deleteUserApi, resetUserPasswordApi } from '@/api/user'
import { getApiKeyListApi } from '@/api/apiKey'
import type { KeyForm, BlacklistForm, AdminForm, UserForm } from '@/api/types'

interface FieldConfig {
  prop: string
  label: string
  type: 'input' | 'textarea' | 'number' | 'select' | 'switch'
  options?: { label: string; value: any }[]
  required?: boolean
  placeholder?: string
  tip?: string
}

interface ColumnConfig {
  prop: string
  label: string
  width?: number
  slot?: 'status' | 'tag' | 'online' | 'platform'
        | 'targetType' | 'targetValue' | 'count' | 'email' | 'phone'
        | 'notifyEnabled' | 'notifyEmail' | 'notifyInterval'
        | 'failReason' | 'elapsedMs' | 'deviceText'
}

// 各模块配置
const moduleConfigs: Record<string, {
  title: string
  columns: ColumnConfig[]
  fields: FieldConfig[]
  mockRow: () => Record<string, any>
}> = {
  users: {
    title: '用户',
    columns: [
      { prop: 'id', label: '用户ID', width: 100 },
      { prop: 'username', label: '用户名', width: 160 },
      { prop: 'email', label: '邮箱', slot: 'email' },
      { prop: 'phone', label: '手机号', width: 160, slot: 'phone' },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'created_at', label: '注册时间', width: 170 }
    ],
    fields: [
      { prop: 'username', label: '用户名', type: 'input', required: true, placeholder: '字母、数字、下划线，4-20位' },
      { prop: 'password', label: '密码', type: 'input', required: true, placeholder: '默认密码 Admin@123，用户可自行修改' },
      { prop: 'phone', label: '手机号', type: 'input', placeholder: '11位手机号' },
      { prop: 'email', label: '邮箱', type: 'input', placeholder: '用于账号安全通知' },
      { prop: 'status', label: '状态', type: 'switch' }
    ],
    mockRow: () => ({
      id: 0,
      username: 'user_' + Math.floor(Math.random() * 9999),
      email: 'user' + Math.floor(Math.random() * 9999) + '@example.com',
      phone: '138' + String(Math.floor(Math.random() * 100000000)).padStart(8, '0'),
      status: Math.random() > 0.2 ? 1 : 0,
      created_at: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  keys: {
    title: 'Key',
    columns: [
      { prop: 'key_value', label: 'AppKey', width: 220 },
      { prop: 'name', label: '名称', width: 160 },
      { prop: 'max_devices', label: '最大设备数', width: 110 },
      { prop: 'notify_enabled', label: '掉线通知', width: 100, slot: 'notifyEnabled' },
      { prop: 'notify_email', label: '通知邮箱', width: 220, slot: 'notifyEmail' },
      { prop: 'notify_interval', label: '通知间隔', width: 120, slot: 'notifyInterval' },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'created_at', label: '创建时间', width: 170 }
    ],
    fields: [
      { prop: 'name', label: '名称', type: 'input', required: true, placeholder: '用于区分不同的应用或场景', tip: '建议填写应用名称，例如：官网APP、内部OA' },
      { prop: 'max_devices', label: '最大设备数', type: 'number', tip: '0 表示不限制；大于 0 表示该 Key 下最多允许连接多少台设备，超过会拒绝新连接' },
      { prop: 'status', label: '状态', type: 'switch' },
      { prop: 'notify_enabled', label: '启用掉线通知', type: 'switch', tip: '开启后，设备掉线会向指定邮箱发送告警邮件' },
      { prop: 'notify_email', label: '通知邮箱', type: 'input', placeholder: '多个邮箱用英文逗号分隔，如：a@qq.com,b@163.com', tip: '支持 QQ 邮箱、163 邮箱、Gmail 等，建议至少填 2 个以免漏收' },
      { prop: 'notify_interval', label: '通知间隔(秒)', type: 'number', tip: '同一设备的重复掉线通知最小间隔，默认 300 秒（5分钟），可避免邮件轰炸' }
    ],
    mockRow: () => ({
      id: 0,
      key_value: 'AK' + Math.floor(Math.random() * 9e15 + 1e15).toString(16),
      name: '应用Key ' + Math.floor(Math.random() * 99),
      max_devices: Math.random() > 0.5 ? 0 : Math.floor(Math.random() * 500),
      notify_enabled: Math.random() > 0.5 ? 1 : 0,
      notify_email: Math.random() > 0.5 ? 'admin@example.com' : 'dev@qq.com,ops@163.com',
      notify_interval: [60, 180, 300, 600][Math.floor(Math.random() * 4)],
      status: Math.random() > 0.15 ? 1 : 0,
      created_at: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  devices: {
    title: '设备',
    columns: [
      { prop: 'device_id', label: '设备ID', width: 220 },
      { prop: 'platform', label: '平台', width: 110, slot: 'platform' },
      { prop: 'model', label: '型号', slot: 'deviceText' },
      { prop: 'app_version', label: 'App版本', width: 110, slot: 'deviceText' },
      { prop: 'online', label: '在线', width: 90, slot: 'online' },
      { prop: 'status', label: '状态', width: 90, slot: 'tag' },
      { prop: 'last_active_at', label: '最后活跃', width: 170, slot: 'deviceText' }
    ],
    fields: [
      { prop: 'device_id', label: '设备ID', type: 'input', required: true },
      { prop: 'platform', label: '平台', type: 'select', required: true, options: [
        { label: 'Android', value: 'android' },
        { label: 'iOS', value: 'ios' },
        { label: 'Web', value: 'web' },
        { label: 'HarmonyOS', value: 'harmony' }
      ] },
      { prop: 'model', label: '型号', type: 'input' }
    ],
    mockRow: () => ({
      id: 0,
      device_id: 'D' + Math.floor(Math.random() * 9e15 + 1e15).toString(16),
      platform: ['android', 'ios', 'web', 'harmony'][Math.floor(Math.random() * 4)],
      model: ['iPhone 15', 'Xiaomi 14', 'HUAWEI Mate60', 'Pixel 8'][Math.floor(Math.random() * 4)],
      app_version: '2.' + Math.floor(Math.random() * 9) + '.' + Math.floor(Math.random() * 9),
      online: Math.random() > 0.4 ? 1 : 0,
      status: Math.random() > 0.15 ? 1 : 2,
      last_active_at: '2026-07-12 ' + String(Math.floor(Math.random() * 24)).padStart(2, '0') + ':' + String(Math.floor(Math.random() * 60)).padStart(2, '0')
    })
  },
  'push-logs': {
    title: '推送记录',
    columns: [
      { prop: 'id', label: 'ID', width: 80 },
      { prop: 'title', label: '推送标题', width: 220 },
      { prop: 'content', label: '内容' },
      { prop: 'target_type', label: '目标类型', width: 110, slot: 'targetType' },
      { prop: 'target_value', label: '目标值', width: 200, slot: 'targetValue' },
      { prop: 'success_count', label: '成功', width: 100, slot: 'count' },
      { prop: 'fail_count', label: '失败', width: 100, slot: 'count' },
      { prop: 'fail_reason', label: '失败原因', width: 240, slot: 'failReason' },
      { prop: 'status', label: '状态', width: 110, slot: 'status' },
      { prop: 'elapsed_ms', label: '耗时', width: 100, slot: 'elapsedMs' },
      { prop: 'created_at', label: '时间', width: 170 }
    ],
    fields: [
      { prop: 'title', label: '标题', type: 'input', required: true, placeholder: '例如：系统维护通知', tip: '推送消息的标题，会显示在设备通知栏' },
      { prop: 'content', label: '内容', type: 'textarea', required: true, placeholder: '例如：系统将于今晚 22:00-23:00 进行维护升级，期间推送服务可能短暂不可用', tip: '推送消息正文，支持纯文本' },
      { prop: 'target_type', label: '目标类型', type: 'select', required: true, options: [
        { label: '设备', value: 'device' },
        { label: 'Key', value: 'key' },
        { label: '广播', value: 'broadcast' },
        { label: '用户', value: 'user' }
      ], placeholder: '选择推送目标类型', tip: '设备：精确推送到 device_id；Key：按 key 分组推送；广播：推送给所有在线设备；用户：按 user_id 推送' },
      { prop: 'target_value', label: '目标值', type: 'input', required: true, placeholder: 'device_id / key_value / user_id，多个用英文逗号分隔', tip: '案例：device 类型填 dev_001,dev_002；key 类型填 my_app_key；broadcast 可留空' }
    ],
    mockRow: () => {
      const successCount = Math.floor(Math.random() * 8000)
      const failCount = Math.floor(Math.random() * 200)
      const reasons = ['设备离线，APP未连接或已断开', '连接不存在或已关闭', '发送缓冲区已满', '无订阅设备', '']
      return {
        id: 0,
        title: '推送消息 ' + Math.floor(Math.random() * 999),
        content: '这是一条测试推送消息内容，包含多字段用于展示。',
        target_type: ['device', 'key', 'broadcast', 'user'][Math.floor(Math.random() * 4)],
        target_value: 'target_' + Math.floor(Math.random() * 99999),
        success_count: successCount,
        fail_count: failCount,
        fail_reason: failCount > 0 ? reasons[Math.floor(Math.random() * reasons.length)] : '',
        // status: 0=失败 1=成功 2=部分成功 3=进行中
        status: failCount === 0 ? (successCount > 0 ? 1 : 3) : (successCount > 0 ? 2 : 0),
        elapsed_ms: Math.floor(Math.random() * 500) + 10,
        created_at: '2026-07-12 ' + String(Math.floor(Math.random() * 24)).padStart(2, '0') + ':' + String(Math.floor(Math.random() * 60)).padStart(2, '0')
      }
    }
  },
  blacklist: {
    title: '黑名单',
    columns: [
      { prop: 'type', label: '类型', width: 100 },
      { prop: 'value', label: '值' },
      { prop: 'reason', label: '原因' },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'created_at', label: '创建时间', width: 170 }
    ],
    fields: [
      { prop: 'type', label: '类型', type: 'select', required: true, options: [
        { label: '用户', value: 'user' },
        { label: '设备', value: 'device' },
        { label: 'IP', value: 'ip' }
      ] },
      { prop: 'value', label: '值', type: 'input', required: true },
      { prop: 'reason', label: '原因', type: 'textarea', required: true }
    ],
    mockRow: () => ({
      id: 0,
      type: ['user', 'device', 'ip'][Math.floor(Math.random() * 3)],
      value: 'block_' + Math.floor(Math.random() * 99999),
      reason: ['违规操作', '异常请求', '安全风险'][Math.floor(Math.random() * 3)],
      status: 1,
      created_at: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  admins: {
    title: '管理员',
    columns: [
      { prop: 'username', label: '账号', width: 140 },
      { prop: 'role', label: '角色', width: 140 },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'created_at', label: '创建时间', width: 170 }
    ],
    fields: [
      { prop: 'username', label: '账号', type: 'input', required: true },
      { prop: 'password', label: '密码', type: 'input', required: true },
      { prop: 'role', label: '角色', type: 'select', required: true, options: [
        { label: '超级管理员', value: 'super_admin' },
        { label: '管理员', value: 'admin' }
      ] },
      { prop: 'status', label: '状态', type: 'switch' }
    ],
    mockRow: () => ({
      id: 0,
      username: 'admin_' + Math.floor(Math.random() * 99),
      role: ['super_admin', 'admin'][Math.floor(Math.random() * 2)],
      status: 1,
      created_at: '2026-07-12 ' + String(Math.floor(Math.random() * 24)).padStart(2, '0') + ':00'
    })
  },
  'app-build': {
    title: 'APP打包',
    columns: [
      { prop: 'buildId', label: '构建ID', width: 160 },
      { prop: 'name', label: '应用名称' },
      { prop: 'platform', label: '平台', width: 90 },
      { prop: 'version', label: '版本', width: 100 },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'createdAt', label: '创建时间', width: 170 }
    ],
    fields: [
      { prop: 'name', label: '应用名', type: 'input', required: true },
      { prop: 'platform', label: '平台', type: 'select', required: true, options: [
        { label: 'Android', value: 'android' },
        { label: 'iOS', value: 'ios' }
      ] },
      { prop: 'version', label: '版本号', type: 'input', required: true }
    ],
    mockRow: () => ({
      id: 0,
      buildId: 'B' + Math.floor(Math.random() * 90000 + 10000),
      name: 'PushApp',
      platform: Math.random() > 0.5 ? 'android' : 'ios',
      version: '2.' + Math.floor(Math.random() * 9) + '.' + Math.floor(Math.random() * 9),
      status: Math.random() > 0.3 ? 1 : 0,
      createdAt: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  'api-keys': {
    title: 'API Key',
    columns: [
      { prop: 'name', label: '名称' },
      { prop: 'accessKey', label: 'AccessKey', width: 260 },
      { prop: 'rateLimit', label: '限流/分', width: 100 },
      { prop: 'permissions', label: '权限', width: 200, slot: 'tag' },
      { prop: 'expireAt', label: '过期时间', width: 180 },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'createdAt', label: '创建时间', width: 170 }
    ],
    fields: [
      { prop: 'name', label: '名称', type: 'input', required: true },
      { prop: 'rateLimit', label: '限流(/分)', type: 'number' },
      { prop: 'status', label: '状态', type: 'switch' }
    ],
    mockRow: () => ({
      id: 0,
      name: 'API客户端 ' + Math.floor(Math.random() * 99),
      accessKey: 'AK' + Math.floor(Math.random() * 9e15 + 1e15).toString(16),
      rateLimit: [60, 100, 600, 1000][Math.floor(Math.random() * 4)],
      permissions: ['push:send', 'device:query', 'user:read'].slice(0, Math.floor(Math.random() * 3) + 1),
      status: 1,
      createdAt: '2026-07-' + String(Math.floor(Math.random() * 12) + 1).padStart(2, '0')
    })
  },
  settings: {
    title: '系统设置',
    columns: [
      { prop: 'siteName', label: '站点名称' },
      { prop: 'siteDescription', label: '站点描述' },
      { prop: 'status', label: '状态', width: 90, slot: 'status' },
      { prop: 'createdAt', label: '更新时间', width: 170 }
    ],
    fields: [
      { prop: 'siteName', label: '站点名称', type: 'input', required: true },
      { prop: 'siteDescription', label: '站点描述', type: 'textarea' }
    ],
    mockRow: () => ({
      id: 1,
      siteName: 'Push 推送平台',
      siteDescription: '即时消息推送管理系统',
      status: 1,
      createdAt: '2026-07-01 10:00:00'
    })
  }
}

const route = useRoute()
const loading = ref(false)
const submitting = ref(false)
const tableData = ref<Record<string, any>[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pageSize: 10,
  keyword: '',
  status: undefined as number | undefined,
  platform: undefined as string | undefined,
  online: undefined as number | undefined,
  targetType: undefined as string | undefined
})

const currentModule = computed(() => (route.meta.module as string) || 'users')
const config = computed(() => moduleConfigs[currentModule.value] || moduleConfigs.users)
const moduleTitle = computed(() => config.value.title)
const columns = computed(() => config.value.columns)
const formFields = computed(() => config.value.fields)

// 弹窗
const dialogVisible = ref(false)
const dialogFormRef = ref<FormInstance>()
const dialogForm = reactive<Record<string, any>>({})
const isEdit = ref(false)

const dialogTitle = computed(() => `${isEdit.value ? '编辑' : '新增'}${moduleTitle.value}`)

const dialogRules = computed<FormRules>(() => {
  const rules: FormRules = {}
  formFields.value.forEach((f) => {
    if (f.required) {
      rules[f.prop] = [{ required: true, message: `请输入${f.label}`, trigger: 'blur' }]
    }
    if (f.prop === 'notify_email') {
      rules[f.prop] = [
        {
          validator: (rule, value, callback) => {
            if (!value) {
              callback()
              return
            }
            const emails = value.split(',').map((e: string) => e.trim()).filter((e: string) => e)
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
            for (const email of emails) {
              if (!emailRegex.test(email)) {
                callback(new Error(`邮箱格式不正确: ${email}`))
                return
              }
            }
            callback()
          },
          trigger: 'blur'
        }
      ]
    }
  })
  return rules
})

// 修改密码弹窗
const passwordDialogVisible = ref(false)
const passwordFormRef = ref<FormInstance>()
const resettingPassword = ref(false)
const passwordForm = reactive<{ id: number | null; password: string }>({
  id: null,
  password: ''
})
const passwordRules: FormRules = {
  password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 6, message: '密码长度不能少于6位', trigger: 'blur' }
  ]
}

function openPasswordDialog(row: Record<string, any>) {
  passwordForm.id = row.id
  passwordForm.password = ''
  passwordDialogVisible.value = true
}

async function handleResetPassword() {
  if (!passwordFormRef.value) return
  try {
    await passwordFormRef.value.validate()
  } catch {
    return
  }
  const userId = passwordForm.id
  if (userId === null) return
  resettingPassword.value = true
  try {
    await resetUserPasswordApi(userId, passwordForm.password)
    ElMessage.success('密码修改成功')
    passwordDialogVisible.value = false
  } catch (error) {
    ElMessage.error('密码修改失败')
  }
  resettingPassword.value = false
}

// 生成模拟数据
function generateMockData(): Record<string, any>[] {
  const list: Record<string, any>[] = []
  const count = 38
  for (let i = 0; i < count; i++) {
    const row = config.value.mockRow()
    row.id = i + 1
    list.push(row)
  }
  return list
}

let allData: Record<string, any>[] = []

async function fetchData() {
  loading.value = true
  try {
    const mod = currentModule.value
    if (mod === 'keys') {
      const res = await getKeyListApi(query)
      tableData.value = res.data?.list || []
      total.value = res.data?.total || 0
    } else if (mod === 'api-keys') {
      // API Key 模块：接入真实后端 API
      const res = await getApiKeyListApi(query)
      const rawList = res.data?.list || []
      tableData.value = rawList.map((row: any) => ({
        ...row,
        // 字段映射：后端 key_value -> 前端 accessKey（列配置用 accessKey）
        accessKey: row.key_value || row.accessKey || '',
        // 后端 created_at -> 前端 createdAt
        createdAt: row.created_at || row.createdAt || '',
        // 后端 expire_at -> 前端 expireAt
        expireAt: row.expire_at || row.expireAt || '永不过期',
        // 限流兜底（后端可能暂无此字段）
        rateLimit: row.rate_limit || row.rateLimit || '—',
        // 权限兜底
        permissions: Array.isArray(row.permissions) ? row.permissions : (row.permissions ? row.permissions.split(',').filter(Boolean) : [])
      }))
      total.value = res.data?.total || 0
    } else if (mod === 'blacklist') {
      const res = await getBlacklistApi(query)
      tableData.value = res.data?.list || []
      total.value = res.data?.total || 0
    } else if (mod === 'users') {
      const res = await getUserListApi(query)
      tableData.value = res.data?.list || []
      total.value = res.data?.total || 0
    } else if (mod === 'admins') {
      const res = await getAdminListApi(query)
      tableData.value = res.data?.list || []
      total.value = res.data?.total || 0
    } else if (mod === 'devices') {
      const res = await getDeviceListApi(query)
      const rawList = res.data?.list || []
      tableData.value = rawList.map((row: any) => {
        // 后端 status 字段：1=启用 2=禁用
        const rawStatus = typeof row.status === 'object' ? (row.status as any)[0] : (Number(row.status) || 1)
        return {
          ...row,
          // 原始状态数字，供切换按钮判断
          _rawStatus: rawStatus,
          // 兼容旧字段
          model: row.model || row.device_model || '',
          // 将 status 数字转换为 tag 数组（1=启用 2=禁用），供 slot='tag' 渲染
          status: rawStatus === 2 ? ['禁用'] : ['启用'],
          // 确保 online 字段为数字
          online: Number(row.online) || 0,
          // 时间字段兜底
          last_active_at: row.last_active_at || row.last_connect_at || ''
        }
      })
      total.value = res.data?.total || 0
    } else if (mod === 'push-logs') {
      // 传 target_type 参数给后端（若支持则自动过滤，否则返回全量，前端兜底）
      const apiParams: any = { ...query }
      if (query.targetType) {
        apiParams.target_type = query.targetType
      }
      const res = await getPushLogListApi(apiParams)
      const rawList = res.data?.list || []
      tableData.value = rawList.map((row: any) => {
        // 兜底：后端未返回 status 时，根据 success_count/fail_count 计算
        let status = Number(row.status)
        if (Number.isNaN(status)) {
          const sc = Number(row.success_count) || 0
          const fc = Number(row.fail_count) || 0
          status = fc === 0 ? (sc > 0 ? 1 : 3) : (sc > 0 ? 2 : 0)
        }
        return { ...row, status }
      })
      total.value = res.data?.total || 0
    } else {
      await new Promise((r) => setTimeout(r, 300))
      let list = [...allData]
      if (query.keyword) {
        list = list.filter((item) =>
          JSON.stringify(item).toLowerCase().includes(query.keyword.toLowerCase())
        )
      }
      if (query.status !== undefined) {
        list = list.filter((item) => item.status === query.status)
      }
      total.value = list.length
      const start = (query.page - 1) * query.pageSize
      tableData.value = list.slice(start, start + query.pageSize)
    }
  } catch (error) {
    ElMessage.error('获取数据失败')
  }
  loading.value = false
}

function handleSearch() {
  query.page = 1
  fetchData()
}

function handleReset() {
  query.keyword = ''
  query.status = undefined
  query.platform = undefined
  query.online = undefined
  query.targetType = undefined
  query.page = 1
  fetchData()
}

function openDialog(row?: Record<string, any>) {
  isEdit.value = !!row
  Object.keys(dialogForm).forEach((k) => delete dialogForm[k])
  if (row) {
    Object.assign(dialogForm, JSON.parse(JSON.stringify(row)))
    if (currentModule.value === 'admins' && isEdit.value) {
      delete dialogForm.password
    }
  } else {
    formFields.value.forEach((f) => {
      if (f.type === 'switch') {
        dialogForm[f.prop] = 1
      } else if (f.type === 'number') {
        dialogForm[f.prop] = f.prop === 'max_devices' ? 10 : f.prop === 'notify_interval' ? 300 : 0
      } else if (f.prop === 'username') {
        dialogForm[f.prop] = `user_${Math.floor(Math.random() * 9000 + 1000)}`
      } else if (f.prop === 'nickname') {
        dialogForm[f.prop] = `用户${Math.floor(Math.random() * 900 + 100)}`
      } else if (f.prop === 'phone') {
        dialogForm[f.prop] = `138${String(Math.floor(Math.random() * 100000000)).padStart(8, '0')}`
      } else if (f.prop === 'name') {
        dialogForm[f.prop] = currentModule.value === 'keys'
          ? `应用Key_${Math.floor(Math.random() * 900 + 100)}`
          : `项目${Math.floor(Math.random() * 900 + 100)}`
      } else if (f.prop === 'password') {
        dialogForm[f.prop] = `Admin@${Math.floor(Math.random() * 9000 + 1000)}`
      } else if (f.prop === 'role' && f.options && f.options.length > 0) {
        dialogForm[f.prop] = 'admin'
      } else if (f.prop === 'value') {
        dialogForm[f.prop] = 'block_' + Math.floor(Math.random() * 99999)
      } else if (f.prop === 'reason') {
        dialogForm[f.prop] = ['违规操作', '异常请求', '安全风险'][Math.floor(Math.random() * 3)]
      } else if (f.prop === 'content') {
        dialogForm[f.prop] = '这是一条测试推送消息内容'
      } else if (f.prop === 'target_value') {
        dialogForm[f.prop] = 'device_' + Math.floor(Math.random() * 99999)
      } else if (f.prop === 'type' && f.options && f.options.length > 0) {
        dialogForm[f.prop] = f.options[0].value
      } else if (f.prop === 'target_type' && f.options && f.options.length > 0) {
        dialogForm[f.prop] = f.options[0].value
      } else if (f.prop === 'platform' && f.options && f.options.length > 0) {
        dialogForm[f.prop] = 'all'
      } else {
        dialogForm[f.prop] = ''
      }
    })
  }
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!dialogFormRef.value) return
  try {
    await dialogFormRef.value.validate()
  } catch {
    return
  }
  submitting.value = true
  try {
    const mod = currentModule.value
    if (mod === 'users') {
      if (isEdit.value) {
        await updateUserApi(dialogForm.id, dialogForm as unknown as UserForm)
        ElMessage.success('更新成功')
      } else {
        await createUserApi(dialogForm as unknown as UserForm)
        ElMessage.success('新增成功')
      }
    } else if (mod === 'keys') {
      if (isEdit.value) {
        await updateKeyApi(dialogForm.id, dialogForm as unknown as KeyForm)
        ElMessage.success('更新成功')
      } else {
        await createKeyApi(dialogForm as unknown as KeyForm)
        ElMessage.success('新增成功')
      }
    } else if (mod === 'blacklist') {
      if (isEdit.value) {
        ElMessage.info('黑名单暂不支持编辑')
      } else {
        await createBlacklistApi(dialogForm as unknown as BlacklistForm)
        ElMessage.success('新增成功')
      }
    } else if (mod === 'admins') {
      if (isEdit.value) {
        const updateData = { ...dialogForm }
        if (!updateData.password) delete updateData.password
        await updateAdminApi(dialogForm.id, updateData as unknown as AdminForm)
        ElMessage.success('更新成功')
      } else {
        await createAdminApi(dialogForm as unknown as AdminForm)
        ElMessage.success('新增成功')
      }
    } else if (mod === 'push-logs') {
      // 推送记录不支持编辑，仅支持新增（即发起一次推送）
      if (isEdit.value) {
        ElMessage.info('推送记录为历史记录，不支持编辑')
        return
      }
      // 后端 /admin/push/send 兼容 target_type/target_value 字段名
      const payload = {
        title: dialogForm.title,
        content: dialogForm.content,
        target_type: dialogForm.target_type,   // 'device' | 'key'
        target_value: dialogForm.target_value,  // device_id 或 key_value（可逗号分隔多个）
        pushType: 'notification',
      }
      const res = await sendPushApi(payload as any)
      if (res.success) {
        ElMessage.success(res.message || `推送成功（成功 ${res.success_count}，失败 ${res.fail_count}）`)
      } else {
        ElMessage.warning(res.message || '推送失败，可能没有在线设备')
      }
    } else {
      await new Promise((r) => setTimeout(r, 400))
      if (isEdit.value) {
        const idx = allData.findIndex((item) => item.id === dialogForm.id)
        if (idx > -1) {
          allData[idx] = { ...dialogForm }
        }
        ElMessage.success('更新成功')
      } else {
        const newId = Math.max(0, ...allData.map((i) => i.id)) + 1
        allData.unshift({ ...dialogForm, id: newId })
        ElMessage.success('新增成功')
      }
    }
  } catch (error) {
    ElMessage.error('操作失败')
  }
  submitting.value = false
  dialogVisible.value = false
  fetchData()
}

// 平台标签文本映射
function platformLabel(platform: string): string {
  const map: Record<string, string> = {
    android: 'Android',
    ios: 'iOS',
    web: 'Web',
    harmony: 'HarmonyOS'
  }
  return map[platform] || platform || '-'
}

// 平台标签颜色映射
function platformTagType(platform: string): 'success' | 'warning' | 'info' | 'primary' | 'danger' {
  const map: Record<string, 'success' | 'warning' | 'info' | 'primary' | 'danger'> = {
    android: 'success',
    ios: 'primary',
    web: 'warning',
    harmony: 'danger'
  }
  return map[platform] || 'info'
}

// ------------------------------------------------------------
// 推送记录：目标类型映射
// ------------------------------------------------------------
function targetTypeLabel(type: string): string {
  const map: Record<string, string> = {
    device:    '设备',
    key:       '按 Key',
    broadcast: '广播',
    user:      '用户'
  }
  return map[type] || type || '-'
}
function targetTypeTagType(type: string): 'success' | 'warning' | 'info' | 'primary' | 'danger' {
  const map: Record<string, 'success' | 'warning' | 'info' | 'primary' | 'danger'> = {
    device:    'success',
    key:       'primary',
    broadcast: 'danger',
    user:      'warning'
  }
  return map[type] || 'info'
}

// 手机号格式化（脱敏显示：138****1234）
function formatPhone(phone: string): string {
  if (!phone) return ''
  if (phone.length === 11) {
    return phone.substring(0, 3) + '****' + phone.substring(7)
  }
  return phone
}

// 秒数转换为人类可读时长（300秒 -> 5分钟）
function formatDuration(seconds: number | string): string {
  const s = Number(seconds) || 0
  if (s <= 0) return '-'
  if (s < 60) return s + ' 秒'
  if (s < 3600) {
    const m = Math.floor(s / 60)
    const r = s % 60
    return r > 0 ? `${m}分${r}秒` : `${m}分钟`
  }
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  return m > 0 ? `${h}小时${m}分钟` : `${h}小时`
}

// 判断过期时间是否已过期
function isExpired(expireAt: string): boolean {
  if (!expireAt || expireAt === '永不过期') return false
  const ts = new Date(expireAt.replace(/-/g, '/')).getTime()
  return !Number.isNaN(ts) && ts < Date.now()
}

// 判断过期时间是否即将过期（7天内）
function isExpireSoon(expireAt: string): boolean {
  if (!expireAt || expireAt === '永不过期') return false
  const ts = new Date(expireAt.replace(/-/g, '/')).getTime()
  if (Number.isNaN(ts)) return false
  const diff = ts - Date.now()
  return diff > 0 && diff < 7 * 24 * 3600 * 1000
}

// 复制到剪贴板（兼容HTTP环境）
async function copyToClipboard(text: string) {
  if (!text) return
  try {
    // 优先使用现代 Clipboard API
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      ElMessage.success('已复制到剪贴板')
      return
    }
    // 回退方案：使用 textarea + execCommand
    const textarea = document.createElement('textarea')
    textarea.value = text
    textarea.style.position = 'fixed'
    textarea.style.left = '-9999px'
    textarea.style.top = '0'
    document.body.appendChild(textarea)
    textarea.focus()
    textarea.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(textarea)
    if (ok) {
      ElMessage.success('已复制到剪贴板')
    } else {
      ElMessage.warning('复制失败，请手动复制')
    }
  } catch {
    ElMessage.warning('复制失败，请手动复制')
  }
}

async function handleDelete(row: Record<string, any>) {
  try {
    await ElMessageBox.confirm(`确定要删除该${moduleTitle.value}吗？`, '提示', {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning',
      center: true
    })
    const mod = currentModule.value
    if (mod === 'users') {
      await deleteUserApi(row.id)
    } else if (mod === 'keys') {
      await deleteKeyApi(row.id)
    } else if (mod === 'blacklist') {
      await deleteBlacklistApi(row.id)
    } else if (mod === 'admins') {
      await deleteAdminApi(row.id)
    } else if (mod === 'devices') {
      await deleteDeviceApi(row.id)
    } else if (mod === 'push-logs') {
      await deletePushLogApi(row.id)
    } else {
      allData = allData.filter((item) => item.id !== row.id)
    }
    ElMessage.success('删除成功')
    fetchData()
  } catch {
    // 取消
  }
}

// 切换设备状态（禁用/启用）
// ------------------------------------------------------------
// 推送记录：查看详情（弹窗展示完整内容与统计）
// ------------------------------------------------------------
const pushDetailVisible = ref(false)
const pushDetailData = ref<Record<string, any> | null>(null)
const pushDetailLoading = ref(false)

async function viewPushLogDetail(row: Record<string, any>) {
  pushDetailData.value = null
  pushDetailVisible.value = true
  if (!row.id) {
    pushDetailData.value = { ...row }
    return
  }
  pushDetailLoading.value = true
  try {
    const res = await getPushLogDetailApi(row.id)
    pushDetailData.value = res.data || { ...row }
  } catch {
    // 接口不可用时回退到行数据
    pushDetailData.value = { ...row }
  } finally {
    pushDetailLoading.value = false
  }
}

// 推送状态标签
function pushStatusLabel(status: number) {
  const map: Record<number, string> = { 0: '失败', 1: '成功', 2: '部分成功', 3: '进行中', 4: '已存离线' }
  return map[status] || '未知'
}
function pushStatusType(status: number): 'success' | 'danger' | 'warning' | 'primary' | 'info' {
  const map: Record<number, 'success' | 'danger' | 'warning' | 'primary' | 'info'> = {
    0: 'danger',
    1: 'success',
    2: 'warning',
    3: 'primary',
    4: 'info'
  }
  return map[status] || 'info'
}

// ------------------------------------------------------------
// 推送记录：重新推送（对失败的消息进行重试）
// ------------------------------------------------------------
const retryPushLoading = ref(false)
async function retryPushLog(row: Record<string, any>) {
  try {
    await ElMessageBox.confirm(
      `确定要对该推送记录（ID: ${row.id}）进行重新推送吗？仅会对推送失败的目标重新发送。`,
      '重新推送',
      {
        confirmButtonText: '确认推送',
        cancelButtonText: '取消',
        type: 'warning',
        center: true
      }
    )
    retryPushLoading.value = true
    await retryPushApi(row.id)
    ElMessage.success('已提交重新推送任务，请稍后查看最新状态')
    fetchData()
  } catch {
    // 取消或失败
  } finally {
    retryPushLoading.value = false
  }
}

async function handleToggleDeviceStatus(row: Record<string, any>) {
  try {
    const current = (row._rawStatus ?? (Array.isArray(row.status) ? (row.status[0] === '禁用' ? 2 : 1) : (row.status ?? 1))) as number
    const next = current === 2 ? 1 : 2
    const action = next === 2 ? '禁用' : '启用'
    await ElMessageBox.confirm(
      `确定要${action}该设备吗？禁用后设备将断开连接并无法接收推送。`,
      `${action}设备`,
      {
        confirmButtonText: action,
        cancelButtonText: '取消',
        type: next === 2 ? 'warning' : 'success',
        center: true
      }
    )
    await toggleDeviceStatusApi(row.id, next)
    ElMessage.success(`${action}成功`)
    fetchData()
  } catch {
    // 取消
  }
}

// 踢出设备（强制断开连接）
const kickingDeviceId = ref(0)
async function handleKickDevice(row: Record<string, any>) {
  try {
    await ElMessageBox.confirm(
      `确定要踢出设备 ${row.device_id} 吗？将断开其所有在线连接，设备可重新连接。`,
      '踢出设备',
      {
        confirmButtonText: '踢出',
        cancelButtonText: '取消',
        type: 'warning',
        center: true
      }
    )
    kickingDeviceId.value = row.id
    const res = await kickDeviceApi(row.id)
    ElMessage.success(res.data?.message || '已踢出')
    fetchData()
  } catch {
    // 取消
  } finally {
    kickingDeviceId.value = 0
  }
}

// 一键清空
async function handleClearAll() {
  try {
    await ElMessageBox.confirm(
      `确定要清空所有${moduleTitle.value}吗？此操作不可恢复！`,
      '危险操作',
      {
        confirmButtonText: '确定清空',
        cancelButtonText: '取消',
        type: 'error',
        confirmButtonClass: 'el-button--danger',
        center: true
      }
    )
    const mod = currentModule.value
    if (mod === 'users') {
      // 用户：逐条删除（或调用清空接口）
      const items = tableData.value
      for (const item of items) {
        try {
          await deleteUserApi(item.id)
        } catch {
          // 跳过删除失败的
        }
      }
      ElMessage.success('已清空当前页的用户')
    } else if (mod === 'admins') {
      // 管理员：逐条删除（保留当前登录的管理员）
      const items = tableData.value
      for (const item of items) {
        try {
          await deleteAdminApi(item.id)
        } catch {
          // 跳过删除失败的（如当前登录的管理员）
        }
      }
      ElMessage.success('已清空可删除的管理员')
    } else if (mod === 'keys') {
      // Key：逐条删除
      const items = tableData.value
      for (const item of items) {
        try {
          await deleteKeyApi(item.id)
        } catch {
          // 跳过删除失败的
        }
      }
      ElMessage.success('已清空可删除的Key')
    } else if (mod === 'push-logs') {
      // 推送记录：逐条删除
      const items = tableData.value
      for (const item of items) {
        try {
          await deletePushLogApi(item.id)
        } catch {
          // 跳过删除失败的
        }
      }
      ElMessage.success('已清空当前页的推送记录')
    } else {
      // 用户等模拟数据模块：直接清空本地数据
      allData = []
      ElMessage.success('已清空全部数据')
    }
    fetchData()
  } catch {
    // 取消
  }
}

// 模块切换时重新加载数据
watch(
  () => currentModule.value,
  () => {
    allData = generateMockData()
    query.page = 1
    query.keyword = ''
    query.status = undefined
    query.platform = undefined
    query.online = undefined
    query.targetType = undefined
    fetchData()
  },
  { immediate: true }
)

// ========== 导出功能 ==========

const exporting = ref(false)

/** 处理导出命令 */
async function handleExport(format: string) {
  exporting.value = true
  try {
    const res = await exportPushLogsApi({
      format: format as 'csv' | 'json',
      keyword: query.keyword || undefined,
    })
    // 从响应头解析文件名
    const disposition = (res as any).headers?.['content-disposition'] || ''
    let filename = `push_logs_${Date.now()}.${format}`
    const match = disposition.match(/filename="?([^"]+)"?/)
    if (match) {
      filename = match[1]
    }
    // 创建下载链接
    const blob = new Blob([(res as any).data], {
      type: format === 'csv' ? 'text/csv;charset=utf-8' : 'application/json;charset=utf-8',
    })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    ElMessage.success('导出成功')
  } catch (err) {
    ElMessage.error('导出失败')
  } finally {
    exporting.value = false
  }
}
</script>

<style lang="scss" scoped>
.module-page {
  animation: fade-up 0.4s ease;
}

.header-actions {
  display: flex;
  align-items: center;
}

.field-tip {
  font-size: 12px;
  color: var(--el-text-color-secondary, #909399);
  margin-top: 4px;
  line-height: 1.5;
}

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

// 推送详情弹窗样式
.detail-section {
  margin-bottom: 18px;

  .detail-title {
    font-size: 14px;
    font-weight: 600;
    color: #303133;
    margin: 0 0 8px 2px;
    padding-left: 8px;
    border-left: 3px solid #409eff;
  }
}

.payload-pre {
  margin: 0;
  padding: 12px;
  background: #f5f7fa;
  border-radius: 6px;
  font-family: Consolas, Monaco, 'Courier New', monospace;
  font-size: 12px;
  line-height: 1.6;
  color: #303133;
  white-space: pre-wrap;
  word-break: break-all;
  max-height: 300px;
  overflow: auto;
}

// 通用工具类（与全局 class 保持一致）
.text-green-600 { color: #67c23a; }
.text-red-600   { color: #f56c6c; }
.font-semibold  { font-weight: 600; }
.text-lg        { font-size: 16px; }
</style>
