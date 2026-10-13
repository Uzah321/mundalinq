import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/farm_models.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../widgets/reviews.dart';

/// What buyers said about the farm. The farm can reply to each review once.
class FarmReviewsScreen extends StatefulWidget {
  const FarmReviewsScreen({super.key, required this.farm});

  final Farm farm;

  @override
  State<FarmReviewsScreen> createState() => _FarmReviewsScreenState();
}

class _FarmReviewsScreenState extends State<FarmReviewsScreen> {
  List<Review>? _reviews;
  ReviewSummary? _summary;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final response = await context.read<ApiClient>().get('/farms/${widget.farm.id}/reviews') as Map<String, dynamic>;
      if (!mounted) return;
      setState(() {
        _reviews = (response['data'] as List).cast<Map<String, dynamic>>().map(Review.fromJson).toList();
        _summary = ReviewSummary.fromJson((response['meta'] as Map<String, dynamic>?)?['summary'] as Map<String, dynamic>?);
      });
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
  }

  Future<void> _reply(Review review) async {
    final text = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _ReplySheet(review: review),
    );
    if (text == null || !mounted) return;
    try {
      await context.read<ApiClient>().post('/reviews/${review.id}/response', {'response': text});
      await _load();
      if (mounted) showMessage(context, 'Reply posted');
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    }
  }

  @override
  Widget build(BuildContext context) {
    final reviews = _reviews;
    return Scaffold(
      appBar: AppBar(title: const Text('Reviews')),
      body: reviews == null
          ? Center(
              child: _error == null
                  ? const CircularProgressIndicator()
                  : EmptyState(
                      icon: LucideIcons.wifiOff,
                      title: "We couldn't load reviews",
                      message: errorMessage(_error!),
                      actions: [FilledButton(onPressed: _load, child: const Text('Try again'))],
                    ),
            )
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
                children: [
                  if (_summary != null && _summary!.count > 0) ...[ReviewSummaryView(summary: _summary!), const SizedBox(height: 16)],
                  if (reviews.isEmpty)
                    const EmptyState(
                      icon: LucideIcons.star,
                      title: 'No reviews yet',
                      message: 'Buyers can review your farm after an order is complete. Good reviews help new buyers choose you.',
                    ),
                  for (final review in reviews)
                    ReviewTile(
                      review: review,
                      action: review.farmResponse == null
                          ? TextButton.icon(onPressed: () => _reply(review), icon: const Icon(LucideIcons.reply, size: 16), label: const Text('Reply'))
                          : null,
                    ),
                ],
              ),
            ),
    );
  }
}

class _ReplySheet extends StatefulWidget {
  const _ReplySheet({required this.review});

  final Review review;

  @override
  State<_ReplySheet> createState() => _ReplySheetState();
}

class _ReplySheetState extends State<_ReplySheet> {
  final _text = TextEditingController();

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        children: [
          Text('Reply to ${widget.review.buyerName}', style: Brand.display(size: 19)),
          const SizedBox(height: 6),
          const Text('Your reply is public and can only be posted once.', style: TextStyle(color: Brand.mutedText)),
          const SizedBox(height: 12),
          TextField(
            controller: _text,
            autofocus: true,
            minLines: 3,
            maxLines: 6,
            maxLength: 1000,
            textCapitalization: TextCapitalization.sentences,
            onChanged: (_) => setState(() {}),
          ),
          FilledButton(
            onPressed: _text.text.trim().length < 2 ? null : () => Navigator.pop(context, _text.text.trim()),
            child: const Text('Post reply'),
          ),
        ],
      ),
    );
  }
}
