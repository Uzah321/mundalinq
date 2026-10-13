import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../state/shopping_area.dart';
import '../theme.dart';

/// Where are you shopping? All of Zimbabwe, near the phone's location, or one province.
Future<void> showLocationSheet(BuildContext context) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (_) => const _LocationSheet(),
  );
}

class _LocationSheet extends StatefulWidget {
  const _LocationSheet();

  @override
  State<_LocationSheet> createState() => _LocationSheetState();
}

class _LocationSheetState extends State<_LocationSheet> {
  late int _radius = context.read<ShoppingAreaController>().area?.radiusKm ?? 50;
  bool _locating = false;
  String? _locateError;

  Future<void> _choose(ShoppingArea? area) async {
    await context.read<ShoppingAreaController>().choose(area);
    if (mounted) Navigator.pop(context);
  }

  Future<void> _useMyLocation() async {
    setState(() {
      _locating = true;
      _locateError = null;
    });

    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        throw const _LocateProblem('Turn on location in your phone settings, then try again.');
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied) {
        throw const _LocateProblem('MundaLinq needs your location to find farms near you. You can pick a province instead.');
      }
      if (permission == LocationPermission.deniedForever) {
        throw const _LocateProblem('Location is blocked for MundaLinq. Allow it in your phone settings, or pick a province.');
      }

      // A rough fix is enough to find farms within tens of kilometres, and quicker on a weak signal.
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.low, timeLimit: Duration(seconds: 20)),
      );

      await _choose(ShoppingArea.nearby(latitude: position.latitude, longitude: position.longitude, radiusKm: _radius));
    } on _LocateProblem catch (problem) {
      setState(() => _locateError = problem.message);
    } catch (_) {
      setState(() => _locateError = "We couldn't find your location. Try again outside, or pick a province.");
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final current = context.watch<ShoppingAreaController>().area;

    return ListView(
      shrinkWrap: true,
      padding: EdgeInsets.fromLTRB(20, 0, 20, 24 + MediaQuery.paddingOf(context).bottom),
      children: [
        Text('Where are you shopping?', style: Brand.display(size: 19)),
        const SizedBox(height: 6),
        const Text('We show farms that can sell to you there.', style: TextStyle(color: Brand.mutedText)),
        const SizedBox(height: 18),
        _Option(
          icon: LucideIcons.globe,
          title: 'All of Zimbabwe',
          subtitle: 'Every farm on MundaLinq',
          selected: current == null,
          onTap: () => _choose(null),
        ),
        const SizedBox(height: 20),
        const Text('Near me', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          children: [
            for (final radius in radiusOptions)
              ChoiceChip(
                label: Text('Within $radius km'),
                selected: _radius == radius,
                onSelected: (_) => setState(() => _radius = radius),
                selectedColor: Brand.mint,
                labelStyle: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700, color: _radius == radius ? Brand.green : Brand.ink),
                side: BorderSide(color: _radius == radius ? Brand.green : Brand.border),
                showCheckmark: false,
              ),
          ],
        ),
        const SizedBox(height: 10),
        OutlinedButton.icon(
          onPressed: _locating ? null : _useMyLocation,
          icon: _locating
              ? const SizedBox.square(dimension: 16, child: CircularProgressIndicator(strokeWidth: 2))
              : const Icon(LucideIcons.locateFixed, size: 18, color: Brand.green),
          label: Text(current?.isNearby == true ? 'Update my location' : 'Use my location'),
        ),
        if (_locateError != null) ...[
          const SizedBox(height: 8),
          Text(_locateError!, style: const TextStyle(fontSize: 13, color: Brand.danger)),
        ],
        const SizedBox(height: 22),
        const Text('Or pick a province', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
        const SizedBox(height: 10),
        GridView.count(
          crossAxisCount: 2,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          mainAxisSpacing: 8,
          crossAxisSpacing: 8,
          childAspectRatio: 3.6,
          children: [
            for (final province in provinces)
              _ProvinceChoice(
                province: province,
                selected: current?.province == province,
                onTap: () => _choose(ShoppingArea.province(province)),
              ),
          ],
        ),
      ],
    );
  }
}

class _LocateProblem implements Exception {
  const _LocateProblem(this.message);

  final String message;
}

class _Option extends StatelessWidget {
  const _Option({required this.icon, required this.title, required this.subtitle, required this.selected, required this.onTap});

  final IconData icon;
  final String title;
  final String subtitle;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected ? Brand.mint : Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Brand.radius),
        side: BorderSide(color: selected ? Brand.green : Brand.border),
      ),
      child: ListTile(
        onTap: onTap,
        leading: Icon(icon, color: Brand.green),
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text(subtitle, style: const TextStyle(color: Brand.mutedText)),
        trailing: selected ? const Icon(LucideIcons.check, color: Brand.green) : null,
      ),
    );
  }
}

class _ProvinceChoice extends StatelessWidget {
  const _ProvinceChoice({required this.province, required this.selected, required this.onTap});

  final String province;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected ? Brand.mint : Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Brand.radius),
        side: BorderSide(color: selected ? Brand.green : Brand.border),
      ),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(Brand.radius),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Align(
            alignment: Alignment.centerLeft,
            child: Text(
              province,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: selected ? Brand.green : Brand.ink),
            ),
          ),
        ),
      ),
    );
  }
}

/// "Shopping near Harare ⌄", opening the location sheet.
class ShoppingNearButton extends StatelessWidget {
  const ShoppingNearButton({super.key});

  @override
  Widget build(BuildContext context) {
    final label = context.select<ShoppingAreaController, String>((area) => area.label);
    return InkWell(
      onTap: () => showLocationSheet(context),
      borderRadius: BorderRadius.circular(6),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(LucideIcons.mapPin, size: 16, color: Brand.green),
            const SizedBox(width: 6),
            const Text('Shopping near ', style: TextStyle(fontSize: 13, color: Brand.mutedText)),
            Flexible(
              child: Text(
                label,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Brand.green),
              ),
            ),
            const SizedBox(width: 4),
            const Icon(LucideIcons.chevronDown, size: 16, color: Brand.green),
          ],
        ),
      ),
    );
  }
}

/// Shown when nothing is listed in the chosen area.
class AreaEmptyHint extends StatelessWidget {
  const AreaEmptyHint({super.key});

  @override
  Widget build(BuildContext context) {
    final area = context.watch<ShoppingAreaController>();
    if (area.area == null) return const SizedBox.shrink();
    return TextButton(onPressed: () => area.choose(null), child: const Text('Show all of Zimbabwe'));
  }
}
