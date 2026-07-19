import 'package:flutter/foundation.dart';

/// Compile-time debug flag: `flutter run --dart-define=APP_DEBUG=true`
const bool appDebug = bool.fromEnvironment('APP_DEBUG', defaultValue: false);

/// Route simulation is available only in debug builds or when APP_DEBUG is set.
bool get routeSimulationEnabled => kDebugMode || appDebug;

/// Source tag stored with simulated route points (same API pipeline as real GPS).
const String routeSimulationSource = 'debug_simulation';

/// Interval between simulated points.
const Duration routeSimulationInterval = Duration(seconds: 10);

/// Simulated movement per point (meters).
const double routeSimulationMinMeters = 30;
const double routeSimulationMaxMeters = 50;

/// Total simulated points per run.
const int routeSimulationPointCount = 18;
