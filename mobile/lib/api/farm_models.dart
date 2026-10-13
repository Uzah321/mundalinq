// The farm side of the API: farms, their orders, listings, money and verification.

import 'models.dart';
import 'server.dart';

List<T> _list<T>(Object? value, T Function(Map<String, dynamic>) parse) =>
    value is List ? value.whereType<Map<String, dynamic>>().map(parse).toList() : <T>[];

DateTime? _time(Object? value) => value is String ? DateTime.tryParse(value)?.toLocal() : null;

double? _number(Object? value) => value == null ? null : double.tryParse('$value');

const farmerTypes = {
  'smallholder': 'Smallholder farmer',
  'commercial': 'Commercial farm',
  'cooperative': 'Co-operative',
  'supply_agent': 'Supply agent',
};

class FarmLocation {
  FarmLocation({
    required this.id,
    required this.name,
    required this.province,
    required this.district,
    required this.ward,
    required this.addressLine,
    required this.latitude,
    required this.longitude,
  });

  factory FarmLocation.fromJson(Map<String, dynamic> json) => FarmLocation(
        id: json['id'] as String,
        name: json['name'] as String?,
        province: json['province'] as String,
        district: json['district'] as String,
        ward: json['ward'] as String?,
        addressLine: json['address_line'] as String?,
        latitude: _number(json['latitude']),
        longitude: _number(json['longitude']),
      );

  final String id;
  final String? name;
  final String province;
  final String district;
  final String? ward;
  final String? addressLine;
  final double? latitude;
  final double? longitude;

  String get summary => [name, addressLine, ward == null ? null : 'Ward $ward', district, province].whereType<String>().join(', ');
}

class Farm {
  Farm({
    required this.id,
    required this.name,
    required this.description,
    required this.farmerType,
    required this.contactPhone,
    required this.offersPickup,
    required this.offersDelivery,
    required this.deliveryRadiusKm,
    required this.deliveryFee,
    required this.freeDeliveryOver,
    required this.verificationStatus,
    required this.verified,
    required this.rating,
    required this.location,
    required this.myRole,
  });

  factory Farm.fromJson(Map<String, dynamic> json) => Farm(
        id: json['id'] as String,
        name: json['name'] as String,
        description: json['description'] as String?,
        farmerType: json['farmer_type'] as String? ?? 'smallholder',
        contactPhone: json['contact_phone'] as String?,
        offersPickup: json['offers_pickup'] as bool? ?? true,
        offersDelivery: json['offers_delivery'] as bool? ?? false,
        deliveryRadiusKm: _number(json['delivery_radius_km']),
        deliveryFee: json['delivery_fee'] as String? ?? '0.00',
        freeDeliveryOver: json['free_delivery_over'] as String?,
        verificationStatus: json['verification_status'] as String? ?? 'unverified',
        verified: json['verified'] as bool? ?? false,
        rating: Rating.fromJson(json['rating']),
        location: json['primary_location'] == null ? null : FarmLocation.fromJson(json['primary_location'] as Map<String, dynamic>),
        myRole: json['my_role'] as String?,
      );

  final String id;
  final String name;
  final String? description;
  final String farmerType;
  final String? contactPhone;
  final bool offersPickup;
  final bool offersDelivery;
  final double? deliveryRadiusKm;
  final String deliveryFee;
  final String? freeDeliveryOver;

  /// unverified, pending, approved or rejected.
  final String verificationStatus;
  final bool verified;
  final Rating rating;
  final FarmLocation? location;

  /// owner or manager, when loaded through the user's own farms.
  final String? myRole;

  bool get isOwner => myRole == 'owner';

  bool get needsBusinessRegistration => farmerType == 'commercial' || farmerType == 'cooperative';
}

/// An order line as the farm packs it; [id] is needed to supply less when accepting.
class FarmOrderItem {
  FarmOrderItem({
    required this.id,
    required this.title,
    required this.label,
    required this.unit,
    required this.quantity,
    required this.confirmedQuantity,
    required this.unitPrice,
    required this.lineTotal,
  });

  factory FarmOrderItem.fromJson(Map<String, dynamic> json) => FarmOrderItem(
        id: json['id'] as String,
        title: json['title'] as String,
        label: json['label'] as String?,
        unit: Unit.fromJson(json['unit'] as Map<String, dynamic>),
        quantity: json['quantity'] as String,
        confirmedQuantity: json['confirmed_quantity'] as String?,
        unitPrice: json['unit_price'] as String,
        lineTotal: json['line_total'] as String,
      );

