import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/farm_models.dart';
import '../../api/models.dart';
import '../../state/catalog.dart';
import '../../state/farm.dart';
import '../../theme.dart';
import '../../util/farm_status.dart';
import '../../util/format.dart';
import '../../widgets/common.dart';
import '../../widgets/photo_picker.dart';
import '../order_screen.dart' show StatusChip;
import 'listing_form_screen.dart';
import 'verification_screen.dart';

final _decimal = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))];

/// One listing as its farm manages it: photos, status, details, ways to buy and stock.
class ListingScreen extends StatefulWidget {
  const ListingScreen({super.key, required this.listingId});

  final String listingId;

  @override
  State<ListingScreen> createState() => _ListingScreenState();
}

class _ListingScreenState extends State<ListingScreen> {
  ManagedListing? _listing;
  Object? _error;
  bool _busy = false;

  ApiClient get _api => context.read<ApiClient>();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final response = await _api.get('/products/${widget.listingId}') as Map<String, dynamic>;
      if (mounted) setState(() => _listing = ManagedListing.fromJson(response['data'] as Map<String, dynamic>));
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
  }

  /// Runs a change, then shows the listing as the server now has it.
  Future<void> _run(Future<dynamic> Function() action, {String? done}) async {
    setState(() => _busy = true);
    try {
      final response = await action();
      if (response is Map<String, dynamic> && response['data'] is Map<String, dynamic> && (response['data'] as Map).containsKey('stock')) {
        setState(() => _listing = ManagedListing.fromJson(response['data'] as Map<String, dynamic>));
      } else {
        await _load();
      }
      if (done != null && mounted) showMessage(context, done);
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _addPhoto() async {
    final photo = await pickPhoto(context, field: 'image', title: 'Add a photo of ${_listing!.title}');
    if (photo == null || !mounted) return;
    await _run(() => _api.upload('/products/${widget.listingId}/images', files: [photo]), done: 'Photo added');
  }

  Future<void> _photoOptions(ProductImage image, int index) async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (index > 0)
              ListTile(leading: const Icon(LucideIcons.star), title: const Text('Use as the main photo'), onTap: () => Navigator.pop(context, 'cover')),
            ListTile(
              leading: const Icon(LucideIcons.trash2, color: Brand.danger),
              title: const Text('Remove photo', style: TextStyle(color: Brand.danger)),
              onTap: () => Navigator.pop(context, 'delete'),
            ),
          ],
        ),
      ),
    );
    if (!mounted) return;
    final images = _listing!.images;
    if (choice == 'cover') {
      await _run(() => _api.put('/products/${widget.listingId}/images/order', {
            'image_ids': [image.id, for (final other in images) if (other.id != image.id) other.id],
          }));
    } else if (choice == 'delete') {
      await _run(() => _api.delete('/products/${widget.listingId}/images/${image.id}'), done: 'Photo removed');
    }
  }

  Future<void> _edit() async {
    final saved = await Navigator.of(context).push<ManagedListing>(MaterialPageRoute(builder: (_) => ListingFormScreen(listing: _listing)));
    if (saved != null && mounted) setState(() => _listing = saved);
  }

  Future<void> _variant([ManagedVariant? variant]) async {
    final changed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _VariantSheet(listing: _listing!, variant: variant, api: _api),
    );
    if (changed == true) await _load();
  }

  Future<void> _addStock() async {
    final added = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _StockSheet(listing: _listing!, api: _api),
    );
    if (added == true) {
      await _load();
      if (mounted) showMessage(context, 'Stock added');
    }
  }

  Future<void> _delete() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delete this listing?'),
        content: const Text('Buyers will no longer see it. Past orders keep their details.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Keep')),
          TextButton(onPressed: () => Navigator.pop(context, true), child: const Text('Delete', style: TextStyle(color: Brand.danger))),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    try {
      await _api.delete('/products/${widget.listingId}');
      if (mounted) Navigator.pop(context);
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    }
  }

  @override
  Widget build(BuildContext context) {
    final listing = _listing;
    return Scaffold(
      appBar: AppBar(
        title: Text(listing?.title ?? 'Listing'),
        actions: [
          if (listing != null)
            IconButton(onPressed: _edit, tooltip: 'Edit details', icon: const Icon(LucideIcons.pencil, size: 20)),
        ],
      ),
      body: listing == null
          ? Center(
              child: _error == null
                  ? const CircularProgressIndicator()
                  : EmptyState(
                      icon: LucideIcons.wifiOff,
                      title: "We couldn't load this listing",
                      message: errorMessage(_error!),
                      actions: [FilledButton(onPressed: _load, child: const Text('Try again'))],
                    ),
            )
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
                children: [
                  _photos(listing),
                  const SizedBox(height: 16),
                  _status(listing),
                  // Featuring is paid from the farm's earnings, which only owners handle.
                  if (listing.status == 'active' && !listing.isExpired && context.watch<FarmController>().farm?.isOwner == true) ...[
                    const SizedBox(height: 16),
                    _featuring(listing),
                  ],
                  const SizedBox(height: 16),
                  _section(
                    'Ways to buy',
                    Column(
                      children: [
                        for (final variant in listing.variants)
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            onTap: () => _variant(variant),
                            title: Text(variant.name, style: TextStyle(fontWeight: FontWeight.w700, color: variant.isActive ? Brand.ink : Brand.mutedText)),
                            subtitle: _variantNote(variant, listing) == null
                                ? null
                                : Text(_variantNote(variant, listing)!, style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
                            trailing: Text(
                              '${formatMoney(variant.price, listing.currency)} / ${variant.unit.code}',
                              style: const TextStyle(fontWeight: FontWeight.w800, color: Brand.green),
                            ),
                          ),
                        Align(
                          alignment: Alignment.centerLeft,
                          child: TextButton.icon(onPressed: () => _variant(), icon: const Icon(LucideIcons.plus, size: 16), label: const Text('Add a way to buy')),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  _section(
                    'Stock',
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            _stockFigure('Available', listing.stock.available, listing.stockUnit, strong: true, warn: listing.stock.isLow),
                            _stockFigure('In orders', listing.stock.reserved, listing.stockUnit),
                            _stockFigure('On hand', listing.stock.onHand, listing.stockUnit),
                          ],
                        ),
                        if (listing.stock.isLow)
                          const Padding(
                            padding: EdgeInsets.only(top: 8),
                            child: Text('Running low. Add stock if you have more.', style: TextStyle(color: Brand.danger, fontSize: 12.5)),
                          ),
                        const SizedBox(height: 8),
                        OutlinedButton.icon(onPressed: _busy ? null : _addStock, icon: const Icon(LucideIcons.packagePlus, size: 18), label: const Text('Add stock')),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  _section(
                    'Details',
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          [listing.categoryName, if (listing.grade != null) 'Grade ${listing.grade}', 'per ${listing.stockUnit.name} stock'].join(' · '),
                          style: const TextStyle(color: Brand.mutedText, fontSize: 13),
                        ),
                        if (listing.description != null) ...[const SizedBox(height: 6), Text(listing.description!, style: const TextStyle(height: 1.5))],
                        const SizedBox(height: 6),
                        Text(
                          [if (listing.offersPickup) 'Collection', if (listing.offersDelivery) 'Delivery'].join(' and ').ifEmpty('No way to get it yet'),
                          style: const TextStyle(fontSize: 13),
                        ),
                        Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: _edit, child: const Text('Edit details'))),
                      ],
                    ),
                  ),
                  const SizedBox(height: 24),
                  Center(
                    child: TextButton.icon(
                      onPressed: _busy ? null : _delete,
                      style: TextButton.styleFrom(foregroundColor: Brand.danger),
                      icon: const Icon(LucideIcons.trash2, size: 16),
                      label: const Text('Delete listing'),
                    ),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _photos(ManagedListing listing) {
    return SizedBox(
      height: 112,
      child: ListView(
        scrollDirection: Axis.horizontal,
        children: [
          for (final (index, image) in listing.images.indexed)
            Padding(
              padding: const EdgeInsets.only(right: 10),
              child: InkWell(
                onTap: () => _photoOptions(image, index),
                borderRadius: BorderRadius.circular(Brand.radius),
                child: Stack(
                  children: [
                    ClipRRect(
                      borderRadius: BorderRadius.circular(Brand.radius),
                      child: CachedNetworkImage(imageUrl: image.thumbUrl, width: 112, height: 112, fit: BoxFit.cover),
                    ),
                    if (index == 0)
                      Positioned(
                        left: 6,
                        top: 6,
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(color: Brand.green, borderRadius: BorderRadius.circular(4)),
                          child: const Text('Main', style: TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w700)),
                        ),
                      ),
                  ],
                ),
              ),
            ),
          InkWell(
            onTap: _busy ? null : _addPhoto,
            borderRadius: BorderRadius.circular(Brand.radius),
            child: Container(
              width: 112,
              decoration: BoxDecoration(
                color: Brand.muted,
                borderRadius: BorderRadius.circular(Brand.radius),
                border: Border.all(color: Brand.border),
              ),
              child: _busy
                  ? const Center(child: CircularProgressIndicator())
                  : const Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(LucideIcons.camera, color: Brand.green),
                        SizedBox(height: 6),
                        Text('Add photo', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Brand.green)),
                      ],
                    ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _featuring(ManagedListing listing) {
    final until = listing.featuredUntil;
    return _section(
      'Featured',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            until == null
                ? 'Featured listings show first in the market with a Featured badge, so more buyers see them.'
                : 'Featured until ${formatDate(until)}. It shows first in the market with a Featured badge.',
            style: const TextStyle(fontSize: 13, height: 1.5),
          ),
          const SizedBox(height: 10),
          OutlinedButton.icon(
            onPressed: _busy ? null : () => _openFeaturing(listing),
            icon: const Icon(LucideIcons.sparkles, size: 18),
            label: Text(until == null ? 'Feature this listing' : 'Add more weeks'),
          ),
        ],
      ),
    );
  }

  Future<void> _openFeaturing(ManagedListing listing) async {
    final farm = context.read<FarmController>().farm;
    if (farm == null) return;
    final updated = await showModalBottomSheet<ManagedListing>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _FeatureSheet(api: _api, listing: listing, farmId: farm.id),
    );
    if (updated != null && mounted) {
      setState(() => _listing = updated);
      showMessage(context, 'Featured until ${formatDate(updated.featuredUntil!)}');
    }
  }

  /// Verified farms' listings go straight on sale; some categories (livestock, dairy) are checked first.
  Future<void> _publish(ManagedListing listing) async {
    await _run(() => _api.post('/products/${listing.id}/publish'));
    final status = _listing?.status;
    if (!mounted || status == listing.status) return;
    showMessage(context, status == 'active' ? 'On sale now. Buyers can see it.' : 'Sent to MundaLinq to check before it goes on sale.');
  }

  Future<void> _openVerification(Farm farm) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => VerificationScreen(farm: farm)));
    // Sending documents reloads the farm, which swaps this card back to Publish once it is approved.
    if (mounted) setState(() {});
  }

  Widget _status(ManagedListing listing) {
    final hasPhoto = listing.images.isNotEmpty;
    final farm = context.watch<FarmController>().farm;
    // Nothing goes on sale until the farm is verified, so point there instead of offering a Publish that fails.
    final unverified = farm != null && farm.verificationStatus != 'approved' && (listing.status == 'draft' || listing.status == 'rejected') ? farm : null;
    final List<Widget> actions = switch (listing.status) {
      _ when unverified != null => [
          if (unverified.verificationStatus == 'pending')
            OutlinedButton(onPressed: () => _openVerification(unverified), child: const Text('See verification'))
          else
            FilledButton.icon(
              onPressed: () => _openVerification(unverified),
              icon: const Icon(LucideIcons.shieldCheck, size: 18),
              label: const Text('Verify your farm'),
            ),
        ],
      'draft' || 'rejected' => [
          FilledButton(
            onPressed: _busy || !hasPhoto ? null : () => _publish(listing),
            child: const Text('Publish'),
          ),
        ],
      'active' => [
          if (listing.isExpired || (listing.expiresAt != null && listing.expiresAt!.difference(DateTime.now()).inDays < 7))
            FilledButton(
              onPressed: _busy ? null : () => _run(() => _api.post('/products/${listing.id}/renew'), done: 'Renewed for another 30 days'),
              child: const Text('Renew for 30 days'),
            ),
          OutlinedButton(
            onPressed: _busy ? null : () => _run(() => _api.post('/products/${listing.id}/pause'), done: 'Paused. Buyers no longer see it.'),
            child: const Text('Pause'),
          ),
        ],
      'paused' => [
          FilledButton(
            onPressed: _busy ? null : () => _run(() => _api.post('/products/${listing.id}/resume'), done: 'Back on sale'),
            child: const Text('Put back on sale'),
          ),
        ],
      _ => const [],
    };

    final note = switch (listing.status) {
      _ when unverified?.verificationStatus == 'pending' =>
        'MundaLinq is checking your ID, usually within 2 working days. You can publish once your farm is verified.',
      _ when unverified != null => unverified.verificationStatus == 'rejected'
          ? 'Your farm was not verified yet. Fix what MundaLinq asked for and send your ID again, then publish.'
          : 'Before anything goes on sale, MundaLinq checks your ID once. Verify your farm, then come back and publish.',
      'draft' => hasPhoto ? 'Ready to publish when you are.' : 'Add at least one photo, then publish.',
      'pending_review' => 'MundaLinq checks new listings before buyers see them, usually within a day.',
      'active' => listing.expiresAt == null ? 'Buyers can see and order it.' : 'Live until ${formatDate(listing.expiresAt!)}. Renew it to keep it up.',
      'paused' => 'Hidden from buyers. Orders already placed still go ahead.',
      'rejected' => listing.rejectionReason ?? 'MundaLinq did not approve this listing. Fix it and publish again.',
      _ => '',
    };

    return _section(
      'Status',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Align(alignment: Alignment.centerLeft, child: StatusChip(listingStatus(listing))),
          const SizedBox(height: 8),
          Text(note, style: TextStyle(fontSize: 13, height: 1.5, color: listing.status == 'rejected' ? Brand.danger : Brand.ink)),
          for (final action in actions) Padding(padding: const EdgeInsets.only(top: 8), child: action),
        ],
      ),
    );
  }

  String? _variantNote(ManagedVariant variant, ManagedListing listing) {
    final notes = [
      if (variant.unit.code != listing.stockUnit.code) 'uses ${formatQuantity(variant.baseQuantity, listing.stockUnit)}',
      if (variant.hasTiers) 'bulk prices',
      if (!variant.isActive) 'hidden',
    ];
    return notes.isEmpty ? null : notes.join(' · ');
  }

  Widget _section(String title, Widget child) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(border: Border.all(color: Brand.border), borderRadius: BorderRadius.circular(Brand.radius)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [Text(title, style: Brand.display(size: 15)), const SizedBox(height: 6), child],
        ),
      );

  Widget _stockFigure(String label, String quantity, Unit unit, {bool strong = false, bool warn = false}) => Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
            Text(
              quantity,
              style: TextStyle(fontSize: strong ? 20 : 16, fontWeight: FontWeight.w800, color: warn ? Brand.danger : (strong ? Brand.green : Brand.ink)),
            ),
            Text(unit.pluralName, style: const TextStyle(fontSize: 11.5, color: Brand.mutedText)),
          ],
        ),
      );
}

