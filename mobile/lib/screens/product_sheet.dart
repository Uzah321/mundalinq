import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/farm_models.dart';
import '../api/models.dart';
import '../state/cart.dart';
import '../state/catalog.dart';
import '../state/favourites.dart';
import '../theme.dart';
import '../util/format.dart';
import '../widgets/category_visual.dart';
import '../widgets/common.dart';
import '../widgets/reviews.dart';
import 'farm_page_screen.dart';
import 'messages/chat_screen.dart';
import 'store_actions.dart';

Future<void> showProductSheet(BuildContext context, String productId) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (_) => DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.92,
      minChildSize: 0.5,
      maxChildSize: 1,
      builder: (context, scrollController) => _ProductSheet(productId: productId, scrollController: scrollController),
    ),
  );
}

class _ProductSheet extends StatefulWidget {
  const _ProductSheet({required this.productId, required this.scrollController});

  final String productId;
  final ScrollController scrollController;

  @override
  State<_ProductSheet> createState() => _ProductSheetState();
}

class _ProductSheetState extends State<_ProductSheet> {
  late Future<ProductDetail> _product = _load();

  Future<ProductDetail> _load() async {
    final response = await context.read<ApiClient>().get('/products/${widget.productId}');
    return ProductDetail.fromJson((response as Map<String, dynamic>)['data'] as Map<String, dynamic>);
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<ProductDetail>(
      future: _product,
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          final error = snapshot.error;
          final gone = error is ApiException && error.status == 404;
          return ListView(
            controller: widget.scrollController,
            children: [
              EmptyState(
                icon: LucideIcons.sprout,
                title: gone ? 'This listing has been picked' : "We couldn't load this listing",
                message: gone ? "It sold out or the farm took it down. There's plenty more in the market." : errorMessage(error!),
                actions: [
                  if (!gone) FilledButton(onPressed: () => setState(() => _product = _load()), child: const Text('Try again')),
                  OutlinedButton(onPressed: () => Navigator.pop(context), child: const Text('Back to the market')),
                ],
              ),
            ],
          );
        }

        if (!snapshot.hasData) return const Center(child: CircularProgressIndicator());

        return _ProductBody(product: snapshot.data!, scrollController: widget.scrollController);
      },
    );
  }
}

class _ProductBody extends StatefulWidget {
  const _ProductBody({required this.product, required this.scrollController});

  final ProductDetail product;
  final ScrollController scrollController;

  @override
  State<_ProductBody> createState() => _ProductBodyState();
}

class _ProductBodyState extends State<_ProductBody> {
  late Variant? _variant;
  int _photo = 0;
  List<Review> _reviews = const [];
  ReviewSummary? _summary;

  ProductDetail get product => widget.product;

  @override
  void initState() {
    super.initState();
    final cart = context.read<CartController>();
    // Start on the option already in the basket, if any.
    _variant = product.variants.where((variant) => cart.lineForVariant(variant.id) != null).firstOrNull ??
        product.variants.firstOrNull;
    _loadReviews();
  }

  Future<void> _loadReviews() async {
    try {
      final response = await context.read<ApiClient>().get('/products/${product.id}/reviews') as Map<String, dynamic>;
      if (!mounted) return;
      setState(() {
        _reviews = (response['data'] as List).cast<Map<String, dynamic>>().map(Review.fromJson).take(3).toList();
        _summary = ReviewSummary.fromJson((response['meta'] as Map<String, dynamic>?)?['summary'] as Map<String, dynamic>?);
      });
    } catch (_) {
      // Reviews are a nice-to-have here; the listing works without them.
    }
  }

