// Messages between buyers and farms, notifications, problem reports and signed-in devices.

DateTime? _time(Object? value) => value is String ? DateTime.tryParse(value)?.toLocal() : null;

class Conversation {
  Conversation({
    required this.id,
    required this.farmId,
    required this.farmName,
    required this.buyerName,
    required this.lastMessage,
    required this.lastMessageFromFarm,
    required this.unreadCount,
    required this.lastMessageAt,
  });

  factory Conversation.fromJson(Map<String, dynamic> json) {
    final farm = json['farm'] as Map<String, dynamic>;
    final last = json['last_message'] as Map<String, dynamic>?;
    return Conversation(
      id: json['id'] as String,
      farmId: farm['id'] as String,
      farmName: farm['name'] as String,
      buyerName: (json['buyer'] as Map<String, dynamic>?)?['name'] as String? ?? 'Buyer',
      lastMessage: last?['body'] as String?,
      lastMessageFromFarm: last?['side'] == 'farm',
      unreadCount: json['unread_count'] as int? ?? 0,
      lastMessageAt: _time(json['last_message_at']),
    );
  }

  final String id;
  final String farmId;
  final String farmName;
  final String buyerName;
  final String? lastMessage;
  final bool lastMessageFromFarm;
  final int unreadCount;
  final DateTime? lastMessageAt;
}

class ChatMessage {
  ChatMessage({
    required this.id,
    required this.conversationId,
    required this.body,
    required this.fromMe,
    required this.senderName,
    required this.productTitle,
    required this.orderNumber,
    required this.createdAt,
  });

  factory ChatMessage.fromJson(Map<String, dynamic> json) => ChatMessage(
        id: json['id'] as String,
        conversationId: json['conversation_id'] as String,
        body: json['body'] as String,
        fromMe: json['from_me'] as bool? ?? false,
        senderName: (json['sender'] as Map<String, dynamic>?)?['name'] as String? ?? '',
        productTitle: (json['product'] as Map<String, dynamic>?)?['title'] as String?,
        orderNumber: (json['seller_order'] as Map<String, dynamic>?)?['number'] as String?,
        createdAt: _time(json['created_at']),
      );

  final String id;
  final String conversationId;
  final String body;
  final bool fromMe;
  final String senderName;
  final String? productTitle;
  final String? orderNumber;
  final DateTime? createdAt;
}

class AppNotification {
  AppNotification({
    required this.id,
    required this.category,
    required this.title,
    required this.body,
    required this.context,
    required this.read,
    required this.createdAt,
  });

  factory AppNotification.fromJson(Map<String, dynamic> json) => AppNotification(
        id: json['id'] as String,
        category: json['category'] as String? ?? 'account',
        title: json['title'] as String?,
        body: json['body'] as String?,
        context: {
          for (final entry in ((json['context'] as Map<String, dynamic>?) ?? const {}).entries)
            if (entry.value != null) entry.key: '${entry.value}',
        },
        read: json['read_at'] != null,
        createdAt: _time(json['created_at']),
      );

  final String id;

  /// orders, messages, reviews, disputes, listings, account or payouts.
  final String category;
  final String? title;
  final String? body;

  /// Ids for opening the right screen, e.g. order_id, or farm_id with seller_order_id.
  final Map<String, String> context;
  final bool read;
  final DateTime? createdAt;
}

const problemReasons = {
  'missing_items': 'Something is missing',
  'damaged': 'It arrived damaged',
  'poor_quality': 'The quality is poor',
  'wrong_items': 'I got the wrong items',
  'other': 'Something else',
};

class DisputeMessage {
  DisputeMessage({required this.party, required this.authorName, required this.body, required this.createdAt});

  factory DisputeMessage.fromJson(Map<String, dynamic> json) => DisputeMessage(
        party: json['party'] as String,
        authorName: (json['author'] as Map<String, dynamic>?)?['name'] as String? ?? '',
        body: json['body'] as String,
        createdAt: _time(json['created_at']),
      );

  /// buyer, farm or support.
  final String party;
  final String authorName;
  final String body;
  final DateTime? createdAt;
}

class Dispute {
  Dispute({
    required this.id,
    required this.status,
    required this.reason,
    required this.description,
    required this.currency,
    required this.resolution,
    required this.refundAmount,
    required this.resolutionNote,
    required this.orderNumber,
    required this.farmName,
    required this.messages,
    required this.evidenceCount,
    required this.createdAt,
  });

  factory Dispute.fromJson(Map<String, dynamic> json) => Dispute(
        id: json['id'] as String,
        status: json['status'] as String,
        reason: json['reason'] as String,
        description: json['description'] as String,
        currency: json['currency'] as String? ?? 'USD',
        resolution: json['resolution'] as String?,
        refundAmount: json['refund_amount'] as String?,
        resolutionNote: json['resolution_note'] as String?,
        orderNumber: (json['seller_order'] as Map<String, dynamic>)['number'] as String,
        farmName: (json['farm'] as Map<String, dynamic>)['name'] as String,
        messages: (json['messages'] as List? ?? const []).whereType<Map<String, dynamic>>().map(DisputeMessage.fromJson).toList(),
        evidenceCount: (json['evidence'] as List? ?? const []).length,
        createdAt: _time(json['created_at']),
      );

  final String id;
  final String status;
  final String reason;
  final String description;
  final String currency;
  final String? resolution;
  final String? refundAmount;
  final String? resolutionNote;
  final String orderNumber;
  final String farmName;
  final List<DisputeMessage> messages;
  final int evidenceCount;
  final DateTime? createdAt;

  bool get open => status != 'resolved' && status != 'withdrawn';
}

class DeviceSession {
  DeviceSession({required this.id, required this.name, required this.current, required this.lastUsedAt});

  factory DeviceSession.fromJson(Map<String, dynamic> json) => DeviceSession(
        id: '${json['id']}',
        name: json['device_name'] as String? ?? 'Device',
        current: json['current'] as bool? ?? false,
        lastUsedAt: _time(json['last_used_at']),
      );

  final String id;
  final String name;
  final bool current;
  final DateTime? lastUsedAt;
}
