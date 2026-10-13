import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../theme.dart';

/// The mint banner with the vegetable-crate photo fading in behind the copy, as on the web.
class HarvestBanner extends StatelessWidget {
  const HarvestBanner({super.key, required this.onShop});

  final VoidCallback onShop;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(Brand.radius),
      child: Container(
        height: 220,
        color: Brand.mint,
        child: Stack(
          children: [
            Positioned.fill(
              child: Align(
                alignment: Alignment.centerRight,
                child: FractionallySizedBox(
                  widthFactor: 0.55,
                  heightFactor: 1,
                  child: ShaderMask(
                    blendMode: BlendMode.dstIn,
                    shaderCallback: (bounds) => const LinearGradient(
                      colors: [Colors.transparent, Colors.black],
                      stops: [0, 0.3],
                    ).createShader(bounds),
                    child: Image.asset('assets/images/hero.jpg', fit: BoxFit.cover, alignment: const Alignment(0.5, 0)),
                  ),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 20, 16, 16),
              child: FractionallySizedBox(
                widthFactor: 0.62,
                alignment: Alignment.topLeft,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 5),
                      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(3)),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Container(width: 5, height: 5, decoration: const BoxDecoration(color: Brand.green, shape: BoxShape.circle)),
                          const SizedBox(width: 5),
                          const Text('FRESH FROM THE FARM', style: TextStyle(fontSize: 8, fontWeight: FontWeight.w800, letterSpacing: 0.8, color: Brand.green)),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: Text('A little fresher.\nA lot closer.', style: Brand.display(size: 22, color: Brand.green, height: 1.25)),
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      'Seasonal goodness, straight from local farms.',
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(fontSize: 11, height: 1.6, color: Brand.green),
                    ),
                    const Spacer(),
                    FilledButton(
                      onPressed: onShop,
                      style: FilledButton.styleFrom(
                        minimumSize: const Size(0, 36),
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        textStyle: const TextStyle(fontFamily: 'Manrope', fontSize: 11, fontWeight: FontWeight.w700),
                      ),
                      child: const Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [Text('Shop the harvest'), SizedBox(width: 10), Icon(LucideIcons.arrowRight, size: 14)],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            Positioned(right: 12, bottom: 13, child: Transform.rotate(angle: 10 * math.pi / 180, child: const _HarvestStamp())),
          ],
        ),
      ),
    );
  }
}

class _HarvestStamp extends StatelessWidget {
  const _HarvestStamp();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 64,
      height: 64,
      decoration: const BoxDecoration(color: Brand.harvest, shape: BoxShape.circle),
      padding: const EdgeInsets.all(4),
      child: DecoratedBox(
        decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: Brand.harvestText.withValues(alpha: 0.6))),
        child: const Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(LucideIcons.leaf, size: 14, color: Brand.harvestText),
            Text('GROWN WITH', style: TextStyle(fontSize: 5.5, letterSpacing: 0.4, color: Brand.harvestText)),
            Text('goodness', style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: Brand.harvestText)),
          ],
        ),
      ),
    );
  }
}
