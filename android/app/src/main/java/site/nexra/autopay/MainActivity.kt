package site.nexra.autopay

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class MainActivity : ComponentActivity() {

    private lateinit var status: TextView
    private lateinit var logView: TextView
    private lateinit var pairingInput: EditText
    private lateinit var senderInput: EditText

    private val needed = mutableListOf(
        Manifest.permission.RECEIVE_SMS,
        Manifest.permission.READ_SMS
    ).apply {
        if (Build.VERSION.SDK_INT >= 33) add(Manifest.permission.POST_NOTIFICATIONS)
    }.toTypedArray()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        status = findViewById(R.id.status)
        logView = findViewById(R.id.log)
        pairingInput = findViewById(R.id.pairing)
        senderInput = findViewById(R.id.senders)

        findViewById<Button>(R.id.btnPair).setOnClickListener { pair() }
        findViewById<Button>(R.id.btnTest).setOnClickListener { test() }
        findViewById<Button>(R.id.btnScan).setOnClickListener { scan() }
        findViewById<Button>(R.id.btnBattery).setOnClickListener { askBattery() }
        findViewById<Button>(R.id.btnSenders).setOnClickListener {
            Prefs.setSenderFilter(this, senderInput.text.toString())
            toast("ذخیره شد")
        }

        senderInput.setText(Prefs.senderFilter(this))
        ask()
        if (Prefs.paired(this)) ForwardService.start(this)
    }

    override fun onResume() {
        super.onResume()
        refresh()
    }

    private fun ask() {
        val missing = needed.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }
        if (missing.isNotEmpty()) ActivityCompat.requestPermissions(this, missing.toTypedArray(), 1)
    }

    override fun onRequestPermissionsResult(code: Int, perms: Array<out String>, res: IntArray) {
        super.onRequestPermissionsResult(code, perms, res)
        refresh()
    }

    private fun pair() {
        val err = Prefs.pair(this, pairingInput.text.toString())
        if (err != null) {
            toast(err)
            return
        }
        pairingInput.setText("")
        ForwardService.start(this)
        toast("متصل شد")
        Thread {
            val r = Api.heartbeat(this)
            runOnUiThread {
                toast(if (r.ok) "ارتباط با سرور برقرار است ✅" else "سرور جواب نداد: ${r.code} ${r.body.take(80)}")
                refresh()
            }
        }.start()
    }

    private fun test() {
        if (!Prefs.paired(this)) {
            toast("اول کلید را وارد کن")
            return
        }
        Thread {
            val r = Api.heartbeat(this)
            Store(this).log(if (r.ok) "تست ارتباط موفق" else "تست ارتباط ناموفق: ${r.code}")
            runOnUiThread {
                toast(if (r.ok) "ارتباط سالم است ✅" else "ناموفق: ${r.code} ${r.body.take(80)}")
                refresh()
            }
        }.start()
    }

    private fun scan() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_SMS)
            != PackageManager.PERMISSION_GRANTED
        ) {
            ask()
            return
        }
        startService(Intent(this, ForwardService::class.java).setAction(ForwardService.ACTION_SCAN))
        toast("در حال بازبینی پیامک‌های ۲۴ ساعت اخیر...")
    }

    /** Without this, the system eventually stops the forwarder on most phones. */
    private fun askBattery() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return
        val pm = getSystemService(POWER_SERVICE) as PowerManager
        if (pm.isIgnoringBatteryOptimizations(packageName)) {
            toast("از قبل اجازه دارد ✅")
            return
        }
        try {
            startActivity(
                Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS)
                    .setData(Uri.parse("package:$packageName"))
            )
        } catch (e: Exception) {
            startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
        }
    }

    private fun refresh() {
        val store = Store(this)
        val fmt = SimpleDateFormat("HH:mm:ss", Locale.US)
        val sync = Prefs.lastSync(this)
        val err = Prefs.lastError(this)
        val smsOk = ContextCompat.checkSelfPermission(this, Manifest.permission.RECEIVE_SMS) ==
                PackageManager.PERMISSION_GRANTED

        status.text = buildString {
            append(if (Prefs.paired(this@MainActivity)) "اتصال: متصل ✅\n" else "اتصال: وارد نشده ⛔️\n")
            append("دسترسی پیامک: ${if (smsOk) "دارد ✅" else "ندارد ⛔️"}\n")
            append("در صف: ${store.count()} پیامک\n")
            append("آخرین تماس موفق: ${if (sync == 0L) "—" else fmt.format(Date(sync))}\n")
            if (err.isNotEmpty()) append("آخرین خطا: $err\n")
        }

        logView.text = store.logs().joinToString("\n") { (at, line) ->
            "${fmt.format(Date(at))}  $line"
        }.ifEmpty { "هنوز رویدادی ثبت نشده." }
    }

    private fun toast(msg: String) = Toast.makeText(this, msg, Toast.LENGTH_LONG).show()
}
