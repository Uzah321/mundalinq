import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/farm_models.dart';
import '../state/product_search.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/product_grid.dart';
import '../widgets/reviews.dart';
import 'messages/chat_screen.dart';
import 'store_actions.dart';

/// A farm as buyers see it: who they are, what they sell now, and what other buyers said.
class FarmPageScreen extends StatefulWidget {
  const FarmPageScreen({super.key, required this.farmId, required this.farmName});

  final String farmId;
  final String farmName;

  @override
  State<FarmPageScreen> createState() => _FarmPageScreenState();
}

class _FarmPageScreenState extends State<FarmPageScreen> {
  late final ProductSearch _listings = ProductSearch(context.read<ApiClient>());
  Farm? _farm;
  List<Review> _reviews = const [];
  ReviewSummary? _summary;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _listings.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final api = context.read<ApiClient>();
    _listings.search({'farm_id': widget.farmId, 'sort': 'newest'});
    try {
      final results = await Future.wait([api.get('/farms/${widget.farmId}'), api.get('/farms/${widget.farmId}/reviews')]);
      if (!mounted) return;
      final reviews = results[1] as Map<String, dynamic>;
      setState(() {
        _farm = Farm.fromJson((results[0] as Map<String, dynamic>)['data'] as Map<String, dynamic>);
        _reviews = (reviews['data'] as List).cast<Map<String, dynamic>>().map(Review.fromJson).take(3).toList();
        _summary = ReviewSummary.fromJson((reviews['meta'] as Map<String, dynamic>?)?['summary'] as Map<String, dynamic>?);
      });
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    }
  }

  Future<void> _message() async {
    if (!await ensureSignedIn(context, 'Sign in to message ${widget.farmName}.')) return;
    if (!mounted) return;
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ChatScreen.forFarm(farmId: widget.farmId, title: widget.farmName)));
  }

  @override
  Widget build(BuildContext context) {
    final farm = _farm;
    return Scaffold(
      appBar: AppBar(title: Text(widget.farmName)),
      body: CustomScrollView(
        slivers: [
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
            sliver: SliverList.list(
              children: [
                Row(
                  children: [
                    Container(
                      width: 56,
                      height: 56,
                      decoration: const BoxDecoration(color: Brand.mint, shape: BoxShape.circle),
                      child: const Icon(LucideIcons.sprout, color: Brand.green, size: 26),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(widget.farmName, style: Brand.display(size: 19)),
                          if (farm != null)
                            Text(
                              [
                                farmerTypes[farm.farmerType] ?? '',
                                if (farm.location != null) '${farm.location!.district}, ${farm.location!.province}',
                              ].join(' · '),
                              style: const TextStyle(color: Brand.mutedText, fontSize: 13),
                            ),
                        ],
                      ),
                    ),
                  ],
                ),
                if (farm != null) ...[
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    children: [
                      if (farm.verified) const Tag(label: 'Verified farm', icon: LucideIcons.badgeCheck),
                      if (farm.offersPickup) const Tag(label: 'Collection', icon: LucideIcons.store),
                      if (farm.offersDelivery)
                        Tag(label: 'Delivers within ${farm.deliveryRadiusKm?.round()} km', icon: LucideIcons.truck),
                      if (farm.rating.count > 0)
                        Tag(label: '${farm.rating.average?.toStringAsFixed(1)} ★ (${farm.rating.count})', warm: true),
                    ],
                  ),
                  if (farm.description != null) ...[
                    const SizedBox(height: 12),
                    Text(farm.description!, style: const TextStyle(height: 1.6, color: Brand.mutedText)),
                  ],
                ],
                const SizedBox(height: 14),
                OutlinedButton.icon(onPressed: _message, icon: const Icon(LucideIcons.messageCircle, size: 18), label: const Text('Message the farm')),
                const SizedBox(height: 20),
                Text('For sale now', style: Brand.display(size: 17)),
                const SizedBox(height: 12),
              ],
            ),
          ),
          ListenableBuilder(
            listenable: _listings,
            builder: (context, _) {
              if (_listings.initialLoading) return const ProductGridSkeleton(count: 2);
              if (_listings.items.isEmpty) {
                return const SliverToBoxAdapter(
                  child: Padding(
                    padding: EdgeInsets.symmetric(horizontal: 20),
                    child: Text('Nothing listed right now. Check back after the next harvest.', style: TextStyle(color: Brand.mutedText)),
                  ),
                );
              }
              return ProductGrid(products: _listings.items);
            },
          ),
          if (_summary != null && _summary!.count > 0)
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
              sliver: SliverList.list(
                children: [
                  Text('What buyers say', style: Brand.display(size: 17)),
                  const SizedBox(height: 12),
                  ReviewSummaryView(summary: _summary!),
                  for (final review in _reviews) ReviewTile(review: review),
                ],
              ),
            )
          else
            const SliverToBoxAdapter(child: SizedBox(height: 32)),
        ],
      ),
    );
  }
}
