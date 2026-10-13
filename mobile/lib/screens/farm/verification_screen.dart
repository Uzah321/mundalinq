import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/farm_models.dart';
import '../../state/farm.dart';
import '../../theme.dart';
import '../../util/format.dart';
import '../../widgets/common.dart';
import '../../widgets/photo_picker.dart';

/// Farm verification: an ID photo (and business papers for companies and co-operatives) for MundaLinq to check.
/// Documents are encrypted on the server and only seen by the people who approve farms.
class VerificationScreen extends StatefulWidget {
  const VerificationScreen({super.key, required this.farm});

  final Farm farm;

  @override
  State<VerificationScreen> createState() => _VerificationScreenState();
}

class _VerificationScreenState extends State<VerificationScreen> {
  Verification? _latest;
  bool _loading = true;
  bool _showForm = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final response = await context.read<ApiClient>().get('/farms/${widget.farm.id}/verification') as Map<String, dynamic>;
      final data = response['data'];
      setState(() {
        _latest = data is Map<String, dynamic> ? Verification.fromJson(data) : null;
        _showForm = _latest == null || _latest!.status == 'rejected';
      });
    } catch (_) {
      setState(() => _showForm = true);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final latest = _latest;
    return Scaffold(
      appBar: AppBar(title: const Text('Verify your farm')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
              children: [
                if (latest != null && latest.status == 'pending')
                  _Status(
                    icon: LucideIcons.clock,
                    title: 'We are checking your documents',
                    message: 'Sent ${latest.submittedAt == null ? '' : formatWhen(latest.submittedAt!)}. You will get an SMS when it is done, usually within 2 working days.',
                  ),
                if (latest != null && latest.status == 'approved')
                  const _Status(icon: LucideIcons.badgeCheck, title: 'Your farm is verified', message: 'Buyers see your listings with a verified badge.'),
                if (latest != null && latest.status == 'rejected')
                  _Status(
                    icon: LucideIcons.circleAlert,
                    title: 'Not approved',
                    message: latest.rejectionReason ?? 'Please check your documents and send them again.',
                    warning: true,
                  ),
                if (_showForm) _VerificationForm(farm: widget.farm, onSent: _load),
              ],
            ),
    );
  }
}

class _Status extends StatelessWidget {
  const _Status({required this.icon, required this.title, required this.message, this.warning = false});

