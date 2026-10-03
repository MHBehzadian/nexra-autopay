package site.nexra.autopay

import android.content.Context
import android.util.Base64
import org.json.JSONObject

/**
 * Where the pairing details live. The pairing string comes from the bot and
 * carries both the endpoint and the shared key, so the admin pastes one thing.
 */
object Prefs {
    private const val FILE = "autopay"

    fun get(ctx: Context) = ctx.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    var urlCache: String? = null

    fun url(ctx: Context): String = get(ctx).getString("url", "") ?: ""
    fun key(ctx: Context): String = get(ctx).getString("key", "") ?: ""
    fun paired(ctx: Context): Boolean = url(ctx).isNotEmpty() && key(ctx).isNotEmpty()

    fun lastSync(ctx: Context): Long = get(ctx).getLong("last_sync", 0L)
    fun setLastSync(ctx: Context, at: Long) = get(ctx).edit().putLong("last_sync", at).apply()

    fun lastError(ctx: Context): String = get(ctx).getString("last_error", "") ?: ""
    fun setLastError(ctx: Context, msg: String) = get(ctx).edit().putString("last_error", msg).apply()

    fun senderFilter(ctx: Context): String = get(ctx).getString("senders", "") ?: ""
    fun setSenderFilter(ctx: Context, v: String) = get(ctx).edit().putString("senders", v).apply()

    /**
     * "NXP1.<base64url of {u,k}>" - anything else is rejected, so a mistyped
     * string fails here instead of silently never delivering anything.
     */
    fun pair(ctx: Context, pairing: String): String? {
        val raw = pairing.trim()
        if (!raw.startsWith("NXP1.")) return "کلید نامعتبر است (باید با NXP1. شروع شود)"
        val payload = raw.removePrefix("NXP1.")
        return try {
            val json = String(Base64.decode(payload, Base64.URL_SAFE or Base64.NO_WRAP or Base64.NO_PADDING))
            val o = JSONObject(json)
            val u = o.optString("u")
            val k = o.optString("k")
            if (u.isEmpty() || k.isEmpty()) return "کلید ناقص است"
            if (!u.startsWith("https://")) return "آدرس سرور باید https باشد"
            get(ctx).edit().putString("url", u).putString("key", k).apply()
            null
        } catch (e: Exception) {
            "کلید خوانده نشد: ${e.message}"
        }
    }

    fun forget(ctx: Context) {
        get(ctx).edit().remove("url").remove("key").apply()
    }
}