extension on String {
  String ifEmpty(String fallback) => isEmpty ? fallback : this;
}

/// Add or change a way to buy: the unit, its price, and for packages how much stock one holds.
class _VariantSheet extends StatefulWidget {
  const _VariantSheet({required this.listing, required this.variant, required this.api});

  final ManagedListing listing;
  final ManagedVariant? variant;
  final ApiClient api;

  @override
  State<_VariantSheet> createState() => _VariantSheetState();
}

class _VariantSheetState extends State<_VariantSheet> {
  late String _unit = widget.variant?.unit.code ?? widget.listing.stockUnit.code;
  late final _label = TextEditingController(text: widget.variant?.label);
  late final _price = TextEditingController(text: widget.variant?.price);
  late final _base = TextEditingController(text: widget.variant?.baseQuantity);
  late final _minimum = TextEditingController(text: widget.variant?.minOrderQuantity ?? '1');
  late bool _active = widget.variant?.isActive ?? true;
  ApiException? _error;
  bool _saving = false;

  @override
  void dispose() {
    for (final controller in [_label, _price, _base, _minimum]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _save(bool needsBase) async {
    setState(() {
      _saving = true;
      _error = null;
    });
    final body = {
      'unit': _unit,
      'label': _label.text.trim().isEmpty ? null : _label.text.trim(),
      'price': _price.text.trim(),
      if (needsBase) 'base_quantity': _base.text.trim(),
      'min_order_quantity': _minimum.text.trim().isEmpty ? '1' : _minimum.text.trim(),
      'is_active': _active,
    };
    try {
      final path = '/products/${widget.listing.id}/variants';
      if (widget.variant == null) {
        await widget.api.post(path, body);
      } else {
        await widget.api.patch('$path/${widget.variant!.id}', body);
      }
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (error) {
      setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _delete() async {
    setState(() => _saving = true);
    try {
      await widget.api.delete('/products/${widget.listing.id}/variants/${widget.variant!.id}');
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (error) {
      setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final units = context.watch<CatalogController>().units;
    final stock = units[widget.listing.stockUnit.code] ?? widget.listing.stockUnit;
    final unit = units[_unit];
    final needsBase = unit != null && !unit.convertsTo(stock);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        children: [
          Text(widget.variant == null ? 'Add a way to buy' : 'Edit ${widget.variant!.name}', style: Brand.display(size: 19)),
          const SizedBox(height: 14),
          DropdownButtonFormField<String>(
            initialValue: _unit,
            decoration: InputDecoration(labelText: 'Sold per', errorText: _error?.field('unit')),
            items: [for (final option in units.values) DropdownMenuItem(value: option.code, child: Text(option.name))],
            onChanged: (code) => setState(() => _unit = code ?? _unit),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _label,
            decoration: InputDecoration(labelText: 'Name buyers see (optional)', hintText: 'e.g. Crate (about 28 kg)', errorText: _error?.field('label')),
          ),
          const SizedBox(height: 12),
          if (needsBase) ...[
            TextField(
              controller: _base,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              inputFormatters: _decimal,
              decoration: InputDecoration(
                labelText: 'How many ${stock.pluralName} in one ${unit.name}?',
                errorText: _error?.field('base_quantity'),
                errorMaxLines: 2,
              ),
            ),
            const SizedBox(height: 12),
          ],
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _price,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: _decimal,
                  decoration: InputDecoration(labelText: 'Price', prefixText: r'$ ', errorText: _error?.field('price')),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: TextField(
                  controller: _minimum,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: _decimal,
                  decoration: InputDecoration(labelText: 'Smallest order', errorText: _error?.field('min_order_quantity'), errorMaxLines: 2),
                ),
              ),
            ],
          ),
          if (widget.variant != null)
            SwitchListTile(
              value: _active,
              onChanged: (value) => setState(() => _active = value),
              contentPadding: EdgeInsets.zero,
              title: const Text('Buyers can choose this'),
            ),
          if (_error != null && _error!.fieldErrors.isEmpty)
            Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!.message, style: const TextStyle(color: Brand.danger))),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: _saving ? null : () => _save(needsBase),
            child: _saving
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Save'),
          ),
          if (widget.variant != null && widget.listing.variants.length > 1)
            TextButton(
              onPressed: _saving ? null : _delete,
              style: TextButton.styleFrom(foregroundColor: Brand.danger),
              child: const Text('Remove this way to buy'),
            ),
        ],
      ),
    );
  }
}

