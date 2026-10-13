// Requests for quotes: what a buyer asks farms for ("200 kg of tomatoes every week"), and a farm's offer on it.

import 'models.dart';

DateTime? _time(Object? value) => value is String ? DateTime.tryParse(value)?.toLocal() : null;

/// A farm's price for a request: per unit of one of its selling options, plus delivery.
class QuoteOffer {
  QuoteOffer({
    required this.id,
    required this.status,
    required this.productId,
    required this.title,
    required this.variantId,
    required this.label,
    required this.unit,
    required this.currency,
    required this.unitPrice,
    required this.quantity,
    required this.fulfilment,
    required this.deliveryFee,
    required this.total,
    required this.message,
    required this.validUntil,
    required this.updatedAt,
    this.farmId,
    this.farmName,
    this.farmVerified = false,
    this.rating,
    this.canOrderAgain = false,
  });

  factory QuoteOffer.fromJson(Map<String, dynamic> json) {
    final listing = json['listing'] as Map<String, dynamic>;
    final farm = json['farm'] as Map<String, dynamic>?;
    return QuoteOffer(
      id: json['id'] as String,
      status: json['status'] as String,
      farmId: farm?['id'] as String?,
      farmName: farm?['name'] as String?,
      farmVerified: farm?['verified'] as bool? ?? false,
      rating: farm == null ? null : Rating.fromJson(farm['rating']),
      canOrderAgain: json['can_order_again'] as bool? ?? false,
      productId: listing['product_id'] as String,
      title: listing['title'] as String,
      variantId: listing['variant_id'] as String,
      label: listing['label'] as String?,
      unit: Unit.fromJson(listing['unit'] as Map<String, dynamic>),
      currency: json['currency'] as String? ?? 'USD',
      unitPrice: json['unit_price'] as String,
      quantity: json['quantity'] as String,
      fulfilment: json['fulfilment'] as String,
      deliveryFee: json['delivery_fee'] as String,
      total: json['total'] as String,
      message: json['message'] as String?,
      validUntil: json['valid_until'] as String?,
      updatedAt: _time(json['updated_at']),
    );
  }

  final String id;

  /// pending (waiting for the buyer), accepted, declined or withdrawn.
  final String status;
  final String? farmId;
  final String? farmName;
  final bool farmVerified;
  final Rating? rating;

  /// An accepted offer on a repeating request, still within its dates: the buyer can order again at this price.
  final bool canOrderAgain;
  final String productId;
  final String title;
  final String variantId;
  final String? label;
  final Unit unit;
  final String currency;
  final String unitPrice;
  final String quantity;

  /// pickup or delivery.
  final String fulfilment;
  final String deliveryFee;
  final String total;
  final String? message;

  /// The last day the price holds for repeat orders (yyyy-mm-dd), if the farm set one.
  final String? validUntil;
  final DateTime? updatedAt;

  bool get isPending => status == 'pending';
}

/// A buyer's request. Farms see the buyer's first name only, and just their own offer.
class QuoteRequest {
  QuoteRequest({
    required this.id,
    required this.title,
    required this.categoryId,
    required this.categoryName,
    required this.quantity,
    required this.unit,
    required this.frequency,
    required this.frequencyLabel,
    required this.neededBy,
    required this.fulfilment,
    required this.province,
    required this.district,
    required this.notes,
    required this.status,
    required this.closesAt,
    required this.buyerName,
    required this.offers,
    required this.offersCount,
    required this.createdAt,
  });

  factory QuoteRequest.fromJson(Map<String, dynamic> json) {
    final category = json['category'] as Map<String, dynamic>;
    final offers = (json['offers'] as List? ?? const []).whereType<Map<String, dynamic>>();
    return QuoteRequest(
      id: json['id'] as String,
      title: json['title'] as String,
      categoryId: category['id'] as String,
      categoryName: category['name'] as String,
      quantity: json['quantity'] as String,
      unit: Unit.fromJson(json['unit'] as Map<String, dynamic>),
      frequency: json['frequency'] as String,
      frequencyLabel: json['frequency_label'] as String,
      neededBy: DateTime.parse(json['needed_by'] as String),
      fulfilment: json['fulfilment'] as String,
      province: json['province'] as String,
      district: json['district'] as String,
      notes: json['notes'] as String?,
      status: json['status'] as String,
      closesAt: _time(json['closes_at']),
      buyerName: (json['buyer'] as Map<String, dynamic>?)?['name'] as String?,
      offers: offers.map(QuoteOffer.fromJson).toList(),
      offersCount: json['offers_count'] as int? ?? offers.length,
      createdAt: _time(json['created_at']),
    );
  }

  final String id;
  final String title;
  final String categoryId;
  final String categoryName;
  final String quantity;
  final Unit unit;

  /// one_off, weekly, fortnightly or monthly; [frequencyLabel] reads "once", "every week"...
  final String frequency;
  final String frequencyLabel;
  final DateTime neededBy;

  /// pickup, delivery or either.
  final String fulfilment;
  final String province;
  final String district;
  final String? notes;

  /// open, awarded, closed or expired.
  final String status;
  final DateTime? closesAt;
  final String? buyerName;

  /// The offers, cheapest first. A farm sees only its own.
  final List<QuoteOffer> offers;
  final int offersCount;
  final DateTime? createdAt;

  /// For a farm: its offer, if it sent one.
  QuoteOffer? get offer => offers.firstOrNull;

  bool get isOpen => status == 'open';
  bool get repeats => frequency != 'one_off';
}
