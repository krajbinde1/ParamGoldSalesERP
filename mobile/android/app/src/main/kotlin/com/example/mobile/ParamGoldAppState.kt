package com.example.mobile

/**
 * Tracks whether the Flutter UI is in the foreground so native FCM can
 * avoid stacking a full-screen Activity on top of an already-open alert.
 */
object ParamGoldAppState {
    @Volatile
    var isInForeground: Boolean = false
}
