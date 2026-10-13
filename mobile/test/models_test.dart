import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:mundalinq/api/models.dart';
import 'package:mundalinq/util/order_status.dart';

// Fixtures are real responses from the local API (test/fixtures), so the models are checked against what the
// server actually sends rather than what we think it sends.
Map<String, dynamic> fixture(String name) =>
    jsonDecode(File('test/fixtures/$name.json').readAsStringSync()) as Map<String, dynamic>;

void main() {
  test('a paid order parses with its handover code and pickup point', () {
    final order = Order.fromJson(fixture('order_paid')['data'] as Map<String, dynamic>);
    final seller = order.sellerOrders.single;

    expect(order.status, 'paid');
    expect(order.total, '2.40');
    expect(order.latestPayment?.status, 'succeeded');
    expect(order.cancellable, isFalse);
    expect(seller.isPickup, isTrue);
    expect(seller.handoverCode, matches(RegExp(r'^\d{6}$')));
    expect(seller.pickupLocations.single['district'], 'Goromonzi');
    expect(seller.items.single.title, 'Roma tomatoes');
    expect(orderStatus(order).text, 'Paid · in progress');
    expect(sellerOrderStatus(seller).text, 'Being prepared');
    expect(orderNextStep(order), startsWith('Paid. The farm is preparing'));
  });

  test('a page of orders parses', () {
    final page = Paged.fromJson(fixture('orders_page'), Order.fromJson);
    expect(page.items, isNotEmpty);
    expect(page.currentPage, 1);
  });

  test('a delivery quote carries the fee and the methods each farm offers', () {
    final quote = Quote.fromJson(fixture('quote_delivery')['data'] as Map<String, dynamic>);
    final seller = quote.sellers.single;

    expect(seller.fulfilmentMethod, 'delivery');
    expect(seller.availableMethods, containsAll(['pickup', 'delivery']));
    expect(quote.deliveryFee, isNot('0.00'));
    expect(quote.canPlaceOrder, isTrue);
    expect(quote.problems, isEmpty);
  });

  test('addresses parse', () {
    final addresses = (fixture('addresses')['data'] as List).cast<Map<String, dynamic>>().map(Address.fromJson).toList();
    expect(addresses.first.addressLine, isNotEmpty);
    expect(addresses.first.phone, startsWith('+263'));
  });
}
