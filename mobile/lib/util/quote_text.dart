import '../api/quote_models.dart';
import 'format.dart';

/// "200 kilograms of Roma tomatoes".
String quoteHeadline(QuoteRequest request) => '${formatQuantity(request.quantity, request.unit)} of ${request.title}';

/// "Every week, from 16 Oct", or "Needed by 18 Oct" for a one-off.
String quoteSchedule(QuoteRequest request) {
  final date = formatDate(request.neededBy);
  if (!request.repeats) return 'Needed by $date';
  final often = request.frequencyLabel;
  return '${often[0].toUpperCase()}${often.substring(1)}, from $date';
}
