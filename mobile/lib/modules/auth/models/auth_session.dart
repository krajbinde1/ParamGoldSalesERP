class AuthUser {
  const AuthUser({
    required this.id,
    required this.employeeId,
    required this.loginId,
    required this.role,
    required this.roleLabel,
    required this.mustChangePassword,
    required this.permissions,
    this.canViewProductionCosts = false,
  });

  final int id;
  final int employeeId;
  final String loginId;
  final String role;
  final String roleLabel;
  final bool mustChangePassword;
  final List<String> permissions;
  final bool canViewProductionCosts;

  factory AuthUser.fromJson(Map<String, dynamic> json) {
    final permissions =
        (json['permissions'] as List?)?.map((item) => '$item').toList() ??
            const [];
    final canViewCosts = json['can_view_production_costs'] == true ||
        permissions.contains('production_cost_view');

    return AuthUser(
      id: _asInt(json['id']),
      employeeId: _asInt(json['employee_id']),
      loginId: _asString(json['login_id']),
      role: _asString(json['role'], fallback: 'employee'),
      roleLabel: _asString(json['role_label'], fallback: 'Employee'),
      mustChangePassword: json['must_change_password'] == true,
      permissions: permissions,
      canViewProductionCosts: canViewCosts,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'employee_id': employeeId,
        'login_id': loginId,
        'role': role,
        'role_label': roleLabel,
        'must_change_password': mustChangePassword,
        'permissions': permissions,
        'can_view_production_costs': canViewProductionCosts,
      };
}

class EmployeeProfile {
  const EmployeeProfile({
    required this.id,
    required this.employeeCode,
    required this.fullName,
    required this.mobile,
    this.email,
    required this.department,
    required this.designation,
    this.reportingManager,
    required this.baseLocation,
    this.joiningDate,
    this.profilePhotoUrl,
    required this.active,
  });

  final int id;
  final String employeeCode;
  final String fullName;
  final String mobile;
  final String? email;
  final String department;
  final String designation;
  final String? reportingManager;
  final String baseLocation;
  final String? joiningDate;
  final String? profilePhotoUrl;
  final bool active;

  factory EmployeeProfile.fromJson(Map<String, dynamic> json) =>
      EmployeeProfile(
        id: _asInt(json['id']),
        employeeCode: _asString(json['employee_code']),
        fullName: _asString(json['full_name']),
        mobile: _asString(json['mobile']),
        email: _asNullableString(json['email']),
        // Live API often returns null for these optional HR fields.
        department: _asString(json['department']),
        designation: _asString(json['designation']),
        reportingManager: _asNullableString(json['reporting_manager']),
        baseLocation: _asString(json['base_location']),
        joiningDate: _asNullableString(json['joining_date']),
        profilePhotoUrl: _asNullableString(json['profile_photo_url']),
        active: json['active'] == true,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'employee_code': employeeCode,
        'full_name': fullName,
        'mobile': mobile,
        'email': email,
        'department': department,
        'designation': designation,
        'reporting_manager': reportingManager,
        'base_location': baseLocation,
        'joining_date': joiningDate,
        'profile_photo_url': profilePhotoUrl,
        'active': active,
      };
}

class AuthSession {
  const AuthSession({
    required this.token,
    required this.user,
    required this.employee,
  });
  final String token;
  final AuthUser user;
  final EmployeeProfile employee;

  factory AuthSession.fromJson(Map<String, dynamic> json) {
    final token = _asString(json['token']);
    if (token.isEmpty) {
      throw const FormatException('Login response missing token.');
    }

    final userRaw = json['user'];
    if (userRaw is! Map) {
      throw const FormatException('Login response missing user.');
    }
    final userJson = Map<String, dynamic>.from(userRaw);
    if (json['permissions'] is List) {
      userJson['permissions'] = json['permissions'];
    }

    final employeeRaw = json['employee'];
    if (employeeRaw is! Map) {
      throw const FormatException('Login response missing employee.');
    }

    return AuthSession(
      token: token,
      user: AuthUser.fromJson(userJson),
      employee: EmployeeProfile.fromJson(
        Map<String, dynamic>.from(employeeRaw),
      ),
    );
  }

  Map<String, dynamic> toJson() => {
        'token': token,
        'user': user.toJson(),
        'employee': employee.toJson(),
      };

  AuthSession copyWith({AuthUser? user, EmployeeProfile? employee}) =>
      AuthSession(
        token: token,
        user: user ?? this.user,
        employee: employee ?? this.employee,
      );
}

int _asInt(Object? value, {int fallback = 0}) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  return int.tryParse('$value') ?? fallback;
}

String _asString(Object? value, {String fallback = ''}) {
  if (value == null) return fallback;
  final text = '$value'.trim();
  return text.isEmpty ? fallback : text;
}

String? _asNullableString(Object? value) {
  if (value == null) return null;
  final text = '$value'.trim();
  return text.isEmpty ? null : text;
}