  final String id;
  final String title;
  final String? label;
  final Unit unit;
  final String quantity;
  final String? confirmedQuantity;
  final String unitPrice;
  final String lineTotal;
}

/// FarmSellerOrderResource: one order as the farm sees it.
class FarmOrder {
  FarmOrder({
    required this.id,
    required this.number,
    required this.status,
    required this.farmId,
    required this.fulfilmentMethod,
    required this.buyerName,
    required this.buyerPhone,
    required this.note,
    required this.deliveryAddress,
    required this.items,
    required this.subtotal,
    required this.deliveryFee,
    required this.total,
    required this.currency,
    required this.rejectionReason,
    required this.confirmBy,
    required this.disputeStatus,
    required this.createdAt,
  });

  factory FarmOrder.fromJson(Map<String, dynamic> json) {
    final buyer = json['buyer'] as Map<String, dynamic>? ?? const {};
    final dispute = json['dispute'] as Map<String, dynamic>?;
    return FarmOrder(
      id: json['id'] as String,
      number: json['number'] as String,
      status: json['status'] as String,
      farmId: json['farm_id'] as String,
      fulfilmentMethod: json['fulfilment_method'] as String,
      buyerName: buyer['name'] as String? ?? 'Buyer',
      buyerPhone: buyer['phone'] as String?,
      note: json['note'] as String?,
      deliveryAddress: json['delivery_address'] as Map<String, dynamic>?,
      items: _list(json['items'], FarmOrderItem.fromJson),
      subtotal: json['subtotal'] as String,
      deliveryFee: json['delivery_fee'] as String,
      total: json['total'] as String,
      currency: json['currency'] as String? ?? 'USD',
      rejectionReason: json['rejection_reason'] as String?,
      confirmBy: _time(json['confirm_by']),
      disputeStatus: dispute?['status'] as String?,
      createdAt: _time(json['created_at']),
    );
  }

  final String id;
  final String number;
  final String status;
  final String farmId;
  final String fulfilmentMethod;
  final String buyerName;

  /// Only once the farm has committed to the order.
  final String? buyerPhone;
  final String? note;
  final Map<String, dynamic>? deliveryAddress;
  final List<FarmOrderItem> items;
  final String subtotal;
  final String deliveryFee;
  final String total;
  final String currency;
  final String? rejectionReason;
  final DateTime? confirmBy;
  final String? disputeStatus;
  final DateTime? createdAt;

  bool get isPickup => fulfilmentMethod == 'pickup';
}

class StockSummary {
  StockSummary({required this.unit, required this.onHand, required this.reserved, required this.available, required this.lowThreshold, required this.isLow});

  factory StockSummary.fromJson(Map<String, dynamic> json) => StockSummary(
        unit: json['unit'] as String,
        onHand: json['on_hand'] as String,
        reserved: json['reserved'] as String,
        available: json['available'] as String,
        lowThreshold: json['low_stock_threshold'] as String?,
        isLow: json['is_low'] as bool? ?? false,
      );

  final String unit;
  final String onHand;
  final String reserved;
  final String available;
  final String? lowThreshold;
  final bool isLow;
}

/// ManagedProductResource: a listing as its farm manages it, whatever its status.
class ManagedListing {
  ManagedListing({
    required this.id,
    required this.title,
    required this.description,
    required this.categoryId,
    required this.categoryName,
    required this.categorySlug,
    required this.grade,
    required this.currency,
    required this.stockUnit,
    required this.variants,
    required this.images,
    required this.coverUrl,
    required this.status,
    required this.rejectionReason,
    required this.expiresAt,
    required this.isExpired,
    required this.offersPickup,
    required this.offersDelivery,
    required this.stock,
    required this.harvestedOn,
  });

  factory ManagedListing.fromJson(Map<String, dynamic> json) {
    final category = json['category'] as Map<String, dynamic>;
    final cover = json['cover_image'] as Map<String, dynamic>?;
    return ManagedListing(
      id: json['id'] as String,
      title: json['title'] as String,
      description: json['description'] as String?,
      categoryId: category['id'] as String?,
      categoryName: category['name'] as String,
      categorySlug: category['slug'] as String,
      grade: json['quality_grade'] as String?,
      currency: json['currency'] as String? ?? 'USD',
      stockUnit: Unit.fromJson(json['stock_unit'] as Map<String, dynamic>),
      variants: _list(json['variants'], ManagedVariant.fromJson),
      images: _list(json['images'], ProductImage.fromJson),
      coverUrl: cover == null ? null : Server.media(cover['thumb_url'] as String),
      status: json['status'] as String,
      rejectionReason: json['rejection_reason'] as String?,
      expiresAt: _time(json['expires_at']),
      isExpired: json['is_expired'] as bool? ?? false,
      offersPickup: json['offers_pickup'] as bool? ?? true,
      offersDelivery: json['offers_delivery'] as bool? ?? false,
      stock: StockSummary.fromJson(json['stock'] as Map<String, dynamic>),
      harvestedOn: json['last_harvested_on'] as String?,
    );
  }

