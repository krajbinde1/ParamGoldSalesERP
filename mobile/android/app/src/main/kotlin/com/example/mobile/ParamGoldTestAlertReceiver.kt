package com.example.mobile

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/**
 * DEBUG-ONLY: adb-triggerable entry that calls the SAME production
 * [CriticalAlertNotifier] path used by FCM. Not registered in release.
 */
class ParamGoldTestAlertReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        val data = LinkedHashMap<String, String>()
        intent?.extras?.keySet()?.forEach { key ->
            if (key.isNullOrBlank()) return@forEach
            val value = intent.extras?.getString(key) ?: return@forEach
            data[key] = value
        }

        // Production-equivalent critical payload defaults for diagnostics.
        data.putIfAbsent("type", "new_order")
        data.putIfAbsent("fullscreen", "1")
        data.putIfAbsent("title", "ParamGold Test Alert")
        data.putIfAbsent("body", "Full-screen notification diagnostic test")
        data.putIfAbsent("order_id", "TEST123")
        data.putIfAbsent("short_order_no", "PG-TEST")
        data.putIfAbsent("dealer_name", "Diagnostic Dealer")
        data.putIfAbsent("sales_person_name", "Diagnostic Sales")
        data.putIfAbsent("amount", "27615")

        Log.i(TAG, "DEBUG_TRIGGER_RECEIVED")
        Log.i(TAG, "fullscreen=${data["fullscreen"]}")
        for ((key, value) in data) {
            Log.i(TAG, "data.$key=$value")
        }

        // Force background path so FSI is exercised even if UI is open.
        val wasFg = ParamGoldAppState.isInForeground
        ParamGoldAppState.isInForeground = false
        try {
            CriticalAlertNotifier.post(context.applicationContext, data)
        } finally {
            ParamGoldAppState.isInForeground = wasFg
        }
    }

    companion object {
        private const val TAG = "PARAMGOLD_FSI"
        const val ACTION = "com.example.mobile.TEST_CRITICAL_ALERT"
    }
}
