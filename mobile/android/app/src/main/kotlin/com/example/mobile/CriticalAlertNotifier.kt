package com.example.mobile

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.os.Build
import android.os.Bundle
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import java.util.Locale

/**
 * Posts the native MAX/HIGH critical notification with a real fullScreenIntent
 * targeting [CriticalAlertActivity]. Invoked from [ParamGoldFcmReceiver].
 */
object CriticalAlertNotifier {
    private const val TAG = "PARAMGOLD_FSI"
    const val CHANNEL_ID = "paramgold_critical_alerts_v5"

    fun isCritical(data: Map<String, String>): Boolean {
        if (data["fullscreen"] == "1") return true
        return isCriticalType(data["type"]?.trim()?.lowercase().orEmpty())
    }

    fun isCriticalType(type: String): Boolean =
        type == "new_order" ||
            type == "order_approved" ||
            type.startsWith("order_approved") ||
            type == "order_billed" ||
            type == "payment_approval_required" ||
            type == "payment_request_reminder" ||
            type == "payment_request_created" ||
            type == "payment_request_first_approved" ||
            type == "diagnostic_test"

    fun post(context: Context, data: Map<String, String>, titleHint: String? = null, bodyHint: String? = null) {
        ensureChannel(context)

        val type = data["type"]?.trim()?.lowercase().orEmpty()
        val notificationId = resolveNotificationId(data)
        val title = data["title"].orEmpty().ifBlank { titleHint.orEmpty() }.ifBlank { defaultTitle(type) }
        val body = data["body"].orEmpty().ifBlank { bodyHint.orEmpty() }

        // TEMP diagnostics — identify where the FSI chain stops.
        Log.i(TAG, "BUILDING_CRITICAL_NOTIFICATION")
        Log.i(TAG, "CHANNEL_ID=$CHANNEL_ID")
        Log.i(TAG, "fullscreen=${data["fullscreen"] ?: ""}")
        val canUseFsi = canUseFullScreenIntent(context)
        Log.i(TAG, "CAN_USE_FULL_SCREEN_INTENT=$canUseFsi")

        if (ParamGoldAppState.isInForeground) {
            Log.i(TAG, "SKIP_NATIVE_FSI app_foreground=true")
            return
        }

        val party = buildPartyLine(data)
        val payloadJson = org.json.JSONObject(HashMap<String, Any>(data)).toString()

        val alertIntent = Intent(context, CriticalAlertActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            putExtra(CriticalAlertActivity.EXTRA_TYPE, type)
            putExtra(CriticalAlertActivity.EXTRA_TITLE, title)
            putExtra(
                CriticalAlertActivity.EXTRA_REFERENCE,
                data["short_order_no"]
                    ?: data["order_no"]
                    ?: data["request_no"]
                    ?: data["pending_count"]
                    ?: "",
            )
            putExtra(CriticalAlertActivity.EXTRA_PARTY, party)
            putExtra(CriticalAlertActivity.EXTRA_SALES, data["sales_person_name"] ?: "")
            putExtra(
                CriticalAlertActivity.EXTRA_AMOUNT,
                formatAmount(data["amount"] ?: data["pending_amount"] ?: data["grand_total"]),
            )
            putExtra(
                CriticalAlertActivity.EXTRA_STAGE,
                data["approval_stage"] ?: data["status_label"] ?: data["status"] ?: "",
            )
            putExtra(CriticalAlertActivity.EXTRA_ORDER_ID, data["order_id"] ?: "")
            putExtra(CriticalAlertActivity.EXTRA_ROUTE, data["route"] ?: "")
            putExtra(CriticalAlertActivity.EXTRA_PAYLOAD_JSON, payloadJson)
            putExtra(CriticalAlertActivity.EXTRA_NOTIFICATION_ID, notificationId)
        }

        val fullScreenPending = PendingIntent.getActivity(
            context,
            notificationId,
            alertIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val contentPending = PendingIntent.getActivity(
            context,
            notificationId + 10_000,
            alertIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val builder = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(title)
            .setContentText(body.ifBlank { "Open to review" })
            .setStyle(NotificationCompat.BigTextStyle().bigText(body.ifBlank { title }))
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .setAutoCancel(true)
            .setContentIntent(contentPending)
            .setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE))
            .setVibrate(longArrayOf(0, 800, 400, 800, 400, 800))

        Log.i(TAG, "SETTING_FULL_SCREEN_INTENT")
        builder.setFullScreenIntent(fullScreenPending, true)

        try {
            NotificationManagerCompat.from(context).notify(notificationId, builder.build())
            Log.i(TAG, "CRITICAL_NOTIFICATION_POSTED")
        } catch (error: SecurityException) {
            Log.e(TAG, "CRITICAL_NOTIFICATION_POSTED=false error=$error")
        }
    }

