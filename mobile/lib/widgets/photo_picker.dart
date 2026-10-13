import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/api_client.dart';
import '../api/server.dart';
import '../theme.dart';
import '../util/format.dart';
import 'common.dart';

/// Asks camera or gallery, then returns the photo ready to upload in [field], or null if cancelled.
/// Photos are shrunk on the phone first: a 12 MP original would cost a farmer several megabytes of data.
Future<UploadFile?> pickPhoto(BuildContext context, {required String field, String title = 'Add a photo'}) async {
  final source = await showModalBottomSheet<ImageSource>(
    context: context,
    builder: (context) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(padding: const EdgeInsets.fromLTRB(20, 0, 20, 8), child: Text(title, style: Brand.display(size: 17))),
          ListTile(
            leading: const Icon(LucideIcons.camera, color: Brand.green),
            title: const Text('Take a photo'),
            onTap: () => Navigator.pop(context, ImageSource.camera),
          ),
          ListTile(
            leading: const Icon(LucideIcons.image, color: Brand.green),
            title: const Text('Choose from gallery'),
            onTap: () => Navigator.pop(context, ImageSource.gallery),
          ),
          const SizedBox(height: 12),
        ],
      ),
    ),
  );
  if (source == null) return null;

  try {
    final file = await ImagePicker().pickImage(source: source, maxWidth: 2000, maxHeight: 2000, imageQuality: 85);
    if (file == null) return null;
    final name = file.name.contains('.') ? file.name : '${file.name}.jpg';
    return UploadFile(field, await file.readAsBytes(), name);
  } catch (_) {
    if (context.mounted) showMessage(context, "We couldn't open the camera or gallery. Check MundaLinq's permissions in your phone settings.");
    return null;
  }
}

/// Opens the phone's dialler.
Future<void> callPhone(BuildContext context, String phone) async {
  final opened = await launchUrl(Uri(scheme: 'tel', path: phone)).catchError((Object _) => false);
  if (!opened && context.mounted) showMessage(context, 'Call ${formatPhone(phone)}');
}

/// Opens WhatsApp with [text] ready to send to anyone (WhatsApp Web when the app isn't installed).
Future<void> shareOnWhatsApp(BuildContext context, String text) async {
  final link = Uri.https('wa.me', '/', {'text': text});
  final opened = await launchUrl(link, mode: LaunchMode.externalApplication).catchError((Object _) => false);
  if (!opened && context.mounted) showMessage(context, "Couldn't open WhatsApp.");
}

/// Opens a PDF (receipt, sale record) in the phone's browser. The browser has no sign-in, so the API first
/// hands out a short-lived signed link.
Future<void> openDocument(BuildContext context, ApiClient api, String linkPath) async {
  try {
    final data = (await api.get(linkPath) as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    final opened = await launchUrl(Uri.parse('${Server.origin}${data['path']}'), mode: LaunchMode.externalApplication);
    if (!opened && context.mounted) showMessage(context, "Couldn't open the PDF.");
  } catch (error) {
    if (context.mounted) showMessage(context, errorMessage(error));
  }
}

/// Opens a maps app at a point, falling back to Google Maps in the browser.
Future<void> openMap(BuildContext context, double latitude, double longitude, String label) async {
  final web = Uri.https('www.google.com', '/maps/search/', {'api': '1', 'query': '$latitude,$longitude'});
  final opened = await launchUrl(web, mode: LaunchMode.externalApplication).catchError((Object _) => false);
  if (!opened && context.mounted) showMessage(context, "Couldn't open maps for $label.");
}
