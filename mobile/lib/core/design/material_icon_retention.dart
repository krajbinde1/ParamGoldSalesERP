import 'package:flutter/material.dart';

/// Const icon widgets — retention signal for Flutter 3.44 ConstFinder.
///
/// Flutter 3.44 release tree-shaking only keeps glyphs that appear as
/// **const** [Icon] / const [IconData] inputs. Shared widgets that used to
/// take an [IconData] field (`Icon(icon)`) dropped those glyphs, which made
/// dashboard and bottom-nav icons render as empty squares in release APKs.
///
/// Call sites now pass `const Icon(Icons.xxx)`. This offstage list is an
/// extra safety net for any remaining dynamic [IconData] usages.
const List<Widget> kRetainedMaterialIconWidgets = [
  Icon(Icons.dashboard_rounded),
  Icon(Icons.receipt_long_rounded),
  Icon(Icons.route_rounded),
  Icon(Icons.payments_rounded),
  Icon(Icons.person_rounded),
  Icon(Icons.fingerprint_rounded),
  Icon(Icons.trending_up_rounded),
  Icon(Icons.pending_actions_rounded),
  Icon(Icons.storefront_rounded),
  Icon(Icons.flag_rounded),
  Icon(Icons.flag_outlined),
  Icon(Icons.travel_explore_rounded),
  Icon(Icons.travel_explore_outlined),
  Icon(Icons.agriculture_rounded),
  Icon(Icons.shopping_cart_outlined),
  Icon(Icons.storefront_outlined),
  Icon(Icons.route_outlined),
  Icon(Icons.receipt_long_outlined),
  Icon(Icons.payments_outlined),
  Icon(Icons.calendar_today_rounded),
  Icon(Icons.construction_rounded),
  Icon(Icons.diamond_outlined),
  Icon(Icons.phone_android_rounded),
  Icon(Icons.lock_outline_rounded),
  Icon(Icons.lock_rounded),
  Icon(Icons.visibility_rounded),
  Icon(Icons.visibility_off_rounded),
  Icon(Icons.login_rounded),
  Icon(Icons.logout_rounded),
  Icon(Icons.password_outlined),
  Icon(Icons.password_rounded),
  Icon(Icons.arrow_back_rounded),
  Icon(Icons.search_rounded),
  Icon(Icons.close_rounded),
  Icon(Icons.cloud_off_rounded),
  Icon(Icons.inbox_rounded),
  Icon(Icons.check),
  Icon(Icons.chevron_right_rounded),
  Icon(Icons.chevron_left_rounded),
  Icon(Icons.calendar_month_rounded),
  Icon(Icons.history_rounded),
  Icon(Icons.add_rounded),
  Icon(Icons.people_outline),
  Icon(Icons.shopping_cart_checkout_outlined),
  Icon(Icons.analytics_outlined),
  Icon(Icons.inventory_2_outlined),
  Icon(Icons.inventory_2),
  Icon(Icons.local_shipping_outlined),
  Icon(Icons.local_shipping),
  Icon(Icons.hub_outlined),
  Icon(Icons.verified_outlined),
  Icon(Icons.document_scanner_outlined),
  Icon(Icons.bar_chart_outlined),
  Icon(Icons.tune_outlined),
  Icon(Icons.warning_amber_outlined),
  Icon(Icons.today_outlined),
  Icon(Icons.calendar_month_outlined),
  Icon(Icons.list_alt_rounded),
  Icon(Icons.today_rounded),
  Icon(Icons.photo_camera_outlined),
  Icon(Icons.map_outlined),
  Icon(Icons.event_busy_rounded),
  Icon(Icons.verified_rounded),
  Icon(Icons.schedule_rounded),
  Icon(Icons.broken_image),
  Icon(Icons.search),
  Icon(Icons.add),
  Icon(Icons.edit_outlined),
  Icon(Icons.delete_outline),
  Icon(Icons.person_outline),
  Icon(Icons.logout),
  Icon(Icons.history),
  Icon(Icons.add_circle_outline),
  Icon(Icons.check_circle_outline),
  Icon(Icons.picture_as_pdf_outlined),
  Icon(Icons.filter_alt),
  Icon(Icons.share_outlined),
  Icon(Icons.download),
  Icon(Icons.print),
  Icon(Icons.fact_check_outlined),
  Icon(Icons.insights_outlined),
  Icon(Icons.badge_outlined),
  Icon(Icons.groups_outlined),
  Icon(Icons.account_balance_outlined),
  Icon(Icons.account_balance_wallet_outlined),
  Icon(Icons.trending_up_outlined),
  Icon(Icons.fingerprint_outlined),
  Icon(Icons.notifications_none_outlined),
  Icon(Icons.assessment_outlined),
  Icon(Icons.error_outline),
  Icon(Icons.local_shipping_rounded),
  Icon(Icons.cancel_outlined),
  Icon(Icons.pending_actions_rounded),
  Icon(Icons.bug_report_outlined),
  Icon(Icons.location_off_outlined),
  Icon(Icons.no_photography_outlined),
  Icon(Icons.photo_library_outlined),
  Icon(Icons.add_a_photo_outlined),
  Icon(Icons.calendar_today_outlined),
  Icon(Icons.location_on_outlined),
  Icon(Icons.remove_circle_outline),
  Icon(Icons.clear),
  Icon(Icons.warning_amber_rounded),
  Icon(Icons.camera_alt_outlined),
  Icon(Icons.fullscreen),
  Icon(Icons.close_fullscreen),
  Icon(Icons.arrow_back),
  Icon(Icons.stop_circle_outlined),
  Icon(Icons.chevron_left),
  Icon(Icons.chevron_right),
];

/// Ensures retained icon constants stay live in release AOT builds.
@pragma('vm:entry-point')
int retainMaterialIconGlyphs() => kRetainedMaterialIconWidgets.length;

/// Offstage mount so const [Icon] widgets remain in the element/build graph.
class MaterialIconRetention extends StatelessWidget {
  const MaterialIconRetention({super.key});

  @override
  Widget build(BuildContext context) {
    return const Offstage(
      child: Wrap(children: kRetainedMaterialIconWidgets),
    );
  }
}
