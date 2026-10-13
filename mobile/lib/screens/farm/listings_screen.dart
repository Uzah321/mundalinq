import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/farm_models.dart';
import '../../api/models.dart';
import '../../state/farm.dart';
import '../../state/paged_list.dart';
import '../../theme.dart';
import '../../util/farm_status.dart';
import '../../util/format.dart';
import '../../widgets/category_visual.dart';
import '../../widgets/common.dart';
import '../order_screen.dart' show StatusChip;
import 'farm_shell.dart';
import 'listing_form_screen.dart';
import 'listing_screen.dart';

const _filters = [
  (null, 'All'),
  ('active', 'Live'),
  ('draft', 'Drafts'),
  ('pending_review', 'In review'),
  ('paused', 'Paused'),
  ('low_stock', 'Low stock'),
];

/// Every listing of the farm, whatever its status.
class ListingsScreen extends StatefulWidget {
  const ListingsScreen({super.key});

  @override
  State<ListingsScreen> createState() => _ListingsScreenState();
}

class _ListingsScreenState extends State<ListingsScreen> {
  String? _filter;
  late final PagedList<ManagedListing> _listings = PagedList(_fetch);

  Future<Paged<ManagedListing>> _fetch(int page) async {
    final farmId = context.read<FarmController>().farm!.id;
    return Paged.fromJson(
      await context.read<ApiClient>().get('/farms/$farmId/products', query: {
        'page': page,
        if (_filter == 'low_stock') 'low_stock': 1 else 'status': _filter,
      }) as Map<String, dynamic>,
      ManagedListing.fromJson,
    );
  }

  @override
  void initState() {
    super.initState();
    _listings.refresh();
  }

  @override
  void dispose() {
    _listings.dispose();
    super.dispose();
  }

  Future<void> _open(String listingId) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ListingScreen(listingId: listingId)));
    if (mounted) _listings.refresh();
  }

  Future<void> _create() async {
    final created = await Navigator.of(context).push<ManagedListing>(MaterialPageRoute(builder: (_) => const ListingFormScreen()));
    if (created != null && mounted) await _open(created.id);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(toolbarHeight: 64, titleSpacing: 20, title: const FarmTitle(subtitle: 'Listings'), actions: const [NotificationBell()]),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _create,
        backgroundColor: Brand.green,
        foregroundColor: Colors.white,
        icon: const Icon(LucideIcons.plus),
        label: const Text('New listing', style: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700)),
      ),
      body: Column(
        children: [
          SizedBox(
            height: 52,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
              children: [
                for (final (value, label) in _filters)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: Text(label),
                      selected: _filter == value,
                      onSelected: (_) {
                        setState(() => _filter = value);
                        _listings.refresh();
                      },
                      selectedColor: Brand.mint,
                      backgroundColor: Colors.white,
                      showCheckmark: false,
                      labelStyle: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700, color: _filter == value ? Brand.green : Brand.ink),
                      side: BorderSide(color: _filter == value ? Brand.green : Brand.border),
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _listings.refresh,
              child: ListenableBuilder(
                listenable: _listings,
                builder: (context, _) {
                  if (_listings.initialLoading) return const Center(child: CircularProgressIndicator());
                  if (_listings.error != null && _listings.items.isEmpty) {
                    return ListView(children: [
                      EmptyState(
                        icon: LucideIcons.wifiOff,
                        title: "We couldn't load your listings",
                        message: errorMessage(_listings.error!),
                        actions: [FilledButton(onPressed: _listings.refresh, child: const Text('Try again'))],
                      ),
                    ]);
                  }
                  if (_listings.items.isEmpty) {
                    return ListView(children: [
                      EmptyState(
                        icon: LucideIcons.package,
                        title: _filter == null ? 'List your first produce' : 'Nothing here',
                        message: _filter == null
                            ? 'Add what you have for sale: a photo, a price and how much you have.'
                            : 'Listings with this status show up here.',
                        actions: [if (_filter == null) FilledButton(onPressed: _create, child: const Text('New listing'))],
                      ),
                    ]);
                  }
                  return ListView.separated(
                    padding: const EdgeInsets.fromLTRB(20, 8, 20, 96),
                    itemCount: _listings.items.length + (_listings.hasMore ? 1 : 0),
                    separatorBuilder: (_, _) => const SizedBox(height: 10),
                    itemBuilder: (context, index) {
                      if (index == _listings.items.length) {
                        _listings.loadMore();
                        return const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator()));
                      }
                      final listing = _listings.items[index];
                      return _ListingTile(listing: listing, onTap: () => _open(listing.id));
                    },
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ListingTile extends StatelessWidget {
  const _ListingTile({required this.listing, required this.onTap});

  final ManagedListing listing;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final cheapest = listing.cheapest;
    final unit = Unit(code: listing.stock.unit, name: listing.stockUnit.name, pluralName: listing.stockUnit.pluralName);

    return Material(
      color: Colors.white,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Brand.radius), side: const BorderSide(color: Brand.border)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Row(
          children: [
            SizedBox.square(
              dimension: 92,
              child: listing.coverImage == null
                  ? ProductPlaceholder(categorySlug: listing.categorySlug, iconSize: 28)
                  : CachedNetworkImage(imageUrl: listing.coverImage!, fit: BoxFit.cover),
            ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(listing.title, maxLines: 1, overflow: TextOverflow.ellipsis, style: Brand.display(size: 14)),
                    const SizedBox(height: 3),
                    Text(
                      [
                        if (cheapest != null) '${formatMoney(cheapest.price, listing.currency)} / ${cheapest.unit.code}',
                        '${formatQuantity(listing.stock.available, unit)} left',
                      ].join(' · '),
                      style: TextStyle(fontSize: 12.5, color: listing.stock.isLow ? Brand.danger : Brand.mutedText),
                    ),
                    const SizedBox(height: 8),
                    StatusChip(listingStatus(listing)),
                  ],
                ),
              ),
            ),
            const Padding(padding: EdgeInsets.only(right: 10), child: Icon(LucideIcons.chevronRight, size: 18, color: Brand.mutedText)),
          ],
        ),
      ),
    );
  }
}
