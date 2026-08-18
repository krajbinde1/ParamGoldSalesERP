package com.example.mobile

import android.util.Log
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

/**
 * Reliable FCM data-message entry for critical alerts.
 *
 * FlutterFire still receives via [ParamGoldFcmReceiver] / its C2DM path for Dart.
 * This service ensures native CriticalAlertNotifier runs even when the parallel
 * C2DM broadcast is not delivered to our custom receiver.
 *
 * Does not change CriticalAlertActivity or FSI PendingIntent logic.
 */
class ParamGoldFirebaseMessagingService : FirebaseMessagingService() {
    override fun onMessageReceived(message: RemoteMessage) {
        val data = HashMap<String, String>(message.data)
        Log.i(LIVE_TAG, "FCM_RECEIVED")
        Log.i(LIVE_TAG, "fullscreen=${data["fullscreen"] ?: ""}")
        Log.i(LIVE_TAG, "type=${data["type"] ?: ""}")
        Log.i(LIVE_TAG, "order_id=${data["order_id"] ?: ""}")

        if (data.isEmpty()) {
            Log.i(LIVE_TAG, "data.empty=true")
            return
        }

        val type = data["type"]?.trim()?.lowercase().orEmpty()
        if (type == "session_replaced") return

        if (!CriticalAlertNotifier.isCritical(data)) {
            Log.i(LIVE_TAG, "SKIP_NOT_CRITICAL type=$type")
            return
        }

        // Prefer data title/body; fall back to notification block if present.
        val notification = message.notification
        if (data["title"].isNullOrBlank() && !notification?.title.isNullOrBlank()) {
            data["title"] = notification!!.title!!
        }
        if (data["body"].isNullOrBlank() && !notification?.body.isNullOrBlank()) {
            data["body"] = notification!!.body!!
        }

        Log.i(LIVE_TAG, "CRITICAL_NOTIFIER_CALLED")
        CriticalAlertNotifier.post(applicationContext, data)
    }

    companion object {
        private const val LIVE_TAG = "PARAMGOLD_LIVE_FCM"
    }
}
