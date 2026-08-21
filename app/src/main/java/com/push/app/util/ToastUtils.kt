package com.push.app.util

import android.content.Context
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.view.Gravity
import android.view.View
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.annotation.StringRes
import kotlin.math.min

/**
 * 统一 Toast：
 *  - 替换原生 Toast.makeText() 单行截断/ROM 定制导致文字显示不全的坑。
 *  - 固定屏幕 85% 宽、内部 TextView 最多 4 行可换行、半透明深色圆角背景；
 *    短消息一行居中，长错误信息（URL、堆栈摘要）也能完整显示 2~4 行不截断。
 */
object ToastUtils {

    private const val MAX_LINES = 4
    private const val WIDTH_RATIO = 0.85f
    private const val MAX_WIDTH_DP = 340f

    fun show(context: Context?, msg: CharSequence?, duration: Int = Toast.LENGTH_SHORT) {
        if (context == null || msg.isNullOrEmpty()) return
        val appCtx = context.applicationContext ?: context
        val toast = Toast(appCtx)
        toast.duration = duration
        toast.view = buildView(appCtx, msg.toString())
        toast.setGravity(Gravity.CENTER, 0, 0)
        runCatching { toast.show() }
    }

    fun show(context: Context?, @StringRes resId: Int, duration: Int = Toast.LENGTH_SHORT) {
        val ctx = context?.applicationContext ?: context ?: return
        show(ctx, ctx.getText(resId), duration)
    }

    // --------------- 内部实现 ---------------

    private fun buildView(ctx: Context, message: String): View {
        val dm = ctx.resources.displayMetrics
        val density = dm.density
        val screenWidth = dm.widthPixels

        val paddingH = (18 * density).toInt()
        val paddingV = (12 * density).toInt()
        val radius = (18 * density)

        // 1. 容器背景：深灰 88% 透明度 + 细白描边 + 18dp 圆角
        val bg = GradientDrawable().apply {
            shape = GradientDrawable.RECTANGLE
            cornerRadius = radius
            setColor(0xDC_1A1A28.toInt())
            setStroke((1.2 * density).toInt(), 0x26_FFFFFF.toInt())
        }

        // 2. 文字：纯白 15sp、最多 4 行、水平居中、行距略松
        val text = TextView(ctx).apply {
            text = message
            setTextColor(Color.WHITE)
            textSize = 15f
            setLineSpacing((4 * density), 1f)
            maxLines = MAX_LINES
            gravity = Gravity.CENTER
            includeFontPadding = false
        }

        // 3. 容器 + 加内边距 + 应用背景
        val maxWidthPx = min(
            (screenWidth * WIDTH_RATIO).toInt(),
            (MAX_WIDTH_DP * density).toInt(),
        )
        val container = object : LinearLayout(ctx) {
            override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
                val wms = MeasureSpec.makeMeasureSpec(maxWidthPx, MeasureSpec.AT_MOST)
                super.onMeasure(wms, heightMeasureSpec)
            }
        }.apply {
            orientation = VERTICAL
            layoutParams = LayoutParams(LayoutParams.WRAP_CONTENT, LayoutParams.WRAP_CONTENT)
            setPadding(paddingH, paddingV, paddingH, paddingV)
            background = bg
        }
        container.addView(
            text,
            LayoutParams(LayoutParams.WRAP_CONTENT, LayoutParams.WRAP_CONTENT).apply {
                gravity = Gravity.CENTER_HORIZONTAL
            }
        )
        return container
    }
}
