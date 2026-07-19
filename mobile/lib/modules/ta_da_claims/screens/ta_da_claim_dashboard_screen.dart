import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/ta_da_claim_api.dart';
import '../models/ta_da_claim_calendar_data.dart';
import '../models/ta_da_claim_dashboard_data.dart';
import '../widgets/ta_da_claim_calendar.dart';
import '../widgets/ta_da_claim_widgets.dart';

class TaDaClaimDashboardScreen extends StatefulWidget {
  const TaDaClaimDashboardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<TaDaClaimDashboardScreen> createState() =>
      _TaDaClaimDashboardScreenState();
}

class _TaDaClaimDashboardScreenState extends State<TaDaClaimDashboardScreen> {
  late Future<TaDaClaimDashboardData> _future;
  late int _calendarMonth;
  late int _calendarYear;
  Future<TaDaClaimCalendarData>? _calendarFuture;

  TaDaClaimApi get _api => TaDaClaimApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _calendarMonth = now.month;
    _calendarYear = now.year;
    _future = _loadDashboard();
    _calendarFuture = _loadCalendar();
  }

  Future<TaDaClaimDashboardData> _loadDashboard() => _api.loadDashboard();

  Future<TaDaClaimCalendarData> _loadCalendar() =>
      _api.loadCalendar(month: _calendarMonth, year: _calendarYear);

  Future<void> _reload() async {
    setState(() {
      _future = _loadDashboard();
      _calendarFuture = _loadCalendar();
    });
    await Future.wait([_future, _calendarFuture!]);
  }

  void _goToPreviousMonth() {
    final date = DateTime(_calendarYear, _calendarMonth - 1, 1);
    setState(() {
      _calendarMonth = date.month;
      _calendarYear = date.year;
      _calendarFuture = _loadCalendar();
    });
  }

  void _goToNextMonth() {
    if (!_canGoNextMonth) return;
    final date = DateTime(_calendarYear, _calendarMonth + 1, 1);
    setState(() {
      _calendarMonth = date.month;
      _calendarYear = date.year;
      _calendarFuture = _loadCalendar();
    });
  }

  bool get _canGoNextMonth {
    final nextMonth = DateTime(_calendarYear, _calendarMonth + 1, 1);
    final todayMonth = DateTime(
      TaDaClaimCalendar.today.year,
      TaDaClaimCalendar.today.month,
      1,
    );
    return !nextMonth.isAfter(todayMonth);
  }

  Future<void> _openClaim(int claimId) async {
    await context.push('/ta-da-claims/$claimId');
    if (!mounted) return;
    await _reload();
  }

  Future<void> _openNewClaim([DateTime? claimDate]) async {
    final result = await context.push<bool>(
      '/ta-da-claims/new',
      extra: claimDate,
    );
    if (!mounted) return;
    if (result == true) await _reload();
  }

  void _onCalendarDateTap(DateTime date, TaDaClaimCalendarEntry? claim) {
    if (claim != null) {
      _openClaim(claim.id);
      return;
    }

    if (date.isAfter(TaDaClaimCalendar.today)) return;
    _openNewClaim(date);
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 0,
    );

    return PgPageScaffold(
      auth: widget.auth,
      title: 'TA/DA Claim',
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _openNewClaim(),
        icon: const Icon(Icons.add_rounded),
        label: const Text('New Claim'),
      ),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<TaDaClaimDashboardData>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting &&
                !snapshot.hasData) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: const [PgLoadingState()],
              );
            }

            if (snapshot.hasError) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgErrorState(
                    message: 'Unable to load TA/DA claims.',
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final data = snapshot.data ?? TaDaClaimDashboardData.empty;
            final summary = data.summary;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                LayoutBuilder(
                  builder: (context, constraints) => GridView.count(
                    crossAxisCount: 2,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    childAspectRatio: constraints.maxWidth >= 700 ? 1.8 : 1.35,
                    mainAxisSpacing: AppSpacing.sm,
                    crossAxisSpacing: AppSpacing.sm,
                    children: [
                      TaDaClaimSummaryCard(
                        label: 'Total Claims',
                        value: '${summary.totalClaims}',
                        color: AppColors.primary,
                      ),
                      TaDaClaimSummaryCard(
                        label: 'This Month Claims',
                        value: '${summary.monthClaims}',
                        color: AppColors.info,
                      ),
                      TaDaClaimSummaryCard(
                        label: 'Pending Claims',
                        value: '${summary.pendingClaims}',
                        color: AppColors.pendingFg,
                      ),
                      TaDaClaimSummaryCard(
                        label: 'Approved Claims',
                        value: '${summary.approvedClaims}',
                        color: AppColors.approvedFg,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                FutureBuilder<TaDaClaimCalendarData>(
                  future: _calendarFuture,
                  builder: (context, calendarSnapshot) {
                    final calendarData = calendarSnapshot.data;
                    return TaDaClaimCalendar(
                      month: _calendarMonth,
                      year: _calendarYear,
                      claimsByDate: calendarData?.claimsByDate ?? const {},
                      loading:
                          calendarSnapshot.connectionState ==
                          ConnectionState.waiting,
                      onPreviousMonth: _goToPreviousMonth,
                      onNextMonth: _canGoNextMonth ? _goToNextMonth : null,
                      canGoNextMonth: _canGoNextMonth,
                      onDateTap: _onCalendarDateTap,
                    );
                  },
                ),
                const SizedBox(height: AppSpacing.lg),
                const PgSectionHeader(title: 'Recent Claims'),
                if (data.recentClaims.isEmpty)
                  const PgEmptyState(
                    message: 'No TA/DA claims submitted yet.',
                    icon: Icons.receipt_long_outlined,
                  )
                else
                  ...data.recentClaims.map(
                    (claim) => RecentTaDaClaimTile(
                      claim: claim,
                      currency: currency,
                      onTap: claim.id == 0 ? null : () => _openClaim(claim.id),
                    ),
                  ),
                const SizedBox(height: 80),
              ],
            );
          },
        ),
      ),
    );
  }
}
