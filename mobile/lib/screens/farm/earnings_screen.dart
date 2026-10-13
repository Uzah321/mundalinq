import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/farm_models.dart';
import '../../api/models.dart';
import '../../state/session.dart';
import '../../theme.dart';
import '../../util/format.dart';
import '../../widgets/common.dart';

const _providers = ['EcoCash', 'OneMoney', 'InnBucks', 'Telecash'];

/// What the farm has earned, what has been paid out, and where payouts go.
class EarningsScreen extends StatefulWidget {
  const EarningsScreen({super.key, required this.farm});

  final Farm farm;

  @override
  State<EarningsScreen> createState() => _EarningsScreenState();
}

class _EarningsScreenState extends State<EarningsScreen> {
  Earnings? _earnings;
  List<Payout> _payouts = const [];
  PayoutAccount? _account;
  bool _accountLoaded = false;
  Object? _error;

  ApiClient get _api => context.read<ApiClient>();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final id = widget.farm.id;
    try {
      final results = await Future.wait([
        _api.get('/farms/$id/earnings'),
        _api.get('/farms/$id/payouts'),
        _api.get('/farms/$id/payout-account'),
      ]);
      if (!mounted) return;
      setState(() {
        _earnings = Earnings.fromJson((results[0] as Map<String, dynamic>)['data'] as Map<String, dynamic>);
        _payouts = Paged.fromJson(results[1] as Map<String, dynamic>, Payout.fromJson).items;
        final account = (results[2] as Map<String, dynamic>)['data'];
        _account = account is Map<String, dynamic> ? PayoutAccount.fromJson(account) : null;
        _accountLoaded = true;
        _error = null;
      });
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
  }

  Future<void> _editAccount() async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _AccountSheet(farm: widget.farm, current: _account, api: _api),
    );
    if (saved == true) {
      await _load();
      if (mounted) showMessage(context, 'Payout account saved');
    }
  }

  @override
  Widget build(BuildContext context) {
    final earnings = _earnings;
    return Scaffold(
      appBar: AppBar(title: const Text('Earnings')),
      body: earnings == null
          ? Center(
              child: _error == null
                  ? const CircularProgressIndicator()
                  : EmptyState(
                      icon: LucideIcons.wifiOff,
                      title: "We couldn't load your earnings",
                      message: errorMessage(_error!),
                      actions: [FilledButton(onPressed: _load, child: const Text('Try again'))],
                    ),
            )
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
                children: [
                  Container(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(color: Brand.mint, borderRadius: BorderRadius.circular(Brand.radius)),
                    child: Row(
                      children: [
                        Expanded(child: _money('Available', earnings.available, earnings.currency, strong: true)),
                        Expanded(child: _money('On its way', earnings.pending, earnings.currency)),
                      ],
                    ),
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    "'On its way' is from orders the buyer can still report a problem with (48 hours after handover). "
                    'Available money is paid to your mobile money account in the next payout run.',
                    style: TextStyle(fontSize: 12, height: 1.5, color: Brand.mutedText),
                  ),
                  const SizedBox(height: 20),
                  Text('Paid to', style: Brand.display(size: 15)),
                  const SizedBox(height: 8),
                  if (_accountLoaded)
                    Material(
                      color: Colors.white,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Brand.radius), side: const BorderSide(color: Brand.border)),
                      child: ListTile(
                        onTap: _editAccount,
                        leading: const Icon(LucideIcons.smartphone, color: Brand.green),
                        title: Text(_account == null ? 'Add your mobile money account' : '${_account!.provider} ···· ${_account!.last4 ?? ''}',
                            style: const TextStyle(fontWeight: FontWeight.w700)),
                        subtitle: Text(
                          _account == null
                              ? 'Needed before MundaLinq can pay you'
                              : (_account!.onHoldUntil != null
                                  ? 'Changed recently: payouts resume ${formatWhen(_account!.onHoldUntil!)}'
                                  : _account!.accountName),
                          style: TextStyle(color: _account?.onHoldUntil != null ? Brand.harvestText : Brand.mutedText),
                        ),
                        trailing: const Icon(LucideIcons.pencil, size: 18),
                      ),
                    ),
                  if (_payouts.isNotEmpty) ...[
                    const SizedBox(height: 20),
                    Text('Payouts', style: Brand.display(size: 15)),
                    for (final payout in _payouts)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: const Icon(LucideIcons.banknote, color: Brand.green),
                        title: Text(formatMoney(payout.amount, payout.currency), style: const TextStyle(fontWeight: FontWeight.w800)),
                        subtitle: Text(
                          [if (payout.paidAt != null) formatDate(payout.paidAt!), if (payout.provider != null) payout.provider!, if (payout.reference != null) 'Ref ${payout.reference}']
                              .join(' · '),
                          style: const TextStyle(color: Brand.mutedText),
                        ),
                      ),
                  ],
                  const SizedBox(height: 20),
                  Text('Recent activity', style: Brand.display(size: 15)),
                  if (earnings.entries.isEmpty)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 12),
                      child: Text('Sales show up here once buyers pay.', style: TextStyle(color: Brand.mutedText)),
                    ),
                  for (final entry in earnings.entries)
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      title: Text(entry.description ?? entry.type, style: const TextStyle(fontWeight: FontWeight.w600)),
                      subtitle: Text(
                        [if (entry.createdAt != null) formatDate(entry.createdAt!), entry.available ? 'available' : 'on its way'].join(' · '),
                        style: const TextStyle(color: Brand.mutedText),
                      ),
                      trailing: Text(
                        formatMoney(entry.amount, earnings.currency),
                        style: TextStyle(fontWeight: FontWeight.w800, color: entry.amount.startsWith('-') ? Brand.danger : Brand.green),
                      ),
                    ),
                ],
              ),
            ),
    );
  }

  Widget _money(String label, String amount, String currency, {bool strong = false}) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: const TextStyle(fontSize: 12.5, color: Brand.green)),
          Text(formatMoney(amount, currency), style: TextStyle(fontSize: strong ? 24 : 18, fontWeight: FontWeight.w800, color: Brand.green)),
        ],
      );
}

