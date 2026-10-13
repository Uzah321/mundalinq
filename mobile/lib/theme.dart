import 'package:flutter/material.dart';

/// The web storefront's design tokens (frontend/src/styles.css), converted from oklch.
class Brand {
  /// Leaf green: white text on it, and it as text on [mint], both stay above 4.5:1.
  static const green = Color(0xFF168028);
  static const mint = Color(0xFFE8FBE1);
  static const muted = Color(0xFFF4FAF2);
  static const mutedText = Color(0xFF6A756D);
  static const ink = Color(0xFF18251C);
  static const border = Color(0xFFDFEADC);
  static const harvest = Color(0xFFF9C635);
  static const harvestText = Color(0xFF643E03);
  static const danger = Color(0xFFE7000B);

  static const radius = 8.0;

  /// Headings use Sora; everything else uses Manrope.
  static TextStyle display({double size = 20, FontWeight weight = FontWeight.w600, Color color = ink, double? height}) =>
      TextStyle(fontFamily: 'Sora', fontSize: size, fontWeight: weight, color: color, height: height);

  static TextStyle eyebrow({Color color = mutedText}) =>
      TextStyle(fontFamily: 'Manrope', fontSize: 10, fontWeight: FontWeight.w700, letterSpacing: 1.6, color: color);
}

ThemeData buildTheme() {
  final base = ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(
      seedColor: Brand.green,
      primary: Brand.green,
      onPrimary: Colors.white,
      secondary: Brand.harvest,
      onSecondary: Brand.harvestText,
      surface: Colors.white,
      onSurface: Brand.ink,
      error: Brand.danger,
    ),
    scaffoldBackgroundColor: Colors.white,
    dividerColor: Brand.border,
    fontFamily: 'Manrope',
  );

  final text = base.textTheme.apply(bodyColor: Brand.ink, displayColor: Brand.ink);
  final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(Brand.radius));

  return base.copyWith(
    textTheme: text,
    appBarTheme: AppBarTheme(
      backgroundColor: Colors.white,
      foregroundColor: Brand.ink,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      titleTextStyle: Brand.display(size: 18),
      shape: const Border(bottom: BorderSide(color: Brand.border)),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: Brand.green,
        foregroundColor: Colors.white,
        disabledBackgroundColor: Brand.green.withValues(alpha: 0.45),
        disabledForegroundColor: Colors.white,
        minimumSize: const Size(0, 48),
        shape: shape,
        textStyle: const TextStyle(fontFamily: 'Manrope', fontSize: 15, fontWeight: FontWeight.w700),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: Brand.ink,
        side: const BorderSide(color: Brand.border),
        minimumSize: const Size(0, 48),
        shape: shape,
        textStyle: const TextStyle(fontFamily: 'Manrope', fontSize: 15, fontWeight: FontWeight.w700),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        foregroundColor: Brand.green,
        textStyle: const TextStyle(fontFamily: 'Manrope', fontSize: 14, fontWeight: FontWeight.w700),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Brand.muted,
      hintStyle: const TextStyle(fontFamily: 'Manrope', color: Brand.mutedText, fontSize: 14),
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(Brand.radius), borderSide: const BorderSide(color: Brand.border)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(Brand.radius), borderSide: const BorderSide(color: Brand.border)),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(Brand.radius), borderSide: const BorderSide(color: Brand.green, width: 1.5)),
      errorBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(Brand.radius), borderSide: const BorderSide(color: Brand.danger)),
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: Brand.ink,
      actionTextColor: Brand.harvest,
      contentTextStyle: const TextStyle(fontFamily: 'Manrope', color: Colors.white, fontSize: 14, fontWeight: FontWeight.w600),
      shape: shape,
    ),
    bottomSheetTheme: const BottomSheetThemeData(
      backgroundColor: Colors.white,
      surfaceTintColor: Colors.transparent,
      showDragHandle: true,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
    ),
    progressIndicatorTheme: const ProgressIndicatorThemeData(color: Brand.green),
    radioTheme: RadioThemeData(fillColor: WidgetStateProperty.resolveWith((states) => states.contains(WidgetState.selected) ? Brand.green : Brand.mutedText)),
  );
}
