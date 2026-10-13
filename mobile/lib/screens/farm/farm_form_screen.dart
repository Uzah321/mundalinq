import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/farm_models.dart';
import '../../state/farm.dart';
import '../../state/session.dart';
import '../../state/shopping_area.dart';
import '../../theme.dart';
import '../auth_screen.dart';

final _decimal = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))];

/// Sets up a new farm (becoming a farmer if needed), or edits the current one. The farm's pin is taken from the
/// phone's location: buyers search by distance, and delivery areas are measured from it.
class FarmFormScreen extends StatefulWidget {
  const FarmFormScreen({super.key, this.farm});

  final Farm? farm;

  @override
  State<FarmFormScreen> createState() => _FarmFormScreenState();
}

class _FarmFormScreenState extends State<FarmFormScreen> {
  late final _name = TextEditingController(text: widget.farm?.name);
  late final _description = TextEditingController(text: widget.farm?.description);
  late final _phone = TextEditingController(text: widget.farm?.contactPhone ?? context.read<Session>().user?.phone);
  late final _district = TextEditingController(text: widget.farm?.location?.district);
  late final _ward = TextEditingController(text: widget.farm?.location?.ward);
  late final _radius = TextEditingController(text: widget.farm?.deliveryRadiusKm?.round().toString() ?? '25');
  late final _fee = TextEditingController(text: widget.farm?.deliveryFee ?? '2.00');
  late final _freeOver = TextEditingController(text: widget.farm?.freeDeliveryOver);
  late String _type = widget.farm?.farmerType ?? 'smallholder';
  late String? _province = widget.farm?.location?.province ?? context.read<ShoppingAreaController>().area?.province;
  late bool _pickup = widget.farm?.offersPickup ?? true;
  late bool _delivery = widget.farm?.offersDelivery ?? false;
  Position? _pin;
  bool _locating = false;
  bool _saving = false;
  ApiException? _error;
  String? _pinError;

  bool get _creating => widget.farm == null;

