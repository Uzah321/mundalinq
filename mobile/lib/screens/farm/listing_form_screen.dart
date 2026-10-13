import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/farm_models.dart';
import '../../state/catalog.dart';
import '../../state/farm.dart';
import '../../theme.dart';
import '../../util/format.dart';
import '../../widgets/photo_picker.dart';

final _decimal = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))];

String _today() => DateTime.now().toIso8601String().substring(0, 10);

/// A new listing (with its first selling option and opening stock), or the details of an existing one.
/// Returns the saved listing.
class ListingFormScreen extends StatefulWidget {
  const ListingFormScreen({super.key, this.listing});

  final ManagedListing? listing;

  @override
  State<ListingFormScreen> createState() => _ListingFormScreenState();
}

class _ListingFormScreenState extends State<ListingFormScreen> {
  late final _title = TextEditingController(text: widget.listing?.title);
  late final _description = TextEditingController(text: widget.listing?.description);
  late final _lowStock = TextEditingController(text: widget.listing?.stock.lowThreshold);
  final _price = TextEditingController();
  final _baseQuantity = TextEditingController();
  final _stock = TextEditingController();
  final _scroll = ScrollController();

  String? _parentSlug;
  late String? _categoryId = widget.listing?.categoryId;
  late String? _grade = widget.listing?.grade ?? (widget.listing == null ? 'A' : null);
  late String _stockUnit = widget.listing?.stockUnit.code ?? 'kg';
  String? _sellUnit; // null: sell in the stock unit
  late String _harvestedOn = _today();
  late bool _pickup = widget.listing?.offersPickup ?? true;
  late bool _delivery = widget.listing?.offersDelivery ?? (context.read<FarmController>().farm?.offersDelivery ?? false);
  bool _saving = false;
  String? _progress;
  ApiException? _error;

  /// Photos chosen before saving; uploaded once the listing exists.
  final List<UploadFile> _photos = [];

  /// Problems found before sending, by the same field names the server uses.
  Map<String, String> _problems = const {};

  bool get _editing => widget.listing != null;

  String? _problem(String field) => _problems[field] ?? _error?.field(field);

  /// Once the farmer changes a field, its old complaint goes away.
  void _fixed(String field) {
    if (_problems.containsKey(field) || _error?.field(field) != null) {
      setState(() {
        _problems = {..._problems}..remove(field);
        _error = null;
      });
    }
  }

  @override
  void initState() {
    super.initState();
    final catalog = context.read<CatalogController>();
    if (_categoryId != null) {
      for (final parent in catalog.categories) {
        if (parent.id == _categoryId || parent.children.any((child) => child.id == _categoryId)) _parentSlug = parent.slug;
      }
    }
  }

