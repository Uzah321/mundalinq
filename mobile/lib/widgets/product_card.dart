import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../api/models.dart';
import '../theme.dart';
import '../util/format.dart';
import 'category_visual.dart';

/// A listing in a grid: photo, badge, farm, price, and an add button that becomes a quantity stepper
/// once the listing is in the basket.
class ProductTile extends StatelessWidget {
  const ProductTile({
    super.key,
    required this.product,
    required this.favourite,
    required this.basketLines,
    required this.adding,
    required this.basketBusy,
    required this.onOpen,
    required this.onAdd,
    required this.onStep,
    required this.onToggleFavourite,
  });

  final ProductCard product;
  final bool favourite;
  final List<CartLine> basketLines;
  final bool adding;
  final bool basketBusy;
  final VoidCallback onOpen;
  final VoidCallback onAdd;
  final void Function(int direction) onStep;
  final VoidCallback onToggleFavourite;

  @override
  Widget build(BuildContext context) {
    final badge = _badge();
    final place = product.distanceKm != null ? '${product.distanceKm} km away' : product.district;
    final unit = product.unitCode == null ? place : 'per ${product.unitCode} · $place';

    return Material(
      color: Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Brand.radius),
        side: const BorderSide(color: Brand.border),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onOpen,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            AspectRatio(
              aspectRatio: 1.13,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  product.imageUrl == null
                      ? ProductPlaceholder(categorySlug: product.categorySlug)
                      : CachedNetworkImage(
                          imageUrl: product.imageUrl!,
                          fit: BoxFit.cover,
                          // Like the web's mix-blend-mode: multiply, so white photo backgrounds sit on the tint.
                          color: Brand.muted,
                          colorBlendMode: BlendMode.multiply,
                          placeholder: (_, _) => const ColoredBox(color: Brand.muted),
                          errorWidget: (_, _, _) => ProductPlaceholder(categorySlug: product.categorySlug),
                        ),
                  if (badge != null) Positioned(top: 9, left: 8, child: badge),
                  Positioned(
                    top: 4,
                    right: 4,
                    child: IconButton(
                      onPressed: onToggleFavourite,
                      tooltip: favourite ? 'Remove from favourites' : 'Save to favourites',
                      style: IconButton.styleFrom(backgroundColor: Colors.white, fixedSize: const Size(32, 32), minimumSize: const Size(32, 32), padding: EdgeInsets.zero),
                      icon: Icon(
                        favourite ? Icons.favorite : LucideIcons.heart,
                        size: 15,
                        color: favourite ? Brand.green : Brand.mutedText,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(11, 10, 11, 11),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Icon(LucideIcons.sprout, size: 11, color: Brand.green),
                      const SizedBox(width: 4),
                      Expanded(
                        child: Text(
                          product.farmName,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontSize: 10, color: Brand.mutedText),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  SizedBox(
                    height: 34,
                    child: Text(
                      product.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Brand.display(size: 13, height: 1.3),
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(unit, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 10.5, color: Brand.mutedText)),
                  const SizedBox(height: 8),
                  SizedBox(
                    height: 34,
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            product.price == null ? '—' : formatMoney(product.price!, product.currency),
                            style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: Brand.green),
                          ),
                        ),
                        _basketControl(),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _basketControl() {
    // One selling option in the basket: step it here. Several: the listing sheet handles them.
    if (basketLines.length == 1) {
      final line = basketLines.first;
      return Container(
        decoration: BoxDecoration(color: Brand.mint, borderRadius: BorderRadius.circular(6)),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            _stepButton(LucideIcons.minus, 'One less', () => onStep(-1)),
            ConstrainedBox(
              constraints: const BoxConstraints(minWidth: 18),
              child: Text(line.quantity, textAlign: TextAlign.center, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800)),
            ),
            _stepButton(LucideIcons.plus, 'One more', () => onStep(1)),
          ],
        ),
      );
    }

    return SizedBox.square(
      dimension: 34,
      child: FilledButton(
        onPressed: adding ? null : (basketLines.length > 1 ? onOpen : onAdd),
        style: FilledButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(34, 34), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6))),
        child: adding
            ? const SizedBox.square(dimension: 14, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
            : Icon(basketLines.length > 1 ? LucideIcons.shoppingBag : LucideIcons.plus, size: 17),
      ),
    );
  }

  Widget _stepButton(IconData icon, String tooltip, VoidCallback onPressed) => IconButton(
        onPressed: basketBusy ? null : onPressed,
        tooltip: tooltip,
        style: IconButton.styleFrom(fixedSize: const Size(30, 34), minimumSize: const Size(30, 34), padding: EdgeInsets.zero),
        icon: Icon(icon, size: 14, color: Brand.green),
      );

  Widget? _badge() {
    if (product.featured) return const _Badge(label: 'Featured', warm: true);
    final harvested = product.harvestedOn;
    if (harvested != null && daysSince(harvested) <= 2) {
      return const _Badge(label: 'Fresh pick', warm: true);
    }
    if (product.grade == 'A') return const _Badge(label: 'Grade A', icon: LucideIcons.leaf);
    return null;
  }
}

class _Badge extends StatelessWidget {
  const _Badge({required this.label, this.icon, this.warm = false});

  final String label;
  final IconData? icon;
  final bool warm;

  @override
  Widget build(BuildContext context) {
    final color = warm ? Brand.harvestText : Brand.green;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
      decoration: BoxDecoration(color: warm ? Brand.harvest : Brand.mint, borderRadius: BorderRadius.circular(3)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[Icon(icon, size: 10, color: color), const SizedBox(width: 3)],
          Text(label, style: TextStyle(fontSize: 9, fontWeight: FontWeight.w700, color: color)),
        ],
      ),
    );
  }
}
