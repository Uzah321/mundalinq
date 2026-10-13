import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../state/favourites.dart';
import '../state/session.dart';
import '../state/shell.dart';
import '../widgets/common.dart';
import '../widgets/product_grid.dart';
import 'store_actions.dart';

class FavouritesScreen extends StatelessWidget {
  const FavouritesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final signedIn = context.select<Session, bool>((session) => session.signedIn);
    final favourites = context.watch<FavouritesController>();

    // Reload after saving something new (the controller drops its list then).
    if (signedIn && favourites.items == null && !favourites.loading && favourites.error == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => favourites.load());
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Favourites')),
      body: !signedIn
          ? Center(
              child: SingleChildScrollView(
                child: EmptyState(
                  icon: LucideIcons.heart,
                  title: 'Save what you love',
                  message: 'Tap the heart on any listing to keep it here, on this phone and any other you sign in on.',
                  actions: [FilledButton(onPressed: () => ensureSignedIn(context, 'Sign in to see your favourites.'), child: const Text('Sign in'))],
                ),
              ),
            )
          : RefreshIndicator(
              onRefresh: favourites.load,
              child: CustomScrollView(
                slivers: [
                  const SliverToBoxAdapter(child: SizedBox(height: 16)),
                  if (favourites.items == null && favourites.error == null)
                    const ProductGridSkeleton()
                  else if (favourites.error != null && favourites.items == null)
                    SliverToBoxAdapter(
                      child: EmptyState(
                        icon: LucideIcons.wifiOff,
                        title: "We couldn't load your favourites",
                        message: errorMessage(favourites.error!),
                        actions: [FilledButton(onPressed: favourites.load, child: const Text('Try again'))],
                      ),
                    )
                  else if (favourites.items!.isEmpty)
                    SliverToBoxAdapter(
                      child: EmptyState(
                        icon: LucideIcons.heart,
                        title: 'No favourites yet',
                        message: 'Tap the heart on a listing to save it for later.',
                        actions: [
                          FilledButton(onPressed: () => context.read<ShellController>().go(AppTab.browse), child: const Text('Browse produce')),
                        ],
                      ),
                    )
                  else
                    ProductGrid(products: favourites.items!),
                  const SliverToBoxAdapter(child: SizedBox(height: 24)),
                ],
              ),
            ),
    );
  }
}
