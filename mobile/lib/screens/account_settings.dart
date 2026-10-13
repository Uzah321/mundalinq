import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../api/social_models.dart';
import '../state/session.dart';
import '../theme.dart';
import '../util/format.dart';
import '../widgets/common.dart';
import 'address_form_screen.dart';

/// Name and email. The phone number is the account's identity, so it is changed through support.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  late final _name = TextEditingController(text: context.read<Session>().user?.name);
  late final _email = TextEditingController(text: context.read<Session>().user?.email);
  ApiException? _error;
  bool _saving = false;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await context.read<Session>().updateProfile(name: _name.text.trim(), email: _email.text.trim());
      if (mounted) {
        showMessage(context, 'Profile saved');
        Navigator.pop(context);
      }
    } on ApiException catch (error) {
      setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final phone = context.select<Session, String>((session) => session.user?.phone ?? '');
    return Scaffold(
      appBar: AppBar(title: const Text('Your details')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          TextField(controller: _name, decoration: InputDecoration(labelText: 'Full name', errorText: _error?.field('name'))),
          const SizedBox(height: 14),
          TextField(
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            decoration: InputDecoration(labelText: 'Email (optional)', errorText: _error?.field('email')),
          ),
          const SizedBox(height: 14),
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: const Icon(LucideIcons.phone, color: Brand.mutedText),
            title: Text(formatPhone(phone)),
            subtitle: const Text('To change your number, contact MundaLinq support.', style: TextStyle(color: Brand.mutedText)),
          ),
          const SizedBox(height: 20),
          FilledButton(
            onPressed: _saving ? null : _save,
            child: _saving
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Save'),
          ),
        ],
      ),
    );
  }
}

/// Saved delivery addresses: choose the default, remove old ones, add new ones.
class AddressesScreen extends StatefulWidget {
  const AddressesScreen({super.key});

  @override
  State<AddressesScreen> createState() => _AddressesScreenState();
}

class _AddressesScreenState extends State<AddressesScreen> {
  List<Address>? _addresses;
  Object? _error;

