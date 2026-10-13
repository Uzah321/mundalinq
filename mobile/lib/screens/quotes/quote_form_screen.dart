import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/quote_models.dart';
import '../../state/catalog.dart';
import '../../state/session.dart';
import '../../state/shopping_area.dart';
import '../../theme.dart';
import '../../util/format.dart';
import '../../widgets/common.dart';
import '../auth_screen.dart';

final _decimal = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))];

const _frequencies = [('one_off', 'Just once'), ('weekly', 'Every week'), ('fortnightly', 'Every two weeks'), ('monthly', 'Every month')];
const _fulfilments = [('either', 'Either is fine'), ('delivery', 'Delivered to me'), ('pickup', "I'll collect")];

String _date(DateTime date) => date.toIso8601String().substring(0, 10);

/// A new request for quotes: what, how much, how often and where. Farms in that province selling it are told.
/// Returns the new request and how many farms were told.
class QuoteFormScreen extends StatefulWidget {
  const QuoteFormScreen({super.key});

  @override
  State<QuoteFormScreen> createState() => _QuoteFormScreenState();
}

class _QuoteFormScreenState extends State<QuoteFormScreen> {
  final _title = TextEditingController();
  final _quantity = TextEditingController();
  final _district = TextEditingController();
  final _notes = TextEditingController();
  final _scroll = ScrollController();

  String? _parentId;
  String? _categoryId;
  String _unit = 'kg';
  String _frequency = 'weekly';
  DateTime _neededBy = DateTime.now().add(const Duration(days: 3));
  String _fulfilment = 'either';
  late String _province = context.read<ShoppingAreaController>().area?.province ?? 'Harare';
  int _openDays = 7;
  bool _sending = false;
  ApiException? _error;
  Map<String, String> _problems = const {};

  String? _problem(String field) => _problems[field] ?? _error?.field(field);

  void _fixed(String field) {
    if (_problems.containsKey(field) || _error?.field(field) != null) {
      setState(() {
        _problems = {..._problems}..remove(field);
        _error = null;
      });
    }
  }

