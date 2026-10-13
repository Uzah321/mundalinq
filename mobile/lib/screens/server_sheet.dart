import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/server.dart';
import '../state/cart.dart';
import '../state/catalog.dart';
import '../state/favourites.dart';
import '../state/session.dart';
import '../theme.dart';

/// Lets a tester point the app at the computer running the API, e.g. after it joins another Wi-Fi network.
/// The address is tried before it is saved, so a typo can't cut the app off.
Future<void> showServerSheet(BuildContext context) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (_) => const _ServerSheet(),
  );
}

class _ServerSheet extends StatefulWidget {
  const _ServerSheet();

  @override
  State<_ServerSheet> createState() => _ServerSheetState();
}

class _ServerSheetState extends State<_ServerSheet> {
  late final _address = TextEditingController(text: Server.origin);
  String? _error;
  bool _checking = false;

  @override
  void dispose() {
    _address.dispose();
    super.dispose();
  }

  Future<void> _save(String input) async {
    final baseUrl = Server.normalize(input);
    if (baseUrl == null) {
      setState(() => _error = 'Enter an address like 192.168.1.20:8000');
      return;
    }

    setState(() {
      _checking = true;
      _error = null;
    });
    try {
      await ApiClient(baseUrl: baseUrl).get('/categories');
    } catch (error) {
      setState(() {
        _checking = false;
        _error = "Couldn't reach MundaLinq at that address. Check the phone is on the same Wi-Fi and the server is running.";
      });
      return;
    }

    await Server.use(baseUrl);
    if (!mounted) return;

    // Everything shown so far came from the old address.
    context.read<CatalogController>().load();
    if (context.read<Session>().signedIn) {
      context.read<CartController>().load();
      context.read<FavouritesController>().load();
    }
    Navigator.pop(context);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Connected to ${Server.origin}')));
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        children: [
          Text('Server address', style: Brand.display(size: 19)),
          const SizedBox(height: 6),
          const Text(
            'The computer running MundaLinq for this test. If its Wi-Fi address changes, enter the new one here '
            '(on Windows, run ipconfig and use the Wi-Fi IPv4 address, with :8000).',
            style: TextStyle(color: Brand.mutedText, height: 1.5),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _address,
            keyboardType: TextInputType.url,
            autocorrect: false,
            decoration: InputDecoration(
              hintText: '192.168.1.20:8000',
              prefixIcon: const Icon(LucideIcons.server, size: 18),
              errorText: _error,
              errorMaxLines: 3,
            ),
            onSubmitted: _save,
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _checking ? null : () => _save(_address.text),
            child: _checking
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Connect'),
          ),
          if (Server.baseUrl != Server.buildDefault)
            TextButton(
              onPressed: _checking ? null : () => _save(Server.buildDefault),
              child: Text('Back to ${Uri.parse(Server.buildDefault).authority}'),
            ),
        ],
      ),
    );
  }
}
