package com.push.app.network

import com.push.app.data.PreferencesManager
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.TimeUnit

object ApiClient {

    private const val TAG = "ApiClient"

    val client: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(15, TimeUnit.SECONDS)
            .addInterceptor(authorizationInterceptor())
            .build()
    }

    fun baseUrl(): String {
        return runCatching { PreferencesManager.getServerUrl() }.getOrDefault("")
    }

    private fun authorizationInterceptor(): Interceptor {
        return Interceptor { chain ->
            val original = chain.request()
            val token = runCatching { PreferencesManager.getUserToken() }.getOrDefault("")

            val requestBuilder: Request.Builder = original.newBuilder()
                .header("Content-Type", "application/json")
                .header("Accept", "application/json")

            if (token.isNotBlank()) {
                requestBuilder.header("Authorization", "Bearer $token")
            }

            chain.proceed(requestBuilder.build())
        }
    }
}