    fun dataFromExtras(extras: Bundle?): Map<String, String> {
        if (extras == null) return emptyMap()
        val out = LinkedHashMap<String, String>()
        for (key in extras.keySet()) {
            if (key.isNullOrBlank()) continue
            // Skip FCM/framework metadata; keep app data keys only.
            if (key.startsWith("google.") ||
                key.startsWith("gcm.") ||
                key == "from" ||
                key == "collapse_key" ||
                key == "message_type"
            ) {
                continue
            }
            val value = extras.getString(key) ?: continue
            out[key] = value
        }
        return out
    }

    fun ensureChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val existing = nm.getNotificationChannel(CHANNEL_ID)
        // Android may cap third-party channels below IMPORTANCE_MAX (stores as HIGH=4).
        // Only recreate if the channel is too weak for heads-up / FSI (< HIGH).
        if (existing != null) {
            if (existing.importance >= NotificationManager.IMPORTANCE_HIGH) {
                Log.i(TAG, "CHANNEL_OK id=$CHANNEL_ID importance=${existing.importance}")
                return
            }
            Log.i(
                TAG,
                "CHANNEL_RECREATE id=$CHANNEL_ID oldImportance=${existing.importance}",
            )
            nm.deleteNotificationChannel(CHANNEL_ID)
        }

        val attrs = AudioAttributes.Builder()
            .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
            .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
            .build()
        val channel = NotificationChannel(
            CHANNEL_ID,
            "ParamGold Critical Alerts",
            NotificationManager.IMPORTANCE_MAX,
        ).apply {
            description = "Full-screen order and payment approval alerts"
            enableVibration(true)
            vibrationPattern = longArrayOf(0, 800, 400, 800, 400, 800)
            setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE), attrs)
            lockscreenVisibility = android.app.Notification.VISIBILITY_PUBLIC
            setBypassDnd(true)
        }
        nm.createNotificationChannel(channel)
        Log.i(TAG, "CHANNEL_CREATED=$CHANNEL_ID")
    }

    fun canUseFullScreenIntent(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.UPSIDE_DOWN_CAKE) return true
        val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        return nm.canUseFullScreenIntent()
    }

    fun channelImportance(context: Context): Int {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return -1
        val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        return nm.getNotificationChannel(CHANNEL_ID)?.importance ?: -1
    }

    private fun buildPartyLine(data: Map<String, String>): String {
        val name = (data["dealer_name"] ?: data["vendor_name"] ?: "").trim()
        val place = (data["dealer_village"] ?: data["dealer_place"] ?: data["village"] ?: "").trim()
        return when {
            name.isNotEmpty() && place.isNotEmpty() -> "$name\n$place"
            name.isNotEmpty() -> name
            place.isNotEmpty() -> place
            else -> ""
        }
    }

    private fun resolveNotificationId(data: Map<String, String>): Int {
        data["order_id"]?.toIntOrNull()?.let { return it }
        data["payment_request_id"]?.toIntOrNull()?.let { return 700_000 + it }
        return (data["type"].orEmpty() + data["title"].orEmpty()).hashCode().and(0x7fffffff) % 100_000
    }

    private fun formatAmount(raw: String?): String {
        val value = raw?.trim().orEmpty()
        if (value.isEmpty()) return "—"
        val number = value.replace(",", "").toDoubleOrNull() ?: return value
        return "₹" + String.format(Locale.US, "%,.0f", number)
    }

    private fun defaultTitle(type: String): String = when (type) {
        "new_order" -> "New Order for Approval"
        "order_approved" -> "Order Approved"
        "order_billed" -> "Order Billed"
        "payment_request_reminder" -> "PAYMENT APPROVAL REMINDER"
        else -> "PAYMENT APPROVAL REQUIRED"
    }
}
