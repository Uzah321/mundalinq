// Shapes of the MundaLinq API (backend/app/Http/Resources). Prices and quantities are decimal strings.

import 'server.dart';

List<T> _list<T>(Object? value, T Function(Map<String, dynamic>) parse) =>
    value is List ? value.whereType<Map<String, dynamic>>().map(parse).toList() : <T>[];

class Paged<T> {
  Paged(this.items, this.currentPage, this.lastPage, this.total);

  factory Paged.fromJson(Map<String, dynamic> json, T Function(Map<String, dynamic>) parse) {
    final meta = json['meta'] as Map<String, dynamic>? ?? const {};
    return Paged(
      _list(json['data'], parse),
      meta['current_page'] as int? ?? 1,
      meta['last_page'] as int? ?? 1,
      meta['total'] as int? ?? 0,
    );
  }

  final List<T> items;
  final int currentPage;
  final int lastPage;
  final int total;

  bool get hasMore => currentPage < lastPage;
}

class Category {
  Category({required this.id, required this.name, required this.slug, required this.children});

  factory Category.fromJson(Map<String, dynamic> json) => Category(
        id: json['id'] as String,
        name: json['name'] as String,
        slug: json['slug'] as String,
        children: _list(json['children'], Category.fromJson),
      );

  final String id;
  final String name;
  final String slug;
  final List<Category> children;
}

class Unit {
  Unit({required this.code, required this.name, required this.pluralName, this.allowsFraction, this.measure});

  factory Unit.fromJson(Map<String, dynamic> json) => Unit(
        code: json['code'] as String,
        name: json['name'] as String,
        pluralName: json['plural_name'] as String,
        allowsFraction: json['allows_fraction'] as bool?,
        measure: json['measure'] as String?,
      );

  final String code;
  final String name;
  final String pluralName;

  /// Only present on the full unit list: whether it can be ordered in fractions (kg) or only whole (crates).
  final bool? allowsFraction;

  /// mass, volume or count; only on the full unit list.
  final String? measure;

  /// Whether one of these converts to [stock] by itself (kg to tonne) or the farmer must say how much it holds
  /// (a crate of tomatoes).
  bool convertsTo(Unit stock) => code == stock.code || (measure != null && measure != 'count' && measure == stock.measure);
}

class ProductImage {
  ProductImage({required this.id, required this.url, required this.thumbUrl});

  factory ProductImage.fromJson(Map<String, dynamic> json) => ProductImage(
        id: json['id'] as String,
        url: Server.media(json['url'] as String),
        thumbUrl: Server.media(json['thumb_url'] as String),
      );

  final String id;
  final String url;
  final String thumbUrl;
}

class Rating {
  Rating(this.average, this.count);

  factory Rating.fromJson(Object? json) {
    final map = json is Map<String, dynamic> ? json : const <String, dynamic>{};
    final average = map['average'];
    return Rating(average == null ? null : double.tryParse('$average'), map['count'] as int? ?? 0);
  }

  final double? average;
  final int count;
}

/// ProductCardResource: a listing in search results and favourites.
class ProductCard {
  ProductCard({
    required this.id,
    required this.title,
    required this.categorySlug,
    required this.grade,
    required this.currency,
    required this.price,
    required this.unitCode,
    required this.imageUrl,
    required this.farmName,
    required this.district,
    required this.distanceKm,
    required this.harvestedOn,
    required this.rating,
    required this.isFavourite,
  });

  factory ProductCard.fromJson(Map<String, dynamic> json) {
    final fromPrice = json['from_price'] as Map<String, dynamic>?;
    final cover = json['cover_image'] as Map<String, dynamic>?;
    return ProductCard(
      id: json['id'] as String,
      title: json['title'] as String,
      categorySlug: (json['category'] as Map<String, dynamic>)['slug'] as String,
      grade: json['quality_grade'] as String?,
      currency: json['currency'] as String? ?? 'USD',
      price: fromPrice?['price'] as String?,
      unitCode: fromPrice?['unit'] as String?,
      imageUrl: cover == null ? null : Server.media(cover['thumb_url'] as String),
      farmName: (json['farm'] as Map<String, dynamic>)['name'] as String,
      district: (json['location'] as Map<String, dynamic>)['district'] as String,
      distanceKm: json['distance_km'] as int?,
      harvestedOn: json['last_harvested_on'] as String?,
      rating: Rating.fromJson(json['rating']),
      isFavourite: json['is_favourite'] as bool?,
    );
  }

