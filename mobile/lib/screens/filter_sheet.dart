import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../state/product_search.dart';
import '../state/shopping_area.dart';
import '../theme.dart';

/// Sort order and delivery/pickup. Returns the chosen filters, or null if dismissed.
Future<SearchFilters?> showFilterSheet(BuildContext context, SearchFilters current) {
  return showModalBottomSheet<SearchFilters>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (_) => _FilterSheet(current: current),
  );
}

class _FilterSheet extends StatefulWidget {
  const _FilterSheet({required this.current});

  final SearchFilters current;

  @override
  State<_FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends State<_FilterSheet> {
  late Sort _sort = widget.current.sort;
  late Fulfilment? _fulfilment = widget.current.fulfilment;

  @override
  Widget build(BuildContext context) {
    final nearby = context.select<ShoppingAreaController, bool>((area) => area.area?.isNearby ?? false);

    return ListView(
      shrinkWrap: true,
      padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.paddingOf(context).bottom),
      children: [
        Text('Sort and filter', style: Brand.display(size: 19)),
        const SizedBox(height: 16),
        const Text('Sort by', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
        RadioGroup<Sort>(
          groupValue: _sort,
          onChanged: (sort) => setState(() => _sort = sort ?? Sort.newest),
          child: Column(
            children: [
              for (final sort in Sort.values)
                // "Nearest" needs a location: choose "Near me" in the shopping area first.
                if (sort != Sort.nearest || nearby)
                  RadioListTile<Sort>(value: sort, title: Text(sort.label), contentPadding: EdgeInsets.zero, dense: true),
            ],
          ),
        ),
        const SizedBox(height: 10),
        const Text('Getting it', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            _chip('Any way', _fulfilment == null, () => setState(() => _fulfilment = null)),
            for (final option in Fulfilment.values)
              _chip(option.label, _fulfilment == option, () => setState(() => _fulfilment = option)),
          ],
        ),
        const SizedBox(height: 24),
        Row(
          children: [
            Expanded(
              child: OutlinedButton(
                onPressed: () => Navigator.pop(context, widget.current.copyWith(sort: Sort.newest, fulfilment: () => null)),
                child: const Text('Reset'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              flex: 2,
              child: FilledButton(
                onPressed: () => Navigator.pop(context, widget.current.copyWith(sort: _sort, fulfilment: () => _fulfilment)),
                child: const Text('Show results'),
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _chip(String label, bool selected, VoidCallback onTap) => ChoiceChip(
        label: Text(label),
        selected: selected,
        onSelected: (_) => onTap(),
        selectedColor: Brand.mint,
        showCheckmark: false,
        labelStyle: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700, color: selected ? Brand.green : Brand.ink),
        side: BorderSide(color: selected ? Brand.green : Brand.border),
      );
}
