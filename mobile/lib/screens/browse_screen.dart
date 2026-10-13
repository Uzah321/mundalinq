import 'dart:async';

import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../state/catalog.dart';
import '../state/product_search.dart';
import '../state/shopping_area.dart';
import '../theme.dart';
import '../widgets/category_strip.dart';
import '../widgets/common.dart';
import '../widgets/product_grid.dart';
import 'filter_sheet.dart';
import 'location_sheet.dart';

/// Search and browse every listing, filtered by category, area, sort and delivery.
class BrowseScreen extends StatefulWidget {
  const BrowseScreen({super.key});

  @override
  State<BrowseScreen> createState() => _BrowseScreenState();
}

class _BrowseScreenState extends State<BrowseScreen> {
  late final ProductSearch _results = ProductSearch(context.read<ApiClient>());
  final _searchField = TextEditingController();
  final _searchFocus = FocusNode();
  final _scroll = ScrollController();
  Timer? _debounce;
  int _handledFocusRequests = 0;

  @override
  void initState() {
    super.initState();
    _searchField.text = context.read<BrowseController>().filters.query;
    _scroll.addListener(() {
      if (_scroll.position.extentAfter < 600) _results.loadMore();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final browse = context.watch<BrowseController>();
    final area = context.watch<ShoppingAreaController>().area;

    if (browse.filters.query != _searchField.text && !_searchFocus.hasFocus) _searchField.text = browse.filters.query;
    if (browse.focusRequests != _handledFocusRequests) {
      _handledFocusRequests = browse.focusRequests;
      WidgetsBinding.instance.addPostFrameCallback((_) => _searchFocus.requestFocus());
    }

    _results.search(productQuery(browse.filters, area?.filters ?? const {}));
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _results.dispose();
    _searchField.dispose();
    _searchFocus.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _onQueryChanged(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () {
      final browse = context.read<BrowseController>();
      browse.update(browse.filters.copyWith(query: value));
    });
  }

  @override
  Widget build(BuildContext context) {
    final browse = context.watch<BrowseController>();
    final filters = browse.filters;
    final categoryName = filters.category == null ? null : context.watch<CatalogController>().nameOf(filters.category!);

    return Scaffold(
      appBar: AppBar(
        toolbarHeight: 72,
        titleSpacing: 16,
        title: Row(
          children: [
            Expanded(
              child: TextField(
                controller: _searchField,
                focusNode: _searchFocus,
                textInputAction: TextInputAction.search,
                onChanged: _onQueryChanged,
                onSubmitted: (value) {
                  _debounce?.cancel();
                  browse.update(filters.copyWith(query: value));
                },
                style: const TextStyle(fontSize: 14),
                decoration: InputDecoration(
                  hintText: 'Search tomatoes, maize, eggs…',
                  prefixIcon: const Icon(LucideIcons.search, size: 19, color: Brand.mutedText),
                  suffixIcon: ListenableBuilder(
                    listenable: _searchField,
                    builder: (context, _) => _searchField.text.isEmpty
                        ? const SizedBox.shrink()
                        : IconButton(
                            tooltip: 'Clear search',
                            icon: const Icon(LucideIcons.x, size: 18),
                            onPressed: () {
                              _searchField.clear();
                              browse.update(filters.copyWith(query: ''));
                            },
                          ),
                  ),
                  contentPadding: const EdgeInsets.symmetric(vertical: 12),
                ),
              ),
            ),
            const SizedBox(width: 10),
            Stack(
              children: [
                IconButton.outlined(
                  onPressed: () async {
                    final chosen = await showFilterSheet(context, filters);
                    if (chosen != null) browse.update(chosen);
                  },
                  tooltip: 'Sort and filter',
                  style: IconButton.styleFrom(
                    fixedSize: const Size(48, 48),
                    side: const BorderSide(color: Brand.border),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Brand.radius)),
                  ),
                  icon: const Icon(LucideIcons.slidersHorizontal, size: 20),
                ),
                if (filters.refined)
                  Positioned(
                    top: 9,
                    right: 9,
                    child: Container(
                      width: 9,
                      height: 9,
                      decoration: BoxDecoration(color: Brand.harvest, shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 1.5)),
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
      body: RefreshIndicator(
        color: Brand.green,
        onRefresh: _results.refresh,
        child: CustomScrollView(
          controller: _scroll,
          keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
          slivers: [
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.only(top: 12),
                child: CategoryChips(
                  selected: filters.category,
                  onSelect: (slug) => browse.update(filters.copyWith(category: () => slug)),
                ),
              ),
            ),
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
              sliver: SliverToBoxAdapter(
                child: ListenableBuilder(
                  listenable: _results,
                  builder: (context, _) => Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _heading(filters.query, categoryName, _results),
                        style: Brand.display(size: 17),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const ShoppingNearButton(),
                    ],
                  ),
                ),
              ),
            ),
            ListenableBuilder(
              listenable: _results,
              builder: (context, _) {
                if (_results.initialLoading) return const ProductGridSkeleton(count: 6);
                if (_results.error != null && _results.items.isEmpty) {
                  return SliverToBoxAdapter(
                    child: EmptyState(
                      icon: LucideIcons.wifiOff,
                      title: "We couldn't load the market",
                      message: errorMessage(_results.error!),
                      actions: [FilledButton(onPressed: _results.refresh, child: const Text('Try again'))],
                    ),
                  );
                }
                if (_results.items.isEmpty) {
                  return SliverToBoxAdapter(
                    child: EmptyState(
                      icon: LucideIcons.searchX,
                      title: 'Nothing matches yet',
                      message: 'Try another word, a different category, or a wider area.',
                      actions: [
                        OutlinedButton(
                          onPressed: () {
                            _searchField.clear();
                            browse.update(const SearchFilters());
                          },
                          child: const Text('Clear filters'),
                        ),
                        const AreaEmptyHint(),
                      ],
                    ),
                  );
                }
                return ProductGrid(products: _results.items);
              },
            ),
            SliverToBoxAdapter(
              child: ListenableBuilder(
                listenable: _results,
                builder: (context, _) => Padding(
                  padding: const EdgeInsets.fromLTRB(20, 20, 20, 32),
                  child: Center(
                    child: _results.items.isNotEmpty && _results.loading
                        ? const CircularProgressIndicator()
                        : (_results.error != null && _results.items.isNotEmpty
                            ? TextButton(onPressed: _results.loadMore, child: const Text('Couldn’t load more. Try again'))
                            : const SizedBox.shrink()),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  String _heading(String query, String? category, ProductSearch results) {
    final count = results.initialLoading ? '' : ' · ${results.total}';
    if (query.trim().isNotEmpty) return 'Results for “${query.trim()}”$count';
    return '${category ?? 'All produce'}$count';
  }
}
