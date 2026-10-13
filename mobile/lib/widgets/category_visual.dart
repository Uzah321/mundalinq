import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../state/catalog.dart';
import '../theme.dart';

const _icons = <String, IconData>{
  'vegetables': LucideIcons.carrot,
  'fruit': LucideIcons.apple,
  'grains-and-cereals': LucideIcons.wheat,
  'legumes-and-nuts': LucideIcons.bean,
  'livestock': LucideIcons.beef,
  'eggs-and-dairy': LucideIcons.egg,
  'honey': LucideIcons.hexagon,
  'herbs-and-spices': LucideIcons.leaf,
};

/// Top-level categories with a photo in assets/images/categories; the rest show their icon.
const _photos = {'vegetables', 'fruit', 'eggs-and-dairy'};

IconData categoryIcon(String rootSlug) => _icons[rootSlug] ?? LucideIcons.sprout;

String? categoryPhoto(String rootSlug) =>
    _photos.contains(rootSlug) ? 'assets/images/categories/$rootSlug.jpg' : null;

/// Stands in for a listing without photos: its category's icon on the muted background.
class ProductPlaceholder extends StatelessWidget {
  const ProductPlaceholder({super.key, required this.categorySlug, this.iconSize = 40});

  final String categorySlug;
  final double iconSize;

  @override
  Widget build(BuildContext context) {
    final root = context.select<CatalogController, String>((catalog) => catalog.rootOf(categorySlug));
    return ColoredBox(
      color: Brand.muted,
      child: Center(
        child: Icon(categoryIcon(root), size: iconSize, color: Brand.green.withValues(alpha: 0.4)),
      ),
    );
  }
}