  ApiClient get _api => context.read<ApiClient>();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final response = await _api.get('/me/addresses') as Map<String, dynamic>;
      if (mounted) setState(() => _addresses = (response['data'] as List).cast<Map<String, dynamic>>().map(Address.fromJson).toList());
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
  }

  Future<void> _run(Future<dynamic> Function() action) async {
    try {
      await action();
      await _load();
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    }
  }

  @override
  Widget build(BuildContext context) {
    final addresses = _addresses;
    return Scaffold(
      appBar: AppBar(title: const Text('Delivery addresses')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          final added = await Navigator.of(context).push<Address>(MaterialPageRoute(builder: (_) => const AddressFormScreen()));
          if (added != null) _load();
        },
        backgroundColor: Brand.green,
        foregroundColor: Colors.white,
        icon: const Icon(LucideIcons.plus),
        label: const Text('Add address', style: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700)),
      ),
      body: addresses == null
          ? Center(child: _error == null ? const CircularProgressIndicator() : Text(errorMessage(_error!)))
          : addresses.isEmpty
              ? const Center(
                  child: EmptyState(icon: LucideIcons.mapPin, title: 'No addresses yet', message: 'Add one at checkout or here, for farms that deliver.'),
                )
              : ListView(
                  padding: const EdgeInsets.fromLTRB(20, 12, 20, 96),
                  children: [
                    for (final address in addresses)
                      Card(
                        elevation: 0,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(Brand.radius),
                          side: BorderSide(color: address.isDefault ? Brand.green : Brand.border),
                        ),
                        child: ListTile(
                          title: Row(
                            children: [
                              Text(address.label ?? address.recipientName, style: const TextStyle(fontWeight: FontWeight.w700)),
                              if (address.isDefault) ...[const SizedBox(width: 8), const Tag(label: 'Default')],
                            ],
                          ),
                          subtitle: Text(
                            '${address.addressLine}, ${address.district}\n${address.recipientName} · ${formatPhone(address.phone)}',
                            style: const TextStyle(height: 1.5, color: Brand.mutedText),
                          ),
                          isThreeLine: true,
                          trailing: PopupMenuButton<String>(
                            onSelected: (choice) => _run(() => choice == 'default'
                                ? _api.patch('/me/addresses/${address.id}', {'is_default': true})
                                : _api.delete('/me/addresses/${address.id}')),
                            itemBuilder: (context) => [
                              if (!address.isDefault) const PopupMenuItem(value: 'default', child: Text('Make default')),
                              const PopupMenuItem(value: 'delete', child: Text('Remove', style: TextStyle(color: Brand.danger))),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
    );
  }
}

const _smsCategories = {
  'orders': ('Orders', 'New orders, confirmations, payment and handover'),
  'messages': ('Messages', 'When someone writes to you'),
  'disputes': ('Problems with orders', 'Reports and their outcome'),
  'listings': ('Listings', 'Approvals and low stock'),
  'account': ('Account', 'Farm verification'),
  'payouts': ('Payouts', 'When money is sent to you'),
};

/// Which updates also come by SMS. Everything always lands in the in-app notifications; codes always come by SMS.
class NotificationSettingsScreen extends StatefulWidget {
  const NotificationSettingsScreen({super.key});

  @override
  State<NotificationSettingsScreen> createState() => _NotificationSettingsScreenState();
}

class _NotificationSettingsScreenState extends State<NotificationSettingsScreen> {
  Map<String, bool>? _sms;

  @override
  void initState() {
    super.initState();
    context.read<ApiClient>().get('/me/notification-preferences').then((response) {
      final sms = ((response as Map<String, dynamic>)['data'] as Map<String, dynamic>)['sms'] as Map<String, dynamic>;
      if (mounted) setState(() => _sms = sms.map((key, value) => MapEntry(key, value as bool)));
    }).catchError((Object error) {
      if (mounted) showMessage(context, errorMessage(error));
    });
  }

  Future<void> _set(String category, bool on) async {
    setState(() => _sms![category] = on);
    try {
      await context.read<ApiClient>().patch('/me/notification-preferences', {
        'sms': {category: on},
      });
    } catch (error) {
      if (!mounted) return;
      setState(() => _sms![category] = !on);
      showMessage(context, errorMessage(error));
    }
  }

  @override
  Widget build(BuildContext context) {
    final sms = _sms;
    return Scaffold(
      appBar: AppBar(title: const Text('SMS updates')),
      body: sms == null
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.symmetric(vertical: 12),
              children: [
                const Padding(
                  padding: EdgeInsets.fromLTRB(20, 0, 20, 8),
                  child: Text(
                    'Everything always shows in the app. Choose what also comes by SMS. Sign-in codes always come by SMS.',
                    style: TextStyle(color: Brand.mutedText, height: 1.5),
                  ),
                ),
                for (final entry in _smsCategories.entries)
                  if (sms.containsKey(entry.key))
                    SwitchListTile(
                      value: sms[entry.key]!,
                      onChanged: (on) => _set(entry.key, on),
                      title: Text(entry.value.$1, style: const TextStyle(fontWeight: FontWeight.w600)),
                      subtitle: Text(entry.value.$2, style: const TextStyle(color: Brand.mutedText)),
                    ),
              ],
            ),
    );
  }
}

/// Phones and browsers signed in to this account; sign out any you don't recognise.
class DevicesScreen extends StatefulWidget {
  const DevicesScreen({super.key});

  @override
  State<DevicesScreen> createState() => _DevicesScreenState();
}

class _DevicesScreenState extends State<DevicesScreen> {
  List<DeviceSession>? _sessions;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final response = await context.read<ApiClient>().get('/auth/sessions') as Map<String, dynamic>;
      if (mounted) setState(() => _sessions = (response['data'] as List).cast<Map<String, dynamic>>().map(DeviceSession.fromJson).toList());
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    }
  }

  Future<void> _signOut(DeviceSession session) async {
    try {
      await context.read<ApiClient>().delete('/auth/sessions/${session.id}');
      await _load();
      if (mounted) showMessage(context, '${session.name} signed out');
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    }
  }

  @override
  Widget build(BuildContext context) {
    final sessions = _sessions;
    return Scaffold(
      appBar: AppBar(title: const Text('Signed-in devices')),
      body: sessions == null
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              children: [
                for (final session in sessions)
                  ListTile(
                    leading: Icon(session.name.contains('Web') ? LucideIcons.monitor : LucideIcons.smartphone, color: Brand.green),
                    title: Text(session.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Text(
                      session.current
                          ? 'This phone'
                          : (session.lastUsedAt == null ? 'Not used yet' : 'Last used ${formatWhen(session.lastUsedAt!)}'),
                      style: const TextStyle(color: Brand.mutedText),
                    ),
                    trailing: session.current ? null : TextButton(onPressed: () => _signOut(session), child: const Text('Sign out')),
                  ),
              ],
            ),
    );
  }
}