/// Stock received: how much, and when it was harvested.
class _StockSheet extends StatefulWidget {
  const _StockSheet({required this.listing, required this.api});

  final ManagedListing listing;
  final ApiClient api;

  @override
  State<_StockSheet> createState() => _StockSheetState();
}

class _StockSheetState extends State<_StockSheet> {
  final _quantity = TextEditingController();
  DateTime _harvested = DateTime.now();
  String? _error;
  bool _saving = false;

  @override
  void dispose() {
    _quantity.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await widget.api.post('/products/${widget.listing.id}/batches', {
        'quantity': _quantity.text.trim(),
        'harvested_on': _harvested.toIso8601String().substring(0, 10),
      });
      if (mounted) Navigator.pop(context, true);
    } catch (error) {
      setState(() => _error = errorMessage(error));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final unit = widget.listing.stockUnit;
    final today = DateTime.now();
    final isToday = formatDate(_harvested) == formatDate(today);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        children: [
          Text('Add stock', style: Brand.display(size: 19)),
          const SizedBox(height: 6),
          Text('Newly harvested ${widget.listing.title.toLowerCase()} you can sell.', style: const TextStyle(color: Brand.mutedText)),
          const SizedBox(height: 14),
          TextField(
            controller: _quantity,
            autofocus: true,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            inputFormatters: _decimal,
            decoration: InputDecoration(labelText: 'How much', suffixText: unit.pluralName, errorText: _error, errorMaxLines: 3),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 10),
          OutlinedButton.icon(
            onPressed: () async {
              final picked = await showDatePicker(
                context: context,
                initialDate: _harvested,
                firstDate: today.subtract(const Duration(days: 90)),
                lastDate: today,
              );
              if (picked != null) setState(() => _harvested = picked);
            },
            icon: const Icon(LucideIcons.calendarDays, size: 18),
            label: Text(isToday ? 'Harvested today' : 'Harvested ${formatDate(_harvested)}'),
          ),
          const SizedBox(height: 14),
          FilledButton(
            onPressed: _saving || _quantity.text.trim().isEmpty ? null : _save,
            child: _saving
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Add stock'),
          ),
        ],
      ),
    );
  }
}

