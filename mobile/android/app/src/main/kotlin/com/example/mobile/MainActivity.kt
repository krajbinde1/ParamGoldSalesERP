package com.example.mobile

import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.util.Log
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterFragmentActivity() {
    private val channelName = "paramgold/critical_alerts"

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        ParamGoldAppState.isInForeground = true
        handleCriticalIntent(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleCriticalIntent(intent)
    }

    override fun onResume() {
        super.onResume()
        ParamGoldAppState.isInForeground = true
    }

    override fun onPause() {
        ParamGoldAppState.isInForeground = false
        super.onPause()
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, channelName)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "canUseFullScreenIntent" -> {
                        val allowed = canUseFullScreenIntent()
                        Log.i(TAG, "CAN_USE_FULL_SCREEN_INTENT=$allowed")
                        result.success(allowed)
                    }
                    "openFullScreenIntentSettings" -> {
                        openFullScreenIntentSettings()
                        result.success(true)
                    }
                    "getCriticalChannelInfo" -> {
                        result.success(
                            mapOf(
                                "channelId" to CriticalAlertNotifier.CHANNEL_ID,
                                "importance" to channelImportance(),
                                "canUseFullScreenIntent" to canUseFullScreenIntent(),
                                "targetSdk" to applicationContext.applicationInfo.targetSdkVersion,
                                "sdkInt" to Build.VERSION.SDK_INT,
                            ),
                        )
                    }
                    "consumeNativeCriticalLaunch" -> {
                        val data = pendingCriticalLaunch
                        pendingCriticalLaunch = null
                        result.success(data)
                    }
                    else -> result.notImplemented()
                }
            }
    }

    private fun handleCriticalIntent(intent: Intent?) {
        if (intent == null) return
        if (!intent.getBooleanExtra(CriticalAlertActivity.EXTRA_FROM_CRITICAL_ALERT, false)) {
            return
        }
        pendingCriticalLaunch = mapOf(
            "action" to (intent.getStringExtra(CriticalAlertActivity.EXTRA_ACTION) ?: ""),
            "type" to (intent.getStringExtra(CriticalAlertActivity.EXTRA_TYPE) ?: ""),
            "order_id" to (intent.getStringExtra(CriticalAlertActivity.EXTRA_ORDER_ID) ?: ""),
            "route" to (intent.getStringExtra(CriticalAlertActivity.EXTRA_ROUTE) ?: ""),
            "payload_json" to (intent.getStringExtra(CriticalAlertActivity.EXTRA_PAYLOAD_JSON) ?: ""),
        )
        Log.i(TAG, "NATIVE_CRITICAL_LAUNCH_QUEUED action=${pendingCriticalLaunch?.get("action")}")
    }

    private fun canUseFullScreenIntent(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.UPSIDE_DOWN_CAKE) return true
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        return nm.canUseFullScreenIntent()
    }

    private fun openFullScreenIntentSettings() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.UPSIDE_DOWN_CAKE) return
        try {
            val intent = Intent(
                Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT,
                Uri.parse("package:$packageName"),
            )
            startActivity(intent)
        } catch (_: Exception) {
            val fallback = Intent(
                Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                Uri.parse("package:$packageName"),
            )
            startActivity(fallback)
        }
    }

    private fun channelImportance(): Int {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return -1
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        return nm.getNotificationChannel(CriticalAlertNotifier.CHANNEL_ID)?.importance ?: -1
    }

    companion object {
        private const val TAG = "PARAMGOLD_FSI"
        @Volatile
        var pendingCriticalLaunch: Map<String, String>? = null
    }
}