  @override
  void dispose() {
    for (final controller in [_title, _quantity, _district, _notes]) {
      controller.dispose();
    }
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _neededBy,
      firstDate: DateTime(now.year, now.month, now.day),
      lastDate: now.add(const Duration(days: 180)),
    );
    if (picked != null) setState(() => _neededBy = picked);
  }

  Future<void> _verifyPhone() => Navigator.of(context).push(
        MaterialPageRoute(
          builder: (context) => Scaffold(
            appBar: AppBar(title: const Text('Confirm your number')),
            body: VerifyPhoneView(codeJustSent: false, onDone: () => Navigator.pop(context)),
          ),
        ),
      );

  Future<void> _send() async {
    if (context.read<Session>().user?.phoneVerified != true) {
      await _verifyPhone();
      return;
    }
    final problems = {
      if (_title.text.trim().length < 3) 'title': 'Say what you need, e.g. Roma tomatoes.',
      if (_categoryId == null) 'category_id': 'Choose what kind of produce it is.',
      if ((toMilli(_quantity.text.trim()) ?? 0) <= 0) 'quantity': 'Enter how much, e.g. 200.',
      if (_district.text.trim().isEmpty) 'district': 'Enter your town or district.',
    };
    setState(() => _problems = problems);
    if (problems.isNotEmpty) {
      showMessage(context, problems.values.first);
      if (problems.containsKey('title') || problems.containsKey('category_id')) {
        _scroll.animateTo(0, duration: const Duration(milliseconds: 350), curve: Curves.easeOut);
      }
      return;
    }

    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final response = await context.read<ApiClient>().post('/quote-requests', {
        'category_id': _categoryId,
        'title': _title.text.trim(),
        'quantity': _quantity.text.trim(),
        'unit': _unit,
        'frequency': _frequency,
        'needed_by': _date(_neededBy),
        'fulfilment': _fulfilment,
        'province': _province,
        'district': _district.text.trim(),
        if (_notes.text.trim().isNotEmpty) 'notes': _notes.text.trim(),
        'open_days': _openDays,
      }) as Map<String, dynamic>;
      if (!mounted) return;
      final farmsTold = (response['meta'] as Map<String, dynamic>?)?['farms_told'] as int? ?? 0;
      Navigator.pop(context, (QuoteRequest.fromJson(response['data'] as Map<String, dynamic>), farmsTold));
    } on ApiException catch (error) {
      setState(() => _error = error);
      if (mounted) showMessage(context, error.detail);
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final catalog = context.watch<CatalogController>();
    final user = context.watch<Session>().user;
    final parent = catalog.categories.where((category) => category.id == _parentId).firstOrNull;

    return Scaffold(
      appBar: AppBar(title: const Text('Ask farms for a price')),
      body: ListView(
        controller: _scroll,
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
        children: [
          const Text(
            'Buying a lot, or every week? Farms near you that sell it send their prices. You compare them and accept the one you like.',
            style: TextStyle(fontSize: 13, color: Brand.mutedText, height: 1.5),
          ),
          const SizedBox(height: 18),
          if (user != null && !user.phoneVerified) ...[
            Container(
              padding: const EdgeInsets.fromLTRB(14, 6, 6, 6),
              decoration: BoxDecoration(color: Brand.harvest.withValues(alpha: 0.25), borderRadius: BorderRadius.circular(Brand.radius)),
              child: Row(
                children: [
                  const Expanded(
                    child: Text('Confirm your phone number first. Farms use it to reach you.',
                        style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: Brand.harvestText)),
                  ),
                  TextButton(onPressed: _verifyPhone, child: const Text('Confirm')),
                ],
              ),
            ),
            const SizedBox(height: 18),
          ],
          _text('What do you need?', _title, hint: 'e.g. Roma tomatoes', error: 'title'),
          _label('Kind of produce'),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final category in catalog.categories)
                _chip(category.name, _parentId == category.id, () {
                  setState(() {
                    _parentId = category.id;
                    _categoryId = category.id;
                  });
                  _fixed('category_id');
                }),
            ],
          ),
          if (parent != null && parent.children.isNotEmpty) ...[
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _chip('Any ${parent.name.toLowerCase()}', _categoryId == parent.id, () => setState(() => _categoryId = parent.id), small: true),
                for (final child in parent.children) _chip(child.name, _categoryId == child.id, () => setState(() => _categoryId = child.id), small: true),
              ],
            ),
          ],
          if (_problem('category_id') != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(_problem('category_id')!, style: const TextStyle(color: Brand.danger, fontSize: 12.5)),
            ),
          const SizedBox(height: 18),
          _label('How much'),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: TextField(
                  controller: _quantity,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: _decimal,
                  decoration: InputDecoration(hintText: '200', errorText: _problem('quantity')),
                  onChanged: (_) => _fixed('quantity'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: DropdownButtonFormField<String>(
                  initialValue: catalog.units.containsKey(_unit) || catalog.units.isEmpty ? _unit : catalog.units.keys.first,
                  isExpanded: true,
                  items: [for (final unit in catalog.units.values) DropdownMenuItem(value: unit.code, child: Text(unit.pluralName))],
                  onChanged: (code) => setState(() => _unit = code ?? _unit),
                ),
              ),
            ],
          ),
          const SizedBox(height: 18),
          _label('How often'),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [for (final (value, label) in _frequencies) _chip(label, _frequency == value, () => setState(() => _frequency = value), small: true)],
          ),
          const SizedBox(height: 18),
          _label(_frequency == 'one_off' ? 'Needed by' : 'First needed by'),
          OutlinedButton.icon(onPressed: _pickDate, icon: const Icon(LucideIcons.calendar, size: 16), label: Text(formatDate(_neededBy))),
          if (_problem('needed_by') != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(_problem('needed_by')!, style: const TextStyle(color: Brand.danger, fontSize: 12.5)),
            ),
          const SizedBox(height: 18),
          _label('Getting it'),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [for (final (value, label) in _fulfilments) _chip(label, _fulfilment == value, () => setState(() => _fulfilment = value), small: true)],
          ),
          const SizedBox(height: 18),
          _label('Where'),
          DropdownButtonFormField<String>(
            initialValue: _province,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Province'),
            items: [for (final province in provinces) DropdownMenuItem(value: province, child: Text(province))],
            onChanged: (value) => setState(() => _province = value ?? _province),
          ),
          const SizedBox(height: 12),
          _text('Town or district', _district, hint: 'e.g. Borrowdale', error: 'district'),
          _text('Anything else farms should know (optional)', _notes, hint: 'e.g. Firm and red, for a restaurant. Delivery before 8am.', lines: 3, error: 'notes'),
          _label('Take offers for'),
          Wrap(
            spacing: 8,
            children: [for (final days in const [3, 7, 14]) _chip('$days days', _openDays == days, () => setState(() => _openDays = days), small: true)],
          ),
          const SizedBox(height: 24),
          FilledButton(
            onPressed: _sending ? null : _send,
            child: _sending
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Send to farms'),
          ),
          const SizedBox(height: 8),
          const Text(
            'Only farms in that province that sell this produce see your request, with your first name only.',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 12, color: Brand.mutedText),
          ),
        ],
      ),
    );
  }

  Widget _label(String text) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(text, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
      );

  Widget _chip(String label, bool selected, VoidCallback onTap, {bool small = false}) => ChoiceChip(
        label: Text(label),
        selected: selected,
        onSelected: (_) => onTap(),
        selectedColor: Brand.mint,
        backgroundColor: Colors.white,
        showCheckmark: false,
        visualDensity: small ? VisualDensity.compact : null,
        labelStyle: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700, color: selected ? Brand.green : Brand.ink),
        side: BorderSide(color: selected ? Brand.green : Brand.border),
      );

  Widget _text(String label, TextEditingController controller, {String? hint, int lines = 1, String? error}) => Padding(
        padding: const EdgeInsets.only(bottom: 14),
        child: TextField(
          controller: controller,
          minLines: lines,
          maxLines: lines,
          textCapitalization: TextCapitalization.sentences,
          decoration: InputDecoration(labelText: label, hintText: hint, errorText: error == null ? null : _problem(error), errorMaxLines: 2),
          onChanged: error == null ? null : (_) => _fixed(error),
        ),
      );
}
