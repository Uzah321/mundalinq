import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../state/session.dart';
import '../state/shopping_area.dart';
import '../theme.dart';

/// A new delivery address. The pin lets the farm check the address is within its delivery distance, so it is
/// taken from the phone's location: add the address while you are there.
class AddressFormScreen extends StatefulWidget {
  const AddressFormScreen({super.key});

  @override
  State<AddressFormScreen> createState() => _AddressFormScreenState();
}

class _AddressFormScreenState extends State<AddressFormScreen> {
  static const _labels = ['Home', 'Work', 'Other'];

  late final _name = TextEditingController(text: context.read<Session>().user?.name);
  late final _phone = TextEditingController(text: context.read<Session>().user?.phone);
  final _district = TextEditingController();
  final _addressLine = TextEditingController();
  final _directions = TextEditingController();
  String _label = 'Home';
  late String? _province = context.read<ShoppingAreaController>().area?.province;
  bool _isDefault = true;
  Position? _pin;
  bool _locating = false;
  bool _saving = false;
  ApiException? _error;
  String? _pinError;

  @override
  void dispose() {
    for (final controller in [_name, _phone, _district, _addressLine, _directions]) {
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
        throw 'Allow MundaLinq to use your location so the farm knows where to deliver.';
      }
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.low, timeLimit: Duration(seconds: 20)),
      );
      setState(() => _pin = position);
    } on String catch (message) {
      setState(() => _pinError = message);
    } catch (_) {
      setState(() => _pinError = "We couldn't find your location. Try again, ideally outside.");
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  Future<void> _save() async {
    if (_pin == null) {
      setState(() => _pinError = 'Pin the address first.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final response = await context.read<ApiClient>().post('/me/addresses', {
        'label': _label,
        'recipient_name': _name.text.trim(),
        'phone': _phone.text.trim(),
        'province': _province,
        'district': _district.text.trim(),
        'address_line': _addressLine.text.trim(),
        if (_directions.text.trim().isNotEmpty) 'directions': _directions.text.trim(),
        'latitude': _pin!.latitude,
        'longitude': _pin!.longitude,
        'is_default': _isDefault,
      }) as Map<String, dynamic>;
      if (mounted) Navigator.pop(context, Address.fromJson(response['data'] as Map<String, dynamic>));
    } on ApiException catch (error) {
      setState(() => _error = error);
    } catch (error) {
      setState(() => _error = ApiException(0, errorMessage(error)));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('New address')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Wrap(
            spacing: 8,
            children: [
              for (final label in _labels)
                ChoiceChip(
                  label: Text(label),
                  selected: _label == label,
                  onSelected: (_) => setState(() => _label = label),
                  selectedColor: Brand.mint,
                  showCheckmark: false,
                  labelStyle: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700, color: _label == label ? Brand.green : Brand.ink),
                  side: BorderSide(color: _label == label ? Brand.green : Brand.border),
                ),
            ],
          ),
          const SizedBox(height: 16),
          _field('Who receives it', _name, error: _error?.field('recipient_name')),
          _field('Their phone number', _phone, keyboard: TextInputType.phone, error: _error?.field('phone')),
          Padding(
            padding: const EdgeInsets.only(bottom: 14),
            child: DropdownButtonFormField<String>(
              initialValue: _province,
              decoration: InputDecoration(labelText: 'Province', errorText: _error?.field('province')),
              items: [for (final province in provinces) DropdownMenuItem(value: province, child: Text(province))],
              onChanged: (value) => setState(() => _province = value),
            ),
          ),
          _field('Town or district', _district, hint: 'e.g. Harare, Chitungwiza', error: _error?.field('district')),
          _field('Street address', _addressLine, hint: 'House number and street, or the area', error: _error?.field('address_line')),
          _field('Directions (optional)', _directions, hint: 'e.g. blue gate opposite the clinic', lines: 2, error: _error?.field('directions')),
          const SizedBox(height: 4),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: _pin == null ? Brand.muted : Brand.mint,
              borderRadius: BorderRadius.circular(Brand.radius),
              border: Border.all(color: _pin == null ? Brand.border : Brand.green),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(_pin == null ? LucideIcons.mapPinOff : LucideIcons.mapPinCheck, size: 18, color: Brand.green),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _pin == null ? 'Pin this address on the map' : 'Pinned at your current location',
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                const Text(
                  "Farms use the pin to check you're within their delivery distance. Pin it while you're at the address.",
                  style: TextStyle(fontSize: 12.5, height: 1.5, color: Brand.mutedText),
                ),
                const SizedBox(height: 10),
                OutlinedButton.icon(
                  onPressed: _locating ? null : _locate,
                  icon: _locating
                      ? const SizedBox.square(dimension: 16, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(LucideIcons.locateFixed, size: 18, color: Brand.green),
                  label: Text(_pin == null ? 'Use my current location' : 'Pin again'),
                ),
                if (_pinError != null || _error?.field('latitude') != null) ...[
                  const SizedBox(height: 6),
                  Text(_pinError ?? _error!.field('latitude')!, style: const TextStyle(fontSize: 12.5, color: Brand.danger)),
                ],
              ],
            ),
          ),
          SwitchListTile(
            value: _isDefault,
            onChanged: (value) => setState(() => _isDefault = value),
            contentPadding: EdgeInsets.zero,
            title: const Text('Use for my deliveries by default'),
          ),
          if (_error != null && _error!.fieldErrors.isEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Text(_error!.message, style: const TextStyle(color: Brand.danger)),
            ),
          FilledButton(
            onPressed: _saving ? null : _save,
            child: _saving
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Save address'),
          ),
        ],
      ),
    );
  }

  Widget _field(String label, TextEditingController controller, {String? hint, String? error, TextInputType? keyboard, int lines = 1}) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 14),
        child: TextField(
          controller: controller,
          keyboardType: keyboard,
          minLines: lines,
          maxLines: lines,
          textInputAction: TextInputAction.next,
          decoration: InputDecoration(labelText: label, hintText: hint, errorText: error, errorMaxLines: 2),
        ),
      );
}
