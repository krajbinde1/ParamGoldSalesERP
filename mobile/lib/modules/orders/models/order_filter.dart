enum OrderFilter { all, pending, dispatched, rejected }

extension OrderFilterX on OrderFilter {
  String get apiValue => name;

  String get title => switch (this) {
    OrderFilter.all => 'All Orders',
    OrderFilter.pending => 'Pending Orders',
    OrderFilter.dispatched => 'Dispatched Orders',
    OrderFilter.rejected => 'Rejected Orders',
  };

  static OrderFilter? fromName(String? value) {
    if (value == null || value.isEmpty) return null;
    for (final filter in OrderFilter.values) {
      if (filter.name == value) return filter;
    }
    return null;
  }
}
