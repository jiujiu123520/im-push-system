// 模块级 build.gradle.kts - 即时消息推送 Android 客户端
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.serialization")
}

android {
    namespace = "com.push.app"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.push.app"
        minSdk = 21
        targetSdk = 34
        versionCode = 1
        versionName = "1.0.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        vectorDrawables {
            useSupportLibrary = true
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        compose = true
        buildConfig = true
    }
    composeOptions {
        // 与 Kotlin 1.9.24 对应的 Compose 编译器版本
        kotlinCompilerExtensionVersion = "1.5.14"
    }
    // DEX 编译内存限制（2G 服务器优化）
    dexOptions {
        javaMaxHeapSize = "512m"
        // 禁用预 DEX，减少内存占用（牺牲少量构建时间）
        preDexLibraries = false
    }
    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }
}

dependencies {
    // Compose BOM - 统一管理 Compose 库版本
    val composeBom = platform("androidx.compose:compose-bom:2024.06.00")
    implementation(composeBom)
    androidTestImplementation(composeBom)

    // Compose 核心
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.2")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.2")
    implementation("androidx.activity:activity-compose:1.9.0")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-graphics")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")

    // Navigation Compose - 页面导航
    implementation("androidx.navigation:navigation-compose:2.7.7")

    // DataStore - 偏好存储
    implementation("androidx.datastore:datastore-preferences:1.1.1")

    // WorkManager - 后台定时保活任务
    implementation("androidx.work:work-runtime-ktx:2.9.0")

    // OkHttp - WebSocket 长连接
    implementation("com.squareup.okhttp3:okhttp:4.12.0")

    // Coil - 图片加载
    implementation("io.coil-kt:coil-compose:2.6.0")

    // Accompanist Permissions - 运行时权限申请
    implementation("com.google.accompanist:accompanist-permissions:0.34.0")

    // Coroutines - 协程
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.8.0")
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.6.3")

    // 调试与测试
    debugImplementation("androidx.compose.ui:ui-tooling")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
    testImplementation("junit:junit:4.13.2")
    androidTestImplementation("androidx.test.ext:junit:1.1.5")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.5.1")
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
}
