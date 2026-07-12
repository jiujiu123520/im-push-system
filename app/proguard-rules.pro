# ProGuard / R8 规则

# OkHttp
-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn org.conscrypt.**
-keep class okhttp3.** { *; }
-keep class okio.** { *; }

# kotlinx.serialization
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.AnnotationsKt
-keepclassmembers class kotlinx.serialization.json.** {
    *** Companion;
}
-keepclasseswithmembers class kotlinx.serialization.json.** {
    kotlinx.serialization.KSerializer serializer(...);
}
-keep,includedescriptorclasses class com.push.app.data.**$$serializer { *; }
-keepclassmembers class com.push.app.data.** {
    *** Companion;
}
-keepclasseswithmembers class com.push.app.data.** {
    kotlinx.serialization.KSerializer serializer(...);
}

# 保留数据模型
-keep class com.push.app.data.PushMessage { *; }

# WorkManager
-keep class androidx.work.** { *; }
-dontwarn androidx.work.**
