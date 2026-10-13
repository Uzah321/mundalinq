import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api/models.dart';
import '../screens/store_actions.dart';
import '../state/cart.dart';
import '../state/favourites.dart';
import '../state/shell.dart';
import '../theme.dart';
import 'product_card.dart';

/// Two columns of listings on phones, more on tablets.
class ProductGrid extends StatelessWidget {
  const ProductGrid({super.key, required this.products, this.padding = const EdgeInsets.symmetric(horizontal: 20)});

  final List<ProductCard> products;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartController>();
    final favourites = context.watch<FavouritesController>();
    final adding = context.watch<QuickAddController>();
    // Tiles have fixed text rows, so very large system text is capped to keep them from overflowing.
    final textScaler = MediaQuery.textScalerOf(context).clamp(maxScaleFactor: 1.15);

    return SliverPadding(
      padding: padding,
      sliver: SliverLayoutBuilder(
        builder: (context, constraints) {
          final width = constraints.crossAxisExtent;
          final columns = width >= 900 ? 4 : (width >= 600 ? 3 : 2);
          const spacing = 13.0;
          final tileWidth = (width - spacing * (columns - 1)) / columns;
          final tileHeight = tileWidth / 1.13 + textScaler.scale(1) * 128 + 14;

          return SliverGrid(
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: columns,
              mainAxisSpacing: spacing,
              crossAxisSpacing: spacing,
              mainAxisExtent: tileHeight,
            ),
            delegate: SliverChildBuilderDelegate(
              childCount: products.length,
              (context, index) {
                final product = products[index];
                final lines = cart.linesFor(product.id);
                return MediaQuery(
                  data: MediaQuery.of(context).copyWith(textScaler: textScaler),
                  child: ProductTile(
                    product: product,
                    favourite: favourites.has(product.id),
                    basketLines: lines,
                    adding: adding.isAdding(product.id),
                    basketBusy: cart.busy,
                    onOpen: () => openProduct(context, product.id),
                    onAdd: () => quickAdd(context, product),
                    onStep: (direction) => stepLine(context, lines.first, direction),
                    onToggleFavourite: () => toggleFavourite(context, product.id, product.title),
                  ),
                );
              },
            ),
          );
        },
      ),
    );
  }
}

/// Placeholder tiles while the first page loads.
class ProductGridSkeleton extends StatelessWidget {
  const ProductGridSkeleton({super.key, this.count = 4});

  final int count;

  @override
  Widget build(BuildContext context) {
    return SliverPadding(
      padding: const EdgeInsets.symmetric(horizontal: 20),
      sliver: SliverGrid(
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: 2, mainAxisSpacing: 13, crossAxisSpacing: 13, childAspectRatio: 0.62),
        delegate: SliverChildBuilderDelegate(
          childCount: count,
          (context, index) => Container(
            decoration: BoxDecoration(
              border: Border.all(color: Brand.border),
              borderRadius: BorderRadius.circular(Brand.radius),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const AspectRatio(aspectRatio: 1.13, child: ColoredBox(color: Brand.muted)),
                Padding(
                  padding: const EdgeInsets.all(11),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      for (final width in [70.0, 110.0, 60.0]) ...[
                        Container(width: width, height: 10, decoration: BoxDecoration(color: Brand.muted, borderRadius: BorderRadius.circular(4))),
                        const SizedBox(height: 10),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
