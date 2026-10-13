import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import 'api/api_client.dart';
import 'api/server.dart';
import 'screens/app_shell.dart';
import 'screens/farm/farm_shell.dart';
import 'state/cart.dart';
import 'state/catalog.dart';
import 'state/farm.dart';
import 'state/favourites.dart';
import 'state/notifications.dart';
import 'state/product_search.dart';
import 'state/session.dart';
import 'state/shell.dart';
import 'state/shopping_area.dart';
import 'theme.dart';
import 'widgets/common.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Server.restore();
  LicenseRegistry.addLicense(() async* {
    for (final font in ['Manrope', 'Sora']) {
      yield LicenseEntryWithLineBreaks([font], await rootBundle.loadString('assets/fonts/OFL-$font.txt'));
    }
  });
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    statusBarIconBrightness: Brightness.dark,
  ));

  final api = ApiClient();
  final session = Session(api);
  final cart = CartController(api);
  final favourites = FavouritesController(api);
  final catalog = CatalogController(api);
  final area = ShoppingAreaController();
  final farms = FarmController(api);
  final notifications = NotificationsController(api);
  await farms.restore();

  // The basket, favourites, farms and notifications belong to whoever is signed in: load them on sign-in, drop
  // them on sign-out.
  var wasSignedIn = false;
  session.addListener(() {
    if (session.signedIn == wasSignedIn) return;
    wasSignedIn = session.signedIn;
    if (session.signedIn) {
      cart.load();
      favourites.load();
      farms.load();
      notifications.start();
    } else {
      cart.reset();
      favourites.reset();
      farms.reset();
      notifications.stop();
    }
  });

  session.restore();
  catalog.load();
  area.restore();

  runApp(
    MultiProvider(
      providers: [
        Provider.value(value: api),
        ChangeNotifierProvider.value(value: session),
        ChangeNotifierProvider.value(value: cart),
        ChangeNotifierProvider.value(value: favourites),
        ChangeNotifierProvider.value(value: catalog),
        ChangeNotifierProvider.value(value: area),
        ChangeNotifierProvider.value(value: farms),
        ChangeNotifierProvider.value(value: notifications),
        ChangeNotifierProvider(create: (_) => BrowseController()),
        ChangeNotifierProvider(create: (_) => ShellController()),
        ChangeNotifierProvider(create: (_) => QuickAddController()),
      ],
      child: const MundaLinqApp(),
    ),
  );
}

class MundaLinqApp extends StatelessWidget {
  const MundaLinqApp({super.key});

  @override
  Widget build(BuildContext context) {
    final restoring = context.select<Session, bool>((session) => session.restoring);
    final signedIn = context.select<Session, bool>((session) => session.signedIn);
    // Farmers land back on their farm; a farm the server hasn't returned yet shows the splash briefly.
    final selling = context.select<FarmController, bool>((farms) => farms.selling);
    final farmsPending = context.select<FarmController, bool>((farms) => !farms.loaded && farms.error == null);

    final Widget home;
    if (restoring || (signedIn && farmsPending && FarmController.wasSelling)) {
      home = const _Splash();
    } else if (signedIn && selling) {
      home = const FarmShell();
    } else {
      home = const AppShell();
    }

    return MaterialApp(
      title: 'MundaLinq',
      debugShowCheckedModeBanner: false,
      theme: buildTheme(),
      home: home,
    );
  }
}

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(body: Center(child: Wordmark(size: 32)));
  }
}
