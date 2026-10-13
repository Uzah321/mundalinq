import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../theme.dart';

/// The MundaLinq wordmark: a sprout and "mundalinq." with a harvest-yellow full stop.
class Wordmark extends StatelessWidget {
  const Wordmark({super.key, this.size = 25});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(LucideIcons.sprout, color: Brand.green, size: size * 1.05),
        SizedBox(width: size * 0.3),
        Text.rich(
          TextSpan(
            text: 'mundalinq',
            children: const [TextSpan(text: '.', style: TextStyle(color: Brand.harvest))],
            style: Brand.display(size: size, weight: FontWeight.w700, color: Brand.green, height: 1),
          ),
        ),
      ],
    );
  }
}

/// A round icon button with an optional count badge, like the header's basket.
class BadgedIconButton extends StatelessWidget {
  const BadgedIconButton({super.key, required this.icon, required this.onPressed, required this.tooltip, this.count = 0});

  final IconData icon;
  final VoidCallback onPressed;
  final String tooltip;
  final int count;

  @override
  Widget build(BuildContext context) {
    return Stack(
      clipBehavior: Clip.none,
      children: [
        IconButton(
          onPressed: onPressed,
          tooltip: tooltip,
          style: IconButton.styleFrom(backgroundColor: Brand.mint, fixedSize: const Size(44, 44)),
          icon: Icon(icon, size: 20, color: Brand.ink),
        ),
        if (count > 0) Positioned(top: -2, right: -2, child: CountBadge(count: count)),
      ],
    );
  }
}

class CountBadge extends StatelessWidget {
  const CountBadge({super.key, required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: const BoxConstraints(minWidth: 19, minHeight: 19),
      padding: const EdgeInsets.symmetric(horizontal: 4),
      decoration: BoxDecoration(
        color: Brand.harvest,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: Colors.white, width: 2),
      ),
      alignment: Alignment.center,
      child: Text('$count', style: const TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: Brand.ink)),
    );
  }
}

/// A centred message with an icon, used for empty lists, errors and signed-out screens.
class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.title, required this.message, this.actions = const []});

  final IconData icon;
  final String title;
  final String message;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 36),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 34, color: Brand.green),
          const SizedBox(height: 16),
          Text(title, textAlign: TextAlign.center, style: Brand.display(size: 17)),
          const SizedBox(height: 8),
          Text(
            message,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 13, height: 1.6, color: Brand.mutedText),
          ),
          if (actions.isNotEmpty) ...[
            const SizedBox(height: 20),
            Wrap(spacing: 10, runSpacing: 10, alignment: WrapAlignment.center, children: actions),
          ],
        ],
      ),
    );
  }
}

/// Small rounded label on a tinted background ("Grade A", "Farm pickup").
class Tag extends StatelessWidget {
  const Tag({super.key, required this.label, this.icon, this.warm = false});

  final String label;
  final IconData? icon;
  final bool warm;

  @override
  Widget build(BuildContext context) {
    final color = warm ? Brand.harvestText : Brand.green;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
      decoration: BoxDecoration(color: warm ? Brand.harvest : Brand.mint, borderRadius: BorderRadius.circular(4)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[Icon(icon, size: 12, color: color), const SizedBox(width: 4)],
          Text(label, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: color)),
        ],
      ),
    );
  }
}

void showMessage(BuildContext context, String message, {String? actionLabel, VoidCallback? onAction}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(
      SnackBar(
        content: Text(message),
        action: actionLabel == null ? null : SnackBarAction(label: actionLabel, onPressed: onAction ?? () {}),
      ),
    );
}

class SectionHeading extends StatelessWidget {
  const SectionHeading({super.key, this.eyebrow, required this.title, this.trailing});

  final String? eyebrow;
  final String title;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (eyebrow != null) ...[Text(eyebrow!, style: Brand.eyebrow()), const SizedBox(height: 6)],
              Text(title, style: Brand.display(size: 17)),
            ],
          ),
        ),
        ?trailing,
      ],
    );
  }
}