  final String id;
  final String title;
  final String categorySlug;
  final String? grade;
  final String currency;
  final String? price;
  final String? unitCode;
  final String? imageUrl;
  final String farmName;
  final String district;
  final int? distanceKm;
  final String? harvestedOn;
  final Rating rating;

  /// Only sent for signed-in buyers.
  final bool? isFavourite;
}

class PriceTier {
  PriceTier(this.minQuantity, this.price);

  factory PriceTier.fromJson(Map<String, dynamic> json) =>
      PriceTier(json['min_quantity'] as String, json['price'] as String);

  final String minQuantity;
  final String price;
}

class Variant {
  Variant({
    required this.id,
    required this.unit,
    required this.label,
    required this.price,
    required this.priceTiers,
    required this.minOrderQuantity,
    required this.maxOrderQuantity,
    required this.availableQuantity,
  });

  factory Variant.fromJson(Map<String, dynamic> json) => Variant(
        id: json['id'] as String,
        unit: Unit.fromJson(json['unit'] as Map<String, dynamic>),
        label: json['label'] as String?,
        price: json['price'] as String,
        priceTiers: _list(json['price_tiers'], PriceTier.fromJson),
        minOrderQuantity: json['min_order_quantity'] as String,
        maxOrderQuantity: json['max_order_quantity'] as String?,
        availableQuantity: json['available_quantity'] as String,
      );

  final String id;
  final Unit unit;
  final String? label;
  final String price;
  final List<PriceTier> priceTiers;
  final String minOrderQuantity;
  final String? maxOrderQuantity;
  final String availableQuantity;

  String get name => label ?? '${unit.name[0].toUpperCase()}${unit.name.substring(1)}';
}

/// ProductResource: the full listing as buyers see it.
class ProductDetail {
  ProductDetail({
    required this.id,
    required this.title,
    required this.description,
    required this.categorySlug,
    required this.grade,
    required this.currency,
    required this.variants,
    required this.images,
    required this.farmId,
    required this.farmName,
    required this.farmVerified,
    required this.province,
    required this.district,
    required this.offersPickup,
    required this.offersDelivery,
    required this.deliveryRadiusKm,
    required this.harvestedOn,
    required this.rating,
  });

  factory ProductDetail.fromJson(Map<String, dynamic> json) {
    final farm = json['farm'] as Map<String, dynamic>;
    final location = json['location'] as Map<String, dynamic>;
    final radius = json['delivery_radius_km'];
    return ProductDetail(
      id: json['id'] as String,
      title: json['title'] as String,
      description: json['description'] as String?,
      categorySlug: (json['category'] as Map<String, dynamic>)['slug'] as String,
      grade: json['quality_grade'] as String?,
      currency: json['currency'] as String? ?? 'USD',
      variants: _list(json['variants'], Variant.fromJson),
      images: _list(json['images'], ProductImage.fromJson),
      farmId: farm['id'] as String,
      farmName: farm['name'] as String,
      farmVerified: farm['verified'] as bool? ?? false,
      province: location['province'] as String,
      district: location['district'] as String,
      offersPickup: json['offers_pickup'] as bool? ?? false,
      offersDelivery: json['offers_delivery'] as bool? ?? false,
      deliveryRadiusKm: radius == null ? null : num.tryParse('$radius'),
      harvestedOn: json['last_harvested_on'] as String?,
      rating: Rating.fromJson(json['rating']),
    );
  }

  final String id;
  final String title;
  final String? description;
  final String categorySlug;
  final String? grade;
  final String currency;
  final List<Variant> variants;
  final List<ProductImage> images;
  final String farmId;
  final String farmName;
  final bool farmVerified;
  final String province;
  final String district;
  final bool offersPickup;
  final bool offersDelivery;
  final num? deliveryRadiusKm;
  final String? harvestedOn;
  final Rating rating;
}

