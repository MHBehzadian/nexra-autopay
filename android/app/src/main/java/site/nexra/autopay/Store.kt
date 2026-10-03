package site.nexra.autopay

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

data class Pending(
    val id: Long,
    val sender: String,
    val body: String,
    val sentAt: String,
    val tries: Int
)

/**
 * The outbox. A message is deleted only after the server has acknowledged it,
 * so a dead connection, a reboot or a killed process cannot lose a deposit.
 */
class Store(ctx: Context) : SQLiteOpenHelper(ctx, "autopay.db", null, 1) {

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            """CREATE TABLE queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender TEXT, body TEXT, sent_at TEXT,
                fingerprint TEXT UNIQUE, tries INTEGER DEFAULT 0, created INTEGER)"""
        )
        db.execSQL(
            """CREATE TABLE log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                at INTEGER, line TEXT)"""
        )
    }

    override fun onUpgrade(db: SQLiteDatabase, old: Int, new: Int) {}

    /** Returns false when this exact message is already queued or was handled. */
    fun enqueue(sender: String, body: String, sentAt: String): Boolean {
        val v = ContentValues().apply {
            put("sender", sender)
            put("body", body)
            put("sent_at", sentAt)
            put("fingerprint", "$sender|$body|$sentAt".hashCode().toString() + "|" + body.length)
            put("tries", 0)
            put("created", System.currentTimeMillis())
        }
        val id = writableDatabase.insertWithOnConflict("queue", null, v, SQLiteDatabase.CONFLICT_IGNORE)
        return id != -1L
    }

    fun pending(limit: Int = 25): List<Pending> {
        val out = ArrayList<Pending>()
        readableDatabase.rawQuery(
            "SELECT id, sender, body, sent_at, tries FROM queue ORDER BY id ASC LIMIT ?",
            arrayOf(limit.toString())
        ).use { c ->
            while (c.moveToNext()) {
                out.add(Pending(c.getLong(0), c.getString(1), c.getString(2), c.getString(3), c.getInt(4)))
            }
        }
        return out
    }

    fun count(): Int {
        readableDatabase.rawQuery("SELECT COUNT(*) FROM queue", null).use { c ->
            return if (c.moveToFirst()) c.getInt(0) else 0
        }
    }

    fun done(id: Long) {
        writableDatabase.delete("queue", "id = ?", arrayOf(id.toString()))
    }

    fun failed(id: Long) {
        writableDatabase.execSQL("UPDATE queue SET tries = tries + 1 WHERE id = ?", arrayOf(id.toString()))
    }

    fun log(line: String) {
        val v = ContentValues().apply {
            put("at", System.currentTimeMillis())
            put("line", line)
        }
        writableDatabase.insert("log", null, v)
        writableDatabase.execSQL("DELETE FROM log WHERE id NOT IN (SELECT id FROM log ORDER BY id DESC LIMIT 100)")
    }

    fun logs(limit: Int = 30): List<Pair<Long, String>> {
        val out = ArrayList<Pair<Long, String>>()
        readableDatabase.rawQuery("SELECT at, line FROM log ORDER BY id DESC LIMIT ?", arrayOf(limit.toString()))
            .use { c ->
                while (c.moveToNext()) out.add(Pair(c.getLong(0), c.getString(1)))
            }
        return out
    }
}
