import '../api/models.dart';

// The API exchanges money and quantities as decimal strings. Arithmetic here works on whole cents and
// thousandths (mirroring App\Support\Money and Quantity) so estimates never drift through doubles.
// The server always recomputes the real totals.

int? _parseScaled(String value, int scale) {
  final match = RegExp(r'^(\d+)(?:\.(\d+))?$').firstMatch(value.trim());
  if (match == null) return null;

  final fraction = match.group(2) ?? '';
  if (fraction.length > scale) return null;

  var factor = 1;
  for (var i = 0; i < scale; i++) {
    factor *= 10;
  }
  return int.parse(match.group(1)!) * factor + int.parse(fraction.padRight(scale, '0'));
}

int? toMinor(String amount) => _parseScaled(amount, 2);

int? toMilli(String quantity) => _parseScaled(quantity, 3);

String fromMinor(int minor) {
  final digits = minor.toString().padLeft(3, '0');
  return '${digits.substring(0, digits.length - 2)}.${digits.substring(digits.length - 2)}';
}

/// 1500 -> "1.5", 28000 -> "28": the API's unpadded quantity form.
String fromMilli(int milli) {
  final whole = milli ~/ 1000;
  final fraction = (milli % 1000).toString().padLeft(3, '0').replaceFirst(RegExp(r'0+$'), '');
  return fraction.isEmpty ? '$whole' : '$whole.$fraction';
}

const _symbols = {'USD': r'$', 'ZAR': 'R', 'BWP': 'P', 'ZWG': 'ZiG '};

/// "$1,234.50"; negative amounts (fees, refunds in the earnings ledger) as "−$0.12".
String formatMoney(String amount, [String currency = 'USD']) {
  final negative = amount.trim().startsWith('-');
  final minor = toMinor(negative ? amount.trim().substring(1) : amount) ?? 0;
  final digits = fromMinor(minor);
  final parts = digits.split('.');
  final whole = parts[0].replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
  return '${negative && minor != 0 ? '−' : ''}${_symbols[currency] ?? '$currency '}$whole.${parts[1]}';
}

String formatMinor(int minor, [String currency = 'USD']) => formatMoney(fromMinor(minor), currency);

String unitName(Unit unit, String quantity) => toMilli(quantity) == 1000 ? unit.name : unit.pluralName;

/// "2 crates", "0.5 kilograms".
String formatQuantity(String quantity, Unit unit) => '$quantity ${unitName(unit, quantity)}';

/// Price per unit at a quantity, using the best bulk tier reached (as ProductVariant::unitPriceMinorFor).
int unitPriceMinor(Variant variant, String quantity) {
  final milli = toMilli(quantity) ?? 0;
  int? bestMin;
  String? bestPrice;

  for (final tier in variant.priceTiers) {
    final min = toMilli(tier.minQuantity);
    if (min != null && milli >= min && (bestMin == null || min > bestMin)) {
      bestMin = min;
      bestPrice = tier.price;
    }
  }

  return toMinor(bestPrice ?? variant.price) ?? 0;
}

int lineTotalMinor(Variant variant, String quantity) =>
    (unitPriceMinor(variant, quantity) * (toMilli(quantity) ?? 0) / 1000).round();

/// Largest quantity a buyer can order now: the variant's cap or what is in stock, whichever is lower.
int maxOrderMilli(Variant variant) {
  final available = toMilli(variant.availableQuantity) ?? 0;
  final cap = variant.maxOrderQuantity == null ? null : toMilli(variant.maxOrderQuantity!);
  return cap == null ? available : (cap < available ? cap : available);
}

/// One unit when allowed, otherwise the minimum order.
String initialQuantity(Variant variant) {
  final min = toMilli(variant.minOrderQuantity) ?? 1000;
  final max = maxOrderMilli(variant);
  final oneOrMax = max < 1000 ? max : 1000;
  return fromMilli(min > oneOrMax ? min : oneOrMax);
}

int daysSince(String date) {
  final then = DateTime.parse(date);
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  return today.difference(DateTime(then.year, then.month, then.day)).inDays;
}

const _months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

String? harvestedLabel(String? date) {
  if (date == null) return null;
  final days = daysSince(date);
  if (days <= 0) return 'Harvested today';
  if (days == 1) return 'Harvested yesterday';
  final then = DateTime.parse(date);
  return 'Harvested ${then.day} ${_months[then.month - 1]}';
}

String initials(String name) => name
    .trim()
    .split(RegExp(r'\s+'))
    .where((part) => part.isNotEmpty)
    .take(2)
    .map((part) => part[0].toUpperCase())
    .join();

/// +263770000003 -> +263 77 000 0003
String formatPhone(String phone) {
  final match = RegExp(r'^\+263(\d{2})(\d{3})(\d{4})$').firstMatch(phone);
  return match == null ? phone : '+263 ${match.group(1)} ${match.group(2)} ${match.group(3)}';
}

String formatClock(DateTime time) =>
    '${time.hour.toString().padLeft(2, '0')}:${time.minute.toString().padLeft(2, '0')}';

String formatDate(DateTime time) => '${time.day} ${_months[time.month - 1]}';

/// "today at 14:20", "tomorrow at 08:20", "15 Oct at 09:00".
String formatWhen(DateTime time) {
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final days = DateTime(time.year, time.month, time.day).difference(today).inDays;
  final day = switch (days) { 0 => 'today', 1 => 'tomorrow', -1 => 'yesterday', _ => formatDate(time) };
  return '$day at ${formatClock(time)}';
}