/// Something that stops an order going through, tagged with the farm or basket line it concerns.
class Problem {
  Problem(this.code, this.message, this.cartItemId, this.farmId);

  factory Problem.fromJson(Map<String, dynamic> json) => Problem(
        json['code'] as String,
        json['message'] as String,
        json['cart_item_id'] as String?,
        json['farm_id'] as String?,
      );

  final String code;
  final String message;
  final String? cartItemId;
  final String? farmId;
}

class CartLine {
  CartLine({
    required this.cartItemId,
    required this.variantId,
    required this.productId,
    required this.title,
    required this.label,
    required this.unit,
    required this.thumbUrl,
    required this.quantity,
    required this.unitPrice,
    required this.lineTotal,
    required this.problem,
  });

  factory CartLine.fromJson(Map<String, dynamic> json) => CartLine(
        cartItemId: json['cart_item_id'] as String,
        variantId: json['variant_id'] as String,
        productId: json['product_id'] as String,
        title: json['title'] as String,
        label: json['label'] as String?,
        unit: Unit.fromJson(json['unit'] as Map<String, dynamic>),
        thumbUrl: json['thumb_url'] == null ? null : Server.media(json['thumb_url'] as String),
        quantity: json['quantity'] as String,
        unitPrice: json['unit_price'] as String,
        lineTotal: json['line_total'] as String,
        problem: json['problem'] == null ? null : Problem.fromJson(json['problem'] as Map<String, dynamic>),
      );

  final String cartItemId;
  final String variantId;
  final String productId;
  final String title;
  final String? label;
  final Unit unit;
  final String? thumbUrl;
  final String quantity;
  final String unitPrice;
  final String lineTotal;
  final Problem? problem;
}

class CartSeller {
  CartSeller({
    required this.farmId,
    required this.farmName,
    required this.fulfilmentMethod,
    required this.availableMethods,
    required this.items,
    required this.subtotal,
    required this.deliveryFee,
    required this.total,
    required this.problems,
  });

  factory CartSeller.fromJson(Map<String, dynamic> json) {
    final farm = json['farm'] as Map<String, dynamic>;
    return CartSeller(
      farmId: farm['id'] as String,
      farmName: farm['name'] as String,
      fulfilmentMethod: json['fulfilment_method'] as String?,
      availableMethods: (json['available_methods'] as List? ?? const []).cast<String>(),
      items: _list(json['items'], CartLine.fromJson),
      subtotal: json['subtotal'] as String,
      deliveryFee: json['delivery_fee'] as String? ?? '0.00',
      total: json['total'] as String? ?? json['subtotal'] as String,
      problems: _list(json['problems'], Problem.fromJson),
    );
  }

  final String farmId;
  final String farmName;

  /// "pickup" or "delivery"; the quote picks one when the buyer hasn't.
  final String? fulfilmentMethod;
  final List<String> availableMethods;
  final List<CartLine> items;
  final String subtotal;
  final String deliveryFee;
  final String total;
  final List<Problem> problems;
}

/// QuoteResource: the cart (or a checkout quote), grouped by farm and priced by the server.
class Cart {
  Cart({
    required this.currency,
    required this.sellers,
    required this.subtotal,
    required this.deliveryFee,
    required this.total,
    required this.problems,
    required this.canPlaceOrder,
  });

  factory Cart.fromJson(Map<String, dynamic> json) => Cart(
        currency: json['currency'] as String? ?? 'USD',
        sellers: _list(json['sellers'], CartSeller.fromJson),
        subtotal: json['subtotal'] as String,
        deliveryFee: json['delivery_fee'] as String? ?? '0.00',
        total: json['total'] as String? ?? json['subtotal'] as String,
        problems: _list(json['problems'], Problem.fromJson),
        canPlaceOrder: json['can_place_order'] as bool? ?? false,
      );

  final String currency;
  final List<CartSeller> sellers;
  final String subtotal;
  final String deliveryFee;
  final String total;
  final List<Problem> problems;
  final bool canPlaceOrder;

