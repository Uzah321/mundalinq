import 'dart:async';

import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/models.dart';
import '../../api/social_models.dart';
import '../../state/farm.dart';
import '../../state/paged_list.dart';
import '../../theme.dart';
import '../../util/format.dart';
import '../../widgets/common.dart';
import '../farm/farm_shell.dart' show FarmTitle, NotificationBell;
import 'chat_screen.dart';

/// The buyer's conversations with farms, or ([asFarm]) the current farm's inbox.
class ConversationsScreen extends StatefulWidget {
  const ConversationsScreen({super.key, this.asFarm = false});

  final bool asFarm;

  @override
  State<ConversationsScreen> createState() => _ConversationsScreenState();
}

class _ConversationsScreenState extends State<ConversationsScreen> {
  late final PagedList<Conversation> _threads = PagedList(_fetch);
  Timer? _poll;

  Future<Paged<Conversation>> _fetch(int page) async => Paged.fromJson(
        await context.read<ApiClient>().get('/conversations', query: {
          'page': page,
          if (widget.asFarm) 'farm_id': context.read<FarmController>().farm?.id,
        }) as Map<String, dynamic>,
        Conversation.fromJson,
      );

  @override
  void initState() {
    super.initState();
    _threads.refresh();
    _poll = Timer.periodic(const Duration(seconds: 30), (_) {
      if (!_threads.loading) _threads.refresh();
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    _threads.dispose();
    super.dispose();
  }

  Future<void> _open(Conversation conversation) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ChatScreen(conversation: conversation, asFarm: widget.asFarm)));
    if (mounted) _threads.refresh();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: widget.asFarm
          ? AppBar(toolbarHeight: 64, titleSpacing: 20, title: const FarmTitle(subtitle: 'Messages'), actions: const [NotificationBell()])
          : AppBar(title: const Text('Messages')),
      body: RefreshIndicator(
        onRefresh: _threads.refresh,
        child: ListenableBuilder(
          listenable: _threads,
          builder: (context, _) {
            if (_threads.initialLoading) return const Center(child: CircularProgressIndicator());
            if (_threads.error != null && _threads.items.isEmpty) {
              return ListView(children: [
                EmptyState(
                  icon: LucideIcons.wifiOff,
                  title: "We couldn't load your messages",
                  message: errorMessage(_threads.error!),
                  actions: [FilledButton(onPressed: _threads.refresh, child: const Text('Try again'))],
                ),
              ]);
            }
            if (_threads.items.isEmpty) {
              return ListView(children: [
                EmptyState(
                  icon: LucideIcons.messagesSquare,
                  title: 'No messages yet',
                  message: widget.asFarm
                      ? 'When buyers ask about your produce or orders, their messages land here.'
                      : 'Message a farm from any listing or order to ask about produce, collection or delivery.',
                ),
              ]);
            }
            return ListView.separated(
              padding: const EdgeInsets.symmetric(vertical: 8),
              itemCount: _threads.items.length + (_threads.hasMore ? 1 : 0),
              separatorBuilder: (_, _) => const Divider(height: 1, indent: 76),
              itemBuilder: (context, index) {
                if (index == _threads.items.length) {
                  _threads.loadMore();
                  return const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator()));
                }
                final thread = _threads.items[index];
                final name = widget.asFarm ? thread.buyerName : thread.farmName;
                final unread = thread.unreadCount > 0;
                final theirs = widget.asFarm ? !thread.lastMessageFromFarm : thread.lastMessageFromFarm;
                return ListTile(
                  onTap: () => _open(thread),
                  contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 4),
                  leading: CircleAvatar(
                    radius: 22,
                    backgroundColor: Brand.mint,
                    child: Text(initials(name), style: const TextStyle(fontWeight: FontWeight.w800, color: Brand.green)),
                  ),
                  title: Text(name, style: TextStyle(fontWeight: unread ? FontWeight.w800 : FontWeight.w600)),
                  subtitle: Text(
                    '${theirs ? '' : 'You: '}${thread.lastMessage ?? ''}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(color: unread ? Brand.ink : Brand.mutedText, fontWeight: unread ? FontWeight.w600 : FontWeight.w400),
                  ),
                  trailing: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      if (thread.lastMessageAt != null)
                        Text(_when(thread.lastMessageAt!), style: const TextStyle(fontSize: 11.5, color: Brand.mutedText)),
                      if (unread) ...[const SizedBox(height: 4), CountBadge(count: thread.unreadCount)],
                    ],
                  ),
                );
              },
            );
          },
        ),
      ),
    );
  }

  String _when(DateTime time) => daysSince(time.toIso8601String().substring(0, 10)) == 0 ? formatClock(time) : formatDate(time);
}
