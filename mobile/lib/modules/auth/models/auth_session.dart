class AuthUser {
  const AuthUser({
    required this.id,
    required this.employeeId,
    required this.loginId,
    required this.role,
    required this.roleLabel,
    required this.mustChangePassword,
    required this.permissions,
  });

  final int id;
  final int employeeId;
  final String loginId;
  final String role;
  final String roleLabel;
  final bool mustChangePassword;
  final List<String> permissions;

  factory AuthUser.fromJson(Map<String, dynamic> json) => AuthUser(
    id: json['id'] as int,
    employeeId: json['employee_id'] as int,
    loginId: json['login_id'] as String,
    role: json['role'] as String? ?? 'employee',
    roleLabel: json['role_label'] as String? ?? 'Employee',
    mustChangePassword: json['must_change_password'] == true,
    permissions:
        (json['permissions'] as List?)?.map((item) => '$item').toList() ??
        const [],
  );

  Map<String, dynamic> toJson() => {
    'id': id,
    'employee_id': employeeId,
    'login_id': loginId,
    'role': role,
    'role_label': roleLabel,
    'must_change_password': mustChangePassword,
    'permissions': permissions,
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
        id: json['id'] as int,
        employeeCode: json['employee_code'] as String,
        fullName: json['full_name'] as String,
        mobile: json['mobile'] as String,
        email: json['email'] as String?,
        department: json['department'] as String,
        designation: json['designation'] as String,
        reportingManager: json['reporting_manager'] as String?,
        baseLocation: json['base_location'] as String,
        joiningDate: json['joining_date'] as String?,
        profilePhotoUrl: json['profile_photo_url'] as String?,
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
    final userJson = Map<String, dynamic>.from(json['user'] as Map);
    if (json['permissions'] is List) {
      userJson['permissions'] = json['permissions'];
    }

    return AuthSession(
      token: json['token'] as String,
      user: AuthUser.fromJson(userJson),
      employee: EmployeeProfile.fromJson(
        Map<String, dynamic>.from(json['employee'] as Map),
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