  @override
  void dispose() {
    for (final controller in [_name, _description, _phone, _district, _ward, _radius, _fee, _freeOver]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _locate() async {
    setState(() {
      _locating = true;
      _pinError = null;
    });
    try {
      if (!await Geolocator.isLocationServiceEnabled()) throw 'Turn on location in your phone settings, then try again.';
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
        throw 'Allow MundaLinq to use your location to pin your farm.';
      }
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.low, timeLimit: Duration(seconds: 20)),
      );
      setState(() => _pin = position);
    } on String catch (message) {
      setState(() => _pinError = message);
    } catch (_) {
      setState(() => _pinError = "We couldn't find your location. Try again outside.");
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  Map<String, Object?> get _farmFields => {
        'name': _name.text.trim(),
        'description': _description.text.trim().isEmpty ? null : _description.text.trim(),
        'farmer_type': _type,
        'contact_phone': _phone.text.trim().isEmpty ? null : _phone.text.trim(),
        'offers_pickup': _pickup,
        'offers_delivery': _delivery,
        if (_delivery) 'delivery_radius_km': _radius.text.trim(),
        if (_delivery) 'delivery_fee': _fee.text.trim().isEmpty ? '0' : _fee.text.trim(),
        if (_delivery) 'free_delivery_over': _freeOver.text.trim().isEmpty ? null : _freeOver.text.trim(),
      };

  Future<void> _save() async {
    if (_creating && _pin == null) {
      setState(() => _pinError = 'Pin your farm first: stand on the farm and tap the button.');
      return;
    }
    final api = context.read<ApiClient>();
    final session = context.read<Session>();
    final farms = context.read<FarmController>();
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      if (_creating) {
        if (!(session.user?.isFarmer ?? false)) {
          await api.post('/me/roles', {'role': 'farmer'});
          await session.reloadUser();
        }
        final response = await api.post('/farms', {
          ..._farmFields,
          'location': {
            'name': 'Main farm',
            'province': _province,
            'district': _district.text.trim(),
            if (_ward.text.trim().isNotEmpty) 'ward': _ward.text.trim(),
            'latitude': _pin!.latitude,
            'longitude': _pin!.longitude,
          },
        }) as Map<String, dynamic>;
        final farm = Farm.fromJson(response['data'] as Map<String, dynamic>);
        await farms.refreshWith(farm.id);
        await farms.startSelling(farm.id);
        if (mounted) Navigator.of(context).popUntil((route) => route.isFirst);
      } else {
        final farm = widget.farm!;
        await api.patch('/farms/${farm.id}', _farmFields);
        if (_pin != null && farm.location != null) {
          await api.patch('/farms/${farm.id}/locations/${farm.location!.id}', {'latitude': _pin!.latitude, 'longitude': _pin!.longitude});
        }
        await farms.refreshWith(farm.id);
        if (mounted) Navigator.pop(context);
      }
    } on ApiException catch (error) {
      setState(() => _error = error);
      if (mounted && error.fieldErrors.isEmpty) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<Session>().user;

    if (_creating && user != null && !user.phoneVerified) {
      return Scaffold(
        appBar: AppBar(title: const Text('Sell on MundaLinq')),
        body: Column(
          children: [
            const Padding(
              padding: EdgeInsets.fromLTRB(24, 24, 24, 0),
              child: Text('Farms are run by people with a confirmed phone number, so buyers and MundaLinq can reach you.',
                  style: TextStyle(fontSize: 15, height: 1.5)),
            ),
            Expanded(child: VerifyPhoneView(codeJustSent: false, onDone: () => setState(() {}))),
          ],
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(title: Text(_creating ? 'Sell on MundaLinq' : 'Farm details')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
        children: [
          if (_creating) ...[
            Text('Set up your farm', style: Brand.display(size: 21)),
            const SizedBox(height: 6),
            const Text(
              'Buyers near you find your produce, order in the app and pay by mobile money. '
              'MundaLinq keeps 5% of each sale; you are paid to your mobile money account.',
              style: TextStyle(color: Brand.mutedText, height: 1.5),
            ),
            const SizedBox(height: 20),
          ],
          _field('Farm name', _name, error: 'name'),
          const Text('You are a', style: TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final entry in farmerTypes.entries)
                ChoiceChip(
                  label: Text(entry.value),
                  selected: _type == entry.key,
                  onSelected: (_) => setState(() => _type = entry.key),
                  selectedColor: Brand.mint,
                  showCheckmark: false,
                  labelStyle: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700, color: _type == entry.key ? Brand.green : Brand.ink),
                  side: BorderSide(color: _type == entry.key ? Brand.green : Brand.border),
                ),
            ],
          ),
          if (_type == 'commercial' || _type == 'cooperative')
            const Padding(
              padding: EdgeInsets.only(top: 6),
              child: Text('You will need your business registration number and certificate to get verified.',
                  style: TextStyle(fontSize: 12, color: Brand.mutedText)),
            ),
          const SizedBox(height: 16),
          _field('About the farm (optional)', _description, lines: 3, error: 'description', hint: 'What you grow and how'),
          _field('Phone for buyers', _phone, keyboard: TextInputType.phone, error: 'contact_phone', hint: '0771 234 567'),
          if (_creating) ...[
            Padding(
              padding: const EdgeInsets.only(bottom: 14),
              child: DropdownButtonFormField<String>(
                initialValue: _province,
                decoration: InputDecoration(labelText: 'Province', errorText: _error?.field('location.province')),
                items: [for (final province in provinces) DropdownMenuItem(value: province, child: Text(province))],
                onChanged: (value) => setState(() => _province = value),
              ),
            ),
            _field('District', _district, error: 'location.district', hint: 'e.g. Goromonzi'),
            _field('Ward (optional)', _ward, error: 'location.ward'),
          ],
          _PinBox(
            pinned: _pin != null,
            existing: !_creating,
            locating: _locating,
            error: _pinError ?? _error?.field('location.latitude'),
            onLocate: _locate,
          ),
          const SizedBox(height: 16),
          const Text('Getting produce to buyers', style: TextStyle(fontWeight: FontWeight.w700)),
          SwitchListTile(
            value: _pickup,
            onChanged: (value) => setState(() => _pickup = value),
            contentPadding: EdgeInsets.zero,
            title: const Text('Buyers can collect from the farm'),
          ),
          SwitchListTile(
            value: _delivery,
            onChanged: (value) => setState(() => _delivery = value),
            contentPadding: EdgeInsets.zero,
            title: const Text('We deliver'),
          ),
          if (_delivery) ...[
            Row(
              children: [
                Expanded(child: _field('Up to', _radius, number: true, suffix: 'km', error: 'delivery_radius_km')),
                const SizedBox(width: 10),
                Expanded(child: _field('Delivery fee', _fee, number: true, prefix: r'$ ', error: 'delivery_fee')),
              ],
            ),
            _field('Free delivery on orders over (optional)', _freeOver, number: true, prefix: r'$ ', error: 'free_delivery_over'),
          ],
          if (_error != null && _error!.fieldErrors.isNotEmpty)
            Padding(padding: const EdgeInsets.only(bottom: 10), child: Text(_error!.detail, style: const TextStyle(color: Brand.danger))),
          const SizedBox(height: 8),
          FilledButton(
            onPressed: _saving ? null : _save,
            child: _saving
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : Text(_creating ? 'Create my farm' : 'Save'),
          ),
        ],
      ),
    );
  }

