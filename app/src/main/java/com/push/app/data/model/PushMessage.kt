package com.push.app.data.model

import org.json.JSONObject

data class PushMessage(
    val id: String,
    val title: String,
    val content: String,
    val type: String,
    val priority: String,
    val createdAt: Long,
    val read: Boolean,
    val extras: Map<String, String>? = null,
) {
    companion object {
        fun fromJson(jsonString: String): PushMessage {
            val json = JSONObject(jsonString)
            val extras = json.optJSONObject("extras")?.let { obj ->
                val map = mutableMapOf<String, String>()
                val keys = obj.keys()
                while (keys.hasNext()) {
                    val key = keys.next()
                    map[key] = obj.optString(key)
                }
                map
            }
            return PushMessage(
                id = json.optString("id"),
                title = json.optString("title"),
                content = json.optString("content"),
                type = json.optString("type", "push"),
                priority = json.optString("priority", "default"),
                createdAt = json.optLong("createdAt", System.currentTimeMillis()),
                read = json.optBoolean("read", false),
                extras = extras,
            )
        }
    }

    fun toJson(): JSONObject {
        return JSONObject().apply {
            put("id", id)
            put("title", title)
            put("content", content)
            put("type", type)
            put("priority", priority)
            put("createdAt", createdAt)
            put("read", read)
            extras?.let { put("extras", JSONObject(it)) }
        }
    }
}