  final IconData icon;
  final String title;
  final String message;
  final bool warning;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 18),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: warning ? const Color(0xFFFDE8E8) : Brand.mint,
        borderRadius: BorderRadius.circular(Brand.radius),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: warning ? Brand.danger : Brand.green),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: TextStyle(fontWeight: FontWeight.w800, color: warning ? Brand.danger : Brand.green)),
                const SizedBox(height: 4),
                Text(message, style: const TextStyle(height: 1.5)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _VerificationForm extends StatefulWidget {
  const _VerificationForm({required this.farm, required this.onSent});

  final Farm farm;
  final VoidCallback onSent;

  @override
  State<_VerificationForm> createState() => _VerificationFormState();
}

class _VerificationFormState extends State<_VerificationForm> {
  final _idNumber = TextEditingController();
  final _registration = TextEditingController();
  final _notes = TextEditingController();
  String _idType = 'national_id';
  final Map<String, UploadFile> _documents = {};
  ApiException? _error;
  bool _sending = false;

  /// What still needs filling in, by field or document type, so the farmer is told instead of facing a dead button.
  Map<String, String> _problems = const {};

  String? _problem(String field) => _problems[field] ?? _error?.field(field);

  void _fixed(String field) {
    if (_problems.containsKey(field)) setState(() => _problems = {..._problems}..remove(field));
  }

  Map<String, String> _check() {
    final passport = _idType == 'passport';
    return {
      if (_idNumber.text.trim().isEmpty) 'id_number': passport ? 'Type your passport number.' : 'Type the number on your ID.',
      if (!_documents.containsKey('identity')) 'identity': passport ? 'Add a photo of your passport.' : 'Add a photo of your ID.',
      if (widget.farm.needsBusinessRegistration && _registration.text.trim().isEmpty)
        'business_registration_number': 'Type the registration number.',
      if (widget.farm.needsBusinessRegistration && !_documents.containsKey('business_registration'))
        'business_registration': 'Add a photo of the registration certificate.',
    };
  }

  @override
  void dispose() {
    for (final controller in [_idNumber, _registration, _notes]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _pick(String type, String title) async {
    final photo = await pickPhoto(context, field: 'file', title: title);
    if (photo != null) {
      setState(() => _documents[type] = photo);
      _fixed(type);
    }
  }

  Future<void> _send() async {
    final problems = _check();
    setState(() => _problems = problems);
    if (problems.isNotEmpty) {
      showMessage(context, problems.values.first);
      return;
    }

    setState(() {
      _sending = true;
      _error = null;
    });
    final entries = _documents.entries.toList();
    try {
      await context.read<ApiClient>().upload(
        '/farms/${widget.farm.id}/verification',
        fields: {
          'id_document_type': _idType,
          'id_number': _idNumber.text.trim(),
          if (_registration.text.trim().isNotEmpty) 'business_registration_number': _registration.text.trim(),
          if (_notes.text.trim().isNotEmpty) 'notes': _notes.text.trim(),
          for (final (index, entry) in entries.indexed) 'documents[$index][type]': entry.key,
        },
        files: [
          for (final (index, entry) in entries.indexed) UploadFile('documents[$index][file]', entry.value.bytes, entry.value.filename),
        ],
      );
      if (!mounted) return;
      await context.read<FarmController>().load();
      widget.onSent();
    } on ApiException catch (error) {
      setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final needsRegistration = widget.farm.needsBusinessRegistration;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text(
          'MundaLinq checks every farm before buyers see it. Your documents are encrypted and only seen by the team that approves farms.',
          style: TextStyle(color: Brand.mutedText, height: 1.5),
        ),
        const SizedBox(height: 18),
        Wrap(
          spacing: 8,
          children: [
            for (final (value, label) in [('national_id', 'National ID'), ('passport', 'Passport')])
              ChoiceChip(
                label: Text(label),
                selected: _idType == value,
                onSelected: (_) => setState(() => _idType = value),
                selectedColor: Brand.mint,
                showCheckmark: false,
                labelStyle: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700, color: _idType == value ? Brand.green : Brand.ink),
                side: BorderSide(color: _idType == value ? Brand.green : Brand.border),
              ),
          ],
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _idNumber,
          decoration: InputDecoration(labelText: _idType == 'passport' ? 'Passport number' : 'ID number', errorText: _problem('id_number')),
          onChanged: (_) => _fixed('id_number'),
        ),
        const SizedBox(height: 12),
        _DocumentButton(
          label: _idType == 'passport' ? 'Photo of your passport' : 'Photo of your ID',
          photo: _documents['identity'],
          error: _problems['identity'],
          onTap: () => _pick('identity', 'Photo of your ID'),
        ),
        if (needsRegistration) ...[
          const SizedBox(height: 18),
          TextField(
            controller: _registration,
            decoration: InputDecoration(labelText: 'Business registration number', errorText: _problem('business_registration_number')),
            onChanged: (_) => _fixed('business_registration_number'),
          ),
          const SizedBox(height: 12),
          _DocumentButton(
            label: 'Photo of the registration certificate',
            photo: _documents['business_registration'],
            error: _problems['business_registration'],
            onTap: () => _pick('business_registration', 'Registration certificate'),
          ),
        ],
        const SizedBox(height: 12),
        _DocumentButton(
          label: 'Proof of the land (optional)',
          hint: 'A lease, offer letter or letter from the village head',
          photo: _documents['land_proof'],
          onTap: () => _pick('land_proof', 'Proof of the land'),
        ),
        const SizedBox(height: 12),
        TextField(controller: _notes, minLines: 2, maxLines: 4, decoration: const InputDecoration(labelText: 'Anything we should know (optional)')),
        if (_error != null) ...[
          const SizedBox(height: 10),
          Text(_error!.detail, style: const TextStyle(color: Brand.danger)),
        ],
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _sending ? null : _send,
          child: _sending
              ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Text('Send for verification'),
        ),
      ],
    );
  }
}

class _DocumentButton extends StatelessWidget {
  const _DocumentButton({required this.label, required this.photo, required this.onTap, this.hint, this.error});

  final String label;
  final String? hint;
  final UploadFile? photo;
  final VoidCallback onTap;

  /// Shown in place of the hint, with a red border, when this document is still missing.
  final String? error;

  @override
  Widget build(BuildContext context) {
    final added = photo != null;
    return Material(
      color: added ? Brand.mint : Brand.muted,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Brand.radius),
        side: BorderSide(color: added ? Brand.green : (error != null ? Brand.danger : Brand.border)),
      ),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(Brand.radius),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              if (added)
                ClipRRect(
                  borderRadius: BorderRadius.circular(6),
                  child: Image.memory(Uint8List.fromList(photo!.bytes), width: 48, height: 48, fit: BoxFit.cover),
                )
              else
                const SizedBox(width: 48, height: 48, child: Icon(LucideIcons.camera, color: Brand.green)),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(label, style: const TextStyle(fontWeight: FontWeight.w700)),
                    Text(
                      added ? 'Added. Tap to replace.' : (error ?? hint ?? 'Take a clear photo, all corners visible.'),
                      style: TextStyle(fontSize: 12, color: !added && error != null ? Brand.danger : Brand.mutedText),
                    ),
                  ],
                ),
              ),
              Icon(added ? LucideIcons.circleCheck : LucideIcons.plus, color: Brand.green),
            ],
          ),
        ),
      ),
    );
  }
}
