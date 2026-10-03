package site.nexra.autopay

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Brings the forwarder back after a reboot or an app update. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(ctx: Context, intent: Intent) {
        if (!Prefs.paired(ctx)) return
        ForwardService.start(ctx)
        // anything that arrived while the phone was off is still in the inbox
        ctx.startService(Intent(ctx, ForwardService::class.java).setAction(ForwardService.ACTION_SCAN))
    }
}