  final String id;
  final String title;
  final String? description;
  final String? categoryId;
  final String categoryName;
  final String categorySlug;
  final String? grade;
  final String currency;
  final Unit stockUnit;
  final List<ManagedVariant> variants;
  final List<ProductImage> images;
  final String? coverUrl;

  /// draft, pending_review, active, paused or rejected; [isExpired] marks live listings past their date.
  final String status;
  final String? rejectionReason;
  final DateTime? expiresAt;
  final bool isExpired;
  final bool offersPickup;
  final bool offersDelivery;
  final StockSummary stock;
  final String? harvestedOn;

  String? get coverImage => coverUrl ?? images.firstOrNull?.thumbUrl;

  ManagedVariant? get cheapest {
    ManagedVariant? best;
    for (final variant in variants.where((variant) => variant.isActive)) {
      if (best == null || (double.tryParse(variant.price) ?? 0) < (double.tryParse(best.price) ?? 0)) best = variant;
    }
    return best;
  }
}

class ManagedVariant {
  ManagedVariant({
    required this.id,
    required this.unit,
    required this.label,
    required this.baseQuantity,
    required this.price,
    required this.minOrderQuantity,
    required this.isActive,
    required this.hasTiers,
  });

  factory ManagedVariant.fromJson(Map<String, dynamic> json) => ManagedVariant(
        id: json['id'] as String,
        unit: Unit.fromJson(json['unit'] as Map<String, dynamic>),
        label: json['label'] as String?,
        baseQuantity: json['base_quantity'] as String,
        price: json['price'] as String,
        minOrderQuantity: json['min_order_quantity'] as String,
        isActive: json['is_active'] as bool? ?? true,
        hasTiers: (json['price_tiers'] as List? ?? const []).isNotEmpty,
      );

  final String id;
  final Unit unit;
  final String? label;
  final String baseQuantity;
  final String price;
  final String minOrderQuantity;
  final bool isActive;
  final bool hasTiers;

  String get name => label ?? '${unit.name[0].toUpperCase()}${unit.name.substring(1)}';
}

class StockBatch {
  StockBatch({required this.id, required this.code, required this.harvestedOn, required this.received, required this.onHand, required this.createdAt});

  factory StockBatch.fromJson(Map<String, dynamic> json) => StockBatch(
        id: json['id'] as String,
        code: json['code'] as String,
        harvestedOn: json['harvested_on'] as String?,
        received: json['quantity_received'] as String,
        onHand: json['quantity_on_hand'] as String,
        createdAt: _time(json['created_at']),
      );

  final String id;
  final String code;
  final String? harvestedOn;
  final String received;
  final String onHand;
  final DateTime? createdAt;
}

class LedgerEntry {
  LedgerEntry({required this.type, required this.amount, required this.description, required this.available, required this.createdAt});

  factory LedgerEntry.fromJson(Map<String, dynamic> json) => LedgerEntry(
        type: json['type'] as String,
        amount: json['amount'] as String,
        description: json['description'] as String?,
        available: json['available'] as bool? ?? false,
        createdAt: _time(json['created_at']),
      );

  final String type;
  final String amount;
  final String? description;
  final bool available;
  final DateTime? createdAt;
}

class Earnings {
  Earnings({required this.currency, required this.pending, required this.available, required this.entries});

  factory Earnings.fromJson(Map<String, dynamic> json) => Earnings(
        currency: json['currency'] as String? ?? 'USD',
        pending: json['pending'] as String,
        available: json['available'] as String,
        entries: _list(json['entries'], LedgerEntry.fromJson),
      );

  final String currency;

  /// Sold but not yet final (the buyer can still report a problem).
  final String pending;

  /// Final, waiting for the next payout.
  final String available;
  final List<LedgerEntry> entries;
}

class PayoutAccount {
  PayoutAccount({required this.provider, required this.accountName, required this.last4, required this.onHoldUntil});

