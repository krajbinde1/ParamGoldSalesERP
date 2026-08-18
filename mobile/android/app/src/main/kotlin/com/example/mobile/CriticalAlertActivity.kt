package com.example.mobile

import android.app.KeyguardManager
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.util.Log
import android.view.View
import android.view.WindowManager
import android.widget.Button
import android.widget.TextView
import androidx.activity.ComponentActivity

/**
 * Native incoming-call style full-screen approval alert.
 * Launched via Notification fullScreenIntent — must not wait for Flutter.
 */
class CriticalAlertActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // TEMP diagnostics — do not remove until FSI chain is verified on device.
        Log.i(TAG, "CRITICAL_ACTIVITY_OPENED")

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                    WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON or
                    WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON,
            )
        }

        val keyguard = getSystemService(Context.KEYGUARD_SERVICE) as KeyguardManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            keyguard.requestDismissKeyguard(this, null)
        }

        setContentView(R.layout.activity_critical_alert)

        val type = intent.getStringExtra(EXTRA_TYPE).orEmpty()
        val title = intent.getStringExtra(EXTRA_TITLE).orEmpty()
            .ifBlank { defaultTitle(type) }
        val reference = intent.getStringExtra(EXTRA_REFERENCE).orEmpty().ifBlank { "—" }
        val party = intent.getStringExtra(EXTRA_PARTY).orEmpty().ifBlank { "—" }
        val sales = intent.getStringExtra(EXTRA_SALES).orEmpty()
        val amount = intent.getStringExtra(EXTRA_AMOUNT).orEmpty().ifBlank { "—" }
        val stage = intent.getStringExtra(EXTRA_STAGE).orEmpty()
        val orderId = intent.getStringExtra(EXTRA_ORDER_ID).orEmpty()
        val route = intent.getStringExtra(EXTRA_ROUTE).orEmpty()
        val notificationId = intent.getIntExtra(EXTRA_NOTIFICATION_ID, 0)
        val payloadJson = intent.getStringExtra(EXTRA_PAYLOAD_JSON).orEmpty()

        findViewById<TextView>(R.id.alert_title).text = title
        findViewById<TextView>(R.id.alert_reference).text = reference
        findViewById<TextView>(R.id.alert_party).text = party

        val salesLabel = findViewById<TextView>(R.id.alert_sales_label)
        val salesValue = findViewById<TextView>(R.id.alert_sales)
        if (sales.isNotBlank() && !isPaymentType(type)) {
            salesLabel.visibility = View.VISIBLE
            salesValue.visibility = View.VISIBLE
            salesValue.text = sales
        } else {
            salesLabel.visibility = View.GONE
            salesValue.visibility = View.GONE
        }

        findViewById<TextView>(R.id.alert_amount).text = amount

        val stageLabel = findViewById<TextView>(R.id.alert_stage_label)
        val stageValue = findViewById<TextView>(R.id.alert_stage)
        if (stage.isNotBlank()) {
            stageLabel.visibility = View.VISIBLE
            stageValue.visibility = View.VISIBLE
            stageValue.text = stage
        }

        val primary = findViewById<Button>(R.id.btn_primary)
        val ignore = findViewById<Button>(R.id.btn_ignore)
        val reject = findViewById<Button>(R.id.btn_reject)

        when {
            isPaymentType(type) -> {
                primary.text = "VIEW"
                reject.visibility = View.GONE
            }
            type == "new_order" -> {
                primary.text = "REVIEW"
                reject.visibility = View.VISIBLE
            }
            type == "order_billed" -> {
                primary.text = "VIEW BILL"
                reject.visibility = View.GONE
            }
            else -> {
                primary.text = "REVIEW"
                reject.visibility = View.GONE
            }
        }

        primary.setOnClickListener {
            openFlutter(
                action = if (isPaymentType(type)) "view" else "review",
                type = type,
                orderId = orderId,
                route = route,
                payloadJson = payloadJson,
                notificationId = notificationId,
            )
        }
        ignore.setOnClickListener {
            cancelNotification(notificationId)
            finish()
        }
        reject.setOnClickListener {
            openFlutter(
                action = "reject",
                type = type,
                orderId = orderId,
                route = route,
                payloadJson = payloadJson,
                notificationId = notificationId,
            )
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        // TEMP diagnostics — do not remove until FSI chain is verified on device.
        Log.i(TAG, "CRITICAL_ACTIVITY_NEW_INTENT")
    }

    private fun openFlutter(
        action: String,
        type: String,
        orderId: String,
        route: String,
        payloadJson: String,
        notificationId: Int,
    ) {
        cancelNotification(notificationId)
        val launch = Intent(this, MainActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            putExtra(EXTRA_FROM_CRITICAL_ALERT, true)
            putExtra(EXTRA_ACTION, action)
            putExtra(EXTRA_TYPE, type)
            putExtra(EXTRA_ORDER_ID, orderId)
            putExtra(EXTRA_ROUTE, route)
            putExtra(EXTRA_PAYLOAD_JSON, payloadJson)
        }
        startActivity(launch)
        finish()
    }

    private fun cancelNotification(id: Int) {
        if (id <= 0) return
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        nm.cancel(id)
    }

    private fun defaultTitle(type: String): String = when (type) {
        "new_order" -> "New Order for Approval"
        "order_approved" -> "Order Approved"
        "order_billed" -> "Order Billed"
        "payment_request_reminder" -> "PAYMENT APPROVAL REMINDER"
        "payment_approval_required",
        "payment_request_created",
        "payment_request_first_approved",
        -> "PAYMENT APPROVAL REQUIRED"
        else -> "Critical Alert"
    }

    private fun isPaymentType(type: String): Boolean =
        type.startsWith("payment_")

    companion object {
        private const val TAG = "PARAMGOLD_FSI"

        const val EXTRA_FROM_CRITICAL_ALERT = "paramgold_from_critical_alert"
        const val EXTRA_ACTION = "paramgold_action"
        const val EXTRA_TYPE = "paramgold_type"
        const val EXTRA_TITLE = "paramgold_title"
        const val EXTRA_REFERENCE = "paramgold_reference"
        const val EXTRA_PARTY = "paramgold_party"
        const val EXTRA_SALES = "paramgold_sales"
        const val EXTRA_AMOUNT = "paramgold_amount"
        const val EXTRA_STAGE = "paramgold_stage"
        const val EXTRA_ORDER_ID = "paramgold_order_id"
        const val EXTRA_ROUTE = "paramgold_route"
        const val EXTRA_PAYLOAD_JSON = "paramgold_payload_json"
        const val EXTRA_NOTIFICATION_ID = "paramgold_notification_id"
    }
}
