import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Forgotten password: a code by SMS, then a new password. Every device is signed out afterwards.
class ResetPasswordScreen extends StatefulWidget {
  const ResetPasswordScreen({super.key});

  @override
  State<ResetPasswordScreen> createState() => _ResetPasswordScreenState();
}

class _ResetPasswordScreenState extends State<ResetPasswordScreen> {
  final _phone = TextEditingController();
  final _code = TextEditingController();
  final _password = TextEditingController();
  bool _codeSent = false;
  bool _busy = false;
  ApiException? _error;

  @override
  void dispose() {
    for (final controller in [_phone, _code, _password]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _run(Future<void> Function() action) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await action();
    } on ApiException catch (error) {
      setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sendCode() => _run(() async {
        await context.read<ApiClient>().post('/auth/password/forgot', {'phone': _phone.text.trim()});
        setState(() => _codeSent = true);
      });

  Future<void> _reset() => _run(() async {
        await context.read<ApiClient>().post('/auth/password/reset', {
          'phone': _phone.text.trim(),
          'code': _code.text.trim(),
          'password': _password.text,
          'password_confirmation': _password.text,
        });
        if (!mounted) return;
        showMessage(context, 'Password changed. Sign in with your new password.');
        Navigator.pop(context);
      });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Reset your password')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            _codeSent
                ? 'If an account uses that number, we have texted it a 6-digit code. Enter it with your new password.'
                : "Enter your mobile number and we'll text you a code to set a new password.",
            style: const TextStyle(height: 1.5, color: Brand.mutedText),
          ),
          const SizedBox(height: 18),
          TextField(
            controller: _phone,
            enabled: !_codeSent,
            keyboardType: TextInputType.phone,
            decoration: InputDecoration(labelText: 'Mobile number', hintText: '0771 234 567', errorText: _error?.field('phone')),
          ),
          if (_codeSent) ...[
            const SizedBox(height: 14),
            TextField(
              controller: _code,
              keyboardType: TextInputType.number,
              maxLength: 6,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: InputDecoration(labelText: 'Code from the SMS', counterText: '', errorText: _error?.field('code')),
            ),
            const SizedBox(height: 14),
            TextField(
              controller: _password,
              obscureText: true,
              autofillHints: const [AutofillHints.newPassword],
              decoration: InputDecoration(labelText: 'New password', helperText: 'At least 8 characters.', errorText: _error?.field('password')),
            ),
          ],
          if (_error != null && _error!.fieldErrors.isEmpty)
            Padding(padding: const EdgeInsets.only(top: 10), child: Text(_error!.message, style: const TextStyle(color: Brand.danger))),
          const SizedBox(height: 20),
          FilledButton(
            onPressed: _busy ? null : (_codeSent ? _reset : _sendCode),
            child: _busy
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : Text(_codeSent ? 'Set new password' : 'Send code'),
          ),
          if (_codeSent)
            TextButton(onPressed: _busy ? null : () => setState(() => _codeSent = false), child: const Text('Use a different number')),
        ],
      ),
    );
  }
}
