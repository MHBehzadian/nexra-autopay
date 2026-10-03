package site.nexra.autopay

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.app.Service
import android.os.Build
import android.os.IBinder
import android.provider.Telephony
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Keeps the outbox moving. It runs in the foreground because a background
 * service gets killed, and a payment confirmed ten minutes late is a customer
 * already asking where their config is.
 */
class ForwardService : Service() {

    companion object {
        const val CHANNEL = "autopay"
        const val ACTION_FLUSH = "site.nexra.autopay.FLUSH"
        const val ACTION_SCAN = "site.nexra.autopay.SCAN"
        private const val LOOP_MS = 60_000L
        private const val HEARTBEAT_MS = 300_000L

        fun start(ctx: Context) {
            val i = Intent(ctx, ForwardService::class.java)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) ctx.startForegroundService(i) else ctx.startService(i)
        }

        fun flush(ctx: Context) {
            val i = Intent(ctx, ForwardService::class.java).setAction(ACTION_FLUSH)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) ctx.startForegroundService(i) else ctx.startService(i)
        }

        val stamp: SimpleDateFormat
            get() = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
    }

    @Volatile private var running = false
    private var worker: Thread? = null
    private var lastBeat = 0L

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        notifyChannel()
        startForeground(1, buildNotice("در حال اجرا"))
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_SCAN) {
            Thread { scanInbox(24) }.start()
        }
        if (!running) {
            running = true
            worker = Thread { loop() }.also { it.start() }
        }
        return START_STICKY
    }

    override fun onDestroy() {
        running = false
        super.onDestroy()
    }

    private fun loop() {
        val store = Store(this)
        while (running) {
            try {
                if (Prefs.paired(this)) {
                    flushQueue(store)
                    if (System.currentTimeMillis() - lastBeat > HEARTBEAT_MS) {
                        val r = Api.heartbeat(this)
                        lastBeat = System.currentTimeMillis()
                        if (r.ok) {
                            Prefs.setLastSync(this, System.currentTimeMillis())
                            Prefs.setLastError(this, "")
                        } else {
                            Prefs.setLastError(this, r.body.take(120))
                        }
                    }
                }
                updateNotice(store)
            } catch (e: Exception) {
                Prefs.setLastError(this, e.message ?: "error")
            }
            Thread.sleep(LOOP_MS)
        }
    }

    /**
     * Sends whatever is waiting, oldest first. A message that keeps failing is
     * retried for ever rather than dropped - the server ignores duplicates.
     */
    private fun flushQueue(store: Store) {
        val items = store.pending()
        if (items.isEmpty()) return
        for (p in items) {
            val r = Api.sendSms(this, p)
            if (r.ok) {
                store.done(p.id)
                Prefs.setLastSync(this, System.currentTimeMillis())
                Prefs.setLastError(this, "")
                val result = try {
                    JSONObject(r.body).optString("result", "ok")
                } catch (e: Exception) {
                    "ok"
                }
                store.log("فرستاده شد (${p.sender}) → $result")
            } else {
                store.failed(p.id)
                Prefs.setLastError(this, "خطا ${r.code}: ${r.body.take(100)}")
                store.log("ناموفق (${p.sender}) → ${r.code} ${r.body.take(60)}")
                break   // no point hammering a server that is down
            }
        }
    }

    /**
     * Re-reads the inbox so messages that arrived while the app was off still
     * get through. Already-sent ones are rejected by the fingerprint.
     */
    fun scanInbox(hours: Int) {
        val store = Store(this)
        val since = System.currentTimeMillis() - hours * 3600_000L
        var added = 0
        try {
            contentResolver.query(
                Telephony.Sms.Inbox.CONTENT_URI,
                arrayOf(Telephony.Sms.ADDRESS, Telephony.Sms.BODY, Telephony.Sms.DATE),
                "${Telephony.Sms.DATE} > ?",
                arrayOf(since.toString()),
                "${Telephony.Sms.DATE} ASC"
            )?.use { c ->
                while (c.moveToNext()) {
                    val sender = c.getString(0) ?: ""
                    val body = c.getString(1) ?: ""
                    val date = c.getLong(2)
                    if (!SmsReceiver.looksLikeBank(body)) continue
                    if (store.enqueue(sender, body, stamp.format(Date(date)))) added++
                }
            }
            store.log("بازبینی $hours ساعت اخیر: $added پیامک به صف اضافه شد")
        } catch (e: Exception) {
            store.log("بازبینی ناموفق: ${e.message}")
        }
        if (added > 0) flushQueue(store)
    }

    private fun notifyChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val ch = NotificationChannel(CHANNEL, "Autopay", NotificationManager.IMPORTANCE_MIN)
            ch.setShowBadge(false)
            (getSystemService(NOTIFICATION_SERVICE) as NotificationManager).createNotificationChannel(ch)
        }
    }

    private fun buildNotice(text: String): Notification {
        val open = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val b = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O)
            Notification.Builder(this, CHANNEL) else @Suppress("DEPRECATION") Notification.Builder(this)
        return b.setContentTitle(getString(R.string.app_name))
            .setContentText(text)
            .setSmallIcon(android.R.drawable.stat_sys_upload_done)
            .setOngoing(true)
            .setContentIntent(open)
            .build()
    }

    private fun updateNotice(store: Store) {
        val n = store.count()
        val text = if (n == 0) "آماده - صف خالی" else "در صف: $n پیامک"
        (getSystemService(NOTIFICATION_SERVICE) as NotificationManager).notify(1, buildNotice(text))
    }
}
