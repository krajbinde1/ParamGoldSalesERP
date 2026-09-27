/// Kill-switch for route tracking runtime.
const bool routeTrackingRuntimeEnabled = true;

/// Minimum time between route point captures for slow movement.
const Duration routeCaptureInterval = Duration(seconds: 60);

/// Minimum movement before capturing a new point (meters).
const double routeMovementThresholdMeters = 25.0;

/// Treat this radius as stationary GPS jitter — do not save a new point.
const double routeStationaryRadiusMeters = 15.0;

/// Maximum acceptable GPS accuracy (meters).
const double routeMaxAccuracyMeters = 80.0;

/// Reject duplicate coordinates recorded within this window.
const Duration routeDuplicateCoordWindow = Duration(seconds: 20);

/// Source tag sent with each route point.
const String routeTrackingSource = 'foreground_location';

const String routeTrackingNotificationTitle =
    'ParamGold route tracking is active';
const String routeTrackingNotificationText =
    'ParamGold route tracking is active';
const String routeTrackingNotificationChannelName = 'ParamGold Route Tracking';
const String routeTrackingNotificationChannelId = 'paramgold_route_tracking';

/// Foreground task tick: session health + batched sync only (not GPS polling).
const int routeForegroundTaskIntervalMs = 30000;

const int routeForegroundServiceId = 2567;

/// Minimum gap between successful sync attempts.
const Duration routeSyncMinInterval = Duration(seconds: 30);

/// Backoff after a failed sync.
const Duration routeSyncFailureBackoffMin = Duration(seconds: 45);

const Duration routeSyncFailureBackoffMax = Duration(minutes: 5);

const int routeSyncBatchSize = 100;

/// Soft cap on the local pending queue. Older near-duplicates are compacted.
const int routePendingQueueSoftCap = 2500;
