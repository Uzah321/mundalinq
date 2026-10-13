import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../state/session.dart';
import '../theme.dart';
import '../util/format.dart';
import '../widgets/common.dart';

/// Sign in or create an account. Pops with true once the buyer is signed in.
class AuthScreen extends StatefulWidget {
  const AuthScreen({super.key, this.reason, this.startWithRegister = false});

  /// Why we're asking, e.g. "Sign in to add Roma tomatoes to your basket."
  final String? reason;
  final bool startWithRegister;

  @override
  State<AuthScreen> createState() => _AuthScreenState();
}

class _AuthScreenState extends State<AuthScreen> {
  /// After registering, the buyer confirms their phone before carrying on (or chooses "Later").
  bool _verifying = false;

  @override
  Widget build(BuildContext context) {
    if (_verifying) {
      return Scaffold(
        appBar: AppBar(title: const Text('Confirm your number')),
        body: VerifyPhoneView(codeJustSent: true, onDone: () => Navigator.pop(context, true)),
      );
    }

    return DefaultTabController(
      length: 2,
      initialIndex: widget.startWithRegister ? 1 : 0,
      child: Scaffold(
        appBar: AppBar(
          title: const Wordmark(size: 20),
          bottom: const TabBar(
            labelColor: Brand.green,
            unselectedLabelColor: Brand.mutedText,
            indicatorColor: Brand.green,
            labelStyle: TextStyle(fontFamily: 'Manrope', fontSize: 14, fontWeight: FontWeight.w700),
            tabs: [Tab(text: 'Sign in'), Tab(text: 'Create account')],
          ),
        ),
        body: TabBarView(
          children: [
            _SignInForm(reason: widget.reason, onSignedIn: () => Navigator.pop(context, true)),
            _RegisterForm(reason: widget.reason, onRegistered: () => setState(() => _verifying = true)),
          ],
        ),
      ),
    );
  }
}

class _Reason extends StatelessWidget {
  const _Reason(this.text);

  final String? text;

  @override
  Widget build(BuildContext context) {
    if (text == null) return const SizedBox.shrink();
    return Container(
      margin: const EdgeInsets.only(bottom: 20),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Brand.mint, borderRadius: BorderRadius.circular(Brand.radius)),
      child: Row(
        children: [
          const Icon(LucideIcons.shoppingBasket, size: 18, color: Brand.green),
          const SizedBox(width: 10),
          Expanded(child: Text(text!, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Brand.green))),
        ],
      ),
    );
  }
}

class _Field extends StatelessWidget {
  const _Field({
    required this.label,
    required this.controller,
    this.hint,
    this.helper,
    this.error,
    this.keyboardType,
    this.obscure = false,
    this.autofillHints,
    this.textInputAction = TextInputAction.next,
    this.onSubmitted,
  });

  final String label;
  final TextEditingController controller;
  final String? hint;
  final String? helper;
  final String? error;
  final TextInputType? keyboardType;
  final bool obscure;
  final Iterable<String>? autofillHints;
  final TextInputAction textInputAction;
  final ValueChanged<String>? onSubmitted;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          TextField(
            controller: controller,
            keyboardType: keyboardType,
            obscureText: obscure,
            autofillHints: autofillHints,
            textInputAction: textInputAction,
            onSubmitted: onSubmitted,
            decoration: InputDecoration(hintText: hint, helperText: helper, errorText: error, helperMaxLines: 2, errorMaxLines: 3),
          ),
        ],
      ),
    );
  }
}

class _SignInForm extends StatefulWidget {
  const _SignInForm({required this.reason, required this.onSignedIn});

  final String? reason;
  final VoidCallback onSignedIn;

  @override
  State<_SignInForm> createState() => _SignInFormState();
}

class _SignInFormState extends State<_SignInForm> {
  final _login = TextEditingController();
  final _password = TextEditingController();
  ApiException? _error;
  bool _busy = false;

  @override
  void dispose() {
    _login.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<Session>().signIn(_login.text.trim(), _password.text);
      TextInput.finishAutofillContext();
      widget.onSignedIn();
    } on ApiException catch (error) {
      setState(() => _error = error);
    } catch (error) {
      setState(() => _error = ApiException(0, errorMessage(error)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AutofillGroup(
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          _Reason(widget.reason),
          Text('Welcome back', style: Brand.display(size: 22)),
          const SizedBox(height: 6),
          const Text('Sign in with your mobile number or email.', style: TextStyle(color: Brand.mutedText)),
          const SizedBox(height: 22),
          _Field(
            label: 'Mobile number or email',
            controller: _login,
            hint: '0771 234 567',
            keyboardType: TextInputType.emailAddress,
            autofillHints: const [AutofillHints.username],
            error: _error?.field('login'),
          ),
          _Field(
            label: 'Password',
            controller: _password,
            obscure: true,
            autofillHints: const [AutofillHints.password],
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _submit(),
            error: _error?.field('password'),
          ),
          if (_error != null && _error!.fieldErrors.isEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(_error!.message, style: const TextStyle(color: Brand.danger, fontSize: 13)),
            ),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Sign in'),
          ),
        ],
      ),
    );
  }
}

class _RegisterForm extends StatefulWidget {
  const _RegisterForm({required this.reason, required this.onRegistered});

  final String? reason;
  final VoidCallback onRegistered;

  @override
  State<_RegisterForm> createState() => _RegisterFormState();
}

