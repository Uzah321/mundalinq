import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:mundalinq/api/farm_models.dart';
import 'package:mundalinq/api/models.dart';
import 'package:mundalinq/api/social_models.dart';
import 'package:mundalinq/util/farm_status.dart';
import 'package:mundalinq/util/format.dart';

// Real responses from the local API for the demo farmer (test/fixtures).
Map<String, dynamic> fixture(String name) =>
    jsonDecode(File('test/fixtures/$name.json').readAsStringSync()) as Map<String, dynamic>;

void main() {
  test("the farmer's farms carry their role and pin", () {
    final farms = (fixture('my_farms')['data'] as List).cast<Map<String, dynamic>>().map(Farm.fromJson).toList();
    final greenAcres = farms.firstWhere((farm) => farm.name == 'Demo Green Acres');

    expect(greenAcres.isOwner, isTrue);
    expect(greenAcres.verified, isTrue);
    expect(greenAcres.location?.district, 'Goromonzi');
    expect(greenAcres.location?.latitude, closeTo(-17.89, 0.01));
  });

  test("the farm's orders parse with item ids for accepting less", () {
    final page = Paged.fromJson(fixture('farm_orders'), FarmOrder.fromJson);
    final order = page.items.first;

    expect(order.items.first.id, isNotEmpty);
    expect(order.buyerName, isNotEmpty);
    expect(farmOrderStatus(order).text, isNotEmpty);
    expect(farmOrderNextStep(order), isNotEmpty);
  });

  test('managed listings carry stock and status', () {
    final listing = Paged.fromJson(fixture('farm_products'), ManagedListing.fromJson).items.first;

    expect(listing.stock.available, isNotEmpty);
    expect(listing.variants, isNotEmpty);
    expect(listing.cheapest, isNotNull);
    expect(listingStatus(listing).text, isNotEmpty);
  });

  test('earnings show fees as negative amounts', () {
    final earnings = Earnings.fromJson(fixture('farm_earnings')['data'] as Map<String, dynamic>);
    final fee = earnings.entries.firstWhere((entry) => entry.type == 'commission');

    expect(fee.amount, startsWith('-'));
    expect(formatMoney(fee.amount), startsWith('−\$'));
    expect(formatMoney(earnings.available), startsWith(r'$'));
  });

  test('analytics summarise the last 30 days', () {
    final analytics = FarmAnalytics.fromJson(fixture('farm_analytics')['data'] as Map<String, dynamic>);

    expect(analytics.orders, greaterThan(0));
    expect(analytics.acceptanceRate, isNotNull);
    expect(analytics.topProducts.first['title'], isNotNull);
  });

  test('notifications carry the ids that open the right screen', () {
    final page = Paged.fromJson(fixture('notifications'), AppNotification.fromJson);
    final order = page.items.firstWhere((notification) => notification.category == 'orders');

    expect(order.context.containsKey('seller_order_id') || order.context.containsKey('order_id'), isTrue);
  });

  test('a farm with no reviews has an empty summary', () {
    final summary = ReviewSummary.fromJson((fixture('farm_reviews')['meta'] as Map<String, dynamic>)['summary'] as Map<String, dynamic>);
    expect(summary.count, 0);
    expect(summary.stars[5], 0);
  });
}