  Widget _field(String label, TextEditingController controller,
          {String? error, String? hint, int lines = 1, TextInputType? keyboard, bool number = false, String? suffix, String? prefix}) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 14),
        child: TextField(
          controller: controller,
          minLines: lines,
          maxLines: lines,
          keyboardType: number ? const TextInputType.numberWithOptions(decimal: true) : keyboard,
          inputFormatters: number ? _decimal : null,
          textCapitalization: number || keyboard != null ? TextCapitalization.none : TextCapitalization.sentences,
          decoration: InputDecoration(
            labelText: label,
            hintText: hint,
            suffixText: suffix,
            prefixText: prefix,
            errorText: error == null ? null : _error?.field(error),
            errorMaxLines: 2,
          ),
        ),
      );
}

class _PinBox extends StatelessWidget {
  const _PinBox({required this.pinned, required this.existing, required this.locating, required this.error, required this.onLocate});

  final bool pinned;
  final bool existing;
  final bool locating;
  final String? error;
  final VoidCallback onLocate;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: pinned ? Brand.mint : Brand.muted,
        borderRadius: BorderRadius.circular(Brand.radius),
        border: Border.all(color: pinned ? Brand.green : Brand.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(pinned ? LucideIcons.mapPinCheck : LucideIcons.mapPin, size: 18, color: Brand.green),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  pinned ? 'Pinned at your current location' : (existing ? 'Farm pin' : 'Pin your farm on the map'),
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            existing
                ? 'Only move the pin while you are standing on the farm. Buyers see it rounded to about 1 km.'
                : 'Do this while you are on the farm. Buyers search by distance, and only see it rounded to about 1 km.',
            style: const TextStyle(fontSize: 12.5, height: 1.5, color: Brand.mutedText),
          ),
          const SizedBox(height: 10),
          OutlinedButton.icon(
            onPressed: locating ? null : onLocate,
            icon: locating
                ? const SizedBox.square(dimension: 16, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(LucideIcons.locateFixed, size: 18, color: Brand.green),
            label: Text(pinned ? 'Pin again' : (existing ? 'Move pin to where I am' : 'Use my current location')),
          ),
          if (error != null) ...[const SizedBox(height: 6), Text(error!, style: const TextStyle(fontSize: 12.5, color: Brand.danger))],
        ],
      ),
    );
  }
}