class _RegisterFormState extends State<_RegisterForm> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _business = false;
  ApiException? _error;
  bool _busy = false;

  @override
  void dispose() {
    for (final controller in [_name, _phone, _email, _password]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<Session>().register(
            name: _name.text.trim(),
            phone: _phone.text.trim(),
            email: _email.text.trim(),
            password: _password.text,
            business: _business,
          );
      TextInput.finishAutofillContext();
      widget.onRegistered();
    } on ApiException catch (error) {
      setState(() => _error = error);
    } catch (error) {
      setState(() => _error = ApiException(0, errorMessage(error)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AutofillGroup(
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          _Reason(widget.reason),
          Text('Join MundaLinq', style: Brand.display(size: 22)),
          const SizedBox(height: 6),
          const Text('Buy straight from farms near you.', style: TextStyle(color: Brand.mutedText)),
          const SizedBox(height: 22),
          _Field(label: 'Full name', controller: _name, autofillHints: const [AutofillHints.name], error: _error?.field('name')),
          _Field(
            label: 'Mobile number',
            controller: _phone,
            hint: '0771 234 567',
            helper: "We'll text you a code to confirm it.",
            keyboardType: TextInputType.phone,
            autofillHints: const [AutofillHints.telephoneNumber],
            error: _error?.field('phone'),
          ),
          _Field(
            label: 'Email',
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            autofillHints: const [AutofillHints.email],
            error: _error?.field('email'),
          ),
          _Field(
            label: 'Password',
            controller: _password,
            obscure: true,
            helper: 'At least 8 characters.',
            autofillHints: const [AutofillHints.newPassword],
            textInputAction: TextInputAction.done,
            error: _error?.field('password'),
          ),
          const Text('I am buying', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(child: _Choice(label: 'For my home', selected: !_business, onTap: () => setState(() => _business = false))),
              const SizedBox(width: 8),
              Expanded(child: _Choice(label: 'For my business', selected: _business, onTap: () => setState(() => _business = true))),
            ],
          ),
          const SizedBox(height: 20),
          if (_error != null && _error!.fieldErrors.isEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(_error!.message, style: const TextStyle(color: Brand.danger, fontSize: 13)),
            ),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Create account'),
          ),
        ],
      ),
    );
  }
}

class _Choice extends StatelessWidget {
  const _Choice({required this.label, required this.selected, required this.onTap});

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected ? Brand.mint : Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Brand.radius),
        side: BorderSide(color: selected ? Brand.green : Brand.border),
      ),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(Brand.radius),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 13, horizontal: 10),
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: selected ? Brand.green : Brand.ink),
          ),
        ),
      ),
    );
  }
}

/// Enter the 6-digit code texted to the buyer's phone. A confirmed number is needed to place orders.
class VerifyPhoneView extends StatefulWidget {
  const VerifyPhoneView({super.key, required this.codeJustSent, required this.onDone});

  final bool codeJustSent;
  final VoidCallback onDone;

  @override
  State<VerifyPhoneView> createState() => _VerifyPhoneViewState();
}

class _VerifyPhoneViewState extends State<VerifyPhoneView> {
  final _code = TextEditingController();
  late bool _sent = widget.codeJustSent;
  late int _cooldown = widget.codeJustSent ? 60 : 0;
  Timer? _timer;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (_cooldown > 0) _tick();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _code.dispose();
    super.dispose();
  }

  void _tick() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (timer) {
      setState(() => _cooldown--);
      if (_cooldown <= 0) timer.cancel();
    });
  }

  Future<void> _send() async {
    setState(() => _error = null);
    try {
      await context.read<Session>().sendCode();
      setState(() {
        _sent = true;
        _cooldown = 60;
      });
      _tick();
    } on ApiException catch (error) {
      setState(() => _error = error.detail);
    }
  }

  Future<void> _verify() async {
    if (_code.text.length != 6) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<Session>().verifyPhone(_code.text);
      if (!mounted) return;
      showMessage(context, 'Your phone number is verified');
      widget.onDone();
    } on ApiException catch (error) {
      setState(() => _error = error.detail);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final phone = context.select<Session, String>((session) => session.user?.phone ?? '');

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        const Icon(LucideIcons.messageSquareText, size: 34, color: Brand.green),
        const SizedBox(height: 16),
        Text(
          _sent
              ? 'Enter the 6-digit code we sent to ${formatPhone(phone)}.'
              : "We'll text a 6-digit code to ${formatPhone(phone)}. You need a confirmed number to place orders.",
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 15, height: 1.5),
        ),
        const SizedBox(height: 24),
        if (_sent) ...[
          TextField(
            controller: _code,
            autofocus: true,
            keyboardType: TextInputType.number,
            textAlign: TextAlign.center,
            maxLength: 6,
            autofillHints: const [AutofillHints.oneTimeCode],
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            style: Brand.display(size: 26, weight: FontWeight.w700).copyWith(letterSpacing: 14),
            decoration: InputDecoration(counterText: '', hintText: '••••••', errorText: _error),
            onChanged: (value) {
              setState(() {});
              if (value.length == 6) _verify();
            },
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _busy || _code.text.length != 6 ? null : _verify,
            child: _busy
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Confirm number'),
          ),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              TextButton(
                onPressed: _cooldown > 0 ? null : _send,
                child: Text(_cooldown > 0 ? 'Resend code in ${_cooldown}s' : 'Resend code'),
              ),
              TextButton(
                onPressed: widget.onDone,
                style: TextButton.styleFrom(foregroundColor: Brand.ink),
                child: const Text('Later'),
              ),
            ],
          ),
        ] else ...[
          if (_error != null) Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: Brand.danger)),
          FilledButton(onPressed: _send, child: const Text('Send code')),
          TextButton(onPressed: widget.onDone, style: TextButton.styleFrom(foregroundColor: Brand.ink), child: const Text('Later')),
        ],
      ],
    );
  }
}