  List<CartLine> get lines => [for (final seller in sellers) ...seller.items];
}

/// A checkout quote has the cart's shape, with fees and totals for the chosen pickup or delivery.
typedef Quote = Cart;

class User {
  User({required this.id, required this.name, required this.phone, required this.phoneVerified, required this.email, required this.roles});

  factory User.fromJson(Map<String, dynamic> json) => User(
        id: json['id'] as String,
        name: json['name'] as String,
        phone: json['phone'] as String,
        phoneVerified: json['phone_verified'] as bool? ?? false,
        email: json['email'] as String?,
        roles: (json['roles'] as List? ?? const []).cast<String>(),
      );

  final String id;
  final String name;
  final String phone;
  final bool phoneVerified;
  final String? email;

  /// buyer, farmer, business_buyer (and staff roles, which use the web dashboard).
  final List<String> roles;

  bool get isFarmer => roles.contains('farmer');

  String get firstName => name.trim().split(RegExp(r'\s+')).first;
}

DateTime? _time(Object? value) => value is String ? DateTime.tryParse(value)?.toLocal() : null;

class Address {
  Address({
    required this.id,
    required this.label,
    required this.recipientName,
    required this.phone,
    required this.province,
    required this.district,
    required this.addressLine,
    required this.directions,
    required this.isDefault,
  });

  factory Address.fromJson(Map<String, dynamic> json) => Address(
        id: json['id'] as String,
        label: json['label'] as String?,
        recipientName: json['recipient_name'] as String,
        phone: json['phone'] as String,
        province: json['province'] as String,
        district: json['district'] as String,
        addressLine: json['address_line'] as String,
        directions: json['directions'] as String?,
        isDefault: json['is_default'] as bool? ?? false,
      );

  final String id;
  final String? label;
  final String recipientName;
  final String phone;
  final String province;
  final String district;
  final String addressLine;
  final String? directions;
  final bool isDefault;
}

class OrderItem {
  OrderItem({
    required this.title,
    required this.label,
    required this.unit,
    required this.quantity,
    required this.confirmedQuantity,
    required this.lineTotal,
  });

  factory OrderItem.fromJson(Map<String, dynamic> json) => OrderItem(
        title: json['title'] as String,
        label: json['label'] as String?,
        unit: Unit.fromJson(json['unit'] as Map<String, dynamic>),
        quantity: json['quantity'] as String,
        confirmedQuantity: json['confirmed_quantity'] as String?,
        lineTotal: json['line_total'] as String,
      );

  final String title;
  final String? label;
  final Unit unit;
  final String quantity;

  /// Set once the farm accepts; may be less than ordered ("0" = it could not supply this).
  final String? confirmedQuantity;
  final String lineTotal;
}

/// One farm's part of an order, as the buyer sees it.
class SellerOrder {
  SellerOrder({
    required this.id,
    required this.number,
    required this.status,
    required this.farmId,
    required this.farmName,
    required this.farmPhone,
    required this.fulfilmentMethod,
    required this.items,
    required this.total,
    required this.deliveryFee,
    required this.rejectionReason,
    required this.confirmBy,
    required this.handoverCode,
    required this.deliveryAddress,
    required this.pickupLocations,
    required this.reportProblemUntil,
    required this.disputeId,
    required this.disputeStatus,
    required this.reviewRating,
  });

  factory SellerOrder.fromJson(Map<String, dynamic> json) {
    final farm = json['farm'] as Map<String, dynamic>;
    final dispute = json['dispute'] as Map<String, dynamic>?;
    final review = json['review'] as Map<String, dynamic>?;
    return SellerOrder(
      id: json['id'] as String,
      number: json['number'] as String,
      status: json['status'] as String,
      farmId: farm['id'] as String,
      farmName: farm['name'] as String,
      farmPhone: farm['contact_phone'] as String?,
      fulfilmentMethod: json['fulfilment_method'] as String,
      items: _list(json['items'], OrderItem.fromJson),
      total: json['total'] as String,
      deliveryFee: json['delivery_fee'] as String,
      rejectionReason: json['rejection_reason'] as String?,
      confirmBy: _time(json['confirm_by']),
      handoverCode: json['handover_code'] as String?,
      deliveryAddress: json['delivery_address'] as Map<String, dynamic>?,
      pickupLocations: (json['pickup_locations'] as List? ?? const []).whereType<Map<String, dynamic>>().toList(),
      reportProblemUntil: _time(json['report_problem_until']),
      disputeId: dispute?['id'] as String?,
      disputeStatus: dispute?['status'] as String?,
      reviewRating: review?['rating'] as int?,
    );
  }