  @override
  void dispose() {
    for (final controller in [_title, _description, _lowStock, _price, _baseQuantity, _stock]) {
      controller.dispose();
    }
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _pickHarvestDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: DateTime.parse(_harvestedOn),
      firstDate: now.subtract(const Duration(days: 90)),
      lastDate: now,
    );
    if (picked != null) setState(() => _harvestedOn = picked.toIso8601String().substring(0, 10));
  }

  Future<void> _addPhoto() async {
    final photo = await pickPhoto(context, field: 'image', title: 'Add a photo');
    if (photo != null) setState(() => _photos.add(photo));
  }

  /// What still needs filling in, so the farmer is told instead of facing a dead button.
  Map<String, String> _check(bool needsBase) {
    final catalog = context.read<CatalogController>();
    final parent = catalog.categories.where((category) => category.slug == _parentSlug).firstOrNull;
    final units = catalog.units;
    return {
      if (_categoryId == null)
        'category_id': parent == null
            ? "Choose what you're selling at the top of the form."
            : 'Choose which kind of ${parent.name.toLowerCase()} at the top of the form.',
      if (_title.text.trim().length < 3) 'title': 'Give it a name buyers will search for, e.g. Roma tomatoes.',
      if (!_editing && units.isEmpty) 'stock_unit': 'Still loading. Check your connection and try again.',
      if (!_editing && toMinor(_price.text.trim()) == null) 'variants.0.price': 'Enter a price, e.g. 1.20',
      if (!_editing && needsBase && toMilli(_baseQuantity.text.trim()) == null)
        'variants.0.base_quantity': 'Say how much one of these holds.',
      if (!_editing && _stock.text.trim().isNotEmpty && toMilli(_stock.text.trim()) == null)
        'initial_stock.quantity': 'Enter a number, e.g. 50',
    };
  }

  Future<void> _save() async {
    final farm = context.read<FarmController>().farm!;
    final units = context.read<CatalogController>().units;
    final sellUnit = _sellUnit ?? _stockUnit;
    final needsBase = units[sellUnit] != null && units[_stockUnit] != null && !units[sellUnit]!.convertsTo(units[_stockUnit]!);

    final problems = _check(needsBase);
    setState(() => _problems = problems);
    if (problems.isNotEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(problems.values.first)));
      // The save button is at the bottom; bring the first problem into view when it is up top.
      if (problems.containsKey('category_id') || problems.containsKey('title')) {
        _scroll.animateTo(0, duration: const Duration(milliseconds: 350), curve: Curves.easeOut);
      }
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final api = context.read<ApiClient>();
      final dynamic response;
      if (_editing) {
        response = await api.patch('/products/${widget.listing!.id}', {
          'category_id': _categoryId,
          'title': _title.text.trim(),
          'description': _description.text.trim().isEmpty ? null : _description.text.trim(),
          'quality_grade': _grade,
          'offers_pickup': _pickup,
          'offers_delivery': _delivery,
          'low_stock_threshold': _lowStock.text.trim().isEmpty ? null : _lowStock.text.trim(),
        });
      } else {
        response = await api.post('/products', {
          'farm_id': farm.id,
          'category_id': _categoryId,
          'title': _title.text.trim(),
          if (_description.text.trim().isNotEmpty) 'description': _description.text.trim(),
          'quality_grade': _grade,
          'stock_unit': _stockUnit,
          'offers_pickup': _pickup,
          'offers_delivery': _delivery,
          if (_lowStock.text.trim().isNotEmpty) 'low_stock_threshold': _lowStock.text.trim(),
          'variants': [
            {
              'unit': sellUnit,
              'price': _price.text.trim(),
              if (needsBase) 'base_quantity': _baseQuantity.text.trim(),
            },
          ],
          if (_stock.text.trim().isNotEmpty) 'initial_stock': {'quantity': _stock.text.trim(), 'harvested_on': _harvestedOn},
        });
      }
      var listing = ManagedListing.fromJson((response as Map<String, dynamic>)['data'] as Map<String, dynamic>);

      // Photos go up one at a time once the listing exists; a failure here doesn't lose the listing.
      var failed = 0;
      for (final (index, photo) in _photos.indexed) {
        if (mounted) setState(() => _progress = 'Uploading photo ${index + 1} of ${_photos.length}…');
        try {
          await api.upload('/products/${listing.id}/images', files: [photo]);
        } catch (_) {
          failed++;
        }
      }
      if (_photos.isNotEmpty) {
        listing = ManagedListing.fromJson((await api.get('/products/${listing.id}') as Map<String, dynamic>)['data'] as Map<String, dynamic>);
      }
      if (!mounted) return;
      if (failed > 0) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text("Saved, but $failed photo${failed == 1 ? '' : 's'} didn't upload. Add ${failed == 1 ? 'it' : 'them'} again on the next screen.")),
        );
      }
      Navigator.pop(context, listing);
    } on ApiException catch (error) {
      setState(() => _error = error);
      if (error.fieldErrors.isEmpty && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } catch (error) {
      setState(() => _error = ApiException(0, errorMessage(error)));
    } finally {
      if (mounted) {
        setState(() {
          _saving = false;
          _progress = null;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final catalog = context.watch<CatalogController>();
    final farm = context.watch<FarmController>().farm;
    final parent = catalog.categories.where((category) => category.slug == _parentSlug).firstOrNull;
    final units = catalog.units;
    final stockUnit = units[_stockUnit];
    final sellUnit = units[_sellUnit ?? _stockUnit];
    final needsBase = sellUnit != null && stockUnit != null && !sellUnit.convertsTo(stockUnit);

    return Scaffold(
      appBar: AppBar(title: Text(_editing ? 'Edit listing' : 'New listing')),
      body: ListView(
        controller: _scroll,
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
        children: [
          if (!_editing) ...[
            _label('Photos'),
            SizedBox(
              height: 96,
              child: ListView(
                scrollDirection: Axis.horizontal,
                children: [
                  for (final (index, photo) in _photos.indexed)
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: Stack(
                        children: [
                          ClipRRect(
                            borderRadius: BorderRadius.circular(Brand.radius),
                            child: Image.memory(Uint8List.fromList(photo.bytes), width: 96, height: 96, fit: BoxFit.cover),
                          ),
                          if (index == 0)
                            Positioned(
                              left: 4,
                              bottom: 4,
                              child: Container(
                                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                decoration: BoxDecoration(color: Brand.green, borderRadius: BorderRadius.circular(4)),
                                child: const Text('Main', style: TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w700)),
                              ),
                            ),
                          Positioned(
                            right: 2,
                            top: 2,
                            child: IconButton(
                              onPressed: () => setState(() => _photos.removeAt(index)),
                              tooltip: 'Remove photo',
                              style: IconButton.styleFrom(
                                backgroundColor: Colors.white,
                                fixedSize: const Size(28, 28),
                                minimumSize: const Size(28, 28),
                                padding: EdgeInsets.zero,
                              ),
                              icon: const Icon(LucideIcons.x, size: 14),
                            ),
                          ),
                        ],
                      ),
                    ),
                  if (_photos.length < 8)
                    InkWell(
                      onTap: _saving ? null : _addPhoto,
                      borderRadius: BorderRadius.circular(Brand.radius),
                      child: Container(
                        width: 96,
                        decoration: BoxDecoration(
                          color: Brand.mint,
                          borderRadius: BorderRadius.circular(Brand.radius),
                          border: Border.all(color: Brand.green),
                        ),
                        child: const Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(LucideIcons.camera, color: Brand.green),
                            SizedBox(height: 4),
                            Text('Add photo', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Brand.green)),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 6),
            const Text(
              'Buyers see the first photo. A listing needs at least one photo before it can go live.',
              style: TextStyle(fontSize: 12, color: Brand.mutedText),
            ),
            const SizedBox(height: 20),
          ],
          _label('What are you selling?'),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final category in catalog.categories)
                _chip(category.name, _parentSlug == category.slug, () {
                  setState(() {
                    _parentSlug = category.slug;
                    _categoryId = category.children.isEmpty ? category.id : null;
                  });
                  if (_categoryId != null) _fixed('category_id');
                }),
            ],
          ),
          if (parent != null && parent.children.isNotEmpty) ...[
            const SizedBox(height: 14),
            Text(
              'Which kind of ${parent.name.toLowerCase()}?',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: _categoryId == null ? Brand.harvestText : Brand.ink),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final child in parent.children) _chip(child.name, _categoryId == child.id, () {
                  setState(() => _categoryId = child.id);
                  _fixed('category_id');
                }, small: true),
              ],
            ),
          ],
          if (_problem('category_id') != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(_problem('category_id')!, style: const TextStyle(color: Brand.danger, fontSize: 12.5)),
            ),
          const SizedBox(height: 18),
          _text('Title', _title, hint: 'e.g. Roma tomatoes', error: 'title'),
          _text('Description (optional)', _description, hint: 'Variety, size, how it was grown…', lines: 3, error: 'description'),
          _label('Quality grade'),
          Wrap(
            spacing: 8,
            children: [
              for (final grade in ['A', 'B', 'C']) _chip('Grade $grade', _grade == grade, () => setState(() => _grade = grade), small: true),
              _chip('No grade', _grade == null, () => setState(() => _grade = null), small: true),
            ],
          ),
          const SizedBox(height: 18),
          if (!_editing) ...[
            _label('You count your stock in'),
            DropdownButtonFormField<String>(
              initialValue: _stockUnit,
              items: [for (final unit in units.values) DropdownMenuItem(value: unit.code, child: Text(unit.pluralName))],
              onChanged: (code) => setState(() {
                _stockUnit = code ?? _stockUnit;
                _sellUnit = null;
              }),
            ),
            const SizedBox(height: 18),
            _label('First way to buy'),
            Row(
              children: [
                Expanded(
                  child: DropdownButtonFormField<String>(
                    key: ValueKey('sell-$_stockUnit'),
                    initialValue: _sellUnit ?? _stockUnit,
                    decoration: const InputDecoration(labelText: 'Sold per'),
                    items: [for (final unit in units.values) DropdownMenuItem(value: unit.code, child: Text(unit.name))],
                    onChanged: (code) => setState(() => _sellUnit = code == _stockUnit ? null : code),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: TextField(
                    controller: _price,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    inputFormatters: _decimal,
                    decoration: InputDecoration(labelText: 'Price', prefixText: r'$ ', errorText: _problem('variants.0.price')),
                    onChanged: (_) => _fixed('variants.0.price'),
                  ),
                ),
              ],
            ),
            if (needsBase) ...[
              const SizedBox(height: 12),
              TextField(
                controller: _baseQuantity,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: _decimal,
                decoration: InputDecoration(
                  labelText: 'How many ${stockUnit.pluralName} in one ${sellUnit.name}?',
                  errorText: _problem('variants.0.base_quantity'),
                  errorMaxLines: 2,
                ),
                onChanged: (_) => _fixed('variants.0.base_quantity'),
              ),
            ],
            const SizedBox(height: 6),
            const Text('You can add more ways to buy (per crate, per bag…) after saving.', style: TextStyle(fontSize: 12, color: Brand.mutedText)),
            const SizedBox(height: 18),
            _label('How much do you have now?'),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _stock,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    inputFormatters: _decimal,
                    decoration: InputDecoration(
                      suffixText: stockUnit?.pluralName,
                      hintText: '0',
                      errorText: _problem('initial_stock.quantity'),
                    ),
                    onChanged: (_) => _fixed('initial_stock.quantity'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: OutlinedButton(
                    onPressed: _pickHarvestDate,
                    child: Text(_harvestedOn == _today() ? 'Harvested today' : 'Harvested ${formatDate(DateTime.parse(_harvestedOn))}'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 18),
          ],
          _label('Getting it to buyers'),
          SwitchListTile(
            value: _pickup,
            onChanged: (value) => setState(() => _pickup = value),
            contentPadding: EdgeInsets.zero,
            title: const Text('Buyers can collect from the farm'),
          ),
          SwitchListTile(
            value: _delivery && (farm?.offersDelivery ?? false),
            onChanged: (farm?.offersDelivery ?? false) ? (value) => setState(() => _delivery = value) : null,
            contentPadding: EdgeInsets.zero,
            title: const Text('You deliver this'),
            subtitle: (farm?.offersDelivery ?? false)
                ? null
                : const Text('Turn on delivery in your farm details first.', style: TextStyle(fontSize: 12)),
          ),
          const SizedBox(height: 8),
          _text('Warn me when stock drops to (optional)', _lowStock, hint: 'e.g. 10', number: true, suffix: stockUnit?.pluralName, error: 'low_stock_threshold'),
          if (_error != null && _error!.fieldErrors.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Text(_error!.detail, style: const TextStyle(color: Brand.danger)),
            ),
          FilledButton(
            onPressed: _saving ? null : _save,
            child: _saving
                ? Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)),
                      if (_progress != null) ...[const SizedBox(width: 10), Text(_progress!)],
                    ],
                  )
                : Text(_editing ? 'Save changes' : 'Save listing'),
          ),
          if (!_editing)
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: Text('Saved as a draft. On the next screen, tap Publish to put it on sale.',
                  textAlign: TextAlign.center, style: TextStyle(fontSize: 12, color: Brand.mutedText)),
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

  Widget _text(String label, TextEditingController controller, {String? hint, int lines = 1, String? error, bool number = false, String? suffix}) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 14),
        child: TextField(
          controller: controller,
          minLines: lines,
          maxLines: lines,
          keyboardType: number ? const TextInputType.numberWithOptions(decimal: true) : null,
          inputFormatters: number ? _decimal : null,
          textCapitalization: number ? TextCapitalization.none : TextCapitalization.sentences,
          decoration: InputDecoration(labelText: label, hintText: hint, suffixText: suffix, errorText: error == null ? null : _problem(error), errorMaxLines: 2),
          onChanged: error == null ? null : (_) => _fixed(error),
        ),
      );
}
