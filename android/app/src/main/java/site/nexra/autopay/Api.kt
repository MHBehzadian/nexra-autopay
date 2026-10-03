package site.nexra.autopay

import android.content.Context
import android.os.Build
import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

data class Reply(val ok: Boolean, val body: String, val code: Int)

/**
 * Every request is signed with HMAC-SHA256 over the exact bytes being sent.
 * The key never leaves the phone, and the server rejects anything unsigned.
 */
object Api {

    private fun sign(key: String, payload: ByteArray): String {
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(key.toByteArray(), "HmacSHA256"))
        return mac.doFinal(payload).joinToString("") { "%02x".format(it) }
    }

    fun post(ctx: Context, json: JSONObject): Reply {
        val url = Prefs.url(ctx)
        val key = Prefs.key(ctx)
        if (url.isEmpty() || key.isEmpty()) return Reply(false, "not paired", 0)

        json.put("device", "${Build.MANUFACTURER} ${Build.MODEL} / Android ${Build.VERSION.RELEASE}")
        val payload = json.toString().toByteArray(Charsets.UTF_8)

        return try {
            val conn = (URL(url).openConnection() as HttpURLConnection).apply {
                requestMethod = "POST"
                connectTimeout = 20000
                readTimeout = 25000
                doOutput = true
                setRequestProperty("Content-Type", "application/json; charset=utf-8")
                setRequestProperty("X-Autopay-Signature", sign(key, payload))
            }
            conn.outputStream.use { it.write(payload) }
            val code = conn.responseCode
            val stream = if (code in 200..299) conn.inputStream else conn.errorStream
            val text = stream?.bufferedReader()?.use(BufferedReader::readText) ?: ""
            conn.disconnect()
            Reply(code in 200..299, text, code)
        } catch (e: Exception) {
            Reply(false, e.message ?: "network error", 0)
        }
    }

    fun heartbeat(ctx: Context): Reply =
        post(ctx, JSONObject().put("action", "heartbeat").put("nonce", System.currentTimeMillis()))

    fun sendSms(ctx: Context, p: Pending): Reply =
        post(
            ctx, JSONObject()
                .put("action", "sms")
                .put("sender", p.sender)
                .put("body", p.body)
                .put("sent_at", p.sentAt)
                .put("nonce", "${p.id}-${System.currentTimeMillis()}")
        )
}