  Future<void> _message() async {
    if (!await ensureSignedIn(context, 'Sign in to message ${product.farmName}.')) return;
    if (!mounted) return;
    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => ChatScreen.forFarm(farmId: product.farmId, productId: product.id, title: product.farmName)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final favourite = context.select<FavouritesController, bool>((favourites) => favourites.has(product.id));
    final harvested = harvestedLabel(product.harvestedOn);
    final variant = _variant;

    return ListView(
      controller: widget.scrollController,
      padding: EdgeInsets.fromLTRB(20, 0, 20, 24 + MediaQuery.paddingOf(context).bottom),
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(10),
          child: AspectRatio(
            aspectRatio: 4 / 3,
            child: Stack(
              fit: StackFit.expand,
              children: [
                if (product.images.isEmpty)
                  ProductPlaceholder(categorySlug: product.categorySlug, iconSize: 56)
                else
                  PageView.builder(
                    itemCount: product.images.length,
                    onPageChanged: (index) => setState(() => _photo = index),
                    itemBuilder: (context, index) => CachedNetworkImage(
                      imageUrl: product.images[index].url,
                      fit: BoxFit.cover,
                      placeholder: (_, _) => const ColoredBox(color: Brand.muted),
                      errorWidget: (_, _, _) => ProductPlaceholder(categorySlug: product.categorySlug, iconSize: 56),
                    ),
                  ),
                if (product.images.length > 1)
                  Positioned(
                    left: 0,
                    right: 0,
                    bottom: 10,
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        for (var i = 0; i < product.images.length; i++)
                          Container(
                            width: i == _photo ? 16 : 6,
                            height: 6,
                            margin: const EdgeInsets.symmetric(horizontal: 2),
                            decoration: BoxDecoration(
                              color: i == _photo ? Colors.white : Colors.white70,
                              borderRadius: BorderRadius.circular(3),
                            ),
                          ),
                      ],
                    ),
                  ),
                Positioned(
                  right: 10,
                  bottom: 10,
                  child: IconButton(
                    onPressed: () => toggleFavourite(context, product.id, product.title),
                    tooltip: favourite ? 'Remove from favourites' : 'Save to favourites',
                    style: IconButton.styleFrom(backgroundColor: Colors.white),
                    icon: Icon(favourite ? Icons.favorite : LucideIcons.heart, size: 18, color: favourite ? Brand.green : Brand.mutedText),
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          spacing: 6,
          children: [
            const Icon(LucideIcons.sprout, size: 13, color: Brand.green),
            InkWell(
              onTap: () => Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => FarmPageScreen(farmId: product.farmId, farmName: product.farmName)),
              ),
              child: Text(
                product.farmName,
                style: const TextStyle(fontSize: 12, color: Brand.green, fontWeight: FontWeight.w600, decoration: TextDecoration.underline),
              ),
            ),
            if (product.farmVerified)
              const Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(LucideIcons.badgeCheck, size: 13, color: Brand.green),
                  SizedBox(width: 3),
                  Text('Verified farm', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Brand.green)),
                ],
              ),
          ],
        ),
        const SizedBox(height: 8),
        Text(product.title, style: Brand.display(size: 21, height: 1.3)),
        const SizedBox(height: 10),
        Wrap(
          spacing: 16,
          runSpacing: 6,
          children: [
            _Meta(icon: LucideIcons.mapPin, text: '${product.district}, ${product.province}'),
            if (harvested != null) _Meta(icon: LucideIcons.calendarDays, text: harvested),
            if (product.rating.count > 0)
              _Meta(icon: LucideIcons.star, text: '${product.rating.average?.toStringAsFixed(1)} (${product.rating.count})'),
          ],
        ),
        const SizedBox(height: 14),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            if (product.grade != null) Tag(label: 'Grade ${product.grade}'),
            if (product.offersPickup) const Tag(label: 'Farm pickup', icon: LucideIcons.store),
            if (product.offersDelivery)
              Tag(label: 'Delivers within ${product.deliveryRadiusKm?.round()} km', icon: LucideIcons.truck),
          ],
        ),
        if (product.description != null) ...[
          const SizedBox(height: 14),
          Text(product.description!, style: const TextStyle(fontSize: 14, height: 1.65, color: Brand.mutedText)),
        ],
        const SizedBox(height: 20),
        if (variant == null)
          const Text('This listing has no selling options right now.', style: TextStyle(color: Brand.mutedText))
        else ...[
          if (product.variants.length > 1) ...[
            const Text('Choose how to buy', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
            const SizedBox(height: 10),
            RadioGroup<String>(
              groupValue: variant.id,
              onChanged: (id) => setState(() => _variant = product.variants.firstWhere((option) => option.id == id)),
              child: Column(
                children: [
                  for (final option in product.variants)
                    _VariantOption(variant: option, currency: product.currency, selected: option.id == variant.id),
                ],
              ),
            ),
            const SizedBox(height: 12),
          ],
          _QuantityPicker(key: ValueKey(variant.id), product: product, variant: variant),
        ],
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: _message,
          icon: const Icon(LucideIcons.messageCircle, size: 18),
          label: const Text('Ask the farm a question'),
        ),
        if (_summary != null && _summary!.count > 0) ...[
          const SizedBox(height: 24),
          Text('Reviews', style: Brand.display(size: 17)),
          const SizedBox(height: 12),
          ReviewSummaryView(summary: _summary!),
          for (final review in _reviews) ReviewTile(review: review),
        ],
      ],
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 14, color: Brand.harvestText),
        const SizedBox(width: 5),
        Text(text, style: const TextStyle(fontSize: 13, color: Brand.mutedText)),
      ],
    );
  }
}

