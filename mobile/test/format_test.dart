import 'package:flutter_test/flutter_test.dart';
import 'package:mundalinq/api/models.dart';
import 'package:mundalinq/util/format.dart';

Variant variant({
  String price = '1.20',
  List<PriceTier> tiers = const [],
  String min = '1',
  String? max,
  String available = '100',
}) =>
    Variant(
      id: 'v1',
      unit: Unit(code: 'kg', name: 'kilogram', pluralName: 'kilograms'),
      label: null,
      price: price,
      priceTiers: tiers,
      minOrderQuantity: min,
      maxOrderQuantity: max,
      availableQuantity: available,
    );

void main() {
  group('decimal strings', () {
    test('parse into whole cents and thousandths', () {
      expect(toMinor('1.20'), 120);
      expect(toMinor('30'), 3000);
      expect(toMinor('0.5'), 50);
      expect(toMilli('1.5'), 1500);
      expect(toMilli('28'), 28000);
      expect(toMilli('0.001'), 1);
    });

    test('reject what the API never sends', () {
      expect(toMinor('1.234'), isNull);
      expect(toMinor('-1'), isNull);
      expect(toMilli('abc'), isNull);
      expect(toMilli(''), isNull);
    });

    test('format back without padding', () {
      expect(fromMinor(5), '0.05');
      expect(fromMinor(120), '1.20');
      expect(fromMilli(1500), '1.5');
      expect(fromMilli(28000), '28');
      expect(fromMilli(250), '0.25');
    });
  });

  test('money shows the currency symbol and thousands separators', () {
    expect(formatMoney('1.2'), r'$1.20');
    expect(formatMoney('650.00'), r'$650.00');
    expect(formatMoney('12345.6'), r'$12,345.60');
    expect(formatMoney('4.00', 'ZAR'), 'R4.00');
  });

  test('bulk tiers apply once their minimum quantity is reached', () {
    final maize = variant(price: '18.00', tiers: [PriceTier('10', '17.00')]);
    expect(unitPriceMinor(maize, '9'), 1800);
    expect(unitPriceMinor(maize, '10'), 1700);
    expect(lineTotalMinor(maize, '10'), 17000);
  });

  test('line totals round fractional quantities to the cent', () {
    expect(lineTotalMinor(variant(price: '1.20'), '0.333'), 40);
    expect(lineTotalMinor(variant(price: '2.50'), '1.5'), 375);
  });

  test('orders start at one unit, the minimum, or what is left', () {
    expect(initialQuantity(variant()), '1');
    expect(initialQuantity(variant(min: '5')), '5');
    expect(initialQuantity(variant(available: '0.5', min: '0.25')), '0.5');
    expect(maxOrderMilli(variant(max: '20', available: '100')), 20000);
  });

  test('phone numbers are grouped for reading', () {
    expect(formatPhone('+263786809082'), '+263 78 680 9082');
    expect(formatPhone('0771234567'), '0771234567');
  });
}
