import 'dart:async';

import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/social_models.dart';
import '../../theme.dart';
import '../../util/format.dart';
import '../../widgets/common.dart';

/// One conversation between a buyer and a farm. It can open on an existing thread, or start one about a farm,
/// a listing or an order; the server reuses the existing thread, so the first message lands in the history.
class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key, required Conversation this.conversation, required this.asFarm})
      : farmId = null,
        sellerOrderId = null,
        productId = null,
        title = null;

  /// A buyer writing to a farm, optionally about one of its listings.
  const ChatScreen.forFarm({super.key, required String this.farmId, this.productId, required String this.title})
      : conversation = null,
        sellerOrderId = null,
        asFarm = false;

  /// Either side writing about an order.
  const ChatScreen.forOrder({super.key, required String this.sellerOrderId, this.farmId, required String this.title, required this.asFarm})
      : conversation = null,
        productId = null;

  final Conversation? conversation;
  final String? farmId;
  final String? sellerOrderId;
  final String? productId;
  final String? title;

  /// True when the farm is writing; the other party is then the buyer.
  final bool asFarm;

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  final _composer = TextEditingController();
  final _scroll = ScrollController();
  late String? _conversationId = widget.conversation?.id;
  List<ChatMessage> _messages = const []; // newest first
  String? _olderCursor;
  bool _loading = true;
  bool _loadingOlder = false;
  bool _sending = false;
  Object? _error;
  Timer? _poll;

  ApiClient get _api => context.read<ApiClient>();

  String get _title {
    final conversation = widget.conversation;
    if (conversation != null) return widget.asFarm ? conversation.buyerName : conversation.farmName;
    return widget.title!;
  }

  @override
  void initState() {
    super.initState();
    _start();
    _scroll.addListener(() {
      if (_scroll.position.extentAfter < 300) _loadOlder();
    });
    _poll = Timer.periodic(const Duration(seconds: 8), (_) => _refresh());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _composer.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _start() async {
    // A buyer may already have a thread with this farm: open it rather than an empty screen.
    if (_conversationId == null && !widget.asFarm && widget.farmId != null) {
      try {
        final response = await _api.get('/conversations') as Map<String, dynamic>;
        final threads = (response['data'] as List).cast<Map<String, dynamic>>().map(Conversation.fromJson);
        _conversationId = threads.where((thread) => thread.farmId == widget.farmId).firstOrNull?.id;
      } catch (_) {
        // Start a new thread; the server joins it to any existing one.
      }
    }
    await _refresh();
  }

  Future<void> _refresh() async {
    final id = _conversationId;
    if (id == null) {
      if (mounted) setState(() => _loading = false);
      return;
    }
    try {
      final response = await _api.get('/conversations/$id/messages') as Map<String, dynamic>;
      final latest = (response['data'] as List).cast<Map<String, dynamic>>().map(ChatMessage.fromJson).toList();
      if (!mounted) return;
      final known = {for (final message in _messages) message.id};
      final hadNewFromThem = latest.any((message) => !known.contains(message.id) && !message.fromMe);
      setState(() {
        final newest = latest.where((message) => !known.contains(message.id)).toList();
        _messages = _messages.isEmpty ? latest : [...newest, ..._messages];
        if (_olderCursor == null && known.isEmpty) _olderCursor = (response['meta'] as Map<String, dynamic>?)?['next_cursor'] as String?;
        _loading = false;
        _error = null;
      });
      if (known.isEmpty || hadNewFromThem) _api.post('/conversations/$id/read').catchError((Object _) {});
    } catch (error) {
      if (mounted) {
        setState(() {
          _loading = false;
          if (_messages.isEmpty) _error = error;
        });
      }
    }
  }

  Future<void> _loadOlder() async {
    final id = _conversationId;
    final cursor = _olderCursor;
    if (id == null || cursor == null || _loadingOlder) return;
    _loadingOlder = true;
    try {
      final response = await _api.get('/conversations/$id/messages', query: {'cursor': cursor}) as Map<String, dynamic>;
      final older = (response['data'] as List).cast<Map<String, dynamic>>().map(ChatMessage.fromJson).toList();
      if (mounted) {
        setState(() {
          _messages = [..._messages, ...older];
          _olderCursor = (response['meta'] as Map<String, dynamic>?)?['next_cursor'] as String?;
        });
      }
    } catch (_) {
      // Try again on the next scroll.
    } finally {
      _loadingOlder = false;
    }
  }

  Future<void> _send() async {
    final body = _composer.text.trim();
    if (body.isEmpty) return;
    setState(() => _sending = true);
    try {
      final dynamic response;
      if (_conversationId == null) {
        response = await _api.post('/conversations', {
          'body': body,
          if (widget.sellerOrderId != null) 'seller_order_id': widget.sellerOrderId else 'farm_id': widget.farmId,
          if (widget.productId != null) 'product_id': widget.productId,
        });
      } else {
        response = await _api.post('/conversations/$_conversationId/messages', {'body': body});
      }
      final message = ChatMessage.fromJson((response as Map<String, dynamic>)['data'] as Map<String, dynamic>);
      _composer.clear();
      final started = _conversationId == null;
      _conversationId = message.conversationId;
      if (started) {
        // The thread may already have history from before; show all of it.
        _messages = const [];
        await _refresh();
      } else if (mounted) {
        setState(() => _messages = [message, ..._messages]);
      }
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(_title)),
      body: Column(
        children: [
          Expanded(child: _body()),
          _Composer(controller: _composer, sending: _sending, onSend: _send),
        ],
      ),
    );
  }

  Widget _body() {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) {
      return Center(
        child: EmptyState(
          icon: LucideIcons.wifiOff,
          title: "We couldn't load the messages",
          message: errorMessage(_error!),
          actions: [FilledButton(onPressed: _refresh, child: const Text('Try again'))],
        ),
      );
    }
    if (_messages.isEmpty) {
      return Center(
        child: EmptyState(
          icon: LucideIcons.messageCircle,
          title: 'Start the conversation',
          message: widget.asFarm
              ? 'Messages go to the buyer in their MundaLinq app. Phone numbers are never shown here.'
              : 'Ask about availability, quality or collection. Everyone at the farm can see and reply.',
        ),
      );
    }
    return ListView.builder(
      controller: _scroll,
      reverse: true,
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      itemCount: _messages.length,
      itemBuilder: (context, index) {
        final message = _messages[index];
        final previous = index + 1 < _messages.length ? _messages[index + 1] : null;
        final showDay = previous == null ||
            message.createdAt == null ||
            previous.createdAt == null ||
            formatDate(message.createdAt!) != formatDate(previous.createdAt!);
        return Column(
          children: [
            if (showDay && message.createdAt != null)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 10),
                child: Text(formatDate(message.createdAt!), style: const TextStyle(fontSize: 11.5, color: Brand.mutedText)),
              ),
            _Bubble(message: message),
          ],
        );
      },
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.message});

  final ChatMessage message;

  @override
  Widget build(BuildContext context) {
    final mine = message.fromMe;
    final about = message.orderNumber != null ? 'About order ${message.orderNumber}' : (message.productTitle != null ? 'About ${message.productTitle}' : null);

    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.78),
        margin: const EdgeInsets.symmetric(vertical: 3),
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 6),
        decoration: BoxDecoration(
          color: mine ? Brand.green : Brand.muted,
          borderRadius: BorderRadius.only(
            topLeft: const Radius.circular(14),
            topRight: const Radius.circular(14),
            bottomLeft: Radius.circular(mine ? 14 : 4),
            bottomRight: Radius.circular(mine ? 4 : 14),
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (about != null)
              Text(about, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: mine ? Colors.white70 : Brand.green)),
            if (!mine && message.senderName.isNotEmpty)
              Text(message.senderName, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: Brand.mutedText)),
            Text(message.body, style: TextStyle(fontSize: 14.5, height: 1.4, color: mine ? Colors.white : Brand.ink)),
            if (message.createdAt != null)
              Align(
                alignment: Alignment.centerRight,
                child: Text(formatClock(message.createdAt!), style: TextStyle(fontSize: 10.5, color: mine ? Colors.white70 : Brand.mutedText)),
              ),
          ],
        ),
      ),
    );
  }
}

class _Composer extends StatelessWidget {
  const _Composer({required this.controller, required this.sending, required this.onSend});

  final TextEditingController controller;
  final bool sending;
  final VoidCallback onSend;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(color: Colors.white, border: Border(top: BorderSide(color: Brand.border))),
      padding: EdgeInsets.fromLTRB(12, 8, 8, 8 + MediaQuery.paddingOf(context).bottom),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Expanded(
            child: TextField(
              controller: controller,
              minLines: 1,
              maxLines: 5,
              maxLength: 2000,
              textCapitalization: TextCapitalization.sentences,
              decoration: const InputDecoration(hintText: 'Write a message', counterText: '', contentPadding: EdgeInsets.symmetric(horizontal: 14, vertical: 11)),
            ),
          ),
          const SizedBox(width: 6),
          IconButton.filled(
            onPressed: sending ? null : onSend,
            tooltip: 'Send',
            style: IconButton.styleFrom(backgroundColor: Brand.green, fixedSize: const Size(46, 46)),
            icon: sending
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(LucideIcons.send, size: 19, color: Colors.white),
          ),
        ],
      ),
    );
  }
}
