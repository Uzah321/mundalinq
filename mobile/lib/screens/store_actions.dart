import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../state/cart.dart';
import '../state/favourites.dart';
import '../state/session.dart';
import '../state/shell.dart';
import '../util/format.dart';
import '../widgets/common.dart';
import 'auth_screen.dart';
import 'product_sheet.dart';

/// Opens the sign-in screen when needed. True once the buyer is signed in.
Future<bool> ensureSignedIn(BuildContext context, String reason) async {
  if (context.read<Session>().signedIn) return true;

  await Navigator.of(context).push<bool>(
    MaterialPageRoute(fullscreenDialog: true, builder: (_) => AuthScreen(reason: reason)),
  );

  return context.mounted && context.read<Session>().signedIn;
}

void showBasketMessage(BuildContext context, String message) {
  final shell = context.read<ShellController>();
  showMessage(context, message, actionLabel: 'View basket', onAction: () => shell.go(AppTab.basket));
}

Future<void> openProduct(BuildContext context, String productId) => showProductSheet(context, productId);

Future<void> toggleFavourite(BuildContext context, String productId, String title) async {
  if (!await ensureSignedIn(context, 'Sign in to save $title to your favourites.')) return;
  if (!context.mounted) return;

  try {
    await context.read<FavouritesController>().toggle(productId);
  } catch (error) {
    if (context.mounted) showMessage(context, errorMessage(error));
  }
}

/// The card's + button: adds one unit when there is a single way to buy, otherwise opens the listing.
Future<void> quickAdd(BuildContext context, ProductCard product) async {
  if (!await ensureSignedIn(context, 'Sign in to add ${product.title} to your basket.')) return;
  if (!context.mounted) return;

  final api = context.read<ApiClient>();
  final cart = context.read<CartController>();
  final adding = context.read<QuickAddController>();

  adding.start(product.id);
  try {
    final detail = ProductDetail.fromJson((await api.get('/products/${product.id}'))['data'] as Map<String, dynamic>);
    if (!context.mounted) return;

    // Several ways to buy (per kg, per crate...): let the buyer choose.
    if (detail.variants.length != 1) {
      adding.finish(product.id);
      await openProduct(context, product.id);
      return;
    }

    final variant = detail.variants.single;
    if (maxOrderMilli(variant) < (toMilli(variant.minOrderQuantity) ?? 0)) {
      showMessage(context, '${detail.title} is sold out for now.');
      return;
    }

    await cart.add(variant.id, initialQuantity(variant));
    if (context.mounted) showBasketMessage(context, '${detail.title} is in your basket');
  } catch (error) {
    if (context.mounted) showMessage(context, errorMessage(error));
  } finally {
    adding.finish(product.id);
  }
}

/// A card's − and + once its listing is in the basket.
Future<void> stepLine(BuildContext context, CartLine line, int direction) async {
  final cart = context.read<CartController>();
  try {
    await cart.step(line, direction);
  } catch (error) {
    if (context.mounted) showMessage(context, errorMessage(error));
  }
}
