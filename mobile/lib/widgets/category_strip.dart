import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../state/catalog.dart';
import '../theme.dart';
import 'category_visual.dart';

/// Round category shortcuts that scroll sideways, starting with "All products".
class CategoryStrip extends StatelessWidget {
  const CategoryStrip({super.key, required this.selected, required this.onSelect});

  /// Top-level category slug, or null for all products.
  final String? selected;
  final ValueChanged<String?> onSelect;

  @override
  Widget build(BuildContext context) {
    final categories = context.watch<CatalogController>().categories;

    return SizedBox(
      height: 112,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 14),
        children: [
          _CategoryItem(
            label: 'All products',
            icon: LucideIcons.layoutGrid,
            selected: selected == null,
            onTap: () => onSelect(null),
          ),
          for (final category in categories)
            _CategoryItem(
              label: category.name,
              icon: categoryIcon(category.slug),
              photo: categoryPhoto(category.slug),
              selected: selected == category.slug,
              onTap: () => onSelect(category.slug),
            ),
        ],
      ),
    );
  }
}

class _CategoryItem extends StatelessWidget {
  const _CategoryItem({required this.label, required this.icon, required this.selected, required this.onTap, this.photo});

  final String label;
  final IconData icon;
  final String? photo;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 76,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(Brand.radius),
        child: Column(
          children: [
            const SizedBox(height: 4),
            Container(
              width: 58,
              height: 58,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: selected ? Brand.mint : Brand.muted,
                border: Border.all(color: selected ? Brand.green : Colors.transparent, width: 2),
              ),
              clipBehavior: Clip.antiAlias,
              child: photo != null
                  ? Image.asset(photo!, fit: BoxFit.cover, color: Brand.muted, colorBlendMode: BlendMode.multiply)
                  : Icon(icon, size: 22, color: Brand.green),
            ),
            const SizedBox(height: 7),
            Text(
              label,
              textAlign: TextAlign.center,
              maxLines: 2,
              style: TextStyle(
                fontSize: 11,
                height: 1.3,
                fontWeight: selected ? FontWeight.w800 : FontWeight.w500,
                color: selected ? Brand.green : Brand.mutedText,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Text chips for the Browse tab: "All", then each top-level category.
class CategoryChips extends StatelessWidget {
  const CategoryChips({super.key, required this.selected, required this.onSelect});

  final String? selected;
  final ValueChanged<String?> onSelect;

  @override
  Widget build(BuildContext context) {
    final catalog = context.watch<CatalogController>();
    final root = selected == null ? null : catalog.rootOf(selected!);

    return SizedBox(
      height: 44,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        children: [
          _chip('All', root == null, () => onSelect(null)),
          for (final category in catalog.categories) _chip(category.name, root == category.slug, () => onSelect(category.slug)),
        ],
      ),
    );
  }

  Widget _chip(String label, bool selected, VoidCallback onTap) => Padding(
        padding: const EdgeInsets.only(right: 8),
        child: ChoiceChip(
          label: Text(label),
          selected: selected,
          onSelected: (_) => onTap(),
          selectedColor: Brand.mint,
          backgroundColor: Colors.white,
          showCheckmark: false,
          labelStyle: TextStyle(fontFamily: 'Manrope', fontSize: 13, fontWeight: FontWeight.w700, color: selected ? Brand.green : Brand.ink),
          side: BorderSide(color: selected ? Brand.green : Brand.border),
        ),
      );
}
