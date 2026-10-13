import 'package:flutter/material.dart';

import '../api/farm_models.dart';
import '../theme.dart';
import '../util/format.dart';

class Stars extends StatelessWidget {
  const Stars({super.key, required this.rating, this.size = 16});

  final double rating;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var star = 1; star <= 5; star++)
          Icon(
            rating >= star ? Icons.star_rounded : (rating >= star - 0.5 ? Icons.star_half_rounded : Icons.star_outline_rounded),
            size: size,
            color: Brand.harvest,
          ),
      ],
    );
  }
}

/// Average, count, and a bar per star.
class ReviewSummaryView extends StatelessWidget {
  const ReviewSummaryView({super.key, required this.summary});

  final ReviewSummary summary;

  @override
  Widget build(BuildContext context) {
    final average = summary.average ?? 0;
    return Row(
      children: [
        Column(
          children: [
            Text(average.toStringAsFixed(1), style: Brand.display(size: 34, weight: FontWeight.w700)),
            Stars(rating: average),
            const SizedBox(height: 4),
            Text('${summary.count} review${summary.count == 1 ? '' : 's'}', style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
          ],
        ),
        const SizedBox(width: 20),
        Expanded(
          child: Column(
            children: [
              for (var star = 5; star >= 1; star--)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2),
                  child: Row(
                    children: [
                      SizedBox(width: 12, child: Text('$star', style: const TextStyle(fontSize: 11.5, color: Brand.mutedText))),
                      const SizedBox(width: 6),
                      Expanded(
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(3),
                          child: LinearProgressIndicator(
                            value: summary.count == 0 ? 0 : (summary.stars[star] ?? 0) / summary.count,
                            minHeight: 6,
                            backgroundColor: Brand.muted,
                            color: Brand.harvest,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

class ReviewTile extends StatelessWidget {
  const ReviewTile({super.key, required this.review, this.action});

  final Review review;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: Brand.border))),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Stars(rating: review.rating.toDouble(), size: 15),
              const SizedBox(width: 8),
              Text(review.buyerName, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const Spacer(),
              if (review.createdAt != null) Text(formatDate(review.createdAt!), style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
            ],
          ),
          if (review.products.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text('Bought ${review.products.join(', ')}', style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
            ),
          if (review.comment != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(review.comment!, style: const TextStyle(height: 1.5))),
          if (review.farmResponse != null)
            Container(
              margin: const EdgeInsets.only(top: 8),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: Brand.muted, borderRadius: BorderRadius.circular(6)),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Reply from the farm', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Brand.green)),
                  const SizedBox(height: 2),
                  Text(review.farmResponse!, style: const TextStyle(fontSize: 13, height: 1.5)),
                ],
              ),
            ),
          ?action,
        ],
      ),
    );
  }
}
