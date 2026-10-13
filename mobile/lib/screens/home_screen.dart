import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../state/cart.dart';
import '../state/product_search.dart';
import '../state/session.dart';
import '../state/shell.dart';
import '../state/shopping_area.dart';
import '../theme.dart';
import '../widgets/category_strip.dart';
import '../widgets/common.dart';
import '../widgets/harvest_banner.dart';
import '../widgets/product_grid.dart';
import 'farm/farm_shell.dart' show NotificationBell;
import 'filter_sheet.dart';
import 'location_sheet.dart';

/// The storefront: greeting, banner, categories and the freshest listings in the buyer's area.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  late final ProductSearch _freshPicks = ProductSearch(context.read<ApiClient>(), perPage: 10);

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final area = context.watch<ShoppingAreaController>().area;
    _freshPicks.search(productQuery(const SearchFilters(sort: Sort.freshest), area?.filters ?? const {}));
  }

  @override
  void dispose() {
    _freshPicks.dispose();
    super.dispose();
  }

  void _browse({String? Function()? category, bool focusSearch = false, SearchFilters? filters}) {
    final browse = context.read<BrowseController>();
    browse.update(filters ?? (category == null ? browse.filters : browse.filters.copyWith(category: category)));
    if (focusSearch) browse.focusSearch();
    context.read<ShellController>().go(AppTab.browse);
  }

  @override
  Widget build(BuildContext context) {
    final user = context.select<Session, String?>((session) => session.user?.firstName);
    final basketCount = context.select<CartController, int>((cart) => cart.count);
    final signedIn = context.select<Session, bool>((session) => session.signedIn);

    return Scaffold(
      body: RefreshIndicator(
        color: Brand.green,
        onRefresh: () => _freshPicks.refresh(),
        child: CustomScrollView(
          slivers: [
            SliverAppBar(
              pinned: true,
              toolbarHeight: 68,
              titleSpacing: 20,
              title: const Wordmark(),
              actions: [
                if (signedIn) const NotificationBell(),
                BadgedIconButton(
                  icon: LucideIcons.shoppingBag,
                  tooltip: 'Basket',
                  count: basketCount,
                  onPressed: () => context.read<ShellController>().go(AppTab.basket),
                ),
                const SizedBox(width: 16),
              ],
            ),
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(20, 24, 20, 0),
              sliver: SliverList.list(
                children: [
                  Text(user == null ? 'YOUR LOCAL FARM MARKET' : 'WELCOME BACK, ${user.toUpperCase()}', style: Brand.eyebrow()),
                  const SizedBox(height: 8),
                  Text.rich(
                    TextSpan(
                      text: 'Good food. Fresh beginnings',
                      children: const [TextSpan(text: '.', style: TextStyle(color: Brand.green))],
                      style: Brand.display(size: 23, height: 1.35),
                    ),
                  ),
                  const SizedBox(height: 6),
                  const Text("From our farmers' hands to your home.", style: TextStyle(fontSize: 13, color: Brand.mutedText)),
                  const SizedBox(height: 8),
                  const Align(alignment: Alignment.centerLeft, child: ShoppingNearButton()),
                  const SizedBox(height: 14),
                  _SearchRow(
                    onSearch: () => _browse(focusSearch: true),
                    onFilter: () async {
                      final browse = context.read<BrowseController>();
                      final chosen = await showFilterSheet(context, browse.filters);
                      if (chosen != null) _browse(filters: chosen);
                    },
                  ),
                  const SizedBox(height: 18),
                  HarvestBanner(onShop: () => _browse(category: () => null)),
                  const _PromiseStrip(),
                  const SizedBox(height: 18),
                  Text('Shop by category', style: Brand.display(size: 17)),
                  const SizedBox(height: 12),
                ],
              ),
            ),
            SliverToBoxAdapter(
              child: CategoryStrip(selected: null, onSelect: (slug) => _browse(category: () => slug)),
            ),
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(20, 18, 20, 14),
              sliver: SliverToBoxAdapter(
                child: SectionHeading(
                  eyebrow: 'FRESH IN THE MARKET',
                  title: 'Fresh picks of the day',
                  trailing: TextButton(
                    onPressed: () => _browse(category: () => null),
                    child: const Row(mainAxisSize: MainAxisSize.min, children: [Text('See all'), SizedBox(width: 4), Icon(LucideIcons.arrowRight, size: 15)]),
                  ),
                ),
              ),
            ),
            ListenableBuilder(
              listenable: _freshPicks,
              builder: (context, _) {
                if (_freshPicks.initialLoading) return const ProductGridSkeleton();
                if (_freshPicks.error != null && _freshPicks.items.isEmpty) {
                  return SliverToBoxAdapter(
                    child: EmptyState(
                      icon: LucideIcons.wifiOff,
                      title: "We couldn't load the market",
                      message: errorMessage(_freshPicks.error!),
                      actions: [FilledButton(onPressed: _freshPicks.refresh, child: const Text('Try again'))],
                    ),
                  );
                }
                if (_freshPicks.items.isEmpty) {
                  return const SliverToBoxAdapter(
                    child: EmptyState(
                      icon: LucideIcons.sprout,
                      title: 'Nothing listed here yet',
                      message: 'Farms in this area have nothing for sale right now. Try a wider area.',
                      actions: [AreaEmptyHint()],
                    ),
                  );
                }
                return ProductGrid(products: _freshPicks.items);
              },
            ),
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(20, 18, 20, 0),
              sliver: SliverList.list(
                children: [
                  OutlinedButton(onPressed: () => _browse(category: () => null), child: const Text('Browse all produce')),
                  const SizedBox(height: 26),
                  const _LocalBand(),
                  const SizedBox(height: 20),
                  const Text(
                    '© MundaLinq · Fresh from local farms',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 11, color: Brand.mutedText),
                  ),
                  const SizedBox(height: 28),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SearchRow extends StatelessWidget {
  const _SearchRow({required this.onSearch, required this.onFilter});

  final VoidCallback onSearch;
  final VoidCallback onFilter;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Material(
            color: Brand.muted,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Brand.radius), side: const BorderSide(color: Brand.border)),
            child: InkWell(
              onTap: onSearch,
              borderRadius: BorderRadius.circular(Brand.radius),
              child: const SizedBox(
                height: 48,
                child: Row(
                  children: [
                    SizedBox(width: 14),
                    Icon(LucideIcons.search, size: 19, color: Brand.mutedText),
                    SizedBox(width: 10),
                    Text('Search tomatoes, maize, eggs…', style: TextStyle(fontSize: 13, color: Brand.mutedText)),
                  ],
                ),
              ),
            ),
          ),
        ),
        const SizedBox(width: 10),
        IconButton.outlined(
          onPressed: onFilter,
          tooltip: 'Sort and filter',
          style: IconButton.styleFrom(
            fixedSize: const Size(48, 48),
            side: const BorderSide(color: Brand.border),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Brand.radius)),
          ),
          icon: const Icon(LucideIcons.slidersHorizontal, size: 20),
        ),
      ],
    );
  }
}

class _PromiseStrip extends StatelessWidget {
  const _PromiseStrip();

  @override
  Widget build(BuildContext context) {
    Widget item(IconData icon, String text) => Row(
          mainAxisSize: MainAxisSize.min,
          children: [Icon(icon, size: 15, color: Brand.green), const SizedBox(width: 5), Text(text, style: const TextStyle(fontSize: 11, color: Brand.mutedText))],
        );

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 16),
      decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: Brand.border))),
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: Row(
          children: [
            item(LucideIcons.sprout, 'Farm fresh'),
            const SizedBox(width: 22),
            item(LucideIcons.circleCheck, 'Verified farms'),
            const SizedBox(width: 22),
            item(LucideIcons.heart, 'Support local'),
          ],
        ),
      ),
    );
  }
}

class _LocalBand extends StatelessWidget {
  const _LocalBand();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      color: Brand.mint,
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle),
            child: const Icon(LucideIcons.sprout, size: 20, color: Brand.green),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Small farms. Big heart.', style: Brand.display(size: 14, color: Brand.green)),
                const SizedBox(height: 4),
                const Text(
                  'Every basket brings you closer to the people who grow your food.',
                  style: TextStyle(fontSize: 12, height: 1.5, color: Brand.mutedText),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
