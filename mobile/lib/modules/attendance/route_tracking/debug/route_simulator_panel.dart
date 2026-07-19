import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'route_simulator.dart';
import 'route_simulator_provider.dart';
import 'route_tracking_debug_config.dart';

class RouteSimulatorPanel extends ConsumerWidget {
  const RouteSimulatorPanel({super.key, required this.attendanceId});

  final int attendanceId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!routeSimulationEnabled) {
      return const SizedBox.shrink();
    }

    ref.listen(routeSimulatorProvider, (previous, next) {
      if (!context.mounted) return;
      if (previous?.status == RouteSimulationStatus.running &&
          (next.status == RouteSimulationStatus.completed ||
              next.status == RouteSimulationStatus.stopped ||
              next.status == RouteSimulationStatus.failed)) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(next.message ?? 'Simulation finished.')),
        );
      }
    });

    final simulation = ref.watch(routeSimulatorProvider);
    final isRunning = simulation.isRunning;

    return Card(
      color: Theme.of(context).colorScheme.surfaceContainerHighest,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Developer Tools',
              style: Theme.of(context).textTheme.titleSmall,
            ),
            const SizedBox(height: 8),
            if (isRunning) ...[
              LinearProgressIndicator(
                value: simulation.totalPoints == 0
                    ? null
                    : simulation.currentPoint / simulation.totalPoints,
              ),
              const SizedBox(height: 8),
              Text(
                simulation.message ??
                    'Point ${simulation.currentPoint} of ${simulation.totalPoints}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: () =>
                    ref.read(routeSimulatorProvider.notifier).stop(),
                icon: const Icon(Icons.stop_circle_outlined),
                label: const Text('Stop Simulation'),
              ),
            ] else
              OutlinedButton.icon(
                onPressed: () => _confirmAndStart(context, ref),
                icon: const Text('🧪'),
                label: const Text('Simulate Route'),
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _confirmAndStart(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Simulate Route?'),
        content: Text(
          'Generate $routeSimulationPointCount debug GPS points '
          '(every ${routeSimulationInterval.inSeconds}s, '
          '~${routeSimulationMinMeters.toInt()}–'
          '${routeSimulationMaxMeters.toInt()} m apart) '
          'using the same route tracking pipeline.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Start'),
          ),
        ],
      ),
    );

    if (confirmed != true || !context.mounted) return;

    try {
      await ref.read(routeSimulatorProvider.notifier).start(attendanceId);
    } catch (error) {
      if (context.mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('$error')));
      }
    }
  }
}