  final String id;
  final String number;
  final String status;
  final String farmId;
  final String farmName;
  final String? farmPhone;
  final String fulfilmentMethod;
  final List<OrderItem> items;
  final String total;
  final String deliveryFee;
  final String? rejectionReason;
  final DateTime? confirmBy;

  /// Shown to the farm at handover; only sent once paid and until handed over.
  final String? handoverCode;
  final Map<String, dynamic>? deliveryAddress;
  final List<Map<String, dynamic>> pickupLocations;
  final DateTime? reportProblemUntil;
  final String? disputeId;
  final String? disputeStatus;
  final int? reviewRating;

  bool get isPickup => fulfilmentMethod == 'pickup';
}

class Payment {
  Payment({
    required this.id,
    required this.status,
    required this.provider,
    required this.amount,
    required this.currency,
    required this.payerPhone,
    required this.instructions,
    required this.failureReason,
    required this.expiresAt,
  });

  factory Payment.fromJson(Map<String, dynamic> json) {
    final next = json['next_action'] as Map<String, dynamic>?;
    return Payment(
      id: json['id'] as String,
      status: json['status'] as String,
      provider: json['provider'] as String,
      amount: json['amount'] as String,
      currency: json['currency'] as String? ?? 'USD',
      payerPhone: json['payer_phone'] as String,
      instructions: next?['message'] as String?,
      failureReason: json['failure_reason'] as String?,
      expiresAt: _time(json['expires_at']),
    );
  }

  final String id;

  /// pending, succeeded, failed or expired.
  final String status;
  final String provider;
  final String amount;
  final String currency;
  final String payerPhone;

  /// While pending: what to tell the buyer, e.g. "Approve the payment on your phone".
  final String? instructions;
  final String? failureReason;
  final DateTime? expiresAt;

  bool get pending => status == 'pending';
}

class Order {
  Order({
    required this.id,
    required this.number,
    required this.status,
    required this.cancellationReason,
    required this.currency,
    required this.subtotal,
    required this.deliveryFee,
    required this.total,
    required this.paymentDueAt,
    required this.sellerOrders,
    required this.latestPayment,
    required this.createdAt,
  });

  factory Order.fromJson(Map<String, dynamic> json) => Order(
        id: json['id'] as String,
        number: json['number'] as String,
        status: json['status'] as String,
        cancellationReason: json['cancellation_reason'] as String?,
        currency: json['currency'] as String? ?? 'USD',
        subtotal: json['subtotal'] as String,
        deliveryFee: json['delivery_fee'] as String,
        total: json['total'] as String,
        paymentDueAt: _time(json['payment_due_at']),
        sellerOrders: _list(json['seller_orders'], SellerOrder.fromJson),
        latestPayment: json['latest_payment'] == null ? null : Payment.fromJson(json['latest_payment'] as Map<String, dynamic>),
        createdAt: _time(json['created_at']),
      );

  final String id;
  final String number;

  /// awaiting_confirmation, awaiting_payment, paid, completed or cancelled.
  final String status;
  final String? cancellationReason;
  final String currency;
  final String subtotal;
  final String deliveryFee;
  final String total;
  final DateTime? paymentDueAt;
  final List<SellerOrder> sellerOrders;
  final Payment? latestPayment;
  final DateTime? createdAt;

  /// Until paid, and not while a payment prompt is open (the server checks too).
  bool get cancellable =>
      (status == 'awaiting_confirmation' || status == 'awaiting_payment') && latestPayment?.pending != true;
}
