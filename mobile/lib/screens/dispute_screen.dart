import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/social_models.dart';
import '../theme.dart';
import '../util/format.dart';
import '../widgets/common.dart';
import '../widgets/photo_picker.dart';

/// A reported problem with an order: what happened, the conversation with support and the farm, and the outcome.
class DisputeScreen extends StatefulWidget {
  const DisputeScreen({super.key, required this.disputeId, this.asBuyer = false});

  final String disputeId;

  /// The buyer can add photos and withdraw the report.
  final bool asBuyer;

  @override
  State<DisputeScreen> createState() => _DisputeScreenState();
}

class _DisputeScreenState extends State<DisputeScreen> {
  final _reply = TextEditingController();
  Dispute? _dispute;
  Object? _error;
  bool _busy = false;

  ApiClient get _api => context.read<ApiClient>();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _reply.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final response = await _api.get('/disputes/${widget.disputeId}') as Map<String, dynamic>;
      if (mounted) setState(() => _dispute = Dispute.fromJson(response['data'] as Map<String, dynamic>));
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
  }

  Future<void> _run(Future<dynamic> Function() action, String done) async {
    setState(() => _busy = true);
    try {
      await action();
      await _load();
      if (mounted) showMessage(context, done);
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _send() async {
    final body = _reply.text.trim();
    if (body.isEmpty) return;
    await _run(() => _api.post('/disputes/${widget.disputeId}/messages', {'body': body}), 'Sent');
    _reply.clear();
  }

  Future<void> _addPhoto() async {
    final photo = await pickPhoto(context, field: 'photo', title: 'Photo of the problem');
    if (photo == null || !mounted) return;
    await _run(() => _api.upload('/disputes/${widget.disputeId}/evidence', files: [photo]), 'Photo added');
  }

  Future<void> _withdraw() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Withdraw your report?'),
        content: const Text('The sale completes as normal and the farm is paid.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Keep it open')),
          TextButton(onPressed: () => Navigator.pop(context, true), child: const Text('Withdraw')),
        ],
      ),
    );
    if (confirmed == true) await _run(() => _api.post('/disputes/${widget.disputeId}/withdraw'), 'Report withdrawn');
  }

  @override
  Widget build(BuildContext context) {
    final dispute = _dispute;
    return Scaffold(
      appBar: AppBar(title: Text(dispute == null ? 'Problem report' : 'Problem with ${dispute.orderNumber}')),
      body: dispute == null
          ? Center(
              child: _error == null
                  ? const CircularProgressIndicator()
                  : EmptyState(
                      icon: LucideIcons.wifiOff,
                      title: "We couldn't load this report",
                      message: errorMessage(_error!),
                      actions: [FilledButton(onPressed: _load, child: const Text('Try again'))],
                    ),
            )
          : Column(
              children: [
                Expanded(
                  child: RefreshIndicator(
                    onRefresh: _load,
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
                      children: [
                        _Summary(dispute: dispute),
                        const SizedBox(height: 16),
                        for (final message in dispute.messages) _Message(message: message),
                        if (widget.asBuyer && dispute.open) ...[
                          const SizedBox(height: 8),
                          Wrap(
                            spacing: 8,
                            children: [
                              OutlinedButton.icon(
                                onPressed: _busy || dispute.evidenceCount >= 6 ? null : _addPhoto,
                                icon: const Icon(LucideIcons.camera, size: 17),
                                label: Text('Add a photo (${dispute.evidenceCount}/6)'),
                              ),
                              TextButton(onPressed: _busy ? null : _withdraw, child: const Text('Withdraw report')),
                            ],
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
                if (dispute.open)
                  Container(
                    decoration: const BoxDecoration(color: Colors.white, border: Border(top: BorderSide(color: Brand.border))),
                    padding: EdgeInsets.fromLTRB(12, 8, 8, 8 + MediaQuery.paddingOf(context).bottom),
                    child: Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: _reply,
                            minLines: 1,
                            maxLines: 4,
                            textCapitalization: TextCapitalization.sentences,
                            decoration: const InputDecoration(hintText: 'Write to support and the farm'),
                          ),
                        ),
                        IconButton.filled(
                          onPressed: _busy ? null : _send,
                          style: IconButton.styleFrom(backgroundColor: Brand.green),
                          icon: const Icon(LucideIcons.send, size: 18, color: Colors.white),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
    );
  }
}

class _Summary extends StatelessWidget {
  const _Summary({required this.dispute});

  final Dispute dispute;

  @override
  Widget build(BuildContext context) {
    final outcome = switch (dispute.resolution) {
      'refund_full' => 'Full refund${dispute.refundAmount == null ? '' : ' of ${formatMoney(dispute.refundAmount!, dispute.currency)}'}',
      'refund_partial' => 'Partial refund of ${formatMoney(dispute.refundAmount ?? '0', dispute.currency)}',
      'no_refund' => 'No refund',
      _ => null,
    };

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(border: Border.all(color: Brand.border), borderRadius: BorderRadius.circular(Brand.radius)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(problemReasons[dispute.reason] ?? dispute.reason, style: Brand.display(size: 16)),
          const SizedBox(height: 4),
          Text('${dispute.farmName} · reported ${dispute.createdAt == null ? '' : formatWhen(dispute.createdAt!)}',
              style: const TextStyle(fontSize: 12.5, color: Brand.mutedText)),
          const SizedBox(height: 8),
          Text(dispute.description, style: const TextStyle(height: 1.5)),
          const Divider(height: 22),
          Text(
            switch (dispute.status) {
              'withdrawn' => 'Withdrawn. The sale completed as normal.',
              'resolved' => 'Decided: ${outcome ?? 'closed'}.${dispute.resolutionNote == null ? '' : ' ${dispute.resolutionNote}'}',
              _ => 'MundaLinq support is looking into it. The farm is not paid until it is settled.',
            },
            style: TextStyle(fontWeight: FontWeight.w600, color: dispute.open ? Brand.harvestText : Brand.green, height: 1.5),
          ),
        ],
      ),
    );
  }
}

class _Message extends StatelessWidget {
  const _Message({required this.message});

  final DisputeMessage message;

  @override
  Widget build(BuildContext context) {
    final who = switch (message.party) {
      'support' => 'MundaLinq support',
      'farm' => 'The farm (${message.authorName})',
      _ => message.authorName,
    };
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: message.party == 'support' ? Brand.mint : Brand.muted,
        borderRadius: BorderRadius.circular(Brand.radius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(who, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5))),
              if (message.createdAt != null) Text(formatWhen(message.createdAt!), style: const TextStyle(fontSize: 11, color: Brand.mutedText)),
            ],
          ),
          const SizedBox(height: 4),
          Text(message.body, style: const TextStyle(height: 1.5)),
        ],
      ),
    );
  }
}
