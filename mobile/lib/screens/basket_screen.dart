import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../state/cart.dart';
import '../state/session.dart';
import '../state/shell.dart';
import '../theme.dart';
import '../util/format.dart';
import '../widgets/common.dart';
import 'checkout_screen.dart';
import 'store_actions.dart';

/// The basket, grouped by farm and priced by the server.
class BasketScreen extends StatelessWidget {
  const BasketScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final signedIn = context.select<Session, bool>((session) => session.signedIn);
    final cart = context.watch<CartController>();

    Widget body;
    if (!signedIn) {
      body = _centered(
        EmptyState(
          icon: LucideIcons.shoppingBag,
          title: 'Sign in to start a basket',
          message: 'Your basket is saved to your account, so it is here whenever you come back.',
          actions: [FilledButton(onPressed: () => ensureSignedIn(context, 'Sign in to see your basket.'), child: const Text('Sign in'))],
        ),
      );
    } else if (cart.cart == null && cart.error != null) {
      body = _centered(
        EmptyState(
          icon: LucideIcons.wifiOff,
          title: "We couldn't load your basket",
          message: errorMessage(cart.error!),
          actions: [FilledButton(onPressed: cart.load, child: const Text('Try again'))],
        ),
      );
    } else if (cart.cart == null) {
      body = const Center(child: CircularProgressIndicator());
    } else if (cart.lines.isEmpty) {
      body = _centered(
        EmptyState(
          icon: LucideIcons.shoppingBasket,
          title: 'Your basket is empty',
          message: 'Fresh produce from farms near you is a tap away.',
          actions: [FilledButton(onPressed: () => context.read<ShellController>().go(AppTab.browse), child: const Text('Browse produce'))],
        ),
      );
    } else {
      body = _BasketContents(cart: cart.cart!);
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Basket')),
      body: signedIn ? RefreshIndicator(onRefresh: cart.load, child: body) : body,
    );
  }

  Widget _centered(Widget child) => LayoutBuilder(
        builder: (context, constraints) => SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          child: ConstrainedBox(constraints: BoxConstraints(minHeight: constraints.maxHeight), child: Center(child: child)),
        ),
      );
}

class _BasketContents extends StatelessWidget {
  const _BasketContents({required this.cart});

  final Cart cart;

  Future<void> _remove(BuildContext context, CartLine line) async {
    final controller = context.read<CartController>();
    try {
      await controller.remove(line);
      if (!context.mounted) return;
      showMessage(
        context,
        'Removed ${line.title}',
        actionLabel: 'Undo',
        onAction: () => controller.add(line.variantId, line.quantity).catchError((Object _) {}),
      );
    } catch (error) {
      if (context.mounted) showMessage(context, errorMessage(error));
    }
  }

  Future<void> _clear(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Empty your basket?'),
        content: const Text('Everything in it will be removed.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Keep')),
          TextButton(onPressed: () => Navigator.pop(context, true), child: const Text('Empty basket')),
        ],
      ),
    );
    if (confirmed != true || !context.mounted) return;
    try {
      await context.read<CartController>().clear();
    } catch (error) {
      if (context.mounted) showMessage(context, errorMessage(error));
    }
  }

  @override
  Widget build(BuildContext context) {
    final busy = context.select<CartController, bool>((controller) => controller.busy);

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
      children: [
        for (final seller in cart.sellers) ...[
          Padding(
            padding: const EdgeInsets.only(top: 16, bottom: 4),
            child: Row(
              children: [
                const Icon(LucideIcons.store, size: 16, color: Brand.green),
                const SizedBox(width: 6),
                Expanded(child: Text(seller.farmName, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: Brand.green))),
                Text(formatMoney(seller.subtotal, cart.currency), style: const TextStyle(fontSize: 13, color: Brand.mutedText)),
              ],
            ),
          ),
          for (final line in seller.items)
            _BasketLine(
              line: line,
              currency: cart.currency,
              busy: busy,
              onStep: (direction) => stepLine(context, line, direction),
              onRemove: () => _remove(context, line),
            ),
        ],
        for (final problem in cart.problems.where((problem) => problem.cartItemId == null))
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Text(problem.message, style: const TextStyle(color: Brand.danger, fontSize: 13)),
          ),
        const SizedBox(height: 20),
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(color: Brand.muted, borderRadius: BorderRadius.circular(Brand.radius)),
          child: Column(
            children: [
              Row(
                children: [
                  const Expanded(child: Text('Subtotal', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700))),
                  Text(formatMoney(cart.subtotal, cart.currency), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                ],
              ),
              const SizedBox(height: 8),
              const Text(
                'Choose collection or delivery for each farm at checkout. Nothing is charged until the farms confirm.',
                style: TextStyle(fontSize: 12, height: 1.6, color: Brand.mutedText),
              ),
              const SizedBox(height: 14),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: busy
                      ? null
                      : () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const CheckoutScreen())),
                  child: const Text('Checkout'),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 8),
        Center(
          child: TextButton(
            onPressed: busy ? null : () => _clear(context),
            style: TextButton.styleFrom(foregroundColor: Brand.mutedText),
            child: const Text('Empty basket'),
          ),
        ),
      ],
    );
  }
}

class _BasketLine extends StatelessWidget {
  const _BasketLine({required this.line, required this.currency, required this.busy, required this.onStep, required this.onRemove});

  final CartLine line;
  final String currency;
  final bool busy;
  final void Function(int direction) onStep;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final perUnit = '${formatMoney(line.unitPrice, currency)} / ${line.unit.name}';

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: Brand.border))),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          InkWell(
            onTap: () => openProduct(context, line.productId),
            borderRadius: BorderRadius.circular(6),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: SizedBox.square(
                dimension: 58,
                child: line.thumbUrl == null
                    ? const ColoredBox(color: Brand.muted, child: Icon(LucideIcons.sprout, color: Brand.green, size: 22))
                    : CachedNetworkImage(imageUrl: line.thumbUrl!, fit: BoxFit.cover, color: Brand.muted, colorBlendMode: BlendMode.multiply),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(line.title, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
                const SizedBox(height: 3),
                Text(line.label == null ? perUnit : '${line.label} · $perUnit', style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
                if (line.problem != null) ...[
                  const SizedBox(height: 4),
                  Text(line.problem!.message, style: const TextStyle(fontSize: 12, color: Brand.danger)),
                ],
                const SizedBox(height: 8),
                Row(
                  children: [
                    Container(
                      decoration: BoxDecoration(color: Brand.mint, borderRadius: BorderRadius.circular(6)),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          IconButton(
                            onPressed: busy ? null : () => onStep(-1),
                            tooltip: 'One less',
                            icon: const Icon(LucideIcons.minus, size: 15, color: Brand.green),
                          ),
                          Text(formatQuantity(line.quantity, line.unit), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700)),
                          IconButton(
                            onPressed: busy ? null : () => onStep(1),
                            tooltip: 'One more',
                            icon: const Icon(LucideIcons.plus, size: 15, color: Brand.green),
                          ),
                        ],
                      ),
                    ),
                    const Spacer(),
                    Text(formatMoney(line.lineTotal, currency), style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800, color: Brand.green)),
                  ],
                ),
              ],
            ),
          ),
          IconButton(
            onPressed: busy ? null : onRemove,
            tooltip: 'Remove ${line.title}',
            icon: const Icon(LucideIcons.trash2, size: 17, color: Brand.mutedText),
          ),
        ],
      ),
    );
  }
}