class _VariantOption extends StatelessWidget {
  const _VariantOption({required this.variant, required this.currency, required this.selected});

  final Variant variant;
  final String currency;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final tiers = variant.priceTiers
        .map((tier) => '${tier.minQuantity}+ ${variant.unit.pluralName}: ${formatMoney(tier.price, currency)} each')
        .join(' · ');

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: selected ? Brand.mint : Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Brand.radius),
          side: BorderSide(color: selected ? Brand.green : Brand.border),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(Brand.radius),
          onTap: () => RadioGroup.maybeOf<String>(context)?.onChanged(variant.id),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(4, 6, 14, 6),
            child: Row(
              children: [
                Radio<String>(value: variant.id),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(variant.name, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
                      if (tiers.isNotEmpty) ...[
                        const SizedBox(height: 3),
                        Text(tiers, style: const TextStyle(fontSize: 11.5, color: Brand.mutedText)),
                      ],
                    ],
                  ),
                ),
                Text(
                  '${formatMoney(variant.price, currency)} / ${variant.unit.code}',
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w800, color: Brand.green),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _QuantityPicker extends StatefulWidget {
  const _QuantityPicker({super.key, required this.product, required this.variant});

  final ProductDetail product;
  final Variant variant;

  @override
  State<_QuantityPicker> createState() => _QuantityPickerState();
}

class _QuantityPickerState extends State<_QuantityPicker> {
  late final TextEditingController _quantity;

  Variant get variant => widget.variant;

  @override
  void initState() {
    super.initState();
    final inBasket = context.read<CartController>().lineForVariant(variant.id);
    _quantity = TextEditingController(text: inBasket?.quantity ?? initialQuantity(variant));
  }

  @override
  void dispose() {
    _quantity.dispose();
    super.dispose();
  }

  void _set(int milli) => setState(() => _quantity.text = fromMilli(milli));

  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartController>();
    final allowsFraction = context.select<CatalogController, bool?>((catalog) => catalog.allowsFraction(variant.unit.code));
    final inBasket = cart.lineForVariant(variant.id) != null;

