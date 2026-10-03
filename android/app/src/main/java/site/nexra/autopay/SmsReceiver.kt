package site.nexra.autopay

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
import java.util.Date

class SmsReceiver : BroadcastReceiver() {

    companion object {
        private val MONEY_WORDS = listOf(
            "واریز", "بستانکار", "برداشت", "بدهکار", "مانده", "موجودی",
            "ریال", "تومان", "انتقال", "حساب", "کارت"
        )

        /**
         * Keeps the phone's private messages on the phone: only something that
         * reads like a bank notification is ever queued, and only after the
         * filter the admin set, when they set one.
         */
        fun looksLikeBank(body: String): Boolean {
            if (body.isBlank()) return false
            val hits = MONEY_WORDS.count { body.contains(it) }
            val hasNumber = Regex("[0-9۰-۹]{3,}").containsMatchIn(body)
            return hits >= 2 && hasNumber
        }

        fun senderAllowed(ctx: Context, sender: String): Boolean {
            val filter = Prefs.senderFilter(ctx).trim()
            if (filter.isEmpty()) return true
            return filter.split(",", "،", " ")
                .map { it.trim() }
                .filter { it.isNotEmpty() }
                .any { sender.contains(it, ignoreCase = true) }
        }
    }

    override fun onReceive(ctx: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) return
        if (!Prefs.paired(ctx)) return

        // a long SMS arrives in parts; they belong to one message
        val parts = Telephony.Sms.Intents.getMessagesFromIntent(intent) ?: return
        if (parts.isEmpty()) return
        val sender = parts[0].originatingAddress ?: ""
        val body = parts.joinToString("") { it.messageBody ?: "" }
        val at = ForwardService.stamp.format(Date(parts[0].timestampMillis))

        if (!senderAllowed(ctx, sender)) return
        if (!looksLikeBank(body)) return

        val store = Store(ctx)
        if (store.enqueue(sender, body, at)) {
            store.log("پیامک تازه از $sender")
        }
        ForwardService.flush(ctx)
    }
}
