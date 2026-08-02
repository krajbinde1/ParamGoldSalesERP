/// Kill-switch for route tracking runtime.
const bool routeTrackingRuntimeEnabled = true;

/// Minimum time between route point captures.
const Duration routeCaptureInterval = Duration(seconds: 15);

/// Minimum movement before capturing a new point (meters).
const double routeMovementThresholdMeters = 25.0;

/// Maximum acceptable GPS accuracy (meters).
const double routeMaxAccuracyMeters = 100.0;

/// Reject duplicate coordinates recorded within this window.
const Duration routeDuplicateCoordWindow = Duration(seconds: 8);

/// Source tag sent with each route point.
const String routeTrackingSource = 'foreground_location';

const String routeTrackingNotificationTitle =
    'ParamGold route tracking is active';
const String routeTrackingNotificationText =
    'ParamGold route tracking is active';
const String routeTrackingNotificationChannelName = 'ParamGold Route Tracking';
const String routeTrackingNotificationChannelId = 'paramgold_route_tracking';

/// Foreground task polling interval.
const int routeForegroundTaskIntervalMs = 15000;

const int routeForegroundServiceId = 2567;