    final quantity = _quantity.text.trim();
    final milli = toMilli(quantity);
    final min = toMilli(variant.minOrderQuantity) ?? 1000;
    final max = maxOrderMilli(variant);
    final soldOut = max < min;
    final problem = _problem(milli, min, max, allowsFraction);
    final total = problem == null && milli != null ? formatMinor(lineTotalMinor(variant, quantity), widget.product.currency) : null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Quantity (${variant.unit.pluralName})', style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
        const SizedBox(height: 10),
        Row(
          children: [
            _StepperButton(
              icon: LucideIcons.minus,
              tooltip: 'Less',
              onPressed: soldOut || milli == null || milli <= min ? null : () => _set((milli - 1000).clamp(min, max)),
            ),
            const SizedBox(width: 8),
            SizedBox(
              width: 92,
              child: TextField(
                controller: _quantity,
                textAlign: TextAlign.center,
                keyboardType: TextInputType.numberWithOptions(decimal: allowsFraction != false),
                inputFormatters: [
                  FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]')),
                  TextInputFormatter.withFunction((oldValue, newValue) => newValue.copyWith(text: newValue.text.replaceAll(',', '.'))),
                ],
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800),
                decoration: InputDecoration(
                  fillColor: Colors.white,
                  contentPadding: const EdgeInsets.symmetric(vertical: 12),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(Brand.radius),
                    borderSide: BorderSide(color: problem != null && !soldOut ? Brand.danger : Brand.border),
                  ),
                ),
                onChanged: (_) => setState(() {}),
              ),
            ),
            const SizedBox(width: 8),
            _StepperButton(
              icon: LucideIcons.plus,
              tooltip: 'More',
              onPressed: soldOut || milli == null || milli >= max ? null : () => _set((milli + 1000).clamp(min, max)),
            ),
          ],
        ),
        const SizedBox(height: 8),
        Text(
          problem ?? '${formatQuantity(fromMilli(max), variant.unit)} available',
          style: TextStyle(fontSize: 12, color: problem != null ? Brand.danger : Brand.mutedText),
        ),
        const SizedBox(height: 16),
        SizedBox(
          width: double.infinity,
          child: FilledButton(
            onPressed: problem != null || cart.busy ? null : () => _submit(quantity),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (cart.busy) ...[
                  const SizedBox.square(dimension: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)),
                  const SizedBox(width: 10),
                ],
                Text([
                  soldOut ? 'Sold out' : (inBasket ? 'Update basket' : 'Add to basket'),
                  ?total,
                ].join(' · ')),
              ],
            ),
          ),
        ),
      ],
    );
  }

  String? _problem(int? milli, int min, int max, bool? allowsFraction) {
    if (max < min) return 'Sold out for now. Check back after the next harvest.';
    if (milli == null || milli <= 0) return 'Enter a quantity.';
    if (allowsFraction == false && milli % 1000 != 0) return 'Order whole ${variant.unit.pluralName}.';
    if (milli < min) return 'The minimum order is ${formatQuantity(variant.minOrderQuantity, variant.unit)}.';
    if (milli > max) return 'Only ${formatQuantity(fromMilli(max), variant.unit)} available.';
    return null;
  }

  Future<void> _submit(String quantity) async {
    final title = widget.product.title;
    if (!await ensureSignedIn(context, 'Sign in to add $title to your basket.')) return;
    if (!mounted) return;

    try {
      await context.read<CartController>().add(variant.id, quantity);
      if (!mounted) return;
      final navigator = Navigator.of(context);
      showBasketMessage(context, '${formatQuantity(quantity, variant.unit)} of $title in your basket');
      navigator.pop();
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    }
  }
}

class _StepperButton extends StatelessWidget {
  const _StepperButton({required this.icon, required this.tooltip, required this.onPressed});

  final IconData icon;
  final String tooltip;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return IconButton.outlined(
      onPressed: onPressed,
      tooltip: tooltip,
      style: IconButton.styleFrom(
        fixedSize: const Size(48, 48),
        side: const BorderSide(color: Brand.border),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Brand.radius)),
      ),
      icon: Icon(icon, size: 18),
    );
  }
}