  factory PayoutAccount.fromJson(Map<String, dynamic> json) => PayoutAccount(
        provider: json['provider'] as String,
        accountName: json['account_name'] as String,
        last4: json['account_number_last4'] as String?,
        onHoldUntil: _time(json['on_hold_until']),
      );

  final String provider;
  final String accountName;
  final String? last4;

  /// Payouts pause for a while after the account changes, in case someone else changed it.
  final DateTime? onHoldUntil;
}

class Payout {
  Payout({required this.amount, required this.currency, required this.provider, required this.last4, required this.reference, required this.paidAt});

  factory Payout.fromJson(Map<String, dynamic> json) => Payout(
        amount: json['amount'] as String,
        currency: json['currency'] as String? ?? 'USD',
        provider: json['provider'] as String?,
        last4: json['account_number_last4'] as String?,
        reference: json['reference'] as String?,
        paidAt: _time(json['paid_at']),
      );

  final String amount;
  final String currency;
  final String? provider;
  final String? last4;
  final String? reference;
  final DateTime? paidAt;
}

class Verification {
  Verification({required this.status, required this.rejectionReason, required this.submittedAt});

  factory Verification.fromJson(Map<String, dynamic> json) => Verification(
        status: json['status'] as String,
        rejectionReason: json['rejection_reason'] as String?,
        submittedAt: _time(json['submitted_at']),
      );

  final String status;
  final String? rejectionReason;
  final DateTime? submittedAt;
}

/// The last 30 days, from GET /farms/{id}/analytics.
class FarmAnalytics {
  FarmAnalytics({
    required this.currency,
    required this.orders,
    required this.gross,
    required this.platformFees,
    required this.net,
    required this.received,
    required this.missed,
    required this.acceptanceRate,
    required this.topProducts,
    required this.liveListings,
    required this.lowStock,
  });

  factory FarmAnalytics.fromJson(Map<String, dynamic> json) {
    final sales = json['sales'] as Map<String, dynamic>;
    final orders = json['orders'] as Map<String, dynamic>;
    final stock = json['stock'] as Map<String, dynamic>;
    return FarmAnalytics(
      currency: json['currency'] as String? ?? 'USD',
      orders: sales['orders'] as int? ?? 0,
      gross: sales['gross'] as String,
      platformFees: sales['platform_fees'] as String,
      net: sales['net'] as String,
      received: orders['received'] as int? ?? 0,
      missed: orders['missed'] as int? ?? 0,
      acceptanceRate: orders['acceptance_rate'] as String?,
      topProducts: (json['top_products'] as List? ?? const []).whereType<Map<String, dynamic>>().toList(),
      liveListings: stock['live_listings'] as int? ?? 0,
      lowStock: stock['low_stock'] as int? ?? 0,
    );
  }

  final String currency;
  final int orders;
  final String gross;
  final String platformFees;
  final String net;
  final int received;
  final int missed;
  final String? acceptanceRate;
  final List<Map<String, dynamic>> topProducts;
  final int liveListings;
  final int lowStock;
}

class Review {
  Review({
    required this.id,
    required this.rating,
    required this.comment,
    required this.buyerName,
    required this.products,
    required this.farmResponse,
    required this.createdAt,
  });

  factory Review.fromJson(Map<String, dynamic> json) => Review(
        id: json['id'] as String,
        rating: json['rating'] as int,
        comment: json['comment'] as String?,
        buyerName: (json['buyer'] as Map<String, dynamic>?)?['name'] as String? ?? 'A buyer',
        products: (json['products'] as List? ?? const []).whereType<Map<String, dynamic>>().map((product) => product['title'] as String).toList(),
        farmResponse: json['farm_response'] as String?,
        createdAt: _time(json['created_at']),
      );

  final String id;
  final int rating;
  final String? comment;
  final String buyerName;
  final List<String> products;
  final String? farmResponse;
  final DateTime? createdAt;
}

/// The `meta.summary` of a reviews list: average and count per star.
class ReviewSummary {
  ReviewSummary({required this.average, required this.count, required this.stars});

  factory ReviewSummary.fromJson(Map<String, dynamic>? json) => ReviewSummary(
        average: _number(json?['average']),
        count: json?['count'] as int? ?? 0,
        stars: {
          for (final entry in ((json?['stars'] as Map<String, dynamic>?) ?? const {}).entries) int.parse(entry.key): entry.value as int,
        },
      );

  final double? average;
  final int count;
  final Map<int, int> stars;
}