class _AccountSheet extends StatefulWidget {
  const _AccountSheet({required this.farm, required this.current, required this.api});

  final Farm farm;
  final PayoutAccount? current;
  final ApiClient api;

  @override
  State<_AccountSheet> createState() => _AccountSheetState();
}

class _AccountSheetState extends State<_AccountSheet> {
  late String _provider = widget.current?.provider ?? _providers.first;
  late final _name = TextEditingController(text: widget.current?.accountName ?? context.read<Session>().user?.name);
  final _number = TextEditingController();
  ApiException? _error;
  bool _saving = false;

  @override
  void dispose() {
    _name.dispose();
    _number.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await widget.api.put('/farms/${widget.farm.id}/payout-account', {
        'provider': _provider,
        'account_name': _name.text.trim(),
        'account_number': _number.text.trim(),
      });
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (error) {
      setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        children: [
          Text('Mobile money for payouts', style: Brand.display(size: 19)),
          const SizedBox(height: 6),
          Text(
            widget.current == null
                ? 'MundaLinq pays your earnings to this account.'
                : 'For your safety, payouts pause for a short while after the account changes.',
            style: const TextStyle(color: Brand.mutedText, height: 1.5),
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 8,
            children: [
              for (final provider in _providers)
                ChoiceChip(
                  label: Text(provider),
                  selected: _provider == provider,
                  onSelected: (_) => setState(() => _provider = provider),
                  selectedColor: Brand.mint,
                  showCheckmark: false,
                  labelStyle: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700, color: _provider == provider ? Brand.green : Brand.ink),
                  side: BorderSide(color: _provider == provider ? Brand.green : Brand.border),
                ),
            ],
          ),
          const SizedBox(height: 12),
          TextField(controller: _name, decoration: InputDecoration(labelText: 'Name on the account', errorText: _error?.field('account_name'))),
          const SizedBox(height: 12),
          TextField(
            controller: _number,
            keyboardType: TextInputType.phone,
            decoration: InputDecoration(labelText: 'Mobile money number', hintText: '0771 234 567', errorText: _error?.field('account_number'), errorMaxLines: 2),
          ),
          if (_error != null && _error!.fieldErrors.isEmpty)
            Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!.message, style: const TextStyle(color: Brand.danger))),
          const SizedBox(height: 16),
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
