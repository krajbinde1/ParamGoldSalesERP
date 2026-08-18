package com.example.mobile

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/**
 * High-priority C2DM receiver for critical ParamGold alerts.
 *
 * FlutterFire also listens on the same action and still delivers to Dart.
 * This receiver posts a native MAX/HIGH notification with a real
 * fullScreenIntent → [CriticalAlertActivity] without waiting for Flutter.
 *
 * Does not depend on firebase-messaging APIs (avoids classpath issues when
 * Firebase is only pulled in transitively by the Flutter plugin).
 */
class ParamGoldFcmReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        val extras = intent?.extras
        if (extras == null) {
            Log.i(TAG, "FCM_RECEIVED")
            Log.i(LIVE_TAG, "FCM_RECEIVED extras=null")
            Log.i(TAG, "fullscreen=")
            Log.i(TAG, "data.empty=true extras=null")
            return
        }

        val data = CriticalAlertNotifier.dataFromExtras(extras)

        // TEMP diagnostics — do not remove until live FCM chain is verified.
        Log.i(TAG, "FCM_RECEIVED")
        Log.i(LIVE_TAG, "FCM_RECEIVED")
        if (data.isEmpty()) {
            Log.i(TAG, "data.empty=true")
            Log.i(LIVE_TAG, "data.empty=true")
            Log.i(TAG, "fullscreen=")
            return
        }
        for ((key, value) in data) {
            Log.i(TAG, "data.$key=$value")
            Log.i(LIVE_TAG, "data.$key=$value")
        }
        Log.i(TAG, "fullscreen=${data["fullscreen"] ?: ""}")
        Log.i(
            LIVE_TAG,
            "fullscreen=${data["fullscreen"] ?: ""} order_id=${data["order_id"] ?: ""} type=${data["type"] ?: ""}",
        )

        val type = data["type"]?.trim()?.lowercase().orEmpty()
        if (type == "session_replaced") return
        if (!CriticalAlertNotifier.isCritical(data)) {
            Log.i(LIVE_TAG, "SKIP_NOT_CRITICAL type=$type")
            return
        }

        Log.i(LIVE_TAG, "CRITICAL_NOTIFIER_CALLED")
        CriticalAlertNotifier.post(
            context = context.applicationContext,
            data = data,
        )
    }

    companion object {
        private const val TAG = "PARAMGOLD_FSI"
        private const val LIVE_TAG = "PARAMGOLD_LIVE_FCM"
    }
}
