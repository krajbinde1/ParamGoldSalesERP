import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'dart:developer' as developer;

import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_brand.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../providers/auth_controller.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _mobile = TextEditingController();
  final _password = TextEditingController();
  bool _obscure = true;
  bool _startupMessageShown = false;
  String? _loginError;

  static const _friendlyLoginError =
      'Unable to login. Please check your credentials or connection.';

  @override
  void initState() {
    super.initState();
    widget.auth.addListener(_onAuthMessage);
    WidgetsBinding.instance.addPostFrameCallback((_) => _showStartupMessage());
  }

  void _showStartupMessage() {
    if (!mounted || _startupMessageShown) return;
    final message = widget.auth.message;
    if (message == null || message.isEmpty) return;
    _startupMessageShown = true;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        behavior: SnackBarBehavior.floating,
        backgroundColor: message.toLowerCase().contains('signed in on another device')
            ? AppColors.error
            : null,
      ),
    );
    widget.auth.clearMessage();
  }

  void _onAuthMessage() {
    if (!mounted) return;
    final message = widget.auth.message;
    if (message == null || message.isEmpty) return;
    if (message.toLowerCase().contains('signed in on another device')) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(message),
          behavior: SnackBarBehavior.floating,
          backgroundColor: AppColors.error,
        ),
      );
      widget.auth.clearMessage();
    }
  }

  @override
  void dispose() {
    widget.auth.removeListener(_onAuthMessage);
    _mobile.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (widget.auth.loading) return;
    if (!_formKey.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    setState(() => _loginError = null);

    final success = await widget.auth.login(_mobile.text, _password.text);
    if (!mounted) return;
    if (success) {
      developer.log(
        'Login succeeded. Session saved: ${widget.auth.authenticated}. '
        'GoRouter redirect will navigate to the next screen.',
        name: 'Employee Auth Navigation',
      );
      return;
    }

    final friendly = _toFriendlyLoginError(widget.auth.message);
    setState(() => _loginError = friendly);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(friendly),
        behavior: SnackBarBehavior.floating,
        backgroundColor: AppColors.error,
      ),
    );
  }

  String _toFriendlyLoginError(String? raw) {
    if (raw == null || raw.trim().isEmpty) return _friendlyLoginError;
    final lower = raw.toLowerCase();
    if (lower.contains('session expired')) return raw;
    if (lower.contains('signed in on another device')) return raw;
    return _friendlyLoginError;
  }

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.sizeOf(context);
    final compact = media.height < 720;
    final topPad = compact ? AppSpacing.md : AppSpacing.xl;

    return Scaffold(
      body: PgAuthBackdrop(
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: EdgeInsets.fromLTRB(
                AppSpacing.lg,
                topPad,
                AppSpacing.lg,
                AppSpacing.xl,
              ),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const PgBrandHeader(markSize: 68, light: true),
                    SizedBox(height: compact ? AppSpacing.lg : AppSpacing.xl),
                    Text(
                      'Welcome to ParamGold Sales ERP',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                            color: Colors.white,
                            fontWeight: FontWeight.w800,
                            height: 1.25,
                          ),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    Text(
                      'Manage Sales, Orders, Attendance, Collections and Team Performance from one place.',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: Colors.white.withValues(alpha: 0.84),
                            height: 1.45,
                          ),
                    ),
                    SizedBox(height: compact ? AppSpacing.lg : AppSpacing.xl),
                    PgCard(
                      padding: EdgeInsets.all(
                        compact ? AppSpacing.lg : AppSpacing.xl,
                      ),
                      child: Form(
                        key: _formKey,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Text(
                              'Sign in',
                              style: Theme.of(context)
                                  .textTheme
                                  .titleMedium
                                  ?.copyWith(fontWeight: FontWeight.w800),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              'Use your registered mobile number to continue.',
                              style: Theme.of(context)
                                  .textTheme
                                  .bodySmall
                                  ?.copyWith(color: AppColors.textSecondary),
                            ),
                            const SizedBox(height: AppSpacing.lg),
                            TextFormField(
                              controller: _mobile,
                              keyboardType: TextInputType.phone,
                              textInputAction: TextInputAction.next,
                              inputFormatters: [
                                FilteringTextInputFormatter.digitsOnly,
                                LengthLimitingTextInputFormatter(10),
                              ],
                              onChanged: (_) {
                                if (_loginError != null) {
                                  setState(() => _loginError = null);
                                }
                              },
                              decoration: const InputDecoration(
                                labelText: 'Mobile Number / Login ID',
                                hintText: '10-digit mobile number',
                                prefixIcon:
                                    Icon(Icons.phone_android_rounded),
                              ),
                              validator: LoginValidators.mobile,
                            ),
                            const SizedBox(height: AppSpacing.md),
                            TextFormField(
                              controller: _password,
                              obscureText: _obscure,
                              textInputAction: TextInputAction.done,
                              onChanged: (_) {
                                if (_loginError != null) {
                                  setState(() => _loginError = null);
                                }
                              },
                              decoration: InputDecoration(
                                labelText: 'Password',
                                prefixIcon:
                                    const Icon(Icons.lock_outline_rounded),
                                suffixIcon: IconButton(
                                  tooltip: _obscure
                                      ? 'Show password'
                                      : 'Hide password',
                                  onPressed: () =>
                                      setState(() => _obscure = !_obscure),
                                  icon: Icon(
                                    _obscure
                                        ? Icons.visibility_rounded
                                        : Icons.visibility_off_rounded,
                                  ),
                                ),
                              ),
                              validator: LoginValidators.password,
                              onFieldSubmitted: (_) => _submit(),
                            ),
                            if (_loginError != null) ...[
                              const SizedBox(height: AppSpacing.md),
                              _LoginErrorBanner(message: _loginError!),
                            ],
                            const SizedBox(height: AppSpacing.lg),
                            ListenableBuilder(
                              listenable: widget.auth,
                              builder: (_, _) {
                                final loading = widget.auth.loading;
                                return SizedBox(
                                  height: 52,
                                  child: FilledButton(
                                    onPressed: loading ? null : _submit,
                                    child: loading
                                        ? const Row(
                                            mainAxisAlignment:
                                                MainAxisAlignment.center,
                                            children: [
                                              SizedBox.square(
                                                dimension: 18,
                                                child:
                                                    CircularProgressIndicator(
                                                  strokeWidth: 2.2,
                                                  color: Colors.white,
                                                ),
                                              ),
                                              SizedBox(width: 12),
                                              Text('Signing in...'),
                                            ],
                                          )
                                        : const Text('Login'),
                                  ),
                                );
                              },
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: AppSpacing.lg),
                    Text(
                      'Fast  •  Secure  •  Connected',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.labelLarge?.copyWith(
                            color: AppColors.textSecondary,
                            fontWeight: FontWeight.w600,
                            letterSpacing: 0.4,
                          ),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    Text(
                      'Sales  |  Attendance  |  Orders  |  Collections',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AppColors.textMuted,
                          ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _LoginErrorBanner extends StatelessWidget {
  const _LoginErrorBanner({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm + 2,
      ),
      decoration: BoxDecoration(
        color: AppColors.rejectedBg,
        borderRadius: BorderRadius.circular(AppSpacing.radiusSm),
        border: Border.all(color: AppColors.error.withValues(alpha: 0.25)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(
            Icons.error_outline_rounded,
            size: 18,
            color: AppColors.error,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.rejectedFg,
                    fontWeight: FontWeight.w600,
                    height: 1.35,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}
