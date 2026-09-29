<?php

namespace App\Support;

/**
 * Thresholds for the today-only live map. They sit above the route jitter
 * filters so a wobbling GPS fix is not treated as travel.
 */
final class LiveTracking
{
    public const POLL_SECONDS = 12;

    /** Last point newer than this is live. */
    public const DELAYED_AFTER_MINUTES = 5;

    /** Last point older than this is offline. The marker stays on the map. */
    public const OFFLINE_AFTER_MINUTES = 15;

    /** Window used to decide moving versus stopped. */
    public const MOVING_WINDOW_MINUTES = 8;

    /**
     * Matches the mobile capture threshold. Movement inside the stationary
     * radius is GPS jitter and stays Stopped.
     */
    public const MOVING_METERS = 25;

    public const STATUS_MOVING = 'moving';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_DELAYED = 'delayed';

    public const STATUS_OFFLINE = 'offline';
}