/// Choosing how long to feature a listing. The price comes out of earnings that are ready to pay out.
class _FeatureSheet extends StatefulWidget {
  const _FeatureSheet({required this.api, required this.listing, required this.farmId});

  final ApiClient api;
  final ManagedListing listing;
  final String farmId;

  @override
  State<_FeatureSheet> createState() => _FeatureSheetState();
}

class _FeatureSheetState extends State<_FeatureSheet> {
  String? _pricePerWeek;
  String _currency = 'USD';
  List<int> _weeks = const [1, 2, 4];
  String? _available;
  int _chosen = 1;
  String? _error;
  bool _loading = true;
  bool _buying = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final pricing = (await widget.api.get('/featuring') as Map<String, dynamic>)['data'] as Map<String, dynamic>;
      final earnings = Earnings.fromJson((await widget.api.get('/farms/${widget.farmId}/earnings') as Map<String, dynamic>)['data'] as Map<String, dynamic>);
      if (!mounted) return;
      setState(() {
        _pricePerWeek = pricing['price_per_week'] as String;
        _currency = pricing['currency'] as String? ?? 'USD';
        _weeks = (pricing['weeks'] as List).cast<int>();
        _available = earnings.available;
      });
    } catch (error) {
      if (mounted) setState(() => _error = errorMessage(error));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _price(int weeks) => fromMinor((toMinor(_pricePerWeek ?? '0') ?? 0) * weeks);

  Future<void> _buy() async {
    setState(() {
      _buying = true;
      _error = null;
    });
    try {
      final response = await widget.api.post('/products/${widget.listing.id}/feature', {'weeks': _chosen}) as Map<String, dynamic>;
      if (!mounted) return;
      Navigator.pop(context, ManagedListing.fromJson(response['data'] as Map<String, dynamic>));
    } catch (error) {
      if (mounted) setState(() => _error = errorMessage(error));
    } finally {
      if (mounted) setState(() => _buying = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(20, 4, 20, 20 + MediaQuery.viewInsetsOf(context).bottom),
        child: _loading
            ? const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()))
            : Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text('Feature ${widget.listing.title}', style: Brand.display(size: 18)),
                  const SizedBox(height: 6),
                  const Text(
                    'It shows first in the market with a Featured badge. Paid from your earnings that are ready to pay out.',
                    style: TextStyle(color: Brand.mutedText, height: 1.5),
                  ),
                  const SizedBox(height: 14),
                  if (_pricePerWeek != null)
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        for (final weeks in _weeks)
                          ChoiceChip(
                            label: Text('$weeks week${weeks == 1 ? '' : 's'} · ${formatMoney(_price(weeks), _currency)}'),
                            selected: _chosen == weeks,
                            onSelected: (_) => setState(() => _chosen = weeks),
                            selectedColor: Brand.mint,
                            showCheckmark: false,
                            labelStyle: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700, color: _chosen == weeks ? Brand.green : Brand.ink),
                            side: BorderSide(color: _chosen == weeks ? Brand.green : Brand.border),
                          ),
                      ],
                    ),
                  if (_available != null) ...[
                    const SizedBox(height: 12),
                    Text('Available earnings: ${formatMoney(_available!, _currency)}', style: const TextStyle(fontSize: 13, color: Brand.mutedText)),
                  ],
                  if (_error != null) ...[
                    const SizedBox(height: 10),
                    Text(_error!, style: const TextStyle(color: Brand.danger, fontSize: 13, height: 1.4)),
                  ],
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: _buying || _pricePerWeek == null ? null : _buy,
                    child: _buying
                        ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : Text(_pricePerWeek == null ? 'Featuring is unavailable' : 'Feature for $_chosen week${_chosen == 1 ? '' : 's'} · ${formatMoney(_price(_chosen), _currency)}'),
                  ),
                ],
              ),
      ),
    );
  }
}
